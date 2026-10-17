<?php
/** Hermetic native MCP catalog + governed hybrid fallback, no real network. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function wp_http_validate_url( $url ) { return strpos( $url, 'https://reviewed.example/' ) === 0; }
function mcp_check( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'HYBRID_FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$GLOBALS['mcp_calls'] = array();
$GLOBALS['mcp_revision'] = 'modern';
$GLOBALS['mcp_tools'] = array();
function wp_safe_remote_post( $url, $args ) {
    mcp_check( $url === 'https://reviewed.example/mcp', 'Remote URL must remain operator-pinned' );
    mcp_check( $args['redirection'] === 0 && $args['sslverify'] === true && $args['timeout'] <= 7, 'Safe bounded HTTP enforced' );
    $json = json_decode( $args['body'], true );
    $method = (string) ( $json['method'] ?? '' );
    $revision = $GLOBALS['mcp_revision'];
    $GLOBALS['mcp_calls'][] = $method;
    $session = 'legacy' === $revision ? 'test-session' : '';
    if ( $method === 'server/discover' && 'legacy' === $revision )
        return array( 'status'=>404, 'headers'=>array( 'content-type'=>'application/json' ), 'body'=>'{}' );
    if ( $method === 'server/discover' ) {
        $result = array( 'supportedVersions'=>array('2026-07-28'), 'capabilities'=>array('tools'=>new stdClass()) );
    } elseif ( $method === 'initialize' ) {
        $result = array( 'protocolVersion'=>'2025-11-25', 'capabilities'=>array('tools'=>new stdClass()) );
    } elseif ( $method === 'notifications/initialized' ) {
        return array( 'status'=>202, 'headers'=>array(), 'body'=>'' );
    } elseif ( $method === 'tools/list' ) {
        $params = $json['params'] ?? array();
        if ( ! isset( $params['cursor'] ) ) {
            $result = array( 'tools' => array( $GLOBALS['mcp_tools'][0] ), 'nextCursor'=>'page-2' );
        } else {
            mcp_check( $params['cursor'] === 'page-2', 'Bounded cursor progression' );
            $result = array( 'tools' => array( $GLOBALS['mcp_tools'][1] ) );
        }
    } elseif ( $method === 'tools/call' ) {
        $result = array( 'content' => array( array('type'=>'text', 'text'=>'SAFE_DATA') ), 'isError'=>false );
    } else return new WP_Error( 'unexpected_call', 'unknown method' );
    $wire = array( 'jsonrpc'=>'2.0', 'id'=>$json['id'], 'result'=>$result );
    return array( 'status'=>200, 'headers'=>array('content-type'=>'application/json', 'mcp-session-id'=>$session), 'body'=>json_encode($wire) );
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function mcp_canonical( $v ) {
    if ( ! is_array( $v ) ) return $v;
    if ( array_keys($v) !== range(0,count($v)-1) ) ksort($v,SORT_STRING);
    foreach($v as $key=>$item) $v[$key]=mcp_canonical($item);
    return $v;
}
$schema = array( 'type'=>'object', 'properties'=>array('q'=>array('type'=>'string')) );
$schema_digest = hash( 'sha256', json_encode( mcp_canonical($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$GLOBALS['mcp_tools'] = array(
    array( 'name'=>'read_inspect', 'inputSchema'=>$schema, 'annotations'=>array('readOnlyHint'=>true, 'destructiveHint'=>false) ),
    array( 'name'=>'unsafe_delete', 'inputSchema'=>array('type'=>'object'), 'annotations'=>array('readOnlyHint'=>false, 'destructiveHint'=>true) )
);
$manifest_dir = sys_get_temp_dir() . '/mad4b-mcp-hybrid-' . getmypid();
mcp_check( ! is_dir($manifest_dir) && mkdir($manifest_dir, 0700), 'Unique fixture directory' );
$manifest_path = $manifest_dir . '/reviewed.json';
$manifest = array( 'contract'=>'mad4b.mcp-hybrid.v1', 'peers'=>array( array(
    'id'=>'test-peer', 'endpoint'=>'https://reviewed.example/mcp',
    'bindings'=>array(
        array('ability'=>'demo/inspect', 'tool'=>'read_inspect', 'dimension'=>'read',
            'schema_sha256'=>$schema_digest, 'read_execution_reviewed'=>true),
        array('ability'=>'demo/fallback', 'tool'=>'missing_tool', 'dimension'=>'read',
            'schema_sha256'=>$schema_digest, 'read_execution_reviewed'=>true),
        array('ability'=>'demo/delete', 'tool'=>'unsafe_delete', 'dimension'=>'write',
            'schema_sha256'=>hash('sha256',json_encode(array('type'=>'object')))),
    ),
) ) );
file_put_contents( $manifest_path, json_encode( $manifest ) );
define( 'MAD4B_SCP_MCP_PEER_MANIFEST_PATH', $manifest_path );
define( 'MAD4B_SCP_MCP_PEER_MANIFEST_SHA256', hash_file( 'sha256', $manifest_path ) );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-hybrid.php';
$hybrid = 'MAD4B_SCP_MCP_Hybrid';
$catalog = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error($catalog) && $catalog['protocol']==='2026-07-28' && $catalog['tool_count']===2,
    'Modern server/discover + paginated tools/list operational' );
mcp_check( $catalog['bindings']['demo/inspect']['state'] === 'MCP_SCHEMA_REVIEWED'
    && $catalog['bindings']['demo/fallback']['state'] === 'MCP_TOOL_MISSING',
    'Review exact tool schema and expose per-ability gaps' );
$local = array('demo/fallback'=>array('registered'=>true,'reviewed'=>true,'dimension'=>'read','source'=>'adapter'));
$route = $hybrid::hybrid_plan_from_evidence($catalog,$local,'demo/fallback','read');
mcp_check( $route['selected'] === 'reviewed_adapter' && $route['state'] === 'LOCAL_GOVERNED_ROUTE_AUTHORIZATION_REQUIRED',
    'Missing native tool dynamically falls back to reviewed adapter' );
$route = $hybrid::hybrid_plan_from_evidence($catalog,$local,'demo/inspect','read');
mcp_check( $route['selected'] === 'native_mcp', 'Reviewed native tool preferred when available' );
$route = $hybrid::hybrid_plan_from_evidence($catalog,array(),'demo/delete','write');
mcp_check( $route['selected'] === 'native_mcp' && $route['write_execution_allowed_by_plan'] === false,
    'Write is not executed or authorized by discovery' );
$route = $hybrid::hybrid_plan_from_evidence($catalog,array(),'demo/missing','read');
mcp_check( $route['selected'] === '', 'Unknown tool never auto-enrolled' );
$denied = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>str_repeat('0',64), 'arguments'=>array('q'=>'safe') ));
mcp_check( is_wp_error($denied) && $denied->get_error_code()==='mad4b_native_read_catalog_drift',
    'Stale catalogue snapshot cannot trigger tools/call' );
$read = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256'], 'arguments'=>array('q'=>'safe') ));
mcp_check( ! is_wp_error($read) && $read['result']['content'][0]['text']==='SAFE_DATA',
    'Owner-reviewed read-only tools/call executes with exact fingerprint' );
$blocked = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/delete',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256'] ));
mcp_check( is_wp_error($blocked) && $blocked->get_error_code()==='mad4b_native_read_not_certified',
    'Write-capable native tools cannot run through Read method' );
$GLOBALS['mcp_revision']='legacy';
$legacy = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error($legacy) && $legacy['protocol']==='2025-11-25'
    && in_array('initialize',$GLOBALS['mcp_calls'],true)
    && in_array('notifications/initialized',$GLOBALS['mcp_calls'],true),
    'Legacy initialize/initialized plus tools/list fallback supported' );
unlink($manifest_path);rmdir($manifest_dir);
echo "DYNAMIC_MCP_HYBRID: PASS (modern, legacy, read, denied writes, hybrid fallback)\n";
