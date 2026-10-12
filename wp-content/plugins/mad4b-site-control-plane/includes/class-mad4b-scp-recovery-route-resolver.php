<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only recovery path resolution for ALL MAD4B capability consumers.
 *
 * This is a route catalogue, not an executor, installer, approval source or
 * release attestor. Route priorities are based on live registered Abilities and
 * their existing provider preflights, never on guessed host capabilities.
 *
 * Callers must preserve the original blockers; an alternate route is a new,
 * separately planned operation with the SAME central governance.
 */
final class MAD4B_SCP_Recovery_Route_Resolver {
    const CONTRACT = 'mad4b.recovery-route-resolution.v1';
    const MAX_BLOCKERS = 16;
    const MAX_ROUTES = 12;

    private static function row( $id, $ability, $state, $blockers, $purpose, $rank ) {
        return array(
            'route_id' => $id,
            'next_read_ability' => $ability,
            'state' => $state,
            'blockers' => array_values( array_unique( $blockers ) ),
            'purpose' => $purpose,
            'rank' => $rank,
            'requires_new_exact_plan' => true,
            'requires_fresh_authority_and_ticket_for_mutation' => true,
            'does_not_bypass_failed_route' => true,
            'action_executed' => false,
        );
    }

    private static function registered( $ability ) {
        return function_exists( 'wp_has_ability' ) && (bool) wp_has_ability( $ability );
    }

    private static function data( $class, $method, $arguments = array() ) {
        if ( ! class_exists( $class ) || ! is_callable( array( $class, $method ) ) ) return null;
        try {
            $value = call_user_func_array( array( $class, $method ), $arguments );
            return is_array( $value ) ? $value : null;
        } catch ( Throwable $ignored ) { return null; }
    }

    private static function is_authority_blocker( $blocker ) {
        return (bool) preg_match(
            '/(permission|approval|denied|forbidden|breakglass|oauth|scope|step_up|grant|ticket|authority|production|site_profile|binding_drift|replay|rollback|uncertain_write)/i',
            $blocker
        );
    }

    public static function inspect( $input = array() ) {
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) ||
            true !== MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_recovery_read_denied', 'Governed read permission is required.' );
        if ( ! is_array( $input ) ) return new WP_Error(
            'mad4b_recovery_input_invalid', 'Recovery expects a bounded object.' );
        foreach ( array_keys( $input ) as $key )
            if ( ! in_array( $key, array( 'goal', 'expected_head', 'blockers' ), true ) )
                return new WP_Error( 'mad4b_recovery_input_invalid', 'Unsupported recovery input.' );
        $goal = isset( $input['goal'] ) ? (string) $input['goal'] : 'general';
        if ( ! in_array( $goal, array(
            'general', 'source_build', 'staging_install', 'capability_discovery', 'frontend_diagnostics'
        ), true ) ) return new WP_Error( 'mad4b_recovery_goal_invalid', 'Unknown recovery goal.' );
        $head = isset( $input['expected_head'] ) ? $input['expected_head'] : '';
        if ( ! is_string( $head ) || ( '' !== $head && ! preg_match( '/^[a-f0-9]{40}$/D', $head ) ) )
            return new WP_Error( 'mad4b_recovery_head_invalid', 'Exact lowercase immutable SHA required.' );
        $blockers = isset( $input['blockers'] ) ? $input['blockers'] : array();
        if ( ! is_array( $blockers ) || count( $blockers ) > self::MAX_BLOCKERS )
            return new WP_Error( 'mad4b_recovery_blockers_invalid', 'Too many blocker observations.' );
        $clean = array();
        foreach ( $blockers as $b ) {
            if ( ! is_string( $b ) || strlen( $b ) > 128 ||
                ! preg_match( '/^[a-z0-9_:\.-]+$/D', $b ) )
                return new WP_Error( 'mad4b_recovery_blockers_invalid', 'Invalid blocker identity.' );
            $clean[] = $b;
        }
        $clean = array_values( array_unique( $clean ) );
        sort( $clean, SORT_STRING );
        $authority_blockers = array_values( array_filter( $clean, array( __CLASS__, 'is_authority_blocker' ) ) );
        // General callers need not know the recovery provider name. Infer
        // ONLY the read-only diagnostics category from observed blocker IDs.
        // Unknown incidents never default to a source-build operation.
        $inferred = '';
        if ( 'general' === $goal && $clean ) {
            foreach ( $clean as $code ) {
                if ( preg_match( '/(artifact|package|candidate|release|source_build|zip|ci_queue|build_worker)/', $code ) )
                    { $inferred = 'staging_install'; break; }
                if ( preg_match( '/(ability|tool_not_found|method_not_found|discovery|registry|projection|mcp_tools)/', $code ) )
                    $inferred = 'capability_discovery';
                elseif ( preg_match( '/(frontend|http|dns|browser|network|performance)/', $code ) && '' === $inferred )
                    $inferred = 'frontend_diagnostics';
            }
        }
        $resolved_goal = '' !== $inferred ? $inferred : $goal;
        $source_goal = in_array( $resolved_goal, array( 'source_build', 'staging_install' ), true );
        $routes = array();

        // The plugin's already-installed native build provider remains the
        // source of truth. An offline/queued CI is not proof it is unavailable.
        if ( $source_goal ) {
            // Owner intent is distinct from the executor's one-time mutation
            // ticket. It is an authenticated local review bridge, not an
            // alternate remote-admin execution lane.
            $consent_ability = 'mad4b/wp-native-owner-confirmation-plan';
            $routes[] = self::row( 'authenticated_owner_intent', $consent_ability,
                self::registered( $consent_ability )
                    ? 'OWNER_LOCAL_AUTHENTICATION_AND_EXACT_REVIEW_REQUIRED'
                    : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Record auditable exact Staging owner intent locally before separately obtaining OAuth Step-Up and an Admin mutation ticket', 5 );
            $native_ability = 'mad4b/wp-native-build-plan';
            $native = self::data( 'MAD4B_SCP_WP_Native_Build', 'capabilities' );
            $available = self::registered( $native_ability ) && is_array( $native );
            $eligible = $available && ! empty( $native['eligible'] );
            $state = ! $available ? 'NOT_REGISTERED_OR_UNAVAILABLE' :
                ( $eligible ? 'AVAILABLE_FOR_GOVERNED_BUILD_PLAN' : 'PREFLIGHT_BLOCKED' );
            $reasons = $available ? (array) ( $native['blockers'] ?? array() ) : array( 'native_build_provider_unavailable' );
            if ( '' === $head ) { $reasons[] = 'exact_source_head_required'; $state = 'EXACT_HEAD_REQUIRED'; }
            $routes[] = self::row( 'wordpress_native_cron_build', $native_ability,
                $state, $reasons, 'Build pinned GitHub source to private BUILT_UNVERIFIED ZIP on Staging', 10 );
            $status = self::data( 'MAD4B_SCP_WP_Native_Build', 'status' );
            if ( is_array( $status ) && ! empty( $status['state'] ) ) {
                $routes[count($routes)-1]['observed_job_state'] = (string) $status['state'];
                $routes[count($routes)-1]['observed_job_head_match'] =
                    '' !== $head && $head === (string) ( $status['head'] ?? '' );
                $routes[count($routes)-1]['observed_job_is_release_certified'] = false;
                $routes[count($routes)-1]['completed_exact_head_local_package'] =
                    '' !== $head && $head === (string) ( $status['head'] ?? '' )
                    && 'BUILT_UNVERIFIED' === (string) $status['state'];
                // Resume observed work rather than repeating an existing
                // build. Source status is evidence, not a mutation ticket.
                if ( $routes[count($routes)-1]['observed_job_head_match'] ) {
                    $phase = (string) $status['state'];
                    if ( in_array( $phase, array( 'DISCOVERING', 'FETCHING', 'PACKAGING' ), true ) ) {
                        $routes[count($routes)-1]['state'] = 'BUILD_IN_PROGRESS_MONITOR';
                        $routes[count($routes)-1]['next_read_ability'] = 'mad4b/wp-native-build-status';
                    } elseif ( 'BUILT_UNVERIFIED' === $phase ) {
                        $routes[count($routes)-1]['state'] = 'LOCAL_BUILD_READY_FOR_SELF_CHECK';
                        $routes[count($routes)-1]['next_read_ability'] = 'mad4b/wp-native-build-simulate';
                    } elseif ( 'BLOCKED' === $phase ) {
                        $routes[count($routes)-1]['state'] = 'EXACT_BUILD_FAILED_INSPECT_BLOCKER';
                        $routes[count($routes)-1]['next_read_ability'] = 'mad4b/wp-native-build-status';
                        $routes[count($routes)-1]['observed_build_blocker'] =
                            substr( (string) ( $status['blocker'] ?? '' ), 0, 128 );
                    }
                }
            }
            $routes[] = self::row( 'trusted_external_source_builder', 'mad4b/standalone-build-plan',
                self::registered( 'mad4b/standalone-build-plan' )
                    ? 'AVAILABLE_FOR_SEPARATE_PLANNING' : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'External trusted builder and independent release evidence route', 30 );
        }
        if ( 'staging_install' === $resolved_goal ) {
            $routes[] = self::row( 'site_self_certified_staging_install', 'mad4b/wp-native-self-approve-plan',
                self::registered( 'mad4b/wp-native-self-approve-plan' )
                    ? 'REQUIRES_COMPLETED_LOCAL_BUILD_AND_OWNER_APPROVAL'
                    : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Optional lower-independence Staging-only local install after distinct exact-plan owner approval', 20 );
            $routes[] = self::row( 'certified_selected_head_install', 'mad4b/control-plane-selected-head-plan',
                self::registered( 'mad4b/control-plane-selected-head-plan' )
                    ? 'REQUIRES_INDEPENDENT_CERTIFIED_ARTIFACT'
                    : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Signed release candidate with existing governed WordPress updater', 40 );
        }
        if ( 'capability_discovery' === $resolved_goal ) {
            $routes[] = self::row( 'indexed_gateway_discovery', 'mad4b/chatgpt-tool-projection-discover',
                self::registered( 'mad4b/chatgpt-tool-projection-discover' )
                    ? 'AVAILABLE_FOR_READ_PREPARATION' : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Search/prepare exact registered Ability and use canonical fixed dispatch; no direct tool slot required', 15 );
            $routes[] = self::row( 'read_only_dynamic_operation_routing', 'mad4b/operation-route-discover',
                self::registered( 'mad4b/operation-route-discover' )
                    ? 'AVAILABLE_FOR_READ_PREPARATION' : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Find a canonical Operation Registry planner before choosing execution lane', 25 );
        }
        if ( 'frontend_diagnostics' === $resolved_goal )
            $routes[] = self::row( 'staging_origin_diagnostic', 'mad4b/frontend-origin-diagnostic',
                self::registered( 'mad4b/frontend-origin-diagnostic' )
                    ? 'AVAILABLE_FOR_GOVERNED_READ_PLAN' : 'NOT_REGISTERED_OR_UNAVAILABLE',
                array(), 'Bounded same-origin diagnostics are not public browser acceptance', 35 );

        usort( $routes, static function ( $a, $b ) {
            return $a['rank'] === $b['rank']
                ? strcmp( $a['route_id'], $b['route_id'] )
                : ( $a['rank'] < $b['rank'] ? -1 : 1 );
        } );
        $routes = array_slice( $routes, 0, self::MAX_ROUTES );
        // Security failures are hard stops to mutation. Diagnostic routes can
        // be suggested, but never asserted ready for automatic execution.
        $recommended = null;
        if ( ! $authority_blockers ) {
            foreach ( $routes as $row ) {
                if ( in_array( $row['state'], array(
                    'AVAILABLE_FOR_GOVERNED_BUILD_PLAN',
                    'AVAILABLE_FOR_SEPARATE_PLANNING',
                    'AVAILABLE_FOR_READ_PREPARATION',
                    'AVAILABLE_FOR_GOVERNED_READ_PLAN',
                    'BUILD_IN_PROGRESS_MONITOR',
                    'LOCAL_BUILD_READY_FOR_SELF_CHECK' ), true ) ) {
                    $recommended = $row['route_id']; break;
                }
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'goal' => $goal,
            'resolved_goal' => $resolved_goal,
            'category_inferred_from_blockers' => '' !== $inferred,
            'expected_head' => $head,
            'original_blockers' => $clean,
            'authority_blockers' => $authority_blockers,
            'state' => $authority_blockers ? 'AUTHORITY_REVIEW_REQUIRED'
                : ( null === $recommended ? 'NO_VERIFIED_ALTERNATIVE_PLAN' : 'ALTERNATIVE_READ_PLAN_AVAILABLE' ),
            'recommended_route_id' => $recommended,
            'routes' => $routes,
            'no_registered_tool_is_not_no_available_route' => true,
            'alternate_route_does_not_clear_original_blockers' => true,
            'automatic_mutation_allowed' => false,
            'permission_reuse_allowed' => false,
            'runtime_revalidation_required' => true,
            'client_tool_visibility_verified' => false,
            'source_build_is_not_release_certification' => true,
            'read_only' => true, 'mutation_performed' => false, 'authority_effect' => 'none',
        );
    }
}
