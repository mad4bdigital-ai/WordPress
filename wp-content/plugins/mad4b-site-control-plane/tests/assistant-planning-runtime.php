<?php
/** Disposable assistant planner fixture; no WordPress, outbound services or DB. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['registered'] = array(); $GLOBALS['actions'] = array();
$GLOBALS['serialization_side_effect'] = false;
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['actions'][ $name ] = $callback; return true; }
function wp_register_ability( $id, $args ) { $GLOBALS['registered'][ $id ] = $args; return true; }
function wp_has_ability( $id ) { return isset( $GLOBALS['registered'][ $id ] ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
$binding = array( 'profile_digest' => str_repeat( 'a', 64 ), 'runtime_generation' => str_repeat( 'b', 64 ), 'artifact_sha256' => str_repeat( 'c', 64 ), 'environment' => 'staging', 'site_uuid' => 'fixture-site-uuid', 'restore_epoch' => 1, 'origin_sha256' => str_repeat( 'd', 64 ), 'external_record_sha256' => str_repeat( 'e', 64 ) );
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $binding;
    public static $reads = 0;
    public static function current() { ++self::$reads; return self::$binding; }
}
MAD4B_SCP_Adaptive_Operations_Context::$binding = $binding;
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-planning.php';
function check_case( $name, $test ) { if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $name . "\n" ); exit( 1 ); } echo 'PASS ' . $name . "\n"; }
function rejected( $result, $code ) { return is_wp_error( $result ) && 'mad4b_assistant_' . $code === $result->get_error_code(); }
$desired = array( 'capabilities' => array(
    array( 'capability' => 'search.intelligence', 'required' => true ),
    array( 'capability' => 'workflow.automation', 'required' => false ),
), 'facts' => array( array( 'key' => 'audience.market.country', 'value' => 'US', 'provenance' => 'operator' ),
    array( 'key' => 'audience.language', 'value' => 'en', 'provenance' => 'operator' ) ) );
$observed = array( 'capabilities' => array( array( 'capability' => 'search.intelligence', 'state' => 'missing' ),
    array( 'capability' => 'workflow.automation', 'state' => 'missing' ) ) );
MAD4B_SCP_Assistant_Planning::boot();
check_case( 'ability registered on hook', isset( $GLOBALS['actions']['wp_abilities_api_init'] ) );
call_user_func( $GLOBALS['actions']['wp_abilities_api_init'] );
check_case( 'read-only ability metadata', ( $GLOBALS['registered']['mad4b/assistant-plan']['meta']['annotations']['readonly'] ?? false ) === true );
$input = array( 'expected_profile_digest' => $binding['profile_digest'], 'expected_runtime_generation' => $binding['runtime_generation'], 'desired' => $desired, 'observed' => $observed );
$plan = MAD4B_SCP_Assistant_Planning::read_plan( $input );
check_case( 'plan nonauthorizing', is_array( $plan ) && false === $plan['authorizing'] && false === $plan['write_performed'] );
check_case( 'global readiness never authorizes', 'NOT_EXECUTABLE' === $plan['readiness'] && true === $plan['review_required'] );
check_case( 'typed input schema rejects extra facts', false === $GLOBALS['registered']['mad4b/assistant-plan']['input_schema']['properties']['desired']['properties']['facts']['items']['additionalProperties'] );
check_case( 'required capabilities bounded in schema', 24 === $GLOBALS['registered']['mad4b/assistant-plan']['input_schema']['properties']['desired']['properties']['capabilities']['maxItems'] );
check_case( 'stable scoped task identifier', 'proposal-' === substr( $plan['tasks'][0]['task_id'], 0, 9 ) && 41 === strlen( $plan['tasks'][0]['task_id'] ) );
check_case( 'audience proposal is non-authorizing', 'US' === $plan['configuration_proposals']['audience']['country']['proposed_value'] && false === $plan['configuration_proposals']['audience']['country']['apply_allowed'] );
check_case( 'discovery role typed', 'discovery' === $plan['tasks'][0]['assistant_role'] && 'mad4b.assistant-task-proposal.v1' === $plan['tasks'][0]['task_contract'] );
check_case( 'required missing discovers alternatives', 'DISCOVER_ALTERNATIVES' === $plan['tasks'][0]['decision'] );
check_case( 'optional missing does not install', 'OPTIONAL_NO_INSTALL' === $plan['tasks'][1]['decision'] );
check_case( 'tasks cannot expand authority', false === $plan['tasks'][0]['execution_allowed'] && false === $plan['tasks'][0]['authority_expansion_allowed'] );
check_case( 'external restore evidence bound', $binding['external_record_sha256'] === $plan['binding']['external_record_sha256'] );
check_case( 'stable identity-bound plan hash', $plan['plan_sha256'] === MAD4B_SCP_Assistant_Planning::read_plan( $input )['plan_sha256'] );
check_case( 'stable same-plan task identifiers', $plan['tasks'][0]['task_id'] === MAD4B_SCP_Assistant_Planning::read_plan( $input )['tasks'][0]['task_id'] );
$other_epoch = $binding; $other_epoch['restore_epoch'] = 2;
check_case( 'restore epoch changes task identity', MAD4B_SCP_Assistant_Planning::plan( $other_epoch, $desired, $observed )['tasks'][0]['task_id'] !== $plan['tasks'][0]['task_id'] );
$other_site = $binding; $other_site['site_uuid'] = 'another-site-uuid';
check_case( 'site identity changes task identity', MAD4B_SCP_Assistant_Planning::plan( $other_site, $desired, $observed )['tasks'][0]['task_id'] !== $plan['tasks'][0]['task_id'] );
$other_origin = $binding; $other_origin['origin_sha256'] = str_repeat( 'f', 64 );
check_case( 'origin drift changes task identity', MAD4B_SCP_Assistant_Planning::plan( $other_origin, $desired, $observed )['tasks'][0]['task_id'] !== $plan['tasks'][0]['task_id'] );
$other_environment = $binding; $other_environment['environment'] = 'production';
check_case( 'environment changes task identity', MAD4B_SCP_Assistant_Planning::plan( $other_environment, $desired, $observed )['tasks'][0]['task_id'] !== $plan['tasks'][0]['task_id'] );
$change_facts = $desired; $change_facts['facts'][0]['value'] = 'GB';
check_case( 'fact changes produce distinct exact input hash', MAD4B_SCP_Assistant_Planning::plan( $binding, $change_facts, $observed )['plan_sha256'] !== $plan['plan_sha256'] );
check_case( 'fact changes task identity', MAD4B_SCP_Assistant_Planning::plan( $binding, $change_facts, $observed )['tasks'][0]['task_id'] !== $plan['tasks'][0]['task_id'] );
$changed = $input; $changed['expected_runtime_generation'] = str_repeat( 'd', 64 );
check_case( 'stale generation rejects', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $changed ), 'stale_binding' ) );
$changed = $desired; unset( $changed['facts'] );
$missing = MAD4B_SCP_Assistant_Planning::plan( $binding, $changed );
check_case( 'missing market cannot be inferred from timezone', 'REVIEW_CONTEXT' === $missing['tasks'][0]['decision'] );
check_case( 'context review cannot look ready', 'CONTEXT_REVIEW_REQUIRED' === $missing['state'] && 'NOT_EXECUTABLE' === $missing['readiness'] );
$changed = $desired; $changed['facts'][1]['value'] = 'fr'; $changed['facts'][] = array( 'key' => 'audience.language', 'value' => 'en', 'provenance' => 'provider' );
$conflict_plan = MAD4B_SCP_Assistant_Planning::plan( $binding, $changed );
check_case( 'contradictory facts block proposal', 'CONFLICT_REVIEW_REQUIRED' === $conflict_plan['state'] );
check_case( 'conflicts do not expose ambiguous configuration', ! isset( $conflict_plan['configuration_proposals']['audience']['language'] ) );
$secondary = $desired;
$secondary['facts'][] = array( 'key' => 'audience.market.country', 'value' => 'Egypt', 'provenance' => 'provider' );
check_case( 'malformed second country rejected rather than masked by conflict', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $secondary ), 'market_invalid' ) );
$secondary = $desired;
$secondary['facts'][] = array( 'key' => 'audience.language', 'value' => 'en_US<script>', 'provenance' => 'provider' );
check_case( 'malformed second language rejected rather than masked by conflict', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $secondary ), 'language_invalid' ) );
$invalid_site = $binding; $invalid_site['site_uuid'] = 'name<unsafe>';
check_case( 'unsafe site uuid denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $invalid_site, $desired, $observed ), 'binding_invalid' ) );
$changed = $desired; $changed['facts'][0]['value'] = 'Egypt';
check_case( 'invalid country denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'market_invalid' ) );
$changed = $desired; $changed['facts'][1]['value'] = 'en_US<script>';
check_case( 'invalid language denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'language_invalid' ) );
$changed = $desired; $changed['capabilities']['unexpected_index'] = array( 'capability' => 'other.workflow', 'required' => false );
check_case( 'associative capability index denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'budget_invalid' ) );
$changed = $desired; $changed['capabilities'][] = $changed['capabilities'][0];
check_case( 'duplicate capability denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'capabilities_invalid' ) );
$changed = $observed; $changed['capabilities'][] = $changed['capabilities'][0];
check_case( 'ambiguous observations denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $changed ), 'observations_invalid' ) );
$changed = $observed; $changed['capabilities'][0]['state'] = 'active';
check_case( 'claimed active still requires independent verification', 'VERIFY_BEHAVIOR' === MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $changed )['tasks'][0]['decision'] );
// False equivalence objections: degraded is not missing; unknown does not
// authorize alternative discovery or provider mutation.
$degraded = $observed; $degraded['capabilities'][0]['state'] = 'degraded';
$diagnosis = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $degraded );
check_case( 'degraded provider recommends configuration repair, not installation', 'REPAIR_CONFIGURATION' === $diagnosis['tasks'][0]['decision']
    && 'configuration' === $diagnosis['tasks'][0]['assistant_role'] );
$unknown = $observed; $unknown['capabilities'][0]['state'] = 'unknown';
$diagnosis = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $unknown );
check_case( 'unknown existence requires verification, not replacement', 'VERIFY_EXISTENCE' === $diagnosis['tasks'][0]['decision'] );
$uncertified = $observed; $uncertified['capabilities'][0] = array( 'capability' => 'search.intelligence', 'state' => 'active',
    'provider' => 'sample.provider', 'certification_state' => 'uncertified' );
$diagnosis = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $uncertified );
check_case( 'active provider without certificate requires certification', 'CERTIFY_PROVIDER' === $diagnosis['tasks'][0]['decision'] );
$caller_certified = $uncertified;
$caller_certified['capabilities'][0]['certification_state'] = 'certified';
$claimed = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $caller_certified );
check_case( 'caller cannot self-certify provider', 'CERTIFY_PROVIDER' === $claimed['tasks'][0]['decision']
    && ! $claimed['tasks'][0]['execution_allowed']
    && in_array( 'provider_certification_unverified', $claimed['review_reasons'], true ) );
$dependency = $observed; $dependency['capabilities'][0]['dependency_state'] = 'unmet';
$diagnosis = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $dependency );
check_case( 'claimed missing dependency requires independent dependency review', 'RESOLVE_DEPENDENCY' === $diagnosis['tasks'][0]['decision'] );
foreach ( array( 'dependency_state' => 'installed', 'certification_state' => 'approved' ) as $key => $value ) {
    $bad = $observed; $bad['capabilities'][0][ $key ] = $value;
    check_case( 'untrusted observed enum rejected: ' . $key,
        rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $bad ), 'observations_invalid' ) );
}
foreach ( array( $degraded, $unknown, $uncertified, $dependency ) as $hint ) {
    $proposal = MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, $hint );
    check_case( 'classification does not expand authority', ! $proposal['authorizing'] &&
        ! $proposal['tasks'][0]['execution_allowed'] && 'NOT_EXECUTABLE' === $proposal['readiness']
        && ! $proposal['plugin_lifecycle_performed'] );
}
$changed = $desired; $changed['capabilities'] = array_fill( 0, 65, $desired['capabilities'][0] );
check_case( 'oversized input denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $changed ), 'desired_invalid' ) );
class MaliciousObservation { public function __serialize() { $GLOBALS['serialization_side_effect'] = true; return array(); } }
check_case( 'object rejected before serialization', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $desired, array('capabilities'=>array(new MaliciousObservation())) ), 'observed_invalid' ) && ! $GLOBALS['serialization_side_effect'] );
$before_reads = MAD4B_SCP_Adaptive_Operations_Context::$reads;
$bad_input = $input; $bad_input['observed'] = array( 'capabilities' => array( new MaliciousObservation() ) );
check_case( 'malicious input rejected before runtime reads', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $bad_input ), 'input_invalid' )
    && ! $GLOBALS['serialization_side_effect'] && $before_reads === MAD4B_SCP_Adaptive_Operations_Context::$reads );
$bad_input = $input; $bad_input['out_of_contract'] = 'ignore?';
check_case( 'unknown top-level input rejected before runtime reads', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $bad_input ), 'input_invalid' )
    && $before_reads === MAD4B_SCP_Adaptive_Operations_Context::$reads );
$unicode = $desired;
$unicode['facts'][] = array( 'key' => 'brand.name', 'value' => str_repeat( 'س', 128 ), 'provenance' => 'operator' );
$unicode_input = $input; $unicode_input['desired'] = $unicode;
$unicode_plan = MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input );
check_case( '128 Arabic fact characters accepted without authority', is_array( $unicode_plan )
    && false === $unicode_plan['authorizing'] && 'NOT_EXECUTABLE' === $unicode_plan['readiness'] );
check_case( 'Unicode facts bind exact input without echoing their value', is_array( $unicode_plan )
    && $unicode_plan['input_sha256'] !== $plan['input_sha256']
    && false === strpos( json_encode( $unicode_plan, JSON_UNESCAPED_UNICODE ), str_repeat( 'س', 128 ) ) );
$unicode['facts'][2]['value'] = str_repeat( 'س', 129 );
check_case( '129 Arabic fact characters denied', rejected( MAD4B_SCP_Assistant_Planning::plan( $binding, $unicode, $observed ), 'facts_invalid' ) );
$unicode['facts'][2]['value'] = str_repeat( '😀', 128 );
check_case( '128 four-byte emoji fact characters accepted', is_array( MAD4B_SCP_Assistant_Planning::plan( $binding, $unicode, $observed ) ) );
$unicode_input['desired'] = $unicode;
check_case( '128 emoji fact characters pass governed read boundary', is_array( MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input ) ) );
$before_reads = MAD4B_SCP_Adaptive_Operations_Context::$reads;
$unicode_input['desired']['facts'][2]['value'] = str_repeat( '😀', 129 );
check_case( '129 emoji fact characters exceed byte budget before runtime reads', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input ), 'input_invalid' )
    && $before_reads === MAD4B_SCP_Adaptive_Operations_Context::$reads );
$unicode_input['desired']['facts'][2]['value'] = "\xC3\x28";
check_case( 'malformed UTF8 denied before runtime reads', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input ), 'input_invalid' )
    && $before_reads === MAD4B_SCP_Adaptive_Operations_Context::$reads );
$unicode_input['desired']['facts'][2]['value'] = str_repeat( 'a', 513 );
check_case( 'oversized string denied before runtime reads', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input ), 'input_invalid' )
    && $before_reads === MAD4B_SCP_Adaptive_Operations_Context::$reads );
$unicode_input['desired']['facts'][2]['value'] = str_repeat( 'a', 129 );
check_case( '129 ASCII fact characters remain denied', rejected( MAD4B_SCP_Assistant_Planning::read_plan( $unicode_input ), 'facts_invalid' ) );
$prod = $binding; $prod['environment'] = 'production';
check_case( 'production never mutation', false === MAD4B_SCP_Assistant_Planning::plan( $prod, $desired, $observed )['production_authorized'] );
echo "ASSISTANT_GA_GB_FIXTURE: PASS\n";
