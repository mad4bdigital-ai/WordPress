<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Passive, non-authorizing ChatGPT connection discovery.
 *
 * ChatGPT's OAuth CIMD client_id identifies the OAuth CLIENT, not the private
 * asdk_app_* installation. Never invent an App ID or mutate Site Profile.
 * A verified local token issuance or an existing verified MCP Bearer request
 * can register a bounded connection observation without additional admin input.
 */
final class MAD4B_SCP_ChatGPT_Auto_Binding {
    const CONTRACT = 'mad4b.chatgpt-auto-binding.v1';
    const OPTION = 'mad4b_scp_chatgpt_auto_binding_v1';
    const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    const MAX_AGE = 2592000; // 30 days, with on-request renewal.
    const REFRESH_AFTER = 86400; // No database write on every MCP request.

    public static function observe_local_token_issued( $client_id, $wp_user_id, $resource, $scopes ) {
        return self::observe_exact( $client_id, $wp_user_id, $resource, $scopes );
    }

    /** Called only after the OAuth resource bridge verifies token, issuer, audience, scope and site claim. */
    public static function observe_verified_mcp_request( $resource, $wp_user_id ) {
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( self::CHATGPT_CLIENT_ID ) ||
            ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:read' ) )
            return false;
        return self::observe_exact( self::CHATGPT_CLIENT_ID, $wp_user_id, $resource,
            array( 'mad4b:read' ) );
    }

    private static function observe_exact( $client_id, $wp_user_id, $resource, $scopes ) {
        if ( ! is_string( $client_id ) || ! hash_equals( self::CHATGPT_CLIENT_ID, $client_id ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
            ! MAD4B_SCP_Site_Profile::oauth_enabled() ||
            ! is_array( $scopes ) || ! in_array( 'mad4b:read', $scopes, true ) )
            return false;

        $environment = (string) MAD4B_SCP_Site_Profile::current_environment();
        if ( ! in_array( $environment, array( 'staging', 'development', 'local', 'production' ), true ) )
            return false;
        $wp_user_id = absint( $wp_user_id );
        $user = $wp_user_id > 0 && function_exists( 'get_userdata' )
            ? get_userdata( $wp_user_id ) : false;
        if ( ! $user || ! user_can( $user, 'manage_options' ) ||
            ! MAD4B_SCP_Site_Profile::user_is_enrolled( $wp_user_id ) )
            return false;

        $resource = untrailingslashit( trim( (string) $resource ) );
        if ( ! hash_equals( MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(), $resource ) )
            return false;
        $site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
        $profile_digest = strtolower( trim( (string) MAD4B_SCP_Site_Profile::profile_digest() ) );
        if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $site_uuid ) ||
            1 !== preg_match( '/^[a-f0-9]{64}$/D', $profile_digest ) )
            return false;
        $origin = untrailingslashit( (string) MAD4B_SCP_Site_Profile::site_origin() );
        $current = get_option( self::OPTION, array() );
        $fingerprint = hash( 'sha256', $client_id );
        $identity = array(
            'contract' => self::CONTRACT,
            'site_uuid' => $site_uuid,
            'profile_digest' => $profile_digest,
            'origin' => $origin,
            'resource' => $resource,
            'client_fingerprint' => $fingerprint,
            'wp_user_id' => $wp_user_id,
            'environment' => $environment,
        );
        $now = time();
        if ( is_array( $current ) && self::matches( $current, $identity ) &&
            isset( $current['observed_at_unix'] ) &&
            $now - (int) $current['observed_at_unix'] < self::REFRESH_AFTER )
            return true;
        $record = array_merge( $identity, array( 'observed_at_unix' => $now ) );
        // Metadata is not governance authority; never modify Site Profile,
        // grants, roles, scopes or stored OAuth tokens as a side-effect.
        update_option( self::OPTION, $record, false );
        $saved = get_option( self::OPTION, array() );
        return is_array( $saved ) && self::matches( $saved, $record )
            && (int) ( $saved['observed_at_unix'] ?? 0 ) === $now;
    }

    private static function matches( array $record, array $expected ) {
        foreach ( $expected as $field => $value ) {
            if ( ! isset( $record[ $field ] ) || ! is_string( $record[ $field ] ) &&
                'wp_user_id' !== $field || ! hash_equals( (string) $value, (string) $record[ $field ] ) )
                return false;
        }
        return true;
    }

    public static function status() {
        $record = get_option( self::OPTION, array() );
        $ready = false;
        $reason = 'not_observed';
        if ( is_array( $record ) && ! empty( $record ) ) {
            $reason = 'stale_or_site_profile_changed';
            if ( class_exists( 'MAD4B_SCP_Site_Profile' ) &&
                MAD4B_SCP_Site_Profile::origin_enrolled() &&
                MAD4B_SCP_Site_Profile::site_urls_match_enrollment() &&
                MAD4B_SCP_Site_Profile::oauth_enabled() ) {
                $expected = array(
                    'contract' => self::CONTRACT,
                    'site_uuid' => strtolower( (string) MAD4B_SCP_Site_Profile::site_uuid() ),
                    'profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ),
                    'origin' => untrailingslashit( (string) MAD4B_SCP_Site_Profile::site_origin() ),
                    'resource' => MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(),
                    'client_fingerprint' => hash( 'sha256', self::CHATGPT_CLIENT_ID ),
                    'wp_user_id' => (int) ( $record['wp_user_id'] ?? 0 ),
                    'environment' => (string) MAD4B_SCP_Site_Profile::current_environment(),
                );
                $user_id = (int) $expected['wp_user_id'];
                $now = time();
                $observed = (int) ( $record['observed_at_unix'] ?? 0 );
                $ready = $user_id > 0 &&
                    MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) &&
                    ( $user = get_userdata( $user_id ) ) && user_can( $user, 'manage_options' ) &&
                    $observed > 0 && $observed <= $now &&
                    $now - $observed <= self::MAX_AGE &&
                    self::matches( $record, $expected );
                if ( $ready ) $reason = 'verified_oauth_client_observed';
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'state' => $ready ? 'bound' : 'unbound',
            'client_observed' => $ready,
            'binding_source' => $ready ? 'verified_chatgpt_oauth_client' : '',
            'identity_scope' => 'oauth_client_site_and_enrolled_user',
            'chatgpt_app_id_disclosed_by_oauth' => false,
            'app_id_invented_or_stored' => false,
            'site_profile_mutated' => false,
            'write_grants_created' => false,
            'developer_authority_created' => false,
            'production_write_authorized' => false,
            'independent_mcp_session_certified' => false,
            'blocker' => $ready ? '' : $reason,
            'observed_at_unix' => $ready ? (int) $record['observed_at_unix'] : 0,
        );
    }

    /** Eligibility for feature setup requires the same *live* authorized OAuth bearer. */
    public static function current_bearer_binding_ready() {
        $status = self::status();
        return ! empty( $status['client_observed'] )
            && class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( self::CHATGPT_CLIENT_ID )
            && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:read' );
    }
}
