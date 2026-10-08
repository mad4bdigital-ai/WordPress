<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-release-fence.php';
require_once __DIR__ . '/class-mad4b-scp-g9-restore-convergence.php';
require_once __DIR__ . '/class-mad4b-scp-g9-local-reader.php';

/** G9 exposes only passive exact-site reads; release/restore mutations remain private. */
final class MAD4B_SCP_G9_Read_Surface {
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        // Pin one server-owned passive observer; never instantiate a class or
        // callback from a request or discovered manifest.
        MAD4B_SCP_Resilience_Context::register_reader( new MAD4B_SCP_G9_Local_Reader() );
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' )
            || ! class_exists( 'MAD4B_SCP_Policy' ) ) return;
        foreach ( array(
            'mad4b/g9-site-observation' => array(
                'G9 Site Observation', array( __CLASS__, 'site_observation' ),
            ),
            'mad4b/g9-restore-status' => array(
                'G9 Restore Convergence Status', array( __CLASS__, 'restore_status' ),
            ),
        ) as $ability => $spec ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability ) ) continue;
            wp_register_ability( $ability, array(
                'label' => $spec[0],
                'description' => 'Read only the enrolled current local site; never infer fleet membership or restoration of write authority.',
                'category' => 'mad4b-read',
                'execute_callback' => $spec[1],
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array(
                    'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array(
                        'readonly' => true, 'destructive' => false, 'idempotent' => true,
                    ),
                ),
            ) );
        }
    }

    public static function site_observation( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::invalid_input();
        $snapshot = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $snapshot['binding'] );
        if ( is_wp_error( $anchor ) ) return $anchor;
        return array(
            'contract' => 'mad4b.g9.read-site-observation.v1',
            'site_key' => MAD4B_SCP_Resilience_Context::site_key( $snapshot['binding'] ),
            'environment' => $snapshot['binding']['environment'],
            'snapshot_sha256' => $snapshot['snapshot_sha256'],
            'binding_sha256' => $snapshot['binding_sha256'],
            'external_anchor_initialized' => is_int( $anchor['revision'] ) && $anchor['revision'] > 0,
            'anchor_revision' => $anchor['revision'],
            'identity_blockers' => $snapshot['identity_blockers'],
            'authority_eligible' => ! empty( $snapshot['authority']['eligible'] ),
            'provider_evidence_present' => ! empty( $snapshot['providers'] ),
            'host_evidence_present' => ! empty( $snapshot['host'] ),
            'release_execution_supported' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    public static function restore_status( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::invalid_input();
        return MAD4B_SCP_G9_Restore_Convergence::status();
    }

    private static function invalid_input() {
        return new WP_Error( 'mad4b_g9_read_input_invalid', 'G9 passive reads do not accept selectors or arbitrary site IDs.' );
    }
}
