<?php
/** Pure contract tests only. No forged MCP transport certification. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-admin-remote-parity.php';
function parity_check( $condition, $why ) { if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $why . PHP_EOL ); exit( 1 ); } }
$x = array(
    'contract'=>'mad4b.admin-remote-read-snapshot.v1',
    'state'=>'Available', 'target_ability'=>'mad4b/plugin-capability-graph',
    'site_sha256'=>str_repeat('a',64), 'wp_actor_sha256'=>str_repeat('b',64),
    'graph_sha256'=>str_repeat('c',64), 'read_result_sha256'=>str_repeat('d',64),
    'read_authorized'=>true, 'route_mounted'=>true, 'actual_ability_executed'=>true,
);
$m = MAD4B_SCP_Admin_Remote_Parity::compare( $x, $x );
parity_check( $m['same_read'] && ! $m['parity_verified'] && ! $m['transport_certified'],
    'Matching untrusted receipts claimed independent MCP certification' );
$y=$x; $y['wp_actor_sha256']=str_repeat('e',64);
parity_check( !MAD4B_SCP_Admin_Remote_Parity::compare($x,$y)['same_read'], 'Actor mismatch accepted' );
$y=$x; $y['graph_sha256']=str_repeat('f',64);
parity_check( !MAD4B_SCP_Admin_Remote_Parity::compare($x,$y)['same_read'], 'Graph drift accepted' );
$y=$x; $y['read_result_sha256']=str_repeat('1',64);
parity_check( !MAD4B_SCP_Admin_Remote_Parity::compare($x,$y)['same_read'], 'Read result drift accepted' );
$y=$x; $y['route_mounted']=false;
parity_check( !MAD4B_SCP_Admin_Remote_Parity::compare($x,$y)['same_read'], 'Unrouted remote result accepted' );
$y=$x; $y['read_authorized']=false;
parity_check( MAD4B_SCP_Admin_Remote_Parity::compare($x,$y)['state']==='Not Authorized', 'Denied remote access was lost' );
parity_check( !MAD4B_SCP_Admin_Remote_Parity::compare($x,array())['same_read'], 'Unvalidated remote data accepted' );
echo "ADMIN_REMOTE_PARITY_CONTRACT: PASS (transport remains unverified)\n";
