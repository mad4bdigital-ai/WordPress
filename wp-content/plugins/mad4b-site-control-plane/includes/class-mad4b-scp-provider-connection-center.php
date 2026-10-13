<?php
/**
 * Provider-independent connection lifecycle discovery and bounded remote
 * recovery. A remote agent cannot complete third-party OAuth consent:
 * authorization is a same-origin, owner-driven handoff, while refresh of a
 * saved token requires a separate exact one-time mutation approval.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Provider_Connection_Center {
    const CONTRACT = 'mad4b.provider-connection-center.v1';
    const DISCOVER = 'mad4b/provider-connection-discover';
    const PLAN = 'mad4b/provider-connection-plan';
    const REFRESH = 'mad4b/provider-connection-refresh';
    const CONFIRM = 'REFRESH EXISTING STAGING PROVIDER GRANT';
    const PROVIDER_GOOGLE = 'google_drive';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    private static function catalogue() {
        // Only a first-party reviewed adapter can expose executable remote
        // refresh. Extensions may participate in discover/plan but are NOT
        // automatically granted a remote mutation by this registry.
        $providers = array(
            self::PROVIDER_GOOGLE => array(
                'label' => 'Google Workspace / Drive',
                'kind' => 'oauth2',
                'status_callback' => array( 'MAD4B_SCP_Google_Drive_Context', 'public_connection_status' ),
                'identity_callback' => array( 'MAD4B_SCP_Google_Drive_Context', 'encrypted_grant_identity_sha256' ),
                'refresh_callback' => array( 'MAD4B_SCP_Google_Drive_Context', 'refresh_existing_connection' ),
                'handoff_tab' => 'google-drive',
                'remote_refresh_reviewed' => true,
            ),
        );
        // Site extensions must register a descriptive adapter with at least
        // a status callback and an explicit per-provider OAuth handoff.
        // Unreviewed adapters are discovery-only, even if they claim a
        // refresh_callback in their metadata.
        $extra = apply_filters( 'mad4b_provider_connection_adapters', array() );
        if ( is_array( $extra ) ) foreach ( array_slice( $extra, 0, 32 ) as $key => $row ) {
            if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key )
                || isset( $providers[ $key ] ) || ! is_array( $row )
                || ! isset( $row['status_callback'] ) || ! is_callable( $row['status_callback'] ) ) continue;
            $providers[ $key ] = array(
                'label' => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $key,
                'kind' => in_array( (string) ( $row['kind'] ?? '' ), array( 'oauth2', 'api_key', 'service_account' ), true )
                    ? (string) $row['kind'] : 'other',
                'status_callback' => $row['status_callback'],
                'handoff_url' => isset( $row['handoff_url'] ) && is_string( $row['handoff_url'] )
                    ? $row['handoff_url'] : '',
                'remote_refresh_reviewed' => false,
            );
        }
        return $providers;
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $provider = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9_-]*$' );
        $read = array( 'type' => 'object', 'properties' => array( 'provider' => $provider ), 'additionalProperties' => false );
        $write = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'provider' => $provider,
                'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_site_uuid' => array( 'type' => 'string', 'pattern' => '^[a-f0-9-]{36}$' ),
                'expected_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
                'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                '_mad4b_approval_ticket_id' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ),
            ),
            'required' => array( 'provider', 'expected_plan_sha256', 'expected_site_uuid',
                'expected_profile_revision', 'expected_profile_digest', 'confirmation' ) );
        foreach ( array(
            array( self::DISCOVER, 'discover', $read, true ),
            array( self::PLAN, 'plan', $read, true ),
            array( self::REFRESH, 'refresh', $write, false ),
        ) as $row ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $row[0] ) ) continue;
            wp_register_ability( $row[0], array(
                'label' => 'MAD4B Provider Connection ' . ucfirst( $row[1] ),
                'description' => 'Discover exact provider connection state, plan owner OAuth handoff, or separately approve a saved-credential refresh. No remote OAuth consent or automatic grant expansion.',
                'category' => $row[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $row[1] ),
                'permission_callback' => $row[3] ? array( __CLASS__, 'can_read' ) : array( __CLASS__, 'can_refresh' ),
                'input_schema' => $row[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $row[3] ? 'read' : 'write',
                        'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $row[3], 'destructive' => ! $row[3],
                        'idempotent' => $row[3] ) ),
            ) );
        }
    }

    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }

    private static function scope( $for_mutation = false ) {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return new WP_Error( 'mad4b_provider_site_unavailable', 'Site Profile unavailable.' );
        $s = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $s ) || empty( $s['origin_match'] ) || empty( $s['environment_match'] )
            || empty( $s['site_uuid'] ) || empty( $s['profile_digest'] ) )
            return new WP_Error( 'mad4b_provider_site_identity_unverified', 'Exact current Site Profile required.' );
        if ( $for_mutation && ( 'staging' !== (string) ( $s['configured_environment'] ?? '' )
            || 'staging' !== (string) ( $s['wordpress_environment'] ?? '' )
            || empty( $s['authority_ready'] ) || ! empty( $s['mutation_pending_audit'] ) ) )
            return new WP_Error( 'mad4b_provider_remote_staging_only', 'Only enrolled Staging provider refresh can run remotely.' );
        return $s;
    }

    private static function handoff( $provider, $row ) {
        if ( self::PROVIDER_GOOGLE === $provider )
            return admin_url( 'admin.php?page=mad4b-control-plane-context&tab=google-drive' );
        $url = isset( $row['handoff_url'] ) ? (string) $row['handoff_url'] : '';
        if ( ! $url || ! function_exists( 'wp_validate_redirect' ) ) return '';
        // No arbitrary cross-origin OAuth URL can be promoted to a trusted
        // wp-admin handoff by extension metadata.
        $home = admin_url( 'admin.php' );
        return 0 === strpos( $url, dirname( $home ) . '/' ) && wp_validate_redirect( $url, '' ) ? $url : '';
    }

    public static function discover( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input )
            || array_diff( array_keys( $input ), array( 'provider' ) ) )
            return new WP_Error( 'mad4b_provider_discover_denied', 'Exact administrator read required.' );
        $site = self::scope();
        if ( is_wp_error( $site ) ) return $site;
        $filter = isset( $input['provider'] ) ? (string) $input['provider'] : '';
        $rows = array();
        foreach ( self::catalogue() as $id => $row ) {
            if ( $filter && $id !== $filter ) continue;
            $status = is_callable( $row['status_callback'] ) ? call_user_func( $row['status_callback'] ) : array();
            if ( ! is_array( $status ) ) $status = array();
            // Strictly project a public subset: NEVER forward arbitrary
            // adapter account IDs, credentials, response bodies or scopes.
            $rows[] = array(
                'provider' => $id, 'label' => (string) $row['label'],
                'kind' => (string) $row['kind'],
                'health_state' => sanitize_key( (string) ( $status['health_state'] ?? 'unknown' ) ),
                'connected' => ! empty( $status['connected'] ),
                'refresh_required' => ! empty( $status['refresh_required'] ),
                'reconnect_required' => ! empty( $status['reconnect_required'] ),
                'revocation_pending' => ! empty( $status['revocation_pending'] ),
                'remote_refresh_available' => ! empty( $row['remote_refresh_reviewed'] )
                    && is_callable( $row['refresh_callback'] ?? null ),
                'owner_handoff_url' => self::handoff( $id, $row ),
            );
        }
        return array( 'contract' => self::CONTRACT . '.discover.v1',
            'site_uuid' => (string) $site['site_uuid'], 'providers' => $rows,
            'count' => count( $rows ), 'read_only' => true, 'mutation_performed' => false );
    }

    public static function plan( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || array_diff( array_keys( $input ), array( 'provider' ) ) )
            return new WP_Error( 'mad4b_provider_plan_denied', 'Read-only provider plan required.' );
        $provider = isset( $input['provider'] ) ? (string) $input['provider'] : '';
        $catalogue = self::catalogue();
        if ( ! isset( $catalogue[ $provider ] ) ) return new WP_Error( 'mad4b_provider_unknown', 'No registered exact provider adapter.' );
        $site = self::scope();
        if ( is_wp_error( $site ) ) return $site;
        $row = $catalogue[ $provider ];
        $status = call_user_func( $row['status_callback'] );
        if ( ! is_array( $status ) ) return new WP_Error( 'mad4b_provider_status_unavailable', 'Provider health could not be verified.' );
        $refresh = ! empty( $status['connected'] ) && ! empty( $status['refresh_required'] )
            && empty( $status['revocation_pending'] ) && empty( $status['reconnect_required'] )
            && empty( $status['refresh_failed'] ) && ! empty( $row['remote_refresh_reviewed'] );
        $action = ! empty( $status['revocation_pending'] ) ? 'resolve_pending_revocation'
            : ( $refresh ? 'refresh' : ( ! empty( $status['reconnect_required'] ) || empty( $status['connected'] )
                ? 'oauth_handoff' : 'no_action' ) );
        $grant_identity = '';
        if ( $refresh && isset( $row['identity_callback'] ) && is_callable( $row['identity_callback'] ) ) {
            $grant_identity = call_user_func( $row['identity_callback'] );
            if ( is_wp_error( $grant_identity ) || ! is_string( $grant_identity ) ) $grant_identity = '';
        }
        $basis = array( 'provider' => $provider,
            'site_uuid' => (string) $site['site_uuid'], 'profile_revision' => (int) $site['revision'],
            'profile_digest' => (string) $site['profile_digest'],
            'health_state' => (string) ( $status['health_state'] ?? 'unknown' ),
            'grant_identity_sha256' => $grant_identity,
            'action' => $action );
        return array( 'contract' => self::CONTRACT . '.plan.v1',
            'provider' => $provider, 'action' => $action,
            'eligible_remote_refresh' => $refresh && '' !== $grant_identity
                && 'staging' === (string) ( $site['configured_environment'] ?? '' ),
            'plan_sha256' => hash( 'sha256', wp_json_encode( $basis,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'expected_site_uuid' => (string) $site['site_uuid'],
            'expected_profile_revision' => (int) $site['revision'],
            'expected_profile_digest' => (string) $site['profile_digest'],
            'confirmation' => self::CONFIRM,
            'owner_handoff_url' => self::handoff( $provider, $row ),
            'consent_requires_browser' => in_array( $action, array( 'oauth_handoff', 'resolve_pending_revocation' ), true ),
            'one_time_approval_required_for_remote_refresh' => true,
            'next_ability' => $refresh ? self::REFRESH : '',
            'read_only' => true, 'mutation_performed' => false,
            'production_mutation_allowed' => false );
    }

    public static function can_refresh( $input = null ) {
        if ( ! self::can_read() || ! class_exists( 'MAD4B_SCP_Site_Profile_Enrollment' )
            || ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_provider_approval_unavailable', 'Owner remote authorization unavailable.' );
        $site = self::scope( true );
        if ( is_wp_error( $site ) ) return $site;
        $admission = MAD4B_SCP_Site_Profile_Enrollment::can_execute( $input );
        if ( is_wp_error( $admission ) || true !== $admission ) return $admission;
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_provider_verified_client_required', 'Verified enrolled OAuth bearer required.' );
        $decision = MAD4B_SCP_Authorization::authorize_mutation(
            self::REFRESH, 'mad4b-write', 'core', is_array( $input ) ? $input : array() );
        return MAD4B_SCP_Authorization::permission_result_from_authorization( $decision );
    }

    public static function refresh( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array(
            'provider', 'expected_plan_sha256', 'expected_site_uuid',
            'expected_profile_revision', 'expected_profile_digest',
            'confirmation', '_mad4b_approval_ticket_id' ) )
            || ! is_int( $input['expected_profile_revision'] ?? null )
            || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', (string) ( $input['provider'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $input['expected_site_uuid'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_profile_digest'] ?? '' ) )
            || self::CONFIRM !== (string) ( $input['confirmation'] ?? '' )
            || ! preg_match( '/^[a-f0-9-]{36}$/Di', (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ) ) )
            return new WP_Error( 'mad4b_provider_remote_ticket_required', 'Exact owner approval ticket and Staging plan required.' );
        $allowed = self::can_refresh( $input );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        $plan = self::plan( array( 'provider' => $input['provider'] ) );
        if ( is_wp_error( $plan ) || empty( $plan['eligible_remote_refresh'] )
            || ! hash_equals( (string) $plan['plan_sha256'], $input['expected_plan_sha256'] )
            || ! hash_equals( (string) $plan['expected_site_uuid'], $input['expected_site_uuid'] )
            || ! hash_equals( (string) $plan['expected_profile_digest'], $input['expected_profile_digest'] )
            || (int) $plan['expected_profile_revision'] !== $input['expected_profile_revision'] )
            return new WP_Error( 'mad4b_provider_remote_plan_stale', 'Provider/site/grant changed since planning.' );
        $claimed = MAD4B_SCP_Authorization::claim_mutation(
            self::REFRESH, 'mad4b-write', 'core', $input );
        if ( is_wp_error( $claimed ) || ! is_array( $claimed ) || empty( $claimed['allowed'] ) )
            return is_wp_error( $claimed ) ? $claimed : new WP_Error( 'mad4b_provider_claim_denied', 'Approval could not be consumed.' );
        $again = self::plan( array( 'provider' => $input['provider'] ) );
        if ( is_wp_error( $again ) || ! hash_equals( (string) $again['plan_sha256'], $plan['plan_sha256'] ) )
            return new WP_Error( 'mad4b_provider_remote_recheck_failed', 'Provider changed during approval claim.' );
        $row = self::catalogue()[ $input['provider'] ];
        if ( empty( $row['remote_refresh_reviewed'] ) || ! is_callable( $row['refresh_callback'] )
            || ! is_callable( $row['identity_callback'] ) )
            return new WP_Error( 'mad4b_provider_unreviewed_executor', 'Provider not certified for remote token refresh.' );
        $grant_sha = call_user_func( $row['identity_callback'] );
        if ( is_wp_error( $grant_sha ) ) return $grant_sha;
        $result = call_user_func( $row['refresh_callback'], $grant_sha );
        if ( is_wp_error( $result ) ) return $result;
        // Only return redacted evidence, never any provider token or callback data.
        $after = call_user_func( $row['status_callback'] );
        if ( ! is_array( $after ) || empty( $after['credential_fresh'] ) )
            return new WP_Error( 'mad4b_provider_refresh_unverified', 'Fresh saved provider connection not verified.' );
        return array( 'contract' => self::CONTRACT . '.refresh.v1',
            'provider' => $input['provider'], 'state' => 'refreshed',
            'credential_fresh' => true, 'oauth_consent_performed' => false,
            'scopes_expanded' => false, 'new_request_readback_required' => true,
            'production_mutation' => false );
    }
}
MAD4B_SCP_Provider_Connection_Center::boot();
