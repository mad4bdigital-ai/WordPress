<?php
/** Live-source recovery: independently authorized diagnostics vs Staging-only network probe. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/fixture-no-real-wordpress/' );
final class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['admin_allowed'] ) && 'manage_options' === $cap; }
final class MAD4B_SCP_Policy {
    public static $read_allowed = false;
    public static function can_read() { return self::$read_allowed; }
}
final class MAD4B_SCP_Site_Profile {
    public static $snapshot = array();
    public static function status() { return self::$snapshot; }
}
final class MAD4B_SCP_Frontend_Origin_Diagnostic {
    public static $probe_calls = 0;
    public static function inspect( $args ) { self::$probe_calls++; throw new RuntimeException('Status read must never trigger a probe'); }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-native-runtime-diagnostics.php';
function expect_it( $assertion, $label ) { if ( ! $assertion ) { fwrite( STDERR, 'FAIL ' . $label . "\n" ); exit( 1 ); } }
$bound = array(
    'configured'=>true, 'authority_ready'=>true,
    'origin_match'=>true, 'deployment_binding_match'=>true,
    'mutation_pending_audit'=>false, 'configured_environment'=>'production',
    'environment_match'=>true,
);
MAD4B_SCP_Site_Profile::$snapshot=$bound;
$denied=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it(is_wp_error($denied) && 'mad4b_native_recovery_read_denied'===$denied->get_error_code(),'Unauthenticated read denied');
MAD4B_SCP_Policy::$read_allowed=true;
$production=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it('READONLY_DIAGNOSTIC_READY'===$production['state'],'Bound production may read diagnostics');
expect_it($production['read_only_scope_ready']===true,'Exact identity ready');
expect_it($production['paired_probe']['available']===false,'No Staging probe in production');
expect_it($production['site_profile_staging_enrolled']===false,'Production not misclassified as Staging');
expect_it($production['mutation_performed']===false && $production['authority_granted']===false && $production['production_mutation_allowed']===false,'Read never grants mutation');
expect_it($production['external_mcp_acceptance']['tools_call_verified']===false,'Read cannot claim external MCP acceptance');
expect_it($production['browser_acceptance_certified']===false,'Read cannot certify browser');
MAD4B_SCP_Site_Profile::$snapshot['configured_environment']='staging';
$staging=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it('STAGING_DIAGNOSTIC_READY'===$staging['state'],'Exact Staging diagnostics ready');
expect_it($staging['paired_probe']['available']===true,'Staging paired probe only available, not executed');
expect_it($staging['paired_probe']['automatic_on_page_load']===false,'No automatic probe');
expect_it(MAD4B_SCP_Frontend_Origin_Diagnostic::$probe_calls===0,'No network probe called');
MAD4B_SCP_Site_Profile::$snapshot['environment_match']=false;
$bad_environment=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it('READONLY_DIAGNOSTIC_READY'===$bad_environment['state'] && $bad_environment['paired_probe']['available']===false,'Failed staging environment gate preserves read but denies probe');
MAD4B_SCP_Site_Profile::$snapshot['environment_match']=true;
foreach (array('configured','authority_ready','origin_match','deployment_binding_match') as $field) {
    MAD4B_SCP_Site_Profile::$snapshot=$bound;
    MAD4B_SCP_Site_Profile::$snapshot[$field]=false;
    $bad=MAD4B_SCP_Native_Runtime_Diagnostics::status();
    expect_it('SITE_PROFILE_NOT_READY'===$bad['state'] && $bad['paired_probe']['available']===false, 'Unverified identity field denies readiness: '.$field);
}
MAD4B_SCP_Site_Profile::$snapshot=$bound;
MAD4B_SCP_Site_Profile::$snapshot['mutation_pending_audit']=true;
$pending=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it('SITE_PROFILE_NOT_READY'===$pending['state'],'Pending identity mutation denies read readiness');
MAD4B_SCP_Policy::$read_allowed=false;
$GLOBALS['admin_allowed']=true;
$admin=MAD4B_SCP_Native_Runtime_Diagnostics::status();
expect_it(!is_wp_error($admin),'Admin may read diagnostics without a separate MCP entitlement');
expect_it(MAD4B_SCP_Frontend_Origin_Diagnostic::$probe_calls===0,'No status invocation started a network probe');
echo "PASS: Live native diagnostics read parity, profile binding, and Staging-only probe guards (17+ cases)\n";
