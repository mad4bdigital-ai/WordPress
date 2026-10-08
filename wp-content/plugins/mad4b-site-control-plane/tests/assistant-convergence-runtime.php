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
class MAD4B_SCP_Assistant_Planning { public static $calls = 0; public static $plan;
    public static function read_plan( $input ) { ++self::$calls; return self::$plan; } }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-convergence.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-task-contract.php';
function pass_check( $name, $value ) { ++$GLOBALS['checks']; if ( ! $value ) { fwrite( STDERR, 'FAIL ' . $name . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $name . PHP_EOL; }
function denied( $suffix, $value ) { return is_wp_error( $value ) && 'mad4b_assistant_convergence_' . $suffix === $value->get_error_code(); }
$sha = str_repeat( 'a', 64 );
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
MAD4B_SCP_Assistant_Planning::$plan = $plan;
$input = array( 'planning_input' => array( 'expected_profile_digest' => $sha ), 'candidates' => $catalog );
pass_check( 'read preview uses existing planner', is_array( MAD4B_SCP_Assistant_Convergence::read_preview( $input ) ) && 1 === MAD4B_SCP_Assistant_Planning::$calls );
$input['apply'] = true;
pass_check( 'unknown input rejected before planner call', denied( 'input_invalid', MAD4B_SCP_Assistant_Convergence::read_preview( $input ) ) && 1 === MAD4B_SCP_Assistant_Planning::$calls );
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
echo 'ASSISTANT_GA_GF_CONVERGENCE: PASS ' . $GLOBALS['checks'] . ' assertions'.PHP_EOL;
