<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only equality evidence; matching receipts cannot attest MCP transport. */
final class MAD4B_SCP_Admin_Remote_Parity {
    const ABILITY = 'mad4b/admin-remote-parity-snapshot';
    const TARGET = 'mad4b/plugin-capability-graph';

    public static function boot() {
        // Standalone contract tests load the server catalog without WordPress.
        // Hook only when the real WP event API exists; never emulate authority.
        if ( ! function_exists( 'add_action' ) ) return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 43 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Admin and Remote Read Snapshot',
            'description' => 'Capture actual WordPress Ability read evidence under the current subject, without asserting transport certification.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'capture' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array( 'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
        ) );
    }

    public static function capture( $input = array() ) {
        if ( ! is_array( $input ) || $input
            || ! current_user_can( 'manage_options' )
            || ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_read() )
            return new WP_Error( 'mad4b_parity_read_not_authorized', 'Read-only parity capture requires an authorized administrator.' );
        if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'MAD4B_SCP_Servers', false ) )
            return new WP_Error( 'mad4b_parity_runtime_missing', 'WordPress read/MCP registration is not available.' );
        $target = wp_get_ability( self::TARGET );
        $mounted = MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-read', self::TARGET );
        if ( ! is_object( $target ) || ! method_exists( $target, 'execute' ) || ! $mounted )
            return new WP_Error( 'mad4b_parity_read_route_unavailable', 'Target read must be registered and mounted.' );
        $result = $target->execute( array() );
        if ( is_wp_error( $result ) ) return $result;
        if ( ! is_array( $result ) || empty( $result['complete'] )
            || ! is_string( $result['graph_sha256'] ?? null )
            || ! preg_match( '/^[a-f0-9]{64}$/D', $result['graph_sha256'] ) )
            return new WP_Error( 'mad4b_parity_graph_incomplete', 'Exact plugin capability graph read is incomplete.' );
        $site = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        $actor = (int) get_current_user_id();
        if ( '' === $site || $actor <= 0 )
            return new WP_Error( 'mad4b_parity_identity_missing', 'Site or WordPress actor identity is missing.' );
        $site_sha = hash( 'sha256', $site );
        $identity = class_exists( 'MAD4B_SCP_Identity_Context', false )
            ? MAD4B_SCP_Identity_Context::current() : array();
        $subject = is_array( $identity ) && ! empty( $identity['authenticated'] )
            ? (string) ( $identity['subject_fingerprint'] ?? '' ) : '';
        $projection = array(
            'graph_sha256' => $result['graph_sha256'],
            'installed_plugin_count' => (int) ( $result['installed_plugin_count'] ?? 0 ),
            'registered_ability_count' => (int) ( $result['registered_ability_count'] ?? 0 ),
            'complete' => true,
        );
        return array(
            'contract' => 'mad4b.admin-remote-read-snapshot.v1',
            'state' => 'Available', 'target_ability' => self::TARGET,
            'site_sha256' => $site_sha,
            'wp_actor_sha256' => hash( 'sha256', $site_sha . '|' . $actor ),
            'subject_sha256' => preg_match( '/^[a-f0-9]{64}$/D', $subject ) ? $subject : '',
            'read_authorized' => true, 'route_mounted' => true,
            'graph_sha256' => $result['graph_sha256'],
            'read_result_sha256' => hash( 'sha256', (string) wp_json_encode( $projection ) ),
            'actual_ability_executed' => true,
            'transport_certified' => false, 'parity_verified' => false,
            'write_authority_created' => false, 'mutation_performed' => false,
        );
    }

    /** Never certify two source-controlled snapshots as real MCP traffic. */
    public static function compare( $admin, $remote ) {
        $out = array( 'contract' => 'mad4b.admin-remote-read-comparison.v1',
            'state' => 'Needs Review', 'same_read' => false,
            'transport_certified' => false, 'parity_verified' => false,
            'mutation_performed' => false, 'mismatches' => array() );
        foreach ( array( 'admin' => $admin, 'remote' => $remote ) as $side => $row ) {
            if ( ! is_array( $row ) || ( $row['contract'] ?? '' ) !== 'mad4b.admin-remote-read-snapshot.v1'
                || ( $row['target_ability'] ?? '' ) !== self::TARGET ) {
                $out['mismatches'][] = $side . '_contract_invalid';
                continue;
            }
            if ( empty( $row['read_authorized'] ) ) {
                $out['state'] = 'Not Authorized';
                $out['mismatches'][] = $side . '_read_denied';
            }
            if ( empty( $row['route_mounted'] ) || empty( $row['actual_ability_executed'] )
                || ( $row['state'] ?? '' ) !== 'Available' )
                $out['mismatches'][] = $side . '_read_not_executed';
            foreach ( array( 'site_sha256', 'wp_actor_sha256', 'graph_sha256', 'read_result_sha256' ) as $field ) {
                if ( ! is_string( $row[$field] ?? null )
                    || ! preg_match( '/^[a-f0-9]{64}$/D', $row[$field] ) )
                    $out['mismatches'][] = $side . '_' . $field . '_missing';
            }
        }
        if ( $out['mismatches'] ) return $out;
        foreach ( array( 'site_sha256', 'wp_actor_sha256', 'graph_sha256', 'read_result_sha256' ) as $field ) {
            if ( ! hash_equals( $admin[$field], $remote[$field] ) )
                $out['mismatches'][] = $field . '_mismatch';
        }
        if ( $out['mismatches'] ) return $out;
        $out['same_read'] = true;
        $out['state'] = 'Available';
        return $out;
    }
}
