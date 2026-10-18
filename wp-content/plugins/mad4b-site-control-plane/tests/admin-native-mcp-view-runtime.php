<?php
/* Hermetic, data-only admin projection regression: no WordPress bootstrap/HTTP. */
define( 'ABSPATH', '/tmp/mad4b-admin-native-mcp-fixture/' );
require_once __DIR__ . '/../includes/class-mad4b-scp-admin-native-mcp-view.php';
function check_view( $truth, $why ) { if ( ! $truth ) { fwrite( STDERR, $why . "\n" ); exit( 1 ); } }
$graph = array( 'state'=>'DISCOVERY_COMPLETE', 'complete'=>true, 'graph_sha256'=>str_repeat('a',64),
    'items'=>array( array( 'plugin_file'=>'safe/plugin.php', 'version'=>'2.1', 'active'=>true,
        'registered_adapter_read_abilities'=>array('safe/read'),
        'registered_adapter_write_abilities'=>array('safe/write'),
        'provider_certification_ok'=>false, 'unreviewed_namespace_candidates_count'=>3 ) ) );
$model = MAD4B_SCP_Admin_Native_MCP_View::graph_model( $graph );
check_view( $model['rows'][0]['registered_read_count'] === 1, 'read registry count drift' );
check_view( $model['rows'][0]['registered_write_count'] === 1 && ! $model['rows'][0]['certified_provider'], 'registered write falsely certified' );
check_view( $model['rows'][0]['unreviewed_count'] === 3 && ! $model['executing'] && ! $model['network_probed'], 'graph view improperly authorizing' );
check_view( MAD4B_SCP_Admin_Native_MCP_View::graph_model( array('complete'=>false,'items'=>array()), 0 )['complete'] === false, 'partial inventory upgraded' );
$peers = MAD4B_SCP_Admin_Native_MCP_View::peer_model( array(
    'pinned_peer_ids'=>array('peer_good','../../unsafe','peer_second'),
    'peer_transport_health'=>array(
      'peer_good'=>array( 'catalog'=>array('state'=>'OPEN'), 'read'=>array('state'=>'CLOSED'), 'retry_after_seconds'=>20, 'token'=>'sensitive' ),
      'peer_second'=>array('catalog'=>array('state'=>'CLOSED'), 'read'=>array('state'=>'OPEN')) ),
    'oauth_token'=>'secret', 'unexpected_provider_response'=>'secret' ),
    array('state'=>'OWNER_PIN_VERIFIED','validated_source_pin_count'=>2,'private_key'=>'secret') );
check_view( count($peers['rows']) === 2, 'unsafe peer identifier included' );
check_view( $peers['rows'][0]['catalog_state'] === 'OPEN' && $peers['rows'][0]['read_state'] === 'CLOSED', 'catalog/read breaker not separated' );
check_view( $peers['external_live_discovery'] === false && $peers['oauth_checked'] === false && $peers['write_certified'] === false, 'synthetic live certification' );
check_view( false === strpos( json_encode($peers), 'secret' ), 'peer status leaked credentials' );
$valid_catalog = MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'peer_good','mode'=>'catalog') );
check_view( $valid_catalog['mode'] === 'catalog', 'explicit catalog diagnostic rejected' );
$valid_read = MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'peer_good','mode'=>'read','ability'=>'safe/read','expected_catalog_sha256'=>str_repeat('b',64)) );
check_view( $valid_read['mode'] === 'read' && $valid_read['ability'] === 'safe/read', 'exact read canary request rejected' );
check_view( null === MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'../unsafe','mode'=>'catalog') ), 'unsafe peer accepted' );
check_view( null === MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'peer_good','mode'=>'write') ), 'write diagnostic accepted' );
check_view( null === MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'peer_good','mode'=>'read','ability'=>'safe/read','expected_catalog_sha256'=>'stale') ), 'stale catalog accepted' );
check_view( null === MAD4B_SCP_Admin_Native_MCP_View::diagnostic_request( array('peer_id'=>'peer_good','mode'=>'catalog','ability'=>'safe/read') ), 'catalog mode smuggled read' );
check_view( MAD4B_SCP_Admin_Native_MCP_View::state_for_diagnostic_error('mad4b_hybrid_exact_grant_required') === 'Not Authorized', 'Per-agent grant denial presented as a generic block' );
check_view( MAD4B_SCP_Admin_Native_MCP_View::state_for_diagnostic_error('mad4b_native_read_not_certified') === 'Needs Review', 'Unreviewed read did not request review' );
check_view( MAD4B_SCP_Admin_Native_MCP_View::state_for_diagnostic_error('mad4b_mcp_peer_circuit_open') === 'Blocked', 'Circuit breaker denial not blocked' );
echo "Admin Native MCP view projection: PASS\n";
