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
echo 'ASSISTANT_READ_WORK: PASS ' . $GLOBALS['asserts'] . ' checks' . PHP_EOL;
