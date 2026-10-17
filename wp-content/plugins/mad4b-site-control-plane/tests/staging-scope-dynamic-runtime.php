<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['provider_pass'] = true;
$GLOBALS['provider_calls'] = 0;
$GLOBALS['provider_wrong_id'] = false;

function apply_filters($name, $value) {
    if ($name === 'mad4b_scp_staging_functional_scope_dependencies') return array(
        'jetengine_relations' => array('write_runtime'), // Legacy dependency only.
        'elementor_semantic' => array('gates' => array('write_runtime'),
            'acceptance' => array('provider_id' => 'elementor',
                'profile_id' => 'frontpage', 'suite' => 'semantic')),
        'unverified_provider' => array('nonexistent_unknown_gate'),
        'staging_functional' => array('browser_runtime'), // Never override.
        'frontend' => array('write_runtime'), // Reserved task name.
    );
    return $value;
}
class MAD4B_SCP_Acceptance_Core {
    public static function result($input = array()) {
        $GLOBALS['provider_calls']++;
        $pass = $GLOBALS['provider_pass'];
        return array(
            'provider_id' => $GLOBALS['provider_wrong_id'] ? 'wrong' : $input['provider_id'],
            'profile_id' => $input['profile_id'], 'suite' => $input['suite'],
            'plan_digest' => str_repeat('f', 64),
            'verdict' => $pass ? 'PASS' : 'INCOMPLETE_EVIDENCE',
            'overall_evidence_status' => $pass ? 'PASS' : 'INCOMPLETE_EVIDENCE',
            'verification' => array('semantic_parity_verified' => $pass),
            'read_only' => true, 'authorizing' => false,
            'blocking_reasons' => array(), 'defect_reasons' => array(),
            'incomplete_evidence' => $pass ? array() : array('required_semantic_assertion_missing'),
        );
    }
}
function ok($condition, $why) { if (!$condition) { fwrite(STDERR, 'FAIL '.$why."\n"); exit(1); } }
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-certification.php';
$source = file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-staging-certification.php');
ok(strpos($source, "'task_scope' => array( 'type' => 'string'") !== false,
    'MCP read ability exposes bounded task_scope input');
ok(strpos($source, "functional_status( array( 'scope' => ") !== false,
    'requested task_scope reaches native functional evaluation');
ok(strpos($source, "'requested_scope_readiness' => self::requested_scope_readiness(") !== false,
    'both full and compact payloads expose requested readiness');
$r = new ReflectionMethod('MAD4B_SCP_Staging_Certification', 'scoped_readiness');
$r->setAccessible(true);
$q = new ReflectionMethod('MAD4B_SCP_Staging_Certification', 'requested_scope_readiness');
$q->setAccessible(true);
$gates = array();
foreach (array('exact_build','safe_boot','write_runtime','write_authority','browser_runtime',
    'context_authority','brand_core_context_coverage','google_provider_connection',
    'managed_google_broker','skills_runtime','wp_import_export_exact_artifact',
    'performance_budget','query_monitor_db_attribution') as $k) $gates[$k] = array('ready' => true);
$gates['browser_runtime']['ready'] = false;
$gates['google_provider_connection']['ready'] = false;
$functional = array('staging_acceptance_ready' => true);
$state = $r->invoke(null, $gates, $functional);
ok($state['staging_functional']['ready'] === true,
    'unrelated external browser and optional Google cannot block frontend');
ok($state['governed_write']['ready'] === true,
    'unrelated Google cannot block write readiness');
ok($state['full_release']['ready'] === false, 'full release still requires all gates');
ok($state['jetengine_relations']['dependencies_ready'] === true
    && $state['jetengine_relations']['ready'] === false,
    'legacy provider dependency pass is not functional acceptance');
ok($state['elementor_semantic']['dependencies_ready'] === true
    && $state['elementor_semantic']['ready'] === false
    && $GLOBALS['provider_calls'] === 0, 'unrequested tests never run');
ok(!isset($state['unverified_provider']) && !isset($state['frontend']),
    'unknown gates and task-name overrides rejected');
ok($state['staging_functional']['required_gates'] === array('exact_build','safe_boot'),
    'plugins cannot overwrite built-in safety');

$state = $r->invoke(null, $gates, $functional, 'elementor_semantic');
ok($state['elementor_semantic']['ready'] === true
    && $state['elementor_semantic']['functional_acceptance_passed'] === true
    && $GLOBALS['provider_calls'] === 1, 'explicit scope executes validated read-only acceptance');
ok($q->invoke(null, $state, 'elementor_semantic')['ready'] === true,
    'selected provider result can be read explicitly');
$GLOBALS['provider_wrong_id'] = true;
$state = $r->invoke(null, $gates, $functional, 'elementor_semantic');
ok($state['elementor_semantic']['ready'] === false, 'mismatched provider identity denied');
$GLOBALS['provider_wrong_id'] = false;
$GLOBALS['provider_pass'] = false;
$state = $r->invoke(null, $gates, $functional, 'elementor_semantic');
ok($state['elementor_semantic']['ready'] === false,
    'missing provider functional evidence fails closed');
$GLOBALS['provider_pass'] = true;
$gates['write_runtime']['ready'] = false;
$before = $GLOBALS['provider_calls'];
$state = $r->invoke(null, $gates, $functional, 'elementor_semantic');
ok($state['elementor_semantic']['ready'] === false && $state['jetengine_relations']['ready'] === false
    && $before === $GLOBALS['provider_calls'], 'blocked dependencies prevent test execution');
ok($state['staging_functional']['ready'] === true, 'unrelated write gate cannot block frontend');
$functional['staging_acceptance_ready'] = false;
$state = $r->invoke(null, $gates, $functional, 'frontend');
ok($state['staging_functional']['ready'] === false,
    'missing requested native evidence fails closed');
ok($q->invoke(null, $state, 'unknown')['ready'] === false,
    'unknown task scope is explicitly blocked');
echo "STAGING_SCOPE_DYNAMIC_RUNTIME_PASS\n";
