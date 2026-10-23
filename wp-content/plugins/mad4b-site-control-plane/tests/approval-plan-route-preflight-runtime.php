<?php
/** Standalone WordPress-independent fail-closed regression. No live mutation. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code; private $data;
    public function __construct($code, $message = '', $data = array()) { $this->code=$code; $this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function sanitize_key($key) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$key)); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class MAD4B_SCP_Staging_Write_Authority {
    public static $ready = false;
    public static $blockers = array('exact_write_grants_missing','runtime_authority_candidate_not_reconciled');
    public static function current_execution_readiness($ability, $input) {
        if ($ability !== 'mad4b/approval-plan' || $input['server_id'] !== 'mad4b-write') throw new RuntimeException('unsafe preflight selection');
        return array('ready'=>self::$ready, 'blockers'=>self::$blockers);
    }
}
class MAD4B_SCP_Policy { public static $can_mutate = false; public static function can_mutate() { return self::$can_mutate; } }
require __DIR__.'/../includes/class-mad4b-scp-adaptive-operation-router.php';
require __DIR__.'/../includes/class-mad4b-scp-staging-write-planning-guard.php';
function verify($ok, $message) { if (!$ok) {fwrite(STDERR, 'FAIL: '.$message."\n");exit(1);} }
$router='MAD4B_SCP_Adaptive_Operation_Router';
verify(null === $router::approval_plan_execution_preflight('mad4b/approval-plan','plan'), 'planning is not execution');
verify(null === $router::approval_plan_execution_preflight('mad4b/content-update-post','execute'), 'unrelated operation not captured');
$report=$router::approval_plan_execution_preflight('mad4b/approval-plan','execute');
verify($report['ready']===false, 'blocked write authority must block execution');
verify(in_array('exact_write_grants_missing', $report['blockers'], true), 'exact grant evidence retained');
verify(in_array('write_mutation_policy_not_ready', $report['blockers'], true), 'write policy evidence retained');
verify($report['required_target_server']==='mad4b-write' && $report['ticket_created']===false, 'no ticket and correct server');
$invalid=MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input(array('server_id'=>'mad4b-admin'));
verify(is_wp_error($invalid) && $invalid->get_error_code()==='mad4b_remote_plan_server_denied', 'reject admin server before authority checks');
$valid_server=MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input(array('server_id'=>'mad4b-write'));
verify(is_wp_error($valid_server) && $valid_server->get_error_code()==='mad4b_remote_plan_authority_not_ready', 'correct server still requires live authority');
MAD4B_SCP_Staging_Write_Authority::$ready=true;
MAD4B_SCP_Staging_Write_Authority::$blockers=array();
MAD4B_SCP_Policy::$can_mutate=true;
$ready=$router::approval_plan_execution_preflight('mad4b/approval-plan','execute');
verify($ready['ready']===true && $ready['oauth_step_up_verified']===false && $ready['authorizing']===false, 'route readiness never grants authorization');
MAD4B_SCP_Policy::$can_mutate=false;
$policy=$router::approval_plan_execution_preflight('mad4b/approval-plan','execute');
verify($policy['ready']===false && in_array('write_mutation_policy_not_ready',$policy['blockers'],true), 'policy remains hard blocker');
echo "APPROVAL_PLAN_ROUTE_PREFLIGHT_RUNTIME=PASS\n";
