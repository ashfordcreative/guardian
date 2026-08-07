<?php
/**
 * Version-gated schema manager for the local event queue table.
 *
 * Activation is not required for upgrades: maybe_migrate() runs on
 * plugins_loaded (and again from assert_healthy() if a caller needs the
 * table) so sites that updated in place without reactivating still heal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Schema {

	/** Schema version for {prefix}ashford_guardian_queue. Independent of the plugin version. */
	const SCHEMA_VERSION = 2;

	const OPTION   = 'ashford_guardian_schema_version';
	const TRANSIENT = 'ashford_guardian_schema_ok';

	/**
	 * Run on plugins_loaded: migrate when the stored version lags, otherwise
	 * physically verify the table once per day so a dropped table self-heals.
	 */
	public static function maybe_migrate() {
		$stored = (int) get_option( self::OPTION, 0 );
		if ( $stored < self::SCHEMA_VERSION ) {
			self::migrate();
			return;
		}

		if ( false !== get_transient( self::TRANSIENT ) ) {
			return;
		}

		if ( ! self::table_exists() ) {
			self::migrate();
			return;
		}

		set_transient( self::TRANSIENT, 1, DAY_IN_SECONDS );
	}

	/**
	 * Create / sync the queue table via dbDelta and stamp the schema version.
	 */
	public static function migrate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * Future version-conditional steps go here, e.g.:
		 *
		 * if ( (int) get_option( self::OPTION, 0 ) < 3 ) {
		 *     // ALTER / backfill for schema 3
		 * }
		 */

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

		if ( ! self::table_exists() ) {
			delete_transient( self::TRANSIENT );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- queue may be broken; do not log through it.
			error_log(
				sprintf(
					'[Ashford Guardian] Schema migrate failed for %s: %s',
					$table,
					$wpdb->last_error ? $wpdb->last_error : 'table still missing after dbDelta'
				)
			);
			return false;
		}

		update_option( self::OPTION, self::SCHEMA_VERSION, false );
		set_transient( self::TRANSIENT, 1, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Ensure the queue table is usable. Attempts migrate() if missing.
	 *
	 * @return bool True when callers may safely query the queue.
	 */
	public static function assert_healthy() {
		if ( self::table_exists() ) {
			return true;
		}
		self::migrate();
		return self::table_exists();
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ashford_guardian_queue';
	}

	private static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}
}
