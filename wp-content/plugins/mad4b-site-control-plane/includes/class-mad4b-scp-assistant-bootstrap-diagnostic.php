<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Enrollment-safe READ-only bootstrap advisor. Never enrolls a site,
 * initializes a restore epoch, rewrites grants, or recommends bypassing auth.
 * WordPress administrator read permission remains mandatory.
 */
final class MAD4B_SCP_Assistant_Bootstrap_Diagnostic {
    private static $booted = false;
    const CONTRACT = 'mad4b.assistant-bootstrap-diagnostic.v1';
    const ABILITY = 'mad4b/assistant-bootstrap-diagnostic';

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 41 );
        add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 41 );
    }

    /**
     * Register through the existing governed adapter inventory. A standalone
     * WordPress Ability is not automatically an exposed ChatGPT MCP tool.
     */
    public static function register_adapter( $registry ) {
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' )
            || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return;
        $registry->register( new class extends MAD4B_SCP_Adapter_Base {
            public function id() { return 'assistant-bootstrap'; }
            public function label() { return 'Assistant Bootstrap Diagnostics'; }
            public function is_available() { return true; }
            public function ability_names() { return array(
                'read' => array( MAD4B_SCP_Assistant_Bootstrap_Diagnostic::ABILITY ),
                'content' => array(), 'admin' => array(),
            ); }
            public function register_abilities() {
                MAD4B_SCP_Assistant_Bootstrap_Diagnostic::register_ability();
            }
            protected function mutation_requires_certification() { return false; }
            protected function provider_certification( $available ) { return null; }
        } );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Assistant Bootstrap Diagnostic (Read Only)',
            'description' => 'Safe advisory on enrollment, origin and runtime evidence prerequisites without initializing them or changing authority.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'status' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    /** Require exact read-only adapter mapping, not merely an object under a familiar ID. */
    private static function read_adapter_valid( $adapter, $ability ) {
        if ( ! class_exists( 'MAD4B_SCP_Adapter_Base', false )
            || ! is_object( $adapter ) || ! ( $adapter instanceof MAD4B_SCP_Adapter_Base )
            || ! method_exists( $adapter, 'ability_names' ) ) return false;
        $names = $adapter->ability_names();
        return is_array( $names ) && count( $names ) === 3
            && isset( $names['read'], $names['content'], $names['admin'] )
            && $names['read'] === array( $ability )
            && $names['content'] === array() && $names['admin'] === array();
    }

    public static function status( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) > 0 ) return new WP_Error(
            'mad4b_assistant_bootstrap_input_invalid', 'Bootstrap diagnostic accepts no input.', array( 'authorizing' => false ) );
        $enrolled = class_exists( 'MAD4B_SCP_Site_Profile', false )
            && MAD4B_SCP_Site_Profile::configured();
        $environment = $enrolled && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' )
            ? MAD4B_SCP_Site_Profile::current_environment()
            : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' );
        if ( ! in_array( $environment, array( 'staging', 'development', 'local', 'production' ), true ) ) $environment = 'unknown';
        $origin = $enrolled && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
        $blockers = array();
        $actions = array();
        if ( ! $enrolled ) {
            $blockers[] = 'site_profile_unconfigured';
            $actions[] = 'review_site_enrollment';
        } elseif ( ! $origin ) {
            $blockers[] = 'site_origin_not_bound';
            $actions[] = 'verify_site_origin_and_profile_revision';
        }
        $binding_ready = false;
        if ( $enrolled && $origin ) {
            if ( ! class_exists( 'MAD4B_SCP_Adaptive_Operations_Context', false ) ) {
                $blockers[] = 'runtime_context_unavailable';
            } else {
                // current() is a READ-only exact-generation diagnostic and
                // explicitly refuses missing restore anchors instead of creating them.
                $binding = MAD4B_SCP_Adaptive_Operations_Context::current();
                $binding_ready = is_array( $binding ) && 'mad4b.adaptive-operations-context.v1' === ( $binding['contract'] ?? null );
                if ( ! $binding_ready ) {
                    $error_code = is_wp_error( $binding ) ? $binding->get_error_code() : '';
                    $known = array(
                        'mad4b_adaptive_runtime_binding_unavailable', 'mad4b_adaptive_runtime_generation_invalid',
                        'mad4b_adaptive_artifact_manifest_unverified', 'mad4b_adaptive_restore_epoch_unverified',
                        'mad4b_adaptive_binding_contract_invalid', 'mad4b_adaptive_binding_profile_digest_invalid',
                        'mad4b_adaptive_binding_origin_sha256_invalid', 'mad4b_adaptive_binding_artifact_sha256_invalid',
                    );
                    $blockers[] = in_array( $error_code, $known, true ) ? $error_code : 'runtime_evidence_not_verified';
                }
            }
            if ( ! $binding_ready ) $actions[] = 'review_readonly_runtime_and_restore_evidence';
        }
        // Read-only, explicit runtime registration witness. This does not
        // invoke provider discovery, register abilities or create an adapter.
        $plan_ability = class_exists( 'MAD4B_SCP_Assistant_Planning', false )
            ? MAD4B_SCP_Assistant_Planning::ABILITY : 'mad4b/assistant-plan';
        $hooks_bound = function_exists( 'has_action' )
            && false !== has_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Assistant_Planning', 'register_ability' ) )
            && false !== has_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ) )
            && false !== has_action( 'mad4b_scp_register_adapters', array( 'MAD4B_SCP_Assistant_Planning', 'register_adapter' ) )
            && false !== has_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ) );
        $abilities_observed = function_exists( 'did_action' ) && did_action( 'wp_abilities_api_init' ) > 0;
        $plan_visible = $abilities_observed && function_exists( 'wp_has_ability' ) && wp_has_ability( $plan_ability );
        $diagnostic_visible = $abilities_observed && function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY );
        $registry_observed = class_exists( 'MAD4B_SCP_Adapter_Registry', false );
        $plan_adapter = $registry_observed ? MAD4B_SCP_Adapter_Registry::instance()->get( 'assistant-planning' ) : null;
        $bootstrap_adapter = $registry_observed ? MAD4B_SCP_Adapter_Registry::instance()->get( 'assistant-bootstrap' ) : null;
        $plan_adapter_valid = self::read_adapter_valid( $plan_adapter, $plan_ability );
        $bootstrap_adapter_valid = self::read_adapter_valid( $bootstrap_adapter, self::ABILITY );
        $adapters_visible = $plan_adapter_valid && $bootstrap_adapter_valid;
        $read_registration = array(
            'contract' => 'mad4b.assistant-read-registration-witness.v1',
            'hooks_bound' => $hooks_bound,
            'abilities_lifecycle_observed' => $abilities_observed,
            'planning_ability_visible' => (bool) $plan_visible,
            'bootstrap_ability_visible' => (bool) $diagnostic_visible,
            'planning_adapter_visible' => $plan_adapter_valid,
            'bootstrap_adapter_visible' => $bootstrap_adapter_valid,
            'read_catalog_local_ready' => $hooks_bound && $plan_visible && $diagnostic_visible && $adapters_visible,
            'external_mcp_catalog_verified' => false,
            'mutation_performed' => false,
        );
        if ( $abilities_observed && ! $read_registration['read_catalog_local_ready'] ) {
            $blockers[] = 'assistant_runtime_registration_unverified';
            $actions[] = 'verify_assistant_ability_and_adapter_lifecycle';
        }
        return array(
            'contract' => self::CONTRACT,
            'assistant_read_registration' => $read_registration,
            'environment' => $environment,
            'profile_configured' => $enrolled,
            'origin_verified' => $origin,
            'exact_runtime_evidence_ready' => $binding_ready,
            // Exact restore/runtime identity alone cannot make an unregistered
            // tool usable. A missing/widened Adapter or Ability fails closed.
            'preview_eligible' => $binding_ready && $read_registration['read_catalog_local_ready'],
            'blockers' => $blockers,
            'next_safe_actions' => $actions,
            'ready_for_mutation' => false,
            'site_enrollment_performed' => false,
            'restore_initialization_performed' => false,
            'provider_calls_performed' => false,
            'write_grants_created' => false,
            'automatic_install_allowed' => false,
            'production_mutation_allowed' => false,
            'authorizing' => false,
            'read_only' => true,
            'mutation_performed' => false,
        );
    }
}
