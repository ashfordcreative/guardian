<?php
/**
 * Hub pairing/config storage: hub URL, site_id, api_key (encrypted),
 * pairing status. Deliberately kept independent of Ashford_Guardian's own
 * OPT_SETTINGS so the policy engine's option is untouched by hub concerns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Hub_Settings {

	const OPTION = 'ag_hub_settings';

	const STATE_UNPAIRED = 'unpaired';
	const STATE_PENDING  = 'pending';
	const STATE_ACTIVE   = 'active';
	const STATE_ERROR    = 'error';

	private static $cache = null;

	public static function defaults() {
		return array(
			'hub_url'         => defined( 'ASH_GUARDIAN_HUB_URL' ) ? ASH_GUARDIAN_HUB_URL : '',
			'site_id'         => '',
			'public_key'      => '',
			'api_key_enc'     => '',
			'pairing_state'   => self::STATE_UNPAIRED,
			'last_error'      => '',
			'last_pair_at'    => 0,
			'last_flush_at'   => 0,
			'last_flush_ok'   => null,
			'last_checkin_at' => 0,
		);
	}

	public static function get() {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		}
		return self::$cache;
	}

	public static function update( array $changes ) {
		$settings = wp_parse_args( $changes, self::get() );
		self::$cache = $settings;
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	public static function get_hub_url() {
		return trim( untrailingslashit( (string) self::get()['hub_url'] ) );
	}

	public static function get_site_id() {
		return (string) self::get()['site_id'];
	}

	public static function get_pairing_state() {
		return (string) self::get()['pairing_state'];
	}

	public static function is_active() {
		return self::STATE_ACTIVE === self::get_pairing_state() && '' !== self::get_api_key();
	}

	public static function get_public_key() {
		$s = self::get();
		if ( ! empty( $s['public_key'] ) ) {
			return $s['public_key'];
		}
		$key = 'pk_' . wp_generate_password( 40, false, false );
		self::update( array( 'public_key' => $key ) );
		return $key;
	}

	public static function get_api_key() {
		$enc = self::get()['api_key_enc'];
		if ( '' === $enc ) {
			return '';
		}
		return Ashford_Guardian_Crypto::decrypt( $enc );
	}

	public static function set_api_key( $plaintext_key ) {
		self::update( array( 'api_key_enc' => Ashford_Guardian_Crypto::encrypt( $plaintext_key ) ) );
	}

	public static function reset() {
		self::$cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * Human-readable label for the admin UI.
	 */
	public static function state_label() {
		switch ( self::get_pairing_state() ) {
			case self::STATE_ACTIVE:
				return 'Paired';
			case self::STATE_PENDING:
				return 'Pending approval';
			case self::STATE_ERROR:
				return 'Connection error';
			default:
				return 'Not paired';
		}
	}
}
