<?php
/** Offline regression: scope advertisement stops once an eligible step-up tool exists. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['stepup_case'] = 1;
$GLOBALS['expensive_candidate_called'] = 0;
class MAD4B_SCP_Site_Profile_Enrollment {
	const ABILITY = 'mad4b/test-enrollment';
	public static function chatgpt_step_up_tools() {
		return 1 === $GLOBALS['stepup_case'] ? array( self::ABILITY ) : array();
	}
}
class MAD4B_SCP_Site_Profile_Write_Enablement {
	const ABILITY = 'mad4b/test-write-enable';
	public static function chatgpt_step_up_tools() {
		return 2 === $GLOBALS['stepup_case'] ? array( self::ABILITY ) : array();
	}
}
class MAD4B_SCP_Staging_Write_Grant_Reconciliation {
	const ABILITY = 'mad4b/test-reconcile';
	public static function chatgpt_step_up_tools() {
		return 3 === $GLOBALS['stepup_case'] ? array( self::ABILITY ) : array();
	}
}
class MAD4B_SCP_Staging_Write_Candidate_Binding {
	const ABILITY = 'mad4b/test-candidate';
	public static function chatgpt_step_up_tools() {
		$GLOBALS['expensive_candidate_called']++;
		return array();
	}
}
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-oauth-resource-bridge.php';
for ( $case = 1; $case <= 3; $case++ ) {
	$GLOBALS['stepup_case'] = $case;
	if ( true !== MAD4B_SCP_OAuth_Resource_Bridge::authority_step_up_scope_available() ) {
		fwrite( STDERR, 'OAUTH_STEPUP_FAIL: eligible step-up tool not advertised' . PHP_EOL ); exit( 1 );
	}
}
if ( 0 !== $GLOBALS['expensive_candidate_called'] ) {
	fwrite( STDERR, 'OAUTH_STEPUP_FAIL: unnecessary slow candidate plan invoked' . PHP_EOL ); exit( 1 );
}
echo "OAUTH_STEPUP_SHORT_CIRCUIT: PASS\n";