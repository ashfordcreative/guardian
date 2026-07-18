<?php
/**
 * Builds the component inventory sent with agent.checkin: core, PHP,
 * plugins, themes — each as {kind, name, slug, version, state}, matching
 * the hub's ComponentSnapshot shape.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Inventory {

	/**
	 * @return array<int, array{kind:string,name:string,slug:string,version:string,state:string,meta:array}>
	 */
	public static function build() {
		$components = array();

		$components[] = self::core_component();
		$components[] = self::php_component();

		foreach ( self::plugin_components() as $c ) {
			$components[] = $c;
		}
		foreach ( self::theme_components() as $c ) {
			$components[] = $c;
		}

		return $components;
	}

	private static function core_component() {
		$updates = get_core_updates();
		$state   = 'current';
		if ( is_array( $updates ) && ! empty( $updates ) && isset( $updates[0]->response ) && 'latest' !== $updates[0]->response ) {
			$state = 'pending';
		}
		return array(
			'kind'    => 'core',
			'name'    => 'WordPress',
			'slug'    => 'wordpress',
			'version' => get_bloginfo( 'version' ),
			'state'   => $state,
			'meta'    => array(),
		);
	}

	private static function php_component() {
		return array(
			'kind'    => 'runtime',
			'name'    => 'PHP',
			'slug'    => 'php',
			'version' => PHP_VERSION,
			'state'   => 'current',
			'meta'    => array(),
		);
	}

	/**
	 * @return array<int, array>
	 */
	private static function plugin_components() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$pending   = Ashford_Guardian::instance()->get_pending_updates_public();
		$held      = Ashford_Guardian_Held_Releases::all();
		$blocks    = Ashford_Guardian::instance()->get_update_blocks_public();

		$out = array();
		foreach ( $installed as $file => $data ) {
			$slug = strpos( $file, '/' ) !== false ? dirname( $file ) : basename( $file, '.php' );

			$state = in_array( $file, $active, true ) ? 'current' : 'inactive';
			$meta  = array();

			if ( isset( $blocks[ $slug ] ) ) {
				$state                   = 'blocked';
				$meta['block_reason']    = $blocks[ $slug ]['reason'] ?? 'failed';
				$meta['block_message']   = $blocks[ $slug ]['message'] ?? '';
				if ( ! empty( $blocks[ $slug ]['version'] ) ) {
					$meta['target_version'] = $blocks[ $slug ]['version'];
				}
			} elseif ( isset( $pending[ $slug ] ) ) {
				if ( isset( $held[ $slug ] ) ) {
					$state               = 'held';
					$meta['held_reason'] = 'hub_command';
				} elseif ( Ashford_Guardian::package_is_missing( $pending[ $slug ]['item'] ?? null ) ) {
					$state                = 'blocked';
					$meta['block_reason'] = 'license';
					$meta['target_version'] = $pending[ $slug ]['new_version'];
					$meta['decision']       = 'Update available but no download — check license';
				} else {
					$ev                     = Ashford_Guardian::instance()->evaluate_update_public( $slug, $pending[ $slug ] );
					$state                  = 'block' === $ev['status'] ? 'held' : 'pending';
					$meta['target_version'] = $pending[ $slug ]['new_version'];
					$meta['decision']       = $ev['decision'];
				}
			}

			$out[] = array(
				'kind'    => 'plugin',
				'name'    => $data['Name'] ?: $slug,
				'slug'    => $slug,
				'version' => $data['Version'] ?: '0',
				'state'   => $state,
				'meta'    => $meta,
			);
		}
		return $out;
	}

	/**
	 * @return array<int, array>
	 */
	private static function theme_components() {
		$themes       = wp_get_themes();
		$active_theme = get_stylesheet();
		$updates      = get_theme_updates(); // stylesheet => update transient item

		$out = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$state = ( $stylesheet === $active_theme ) ? 'current' : 'inactive';
			$meta  = array();
			if ( isset( $updates[ $stylesheet ] ) ) {
				$state                    = 'pending';
				$meta['target_version'] = $updates[ $stylesheet ]->update['new_version'] ?? '';
			}
			$out[] = array(
				'kind'    => 'theme',
				'name'    => $theme->get( 'Name' ) ?: $stylesheet,
				'slug'    => $stylesheet,
				'version' => $theme->get( 'Version' ) ?: '0',
				'state'   => $state,
				'meta'    => $meta,
			);
		}
		return $out;
	}
}
