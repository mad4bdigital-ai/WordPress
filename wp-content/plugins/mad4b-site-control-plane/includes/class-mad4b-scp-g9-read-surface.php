<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g9-release-fence.php';
require_once __DIR__ . '/class-mad4b-scp-g9-restore-convergence.php';
require_once __DIR__ . '/class-mad4b-scp-g9-local-reader.php';
require_once __DIR__ . '/class-mad4b-scp-g9-operational-readiness.php';

/** G9 exposes only passive exact-site reads; release/restore mutations remain private. */
final class MAD4B_SCP_G9_Read_Surface {
    private static $booted = false;
    private static $reader_pinned = false;
    private static $boot_result = null;
    private static $abilities_registered = false;

    public static function boot() {
        // Process-scoped, deterministic one-time pinning. Repeated calls must
        // return the original failure or true; null must not hide a denial.
        if ( self::$booted ) return self::$boot_result;
        self::$booted = true;
        // Pin one server-owned passive observer; never instantiate a class or
        // callback from a request or discovered manifest. A competing earlier
        // registration is a boot integrity failure, not a fallback source.
        $pinned = MAD4B_SCP_Resilience_Context::register_reader( new MAD4B_SCP_G9_Local_Reader() );
        if ( true !== $pinned ) {
            // Do not expose a WordPress Ability backed by an untrusted or
            // unexpected reader. Existing registration is never overwritten.
            self::$boot_result = is_wp_error( $pinned ) ? $pinned : new WP_Error(
                'mad4b_g9_reader_pin_failed', 'Code-owned G9 passive reader could not be pinned.'
            );
            return self::$boot_result;
        }
        self::$reader_pinned = true;
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
        self::$boot_result = true;
        return self::$boot_result;
    }

    public static function register_abilities() {
        if ( self::$abilities_registered ) return true;
        // WordPress Abilities can also be invoked directly by another PHP
        // component. Do not expose read endpoints without a verified
        // code-owned reader even when boot hook registration was bypassed.
        if ( ! self::$reader_pinned )
            return new WP_Error( 'mad4b_g9_reader_not_pinned',
                'The code-owned G9 passive reader did not initialize successfully.' );
        if ( ! function_exists( 'wp_register_ability' )
            || ! function_exists( 'wp_has_ability' )
            || ! class_exists( 'MAD4B_SCP_Policy' ) )
            return new WP_Error( 'mad4b_g9_ability_runtime_unavailable',
                'The WordPress read Ability registration prerequisites are unavailable.' );
        $abilities = array(
            'mad4b/g9-site-observation' => array(
                'G9 Site Observation', array( __CLASS__, 'site_observation' ),
            ),
            'mad4b/g9-restore-status' => array(
                'G9 Restore Convergence Status', array( __CLASS__, 'restore_status' ),
            ),
            'mad4b/g9-closure-status' => array(
                'G9 Operational Closure Blockers', array( __CLASS__, 'closure_status' ),
            ),
        );
        foreach ( $abilities as $ability => $spec ) {
            // Preflight ALL names, not one-by-one. A separately registered
            // Ability could have an unrelated callback or mutation policy;
            // registering the remaining two would create a misleading
            // partially-owned G9 surface. Never overwrite foreign owners.
            if ( wp_has_ability( $ability ) )
                return new WP_Error( 'mad4b_g9_ability_namespace_collision',
                    'A G9 read Ability name was registered before this code-owned surface.' );
        }
        foreach ( $abilities as $ability => $spec ) {
            $registered = wp_register_ability( $ability, array(
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
            if ( is_wp_error( $registered ) || false === $registered )
                return new WP_Error( 'mad4b_g9_ability_registration_failed',
                    'The code-owned read Ability could not be registered.' );
        }
        self::$abilities_registered = true;
        return true;
    }

    public static function site_observation( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::invalid_input();
        $snapshot = MAD4B_SCP_Resilience_Context::capture();
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $anchor = MAD4B_SCP_Resilience_Anchor::read( $snapshot['binding'] );
        if ( is_wp_error( $anchor ) ) return $anchor;
        $site_key = MAD4B_SCP_Resilience_Context::site_key( $snapshot['binding'] );
        // Completeness from a code-owned observer is not enough by itself:
        // each claimed provider and effect must be fresh-generation/site-bound
        // and in a terminal, non-revoked state. No signature or executable
        // release acceptance is inferred by these passive booleans.
        $provider_verified = true === ( $snapshot['gates']['provider_inventory_complete'] ?? null )
            && is_array( $snapshot['providers'] ?? null )
            && count( $snapshot['providers'] ) >= 1
            && count( $snapshot['providers'] ) <= 32;
        if ( $provider_verified ) foreach ( $snapshot['providers'] as $provider ) {
            if ( ! is_array( $provider )
                || true !== ( $provider['ready'] ?? null )
                || ! empty( $provider['revoked'] )
                || ( $provider['site_key'] ?? '' ) !== $site_key
                || ( $provider['generation_sha256'] ?? '' ) !== $snapshot['binding']['runtime_generation_sha256']
                || ! MAD4B_SCP_Resilience_Context::is_hash( $provider['certification_sha256'] ?? '' ) ) {
                $provider_verified = false;
                break;
            }
        }
        $effects_verified = true === ( $snapshot['gates']['external_effect_inventory_complete'] ?? null )
            && is_array( $snapshot['external_effects'] ?? null )
            && count( $snapshot['external_effects'] ) <= 128;
        if ( $effects_verified ) foreach ( $snapshot['external_effects'] as $effect ) {
            if ( ! is_array( $effect )
                || ! in_array( $effect['state'] ?? null,
                    array( 'verified_no_effect', 'verified_reconciled' ), true )
                || ( $effect['site_key'] ?? '' ) !== $site_key
                || ! MAD4B_SCP_Resilience_Context::is_hash( $effect['receipt_sha256'] ?? '' ) ) {
                $effects_verified = false;
                break;
            }
        }
        $health = $snapshot['health'] ?? array();
        $now = MAD4B_SCP_Resilience_Context::now();
        $health_verified = true === ( $snapshot['gates']['health_sample_window_complete'] ?? null )
            && is_array( $health )
            && is_int( $health['observed_at'] ?? null )
            && $health['observed_at'] <= $now && $health['observed_at'] >= $now - 120
            && is_int( $health['sample_count'] ?? null ) && $health['sample_count'] > 0
            && is_int( $health['error_rate_bps'] ?? null )
            && $health['error_rate_bps'] >= 0 && $health['error_rate_bps'] <= 10000
            && is_int( $health['p95_ms'] ?? null ) && $health['p95_ms'] >= 0;
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
            // An inventory object or host diagnostic is not a certificate.
            // Keep descriptive presence separate from explicitly verified
            // site-local completeness, isolation and readback facts.
            'provider_evidence_present' => ! empty( $snapshot['providers'] ),
            'provider_evidence_verified' => $provider_verified,
            'host_evidence_present' => ! empty( $snapshot['host'] ),
            'host_isolation_verified' => true === ( $snapshot['host']['isolation_verified'] ?? null )
                && true === ( $snapshot['host']['local_readback_verified'] ?? null )
                && true === ( $snapshot['host']['single_host_exclusive_verified'] ?? null )
                && true === ( $snapshot['gates']['host_inventory_complete'] ?? null ),
            'health_window_verified' => $health_verified,
            'external_effect_inventory_verified' => $effects_verified,
            'release_execution_supported' => false,
            'authorizing' => false, 'mutation_performed' => false,
        );
    }

    public static function restore_status( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::invalid_input();
        return MAD4B_SCP_G9_Restore_Convergence::status();
    }

    public static function closure_status( $input = array() ) {
        if ( ! is_array( $input ) || $input ) return self::invalid_input();
        return MAD4B_SCP_G9_Operational_Readiness::status();
    }

    private static function invalid_input() {
        return new WP_Error( 'mad4b_g9_read_input_invalid', 'G9 passive reads do not accept selectors or arbitrary site IDs.' );
    }
}
