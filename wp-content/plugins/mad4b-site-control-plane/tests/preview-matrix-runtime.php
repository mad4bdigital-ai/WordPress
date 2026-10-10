<?php
/** Hermetic WordPress/PHP 7.4+ contract tests for non-authorizing preview plan. */
define( 'ABSPATH', '/tmp/wp/' );
$GLOBALS['mad4b_preview_registered'] = array();
$GLOBALS['mad4b_preview_environment'] = 'staging';
$GLOBALS['mad4b_preview_origin'] = 'https://staging.allroyalegypt.com';
$GLOBALS['mad4b_preview_tests'] = 0;
function wp_get_environment_type() { return $GLOBALS['mad4b_preview_environment']; }
function home_url( $path = '' ) { return $GLOBALS['mad4b_preview_origin'] . $path; }
function site_url( $path = '' ) { return $GLOBALS['mad4b_preview_origin'] . $path; }
function add_action( $name, $callable, $priority = 10 ) { $GLOBALS['mad4b_preview_hook'] = array( $name, $callable, $priority ); }
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_preview_registered'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_preview_registered'][ $name ] = $args; return true; }

final class MAD4B_SCP_Site_Profile {
    public static function status() {
        return array(
            'configured' => true,
            'environment' => $GLOBALS['mad4b_preview_environment'],
            'environment_match' => empty( $GLOBALS['mad4b_preview_profile_drift'] ),
            'origin_match' => empty( $GLOBALS['mad4b_preview_profile_drift'] ),
            'exact_profile_bound' => true,
        );
    }
}
final class MAD4B_SCP_Live_Acceptance_Observer {
    public static function build_provenance_status() {
        return array(
            'source_commit_sha' => str_repeat( 'a', 40 ),
            'build_fingerprint' => str_repeat( 'b', 64 ),
            'package_manifest_digest' => str_repeat( 'c', 64 ),
            'manifest_valid' => true,
            'runtime_manifest_match' => true,
        );
    }
    public static function frontend_performance_status() {
        return array(
            'ready' => false,
            'evaluation_window' => array( 'sample_count' => 2, 'min_samples' => 3 ),
            'budget' => array( 'db_queries_max' => 100 ),
        );
    }
}
function check( $truth, $code ) {
    if ( ! $truth ) throw new Exception( $code );
    $GLOBALS['mad4b_preview_tests']++;
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-preview-matrix.php';
MAD4B_SCP_Preview_Matrix::boot();
check( $GLOBALS['mad4b_preview_hook'][0] === 'wp_abilities_api_init', 'registration_hook' );
call_user_func( $GLOBALS['mad4b_preview_hook'][1] );
$info = $GLOBALS['mad4b_preview_registered']['mad4b/preview-matrix-plan'];
check( $info['category'] === 'mad4b-read', 'category' );
check( $info['meta']['annotations']['readonly'] === true, 'readonly_annotation' );
check( $info['meta']['public'] === false, 'no_public_exposure' );
check( isset( $info['permission_callback'] ), 'permission_guard' );
$plan = MAD4B_SCP_Preview_Matrix::plan( array( 'target_path' => '/', 'mode' => 'all' ) );
check( $plan['state'] === 'ready_for_external_execution_planning', 'read_plan' );
check( count( $plan['lanes'] ) === 3, 'all_lanes' );
check( $plan['remaining_frontend_samples'] === 1, 'sample_gap' );
check( $plan['staging_release_certified'] === false && $plan['mutation_performed'] === false, 'non_authorizing' );
check( $plan['lanes']['native']['equivalent_to_http_timing'] === false, 'no_cli_http_equivalence' );
check( $plan['lanes']['customizer']['equivalent_to_public_frontend'] === false, 'no_preview_public_equivalence' );
$focused = MAD4B_SCP_Preview_Matrix::plan( array( 'target_path' => '/egypt-tours/', 'mode' => 'browser' ) );
check( count( $focused['lanes'] ) === 1 && isset( $focused['lanes']['browser'] ), 'select_lane' );
$invalid = MAD4B_SCP_Preview_Matrix::plan( array( 'target_path' => '/../../etc', 'mode' => 'all' ) );
check( $invalid['state'] === 'blocked', 'traversal_denied' );
$invalid = MAD4B_SCP_Preview_Matrix::plan( array( 'mode' => 'all', 'command' => 'sh' ) );
check( $invalid['state'] === 'blocked', 'arbitrary_execution_denied' );
$GLOBALS['mad4b_preview_environment'] = 'production';
$invalid = MAD4B_SCP_Preview_Matrix::plan();
check( $invalid['state'] === 'blocked' && $invalid['production_mutation'] === false, 'production_denied' );
$GLOBALS['mad4b_preview_environment'] = 'staging';
$GLOBALS['mad4b_preview_profile_drift'] = true;
$invalid = MAD4B_SCP_Preview_Matrix::plan();
check( $invalid['state'] === 'blocked', 'site_profile_drift_denied' );
$GLOBALS['mad4b_preview_profile_drift'] = false;
$GLOBALS['mad4b_preview_origin'] = 'http://staging.allroyalegypt.com';
$invalid = MAD4B_SCP_Preview_Matrix::plan();
check( $invalid['state'] === 'blocked', 'https_required' );
echo 'MAD4B PREVIEW MATRIX PHP RUNTIME: PASS (' . $GLOBALS['mad4b_preview_tests'] . " checks)\n";
