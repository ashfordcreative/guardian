<?php
/**
 * Small symmetric-encryption helper so the hub API key isn't stored in
 * plaintext in the options table. Not a substitute for filesystem/DB
 * security, but keeps the secret out of casual view (DB dumps, backups,
 * other plugins reading options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ashford_Guardian_Crypto {

	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) : '' )
			. DB_NAME;
		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a secret for storage. Returns a base64 string, or an empty
	 * string if given an empty value.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Degrade gracefully rather than fatal; still avoids storing raw text.
			return 'b64:' . base64_encode( $plaintext );
		}
		$iv         = openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return 'b64:' . base64_encode( $plaintext );
		}
		return 'enc:' . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt a value previously produced by encrypt(). Returns '' on failure.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 4 ) );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$iv         = substr( $raw, 0, 16 );
		$ciphertext = substr( $raw, 16 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plaintext ? '' : $plaintext;
	}
}
