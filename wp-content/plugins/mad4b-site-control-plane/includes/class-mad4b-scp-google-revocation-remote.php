<?php
/**
 * Staging-only, governed remote recovery for a Google OAuth grant whose
 * revocation is already pending. This is not a generic OAuth account
 * management endpoint and never upgrades or reconnects permissions.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Google_Revocation_Remote {
    const CONTRACT = 'mad4b.google-revocation-remote.v1';
    const STATUS = 'mad4b/google-revocation-status';
    const PLAN = 'mad4b/google-revocation-plan';
    const RETRY = 'mad4b/google-revocation-retry';
    const CONFIRM = 'RETRY PENDING GOOGLE REVOCATION ON STAGING';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $read = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
        // The approval ticket property is optional in SCHEMA so that the
        // approval planner can validate a ticketless proposal; RETRY itself
        // still requires an actual approved single-use ticket.
        $write = array( 'type' => 'object', 'additionalProperties' => false,
            'properties' => array(
                'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_site_uuid' => array( 'type' => 'string', 'pattern' => '^[a-f0-9-]{36}$' ),
                'expected_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'expected_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
                'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
                '_mad4b_approval_ticket_id' => array( 'type' => 'string', 'pattern' => '^[a-fA-F0-9-]{36}$' ),
            ),
            'required' => array( 'expected_plan_sha256', 'expected_site_uuid',
                'expected_profile_digest', 'expected_profile_revision', 'confirmation' ) );
        foreach ( array(
            array( self::STATUS, 'status', $read, true ),
            array( self::PLAN, 'plan', $read, true ),
            array( self::RETRY, 'retry', $write, false ),
        ) as $ability ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability[0] ) ) continue;
            wp_register_ability( $ability[0], array(
                'label' => 'Google OAuth Pending Revocation ' . ucfirst( $ability[1] ),
                'description' => 'Strictly Staging-bound owner-approved retry of an already disabled and pending Google OAuth revocation; never reconnects, changes grants or treats HTTP 400 as confirmation.',
                'category' => $ability[3] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $ability[1] ),
                'permission_callback' => $ability[3] ? array( __CLASS__, 'can_read' ) : array( __CLASS__, 'can_retry' ),
                'input_schema' => $ability[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool',
                        'surface' => $ability[3] ? 'read' : 'write', 'production_mutation_allowed' => false ),
                    'annotations' => array( 'readonly' => $ability[3], 'destructive' => ! $ability[3],
                        'idempotent' => $ability[3] ) ),
            ) );
        }
    }
    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }
    private static function scope() {
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! class_exists( 'MAD4B_SCP_Google_Drive_Context' ) )
            return new WP_Error( 'mad4b_google_revoke_site_unavailable', 'Site Profile or Google provider unavailable.' );
        $s = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $s ) || 'staging' !== (string) ( $s['configured_environment'] ?? '' )
            || 'staging' !== (string) ( $s['wordpress_environment'] ?? '' )
            || empty( $s['origin_match'] ) || empty( $s['environment_match'] )
            || empty( $s['authority_ready'] ) || ! empty( $s['mutation_pending_audit'] )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $s['profile_digest'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $s['site_uuid'] ?? '' ) )
            || (int) ( $s['revision'] ?? 0 ) < 1 )
            return new WP_Error( 'mad4b_google_revoke_staging_scope_denied', 'Exact enrolled Staging Site Profile required.' );
        return $s;
    }
    /**
     * Shared non-authorizing time admission for MCP Retry revoke. The native
     * wp-admin form already enforces a 60-second cooldown; the remote plan
     * must not offer a shortcut around the same Google provider backoff.
     * Unknown or malformed timestamps fail closed for operator review.
     */
    public static function retry_admission( $connection, $now ) {
        if ( ! is_array( $connection ) || empty( $connection['revocation_pending'] )
            || empty( $connection['connected'] )
            || ! empty( $connection['read_available'] ) || ! empty( $connection['write_available'] ) )
            return array( 'eligible' => false, 'retry_after_seconds' => 0,
                'blocker' => 'google_revocation_not_pending' );
        $stamp = isset( $connection['revocation_attempted_at'] ) && is_string( $connection['revocation_attempted_at'] )
            ? trim( $connection['revocation_attempted_at'] ) : '';
        $last = '' !== $stamp ? strtotime( $stamp ) : false;
        if ( false === $last || $last < 1 )
            return array( 'eligible' => false, 'retry_after_seconds' => 0,
                'blocker' => 'google_revocation_attempt_timestamp_invalid' );
        $wait = max( 0, 60 - ( (int) $now - $last ) );
        if ( $wait > 0 ) return array(
            'eligible' => false, 'retry_after_seconds' => min( $wait, 3600 ),
            'blocker' => 'google_revocation_retry_cooldown',
        );
        return array( 'eligible' => true, 'retry_after_seconds' => 0, 'blocker' => '' );
    }

    public static function status( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return new WP_Error( 'mad4b_google_revoke_status_denied', 'Staging administrator read required.' );
        $s = self::scope();
        if ( is_wp_error( $s ) ) return $s;
        $c = MAD4B_SCP_Google_Drive_Context::connection_status();
        $pending = ! empty( $c['revocation_pending'] ) && ! empty( $c['connected'] )
            && empty( $c['read_available'] ) && empty( $c['write_available'] );
        $admission = self::retry_admission( $c, time() );
        return array(
            'contract' => self::CONTRACT . '.status.v1',
            'state' => ! $pending ? 'NOT_PENDING'
                : ( ! empty( $admission['eligible'] ) ? 'PENDING_SAFE_RETRY'
                    : ( 'google_revocation_retry_cooldown' === $admission['blocker']
                        ? 'PENDING_COOLDOWN' : 'PENDING_OPERATOR_REVIEW' ) ),
            'retry_eligible' => ! empty( $admission['eligible'] ),
            'retry_after_seconds' => (int) $admission['retry_after_seconds'],
            'retry_blocker' => (string) $admission['blocker'],
            'revocation_error' => isset( $c['revocation_error'] ) ? (string) $c['revocation_error'] : '',
            'revocation_attempts' => $c['revocation_attempts'] ?? array(),
            'site_uuid' => (string) $s['site_uuid'],
            'profile_revision' => (int) $s['revision'],
            'next_ability' => ! empty( $admission['eligible'] ) ? self::PLAN : '',
            'oauth_read_available' => ! empty( $c['read_available'] ),
            'oauth_write_available' => ! empty( $c['write_available'] ),
            'remote_revocation_confirmed' => false,
            'read_only' => true,
            'no_token_exposed' => true,
            'production_authorized' => false,
        );
    }
    public static function plan( $input = array() ) {
        if ( ! self::can_read() || ! is_array( $input ) || $input )
            return new WP_Error( 'mad4b_google_revoke_plan_denied', 'Staging administrator read required.' );
        $s = self::scope();
        if ( is_wp_error( $s ) ) return $s;
        $status = self::status();
        if ( is_wp_error( $status ) ) return $status;
        if ( empty( $status['retry_eligible'] ) ) {
            $reason = (string) ( $status['retry_blocker'] ?? '' );
            if ( 'google_revocation_retry_cooldown' === $reason )
                return new WP_Error( 'mad4b_google_revoke_retry_cooldown',
                    'Wait until the pending Google revoke cooldown ends before planning another provider call.',
                    array( 'retry_after_seconds' => (int) ( $status['retry_after_seconds'] ?? 0 ) ) );
            return new WP_Error( 'mad4b_google_revoke_not_retryable',
                'Pending Google revocation needs operator review or is not eligible for provider retry.' );
        }
        $stored = get_option( MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION, null );
        if ( ! is_array( $stored ) || ! $stored )
            return new WP_Error( 'mad4b_google_revoke_token_identity_missing', 'Encrypted pending grant identity is not available.' );
        $encoded = wp_json_encode( $stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $encoded ) )
            return new WP_Error( 'mad4b_google_revoke_token_identity_unhashable', 'Encrypted pending grant identity cannot be verified.' );
        $basis = array(
            'contract' => self::CONTRACT . '.plan.v1',
            'site_uuid' => (string) $s['site_uuid'],
            'profile_revision' => (int) $s['revision'],
            'profile_digest' => (string) $s['profile_digest'],
            'encrypted_grant_identity_sha256' => hash( 'sha256', $encoded ),
            'previous_revocation_error' => (string) ( $status['revocation_error'] ?? '' ),
        );
        return array(
            'contract' => self::CONTRACT . '.plan.v1',
            'eligible' => true,
            'plan_sha256' => hash( 'sha256', wp_json_encode( $basis,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
            'expected_site_uuid' => $basis['site_uuid'],
            'expected_profile_revision' => $basis['profile_revision'],
            'expected_profile_digest' => $basis['profile_digest'],
            'confirmation' => self::CONFIRM,
            'next_ability' => self::RETRY,
            'retry_pending_only' => true,
            'explicit_one_time_approval_required' => true,
            'remote_revoke_http_400_not_confirmation' => true,
            'production_authorized' => false,
            'read_only' => true,
        );
    }
    public static function can_retry( $input = null ) {
        if ( ! self::can_read() || ! class_exists( 'MAD4B_SCP_Site_Profile_Enrollment' )
            || ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_google_revoke_approval_unavailable', 'Enrolled Staging owner authority required.' );
        $scope = self::scope();
        if ( is_wp_error( $scope ) ) return $scope;
        $allowed = MAD4B_SCP_Site_Profile_Enrollment::can_execute( $input );
        if ( is_wp_error( $allowed ) || true !== $allowed ) return $allowed;
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is(
                MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_google_revoke_client_denied', 'Verified enrolled ChatGPT bearer required.' );
        // The immutable wrapper owns the ticket claim and completion. A
        // second authorization here would reject the already-consumed ticket.
        if ( MAD4B_SCP_Authorization::google_revocation_callback_is_claimed( $input ) )
            return true;
        $decision = MAD4B_SCP_Authorization::authorize_mutation(
            self::RETRY, 'mad4b-write', 'core', is_array( $input ) ? $input : array() );
        return MAD4B_SCP_Authorization::permission_result_from_authorization( $decision );
    }
    public static function retry( $input = array() ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array(
            'expected_plan_sha256', 'expected_site_uuid', 'expected_profile_digest',
            'expected_profile_revision', 'confirmation', '_mad4b_approval_ticket_id' ) )
            || ! is_int( $input['expected_profile_revision'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_profile_digest'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $input['expected_site_uuid'] ?? '' ) )
            || self::CONFIRM !== (string) ( $input['confirmation'] ?? '' )
            || ! preg_match( '/^[a-f0-9-]{36}$/Di', (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ) ) )
            return new WP_Error( 'mad4b_google_revoke_ticket_missing', 'Current one-time approval ticket and exact Staging plan are required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' )
            || ! MAD4B_SCP_Authorization::google_revocation_callback_is_claimed( $input ) )
            return new WP_Error( 'mad4b_google_revoke_claimed_callback_required',
                'An exact approved and claimed governed execution callback is required.' );
        $permission = self::can_retry( $input );
        if ( is_wp_error( $permission ) || true !== $permission ) return $permission;
        $plan = self::plan();
        if ( is_wp_error( $plan ) || empty( $plan['eligible'] )
            || ! hash_equals( (string) $input['expected_plan_sha256'], (string) ( $plan['plan_sha256'] ?? '' ) )
            || ! hash_equals( (string) $input['expected_site_uuid'], (string) ( $plan['expected_site_uuid'] ?? '' ) )
            || ! hash_equals( (string) $input['expected_profile_digest'], (string) ( $plan['expected_profile_digest'] ?? '' ) )
            || (int) $input['expected_profile_revision'] !== (int) ( $plan['expected_profile_revision'] ?? 0 ) )
            return new WP_Error( 'mad4b_google_revoke_stale_plan', 'Pending grant or Staging identity changed after planning.' );
        // Do not claim again: the wrapper has already reserved budget,
        // consumed approval and persisted provider-entry evidence.
        // No concurrency fallback, no OAuth scope expansion, no new grant:
        // plan includes encrypted-token identity, so a changed grant fails.
        $again = self::plan();
        if ( is_wp_error( $again ) || ! hash_equals( (string) $plan['plan_sha256'],
            (string) ( $again['plan_sha256'] ?? '' ) ) )
            return new WP_Error( 'mad4b_google_revoke_recheck_failed', 'Pending grant changed before retry.' );
        $result = MAD4B_SCP_Google_Drive_Context::disconnect();
        if ( is_wp_error( $result ) ) return $result;
        // Never trust the provider callback's success bit alone: local
        // encrypted token deletion and disabled transport must be observed
        // independently BEFORE claiming final completion.
        $token_after = get_option( MAD4B_SCP_Google_Drive_Context::TOKEN_OPTION, false );
        $status_after = MAD4B_SCP_Google_Drive_Context::connection_status();
        if ( ! is_array( $result ) || empty( $result['remote_revocation_confirmed'] )
            || ! empty( $result['connected'] )
            || false !== $token_after
            || ! is_array( $status_after ) || ! empty( $status_after['connected'] )
            || ! empty( $status_after['read_available'] )
            || ! empty( $status_after['write_available'] ) )
            return new WP_Error( 'mad4b_google_revoke_readback_unconfirmed',
                'Google confirmed revocation, but independent local token removal and disabled transport were not both verified.' );
        return array(
            'contract' => self::CONTRACT . '.retry.v1',
            'state' => 'REVOCATION_CONFIRMED',
            'remote_revocation_confirmed' => true,
            'local_token_absent' => true,
            'next_ability' => self::STATUS,
            'new_request_readback_required' => true,
            'write_grant_added' => false,
            'production_mutation' => false,
        );
    }
}
MAD4B_SCP_Google_Revocation_Remote::boot();
