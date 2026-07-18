<?php
/**
 * Pure WordPress version helpers (no WP bootstrap required).
 * Used by the policy engine and by CLI tests under tests/.
 */

if ( ! function_exists( 'ashford_guardian_version_branch' ) ) {
	/**
	 * x.y branch from a WordPress version string (mirrors Core_Upgrader).
	 */
	function ashford_guardian_version_branch( $version ) {
		$parts = preg_split( '/[.-]/', (string) $version );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return (string) ( $parts[0] ?? '' );
		}
		return $parts[0] . '.' . $parts[1];
	}
}

if ( ! function_exists( 'ashford_guardian_is_development_version' ) ) {
	function ashford_guardian_is_development_version( $version ) {
		return false !== strpos( (string) $version, '-' );
	}
}

if ( ! function_exists( 'ashford_guardian_is_same_branch_core_update' ) ) {
	/**
	 * True when $offered is a newer same-branch (maintenance/security) release.
	 */
	function ashford_guardian_is_same_branch_core_update( $current, $offered ) {
		$current = (string) $current;
		$offered = (string) $offered;
		if ( '' === $current || '' === $offered ) {
			return false;
		}
		if ( ashford_guardian_is_development_version( $current ) || ashford_guardian_is_development_version( $offered ) ) {
			return false;
		}
		if ( version_compare( $offered, $current, '<=' ) ) {
			return false;
		}
		return ashford_guardian_version_branch( $current ) === ashford_guardian_version_branch( $offered );
	}
}

if ( ! function_exists( 'ashford_guardian_core_result_is_success' ) ) {
	/**
	 * Whether an automatic_updates_complete core row result is a success.
	 *
	 * @param mixed $result true, version string, WP_Error, or other.
	 */
	function ashford_guardian_core_result_is_success( $result ) {
		if ( true === $result ) {
			return true;
		}
		if ( is_string( $result ) && '' !== $result ) {
			return true;
		}
		return false;
	}
}
