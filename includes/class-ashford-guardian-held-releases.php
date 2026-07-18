<?php
/**
 * Slugs temporarily held by a hub `hold_release` command. Checked by the
 * policy engine's denylist filter, independent of hub reachability once
 * set — a hold survives hub downtime just like the rest of the policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Held_Releases {

	const OPTION = 'ag_held_releases';

	/**
	 * @return array<string, array{command_id:string,event_id:?string,correlation_id:?string,held_at:int}>
	 */
	public static function all() {
		$held = get_option( self::OPTION, array() );
		return is_array( $held ) ? $held : array();
	}

	public static function is_held( $slug ) {
		$held = self::all();
		return isset( $held[ $slug ] );
	}

	public static function hold( $slug, array $context = array() ) {
		$held         = self::all();
		$held[ $slug ] = wp_parse_args(
			$context,
			array(
				'command_id'     => '',
				'event_id'       => null,
				'correlation_id' => null,
				'held_at'        => time(),
			)
		);
		update_option( self::OPTION, $held, false );
	}

	/**
	 * Release a hold. Returns the stored context (for building the ack
	 * event's correlation/resolves fields) or null if it wasn't held.
	 */
	public static function release( $slug ) {
		$held = self::all();
		if ( ! isset( $held[ $slug ] ) ) {
			return null;
		}
		$context = $held[ $slug ];
		unset( $held[ $slug ] );
		update_option( self::OPTION, $held, false );
		return $context;
	}

	public static function slugs() {
		return array_keys( self::all() );
	}
}
