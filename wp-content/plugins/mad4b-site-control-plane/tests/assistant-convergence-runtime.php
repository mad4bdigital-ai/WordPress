<?php
/** Hermetic GA–GF candidate DAG and task CAS denial fixture. No WordPress or provider calls. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
$GLOBALS['hooks'] = array(); $GLOBALS['abilities'] = array(); $GLOBALS['checks'] = 0;
function add_action( $hook, $cb, $priority = 10 ) { $GLOBALS['hooks'][ $hook ] = $cb; return true; }
function wp_has_ability( $id ) { return isset( $GLOBALS['abilities'][ $id ] ); }
function wp_register_ability( $id, $schema ) { $GLOBALS['abilities'][ $id ] = $schema; return true; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code, $message, $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class MAD4B_SCP_Adapter_Base {}
class Assistant_Registry_Fixture { public $adapters = array(); public function register( $x ) { $this->adapters[ $x->id() ] = $x; } }
// Only the in-memory binding witness is simulated; planning and convergence
// both use their real production implementations in this fixture.
class MAD4B_SCP_Adaptive_Operations_Context { public static $calls = 0; public static $binding;
    public static function current() { ++self::$calls; return self::$binding; } }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-planning.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-convergence.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-task-contract.php';
function pass_check( $name, $value ) { ++$GLOBALS['checks']; if ( ! $value ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $name . PHP_EOL; }
function denied( $suffix, $value ) { return is_wp_error( $value ) && 'mad4b_assistant_convergence_' . $suffix === $value->get_error_code(); }
$sha = str_repeat( 'a', 64 );
$binding = array( 'contract' => 'mad4b.adaptive-operations-context.v1',
    'profile_digest' => $sha, 'runtime_generation' => str_repeat( 'b', 64 ),
    'artifact_sha256' => str_repeat( 'c', 64 ), 'origin_sha256' => str_repeat( 'd', 64 ),
    'external_record_sha256' => str_repeat( 'e', 64 ), 'site_uuid' => 'fixture-site-uuid',
    'restore_epoch' => 1, 'environment' => 'staging' );
MAD4B_SCP_Adaptive_Operations_Context::$binding = $binding;
$task = array( 'task_contract' => 'mad4b.assistant-task-proposal.v1',
    'task_id' => 'proposal-' . str_repeat( 'b', 32 ), 'assistant_role' => 'discovery',
    'capability' => 'seo.rankmath', 'required' => true, 'reported_state' => 'missing',
    'decision' => 'DISCOVER_ALTERNATIVES', 'observation_trust' => 'UNVERIFIED_CALLER_ASSERTION',
    'approval_required' => true, 'execution_allowed' => false, 'authority_expansion_allowed' => false );
$plan = array( 'contract' => 'mad4b.assistant-plan.v1', 'plan_sha256' => $sha,
    'readiness' => 'NOT_EXECUTABLE', 'authorizing' => false, 'review_required' => false,
    'tasks' => array( $task ) );
$catalog = array(
    array( 'provider' => 'rankmath', 'capabilities' => array( 'seo.rankmath' ), 'requires' => array( 'wordpress' ),
        'package_sha256' => str_repeat( 'c', 64 ), 'source' => 'certified-repository' ),
    array( 'provider' => 'wordpress', 'capabilities' => array( 'core.wordpress' ),
        'requires' => array(), 'source' => 'installed' ),
);
$r = MAD4B_SCP_Assistant_Convergence::preview( $plan, $catalog );
pass_check( 'valid preview contract', is_array( $r ) && $r['contract'] === MAD4B_SCP_Assistant_Convergence::CONTRACT );
pass_check( 'dependency topological order', $r['dependency_order_unverified'] === array( 'wordpress', 'rankmath' ) );
pass_check( 'no execution, authority or install', false === $r['apply_allowed'] && false === $r['automatic_install_allowed'] && false === $r['authorizing'] );
pass_check( 'never certify caller catalog', false === $r['tasks'][0]['provider_evidence_verified'] );
pass_check( 'preview bound to plan', $r['planner_sha256'] === $sha && $r['tasks'][0]['task_id'] === $task['task_id'] );
pass_check( 'deterministic order-independent result', $r['preview_sha256'] === MAD4B_SCP_Assistant_Convergence::preview( $plan, array_reverse( $catalog ) )['preview_sha256'] );
$graph = $catalog;
$graph[0]['capabilities'][] = 'workflow.automation';
$graph[0]['requires'][] = 'zzcache';
$graph[] = array( 'provider' => 'zzcache', 'capabilities' => array( 'cache.memory' ), 'requires' => array(), 'source' => 'installed' );
$canonical = MAD4B_SCP_Assistant_Convergence::preview( $plan, $graph );
$reordered = $graph; $reordered[0]['capabilities'] = array_reverse( $reordered[0]['capabilities'] );
pass_check( 'capability sets hash independent of input order', $canonical['preview_sha256'] === MAD4B_SCP_Assistant_Convergence::preview( $plan, $reordered )['preview_sha256'] );
$reordered = $graph; $reordered[0]['requires'] = array_reverse( $reordered[0]['requires'] );
$reversed = MAD4B_SCP_Assistant_Convergence::preview( $plan, $reordered );
pass_check( 'dependency sets hash independent of input order', $canonical['preview_sha256'] === $reversed['preview_sha256'] );
pass_check( 'dependency set order cannot change topological result', $canonical['dependency_order_unverified'] === $reversed['dependency_order_unverified'] );
pass_check( 'missing provider flagged', in_array( 'provider_missing:seo.rankmath', MAD4B_SCP_Assistant_Convergence::preview( $plan, array() )['blockers'], true ) );
$bad = $catalog; $bad[] = $catalog[0];
pass_check( 'duplicate provider rejected', denied( 'provider_invalid', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad ) ) );
$bad = $catalog; $bad[1]['requires'] = array( 'rankmath' );
$cycle = MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad );
pass_check( 'cycle reported', count( array_filter( $cycle['blockers'], function( $x ) { return 0 === strpos( $x, 'dependency_cycle:' ); } ) ) > 0 );
$bad = $catalog; $bad[0]['requires'] = array( 'unknownhost' );
pass_check( 'missing dependency flagged', in_array( 'dependency_missing:rankmath:unknownhost', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad )['blockers'], true ) );
$bad = $catalog; $bad[] = array( 'provider' => 'otherseo', 'capabilities' => array( 'seo.rankmath' ), 'requires' => array(), 'source' => 'other' );
pass_check( 'ambiguity requires owner choice', 'OWNER_CHOICE_REQUIRED' === MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad )['tasks'][0]['status'] );
$bad = $catalog; $bad[0]['url'] = 'https://invalid.invalid';
pass_check( 'unknown catalog fields refused', denied( 'provider_invalid', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad ) ) );
$bad = $catalog; $bad[0]['package_sha256'] = new stdClass();
pass_check( 'object blocked before hashing', denied( 'catalog_invalid', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad ) ) );
$bad = $catalog; $bad[0]['requires'] = array( 'wordpress', 'wordpress' );
pass_check( 'duplicate dependencies refused', denied( 'provider_dependencies_invalid', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad ) ) );
$bad = $catalog; $bad[0]['package_sha256'] = 'untrusted';
pass_check( 'invalid package digest refused', denied( 'provider_invalid', MAD4B_SCP_Assistant_Convergence::preview( $plan, $bad ) ) );
$bad = $plan; $bad['authorizing'] = true;
pass_check( 'privilege-bearing plan refused', denied( 'plan_invalid', MAD4B_SCP_Assistant_Convergence::preview( $bad, $catalog ) ) );
$bad = $plan; $bad['tasks'][0]['authority_expansion_allowed'] = true;
pass_check( 'privilege-bearing task refused', denied( 'task_invalid', MAD4B_SCP_Assistant_Convergence::preview( $bad, $catalog ) ) );
$bad = $plan; $bad['tasks'][0]['approval_required'] = false;
pass_check( 'approval bypass refused', denied( 'task_invalid', MAD4B_SCP_Assistant_Convergence::preview( $bad, $catalog ) ) );
$bad = $plan; $bad['tasks'][0]['observation_trust'] = 'CERTIFIED';
pass_check( 'false certification refused', denied( 'task_invalid', MAD4B_SCP_Assistant_Convergence::preview( $bad, $catalog ) ) );
MAD4B_SCP_Assistant_Convergence::boot();
pass_check( 'WP and adapter hooks registered', isset( $GLOBALS['hooks']['wp_abilities_api_init'] ) && isset( $GLOBALS['hooks']['mad4b_scp_register_adapters'] ) );
$registry = new Assistant_Registry_Fixture();
call_user_func( $GLOBALS['hooks']['mad4b_scp_register_adapters'], $registry );
pass_check( 'read-only adapter', isset( $registry->adapters['assistant-convergence'] ) && array() === $registry->adapters['assistant-convergence']->ability_names()['admin'] );
call_user_func( $GLOBALS['hooks']['wp_abilities_api_init'] );
pass_check( 'read-only ability', true === $GLOBALS['abilities']['mad4b/assistant-convergence-preview']['meta']['annotations']['readonly'] );
$planning = array( 'expected_profile_digest' => $sha, 'expected_runtime_generation' => $binding['runtime_generation'],
    'desired' => array( 'capabilities' => array( array( 'capability' => 'seo.rankmath', 'required' => true ) ) ),
    'observed' => array( 'capabilities' => array( array( 'capability' => 'seo.rankmath', 'state' => 'missing' ) ) ) );
$input = array( 'planning_input' => $planning, 'candidates' => $catalog );
$integrated = MAD4B_SCP_Assistant_Convergence::read_preview( $input );
pass_check( 'read preview uses actual planner and bound context', is_array( $integrated ) && 1 === MAD4B_SCP_Adaptive_Operations_Context::$calls
    && $integrated['planner_sha256'] === MAD4B_SCP_Assistant_Planning::plan( $binding, $planning['desired'], $planning['observed'] )['plan_sha256'] );
$input['apply'] = true;
pass_check( 'unknown input rejected before planner call', denied( 'input_invalid', MAD4B_SCP_Assistant_Convergence::read_preview( $input ) ) && 1 === MAD4B_SCP_Adaptive_Operations_Context::$calls );
$input = array( 'planning_input' => $planning, 'candidates' => $catalog );
foreach ( array( 'Arabic' => 'ع', 'emoji' => '😀' ) as $name => $character ) {
    $input['planning_input']['desired']['facts'] = array( array( 'key' => 'brand.name', 'value' => str_repeat( $character, 128 ), 'provenance' => 'operator' ) );
    $unicode = MAD4B_SCP_Assistant_Convergence::read_preview( $input );
    pass_check( 'actual planner preview accepts 128 ' . $name . ' code points', is_array( $unicode ) && false === $unicode['authorizing'] && false === $unicode['apply_allowed'] );
    $input['planning_input']['desired']['facts'][0]['value'] = str_repeat( $character, 129 );
    pass_check( 'actual planner preview denies 129 ' . $name . ' code points', is_wp_error( MAD4B_SCP_Assistant_Convergence::read_preview( $input ) ) );
}
foreach ( array( 'invalid UTF-8' => "\xF0\x28\x8C\x28", 'oversized bytes' => str_repeat( 'x', 513 ) ) as $name => $value ) {
    $input['planning_input']['desired']['facts'][0]['value'] = $value;
    $calls = MAD4B_SCP_Adaptive_Operations_Context::$calls;
    pass_check( $name . ' refused before context or plan hashing', denied( 'input_invalid', MAD4B_SCP_Assistant_Convergence::read_preview( $input ) ) && $calls === MAD4B_SCP_Adaptive_Operations_Context::$calls );
}
$maximum = $planning; $maximum['desired'] = array( 'capabilities' => array(), 'facts' => array() );
$maximum['observed'] = array( 'capabilities' => array() ); $maximum_catalog = array();
for ( $i = 0; $i < 24; ++$i ) {
    $id = 'feature.cap' . $i;
    $maximum['desired']['capabilities'][] = array( 'capability' => $id, 'required' => true );
    $maximum['observed']['capabilities'][] = array( 'capability' => $id, 'state' => 'missing', 'provider' => 'provider.item' . $i, 'dependency_state' => 'unmet', 'certification_state' => 'unknown' );
}
for ( $i = 0; $i < 48; ++$i ) $maximum['desired']['facts'][] = array( 'key' => 'brand.fact' . $i, 'value' => str_repeat( 'ع', 128 ), 'provenance' => 'operator' );
for ( $i = 0; $i < 32; ++$i ) $maximum_catalog[] = array( 'provider' => 'provider.item' . $i, 'capabilities' => array( 'feature.cap' . $i ), 'requires' => array(), 'source' => 'installed' );
$maximum_preview = MAD4B_SCP_Assistant_Convergence::read_preview( array( 'planning_input' => $maximum, 'candidates' => $maximum_catalog ) );
pass_check( 'actual 24 tasks 48 Unicode facts and 32 providers fit existing input budget', is_array( $maximum_preview ) && 24 === count( $maximum_preview['tasks'] ) && 32 === $maximum_preview['provider_candidates'] );
pass_check( 'maximum payload cannot authorize or execute', false === $maximum_preview['authorizing'] && false === $maximum_preview['apply_allowed'] && false === $maximum_preview['automatic_install_allowed']
    && false === $maximum_preview['tasks'][23]['execution_allowed'] );
$record = array( 'contract' => MAD4B_SCP_Assistant_Task_Contract::CONTRACT, 'task_id' => $task['task_id'],
    'plan_sha256' => $sha, 'binding_sha256' => str_repeat( 'f', 64 ), 'revision' => 1,
    'state' => 'proposed', 'last_event_sha256' => str_repeat( 'e', 64 ) );
$request = array( 'expected_revision' => 1, 'expected_last_event_sha256' => str_repeat( 'e', 64 ),
    'next_state' => 'evidence_pending', 'reason_code' => 'request_evidence' );
$next = MAD4B_SCP_Assistant_Task_Contract::transition( $record, $request );
pass_check( 'revision increases without persistence', is_array( $next ) && 2 === $next['candidate_record']['revision'] && 'NOT_PERSISTED' === $next['persistence_status'] );
pass_check( 'CAS outcome cannot execute', false === $next['executable'] && false === $next['mutation_performed'] );
pass_check( 'original record untouched', 1 === $record['revision'] && 'proposed' === $record['state'] );
$bad = $request; $bad['expected_revision'] = 2;
pass_check( 'stale CAS refused', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad ) ) );
$bad = $request; $bad['expected_last_event_sha256'] = str_repeat( 'f', 64 );
pass_check( 'wrong previous event hash refused', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad ) ) );
$bad = $request; $bad['next_state'] = 'executing_external';
pass_check( 'unapproved execution refused', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad ) ) );
$bad = $request; $bad['next_state'] = 'completed';
pass_check( 'fake completion refused', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad ) ) );
$bad = $request; $bad['arbitrary'] = true;
pass_check( 'unknown transition field refused', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad ) ) );
$bad = $request; $bad['next_state'] = 'cancelled';
$cancel = MAD4B_SCP_Assistant_Task_Contract::transition( $record, $bad );
pass_check( 'cancellation terminal', is_array( $cancel ) && 'cancelled' === $cancel['candidate_record']['state'] );
foreach ( array( array( 'proposed' ), new stdClass(), 1, true, null, str_repeat( 'x', 513 ) ) as $tainted ) {
    $bad = $record; $bad['state'] = $tainted; $result = null; $thrown = false;
    set_error_handler( function( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
    try { $result = MAD4B_SCP_Assistant_Task_Contract::transition( $bad, $request ); }
    catch ( Throwable $error ) { $thrown = true; }
    restore_error_handler();
    pass_check( 'tainted ' . gettype( $tainted ) . ' state denies without warning or exception', ! $thrown && is_wp_error( $result ) );
}
foreach ( array( 'cancelled', 'completed' ) as $terminal ) {
    $bad = $record; $bad['state'] = $terminal;
    pass_check( $terminal . ' cannot restart evidence collection', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $bad, $request ) ) );
}
$boundary_record = $record; $boundary_record['revision'] = PHP_INT_MAX - 1;
$boundary_request = $request; $boundary_request['expected_revision'] = PHP_INT_MAX - 1;
$boundary = MAD4B_SCP_Assistant_Task_Contract::transition( $boundary_record, $boundary_request );
pass_check( 'last safe revision increment stays an integer', is_array( $boundary ) && is_int( $boundary['candidate_record']['revision'] ) && PHP_INT_MAX === $boundary['candidate_record']['revision'] );
$boundary_request['expected_revision'] = PHP_INT_MAX;
$boundary_request['expected_last_event_sha256'] = $boundary['candidate_record']['last_event_sha256'];
pass_check( 'revision at integer limit cannot overflow', is_wp_error( MAD4B_SCP_Assistant_Task_Contract::transition( $boundary['candidate_record'], $boundary_request ) ) );
echo 'ASSISTANT_GA_GF_CONVERGENCE: PASS ' . $GLOBALS['checks'] . ' assertions'.PHP_EOL;
