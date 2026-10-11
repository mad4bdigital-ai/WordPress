<?php
/**
 * Provider-neutral automatic operation and transport selection.
 *
 * Discovery and route selection NEVER execute an operation. The selected
 * Ability must still use the canonical schema-pinned dispatcher and the
 * original NHI, step-up, approval, budget, CAS and independent readback.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Adaptive_Operation_Router {
    const CONTRACT = 'mad4b.adaptive-operation-router.v1';
    const DISCOVER = 'mad4b/operation-route-discover';
    const PLAN = 'mad4b/operation-route-plan';
    const MAX_CANDIDATES = 8;
    const MAX_QUERY_BYTES = 512;

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $schema = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'task' => array( 'type' => 'string', 'maxLength' => self::MAX_QUERY_BYTES ),
                'ability_name' => array( 'type' => 'string', 'maxLength' => 191 ),
                'operation' => array( 'type' => 'string', 'maxLength' => 64 ),
                'target_kind' => array( 'type' => 'string', 'maxLength' => 64 ),
                'intent' => array( 'type' => 'string', 'enum' => array( 'auto', 'read', 'write' ) ),
                'phase' => array( 'type' => 'string', 'enum' => array( 'plan', 'execute' ) ),
                'failed_transport' => array( 'type' => 'string', 'enum' => array(
                    '', 'direct', 'read-execute', 'write-execute', 'enrollment-execute',
                    'developer-execute' ) ),
                'failure_code' => array( 'type' => 'string', 'maxLength' => 96 ),
                'max_candidates' => array( 'type' => 'integer', 'minimum' => 1,
                    'maximum' => self::MAX_CANDIDATES ),
            ) );
        foreach ( array( self::DISCOVER => 'discover', self::PLAN => 'plan' ) as $name => $method ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => 'MAD4B Adaptive Operation ' . ucfirst( $method ),
                'description' => 'Dynamically discover registered operation planners, capability descriptors and governed routes. Planning only; never dispatches writes or creates approvals.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $method ),
                'permission_callback' => array( __CLASS__, 'can_read' ),
                'input_schema' => $schema,
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                ),
            ) );
        }
    }
    public static function can_read() {
        return class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }
    private static function blocked( $code ) {
        return new WP_Error( 'mad4b_route_' . $code,
            'Exact registered operation, policy and bounded input are required.' );
    }
    private static function normalize( $input ) {
        if ( ! is_array( $input ) ) return self::blocked( 'invalid_input' );
        $allowed = array( 'task', 'ability_name', 'operation', 'target_kind', 'intent',
            'phase', 'failed_transport', 'failure_code', 'max_candidates' );
        if ( array_diff( array_keys( $input ), $allowed ) ) return self::blocked( 'unknown_field' );
        foreach ( array( 'task', 'ability_name', 'operation', 'target_kind', 'intent',
            'phase', 'failed_transport', 'failure_code' ) as $key ) {
            if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) )
                return self::blocked( 'invalid_field' );
        }
        $task = trim( (string) ( $input['task'] ?? '' ) );
        $name = trim( (string) ( $input['ability_name'] ?? '' ) );
        $operation = trim( (string) ( $input['operation'] ?? '' ) );
        if ( (int) ( '' !== $task ) + (int) ( '' !== $name )
            + (int) ( '' !== $operation ) !== 1
            || strlen( $task ) > self::MAX_QUERY_BYTES
            || ( '' !== $name && ! preg_match( '/^[A-Za-z0-9._+\/-]{3,191}$/D', $name ) )
            || ( '' !== $operation && ! preg_match( '/^[a-z0-9._-]{1,64}$/D', $operation ) ) )
            return self::blocked( 'ambiguous_or_invalid_target' );
        $intent = (string) ( $input['intent'] ?? 'auto' );
        $phase = (string) ( $input['phase'] ?? 'plan' );
        $failed = (string) ( $input['failed_transport'] ?? '' );
        if ( ! in_array( $intent, array( 'auto', 'read', 'write' ), true )
            || ! in_array( $phase, array( 'plan', 'execute' ), true )
            || ! in_array( $failed, array( '', 'direct', 'read-execute',
                'write-execute', 'enrollment-execute', 'developer-execute' ), true )
            || strlen( (string) ( $input['failure_code'] ?? '' ) ) > 96 )
            return self::blocked( 'invalid_route_policy' );
        if ( isset( $input['max_candidates'] ) &&
            ( ! is_int( $input['max_candidates'] ) || $input['max_candidates'] < 1
            || $input['max_candidates'] > self::MAX_CANDIDATES ) )
            return self::blocked( 'invalid_limit' );
        return array(
            'task' => $task, 'ability_name' => $name, 'operation' => $operation,
            'target_kind' => sanitize_key( (string) ( $input['target_kind'] ?? '' ) ),
            'intent' => $intent, 'phase' => $phase,
            'failed_transport' => $failed,
            'failure_code' => sanitize_key( (string) ( $input['failure_code'] ?? '' ) ),
            'max_candidates' => (int) ( $input['max_candidates'] ?? 5 ),
        );
    }
    private static function sources( array $request ) {
        if ( ! class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) )
            return self::blocked( 'gateway_unavailable' );
        $operation_row = array();
        if ( '' !== $request['operation'] ) {
            if ( ! class_exists( 'MAD4B_SCP_Operation_Registry' ) )
                return self::blocked( 'operation_registry_unavailable' );
            $operation_row = MAD4B_SCP_Operation_Registry::discover( array(
                'operation' => $request['operation'],
                'target_kind' => $request['target_kind'] ) );
            if ( is_wp_error( $operation_row ) ) return $operation_row;
            $planner = (string) ( $operation_row['planner'] ?? '' );
            $executor = (string) ( $operation_row['executor'] ?? '' );
            // A semantic execution phase cannot infer a dynamic executor
            // from a plan that has never been executed/approved.
            if ( 'execute' === $request['phase'] && 'exact_executor_from_plan' === $executor )
                return self::blocked( 'executor_requires_independent_exact_plan' );
            $names = array( 'execute' === $request['phase'] ? $executor : $planner );
            $ranking = array( $names[0] => 1000 );
        } elseif ( '' !== $request['ability_name'] ) {
            $names = array( $request['ability_name'] );
            $ranking = array( $request['ability_name'] => 1000 );
        } else {
            $matches = MAD4B_SCP_Unified_Capability_Gateway::search( array(
                'task' => $request['task'], 'limit' => $request['max_candidates'],
                'declared_readonly_only' => 'read' === $request['intent'],
            ) );
            if ( is_wp_error( $matches ) ) return $matches;
            $names = array(); $ranking = array();
            foreach ( (array) ( $matches['items'] ?? array() ) as $row ) {
                $ability = (string) ( $row['ability_name'] ?? '' );
                if ( '' === $ability || isset( $ranking[ $ability ] ) ) continue;
                $names[] = $ability;
                $ranking[ $ability ] = (int) ( $row['relevance_score'] ?? 0 );
            }
        }
        if ( empty( $names ) ) return array( 'names' => array(),
            'ranking' => array(), 'operation' => $operation_row );
        if ( count( $names ) > self::MAX_CANDIDATES )
            $names = array_slice( $names, 0, self::MAX_CANDIDATES );
        return array( 'names' => $names, 'ranking' => $ranking,
            'operation' => $operation_row );
    }

    /**
     * Pure selection, also exercised against synthetic host/catalog failures.
     * No client-provided availability flag is trusted as authority.
     */
    public static function select( array $prepared, array $ranking, array $request,
        array $base_tools = array() ) {
        $ranked = array();
        $intent = (string) ( $request['intent'] ?? 'auto' );
        $failed = (string) ( $request['failed_transport'] ?? '' );
        foreach ( $prepared as $row ) {
            if ( ! is_array( $row ) ) continue;
            $name = (string) ( $row['ability_name'] ?? '' );
            $execution = is_array( $row['execution'] ?? null ) ? $row['execution'] : array();
            $lane = (string) ( $execution['expected_execution_lane'] ?? '' );
            if ( '' === $lane && 'requires_operation_resolution' === ( $execution['state'] ?? '' )
                && 'mad4b/enrollment-execute' === ( $execution['dispatch_tool'] ?? '' ) )
                $lane = 'enrollment';
            $is_read = 'read' === $lane;
            if ( 'read' === $intent && ! $is_read ) continue;
            if ( 'write' === $intent && $is_read ) continue;
            if ( ! in_array( $lane, array( 'read', 'write', 'admin', 'content',
                'enrollment', 'developer' ), true ) ) continue;
            $dispatch = (string) ( $execution['dispatch_tool'] ?? '' );
            $state = (string) ( $execution['state'] ?? '' );
            $eligible = ! empty( $row['execution_eligible'] )
                && ! empty( $row['preparation_receipt_ready'] )
                && in_array( $state, array( 'governed_dispatch', 'requires_operation_resolution' ), true )
                && '' !== $dispatch;
            // Canonical descriptor is the authority classification source.
            if ( ! $is_read && ! in_array( $lane, array( 'enrollment', 'developer' ), true )
                && 'write' !== $row['classification'] ) $eligible = false;
            $dispatch_mounted = in_array( $dispatch, $base_tools, true );
            if ( ! $dispatch_mounted ) $eligible = false;
            $route_name = 'mad4b/read-execute' === $dispatch ? 'read-execute'
                : ( 'mad4b/write-execute' === $dispatch ? 'write-execute'
                : ( 'mad4b/enrollment-execute' === $dispatch ? 'enrollment-execute'
                : ( 'mad4b/developer-execute' === $dispatch ? 'developer-execute' : 'unknown' ) ) );
            if ( 'unknown' === $route_name ) $eligible = false;
            // A reported failed route is only an observation/hint. In
            // particular, an indeterminate write may already have committed.
            $unconfirmed_write = ! $is_read && '' !== $failed;
            if ( $unconfirmed_write || $route_name === $failed ) $eligible = false;
            // Safe-first selection: discovery never jumps directly to a
            // mutation when an exact registered read planner is available.
            $score = (int) ( $ranking[ $name ] ?? 0 ) * 10
                + ( $is_read && 'auto' === $intent ? 1000 : ( $is_read ? 3 : 0 ) );
            $ranked[] = array(
                'ability_name' => $name, 'score' => $score,
                'execution_lane' => $lane,
                'route' => $route_name,
                'dispatch_tool' => $dispatch,
                'eligible' => $eligible,
                'blocker' => $unconfirmed_write ? 'previous_write_requires_independent_readback'
                    : ( $route_name === $failed ? 'reported_transport_failed'
                    : ( ! $dispatch_mounted ? 'dispatcher_not_mounted'
                    : ( empty( $row['preparation_receipt_ready'] ) ? 'preparation_receipt_missing'
                    : ( ! empty( $execution['blocker'] ) ? $execution['blocker']
                    : ( $eligible ? '' : 'canonical_descriptor_ineligible' ) ) ) ) ),
                'prepared' => $row,
            );
        }
        usort( $ranked, static function ( $a, $b ) {
            if ( $a['eligible'] !== $b['eligible'] )
                return $a['eligible'] ? -1 : 1;
            if ( $a['score'] !== $b['score'] ) return $a['score'] > $b['score'] ? -1 : 1;
            return strcmp( $a['ability_name'], $b['ability_name'] );
        } );
        return $ranked;
    }
    public static function discover( $input = array() ) {
        return self::resolve( $input, false );
    }
    public static function plan( $input = array() ) {
        return self::resolve( $input, true );
    }
    private static function resolve( $input, $with_plan ) {
        if ( ! self::can_read() ) return self::blocked( 'read_permission_denied' );
        $request = self::normalize( $input );
        if ( is_wp_error( $request ) ) return $request;
        $sources = self::sources( $request );
        if ( is_wp_error( $sources ) ) return $sources;
        if ( empty( $sources['names'] ) ) return array(
            'contract' => self::CONTRACT, 'state' => 'NO_REGISTERED_MATCH',
            'selected' => null, 'candidate_count' => 0,
            'read_only' => true, 'mutation_performed' => false,
            'automatic_authority_created' => false );
        $prepared = MAD4B_SCP_Unified_Capability_Gateway::prepare(
            array( 'ability_names' => $sources['names'] ), 'mcp' );
        if ( is_wp_error( $prepared ) ) return $prepared;
        $base = class_exists( 'MAD4B_SCP_Servers' )
            ? MAD4B_SCP_Servers::chatgpt_base_tools() : array();
        $selected = self::select( (array) ( $prepared['abilities'] ?? array() ),
            $sources['ranking'], $request, $base );
        $rows = array();
        foreach ( $selected as $item ) {
            $row = $item['prepared'];
            $rows[] = array(
                'ability_name' => $item['ability_name'],
                'relevance_score' => $item['score'],
                'execution_lane' => $item['execution_lane'],
                'chosen_route' => $item['route'],
                'execution_eligible' => $item['eligible'],
                'blocker' => $item['blocker'],
                'descriptor_sha256' => (string) ( $row['descriptor_sha256'] ?? '' ),
                'classification_sha256' => (string) ( $row['classification_sha256'] ?? '' ),
                'input_schema_sha256' => (string) ( $row['input_schema_sha256'] ?? '' ),
            );
        }
        $best = $selected[0] ?? null;
        $ready = is_array( $best ) && ! empty( $best['eligible'] );
        $write_in_doubt = in_array( $request['failed_transport'],
            array( 'write-execute', 'enrollment-execute', 'developer-execute' ), true )
            || ( '' !== $request['failed_transport'] &&
                ( 'write' === $request['intent']
                    || ( $best && 'read' !== $best['execution_lane'] ) ) );
        // A client-side failure cannot prove whether a mutation committed.
        // Never return a ready-to-replay route, even if a read tool now ranks
        // higher in a semantically ambiguous task search.
        if ( $write_in_doubt ) $ready = false;
        $best_row = $ready ? $best['prepared'] : array();
        $resolution_required = $ready && 'enrollment' === $best['execution_lane'];
        $developer_isolation_required = $ready && 'developer' === $best['execution_lane'];
        return array(
            'contract' => self::CONTRACT,
            'state' => $write_in_doubt ? 'PREVIOUS_WRITE_REQUIRES_INDEPENDENT_READBACK'
                : ( $developer_isolation_required ? 'ISOLATED_DEVELOPER_STEP_UP_REQUIRED'
                : ( $resolution_required ? 'ENROLLMENT_OPERATION_ID_AND_DIGESTS_REQUIRED'
                : ( $ready ? 'ROUTE_SELECTED_REQUIRES_EXECUTION_REVALIDATION'
                : 'NO_VERIFIED_ROUTE_AVAILABLE' ) ) ),
            'mode' => $with_plan ? 'schema_pinned_route_plan' : 'bounded_route_discovery',
            'selected' => $ready ? array(
                'ability_name' => $best['ability_name'],
                'execution_lane' => $best['execution_lane'],
                'dispatch_tool' => $best['dispatch_tool'],
                'route' => $best['route'],
                'execution' => $best_row['execution'],
                'preparation_receipt' => $with_plan
                    ? (string) ( $best_row['preparation_receipt'] ?? '' ) : '',
                'authority_scope_sha256' => (string) ( $best_row['authority_scope_sha256'] ?? '' ),
                'input_schema_sha256' => (string) ( $best_row['input_schema_sha256'] ?? '' ),
                'classification_sha256' => (string) ( $best_row['classification_sha256'] ?? '' ),
                'descriptor_sha256' => (string) ( $best_row['descriptor_sha256'] ?? '' ),
                'client_tool_visibility' => 'UNVERIFIED_UNTIL_HOST_TOOLS_LIST',
                'enrollment_operation_resolution_required' => $resolution_required,
                'developer_isolated_step_up_required' => $developer_isolation_required,
                'direct_tool_catalog_candidate' => in_array(
                    $best['ability_name'], $base, true ),
                'direct_tool_is_execution_authority' => false,
            ) : null,
            'candidates' => $rows,
            'candidate_count' => count( $rows ),
            'registered_operation' => '' !== $request['operation']
                ? array( 'id' => $request['operation'],
                    'planner' => $sources['operation']['planner'] ?? '',
                    'executor' => $sources['operation']['executor'] ?? '' ) : null,
            'fallback_policy' => array(
                'read_transport_failure' => 'rediscover_and_prepare_fresh_receipt',
                'mutation_transport_failure' => 'no_blind_retry_query_independent_readback',
                'tool_not_found' => 'refresh_host_tools_list_or_use_existing_canonical_dispatch',
                'unknown_ability' => 'do_not_invent_adapter_or_unapproved_route',
            ),
            'requires_exact_owner_approval_for_mutations' => true,
            'no_direct_host_tool_guarantee' => true,
            'routing_selection_is_not_authority' => true,
            'never_auto_executes' => true,
            'client_claims_authoritative' => false,
            'breakglass_auto_enabled' => false,
            'production_auto_write_enabled' => false,
            'read_only' => true, 'mutation_performed' => false,
            'authority_effect' => 'none',
        );
    }
}
