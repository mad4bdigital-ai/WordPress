<?php
/** Hermetic adapter payload constraints; no worker or database is launched. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-assistant-read-work-operations.php';
$GLOBALS['asserts'] = 0;
function check_case( $n, $pass ) { ++$GLOBALS['asserts']; if ( ! $pass ) { fwrite( STDERR, 'FAIL ' . $n . PHP_EOL ); exit( 1 ); } echo 'PASS ' . $n . PHP_EOL; }
$p = array( 'contract' => MAD4B_SCP_Assistant_Read_Work_Operations::CONTRACT,
    'task_id' => 'proposal-' . str_repeat( 'a', 32 ),
    'plan_sha256' => str_repeat( 'b', 64 ), 'binding_sha256' => str_repeat( 'c', 64 ),
    'capabilities' => array( 'seo.rankmath' ), 'provider_ids' => array( 'rankmath' ),
    'purpose' => 'catalog_read' );
check_case( 'read contract', MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $p ) );
$d = MAD4B_SCP_Assistant_Read_Work_Operations::definitions( array() );
check_case( 'three semantic operations', 3 === count( $d ) );
foreach ( $d as $id => $settings ) {
    check_case( 'read-only no credentials ' . $id, true === $settings['read_only']
        && false === $settings['credential_access_allowed'] && false === $settings['plugin_lifecycle_allowed'] );
    check_case( 'production denied ' . $id, 'deny' === $settings['production_policy'] );
    check_case( 'schema applies ' . $id, call_user_func( $settings['validate_payload'], $p ) );
}
check_case( 'existing operation is never overwritten', 'existing' ===
    MAD4B_SCP_Assistant_Read_Work_Operations::definitions( array(
        'assistant_provider_catalog_snapshot' => array( 'marker' => 'existing' ) ) )['assistant_provider_catalog_snapshot']['marker'] );
foreach ( array( 'url', 'sql', 'command', 'php', 'approval', 'install', 'secret' ) as $key ) {
    $bad = $p; $bad[ $key ] = 'unsafe';
    check_case( 'unknown ' . $key . ' rejected', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
}
$bad = $p; $bad['purpose'] = 'plugin_install';
check_case( 'installation purpose refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array( 'seo.rankmath', 'seo.rankmath' );
check_case( 'duplicate capability refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array( new stdClass() );
check_case( 'objects refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['plan_sha256'] = 'invalid';
check_case( 'stale/invalid SHA refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['task_id'] = 'proposal-../../../';
check_case( 'path-like task ID refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['provider_ids'] = array( 'provider/path' );
check_case( 'unsafe provider ID refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['capabilities'] = array();
check_case( 'empty capability target refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
$bad = $p; $bad['contract'] = 'mad4b.fake';
check_case( 'foreign contract refused', ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_payload( $bad ) );
class MAD4B_SCP_Adaptive_Operations_Context {
    public static $current;
    public static function current() { return self::$current; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}
$binding = array( 'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
    'environment' => 'staging', 'profile_digest' => str_repeat( '1', 64 ),
    'origin_sha256' => str_repeat( '2', 64 ), 'runtime_generation' => str_repeat( '3', 64 ),
    'artifact_sha256' => str_repeat( '4', 64 ), 'restore_epoch' => 2,
    'external_record_sha256' => str_repeat( '5', 64 ) );
MAD4B_SCP_Adaptive_Operations_Context::$current = $binding;
$payload = $p;
$payload['binding_sha256'] = hash( 'sha256', serialize( array_values( $binding ) ) );
check_case( 'Staging runtime binding matches', MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
check_case( 'correct purpose matches operation',
    MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( 'assistant_provider_catalog_snapshot', $payload ) );
check_case( 'wrong operation purpose rejected',
    ! MAD4B_SCP_Assistant_Read_Work_Operations::validate_for_operation( 'assistant_configuration_diff', $payload ) );
$stale = $payload; $stale['binding_sha256'] = str_repeat( 'a', 64 );
check_case( 'changed binding rejected', ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $stale ) );
MAD4B_SCP_Adaptive_Operations_Context::$current['environment'] = 'production';
check_case( 'Production never allowed', ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
MAD4B_SCP_Adaptive_Operations_Context::$current = new WP_Error();
check_case( 'Runtime diagnostic error denies enqueue',
    ! MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches( $payload ) );
$queue_code = file_get_contents( dirname( __DIR__ ) . '/includes/class-mad4b-scp-remote-work-queue.php' );
check_case( 'queue gates operation by exact binding', is_string( $queue_code )
    && false !== strpos( $queue_code, 'MAD4B_SCP_Assistant_Read_Work_Operations::runtime_binding_matches' ) );
echo 'ASSISTANT_READ_WORK: PASS ' . $GLOBALS['asserts'] . ' checks' . PHP_EOL;
