<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request-local OAuth operator authorization bridge to the existing Host Bridge.
 * NO subprocesses / DB statements execute inside WordPress PHP.
 * Fail closed unless the separately certified executor is explicitly enabled.
 */
final class MAD4B_SCP_Operator_Bridge {
    const CONTRACT = 'mad4b.operator-host-bridge.v1';
    private static $authorized = array();

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 27 );
    }

    private static function backend_enabled() {
        if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) ||
             MAD4B_SCP_Developer_Runtime::kill_switch_enabled() ) return false;
        return defined( 'MAD4B_MCP_OPERATOR_EXECUTION_ENABLED' ) &&
            true === constant( 'MAD4B_MCP_OPERATOR_EXECUTION_ENABLED' );
    }

    public static function request_authority_matches( $operation_id, $arguments ) {
        if ( ! self::$authorized || ! is_array( $arguments ) ) return false;
        if ( ! hash_equals( (string) self::$authorized['host_operation_id'], (string) $operation_id ) ) return false;
        if ( ! hash_equals( (string) self::$authorized['session_id'], (string) ( $arguments['session_id'] ?? '' ) ) ) return false;
        return hash_equals( (string) self::$authorized['payload_sha256'],
            (string) ( $arguments['payload_sha256'] ?? '' ) );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $base = array(
            'session_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
            'session_token' => array( 'type' => 'string', 'minLength' => 43, 'maxLength' => 43 ),
            'operation' => array( 'type' => 'string', 'enum' => array( 'shell', 'wp-cli', 'sql.read', 'sql.write', 'sql.schema' ) ),
            'payload' => array( 'type' => 'object', 'additionalProperties' => true ),
            'idempotency_key' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 120 ),
            'approval_ref' => array( 'type' => 'string', 'maxLength' => 191 ),
            '_mad4b_approval_ticket_id' => array( 'type' => 'string', 'maxLength' => 36 ),
        );
        self::register( 'mad4b/operator-operation-plan', 'Plan Operator Execution', 'plan', true,
            $base, array( 'session_id', 'session_token', 'operation', 'payload' ) );
        self::register( 'mad4b/operator-operation-submit', 'Submit Operator Execution', 'submit', false,
            $base, array( 'session_id', 'session_token', 'operation', 'payload', 'idempotency_key' ) );
    }

    private static function register( $name, $label, $method, $readonly, $properties, $required ) {
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
        wp_register_ability( $name, array(
            'label' => $label,
            'description' => $label . ' via the existing OAuth-bound Staging Host Bridge.',
            'category' => 'mad4b-developer',
            'execute_callback' => array( __CLASS__, $method ),
            'permission_callback' => array( 'MAD4B_SCP_Operator_Sessions', 'can_access' ),
            'input_schema' => array( 'type' => 'object', 'properties' => $properties,
                'required' => $required, 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'developer', 'production_allowed' => false ),
                'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly,
                    'idempotent' => false ),
            ),
        ) );
    }

    private static function prepare( $input, $callback ) {
        if ( ! is_array( $input ) || ! is_callable( $callback ) ) return new WP_Error( 'mad4b_operator_input_invalid', 'Invalid execution input.' );
        if ( ! self::backend_enabled() ) return new WP_Error( 'mad4b_operator_backend_disabled', 'Certified remote execution backend is not enabled.' );
        if ( self::$authorized ) return new WP_Error( 'mad4b_operator_nested_session_denied', 'Nested operator sessions are not allowed.' );
        $op = (string) ( $input['operation'] ?? '' );
        $session = MAD4B_SCP_Operator_Sessions::verify( $input, $op );
        if ( is_wp_error( $session ) ) return $session;
        $semantic = MAD4B_SCP_Operator_Operation_Policy::inspect( array(
            'operation' => $op, 'payload' => $input['payload'] ?? null,
        ) );
        if ( is_wp_error( $semantic ) ) return $semantic;
        if ( empty( $session['session_id'] ) || (int) $session['expires_at_epoch'] <= time() )
            return new WP_Error( 'mad4b_operator_session_expired', 'Session expired before plan.' );
        $id = 'operator.' . $op;
        $args = array(
            'session_id' => (string) $session['session_id'],
            'session_expires_at_epoch' => (int) $session['expires_at_epoch'],
            'payload_sha256' => (string) $semantic['payload_sha256'],
            'operation' => $op,
            'payload' => $semantic['payload'],
            'risk' => (string) $semantic['risk'],
            'site_uuid' => (string) $session['site_uuid'],
            'client_fingerprint' => (string) $session['client_fingerprint'],
        );
        self::$authorized = array(
            'session_id' => $session['session_id'],
            'payload_sha256' => $semantic['payload_sha256'],
            'host_operation_id' => $id,
        );
        try {
            return call_user_func( $callback, $id, $args, $semantic );
        } finally {
            self::$authorized = array();
        }
    }

    public static function plan( $input ) {
        return self::prepare( $input, static function ( $id, $args ) {
            $profile_id = MAD4B_SCP_Operator_Runner_Profile::current_id();
            if ( is_wp_error( $profile_id ) ) return $profile_id;
            return MAD4B_SCP_Host_Bridge::plan( array(
                'operation_id' => $id,
                'runner_profile_id' => $profile_id,
                'arguments' => $args,
            ) );
        } );
    }

    public static function submit( $input ) {
        return self::prepare( $input, static function ( $id, $args, $semantic ) use ( $input ) {
            $profile_id = MAD4B_SCP_Operator_Runner_Profile::current_id();
            if ( is_wp_error( $profile_id ) ) return $profile_id;
            $plan = MAD4B_SCP_Host_Bridge::plan( array(
                'operation_id' => $id,
                'runner_profile_id' => $profile_id,
                'arguments' => $args,
            ) );
            if ( is_wp_error( $plan ) ) return $plan;
            if ( ! isset( $input['idempotency_key'] ) ||
                1 !== preg_match( '/^[A-Za-z0-9_-]{12,120}$/D', (string) $input['idempotency_key'] ) )
                return new WP_Error( 'mad4b_operator_idempotency_invalid', 'Exact idempotency key required.' );
            $write = (string) $semantic['risk'] === 'mutation_possible';
            if ( $write && ( empty( $input['approval_ref'] ) || empty( $input['_mad4b_approval_ticket_id'] ) ) )
                return new WP_Error( 'mad4b_operator_approval_required', 'Exact preapproved mutation ticket is required.' );
            $request = array(
                'plan' => $plan,
                'idempotency_key' => (string) $input['idempotency_key'],
                'approval_ref' => (string) ( $input['approval_ref'] ?? '' ),
                '_mad4b_approval_ticket_id' => (string) ( $input['_mad4b_approval_ticket_id'] ?? '' ),
                'server_id' => 'mad4b-developer',
            );
            $result = MAD4B_SCP_Host_Bridge::apply( $request );
            if ( is_wp_error( $result ) ) return $result;
            return array( 'contract' => self::CONTRACT,
                'job_id' => $result['job_id'], 'plan_sha256' => $plan['plan_sha256'],
                'queued' => (bool) ( $result['queued'] ?? false ),
                'host_mutation_performed' => false, 'host_verification_pending' => true,
                'production_authorized' => false );
        } );
    }
}
MAD4B_SCP_Operator_Bridge::boot();
