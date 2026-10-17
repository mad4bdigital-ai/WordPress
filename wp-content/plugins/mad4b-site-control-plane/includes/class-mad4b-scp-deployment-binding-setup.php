<?php
/**
 * Host-secret-backed, exact Staging Site Profile binding enrollment.
 * The host secret is ONLY read via the canonical Site Profile API; neither
 * this class nor MCP generates, receives, logs or stores its plaintext.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Deployment_Binding_Setup {
    const CONTRACT = 'mad4b.deployment-binding-setup.v1';
    const STATUS = 'mad4b/deployment-binding-setup-status';
    const PLAN = 'mad4b/deployment-binding-setup-plan';
    const APPLY = 'mad4b/deployment-binding-setup-apply';
    const CONFIRM = 'BIND EXISTING STAGING SITE IDENTITY';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $empty = array( 'type' => 'object', 'properties' => array(),
            'additionalProperties' => false );
        $write = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
                'expected_site_uuid' => array( 'type' => 'string', 'maxLength' => 36 ),
                'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                '_mad4b_approval_ticket_id' => array( 'type' => 'string', 'maxLength' => 36,
                    'pattern' => '^[a-fA-F0-9-]{36}$' ),
            ),
            'required' => array( 'expected_plan_sha256', 'expected_profile_digest',
                'expected_revision', 'expected_site_uuid', 'confirmation',
                '_mad4b_approval_ticket_id' ) );
        $specs = array(
            array( self::STATUS, 'status', $empty, true ),
            array( self::PLAN, 'plan', $empty, true ),
            array( self::APPLY, 'apply', $write, false ),
        );
        foreach ( $specs as $spec ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $spec[0] ) ) continue;
            wp_register_ability( $spec[0], array(
                'label' => 'MAD4B Deployment Binding ' . ucfirst( $spec[1] ),
                'description' => 'Staging-only independent host-secret identity binding; preserves all existing Site Profile fields.',
                'category' => $spec[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $spec[1] ),
                'permission_callback' => $spec[3] ? array( __CLASS__, 'can_read' )
                    : array( __CLASS__, 'can_apply' ),
                'input_schema' => $spec[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $spec[3] ? 'read' : 'write',
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $spec[3],
                        'destructive' => ! $spec[3], 'idempotent' => $spec[3] ) ),
            ) );
        }
    }
    public static function can_read() {
        return function_exists( 'current_user_can' )
            && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }
    public static function can_apply( $input = null ) {
        if ( ! self::can_read() || ! class_exists( 'MAD4B_SCP_Site_Profile_Enrollment' ) )
            return new WP_Error( 'mad4b_binding_admin_required', 'Enrolled Staging owner required.' );
        $identity = MAD4B_SCP_Site_Profile_Enrollment::can_execute( $input );
        if ( is_wp_error( $identity ) || true !== $identity ) return $identity;
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_binding_chatgpt_client_required', 'Exact enrolled ChatGPT client required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_binding_governance_unavailable', 'Governed write authorization unavailable.' );
        $decision = MAD4B_SCP_Authorization::authorize_mutation(
            self::APPLY, 'mad4b-write', 'core', is_array( $input ) ? $input : array() );
        return MAD4B_SCP_Authorization::permission_result_from_authorization( $decision );
    }
    private static function denied( $reason ) {
        return new WP_Error( 'mad4b_binding_' . $reason,
            'Trusted exact Staging Host binding or authorization is not ready.' );
    }
    public static function assess( $site, $stored, $host_digest ) {
        if ( ! is_array( $site ) || ! is_array( $stored )
            || 'staging' !== ( $site['configured_environment'] ?? '' )
            || 'staging' !== ( $site['wordpress_environment'] ?? '' )
            || empty( $site['origin_match'] ) || empty( $site['environment_match'] )
            || empty( $site['authority_ready'] ) || ! empty( $site['mutation_pending_audit'] )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $site['site_uuid'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $site['profile_digest'] ?? '' ) )
            || (string) ( $stored['site_uuid'] ?? '' ) !== (string) ( $site['site_uuid'] ?? '' )
            || (string) ( $stored['canonical_origin'] ?? '' ) !== (string) ( $site['canonical_origin'] ?? '' )
            || (string) ( $stored['environment'] ?? '' ) !== 'staging'
            || (int) ( $stored['revision'] ?? 0 ) !== (int) ( $site['revision'] ?? -1 ) )
            return array( 'state' => 'EXACT_STAGING_IDENTITY_BLOCKED',
                'ready_to_bind' => false, 'reason' => 'invalid_or_foreign_site_profile' );
        $stored_digest = (string) ( $stored['deployment_binding_digest'] ?? '' );
        $host_ok = is_string( $host_digest )
            && 1 === preg_match( '/^[a-f0-9]{64}$/D', $host_digest );
        if ( '' !== $stored_digest ) return array(
            'state' => $host_ok && hash_equals( $stored_digest, $host_digest )
                ? 'ALREADY_BOUND' : 'STORED_HOST_BINDING_MISMATCH',
            'ready_to_bind' => false,
            'reason' => 'existing_binding_must_not_be_replaced' );
        return array(
            'state' => $host_ok ? 'READY_FOR_EXACT_OWNER_APPROVAL' : 'HOST_SECRET_NOT_CONFIGURED',
            'ready_to_bind' => $host_ok,
            'reason' => $host_ok ? '' : 'provision_host_secret_outside_wordpress_database' );
    }
    private static function snapshot() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! method_exists( 'MAD4B_SCP_Site_Profile', 'deployment_binding_digest' ) )
            return self::denied( 'profile_unavailable' );
        $site = MAD4B_SCP_Site_Profile::status();
        $stored = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        if ( ! is_array( $stored ) || ! is_array( $site ) )
            return self::denied( 'stored_profile_missing' );
        $host = MAD4B_SCP_Site_Profile::deployment_binding_digest();
        $status = self::assess( $site, $stored, $host );
        return array( 'site' => $site, 'stored' => $stored,
            'host_digest' => $host, 'assessment' => $status );
    }
    public static function status( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return self::denied( 'invalid_read_input' );
        $snap = self::snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        return array( 'contract' => self::CONTRACT . '.status.v1',
            'state' => $snap['assessment']['state'],
            'host_secret_configured' => '' !== $snap['host_digest'],
            'stored_binding_present' => '' !== (string) ( $snap['stored']['deployment_binding_digest'] ?? '' ),
            'ready_to_bind' => $snap['assessment']['ready_to_bind'],
            'site_uuid' => (string) ( $snap['site']['site_uuid'] ?? '' ),
            'site_profile_revision' => (int) ( $snap['site']['revision'] ?? 0 ),
            'next_ability' => $snap['assessment']['ready_to_bind'] ? self::PLAN
                : ( 'ALREADY_BOUND' === $snap['assessment']['state']
                    ? 'mad4b/deployment-mode-status' : '' ),
            'next_external_action' => 'HOST_SECRET_NOT_CONFIGURED' === $snap['assessment']['state']
                ? 'independent_protected_host_configuration_with_owner_approval'
                : '', 'secret_value_exposed' => false, 'production_authorized' => false,
            'mutation_performed' => false, 'read_only' => true );
    }
    public static function plan( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return self::denied( 'invalid_plan_input' );
        $snap = self::snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        $site = $snap['site'];
        $basis = array( 'contract' => self::CONTRACT . '.plan.v1',
            'site_uuid' => (string) ( $site['site_uuid'] ?? '' ),
            'revision' => (int) ( $site['revision'] ?? 0 ),
            'profile_digest' => (string) ( $site['profile_digest'] ?? '' ),
            'canonical_origin' => (string) ( $site['canonical_origin'] ?? '' ),
            'environment' => (string) ( $site['configured_environment'] ?? '' ),
            'host_binding_digest' => $snap['host_digest'],
            'state' => $snap['assessment']['state'] );
        $hash = hash( 'sha256', wp_json_encode( $basis,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        return array( 'contract' => self::CONTRACT . '.plan.v1',
            'state' => $snap['assessment']['state'],
            'eligible' => $snap['assessment']['ready_to_bind'],
            'plan_sha256' => $snap['assessment']['ready_to_bind'] ? $hash : '',
            'expected_site_uuid' => $basis['site_uuid'],
            'expected_revision' => $basis['revision'],
            'expected_profile_digest' => $basis['profile_digest'],
            'confirmation' => self::CONFIRM,
            'next_ability' => $snap['assessment']['ready_to_bind'] ? self::APPLY : '',
            'host_secret_configured' => '' !== $snap['host_digest'],
            'host_secret_returned' => false, 'identity_replaced' => false,
            'existing_feature_flags_preserved' => true, 'write_authority_unchanged' => true,
            'requires_separate_exact_owner_approval' => true,
            'production_authorized' => false, 'read_only' => true );
    }
    public static function apply( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'expected_plan_sha256', 'expected_profile_digest',
                'expected_revision', 'expected_site_uuid', 'confirmation',
                '_mad4b_approval_ticket_id' ) )
            || ! isset( $input['expected_revision'] )
            || ! is_int( $input['expected_revision'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_profile_digest'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $input['expected_site_uuid'] ?? '' ) )
            || self::CONFIRM !== (string) ( $input['confirmation'] ?? '' )
            || ! preg_match( '/^[a-f0-9-]{36}$/Di', (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ) ) )
            return self::denied( 'exact_plan_ticket_required' );
        $permission = self::can_apply( $input );
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        $plan = self::plan();
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['eligible'] )
            || ! hash_equals( (string) $plan['plan_sha256'],
                (string) $input['expected_plan_sha256'] )
            || ! hash_equals( (string) $plan['expected_profile_digest'],
                (string) $input['expected_profile_digest'] )
            || ! hash_equals( (string) $plan['expected_site_uuid'],
                (string) $input['expected_site_uuid'] )
            || (int) $plan['expected_revision'] !== $input['expected_revision'] )
            return self::denied( 'stale_or_ineligible_plan' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return self::denied( 'governance_missing' );
        $claim = MAD4B_SCP_Authorization::claim_mutation(
            self::APPLY, 'mad4b-write', 'core', $input );
        if ( is_wp_error( $claim ) || ! is_array( $claim )
            || true !== ( $claim['allowed'] ?? false ) )
            return is_wp_error( $claim ) ? $claim : self::denied( 'approval_not_claimed' );
        // A second exact check prevents host re-key or concurrent profile
        // changes between the approved plan and durable Site Profile commit.
        $again = self::plan();
        if ( is_wp_error( $again ) || empty( $again['eligible'] )
            || ! hash_equals( (string) $input['expected_plan_sha256'],
                (string) ( $again['plan_sha256'] ?? '' ) ) )
            return self::denied( 'precommit_snapshot_drift' );
        $snap = self::snapshot();
        if ( is_wp_error( $snap ) ) return $snap;
        $before = $snap['stored'];
        if ( ! empty( $before['mutation_state'] )
            || '' !== (string) ( $before['deployment_binding_digest'] ?? '' )
            || '' === $snap['host_digest'] )
            return self::denied( 'profile_no_longer_unbound' );
        $after = $before;
        $after['revision'] = (int) $before['revision'] + 1;
        $after['deployment_binding_digest'] = $snap['host_digest'];
        $after['updated_at'] = gmdate( 'c' );
        $commit = MAD4B_SCP_Site_Profile::commit_record_with_audit(
            $before, $after, 'mad4b/deployment-binding-enrolled',
            array( 'site_uuid' => $before['site_uuid'],
                'staging_only' => true, 'origin_unchanged' => true,
                'host_secret_present' => true, 'secret_material_logged' => false,
                'feature_flags_unchanged' => true ),
            'mad4b_deployment_binding_setup' );
        if ( is_wp_error( $commit ) ) return $commit;
        MAD4B_SCP_Site_Profile::reset_cache();
        if ( function_exists( 'do_action' ) )
            do_action( 'mad4b_scp_site_profile_saved' );
        $status = MAD4B_SCP_Site_Profile::status();
        $persisted = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
        $verify = is_array( $persisted )
            && is_array( $status )
            && (int) ( $status['revision'] ?? 0 ) === (int) $after['revision']
            && (string) ( $status['site_uuid'] ?? '' ) === (string) $before['site_uuid']
            && (string) ( $status['canonical_origin'] ?? '' ) === (string) $before['canonical_origin']
            && ! empty( $status['deployment_binding_configured'] )
            && ! empty( $status['deployment_binding_bound'] )
            && ! empty( $status['deployment_binding_match'] )
            && $persisted === $after;
        return array( 'contract' => self::CONTRACT . '.apply.v1',
            'state' => $verify ? 'BOUND_INDEPENDENT_READBACK_REQUIRED'
                : 'POSTCONDITION_RECONCILIATION_REQUIRED',
            'binding_verified' => $verify,
            'site_uuid_unchanged' => (string) ( $status['site_uuid'] ?? '' )
                === (string) $before['site_uuid'],
            'profile_revision' => (int) ( $status['revision'] ?? 0 ),
            'next_readback' => self::STATUS,
            'next_scope_diagnostic' => 'mad4b/deployment-mode-status',
            'source_context_certified' => false, 'production_mutation' => false,
            'raw_host_secret_stored_in_database' => false );
    }
}
