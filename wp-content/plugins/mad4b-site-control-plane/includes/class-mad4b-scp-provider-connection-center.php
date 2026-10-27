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
    const RECONNECT_CONFIRM = 'RECONNECT EXISTING STAGING PROVIDER GRANT';
    const PROVIDER_GOOGLE = 'google_drive';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ), 39 );
    }

    /** REST is an adapter to the same governed Abilities API, not new authority. */
    public static function register_rest_routes() {
        register_rest_route( 'mad4b/v1', '/provider-connections', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'rest_discover' ),
            'permission_callback' => array( __CLASS__, 'rest_read_permission' ),
        ) );
        register_rest_route( 'mad4b/v1', '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/plan', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'rest_plan' ),
            'permission_callback' => array( __CLASS__, 'rest_read_permission' ),
        ) );
        register_rest_route( 'mad4b/v1', '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/refresh', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_refresh' ),
            'permission_callback' => array( __CLASS__, 'rest_refresh_permission' ),
        ) );
        register_rest_route( 'mad4b/v1', '/provider-connections/(?P<provider>[a-z][a-z0-9_-]{0,63})/reconnect', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_reconnect' ),
            'permission_callback' => array( __CLASS__, 'rest_reconnect_permission' ),
        ) );
    }

    public static function rest_read_permission( $request = null ) {
        return self::can_read() ? true : new WP_Error( 'mad4b_provider_rest_read_denied',
            'Enrolled administrator read access is required.', array( 'status' => 403 ) );
    }

    private static function rest_refresh_input( $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_json_params' )
            || ! method_exists( $request, 'get_param' ) )
            return new WP_Error( 'mad4b_provider_rest_request_invalid', 'An exact JSON REST request is required.', array( 'status' => 400 ) );
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || array_keys( $input ) === range( 0, count( $input ) - 1 ) )
            return new WP_Error( 'mad4b_provider_rest_json_object_required', 'JSON object required.', array( 'status' => 400 ) );
        $provider = $request->get_param( 'provider' );
        if ( ! is_string( $provider ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $provider )
            || array_key_exists( 'provider', $input ) )
            return new WP_Error( 'mad4b_provider_rest_provider_invalid', 'Provider must be specified only in the REST route.', array( 'status' => 400 ) );
        $expected = array( 'expected_plan_sha256', 'expected_site_uuid',
            'expected_profile_revision', 'expected_profile_digest', 'confirmation', '_mad4b_approval_ticket_id' );
        if ( array_diff( array_keys( $input ), $expected ) || array_diff( $expected, array_keys( $input ) ) )
            return new WP_Error( 'mad4b_provider_rest_exact_payload_required', 'Exact governed plan and approval ticket fields required.', array( 'status' => 400 ) );
        $input['provider'] = $provider;
        return $input;
    }

    public static function rest_refresh_permission( $request ) {
        if ( ! self::can_read() ) return new WP_Error( 'mad4b_provider_rest_owner_denied', 'Administrator required.', array( 'status' => 403 ) );
        $input = self::rest_refresh_input( $request );
        if ( is_wp_error( $input ) ) return $input;
        // Never convert WordPress cookie/API-key auth into an OAuth owner step-up.
        // This checks verified ChatGPT bearer plus exact current mutation policy.
        return self::can_refresh( $input );
    }

    /** Browser-owner only: reissue OAuth authorization, never a token from REST. */
    private static function reconnect_input( $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_json_params' )
            || ! method_exists( $request, 'get_param' ) )
            return new WP_Error( 'mad4b_provider_reconnect_request_invalid', 'Exact JSON REST request required.', array( 'status' => 400 ) );
        $input = $request->get_json_params();
        $provider = $request->get_param( 'provider' );
        $keys = array( 'expected_plan_sha256', 'expected_site_uuid',
            'expected_profile_revision', 'expected_profile_digest', 'confirmation' );
        if ( ! is_array( $input ) || count( $input ) !== count( $keys )
            || array_diff( array_keys( $input ), $keys ) || array_diff( $keys, array_keys( $input ) )
            || ! is_string( $provider ) || self::PROVIDER_GOOGLE !== $provider
            || ! is_string( $input['expected_plan_sha256'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_plan_sha256'] )
            || ! is_string( $input['expected_site_uuid'] )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', $input['expected_site_uuid'] )
            || ! is_int( $input['expected_profile_revision'] )
            || ! is_string( $input['expected_profile_digest'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_profile_digest'] )
            || self::RECONNECT_CONFIRM !== ( $input['confirmation'] ?? '' ) )
            return new WP_Error( 'mad4b_provider_reconnect_exact_plan_required', 'Exact current reconnect plan and explicit consent confirmation required.', array( 'status' => 400 ) );
        $input['provider'] = $provider;
        return $input;
    }

    public static function rest_reconnect_permission( $request ) {
        if ( ! self::can_read() || ! function_exists( 'wp_verify_nonce' )
            || ! function_exists( 'get_current_user_id' ) || get_current_user_id() < 1 )
            return new WP_Error( 'mad4b_provider_reconnect_browser_owner_required', 'Authenticated site administrator browser required.', array( 'status' => 403 ) );
        // Exact wp_rest nonce is bound to the WordPress browser owner session.
        // An OAuth bearer or an Application Password by itself cannot initiate
        // an OAuth handshake bound to a different browser's transient state.
        $nonce = is_object( $request ) && method_exists( $request, 'get_header' )
            ? $request->get_header( 'X-WP-Nonce' ) : null;
        if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) )
            return new WP_Error( 'mad4b_provider_reconnect_browser_nonce_required', 'Same-site owner REST nonce required.', array( 'status' => 403 ) );
        $input = self::reconnect_input( $request );
        return is_wp_error( $input ) ? $input : self::reconnect_admission( $input );
    }

    private static function reconnect_admission( array $input ) {
        $site = self::scope( true );
        if ( is_wp_error( $site ) ) return $site;
        $plan = self::plan( array( 'provider' => $input['provider'] ) );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['reconnect_rest_available'] )
            || 'oauth_handoff' !== ( $plan['action'] ?? '' )
            || ! empty( $plan['eligible_remote_refresh'] ) )
            return new WP_Error( 'mad4b_provider_reconnect_not_required', 'Current grant does not require reconnect.', array( 'status' => 409 ) );
        foreach ( array( 'expected_plan_sha256' => 'plan_sha256', 'expected_site_uuid' => 'expected_site_uuid',
            'expected_profile_digest' => 'expected_profile_digest' ) as $key => $value ) {
            if ( ! hash_equals( (string) $plan[$value], (string) $input[$key] ) )
                return new WP_Error( 'mad4b_provider_reconnect_plan_stale', 'Site, grant or reconnect plan changed.', array( 'status' => 409 ) );
        }
        if ( (int) $plan['expected_profile_revision'] !== $input['expected_profile_revision'] )
            return new WP_Error( 'mad4b_provider_reconnect_plan_stale', 'Profile revision changed.', array( 'status' => 409 ) );
        $status = MAD4B_SCP_Google_Drive_Context::connection_status();
        if ( empty( $status['connected'] ) || empty( $status['reconnect_required'] )
            || ! empty( $status['revocation_pending'] ) || ! empty( $status['token_unreadable'] ) )
            return new WP_Error( 'mad4b_provider_existing_grant_not_reconnectable', 'Retained usable grant with rejected refresh token is required.', array( 'status' => 409 ) );
        $grants = MAD4B_SCP_Google_Drive_Context::workspace_grants_status();
        $requested = isset( $grants['scopes'] ) && is_array( $grants['scopes'] ) ? $grants['scopes'] : array();
        $granted = preg_split( '/\s+/', trim( (string) ( $status['scope'] ?? '' ) ) );
        $granted = array_values( array_unique( array_filter( is_array( $granted ) ? $granted : array() ) ) );
        // Preserve old scopes: reconnect never quietly accepts a broader
        // current Workspace grant selection than the original Google grant.
        if ( empty( $requested ) || empty( $granted ) || array_diff( $requested, $granted )
            || ! empty( $grants['managed_profile_reconsent_required'] ) )
            return new WP_Error( 'mad4b_provider_reconnect_scope_expansion_denied', 'Current Workspace selection exceeds the old Google grant; owner review required.', array( 'status' => 409 ) );
        return true;
    }

    public static function rest_reconnect( $request ) {
        $allowed = self::rest_reconnect_permission( $request );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        $input = self::reconnect_input( $request );
        if ( is_wp_error( $input ) ) return $input;
        $mode = MAD4B_SCP_Google_Drive_Context::auth_mode();
        $grants = MAD4B_SCP_Google_Drive_Context::workspace_grants_status();
        $selection = isset( $grants['selection'] ) && is_array( $grants['selection'] ) ? $grants['selection'] : array();
        $access_mode = ( $selection['drive'] ?? '' ) === 'full' ? 'read_write' : 'read_only';
        // Native methods preserve the existing Site/Profile binding and PKCE
        // challenge/state (10-minute browser session). Google consent remains
        // compulsory and the retained old token is NOT replaced at this step.
        $url = MAD4B_SCP_Google_Drive_Context::AUTH_MODE_MANAGED === $mode
            ? MAD4B_SCP_Google_Drive_Context::managed_authorization_url( $access_mode )
            : MAD4B_SCP_Google_Drive_Context::authorization_url( $access_mode );
        if ( is_wp_error( $url ) ) return $url;
        if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) )
            return new WP_Error( 'mad4b_provider_reconnect_authorization_invalid', 'Provider returned an invalid consent URL.', array( 'status' => 502 ) );
        return self::rest_response( array(
            'contract' => self::CONTRACT . '.reconnect.v1',
            'provider' => $input['provider'],
            'state' => 'owner_consent_pending', 'authorization_url' => $url,
            'consent_expires_in_seconds' => 600, 'credential_replaced' => false,
            'oauth_consent_performed' => false, 'scopes_expanded' => false,
            'callback' => 'existing_native_google_oauth_callback',
            'production_mutation' => false,
        ) );
    }

    private static function rest_response( $value ) {
        if ( is_wp_error( $value ) ) return $value;
        $response = rest_ensure_response( $value );
        if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
            $response->header( 'Cache-Control', 'private, no-store' );
            $response->header( 'Vary', 'Authorization, Cookie' );
            $response->header( 'X-Content-Type-Options', 'nosniff' );
        }
        return $response;
    }

    public static function rest_discover( $request ) {
        $provider = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'provider' ) : null;
        if ( null !== $provider && ( ! is_string( $provider )
            || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $provider ) ) )
            return new WP_Error( 'mad4b_provider_rest_filter_invalid', 'Invalid provider filter.', array( 'status' => 400 ) );
        return self::rest_response( self::discover( $provider ? array( 'provider' => $provider ) : array() ) );
    }

    public static function rest_plan( $request ) {
        $provider = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'provider' ) : null;
        if ( ! is_string( $provider ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $provider ) )
            return new WP_Error( 'mad4b_provider_rest_provider_invalid', 'Invalid provider.', array( 'status' => 400 ) );
        return self::rest_response( self::plan( array( 'provider' => $provider ) ) );
    }

    public static function rest_refresh( $request ) {
        $input = self::rest_refresh_input( $request );
        if ( is_wp_error( $input ) ) return $input;
        // Execution must travel through the existing Abilities registration
        // (central policy, exact approval and claim), never call the provider
        // refresh callback directly from REST.
        if ( ! function_exists( 'wp_get_ability' ) )
            return new WP_Error( 'mad4b_provider_rest_ability_unavailable', 'Governed refresh ability unavailable.', array( 'status' => 503 ) );
        $ability = wp_get_ability( self::REFRESH );
        if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) )
            return new WP_Error( 'mad4b_provider_rest_ability_unmounted', 'Governed refresh ability not mounted.', array( 'status' => 503 ) );
        return self::rest_response( $ability->execute( $input ) );
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
        $retry_after = 0;
        if ( ! empty( $status['refresh_failed'] ) ) {
            $failure_at = isset( $status['refresh_failure_at'] ) ? strtotime( (string) $status['refresh_failure_at'] ) : false;
            // Unknown failure timestamps are never a reason to hammer an
            // external identity provider; require human reconciliation.
            $retry_after = false === $failure_at ? 60 : max( 0, 60 - ( time() - $failure_at ) );
        }
        $refresh = ! empty( $status['connected'] ) && ! empty( $status['refresh_required'] )
            && empty( $status['revocation_pending'] ) && empty( $status['reconnect_required'] )
            && 0 === $retry_after && ! empty( $row['remote_refresh_reviewed'] );
        $action = ! empty( $status['revocation_pending'] ) ? 'resolve_pending_revocation'
            : ( $refresh ? 'refresh' : ( ! empty( $status['reconnect_required'] ) || empty( $status['connected'] )
                ? 'oauth_handoff' : 'no_action' ) );
        $grant_identity = '';
        if ( ( $refresh || ( ! empty( $status['reconnect_required'] ) && ! empty( $status['connected'] ) ) )
            && isset( $row['identity_callback'] ) && is_callable( $row['identity_callback'] ) ) {
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
            'refresh_retry_after_seconds' => $retry_after,
            'eligible_remote_refresh' => $refresh && '' !== $grant_identity
                && 'staging' === (string) ( $site['configured_environment'] ?? '' ),
            'plan_sha256' => hash( 'sha256', wp_json_encode( $basis,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'expected_site_uuid' => (string) $site['site_uuid'],
            'expected_profile_revision' => (int) $site['revision'],
            'expected_profile_digest' => (string) $site['profile_digest'],
            'confirmation' => $action === 'oauth_handoff' && ! empty( $status['reconnect_required'] )
                ? self::RECONNECT_CONFIRM : self::CONFIRM,
            'reconnect_rest_available' => $action === 'oauth_handoff' && ! empty( $status['reconnect_required'] )
                && ! empty( $status['connected'] ) && '' !== $grant_identity
                && 'staging' === (string) ( $site['configured_environment'] ?? '' ),
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
