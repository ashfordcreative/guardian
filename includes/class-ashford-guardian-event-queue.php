<?php
/**
 * Durable local event queue. Events are written here first and only
 * removed once the hub has accepted them, so a flaky cron run or hub
 * downtime never loses an event — it just gets retried later with
 * backoff. The policy engine never depends on this table being healthy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Event_Queue {

	const STATUS_PENDING = 'pending';
	const STATUS_FAILED   = 'failed';

	/** Hard cap so a long hub outage can't grow the table forever. */
	const MAX_ROWS = 5000;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ashford_guardian_queue';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(80) NOT NULL,
			event_type VARCHAR(80) NOT NULL DEFAULT '',
			severity VARCHAR(20) NOT NULL DEFAULT 'info',
			payload LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY status_next_attempt (status, next_attempt_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Add an event to the queue. Idempotent on event_id.
	 *
	 * @param array $event Full event envelope (id, source, type, ...).
	 */
	public static function enqueue( array $event ) {
		global $wpdb;
		if ( empty( $event['id'] ) ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . "
				 (event_id, event_type, severity, payload, status, attempts, next_attempt_at, created_at, updated_at)
				 VALUES (%s, %s, %s, %s, 'pending', 0, %s, %s, %s)",
				$event['id'],
				(string) ( $event['type'] ?? '' ),
				(string) ( $event['severity'] ?? 'info' ),
				wp_json_encode( $event ),
				$now,
				$now,
				$now
			)
		);
		self::prune_if_over_cap();
		return false !== $ok;
	}

	/**
	 * Next batch of events ready to send, oldest first.
	 */
	public static function get_batch( $limit = 25 ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, payload, attempts FROM " . self::table() . "
				 WHERE status IN ('pending','failed') AND next_attempt_at <= %s
				 ORDER BY id ASC
				 LIMIT %d",
				$now,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public static function count_ready( $limit = 500 ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " WHERE status IN ('pending','failed') AND next_attempt_at <= %s LIMIT %d",
				$now,
				$limit
			)
		);
	}

	/**
	 * Delete rows the hub has acknowledged (accepted or already-seen duplicate).
	 */
	public static function delete_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM " . self::table() . " WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore
		);
	}

	/**
	 * Mark rows as failed and schedule a backed-off retry. Never deletes —
	 * events survive hub downtime by design.
	 */
	public static function mark_failed( array $ids ) {
		global $wpdb;
		foreach ( $ids as $id ) {
			$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM " . self::table() . " WHERE id = %d", $id ) );
			$attempts++;
			$backoff_min = min( 60, (int) pow( 2, min( $attempts, 6 ) ) ); // 2,4,8,...64 capped at 60 min
			$next        = gmdate( 'Y-m-d H:i:s', time() + ( $backoff_min * MINUTE_IN_SECONDS ) );
			$wpdb->update(
				self::table(),
				array(
					'status'          => self::STATUS_FAILED,
					'attempts'        => $attempts,
					'next_attempt_at' => $next,
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
		}
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() ); // phpcs:ignore
	}

	public static function oldest_pending_age_seconds() {
		global $wpdb;
		$oldest = $wpdb->get_var( "SELECT created_at FROM " . self::table() . " ORDER BY id ASC LIMIT 1" ); // phpcs:ignore
		if ( ! $oldest ) {
			return 0;
		}
		return max( 0, time() - strtotime( $oldest . ' UTC' ) );
	}

	/**
	 * If the table has grown past MAX_ROWS (sustained hub outage), drop the
	 * oldest low-severity ("info") rows first to bound growth without
	 * discarding anything that looks important.
	 */
	private static function prune_if_over_cap() {
		global $wpdb;
		$total = self::pending_count();
		if ( $total <= self::MAX_ROWS ) {
			return;
		}
		$overflow = $total - self::MAX_ROWS;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . self::table() . "
				 WHERE severity = 'info'
				 ORDER BY id ASC
				 LIMIT %d",
				$overflow
			)
		);
	}
}
