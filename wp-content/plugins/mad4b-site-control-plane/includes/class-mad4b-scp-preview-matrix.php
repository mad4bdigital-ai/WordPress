<?php
/**
 * Bounded, source-bound preview lane discovery. Read-only: no web requests,
 * no browser launch, no CLI, no Theme Mod changes, and no acceptance receipt.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Preview_Matrix {
    const CONTRACT = 'mad4b.preview-matrix-plan.v1';
    const ABILITY = 'mad4b/preview-matrix-plan';

    public static function boot() {
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
    }

    public static function register_ability() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
        wp_register_ability( self::ABILITY, array(
            'label' => 'Plan Staging Preview Evidence Matrix',
            'description' => 'Read-only discovery of bounded Chromium, WordPress Customizer, and WP-CLI evidence lanes; never authorizes acceptance.',
            'category' => 'mad4b-read',
            'execute_callback' => array( __CLASS__, 'plan' ),
            'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
            'input_schema' => array(
                'type' => 'object', 'additionalProperties' => false,
                'properties' => array(
                    'target_path' => array( 'type' => 'string', 'maxLength' => 160, 'pattern' => '^/[A-Za-z0-9/_.-]*$' ),
                    'mode' => array( 'type' => 'string', 'enum' => array( 'all', 'browser', 'customizer', 'native' ) ),
                ),
            ),
            'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
            'meta' => array(
                'public' => false, 'show_in_rest' => false,
                'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
            ),
        ) );
    }

    private static function safe_path( $path ) {
        return is_string( $path ) && strlen( $path ) <= 160
            && 1 === preg_match( '#^/[A-Za-z0-9/_.-]*$#D', $path )
            && false === strpos( $path, '..' ) && false === strpos( $path, '//' );
    }

    private static function runtime_origin() {
        $origin = function_exists( 'home_url' ) ? rtrim( (string) home_url( '/' ), '/' ) : '';
        $site = function_exists( 'site_url' ) ? rtrim( (string) site_url( '/' ), '/' ) : '';
        return '' !== $origin && hash_equals( $origin, $site ) && 0 === strpos( $origin, 'https://' )
            ? $origin : '';
    }

    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        if ( array_diff( array_keys( $input ), array( 'target_path', 'mode' ) ) ) {
            return self::blocked( 'unsupported_input_fields' );
        }
        $path = isset( $input['target_path'] ) ? $input['target_path'] : '/';
        if ( ! self::safe_path( $path ) ) return self::blocked( 'unsafe_target_path' );
        $mode = isset( $input['mode'] ) ? $input['mode'] : 'all';
        if ( ! in_array( $mode, array( 'all', 'browser', 'customizer', 'native' ), true ) ) {
            return self::blocked( 'unsupported_mode' );
        }
        $environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
        $origin = self::runtime_origin();
        if ( 'staging' !== $environment || '' === $origin ) {
            return self::blocked( 'exact_staging_origin_required' );
        }
        $build = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
            ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
        if ( ! is_array( $build ) || empty( $build['manifest_valid'] )
            || empty( $build['runtime_manifest_match'] )
            || ! isset( $build['source_commit_sha'], $build['build_fingerprint'], $build['package_manifest_digest'] ) ) {
            return self::blocked( 'current_build_provenance_required' );
        }
        $source = strtolower( (string) $build['source_commit_sha'] );
        $fingerprint = strtolower( (string) $build['build_fingerprint'] );
        $manifest = strtolower( (string) $build['package_manifest_digest'] );
        if ( 1 !== preg_match( '/^[a-f0-9]{40}$/D', $source )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint )
            || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $manifest ) ) {
            return self::blocked( 'build_identity_invalid' );
        }

        $frontend = MAD4B_SCP_Live_Acceptance_Observer::frontend_performance_status();
        $sample_count = is_array( $frontend ) && isset( $frontend['evaluation_window']['sample_count'] )
            ? (int) $frontend['evaluation_window']['sample_count'] : 0;
        $minimum = is_array( $frontend ) && isset( $frontend['evaluation_window']['min_samples'] )
            ? (int) $frontend['evaluation_window']['min_samples'] : 3;
        $lanes = array(
            'browser' => array(
                'engine' => 'external_chromium',
                'artifact' => 'tools/mad4b-preview-matrix.py',
                'runtime' => 'real_browser',
                'transport' => 'https_same_origin',
                'acceptance_requires' => array( 'exact_claimed_external_work', 'independently_observed_probe_hash', 'signed_runner_receipt' ),
                'source_identity_recheck_required' => true,
            ),
            'customizer' => array(
                'engine' => 'wordpress_classic_customizer_iframe',
                'artifact' => 'tools/mad4b-preview-matrix.py',
                'runtime' => 'authenticated_theme_preview',
                'transport' => 'https_same_origin',
                'availability' => 'runtime_browser_probe_required',
                'acceptance_requires' => array( 'explicit_same_origin_session', 'iframe_path_and_js_runtime_checks' ),
                'equivalent_to_public_frontend' => false,
            ),
            'native' => array(
                'engine' => 'on_host_wp_cli_eval_file',
                'artifact' => 'tools/mad4b-preview-native-probe.php',
                'runtime' => 'wp_bootstrap_diagnostic',
                'transport' => 'local_wp_cli_only',
                'availability' => 'on_host_runner_check_required',
                'equivalent_to_http_timing' => false,
                'rest_request_scope' => '/wp/v2/types GET only',
            ),
        );
        if ( 'all' !== $mode ) $lanes = array( $mode => $lanes[ $mode ] );
        return array(
            'contract' => self::CONTRACT,
            'state' => 'ready_for_external_execution_planning',
            'target_path' => $path,
            'site_origin' => $origin,
            'environment' => 'staging',
            'source_commit_sha' => $source,
            'build_fingerprint' => $fingerprint,
            'package_manifest_digest' => $manifest,
            'lanes' => $lanes,
            'observed_frontend_samples' => $sample_count,
            'required_frontend_samples' => $minimum,
            'remaining_frontend_samples' => max( 0, $minimum - $sample_count ),
            'frontend_budget_ready' => is_array( $frontend ) && ! empty( $frontend['ready'] ),
            'frontend_budget' => is_array( $frontend ) && isset( $frontend['budget'] ) ? $frontend['budget'] : array(),
            'request_surface_and_external_receipts_required' => true,
            'browser_execution_performed' => false,
            'native_execution_performed' => false,
            'customizer_settings_changed' => false,
            'authorizing' => false, 'read_only' => true,
            'staging_release_certified' => false,
            'production_mutation' => false,
            'mutation_performed' => false,
        );
    }

    private static function blocked( $reason ) {
        return array(
            'contract' => self::CONTRACT, 'state' => 'blocked',
            'blockers' => array( (string) $reason ),
            'authorizing' => false, 'read_only' => true,
            'staging_release_certified' => false, 'production_mutation' => false,
            'mutation_performed' => false,
        );
    }
}
