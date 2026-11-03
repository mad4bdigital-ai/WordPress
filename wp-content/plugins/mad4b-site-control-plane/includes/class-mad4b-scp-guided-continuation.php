<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Follow a provider's next-read hint in the same authenticated request.
 *
 * This is NOT a substitute write dispatcher. Every mutation, ticket, external
 * consent and content review remains on the original governed execution lane.
 * There is no persistence, cron privilege, or automatic replay of a failed
 * write. The caller retains its original work identifier for later resume.
 */
final class MAD4B_SCP_Guided_Continuation {
    const CONTRACT = 'mad4b.guided-continuation.v1';
    const MAX_STEPS = 3;

    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $name = 'mad4b/guided-continuation-run';
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
        wp_register_ability( $name, array(
            'label' => 'Follow Governed Next Read Step',
            'description' => 'Automatically inspect certified safe next-read hints; report governed write/approval handoff without executing it.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'run' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'next_ability' => array( 'type' => 'string', 'maxLength' => 191 ),
                'original_operation_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
                'max_steps' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_STEPS ),
            ), 'required' => array( 'next_ability' ) ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    // Even a read-labelled third-party Ability may be effectful. Only native,
    // audited, zero-required-input local inspectors are followed automatically.
    const SAFE_READS = array(
        'context/brand-core-control-loop',
        'context/brand-core-coverage',
        'mad4b/runtime-convergence-status',
        'mad4b/write-runtime-certification',
        'mad4b/skills-runtime-certification',
        'mad4b/brand-core-remote-recovery-status',
    );

    public static function run( $input ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ),
            array( 'next_ability', 'original_operation_sha256', 'max_steps' ) ) )
            return new WP_Error( 'mad4b_guided_continuation_input_invalid', 'Expected a bounded guided continuation request.' );
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_guided_continuation_read_denied', 'Governed read permission is required.' );
        $name = isset( $input['next_ability'] ) ? (string) $input['next_ability'] : '';
        $digest = isset( $input['original_operation_sha256'] ) ? (string) $input['original_operation_sha256'] : '';
        $max = isset( $input['max_steps'] ) ? $input['max_steps'] : self::MAX_STEPS;
        if ( ! preg_match( '~^[a-z0-9][a-z0-9/._-]{2,190}$~D', $name )
            || ! is_int( $max ) || $max < 1 || $max > self::MAX_STEPS
            || ( '' !== $digest && ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) )
            return new WP_Error( 'mad4b_guided_continuation_identity_invalid', 'Exact next Ability and bounded request identity are required.' );

        $seen = array();
        $steps = array();
        $stop = 'AWAITING_GOVERNED_ACTION';
        $next = $name;
        for ( $n = 0; $n < $max; $n++ ) {
            if ( isset( $seen[ $next ] ) ) { $stop = 'CYCLE_DETECTED'; break; }
            $seen[ $next ] = true;
            if ( ! in_array( $next, self::SAFE_READS, true ) ) {
                $stop = 'AWAITING_GOVERNED_ACTION';
                break;
            }
            $ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $next ) : null;
            if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' )
                || ! method_exists( $ability, 'get_category' ) || ! method_exists( $ability, 'get_meta' )
                || ! method_exists( $ability, 'execute' ) ) { $stop = 'READ_ABILITY_UNAVAILABLE'; break; }
            $schema = $ability->get_input_schema();
            $meta = $ability->get_meta();
            if ( ! in_array( (string) $ability->get_category(), array( 'mad4b-read', 'mad4b-context' ), true )
                || ! is_array( $meta ) || true !== ( $meta['annotations']['readonly'] ?? null )
                || ! is_array( $schema ) || ! empty( $schema['required'] ) ) {
                $stop = 'READ_CONTRACT_NOT_CERTIFIED';
                break;
            }
            try { $result = $ability->execute( array() ); }
            catch ( Throwable $ignored ) { $stop = 'READ_EXECUTION_UNAVAILABLE'; break; }
            if ( is_wp_error( $result ) ) {
                $steps[] = array( 'ability' => $next, 'state' => 'BLOCKED',
                    'error_code' => substr( (string) $result->get_error_code(), 0, 96 ) );
                $stop = 'READ_BLOCKED';
                break;
            }
            if ( ! is_array( $result ) ) { $stop = 'READ_RESULT_UNEXPECTED'; break; }
            // No raw provider content, secrets, Context envelopes or receipts.
            $state = isset( $result['state'] ) && is_scalar( $result['state'] )
                ? substr( (string) $result['state'], 0, 64 ) : 'OBSERVED';
            $ready = true === ( $result['ready'] ?? false );
            $suggested = isset( $result['next_ability'] ) && is_string( $result['next_ability'] )
                ? $result['next_ability'] : '';
            $steps[] = array(
                'ability' => $next, 'state' => $state, 'ready' => $ready,
                'next_ability' => substr( $suggested, 0, 191 ),
            );
            if ( $ready ) {
                $stop = 'READY_FOR_ORIGINAL_WORKFLOW_RECHECK';
                $next = '';
                break;
            }
            if ( '' === $suggested || $suggested === $next ) {
                $stop = 'AWAITING_GOVERNED_ACTION';
                break;
            }
            $next = $suggested;
        }
        if ( 'AWAITING_GOVERNED_ACTION' === $stop && count( $steps ) >= $max
            && '' !== $next && in_array( $next, self::SAFE_READS, true ) ) {
            $stop = 'READ_STEP_BUDGET_EXHAUSTED';
        }
        return array(
            'contract' => self::CONTRACT,
            'state' => $stop,
            'original_operation_sha256' => $digest,
            'next_ability' => $next,
            'steps' => $steps,
            'step_count' => count( $steps ),
            'resume_original_request' => 'READY_FOR_ORIGINAL_WORKFLOW_RECHECK' === $stop,
            'fresh_receipt_and_exact_write_approval_required' => true,
            'approval_created' => false,
            'original_mutation_replayed' => false,
            'authorizing' => false,
            'read_only' => true,
            'mutation_performed' => false,
        );
    }
}
