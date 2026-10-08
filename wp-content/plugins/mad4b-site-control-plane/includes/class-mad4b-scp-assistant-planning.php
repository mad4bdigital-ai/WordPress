<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Inert, capability-first assistants foundation. Plans are not approval tickets.
 * All observations supplied by callers are unverified; no plugin is installed,
 * enabled, configured or contacted by this class.
 */
final class MAD4B_SCP_Assistant_Planning {
    const CONTRACT = 'mad4b.assistant-plan.v1';
    const ABILITY = 'mad4b/assistant-plan';
    const MAX_CAPABILITIES = 24;
    const MAX_FACTS = 48;

    public static function boot() {
        if ( ! function_exists( 'add_action' ) ) return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 40 );
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 40 );
    }

    public static function register_adapter( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
        $registry->register( new class extends MAD4B_SCP_Adapter_Base {
            public function id() { return 'assistant-planning'; }
            public function label() { return 'Autonomous Assistant Planning'; }
            public function is_available() { return true; }
            public function ability_names() { return array( 'read' => array( MAD4B_SCP_Assistant_Planning::ABILITY ), 'content' => array(), 'admin' => array() ); }
            public function register_abilities() { MAD4B_SCP_Assistant_Planning::register_ability(); }
            protected function mutation_requires_certification() { return false; }
            protected function provider_certification( $available ) { return null; }
        } );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Preview Autonomous Assistant Plan',
            'description' => 'Read-only deterministic desired-state gap analysis; caller observations are unverified and never authorize installation, activation, or configuration.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'read_plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'required' => array( 'expected_profile_digest', 'expected_runtime_generation', 'desired' ),
                'properties' => array(
                    'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'expected_runtime_generation' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'desired' => array( 'type' => 'object', 'required' => array( 'capabilities' ),
                        'properties' => array(
                            'capabilities' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CAPABILITIES,
                                'items' => array( 'type' => 'object', 'required' => array( 'capability', 'required' ),
                                    'properties' => array(
                                        'capability' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{2,79}$' ),
                                        'required' => array( 'type' => 'boolean' ),
                                    ), 'additionalProperties' => false ) ),
                            'facts' => array( 'type' => 'array', 'maxItems' => self::MAX_FACTS,
                                'items' => array( 'type' => 'object', 'required' => array( 'key', 'value', 'provenance' ),
                                    'properties' => array(
                                        'key' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{2,79}$' ),
                                        'value' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ),
                                        'provenance' => array( 'type' => 'string', 'enum' => array( 'operator', 'site_profile', 'provider', 'inferred' ) ),
                                    ), 'additionalProperties' => false ) ),
                        ), 'additionalProperties' => false ),
                    'observed' => array( 'type' => 'object', 'properties' => array(
                        'capabilities' => array( 'type' => 'array', 'maxItems' => self::MAX_CAPABILITIES,
                            'items' => array( 'type' => 'object', 'required' => array( 'capability', 'state' ),
                                'properties' => array(
                                    'capability' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{2,79}$' ),
                                    'state' => array( 'type' => 'string', 'enum' => array( 'active', 'missing', 'degraded', 'unknown' ) ),
                                    'provider' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{2,79}$' ),
                                     'dependency_state' => array( 'type' => 'string', 'enum' => array( 'unmet', 'met', 'unknown' ) ),
                                     'certification_state' => array( 'type' => 'string', 'enum' => array( 'uncertified', 'certified', 'unknown' ) ),
                                ), 'additionalProperties' => false ) ),
                    ), 'additionalProperties' => false ),
                ), 'additionalProperties' => false,
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    private static function fail( $reason ) {
        return new WP_Error( 'mad4b_assistant_' . $reason, 'Assistant planning requires bounded, exact and verified inputs; no execution was performed.',
            array( 'authorizing' => false, 'automatic_retry_allowed' => false ) );
    }

    private static function id( $value ) {
        return is_string( $value ) && strlen( $value ) <= 80 && 1 === preg_match( '/^[a-z][a-z0-9._-]{2,79}$/D', $value );
    }

    private static function sha( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    private static function keys( array $row, array $allowed ) {
        foreach ( $row as $key => $value ) if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) return false;
        return true;
    }

    /** Inert-data budget must be checked before hashing or parsing nested fields. */
    private static function inert( $value, $depth = 0, &$nodes = 0 ) {
        if ( ++$nodes > 700 || $depth > 6 || is_object( $value ) || is_resource( $value ) ) return false;
        if ( is_array( $value ) ) {
            if ( count( $value ) > 64 ) return false;
            foreach ( $value as $k => $item ) {
                if ( is_string( $k ) && strlen( $k ) > 80 ) return false;
                if ( ! self::inert( $item, $depth + 1, $nodes ) ) return false;
            }
            return true;
        }
        return null === $value || is_bool( $value ) || is_int( $value )
            || ( is_string( $value ) && strlen( $value ) <= 256 );
    }

    /** Called only by existing READ authority. Never claims user-supplied observations are certified. */
    public static function read_plan( $input = array() ) {
        // Reject malformed payloads before the expensive live identity read.
        $nodes = 0;
        if ( ! is_array( $input ) || ! self::inert( $input, 0, $nodes )
            || ! self::keys( $input, array( 'expected_profile_digest', 'expected_runtime_generation', 'desired', 'observed' ) )
            || ! self::sha( $input['expected_profile_digest'] ?? null )
            || ! self::sha( $input['expected_runtime_generation'] ?? null ) ) return self::fail( 'input_invalid' );
        if ( ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false ) ) return self::fail( 'context_unavailable' );
        $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
        if ( is_wp_error( $binding ) ) return $binding;
        if ( ! is_array( $binding ) ) return self::fail( 'context_unavailable' );
        if ( ! self::sha( $input['expected_profile_digest'] ?? null )
            || ! self::sha( $input['expected_runtime_generation'] ?? null )
            || ! hash_equals( (string) ( $binding['profile_digest'] ?? '' ), $input['expected_profile_digest'] )
            || ! hash_equals( (string) ( $binding['runtime_generation'] ?? '' ), $input['expected_runtime_generation'] ) ) return self::fail( 'stale_binding' );
        return self::plan( $binding, $input['desired'] ?? null, $input['observed'] ?? array() );
    }

    /** Pure planner; trusted runtime binding is injected by the governed caller. */
    public static function plan( $binding, $desired, $observed = array() ) {
        $nodes = 0;
        if ( ! self::inert( $binding, 0, $nodes ) || ! is_array( $binding )
            || ! self::sha( $binding['profile_digest'] ?? null ) || ! self::sha( $binding['runtime_generation'] ?? null )
            || ! self::sha( $binding['artifact_sha256'] ?? null )
            || ! self::sha( $binding['origin_sha256'] ?? null )
            || ! self::sha( $binding['external_record_sha256'] ?? null )
            || ! is_string( $binding['site_uuid'] ?? null )
            || strlen( $binding['site_uuid'] ) < 8 || strlen( $binding['site_uuid'] ) > 100
            || 1 !== preg_match( '/^[a-zA-Z0-9._:-]+$/D', $binding['site_uuid'] )
            || ! is_int( $binding['restore_epoch'] ?? null ) || $binding['restore_epoch'] < 1
            || ! in_array( $binding['environment'] ?? null, array( 'staging', 'development', 'local', 'production' ), true ) ) return self::fail( 'binding_invalid' );
        $nodes = 0;
        if ( ! self::inert( $desired, 0, $nodes ) || ! is_array( $desired )
            || ! self::keys( $desired, array( 'capabilities', 'facts' ) ) ) return self::fail( 'desired_invalid' );
        $nodes = 0;
        if ( ! self::inert( $observed, 0, $nodes ) || ! is_array( $observed )
            || ! self::keys( $observed, array( 'capabilities' ) ) ) return self::fail( 'observed_invalid' );
        $want = $desired['capabilities'] ?? null;
        $facts = $desired['facts'] ?? array();
        $have = $observed['capabilities'] ?? array();
        if ( ! is_array( $want ) || ! array_key_exists( 0, $want ) || count( $want ) > self::MAX_CAPABILITIES
            || ! is_array( $facts ) || count( $facts ) > self::MAX_FACTS
            || ! is_array( $have ) || count( $have ) > self::MAX_CAPABILITIES
            || array_keys( $want ) !== range( 0, count( $want ) - 1 )
            || ( count( $have ) && array_keys( $have ) !== range( 0, count( $have ) - 1 ) )
            || ( count( $facts ) && array_keys( $facts ) !== range( 0, count( $facts ) - 1 ) ) ) return self::fail( 'budget_invalid' );
        $observations = array();
        foreach ( $have as $item ) {
            if ( ! is_array( $item ) || ! self::keys( $item, array( 'capability', 'state', 'provider', 'dependency_state', 'certification_state' ) )
                || ! self::id( $item['capability'] ?? null )
                || ! in_array( $item['state'] ?? null, array( 'active', 'missing', 'degraded', 'unknown' ), true )
                || ( isset( $item['provider'] ) && ! self::id( $item['provider'] ) )
                || ( isset( $item['dependency_state'] ) && ! in_array( $item['dependency_state'], array( 'unmet', 'met', 'unknown' ), true ) )
                || ( isset( $item['certification_state'] ) && ! in_array( $item['certification_state'], array( 'uncertified', 'certified', 'unknown' ), true ) )
                || isset( $observations[ $item['capability'] ] ) ) return self::fail( 'observations_invalid' );
            $observations[ $item['capability'] ] = $item;
        }
        $fact_values = array(); $conflicts = array();
        foreach ( $facts as $item ) {
            if ( ! is_array( $item ) || ! self::keys( $item, array( 'key', 'value', 'provenance' ) )
                || ! self::id( $item['key'] ?? null ) || ! is_string( $item['value'] ?? null )
                || '' === trim( $item['value'] ) || strlen( $item['value'] ) > 128
                || ! in_array( $item['provenance'] ?? null, array( 'operator', 'site_profile', 'provider', 'inferred' ), true ) ) return self::fail( 'facts_invalid' );
            $key = $item['key'];
            // Validate *every* candidate, including conflicting second values:
            // no malformed secondary audience entry may pass through as a
            // harmless conflict with the first, valid observation.
            if ( 'audience.market.country' === $key && ! preg_match( '/^[A-Z]{2}$/D', $item['value'] ) ) return self::fail( 'market_invalid' );
            if ( 'audience.language' === $key && ! preg_match( '/^[a-z]{2,3}(?:-[a-zA-Z]{2,4})?$/D', $item['value'] ) ) return self::fail( 'language_invalid' );
            if ( isset( $fact_values[ $key ] ) && $fact_values[ $key ] !== $item['value'] ) $conflicts[ $key ] = true;
            if ( ! isset( $fact_values[ $key ] ) ) $fact_values[ $key ] = $item['value'];
            // Even 'operator' here is only a caller assertion until independently attested.
        }
        // Tasks are stable for identical site, restore generation and inputs.
        // These proposal identifiers are NOT durable execution identifiers.
        $input_digest = hash( 'sha256', serialize( array( $desired, $observed ) ) );
        $task_binding = array( $binding['site_uuid'], $binding['environment'],
            $binding['profile_digest'], $binding['origin_sha256'],
            $binding['runtime_generation'], $binding['artifact_sha256'],
            $binding['restore_epoch'], $binding['external_record_sha256'], $input_digest );
        $tasks = array(); $seen = array(); $review_reasons = array();
        foreach ( $want as $index => $item ) {
            if ( ! is_array( $item ) || ! self::keys( $item, array( 'capability', 'required' ) )
                || ! self::id( $item['capability'] ?? null ) || ! is_bool( $item['required'] ?? null )
                || isset( $seen[ $item['capability'] ] ) ) return self::fail( 'capabilities_invalid' );
            $id = $item['capability']; $seen[ $id ] = true;
            $state = $observations[ $id ]['state'] ?? 'unknown';
            $review = ! empty( $conflicts ) || ( 'search.intelligence' === $id
                && ( ! isset( $fact_values['audience.market.country'] ) || ! isset( $fact_values['audience.language'] ) ) );
            // Caller observations are unverified hints, not proof of provider execution.
            $hint = isset( $observations[ $id ] ) ? $observations[ $id ] : array();
            $action = 'OPTIONAL_NO_INSTALL'; $role = 'supervisor';
            if ( $review ) { $action = 'REVIEW_CONTEXT'; $role = 'configuration'; }
            elseif ( 'degraded' === $state ) { $action = 'REPAIR_CONFIGURATION'; $role = 'configuration'; }
            elseif ( 'active' === $state ) {
                $action = ! empty( $hint['provider'] ) && 'certified' !== ( $hint['certification_state'] ?? 'unknown' )
                    ? 'CERTIFY_PROVIDER' : 'VERIFY_BEHAVIOR';
                $role = 'certification';
            } elseif ( 'unknown' === $state && $item['required'] ) {
                $action = 'VERIFY_EXISTENCE'; $role = 'discovery';
            } elseif ( 'missing' === $state && $item['required'] ) {
                $action = 'unmet' === ( $hint['dependency_state'] ?? 'unknown' )
                    ? 'RESOLVE_DEPENDENCY' : 'DISCOVER_ALTERNATIVES';
                $role = 'discovery';
            }
            if ( $review ) $review_reasons['context_review_required'] = true;
            if ( 'active' === $state ) $review_reasons['provider_behavior_unverified'] = true;
            if ( 'degraded' === $state ) $review_reasons['reported_provider_degraded'] = true;
            if ( 'CERTIFY_PROVIDER' === $action ) $review_reasons['provider_certification_unverified'] = true;
            if ( 'RESOLVE_DEPENDENCY' === $action ) $review_reasons['dependency_claim_requires_independent_verification'] = true;
            if ( 'VERIFY_EXISTENCE' === $action ) $review_reasons['existence_unverified'] = true;
            if ( $item['required'] && 'active' !== $state ) $review_reasons['required_capability_not_verified'] = true;
            $task_id = 'proposal-' . substr( hash( 'sha256', serialize( array_merge( $task_binding, array( $id ) ) ) ), 0, 32 );
            $tasks[] = array( 'task_contract' => 'mad4b.assistant-task-proposal.v1',
                'task_id' => $task_id, 'assistant_role' => $role, 'capability' => $id,
                'required' => $item['required'], 'reported_state' => $state,
                'decision' => $action, 'observation_trust' => 'UNVERIFIED_CALLER_ASSERTION',
                'approval_required' => true, 'execution_allowed' => false,
                'authority_expansion_allowed' => false );
        }
        $audience = array();
        foreach ( array( 'audience.market.country' => 'country', 'audience.language' => 'language' ) as $key => $field ) {
            if ( isset( $fact_values[ $key ] ) && ! isset( $conflicts[ $key ] ) ) {
                $audience[ $field ] = array( 'proposed_value' => $fact_values[ $key ],
                    'provenance' => 'UNVERIFIED_CALLER_ASSERTION', 'apply_allowed' => false );
            }
        }
        $plan = array( 'contract' => self::CONTRACT, 'binding' => array(
            'environment' => $binding['environment'], 'profile_digest' => $binding['profile_digest'],
            'site_uuid' => $binding['site_uuid'], 'restore_epoch' => $binding['restore_epoch'],
            'origin_sha256' => $binding['origin_sha256'],
            'external_record_sha256' => $binding['external_record_sha256'],
            'runtime_generation' => $binding['runtime_generation'], 'artifact_sha256' => $binding['artifact_sha256'] ),
            'state' => ! empty( $conflicts ) ? 'CONFLICT_REVIEW_REQUIRED'
                : ( ! empty( $review_reasons['context_review_required'] ) ? 'CONTEXT_REVIEW_REQUIRED' : 'PROPOSAL_ONLY' ),
            'readiness' => 'NOT_EXECUTABLE',
            'review_required' => ! empty( $review_reasons ) || ! empty( $conflicts ),
            'review_reasons' => array_keys( $review_reasons ),
            'fact_conflicts' => array_keys( $conflicts ), 'tasks' => $tasks,
            'configuration_proposals' => array( 'audience' => $audience ),
            'input_sha256' => $input_digest,
            'authorizing' => false, 'write_performed' => false, 'provider_calls_performed' => false,
            'plugin_lifecycle_performed' => false, 'production_authorized' => false );
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $plan ) : json_encode( $plan );
        if ( ! is_string( $encoded ) || '' === $encoded ) return self::fail( 'serialization_invalid' );
        $plan['plan_sha256'] = hash( 'sha256', $encoded );
        return $plan;
    }
}

/**
 * Assistant discovery/convergence is additive and read-only. Reuse the
 * existing governed Ability and Adapter lifecycles, never a new writer.
 */
require_once __DIR__ . '/class-mad4b-scp-assistant-convergence.php';
require_once __DIR__ . '/class-mad4b-scp-assistant-task-contract.php';
MAD4B_SCP_Assistant_Convergence::boot();
