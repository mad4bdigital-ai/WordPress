<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Capability and dependency convergence PREVIEW. Not a package installer,
 * approval service, executor, provider certificate or durable journal.
 */
final class MAD4B_SCP_Assistant_Convergence {
    private static $booted = false;
    const CONTRACT = 'mad4b.assistant-convergence-preview.v1';
    const ABILITY = 'mad4b/assistant-convergence-preview';
    const MAX_CANDIDATES = 32;
    const MAX_DEPENDENCIES = 12;
    // The planner's 128 Unicode code points need at most 512 UTF-8 bytes.
    const MAX_STRING_BYTES = 512;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 42 );
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 42 );
    }

    public static function register_adapter( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' )
            || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
        $registry->register( new class extends MAD4B_SCP_Adapter_Base {
            public function id() { return 'assistant-convergence'; }
            public function label() { return 'Assistant Dependency and Configuration Preview'; }
            public function is_available() { return true; }
            public function ability_names() {
                return array( 'read' => array( MAD4B_SCP_Assistant_Convergence::ABILITY ),
                    'content' => array(), 'admin' => array() );
            }
            public function register_abilities() { MAD4B_SCP_Assistant_Convergence::register_ability(); }
            protected function mutation_requires_certification() { return false; }
            protected function provider_certification( $available ) { return null; }
        } );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' )
            || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Preview Assistant Capability Convergence',
            'description' => 'Non-authorizing candidate dependency/ambiguity graph; candidates are caller assertions and require independent provider/package certification.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'read_preview' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object',
                'required' => array( 'planning_input', 'candidates' ),
                'properties' => array(
                    'planning_input' => array( 'type' => 'object' ),
                    'candidates' => array( 'type' => 'array', 'maxItems' => self::MAX_CANDIDATES ),
                ), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    private static function fail( $reason ) {
        return new WP_Error( 'mad4b_assistant_convergence_' . $reason,
            'Assistant convergence preview requires bounded exact inert inputs; no execution performed.',
            array( 'authorizing' => false, 'automatic_retry_allowed' => false ) );
    }

    private static function id( $value ) {
        return is_string( $value ) && strlen( $value ) <= 80
            && 1 === preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $value );
    }

    private static function keys( $value, $allowed ) {
        if ( ! is_array( $value ) ) return false;
        foreach ( array_keys( $value ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) return false;
        }
        return true;
    }

    private static function list_array( $value, $maximum ) {
        return is_array( $value ) && count( $value ) <= $maximum
            && ( ! count( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
    }

    private static function inert( $value, $depth = 0, &$nodes = 0 ) {
        if ( ++$nodes > 700 || $depth > 7 || is_object( $value ) || is_resource( $value ) ) return false;
        if ( is_array( $value ) ) {
            if ( count( $value ) > 64 ) return false;
            foreach ( $value as $key => $item ) {
                if ( is_string( $key ) && strlen( $key ) > 80 ) return false;
                if ( ! self::inert( $item, $depth + 1, $nodes ) ) return false;
            }
            return true;
        }
        return null === $value || is_bool( $value ) || is_int( $value )
            || ( is_string( $value ) && strlen( $value ) <= self::MAX_STRING_BYTES
                && 1 === preg_match( '//u', $value ) );
    }

    /** Strict read operation: no caller-supplied plan SHA may become an authority. */
    public static function read_preview( $input = array() ) {
        $nodes = 0;
        if ( ! self::keys( $input, array( 'planning_input', 'candidates' ) )
            || ! array_key_exists( 'planning_input', $input )
            || ! array_key_exists( 'candidates', $input )
            || ! self::inert( $input, 0, $nodes ) ) return self::fail( 'input_invalid' );
        if ( ! class_exists( 'MAD4B_SCP_Assistant_Planning', false ) ) return self::fail( 'planner_unavailable' );
        $plan = MAD4B_SCP_Assistant_Planning::read_plan( $input['planning_input'] );
        if ( is_wp_error( $plan ) ) return $plan;
        return self::preview( $plan, $input['candidates'] );
    }

    /**
     * Pure DAG resolver. Provider data is UNVERIFIED until an independent
     * certified source verifies package, license, vendor, version and policy.
     * It cannot request automatic install/activation, spend or configuration.
     */
    public static function preview( $plan, $candidates ) {
        $nodes = 0;
        if ( ! self::inert( $plan, 0, $nodes ) || ! is_array( $plan )
            || ( $plan['contract'] ?? null ) !== 'mad4b.assistant-plan.v1'
            || ! is_string( $plan['plan_sha256'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $plan['plan_sha256'] )
            || ( $plan['readiness'] ?? null ) !== 'NOT_EXECUTABLE'
            || ( $plan['authorizing'] ?? null ) !== false
            || ! self::list_array( $plan['tasks'] ?? null, 24 ) ) return self::fail( 'plan_invalid' );
        $nodes = 0;
        if ( ! self::inert( $candidates, 0, $nodes )
            || ! self::list_array( $candidates, self::MAX_CANDIDATES ) ) return self::fail( 'catalog_invalid' );
        $catalog = array();
        foreach ( $candidates as $candidate ) {
            if ( ! self::keys( $candidate, array( 'provider', 'capabilities', 'requires', 'package_sha256', 'source' ) )
                || ! self::id( $candidate['provider'] ?? null )
                || isset( $catalog[ $candidate['provider'] ] )
                || ! in_array( $candidate['source'] ?? null,
                    array( 'installed', 'wordpress.org', 'certified-repository', 'other' ), true )
                || ! self::list_array( $candidate['capabilities'] ?? null, 24 )
                || ! count( $candidate['capabilities'] )
                || ! self::list_array( $candidate['requires'] ?? null, self::MAX_DEPENDENCIES )
                || ( isset( $candidate['package_sha256'] ) &&
                    ( ! is_string( $candidate['package_sha256'] )
                    || ! preg_match( '/^[a-f0-9]{64}$/D', $candidate['package_sha256'] ) ) ) ) return self::fail( 'provider_invalid' );
            $capabilities = array();
            foreach ( $candidate['capabilities'] as $id ) {
                if ( ! self::id( $id ) || isset( $capabilities[ $id ] ) ) return self::fail( 'provider_capabilities_invalid' );
                $capabilities[ $id ] = true;
            }
            $depends = array();
            foreach ( $candidate['requires'] as $id ) {
                if ( ! self::id( $id ) || isset( $depends[ $id ] ) ) return self::fail( 'provider_dependencies_invalid' );
                $depends[ $id ] = true;
            }
            ksort( $capabilities, SORT_STRING );
            ksort( $depends, SORT_STRING );
            $catalog[ $candidate['provider'] ] = array(
                'provider' => $candidate['provider'], 'capabilities' => array_keys( $capabilities ),
                'requires' => array_keys( $depends ),
                'package_sha256' => $candidate['package_sha256'] ?? null,
                'source' => $candidate['source'],
            );
        }
        ksort( $catalog, SORT_STRING );
        $graph = array(); $issues = array();
        foreach ( $catalog as $id => $entry ) {
            $graph[ $id ] = $entry['requires'];
            foreach ( $entry['requires'] as $required ) {
                if ( ! isset( $catalog[ $required ] ) ) $issues['dependency_missing:' . $id . ':' . $required] = true;
            }
            if ( $entry['source'] !== 'installed' && null === $entry['package_sha256'] ) {
                $issues['package_digest_unverified:' . $id] = true;
            }
        }
        $visited = array(); $active = array(); $ordered = array();
        $visit = function ( $id ) use ( &$visit, &$visited, &$active, &$ordered, &$issues, $catalog ) {
            if ( isset( $active[ $id ] ) ) { $issues['dependency_cycle:' . $id] = true; return; }
            if ( isset( $visited[ $id ] ) ) return;
            $active[ $id ] = true;
            foreach ( $catalog[ $id ]['requires'] as $dep ) if ( isset( $catalog[ $dep ] ) ) $visit( $dep );
            unset( $active[ $id ] );
            $visited[ $id ] = true;
            $ordered[] = $id;
        };
        foreach ( array_keys( $catalog ) as $id ) $visit( $id );
        $rows = array(); $seenTasks = array();
        foreach ( $plan['tasks'] as $task ) {
            if ( ! self::keys( $task, array( 'task_contract', 'task_id', 'assistant_role', 'capability',
                    'required', 'reported_state', 'decision', 'observation_trust', 'approval_required',
                    'execution_allowed', 'authority_expansion_allowed' ) )
                || ! self::id( $task['capability'] ?? null )
                || ! is_string( $task['task_id'] ?? null ) || strlen( $task['task_id'] ) > 120
                || ! preg_match( '/^proposal-[a-f0-9]{32}$/D', $task['task_id'] )
                || isset( $seenTasks[ $task['task_id'] ] )
                || ( $task['authority_expansion_allowed'] ?? null ) !== false
                || ( $task['execution_allowed'] ?? null ) !== false
                || ( $task['approval_required'] ?? null ) !== true
                || ( $task['observation_trust'] ?? null ) !== 'UNVERIFIED_CALLER_ASSERTION' ) return self::fail( 'task_invalid' );
            $seenTasks[ $task['task_id'] ] = true;
            $matches = array();
            foreach ( $catalog as $id => $entry ) {
                if ( in_array( $task['capability'], $entry['capabilities'], true ) ) $matches[] = $id;
            }
            $decision = (string) ( $task['decision'] ?? '' );
            $needed = in_array( $decision, array( 'DISCOVER_ALTERNATIVES', 'RESOLVE_DEPENDENCY', 'VERIFY_EXISTENCE', 'CERTIFY_PROVIDER', 'REPAIR_CONFIGURATION' ), true );
            $status = ! $needed ? 'NO_PACKAGE_DECISION' : ( ! count( $matches ) ? 'DISCOVER_PROVIDER' : ( count( $matches ) > 1 ? 'OWNER_CHOICE_REQUIRED' : 'VERIFY_CANDIDATE_AND_DEPENDENCIES' ) );
            if ( ! empty( $plan['review_required'] ) ) $status = 'CONTEXT_OR_PROVIDER_REVIEW_REQUIRED';
            if ( 'REVIEW_CONTEXT' === $decision ) $status = 'CONTEXT_REVIEW_REQUIRED';
            if ( count( $matches ) > 1 ) $issues['provider_ambiguity:' . $task['capability']] = true;
            if ( ! count( $matches ) && $needed ) $issues['provider_missing:' . $task['capability']] = true;
            $rows[] = array(
                'task_id' => $task['task_id'], 'capability' => $task['capability'],
                'planner_decision' => $decision, 'provider_options' => $matches,
                'status' => $status, 'provider_evidence_verified' => false,
                'candidate_selected' => false, 'approval_ready' => false,
                'install_allowed' => false, 'execution_allowed' => false,
            );
        }
        ksort( $issues, SORT_STRING );
        $spec = array( $plan['plan_sha256'], $catalog, $rows, $ordered, array_keys( $issues ) );
        $sha = hash( 'sha256', serialize( $spec ) );
        return array(
            'contract' => self::CONTRACT, 'planner_sha256' => $plan['plan_sha256'],
            'preview_sha256' => $sha, 'catalog_trust' => 'CALLER_ASSERTED_UNVERIFIED',
            'dependency_order_unverified' => $ordered, 'provider_candidates' => count( $catalog ),
            'tasks' => $rows, 'blockers' => array_keys( $issues ),
            'review_required' => true, 'status' => 'EXTERNAL_CERTIFICATION_REQUIRED',
            'authorizing' => false, 'read_only' => true,
            'apply_allowed' => false, 'automatic_install_allowed' => false,
            'credential_read_performed' => false, 'provider_call_performed' => false,
            'mutation_performed' => false, 'production_mutation_allowed' => false,
        );
    }
}
