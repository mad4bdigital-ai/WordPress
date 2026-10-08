<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Automatic gap-to-discovery bridge. No tools are selected or executed here.
 * Consumes an exact bound GA planning result and registered metadata only.
 */
final class MAD4B_SCP_Assistant_Solution_Router {
    const ABILITY = 'mad4b/assistant-solution-discover';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 46 );
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 46 );
    }

    public static function register_adapter( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' )
            || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
        $registry->register( new class extends MAD4B_SCP_Adapter_Base {
            public function id() { return 'assistant-solution-router'; }
            public function label() { return 'Assistant Solution Discovery'; }
            public function is_available() { return true; }
            public function ability_names() { return array( 'read' => array( MAD4B_SCP_Assistant_Solution_Router::ABILITY ),
                'content' => array(), 'admin' => array() ); }
            public function register_abilities() { MAD4B_SCP_Assistant_Solution_Router::register_ability(); }
            protected function mutation_requires_certification() { return false; }
            protected function provider_certification( $available ) { return null; }
        } );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Discover Solutions for Assistant Gaps',
            'description' => 'Expand bound assistant capability gaps using live registered metadata. Never selects executors or grants permissions.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'read_plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object',
                'required' => array( 'planning_input' ),
                'properties' => array(
                    'planning_input' => array( 'type' => 'object', 'additionalProperties' => true ),
                    'related_terms' => array( 'type' => 'array', 'maxItems' => 12,
                        'items' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 80 ) ),
                    'limit_per_gap' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
                    'external_hints' => array( 'type' => 'array', 'maxItems' => 24,
                        'items' => array( 'type' => 'object', 'required' => array( 'id', 'label', 'source' ),
                            'properties' => array(
                                'id' => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{1,79}$' ),
                                'label' => array( 'type' => 'string', 'maxLength' => 120 ),
                                'source' => array( 'type' => 'string', 'enum' => array( 'connector', 'skill', 'external_service', 'operator' ) ),
                                'description' => array( 'type' => 'string', 'maxLength' => 180 ),
                            ), 'additionalProperties' => false ) ),
                ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    private static function fail( $reason ) {
        return new WP_Error( 'mad4b_assistant_solution_' . $reason,
            'Solution routing refused stale or invalid inputs; nothing was executed.',
            array( 'authorizing' => false, 'automatic_retry_allowed' => false ) );
    }

    public static function read_plan( $input = array() ) {
        if ( ! is_array( $input ) || ! is_array( $input['planning_input'] ?? null ) )
            return self::fail( 'input_invalid' );
        foreach ( $input as $key => $value )
            if ( ! in_array( $key, array( 'planning_input', 'related_terms', 'limit_per_gap', 'external_hints' ), true ) )
                return self::fail( 'input_invalid' );
        $limit = $input['limit_per_gap'] ?? 8;
        if ( ! is_int( $limit ) || $limit < 1 || $limit > 12 ) return self::fail( 'budget_invalid' );
        $related = $input['related_terms'] ?? array();
        if ( ! is_array( $related ) || count( $related ) > 12 ||
            ( $related && array_keys( $related ) !== range( 0, count( $related ) - 1 ) ) )
            return self::fail( 'terms_invalid' );
        foreach ( $related as $term ) if ( ! is_string( $term ) || strlen( $term ) > 320
            || ! preg_match( '/^[\\p{L}\\p{N}][\\p{L}\\p{N} ._-]{2,79}$/uD', $term ) )
            return self::fail( 'terms_invalid' );
        $external = $input['external_hints'] ?? array();
        if ( ! is_array( $external ) || count( $external ) > 24 ||
            ( $external && array_keys( $external ) !== range( 0, count( $external ) - 1 ) ) )
            return self::fail( 'external_hints_invalid' );
        // Unknown external data is checked by Solution_Discovery::discover
        // against the same bounded schema as local source-only discovery.
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) )
            return self::fail( 'permission_denied' );
        if ( ! class_exists( 'MAD4B_SCP_Assistant_Planning', false )
            || ! class_exists( 'MAD4B_SCP_Solution_Discovery', false )
            || ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false ) )
            return self::fail( 'provider_unavailable' );
        $plan = MAD4B_SCP_Assistant_Planning::read_plan( $input['planning_input'] );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! is_array( $plan ) || 'mad4b.assistant-plan.v1' !== ( $plan['contract'] ?? null )
            || ( $plan['authorizing'] ?? true ) !== false || ! is_array( $plan['tasks'] ?? null )
            || count( $plan['tasks'] ) > 24 ) return self::fail( 'plan_invalid' );
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( ! is_array( $binding ) ) return self::fail( 'binding_invalid' );
        foreach ( array( 'site_uuid', 'profile_digest', 'runtime_generation',
            'artifact_sha256', 'origin_sha256', 'external_record_sha256',
            'restore_epoch', 'environment' ) as $key )
            if ( ( $binding[ $key ] ?? null ) !== ( $plan['binding'][ $key ] ?? null ) )
                return self::fail( 'binding_changed' );
        $inventory = MAD4B_SCP_Solution_Discovery::site_inventory();
        // Validate external catalog hints even on zero-gap plans.
        $guard = MAD4B_SCP_Solution_Discovery::discover( array(
            'expected_profile_digest' => $binding['profile_digest'],
            'expected_runtime_generation' => $binding['runtime_generation'],
            'intent' => 'capability discovery', 'mode' => 'inventory',
            'external_hints' => $external, 'limit' => 1 ), $inventory, $binding );
        if ( is_wp_error( $guard ) ) return $guard;
        $tasks = array();
        foreach ( $plan['tasks'] as $task ) {
            $decision = $task['decision'] ?? '';
            if ( ! in_array( $decision, array( 'DISCOVER_ALTERNATIVES', 'VERIFY_EXISTENCE',
                'CERTIFY_PROVIDER', 'REPAIR_CONFIGURATION', 'RESOLVE_DEPENDENCY' ), true ) ) continue;
            $capability = $task['capability'] ?? '';
            if ( ! is_string( $capability ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $capability ) )
                return self::fail( 'task_invalid' );
            $result = MAD4B_SCP_Solution_Discovery::discover( array(
                'expected_profile_digest' => $binding['profile_digest'],
                'expected_runtime_generation' => $binding['runtime_generation'],
                'intent' => str_replace( array( '.', '_', '-' ), ' ', $capability ),
                'related_terms' => $related, 'external_hints' => $external, 'limit' => $limit ), $inventory, $binding );
            if ( is_wp_error( $result ) ) return $result;
            $tasks[] = array( 'task_id' => $task['task_id'], 'capability' => $capability,
                'planner_decision' => $decision, 'total_matches' => $result['total_matches'],
                'status' => $result['total_matches'] ? 'VERIFY_CANDIDATE_BEHAVIOR'
                    : ( ! empty( $result['inventory_incomplete'] ) ? 'INVENTORY_INCOMPLETE_RETRY' : 'EXPAND_DISCOVERY' ),
                'inventory_incomplete' => $result['inventory_incomplete'],
                'candidates' => $result['candidates'],
                'snapshot_sha256' => $result['snapshot_sha256'],
                'execution_allowed' => false );
        }
        $fresh = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( ! is_array( $fresh ) || serialize( $fresh ) !== serialize( $binding ) )
            return self::fail( 'concurrent_binding_change' );
        return array( 'contract' => 'mad4b.assistant-solution-discovery.v1',
            'plan_sha256' => $plan['plan_sha256'], 'binding' => $plan['binding'],
            'tasks' => $tasks,
            'registry_coverage' => array(
                'plugin_inventory_complete' => ! empty( $inventory['plugin_inventory_complete'] ),
                'ability_inventory_complete' => ! empty( $inventory['ability_inventory_complete'] ),
                'extension_inventory_complete' => ! empty( $inventory['extension_inventory_complete'] ),
                'external_inventory_complete' => false ),
            'authorizing' => false, 'execution_allowed' => false, 'mutation_performed' => false,
            'provider_executed' => false, 'automatic_install_allowed' => false );
    }
}
