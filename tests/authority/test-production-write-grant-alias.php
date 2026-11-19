<?php
/** A hermetic contract test for opt-in, environment-scoped grant alias registration. */
error_reporting(E_ALL);
define('ABSPATH', '/tmp/');
$GLOBALS['registered'] = array();
$GLOBALS['test_environment'] = 'staging';
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class Test_Ability {
    public function get_input_schema() { return array('type' => 'object', 'required' => array('expected_plan_sha256')); }
}
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['test_environment']; }
}
function sanitize_key($value) { return strtolower((string)$value); }
function wp_has_ability($name) { return isset($GLOBALS['registered'][$name]); }
function wp_get_ability($name) { return $GLOBALS['registered'][$name] ?? null; }
function wp_register_ability($name, $args) { $GLOBALS['registered'][$name] = $args; return true; }
function has_filter($name, $callback = null) { return false; }
function current_user_can($cap) { return false; }
function assert_test($predicate, $message) { if (!$predicate) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$cls='MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$GLOBALS['registered'][$cls::ABILITY]=new Test_Ability();
$cls::register_staging_alias();
$cls::register_production_alias();
assert_test(wp_has_ability($cls::STAGING_ALIAS), 'Staging alias must remain available');
assert_test(!wp_has_ability($cls::PRODUCTION_ALIAS), 'Production alias must not appear on Staging');
$GLOBALS['test_environment']='production';
unset($GLOBALS['registered'][$cls::STAGING_ALIAS]);
$cls::register_staging_alias();
$cls::register_production_alias();
assert_test(!wp_has_ability($cls::STAGING_ALIAS), 'Staging alias must not appear on Production');
assert_test(wp_has_ability($cls::PRODUCTION_ALIAS), 'Production alias must register');
$p=$GLOBALS['registered'][$cls::PRODUCTION_ALIAS];
assert_test($p['permission_callback'] === array($cls,'can_reconcile_production'), 'Expected original permission chain');
assert_test($p['execute_callback'] === array($cls,'reconcile_production'), 'Expected production wrapper dispatch');
assert_test($p['input_schema']['required'] === array('expected_plan_sha256'), 'Schema must be inherited exactly');
assert_test(($p['meta']['mcp']['production_exact_confirmation_required'] ?? false) === true, 'Production exact confirmation remains mandatory');
assert_test(($p['meta']['mcp']['no_breakglass_or_developer'] ?? false) === true, 'No breakglass or developer grant');
assert_test(($p['meta']['mcp']['production_mutation_allowed'] ?? false) === true, 'Production supported');
$blocked=$cls::can_reconcile_production();
assert_test($blocked instanceof WP_Error && $blocked->get_error_code()==='mad4b_grant_reconcile_admin_required', 'Unauthenticated production request denied');
$GLOBALS['test_environment']='staging';
$blocked=$cls::can_reconcile_production();
assert_test($blocked instanceof WP_Error && $blocked->get_error_code()==='mad4b_grant_reconcile_alias_production_only', 'Environment-mismatched request denied');
echo "PASS: environment parity; exact confirmation and authorization guards preserved\n";
