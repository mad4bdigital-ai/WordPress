<?php
/**
 * Remote, owner-governed parity for the existing WP-admin native candidate toggle.
 * No arbitrary option/admin action writes, no install, no CI/Production authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_WordPress_Native_Remote_Opt_In {
    const PLAN = 'mad4b/wordpress-native-candidate-opt-in-plan';
    const APPLY = 'mad4b/wordpress-native-candidate-opt-in-apply';
    const DISABLE_CONFIRM = 'DISABLE WORDPRESS NATIVE STAGING CANDIDATES';
    const CONTRACT = 'mad4b.wordpress-native-candidate-remote-consent.v1';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 36 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $mode = array( 'type' => 'string', 'enum' => array( 'enable', 'disable' ) );
        $defs = array(
            array( self::PLAN, 'Plan exact Staging native candidate opt-in',
                'plan', true, array( 'MAD4B_SCP_Policy', 'can_read' ),
                array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'mode' ), 'properties' => array( 'mode' => $mode ) ) ),
            array( self::APPLY, 'Apply exact owner-approved Staging native candidate opt-in',
                'apply', false, array( __CLASS__, 'can_apply' ),
                array( 'type' => 'object', 'additionalProperties' => false,
                    'required' => array( 'mode', 'expected_plan_sha256', 'confirmation' ),
                    'properties' => array(
                        'mode' => $mode,
                        'expected_plan_sha256' => array( 'type' => 'string',
                            'pattern' => '^[a-f0-9]{64}$' ),
                        'confirmation' => array( 'type' => 'string',
                            'enum' => array(
                                MAD4B_SCP_WordPress_Native_Opt_In::CONFIRM,
                                self::DISABLE_CONFIRM ) ) ) ) ),
        );
        foreach ( $defs as $def ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $def[0] ) ) continue;
            wp_register_ability( $def[0], array(
                'label' => $def[1],
                'description' => 'Governed Staging Site Profile option consent; exact read plan, one-time owner OAuth approval and audited rollback. Never builds or installs.',
                'category' => $def[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $def[2] ),
                'permission_callback' => $def[4],
                'input_schema' => $def[5],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $def[3] ? 'read' : 'admin',
                        'production_mutation_allowed' => false ),
                    'annotations' => array(
                        'readonly' => $def[3], 'destructive' => false,
                        'idempotent' => $def[3] ) ),
            ) );
        }
    }

    private static function site() {
        return class_exists( 'MAD4B_SCP_Site_Profile' ) ?
            MAD4B_SCP_Site_Profile::status() : array();
    }
    private static function site_eligible( $site ) {
        if ( ! is_array( $site ) ||
            ! class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' ) )
            return false;
        $policy = MAD4B_SCP_WordPress_Native_Opt_In::site_policy( $site );
        return is_array( $policy ) && ! empty( $policy['eligible'] )
            && function_exists( 'wp_get_environment_type' )
            && 'staging' === wp_get_environment_type()
            && !( function_exists( 'is_multisite' ) && is_multisite() )
            && !( defined( 'MAD4B_MCP_MUTATION_ENABLED' )
                && true !== MAD4B_MCP_MUTATION_ENABLED );
    }
    private static function valid_mode( $mode ) {
        return is_string( $mode ) && in_array( $mode, array( 'enable', 'disable' ), true );
    }
    private static function option_digest() {
        // Binds a plan to the original option record, including site/actor
        // provenance. Never return the option record to remote callers.
        return hash( 'sha256', wp_json_encode( get_option(
            MAD4B_SCP_WordPress_Native_Opt_In::OPTION, array() ) ) );
    }

    public static function plan( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 1 ||
            ! array_key_exists( 'mode', $input ) ||
            ! self::valid_mode( $input['mode'] ) )
            return new WP_Error( 'mad4b_native_remote_plan_input',
                'Exact enable/disable choice required.' );
        $site = self::site();
        $eligible = self::site_eligible( $site );
        $current = class_exists( 'MAD4B_SCP_WordPress_Native_Opt_In' )
            ? MAD4B_SCP_WordPress_Native_Opt_In::enabled() : false;
        $binding = array(
            'contract' => self::CONTRACT . '.plan.v1',
            'mode' => $input['mode'], 'current_enabled' => $current,
            'site_uuid' => is_array( $site ) ? (string) ( $site['site_uuid'] ?? '' ) : '',
            'profile_digest' => is_array( $site ) ? (string) ( $site['profile_digest'] ?? '' ) : '',
            'origin' => is_array( $site ) ? (string) ( $site['canonical_origin'] ?? '' ) : '',
            'host_environment' => function_exists( 'wp_get_environment_type' )
                ? wp_get_environment_type() : 'unknown',
            'option_record_sha256' => self::option_digest(),
        );
        $blockers = $eligible ? array() : array( 'exact_enrolled_staging_opt_in_required' );
        $already = ( 'enable' === $input['mode'] ) === $current;
        return array_merge( $binding, array(
            'eligible' => $eligible, 'blockers' => $blockers,
            'already_current' => $already,
            'next_action' => $already ? 'readback_only'
                : ( $eligible ? 'request_owner_one_time_approval' : 'resolve_blockers' ),
            'plan_sha256' => hash( 'sha256', wp_json_encode( $binding ) ),
            'required_mutation_ability' => self::APPLY,
            'confirmation' => 'enable' === $input['mode']
                ? MAD4B_SCP_WordPress_Native_Opt_In::CONFIRM : self::DISABLE_CONFIRM,
            'exact_one_time_nhi_approval_required' => true,
            'owner_oauth_step_up_required' => true,
            'never_starts_build_or_install' => true,
            'production_authorized' => false, 'authorizing' => false,
            'mutation_performed' => false, 'read_only' => true,
        ) );
    }

    public static function can_apply( $input = array() ) {
        $site = self::site();
        if ( ! self::site_eligible( $site ) ||
            ! function_exists( 'current_user_can' ) ||
            ! current_user_can( 'manage_options' ) ||
            ! current_user_can( 'update_plugins' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) ||
            ! class_exists( 'MAD4B_SCP_Policy' ) ||
            true !== MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_native_remote_owner_required',
                'Exact enrolled Staging WordPress owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope(
                MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ||
            ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ||
            ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_native_remote_step_up_required',
                'Exact owner OAuth step-up required.' );
        // Side-effect-free. Canonical execute wrapper independently claims
        // the exact plan-bound one-time NHI approval and execution budget.
        return true;
    }

    public static function apply( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'mode', 'expected_plan_sha256', 'confirmation',
                '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) ) ||
            ! isset( $input['mode'], $input['expected_plan_sha256'], $input['confirmation'] ) ||
            ! self::valid_mode( $input['mode'] ) ||
            ! is_string( $input['expected_plan_sha256'] ) ||
            1 !== preg_match( '/^[a-f0-9]{64}$/D', $input['expected_plan_sha256'] ) ||
            ! is_string( $input['confirmation'] ) )
            return new WP_Error( 'mad4b_native_remote_apply_input',
                'Exact mode, plan hash and confirmation required.' );
        $permission = self::can_apply( $input );
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        if ( ! method_exists( 'MAD4B_SCP_Authorization', 'execution_callback_started' ) ||
            ! MAD4B_SCP_Authorization::execution_callback_started( self::APPLY ) )
            return new WP_Error( 'mad4b_native_remote_execution_claim_required',
                'Canonical one-time governed claim required.' );
        $plan = self::plan( array( 'mode' => $input['mode'] ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['eligible'] ) ||
            ! hash_equals( $plan['plan_sha256'], $input['expected_plan_sha256'] ) ||
            ! hash_equals( $plan['confirmation'], $input['confirmation'] ) )
            return new WP_Error( 'mad4b_native_remote_plan_drift',
                'Site, option or owner consent changed. Review a fresh plan.' );
        if ( ! empty( $plan['already_current'] ) )
            return array( 'contract' => self::CONTRACT . '.apply.v1',
                'state' => 'ALREADY_CURRENT', 'enabled' => 'enable' === $input['mode'],
                'mutation_performed' => false, 'production_authorized' => false );

        $site = self::site();
        $prior = get_option( MAD4B_SCP_WordPress_Native_Opt_In::OPTION, array() );
        $record = array( 'enabled' => 'enable' === $input['mode'],
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'canonical_origin' => (string) $site['canonical_origin'],
            'approved_by_user_id' => get_current_user_id(), 'saved_at' => time() );
        update_option( MAD4B_SCP_WordPress_Native_Opt_In::OPTION, $record, false );
        if ( get_option( MAD4B_SCP_WordPress_Native_Opt_In::OPTION, array() ) !== $record ) {
            update_option( MAD4B_SCP_WordPress_Native_Opt_In::OPTION, $prior, false );
            return new WP_Error( 'mad4b_native_remote_persistence_uncertain',
                'Option persistence failed; reconcile readback before retry.' );
        }
        $audited = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::record(
            'mad4b/wordpress-native-candidate-opt-in',
            array( 'site_uuid' => $site['site_uuid'],
                'profile_digest' => $site['profile_digest'],
                'enabled' => $record['enabled'], 'source' => 'governed_remote_owner_approved',
                'plan_sha256' => $plan['plan_sha256'],
                'production_authorized' => false ), 'success'
        ) : new WP_Error( 'mad4b_native_remote_audit_missing', 'Audit unavailable.' );
        if ( is_wp_error( $audited ) ) {
            update_option( MAD4B_SCP_WordPress_Native_Opt_In::OPTION, $prior, false );
            return new WP_Error( 'mad4b_native_remote_audit_uncertain',
                'Audit failed; prior setting restoration attempted. Readback required.' );
        }
        $effective = MAD4B_SCP_WordPress_Native_Opt_In::enabled();
        if ( $effective !== $record['enabled'] )
            return new WP_Error( 'mad4b_native_remote_readback_uncertain',
                'Effective opt-in does not match audited update. Reconcile before retry.' );
        return array( 'contract' => self::CONTRACT . '.apply.v1',
            'state' => 'VERIFIED', 'enabled' => $effective,
            'site_uuid' => (string) $site['site_uuid'],
            'profile_digest' => (string) $site['profile_digest'],
            'plan_sha256' => $plan['plan_sha256'],
            'no_build_or_install_started' => true,
            'mutation_performed' => true, 'production_authorized' => false );
    }
}
