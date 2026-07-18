<?php
/**
 * CLI smoke tests for same-branch core update classification.
 *
 * Usage: php tests/test-core-branch.php
 */

require_once dirname( __DIR__ ) . '/includes/ashford-guardian-core-version.php';

$failures = 0;

function ag_assert( $cond, $message ) {
	global $failures;
	if ( $cond ) {
		echo "OK  {$message}\n";
		return;
	}
	++$failures;
	echo "FAIL {$message}\n";
}

ag_assert( '7.0' === ashford_guardian_version_branch( '7.0.1' ), 'branch of 7.0.1 is 7.0' );
ag_assert( '6.9' === ashford_guardian_version_branch( '6.9.5' ), 'branch of 6.9.5 is 6.9' );
ag_assert( ashford_guardian_is_development_version( '7.1-beta2' ), 'beta is development' );
ag_assert( ! ashford_guardian_is_development_version( '7.0.2' ), '7.0.2 is not development' );

ag_assert(
	ashford_guardian_is_same_branch_core_update( '7.0.1', '7.0.2' ),
	'7.0.1 → 7.0.2 is same-branch'
);
ag_assert(
	ashford_guardian_is_same_branch_core_update( '6.9.4', '6.9.5' ),
	'6.9.4 → 6.9.5 is same-branch'
);
ag_assert(
	! ashford_guardian_is_same_branch_core_update( '7.0.1', '7.1.0' ),
	'7.0.1 → 7.1.0 is major (rejected)'
);
ag_assert(
	! ashford_guardian_is_same_branch_core_update( '7.0.2', '7.0.2' ),
	'same version is not an update'
);
ag_assert(
	! ashford_guardian_is_same_branch_core_update( '7.0.2', '7.0.1' ),
	'downgrade is rejected'
);
ag_assert(
	! ashford_guardian_is_same_branch_core_update( '7.0.1', '7.1-beta2' ),
	'development offer is rejected'
);

ag_assert( ashford_guardian_core_result_is_success( true ), 'true is success' );
ag_assert( ashford_guardian_core_result_is_success( '7.0.2' ), 'version string is success' );
ag_assert( ! ashford_guardian_core_result_is_success( false ), 'false is not success' );
ag_assert( ! ashford_guardian_core_result_is_success( null ), 'null is not success' );

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} failure(s)\n" );
	exit( 1 );
}

echo "\nAll core-branch tests passed.\n";
exit( 0 );
