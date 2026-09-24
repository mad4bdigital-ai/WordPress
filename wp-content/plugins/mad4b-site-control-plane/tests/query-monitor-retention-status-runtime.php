<?php

$fixture_dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'mad4b-qm-retention-' . getmypid();
if ( ! is_dir( $fixture_dir ) && ! mkdir( $fixture_dir, 0700, true ) ) {
    fwrite( STDERR, "FAIL: unable to create fixture directory\n" );
    exit( 1 );
}

define( 'ABSPATH', '/srv/wordpress/' );
define( 'MAD4B_SCP_DIR', $fixture_dir . DIRECTORY_SEPARATOR );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.59' );
define( 'QM_VERSION', '4.0.7' );

$build = str_repeat( 'b', 64 );
file_put_contents(
    MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json',
    json_encode( array( 'build_fingerprint' => $build ) )
);

$GLOBALS['mad4b_test_options'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['mad4b_test_options'][ $key ] = $value; return true; }

final class MAD4B_SCP_Site_Profile {
    public static function nonproduction_governed() { return true; }
    public static function site_urls_match_enrollment() { return true; }
    public static function acceptance_enabled() { return true; }
}

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-live-acceptance-observer.php';

$check = function ( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
};

$reset_telemetry_cache = function () {
    $reflection = new ReflectionClass( 'MAD4B_SCP_Live_Acceptance_Observer' );
    $property = $reflection->getProperty( 'telemetry' );
    $property->setAccessible( true );
    $property->setValue( null, null );
};

$event = function ( $classification, $type ) use ( $build ) {
    return array(
        'type' => $type,
        'classification' => $classification,
        'severity' => 'mad4b' === $classification ? 'blocking_regression' : 'third_party_non_blocking',
        'function' => 'fixture',
        'message' => 'fixture',
        'component' => 'mad4b' === $classification ? 'mad4b-site-control-plane' : 'fluentform',
        'plugin_slug' => 'mad4b' === $classification ? 'mad4b-site-control-plane' : 'fluentform',
        'lifecycle_phase' => 'post_init',
        'request_class' => 'frontend',
        'observed_at' => gmdate( 'Y-m-d H:i:s' ),
        'build_fingerprint' => $build,
        'callers' => array(),
    );
};

$base = array(
    'contract' => MAD4B_SCP_Live_Acceptance_Observer::QUERY_MONITOR_CONTRACT,
    'control_plane_version' => MAD4B_SCP_VERSION,
    'build_fingerprint' => $build,
    'capture_started_at' => gmdate( 'Y-m-d H:i:s' ),
    'last_observed_at' => gmdate( 'Y-m-d H:i:s' ),
    'observed_request_count' => 4,
    'request_coverage' => array( 'mcp' => 1, 'rest' => 1, 'wp_admin' => 1, 'frontend' => 1 ),
    'counters' => array(
        'mad4b' => array(
            'doing_it_wrong' => 1,
            'deprecated_function' => 0,
            'deprecated_argument' => 0,
            'deprecated_hook' => 0,
            'deprecated_class' => 0,
            'ability_not_found' => 0,
            'wp_get_ability_missing' => 0,
            'pre_init_abilities_violation' => 0,
        ),
        'third_party' => array( 'doing_it_wrong' => 40, 'fluentform_action_scheduler' => 40 ),
        'wordpress_core' => array(),
        'unknown' => array(),
    ),
    'events' => array(),
    'events_by_bucket' => array(
        'mad4b' => array( $event( 'mad4b', 'doing_it_wrong' ) ),
        'third_party' => array(),
        'wordpress_core' => array(),
        'unknown' => array(),
    ),
    'performance' => array(),
);

for ( $i = 0; $i < 32; $i++ ) {
    $base['events'][] = $event( 'third_party', 'doing_it_wrong' );
    $base['events_by_bucket']['third_party'][] = $event( 'third_party', 'doing_it_wrong' );
}

$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ] = $base;
$status = MAD4B_SCP_Live_Acceptance_Observer::query_monitor_status();

$check( true === $status['evidence']['fresh'], 'new-schema telemetry must be fresh' );
$check( true === $status['evidence']['current_build_match'], 'new-schema telemetry must match current build' );
$check( 1 === count( $status['mad4b_events'] ), 'MAD4B retained event must be projected even after global eviction' );
$check( 'mad4b.query-monitor-event-retention.v1' === $status['event_retention']['contract'], 'retention contract drifted' );
$check( true === $status['event_retention']['events_by_bucket_available'], 'new-schema bucket availability must be true' );
$check( 1 === $status['event_retention']['mad4b_doing_it_wrong_counter_count'], 'counter projection drifted' );
$check( 1 === $status['event_retention']['mad4b_doing_it_wrong_retained_count'], 'retained count projection drifted' );
$check( 0 === $status['event_retention']['mad4b_doing_it_wrong_detail_gap_count'], 'new-schema detail gap must be zero' );
$check( true === $status['event_retention']['mad4b_doing_it_wrong_details_complete'], 'new-schema details must be complete' );
$check( false === $status['ready'] && 'mad4b_regression_observed' === $status['state'], 'retention must not weaken readiness semantics' );

$legacy = $base;
unset( $legacy['events_by_bucket'] );
$legacy['counters']['mad4b']['doing_it_wrong'] = 5;
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ] = $legacy;
$reset_telemetry_cache();
$status = MAD4B_SCP_Live_Acceptance_Observer::query_monitor_status();

$check( false === $status['event_retention']['events_by_bucket_available'], 'legacy telemetry must report bucket retention unavailable' );
$check( 0 === count( $status['mad4b_events'] ), 'legacy global ring fixture contains no recoverable MAD4B detail' );
$check( 5 === $status['event_retention']['mad4b_doing_it_wrong_counter_count'], 'legacy counter must remain visible' );
$check( 0 === $status['event_retention']['mad4b_doing_it_wrong_retained_count'], 'legacy retained count must reflect missing detail' );
$check( 5 === $status['event_retention']['mad4b_doing_it_wrong_detail_gap_count'], 'legacy detail gap must be explicit' );
$check( false === $status['event_retention']['mad4b_doing_it_wrong_details_complete'], 'legacy detail completeness must be false' );

$clean = $base;
$clean['counters']['mad4b']['doing_it_wrong'] = 0;
$clean['events_by_bucket']['mad4b'] = array();
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Live_Acceptance_Observer::TELEMETRY_OPTION ] = $clean;
$reset_telemetry_cache();
$status = MAD4B_SCP_Live_Acceptance_Observer::query_monitor_status();

$check( true === $status['ready'], 'clean fresh telemetry must remain ready' );
$check( 'ready' === $status['state'], 'clean fresh telemetry state must remain ready' );
$check( 0 === $status['event_retention']['mad4b_doing_it_wrong_detail_gap_count'], 'clean telemetry detail gap must remain zero' );

@unlink( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json' );
@rmdir( MAD4B_SCP_DIR );

echo "mad4b.query-monitor-retention-status.runtime.v1: PASS\n";
