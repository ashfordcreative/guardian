<?php
/**
 * Handles commands piggybacked on the /api/v1/events response. Every verb
 * acknowledges by emitting a related event (queued locally, delivered on
 * the next flush) — the hub has no separate ack endpoint. Unrecognized
 * verbs are logged and acknowledged as a no-op rather than causing a
 * failure, since the hub expires commands after 24h either way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Commands {

	/**
	 * @param array $commands Array of {id, verb, payload, expires_at}.
	 */
	public static function process( array $commands ) {
		foreach ( $commands as $command ) {
			$verb    = (string) ( $command['verb'] ?? '' );
			$payload = is_array( $command['payload'] ?? null ) ? $command['payload'] : array();
			$id      = (string) ( $command['id'] ?? '' );

			switch ( $verb ) {
				case 'hold_release':
					self::handle_hold_release( $id, $payload );
					break;
				case 'release_hold':
					self::handle_release_hold( $id, $payload );
					break;
				case 'resync_inventory':
					self::handle_resync_inventory( $id, $payload );
					break;
				case 'run_verification':
					self::handle_run_verification( $id, $payload );
					break;
				case 'set_patrol_frequency':
					self::handle_set_patrol_frequency( $id, $payload );
					break;
				default:
					self::handle_unknown( $id, $verb, $payload );
					break;
			}
		}
	}

	/**
	 * Best-effort match of a plugin/theme slug from a command payload.
	 * The hub's hold payload today is {watch_id, event_id, correlation_id,
	 * release}, where `release` is free text (often the watch's headline,
	 * not a clean slug) — so we try explicit fields first, then fall back
	 * to matching installed plugin names/slugs against that text.
	 */
	private static function resolve_slug( array $payload ) {
		foreach ( array( 'slug', 'plugin_slug', 'component', 'plugin' ) as $key ) {
			if ( ! empty( $payload[ $key ] ) && is_string( $payload[ $key ] ) ) {
				return sanitize_title( $payload[ $key ] );
			}
		}

		$haystack = strtolower( (string) ( $payload['release'] ?? '' ) );
		if ( '' === $haystack ) {
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );
			$name = strtolower( (string) ( $data['Name'] ?? '' ) );
			if ( ( $name && false !== strpos( $haystack, $name ) ) || false !== strpos( $haystack, strtolower( $slug ) ) ) {
				return $slug;
			}
		}
		return null;
	}

	private static function handle_hold_release( $command_id, array $payload ) {
		$slug = self::resolve_slug( $payload );

		if ( null === $slug ) {
			Ashford_Guardian_Hub::instance()->emit(
				'command.acknowledged',
				'warning',
				'Received a hold_release command but could not identify the component from the payload.',
				array( 'command_id' => $command_id, 'verb' => 'hold_release', 'payload' => $payload )
			);
			return;
		}

		Ashford_Guardian_Held_Releases::hold(
			$slug,
			array(
				'command_id'     => $command_id,
				'event_id'       => $payload['event_id'] ?? null,
				'correlation_id' => $payload['correlation_id'] ?? null,
				'held_at'        => time(),
			)
		);

		Ashford_Guardian_Hub::instance()->emit(
			'update.held',
			'notice',
			sprintf( 'Held pending update for %s per hub command.', $slug ),
			array( 'command_id' => $command_id, 'slug' => $slug ),
			null,
			$payload['correlation_id'] ?? null,
			$payload['event_id'] ?? null
		);
	}

	private static function handle_release_hold( $command_id, array $payload ) {
		$slug = self::resolve_slug( $payload );
		$context = $slug ? Ashford_Guardian_Held_Releases::release( $slug ) : null;

		if ( null === $context ) {
			Ashford_Guardian_Hub::instance()->emit(
				'command.acknowledged',
				'info',
				'Received a release_hold command but the component was not currently held.',
				array( 'command_id' => $command_id, 'verb' => 'release_hold', 'payload' => $payload )
			);
			return;
		}

		Ashford_Guardian_Hub::instance()->emit(
			'update.hold_released',
			'notice',
			sprintf( 'Released hold for %s; the update will proceed on the next policy tick.', $slug ),
			array( 'command_id' => $command_id, 'slug' => $slug ),
			null,
			$payload['correlation_id'] ?? $context['correlation_id'] ?? null,
			$payload['event_id'] ?? $context['event_id'] ?? null
		);
	}

	private static function handle_resync_inventory( $command_id, array $payload ) {
		Ashford_Guardian_Hub::instance()->emit_checkin();
		Ashford_Guardian_Hub::instance()->emit(
			'command.acknowledged',
			'info',
			'Inventory resynced on request.',
			array( 'command_id' => $command_id, 'verb' => 'resync_inventory' )
		);
	}

	/**
	 * Lightweight local self-check: confirms the site is up and reports a
	 * few vitals. Not a substitute for the hub's own uptime/Patrol checks —
	 * just proof the agent itself is alive and can see its own environment.
	 */
	private static function handle_run_verification( $command_id, array $payload ) {
		$checks = array(
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'cron_working' => (bool) wp_next_scheduled( Ashford_Guardian::CRON_HOOK ),
			'active_theme' => get_stylesheet(),
			'plugin_count' => count( (array) get_option( 'active_plugins', array() ) ),
		);

		Ashford_Guardian_Hub::instance()->emit(
			'verification.completed',
			'info',
			'Verification check completed.',
			array( 'command_id' => $command_id, 'checks' => $checks ),
			null,
			$payload['correlation_id'] ?? null,
			$payload['event_id'] ?? null
		);
	}

	/**
	 * Patrol frequency is a hub/Patrol concept, not something this plugin
	 * enforces locally — stub handler that just logs + acknowledges so the
	 * command doesn't sit unresolved on the hub side.
	 */
	private static function handle_set_patrol_frequency( $command_id, array $payload ) {
		Ashford_Guardian_Hub::instance()->emit(
			'command.acknowledged',
			'info',
			'set_patrol_frequency is not enforced by the WordPress agent; noted and acknowledged.',
			array( 'command_id' => $command_id, 'verb' => 'set_patrol_frequency', 'payload' => $payload )
		);
	}

	private static function handle_unknown( $command_id, $verb, array $payload ) {
		Ashford_Guardian_Hub::instance()->emit(
			'command.acknowledged',
			'warning',
			sprintf( 'Received unrecognized command verb "%s"; ignored.', $verb ?: '(empty)' ),
			array( 'command_id' => $command_id, 'verb' => $verb, 'payload' => $payload )
		);
	}
}
