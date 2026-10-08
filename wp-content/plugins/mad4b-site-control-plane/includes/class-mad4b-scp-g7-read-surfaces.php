<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Optional G7 read-only WordPress Abilities. Each callback derives fresh
 * site-local evidence or verifies an exact sealed observation. Nothing here
 * executes repairs, starts host commands, writes options or grants access.
 */
final class MAD4B_SCP_G7_Read_Surfaces {
    const CONTRACT = 'mad4b.feature007-g7-read-surfaces.v1';

    public static function boot() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
        }
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $empty = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
        $observation = array( 'type' => 'object', 'additionalProperties' => true );
        $specs = array(
            'mad4b/g7-host-readiness' => array( 'Host Readiness (observation only)', 'host', $empty ),
            'mad4b/g7-update-observation' => array( 'Update Evidence Snapshot', 'update', $empty ),
            'mad4b/g7-update-comparison' => array( 'Update Evidence Comparison', 'compare',
                array( 'type' => 'object', 'properties' => array( 'before' => $observation,
                    'after' => $observation ), 'required' => array( 'before', 'after' ),
                    'additionalProperties' => false ) ),
            'mad4b/g7-action-center' => array( 'G7 Action Center (read only)', 'action', $empty ),
            'mad4b/g7-workload-measurement' => array( 'G7 Workload Evidence (read only)', 'metrics', $empty ),
            'mad4b/g7-release-audit' => array( 'G7 Release Acceptance Evidence (read only)', 'release',
                array( 'type' => 'object', 'properties' => array( 'before' => $observation,
                    'after' => $observation ), 'required' => array( 'before', 'after' ),
                    'additionalProperties' => false ) ),
        );
        foreach ( $specs as $name => $spec ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label' => $spec[0],
                'description' => 'Non-authorizing G7 safety observation. Repair, host execution, grants, update acceptance and Undo are not provided.',
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $spec[1] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => $spec[2],
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false,
                        'idempotent' => true )
                ),
            ) );
        }
    }

    public static function host( $input = array() ) {
        $valid = self::empty_input( $input ); if ( is_wp_error( $valid ) ) return $valid;
        return class_exists( 'MAD4B_SCP_G7_Host_Readiness' )
            ? MAD4B_SCP_G7_Host_Readiness::capture() : self::error( 'host_unavailable' );
    }

    public static function update( $input = array() ) {
        $valid = self::empty_input( $input ); if ( is_wp_error( $valid ) ) return $valid;
        return class_exists( 'MAD4B_SCP_G7_Update_Acceptance' )
            ? MAD4B_SCP_G7_Update_Acceptance::capture() : self::error( 'update_unavailable' );
    }

    public static function compare( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 2 ||
            ! isset( $input['before'], $input['after'] ) ||
            ! is_array( $input['before'] ) || ! is_array( $input['after'] ) ) {
            return self::error( 'comparison_input_invalid' );
        }
        return class_exists( 'MAD4B_SCP_G7_Update_Acceptance' )
            ? MAD4B_SCP_G7_Update_Acceptance::compare( $input['before'], $input['after'] )
            : self::error( 'update_unavailable' );
    }

    public static function action( $input = array() ) {
        $valid = self::empty_input( $input ); if ( is_wp_error( $valid ) ) return $valid;
        if ( ! class_exists( 'MAD4B_SCP_Operator_Control_Center' ) ||
             ! class_exists( 'MAD4B_SCP_G7_Action_Center' ) ) return self::error( 'operator_unavailable' );
        $snapshot = MAD4B_SCP_Operator_Control_Center::execute();
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        if ( ! is_array( $snapshot ) || ! isset( $snapshot['g7_action_center'] ) ||
            ! is_array( $snapshot['g7_action_center'] ) ||
            ( $snapshot['g7_action_center']['contract'] ?? '' ) !== MAD4B_SCP_G7_Action_Center::CONTRACT ) {
            return self::error( 'operator_projection_unavailable' );
        }
        return $snapshot['g7_action_center'];
    }

    public static function metrics( $input = array() ) {
        $valid = self::empty_input( $input ); if ( is_wp_error( $valid ) ) return $valid;
        return class_exists( 'MAD4B_SCP_G7_Workload_Measurement' )
            ? MAD4B_SCP_G7_Workload_Measurement::status( 24 ) : self::error( 'metrics_unavailable' );
    }

    public static function release( $input = array() ) {
        if ( ! is_array( $input ) || count( $input ) !== 2 ||
            ! isset( $input['before'], $input['after'] ) ||
            ! is_array( $input['before'] ) || ! is_array( $input['after'] ) ) {
            return self::error( 'release_audit_input_invalid' );
        }
        return class_exists( 'MAD4B_SCP_G7_Release_Acceptance_Audit' )
            ? MAD4B_SCP_G7_Release_Acceptance_Audit::assess( $input['before'], $input['after'] )
            : self::error( 'release_audit_unavailable' );
    }

    private static function empty_input( $input ) {
        return is_array( $input ) && empty( $input ) ? true : self::error( 'unexpected_input' );
    }

    private static function error( $reason ) {
        return new WP_Error( 'mad4b_g7_read_' . $reason,
            'G7 readings require exact current evidence and never grant authority.',
            array( 'authorizing' => false, 'mutation_performed' => false ) );
    }
}
