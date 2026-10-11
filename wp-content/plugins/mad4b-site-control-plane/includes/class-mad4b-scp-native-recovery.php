<?php
/**
 * WordPress-only Staging scratch rehearsal for Brand Core recovery.
 * No Host/SSH/runner dependency. This NEVER transfers real Context ownership,
 * certifies a MariaDB process crash, or authorizes an uncertified plugin ZIP.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Native_Recovery {
    const CONTRACT = 'mad4b.wordpress-native-recovery.v1';
    const PLAN = 'mad4b/native-recovery-plan';
    const START = 'mad4b/native-recovery-start';
    const READBACK = 'mad4b/native-recovery-readback';
    const CLEAN = 'mad4b/native-recovery-cleanup';
    const OPTION = 'mad4b_scp_native_recovery_scratch_v1';
    const CRON = 'mad4b_scp_native_recovery_expire';
    const TTL = 900;
    const PROBE_CONFIRM = 'START STAGING SCRATCH PROBE';
    const SHADOW_CONFIRM = 'STAGE BRAND SHADOW ONLY';
    const CLEAN_CONFIRM = 'CLEAN STAGING SCRATCH ONLY';
    private static $request_marker = '';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
        add_action( self::CRON, array( __CLASS__, 'expire' ), 10, 1 );
    }
    private static function schema( $properties, $required = array() ) {
        return array( 'type' => 'object', 'additionalProperties' => false,
            'required' => $required, 'properties' => $properties );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $plan = array( 'mode' => array( 'type' => 'string', 'enum' => array( 'probe', 'shadow' ) ),
            'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 240 ),
            'source_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) );
        $jobs = array( 'job_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{32}$' ) );
        $specs = array(
            array( self::PLAN, 'plan', true, self::schema( $plan, array( 'mode', 'reason' ) ) ),
            array( self::START, 'start', false, self::schema( array_merge( $plan,
                array( 'expected_plan_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                    'confirmation' => array( 'type' => 'string', 'maxLength' => 80 ) ) ),
                array( 'mode', 'reason', 'expected_plan_sha256', 'confirmation' ) ) ),
            array( self::READBACK, 'readback', true, self::schema( $jobs, array( 'job_id' ) ) ),
            array( self::CLEAN, 'cleanup', false, self::schema( array_merge( $jobs,
                array( 'confirmation' => array( 'type' => 'string', 'enum' => array( self::CLEAN_CONFIRM ) ) ) ),
                array( 'job_id', 'confirmation' ) ) ),
        );
        foreach ( $specs as $spec ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $spec[0] ) ) continue;
            wp_register_ability( $spec[0], array(
                'label' => 'MAD4B Native Recovery ' . $spec[1],
                'description' => 'Host-free exact Staging scratch rehearsal, never crash certification or ownership transfer.',
                'category' => $spec[2] ? 'mad4b-read' : 'mad4b-admin',
                'execute_callback' => array( __CLASS__, $spec[1] ),
                'permission_callback' => $spec[2] ? array( __CLASS__, 'can_read' ) : array( __CLASS__, 'can_write' ),
                'input_schema' => $spec[3],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $spec[2] ? 'read' : 'admin' ),
                    'annotations' => array( 'readonly' => $spec[2], 'destructive' => ! $spec[2],
                        'idempotent' => $spec[2] ) ),
            ) );
        }
    }
    public static function can_read() {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' )
            && class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_read();
    }
    public static function can_write( $input = null ) {
        if ( ! self::can_read() || ! current_user_can( 'update_plugins' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' )
            || ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() )
            || ! MAD4B_SCP_Policy::can_mutate() )
            return new WP_Error( 'mad4b_native_owner_required', 'Exact enrolled Staging site owner required.' );
        if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )
            || ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
            || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) )
            return new WP_Error( 'mad4b_native_stepup_required', 'Current owner OAuth step-up required.' );
        if ( ! class_exists( 'MAD4B_SCP_Authorization' ) )
            return new WP_Error( 'mad4b_native_authorization_unavailable', 'Native governed write authorization missing.' );
        $ability = is_array( $input ) && isset( $input['job_id'] ) ? self::CLEAN : self::START;
        return MAD4B_SCP_Authorization::authorize_mutation(
            $ability, 'mad4b-admin', 'core', is_array( $input ) ? $input : array() );
    }
    private static function denied( $code ) {
        return new WP_Error( 'mad4b_native_' . $code, 'Exact bound Staging and current governed identity required.' );
    }
    private static function site() {
        $x = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
        if ( ! is_array( $x ) || 'staging' !== ( $x['configured_environment'] ?? '' )
            || 'staging' !== ( $x['wordpress_environment'] ?? '' )
            || empty( $x['authority_ready'] ) || empty( $x['origin_match'] )
            || empty( $x['environment_match'] )
            || ! preg_match( '/^[a-f0-9-]{36}$/D', (string) ( $x['site_uuid'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $x['profile_digest'] ?? '' ) ) )
            return self::denied( 'not_exact_staging' );
        return array( 'site_uuid' => (string) $x['site_uuid'],
            'profile_digest' => (string) $x['profile_digest'],
            'revision' => (int) ( $x['revision'] ?? 0 ), 'origin' => (string) ( $x['canonical_origin'] ?? '' ) );
    }
    private static function valid_input( $x, $keys ) {
        return is_array( $x ) && ! array_diff( array_keys( $x ), $keys );
    }
    private static function request_marker() {
        if ( '' === self::$request_marker ) self::$request_marker = bin2hex( random_bytes( 16 ) );
        return self::$request_marker;
    }
    private static function digest( $record ) {
        return hash( 'sha256', wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    public static function plan( $input = array() ) {
        if ( ! self::can_read() || ! self::valid_input( $input, array( 'mode', 'reason', 'source_id' ) ) )
            return self::denied( 'plan_input' );
        $site = self::site();
        if ( is_wp_error( $site ) ) return $site;
        $mode = (string) ( $input['mode'] ?? '' );
        $reason = trim( (string) ( $input['reason'] ?? '' ) );
        $source = (string) ( $input['source_id'] ?? '' );
        if ( ! in_array( $mode, array( 'probe', 'shadow' ), true )
            || strlen( $reason ) < 3 || strlen( $reason ) > 240
            || ( 'probe' === $mode && '' !== $source )
            || ( 'shadow' === $mode && ! preg_match( '/^[a-f0-9]{64}$/D', $source ) ) )
            return self::denied( 'invalid_mode_or_source' );
        $legacy = array(); $proof = array();
        if ( 'shadow' === $mode ) {
            if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return self::denied( 'context_unavailable' );
            $legacy = MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan( array( 'source_id' => $source ) );
            if ( is_wp_error( $legacy ) ) return $legacy;
            $proof = MAD4B_SCP_Context_Authority::legacy_owner_transfer_evidence( array( 'source_id' => $source ) );
            if ( is_wp_error( $proof ) ) return $proof;
            if ( true !== ( $proof['folder_membership_verified'] ?? null )
                || true !== ( $proof['independent_owner_approval_required'] ?? null )
                || ! hash_equals( (string) ( $legacy['plan_sha256'] ?? '' ), (string) ( $proof['plan_sha256'] ?? '' ) )
                || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $proof['provider_proof_sha256'] ?? '' ) )
                || (int) ( $proof['matched_file_count'] ?? -1 ) !== (int) ( $legacy['asset_count'] ?? 0 ) )
                return self::denied( 'unproven_source' );
        }
        $basis = array( 'contract' => self::CONTRACT . '.plan.v1', 'site' => $site,
            'mode' => $mode, 'reason' => $reason, 'source_id' => $source,
            'legacy_plan_sha256' => (string) ( $legacy['plan_sha256'] ?? '' ),
            'provider_proof_sha256' => (string) ( $proof['provider_proof_sha256'] ?? '' ),
            'asset_count' => (int) ( $legacy['asset_count'] ?? 0 ) );
        $basis['plan_sha256'] = self::digest( $basis );
        return $basis + array( 'eligible' => true, 'requires_separate_owner_approval' => true,
            'requires_host_runner' => false, 'context_registry_modified' => false,
            'ownership_transferred' => false, 'mariadb_crash_certified' => false,
            'production_authorized' => false, 'read_only' => true );
    }
    public static function start( $input = array() ) {
        if ( ! self::valid_input( $input, array( 'mode', 'reason', 'source_id',
            'expected_plan_sha256', 'confirmation' ) ) ) return self::denied( 'invalid_start_input' );
        $mode = (string) ( $input['mode'] ?? '' );
        $expected_phrase = 'shadow' === $mode ? self::SHADOW_CONFIRM : self::PROBE_CONFIRM;
        if ( ! hash_equals( $expected_phrase, (string) ( $input['confirmation'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $input['expected_plan_sha256'] ?? '' ) ) )
            return self::denied( 'confirmation_required' );
        $auth = self::can_write( $input );
        if ( is_wp_error( $auth ) || true !== $auth ) return $auth;
        $params = array( 'mode' => $mode, 'reason' => (string) ( $input['reason'] ?? '' ) );
        if ( isset( $input['source_id'] ) ) $params['source_id'] = $input['source_id'];
        $plan = self::plan( $params );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( (string) $plan['plan_sha256'], (string) $input['expected_plan_sha256'] ) )
            return self::denied( 'stale_plan' );
        $id = bin2hex( random_bytes( 16 ) );
        $now = time();
        $scratch = array( 'contract' => self::CONTRACT . '.scratch.v1', 'job_id' => $id,
            'mode' => $mode, 'site' => $plan['site'], 'plan_sha256' => $plan['plan_sha256'],
            'source_id' => $plan['source_id'],
            'legacy_plan_sha256' => $plan['legacy_plan_sha256'],
            'provider_proof_sha256' => $plan['provider_proof_sha256'],
            'asset_count' => $plan['asset_count'],
            'writer_request_marker' => self::request_marker(),
            'created_at' => $now, 'expires_at' => $now + self::TTL );
        $scratch['record_sha256'] = self::digest( $scratch );
        if ( ! add_option( self::OPTION, $scratch, '', false ) )
            return self::denied( 'scratch_occupied' );
        if ( get_option( self::OPTION, array() ) !== $scratch ) {
            delete_option( self::OPTION );
            return self::denied( 'scratch_write_readback_failed' );
        }
        $scheduled = function_exists( 'wp_schedule_single_event' )
            ? (bool) wp_schedule_single_event( $scratch['expires_at'], self::CRON, array( $id ) ) : false;
        return array( 'contract' => self::CONTRACT . '.start.v1', 'job_id' => $id,
            'state' => 'SCRATCH_WRITTEN_SEPARATE_READ_REQUIRED',
            'scratch_sha256' => $scratch['record_sha256'],
            'cron_cleanup_scheduled' => $scheduled, 'next_ability' => self::READBACK,
            'independent_request_required' => true, 'host_required' => false,
            'context_registry_modified' => false, 'ownership_transferred' => false,
            'mariadb_crash_certified' => false, 'production_authorized' => false );
    }
    private static function inspect( $job_id ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', (string) $job_id ) )
            return self::denied( 'invalid_job_id' );
        $scratch = get_option( self::OPTION, null );
        if ( ! is_array( $scratch ) || $job_id !== ( $scratch['job_id'] ?? null )
            || self::CONTRACT . '.scratch.v1' !== ( $scratch['contract'] ?? null ) )
            return self::denied( 'scratch_absent' );
        $copy = $scratch; unset( $copy['record_sha256'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $scratch['record_sha256'] ?? '' ) )
            || ! hash_equals( (string) $scratch['record_sha256'], self::digest( $copy ) ) )
            return self::denied( 'scratch_tampered' );
        $site = self::site();
        if ( is_wp_error( $site ) ) return $site;
        if ( $site !== ( $scratch['site'] ?? null ) ) return self::denied( 'site_drift' );
        return $scratch;
    }
    /** Pure reducer; tests do not forge a live WordPress readback. */
    public static function cross_request_verified( $writer, $reader, $expires_at,
        $now, $legacy_exact ) {
        return is_string( $writer ) && is_string( $reader )
            && 1 === preg_match( '/^[a-f0-9]{32}$/D', $writer )
            && 1 === preg_match( '/^[a-f0-9]{32}$/D', $reader )
            && ! hash_equals( $writer, $reader )
            && is_int( $expires_at ) && is_int( $now )
            && $now <= $expires_at && true === $legacy_exact;
    }
    public static function readback( $input = array() ) {
        if ( ! self::can_read() || ! self::valid_input( $input, array( 'job_id' ) ) )
            return self::denied( 'read_denied' );
        $scratch = self::inspect( (string) ( $input['job_id'] ?? '' ) );
        if ( is_wp_error( $scratch ) ) return $scratch;
        $separate = ! hash_equals( (string) $scratch['writer_request_marker'], self::request_marker() );
        $fresh = time() <= (int) $scratch['expires_at'];
        $legacy_exact = true;
        if ( 'shadow' === $scratch['mode'] ) {
            $current = MAD4B_SCP_Context_Authority::legacy_owner_transfer_plan(
                array( 'source_id' => (string) $scratch['source_id'] ) );
            $legacy_exact = is_array( $current ) && hash_equals(
                (string) $scratch['legacy_plan_sha256'], (string) ( $current['plan_sha256'] ?? '' ) );
        }
        $passed = self::cross_request_verified(
            (string) $scratch['writer_request_marker'], self::request_marker(),
            (int) $scratch['expires_at'], time(), $legacy_exact );
        return array( 'contract' => self::CONTRACT . '.readback.v1',
            'job_id' => $scratch['job_id'], 'mode' => $scratch['mode'],
            'state' => $passed ? 'WP_NATIVE_CROSS_REQUEST_VERIFIED' : 'WP_NATIVE_INCOMPLETE',
            'independent_request_observed' => $separate,
            'scratch_hash_verified' => true, 'site_binding_verified' => true,
            'shadow_plan_still_exact' => $legacy_exact, 'fresh' => $fresh,
            'scratch_functional_rehearsal_verified' => $passed,
            'source_id' => $scratch['source_id'],
            'stored_provider_proof_sha256' => $scratch['provider_proof_sha256'],
            'ownership_transferred' => false, 'context_registry_modified' => false,
            'mariadb_crash_restart_certified' => false, 'signed_host_receipt' => false,
            'rollback_certification_issued' => false, 'host_required' => false,
            'next_ability' => self::CLEAN, 'production_authorized' => false,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function cleanup( $input = array() ) {
        if ( ! self::valid_input( $input, array( 'job_id', 'confirmation' ) )
            || ! hash_equals( self::CLEAN_CONFIRM, (string) ( $input['confirmation'] ?? '' ) ) )
            return self::denied( 'cleanup_confirmation_required' );
        $auth = self::can_write( $input );
        if ( is_wp_error( $auth ) || true !== $auth ) return $auth;
        $scratch = self::inspect( (string) ( $input['job_id'] ?? '' ) );
        if ( is_wp_error( $scratch ) ) return $scratch;
        if ( ! delete_option( self::OPTION ) || false !== get_option( self::OPTION, false ) )
            return self::denied( 'cleanup_failed' );
        if ( function_exists( 'wp_clear_scheduled_hook' ) )
            wp_clear_scheduled_hook( self::CRON, array( $scratch['job_id'] ) );
        return array( 'contract' => self::CONTRACT . '.cleanup.v1',
            'scratch_removed' => true, 'job_id' => $scratch['job_id'],
            'ownership_transferred' => false, 'host_required' => false,
            'production_authorized' => false );
    }
    /** WP-Cron is a cleanup convenience, not a source of approval or proof. */
    public static function expire( $job_id ) {
        $scratch = get_option( self::OPTION, null );
        if ( ! is_array( $scratch ) || ! hash_equals( (string) ( $scratch['job_id'] ?? '' ), (string) $job_id )
            || time() < (int) ( $scratch['expires_at'] ?? PHP_INT_MAX ) ) return;
        delete_option( self::OPTION );
    }
}
