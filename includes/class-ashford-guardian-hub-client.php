<?php
/**
 * Thin HTTP client for the Guardian Hub REST contract:
 *   POST {hub}/api/v1/pair
 *   POST {hub}/api/v1/events
 *
 * All calls are best-effort: on any failure we return a WP_Error and the
 * caller (Ashford_Guardian_Hub) decides how to react. Nothing here ever
 * touches the auto-update policy engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Hub_Client {

	/**
	 * Register (or re-register) this site with the hub. Does not require
	 * an API key — that's issued by the operator after approval.
	 *
	 * @return array|WP_Error Decoded JSON body on any 2xx, WP_Error on transport failure.
	 */
	public static function pair() {
		$hub_url = Ashford_Guardian_Hub_Settings::get_hub_url();
		if ( '' === $hub_url ) {
			return new WP_Error( 'ag_no_hub_url', 'No hub URL configured.' );
		}

		$body = array(
			'public_key' => Ashford_Guardian_Hub_Settings::get_public_key(),
			'site'       => array(
				'name'          => get_bloginfo( 'name' ) ?: wp_parse_url( home_url(), PHP_URL_HOST ),
				'domain'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'platform'      => 'WordPress',
				'agent_version' => ASH_GUARDIAN_VERSION,
			),
		);

		$response = wp_remote_post(
			$hub_url . '/api/v1/pair',
			array(
				'timeout' => 12,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		return self::decode( $response );
	}

	/**
	 * Send a batch of events. Requires an active API key.
	 *
	 * @param array $events Array of event envelopes.
	 * @return array|WP_Error Decoded JSON body ({accepted, duplicates, commands}) or WP_Error.
	 */
	public static function send_events( array $events ) {
		$hub_url = Ashford_Guardian_Hub_Settings::get_hub_url();
		$api_key = Ashford_Guardian_Hub_Settings::get_api_key();
		if ( '' === $hub_url || '' === $api_key ) {
			return new WP_Error( 'ag_not_paired', 'Hub URL or API key not configured.' );
		}

		$response = wp_remote_post(
			$hub_url . '/api/v1/events',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => wp_json_encode( array( 'events' => array_values( $events ) ) ),
			)
		);

		return self::decode( $response );
	}

	/**
	 * Normalize a wp_remote_* response into a decoded body or WP_Error,
	 * treating non-2xx HTTP codes (401, 403, 500...) as errors even though
	 * the transport itself succeeded.
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error'] )
				? ( is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] ) )
				: sprintf( 'HTTP %d', $code );
			return new WP_Error( 'ag_hub_http_' . $code, $message, array( 'status' => $code, 'body' => $data ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ag_hub_bad_json', 'Hub returned a non-JSON response.' );
		}

		return $data;
	}
}
