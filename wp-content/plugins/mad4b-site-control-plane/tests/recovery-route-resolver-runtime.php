<?php
/** Native, non-mutating fixture for dynamically selected governed recovery paths. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_has_ability( $name ) { return in_array( $name, $GLOBALS['registered_abilities'], true ); }
class MAD4B_SCP_Policy {
    public static function can_read() { return ! empty( $GLOBALS['can_read'] ); }
}
class MAD4B_SCP_WP_Native_Build {
    public static function capabilities() {
        ++$GLOBALS['native_preflight_calls'];
        return array( 'eligible' => $GLOBALS['native_eligible'],
            'blockers' => $GLOBALS['native_eligible'] ? array() : array( 'exact_staging_opt_in' ) );
    }
    public static function status() { return array(
        'state' => $GLOBALS['job_state'], 'head' => $GLOBALS['job_head'] );
    }
}
$GLOBALS['registered_abilities'] = array(
    'mad4b/wp-native-build-plan', 'mad4b/standalone-build-plan',
    'mad4b/wp-native-self-approve-plan', 'mad4b/control-plane-selected-head-plan',
    'mad4b/chatgpt-tool-projection-discover', 'mad4b/operation-route-discover',
    'mad4b/frontend-origin-diagnostic',
);
$GLOBALS['can_read'] = true; $GLOBALS['native_eligible'] = true;
$GLOBALS['native_preflight_calls'] = 0;
$GLOBALS['job_state'] = 'NOT_RUN'; $GLOBALS['job_head'] = '';
$head = str_repeat( 'a', 40 );
$assert = static function ( $condition, $why ) { if ( ! $condition ) throw new RuntimeException( $why ); };
require __DIR__ . '/../includes/class-mad4b-scp-recovery-route-resolver.php';
$result = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'staging_install', 'expected_head' => $head,
    'blockers' => array( 'mad4b_selected_head_certified_artifact_unavailable' ),
) );
$assert( ! is_wp_error( $result ), 'Recovery plan must remain read-only and available.' );
$assert( 'wordpress_native_cron_build' === $result['recommended_route_id'],
    'Missing signed candidate did not prefer eligible existing WordPress Cron build.' );
$assert( 'ALTERNATIVE_READ_PLAN_AVAILABLE' === $result['state'] &&
    $result['original_blockers'][0] === 'mad4b_selected_head_certified_artifact_unavailable',
    'Original blocker was incorrectly erased.' );
$assert( count( $result['routes'] ) === 4 &&
    'AVAILABLE_FOR_GOVERNED_BUILD_PLAN' === $result['routes'][0]['state'],
    'Native and external/lower-trust alternatives were not enumerated.' );
$assert( $result['routes'][0]['observed_job_state'] === 'NOT_RUN' &&
    !$result['routes'][0]['observed_job_head_match'], 'No job was incorrectly treated as built.' );
$assert( !$result['automatic_mutation_allowed'] &&
    !$result['permission_reuse_allowed'] &&
    !$result['mutation_performed'] &&
    !$result['client_tool_visibility_verified'], 'Planning silently granted execution.' );
$assert( 1 === $GLOBALS['native_preflight_calls'], 'Native availability not consulted exactly once.' );
$GLOBALS['job_state'] = 'BUILT_UNVERIFIED'; $GLOBALS['job_head'] = $head;
$completed = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'staging_install', 'expected_head' => $head,
    'blockers' => array( 'mad4b_selected_head_certified_artifact_unavailable' ),
) );
$assert( ! empty( $completed['routes'][0]['completed_exact_head_local_package'] ) &&
    !$completed['routes'][0]['observed_job_is_release_certified'] &&
    'LOCAL_BUILD_READY_FOR_SELF_CHECK' === $completed['routes'][0]['state'] &&
    'mad4b/wp-native-build-simulate' === $completed['routes'][0]['next_read_ability'],
    'A completed Cron ZIP failed to advance into local verification without claiming release certification.' );
$GLOBALS['job_state'] = 'FETCHING';
$running = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'staging_install', 'expected_head' => $head,
    'blockers' => array( 'mad4b_selected_head_certified_artifact_unavailable' ),
) );
$assert( 'BUILD_IN_PROGRESS_MONITOR' === $running['routes'][0]['state'] &&
    'mad4b/wp-native-build-status' === $running['routes'][0]['next_read_ability'],
    'An in-progress Cron worker was silently restarted rather than monitored.' );
$GLOBALS['job_state'] = 'NOT_RUN';
$authority = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'staging_install', 'expected_head' => $head,
    'blockers' => array( 'oauth_step_up_denied' ),
) );
$assert( 'AUTHORITY_REVIEW_REQUIRED' === $authority['state'] &&
    null === $authority['recommended_route_id'] &&
    count( $authority['authority_blockers'] ) === 1,
    'Denied authority silently switched to an unapproved install.' );
$GLOBALS['native_eligible'] = false;
$blocked_native = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'source_build', 'expected_head' => $head,
    'blockers' => array( 'mad4b_selected_head_certified_artifact_unavailable' ),
) );
$assert( $blocked_native['recommended_route_id'] === 'trusted_external_source_builder' &&
    $blocked_native['routes'][0]['state'] === 'PREFLIGHT_BLOCKED',
    'Ineligible native worker was wrongly preferred to external trusted fallback.' );
$GLOBALS['registered_abilities'] = array( 'mad4b/standalone-build-plan' );
$GLOBALS['native_eligible'] = true;
$unregistered = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'source_build', 'expected_head' => $head ) );
$assert( 'NOT_REGISTERED_OR_UNAVAILABLE' === $unregistered['routes'][0]['state'] &&
    'trusted_external_source_builder' === $unregistered['recommended_route_id'],
    'Unregistered Ability incorrectly treated as usable from class presence.' );
$discovery = MAD4B_SCP_Recovery_Route_Resolver::inspect( array( 'goal' => 'capability_discovery' ) );
$assert( ! empty( $discovery['no_registered_tool_is_not_no_available_route'] ) &&
    !$discovery['automatic_mutation_allowed'], 'Missing tool visibility was mistaken for permission.' );
foreach ( array(
    array( 'goal' => 'staging_install', 'expected_head' => 'main' ),
    array( 'goal' => 'unsupported' ),
    array( 'goal' => 'staging_install', 'expected_head' => $head,
        'blockers' => array( 'x', array( 'y' ) ) ),
    array( 'goal' => 'staging_install', 'unexpected' => true ),
) as $input ) {
    $assert( is_wp_error( MAD4B_SCP_Recovery_Route_Resolver::inspect( $input ) ),
        'Malformed or untrusted recovery input was silently accepted.' );
}
// Generic recovery dispatch must select the correct stage from the blocker,
// not assume that every infrastructure incident needs a plugin ZIP.
$GLOBALS['registered_abilities'] = array( 'mad4b/wp-native-build-plan',
    'mad4b/standalone-build-plan', 'mad4b/chatgpt-tool-projection-discover' );
$generic_build = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'blockers' => array( 'mad4b_selected_head_certified_artifact_unavailable' ),
    'expected_head' => $head,
) );
$assert( 'staging_install' === $generic_build['resolved_goal'] &&
    'wordpress_native_cron_build' === $generic_build['recommended_route_id'],
    'General artifact blocker failed to discover existing Cron route' );
$GLOBALS['registered_abilities'][] = 'mad4b/chatgpt-tool-projection-discover';
$generic_tools = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'blockers' => array( 'tool_not_found' ) ) );
$assert( 'capability_discovery' === $generic_tools['resolved_goal'] &&
    'indexed_gateway_discovery' === $generic_tools['recommended_route_id'],
    'Tool-not-found was mislabeled as a build or hard permission failure' );
$generic_unknown = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'blockers' => array( 'unrecognized_generic_incident' ) ) );
$assert( 'general' === $generic_unknown['resolved_goal'] &&
    null === $generic_unknown['recommended_route_id'],
    'Unknown incident triggered an unrelated fallback' );
$generic_frontend = MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'blockers' => array( 'frontend_http_500' ) ) );
$assert( 'frontend_diagnostics' === $generic_frontend['resolved_goal'] &&
    null === $generic_frontend['recommended_route_id'],
    'Unregistered frontend diagnostic was marked available' );
$GLOBALS['can_read'] = false;
$assert( is_wp_error( MAD4B_SCP_Recovery_Route_Resolver::inspect( array(
    'goal' => 'source_build', 'expected_head' => $head ) ) ), 'Unauthorized recovery was allowed.' );
echo "PASS governed recovery: exact SHA, Cron first, native opt-out, foreign fallback, authority halt, no invented certification or mutation\n";
