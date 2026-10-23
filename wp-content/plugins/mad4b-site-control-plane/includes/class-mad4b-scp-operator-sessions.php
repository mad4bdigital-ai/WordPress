<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Time-bound, OAuth-anchored operator session admission.
 *
 * This class NEVER runs a command or SQL and cannot authorize one by itself.
 * It creates a credential bound to the already-verified request subject, exact
 * enrolled Staging site, and an explicitly bounded capability set. The remote
 * executor MUST independently validate the approved semantic job and trusted
 * Host Runner attestation. Disabled by default until that execution lane passes
 * certification.
 */
final class MAD4B_SCP_Operator_Sessions {
    const CONTRACT = 'mad4b.operator-session.v1';
    const KEY_PREFIX = 'mad4b_op_session_';
    const MAX_TTL = 900;
    const CONFIRM = 'OPEN STAGING OPERATOR SESSION';

    private static $booted = false;
    private static $scopes = array( 'shell', 'wp-cli', 'sql.read', 'sql.write', 'sql.schema' );

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 25 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        self::register( 'mad4b/operator-session-open', 'Open Operator Session', 'open', false,
            array(
                'scopes' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'uniqueItems' => true,
                    'items' => array( 'type' => 'string', 'enum' => self::$scopes ) ),
                'ttl_seconds' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => self::MAX_TTL ),
                'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
                'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM ) ),
            ), array( 'scopes', 'ttl_seconds', 'reason', 'confirmation' ) );
        self::register( 'mad4b/operator-session-status', 'Operator Session Status', 'status', true,
            array( 'session_id' => array( 'type' => 'string', 'format' => 'uuid' ),
                   'session_token' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 60 ) ),
            array( 'session_id', 'session_token' ) );
        self::register( 'mad4b/operator-session-close', 'Close Operator Session', 'close', false,
            array( 'session_id' => array( 'type' => 'string', 'format' => 'uuid' ),
                   'session_token' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 60 ) ),
            array( 'session_id', 'session_token' ) );
    }

    private static function register( $name, $label, $method, $readonly, array $properties, array $required ) {
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
        wp_register_ability( $name, array(
            'label' => $label,
            'description' => $label . ' under an enrolled Staging OAuth identity; execution is not authorized.',
            'category' => 'mad4b-developer',
            'execute_callback' => array( __CLASS__, $method ),
            'permission_callback' => array( __CLASS__, 'can_access' ),
            'input_schema' => array( 'type' => 'object', 'properties' => $properties,
                'required' => $required, 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool',
                    'surface' => 'developer', 'production_allowed' => false ),
                'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly,
                    'idempotent' => $readonly ),
            ),
        ) );
    }

    private static function enabled() {
        if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) ||
            MAD4B_SCP_Developer_Runtime::kill_switch_enabled() ||
            ! MAD4B_SCP_Developer_Runtime::developer_flag_enabled() ) return false;
        if ( defined( 'MAD4B_MCP_OPERATOR_SESSIONS_ENABLED' ) )
            return true === constant( 'MAD4B_MCP_OPERATOR_SESSIONS_ENABLED' );
        return '1' === (string) get_option( 'mad4b_scp_operator_sessions_enabled', '0' );
    }

    public static function can_access( $input = null ) {
        if ( ! self::enabled() ) return new WP_Error( 'mad4b_operator_session_disabled', 'Operator Session is disabled until external execution certification.' );
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ||
             ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() )
            return new WP_Error( 'mad4b_operator_oauth_admin_required', 'Verified OAuth administrator is required.' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ||
             'staging' !== (string) MAD4B_SCP_Site_Profile::current_environment() ||
             ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
             ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ||
             ! MAD4B_SCP_Site_Profile::user_is_enrolled( get_current_user_id() ) )
            return new WP_Error( 'mad4b_operator_enrollment_required', 'Exact enrolled ARE Staging site/user required.' );
        $identity = MAD4B_SCP_Identity_Context::current();
        if ( is_wp_error( $identity ) ) return $identity;
        if ( empty( $identity['authenticated'] ) ||
             'oauth' !== (string) $identity['subject_type'] ||
             'oauth2_bearer' !== (string) $identity['auth_method'] ||
             '' === (string) $identity['subject_fingerprint'] ||
             '' === (string) $identity['client_fingerprint'] ||
             ! in_array( 'mad4b:authority:step-up', $identity['token_scopes'], true ) )
            return new WP_Error( 'mad4b_operator_oauth_step_up_required', 'Scoped verified OAuth session identity required.' );
        return true;
    }

    private static function facts() {
        $identity = MAD4B_SCP_Identity_Context::current();
        $fp = class_exists( 'MAD4B_SCP_Host_Bridge' )
            ? MAD4B_SCP_Host_Bridge::target_fingerprint_readonly() : '';
        if ( is_wp_error( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) $fp ) )
            return new WP_Error( 'mad4b_operator_fingerprint_unavailable', 'Fresh enrolled Host target identity unavailable.' );
        return array(
            'environment' => 'staging',
            'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(),
            'target_fingerprint' => $fp,
            'agent_public_id' => (string) MAD4B_SCP_Developer_Runtime::configured_agent_public_id(),
            'subject_fingerprint' => (string) $identity['subject_fingerprint'],
            'client_fingerprint' => (string) $identity['client_fingerprint'],
            'issuer_fingerprint' => (string) $identity['issuer_fingerprint'],
            'wp_user_id' => (int) get_current_user_id(),
        );
    }

    private static function key( $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id ) )
            return '';
        return self::KEY_PREFIX . str_replace( '-', '', $id );
    }

    public static function open( $input ) {
        $gate = self::can_access();
        if ( is_wp_error( $gate ) ) return $gate;
        if ( ! is_array( $input ) || self::CONFIRM !== (string) ( $input['confirmation'] ?? '' ) )
            return new WP_Error( 'mad4b_operator_confirmation_required', 'Exact operator session confirmation required.' );
        $scopes = $input['scopes'] ?? null;
        $ttl = $input['ttl_seconds'] ?? 0;
        $reason = trim( (string) ( $input['reason'] ?? '' ) );
        if ( ! is_array( $scopes ) || count( $scopes ) < 1 || count( $scopes ) > 5 ||
             array_values( array_unique( $scopes ) ) !== array_values( $scopes ) ||
             array_diff( $scopes, self::$scopes ) ||
             ! is_int( $ttl ) || $ttl < 60 || $ttl > self::MAX_TTL ||
             strlen( $reason ) < 3 || strlen( $reason ) > 500 )
            return new WP_Error( 'mad4b_operator_session_input_invalid', 'Invalid scopes, duration or reason.' );
        $facts = self::facts();
        if ( is_wp_error( $facts ) || '' === $facts['agent_public_id'] ) return new WP_Error( 'mad4b_operator_binding_missing', 'Exact Developer Agent and Host binding required.' );
        if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) )
            return new WP_Error( 'mad4b_operator_audit_unavailable', 'Durable audit must be ready.' );
        $id = wp_generate_uuid4();
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $now = time();
        $row = array_merge( $facts, array(
            'contract' => self::CONTRACT,
            'session_id' => $id,
            'secret_sha256' => hash( 'sha256', $token ),
            'scopes' => array_values( $scopes ),
            'issued_at_epoch' => $now,
            'expires_at_epoch' => $now + $ttl,
            'reason_sha256' => hash( 'sha256', $reason ),
        ) );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/operator-session-open', array(
            'session_id' => $id, 'site_uuid' => $facts['site_uuid'],
            'environment' => 'staging', 'scopes' => $scopes,
            'expires_at_epoch' => $now + $ttl, 'reason_sha256' => $row['reason_sha256'],
        ), 'ok' );
        if ( is_wp_error( $audit ) || ! set_transient( self::key( $id ), $row, $ttl ) )
            return new WP_Error( 'mad4b_operator_session_persist_failed', 'Audited session could not be persisted.' );
        return array(
            'contract' => self::CONTRACT,
            'session_id' => $id,
            'session_token' => $token,
            'scopes' => $scopes,
            'expires_at_epoch' => $now + $ttl,
            'eligible' => true,
            'authorized' => false,
            'execution_available' => false,
            'production_authorized' => false,
        );
    }

    public static function verify( $input, $operation = '' ) {
        $gate = self::can_access();
        if ( is_wp_error( $gate ) ) return $gate;
        if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_operator_invalid_session', 'Session input must be an object.' );
        $key = self::key( $input['session_id'] ?? null );
        $token = $input['session_token'] ?? null;
        if ( '' === $key || ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) )
            return new WP_Error( 'mad4b_operator_invalid_session', 'Invalid session credentials.' );
        $row = get_transient( $key );
        $facts = self::facts();
        if ( ! is_array( $row ) || is_wp_error( $facts ) ||
             time() >= (int) ( $row['expires_at_epoch'] ?? 0 ) ||
             ! hash_equals( (string) ( $row['secret_sha256'] ?? '' ), hash( 'sha256', $token ) ) )
            return new WP_Error( 'mad4b_operator_session_expired', 'Session missing, expired or invalid.' );
        foreach ( $facts as $label => $value ) {
            if ( ! isset( $row[ $label ] ) || ! hash_equals( (string) $value, (string) $row[ $label ] ) )
                return new WP_Error( 'mad4b_operator_session_binding_changed', 'Operator identity/site binding changed.' );
        }
        if ( '' !== $operation && ! in_array( $operation, $row['scopes'], true ) )
            return new WP_Error( 'mad4b_operator_scope_denied', 'Operation not within current session scopes.' );
        return $row;
    }

    /**
     * Trusted host worker recheck. This is deliberately NOT an MCP ability.
     * It returns no session secret or user profile information. The external
     * worker must additionally verify its pinned SSH identity and signed job.
     */
    public static function worker_live_status( $session_id, $operation, $target_fp, $client_fp, $ticket_id = '' ) {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'cli' !== PHP_SAPI ||
             ! self::enabled() || ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
             'staging' !== MAD4B_SCP_Site_Profile::current_environment() ||
             ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
             ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
            return array( 'eligible' => false, 'reason' => 'worker_environment_denied' );
        }
        $key = self::key( $session_id );
        $session = '' === $key ? false : get_transient( $key );
        $current_fp = MAD4B_SCP_Host_Bridge::target_fingerprint_readonly();
        if ( ! is_array( $session ) || ! is_string( $current_fp ) ||
             time() >= (int) ( $session['expires_at_epoch'] ?? 0 ) ||
             ! hash_equals( (string) ( $session['target_fingerprint'] ?? '' ), $target_fp ) ||
             ! hash_equals( $current_fp, $target_fp ) ||
             ! hash_equals( (string) ( $session['client_fingerprint'] ?? '' ), $client_fp ) ||
             ! in_array( $operation, $session['scopes'] ?? array(), true ) ) {
            return array( 'eligible' => false, 'reason' => 'session_expired_revoked_or_drifted' );
        }
        if ( 'sql.read' !== $operation ) {
            if ( ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) || '' === $ticket_id ) {
                return array( 'eligible' => false, 'reason' => 'approval_absent' );
            }
            $ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_id );
            if ( ! is_array( $ticket ) || 'executing' !== (string) ( $ticket['status'] ?? '' ) ||
                 strtotime( (string) ( $ticket['expires_at'] ?? '' ) ) <= time() ) {
                return array( 'eligible' => false, 'reason' => 'approval_revoked_or_not_claimed' );
            }
        }
        return array( 'eligible' => true, 'session_id' => $session_id,
            'operation' => $operation, 'environment' => 'staging',
            'expires_at_epoch' => (int) $session['expires_at_epoch'],
            'mutation_performed' => false );
    }

    public static function status( $input ) {
        $session = self::verify( $input );
        if ( is_wp_error( $session ) ) return $session;
        return array(
            'contract' => self::CONTRACT,
            'session_id' => $session['session_id'],
            'environment' => 'staging',
            'scopes' => $session['scopes'],
            'expires_at_epoch' => $session['expires_at_epoch'],
            'eligible' => true,
            'authorized' => false,
            'execution_available' => false,
            'mutation_performed' => false,
        );
    }

    public static function close( $input ) {
        $session = self::verify( $input );
        if ( is_wp_error( $session ) ) return $session;
        delete_transient( self::key( $session['session_id'] ) );
        $audit = MAD4B_SCP_Audit::record( 'mad4b/operator-session-close',
            array( 'session_id' => $session['session_id'], 'environment' => 'staging' ), 'ok' );
        if ( is_wp_error( $audit ) ) return $audit;
        return array( 'contract' => self::CONTRACT, 'closed' => true,
            'session_id' => $session['session_id'], 'mutation_performed' => true,
            'host_mutation_performed' => false );
    }
}
MAD4B_SCP_Operator_Sessions::boot();
