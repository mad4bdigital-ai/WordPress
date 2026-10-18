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
$GLOBALS['hybrid_isolation_effective'] = false;
class MAD4B_SCP_MCP_Provider_Isolation {
    public static function effective() { return ! empty( $GLOBALS['hybrid_isolation_effective'] ); }
}
function site_url() { return 'https://reviewed.example'; }
abstract class MAD4B_SCP_Adapter_Base {}
class MAD4B_SCP_Policy { public static function can_read() { return ! empty( $GLOBALS['hybrid_wp_read'] ); } }
class MAD4B_SCP_Identity_Context { public static function current() {
    return array( 'authenticated' => ! empty( $GLOBALS['hybrid_authenticated'] ) );
} }
class MAD4B_SCP_Agent_Registry {
    public static function resolve_agent( $identity ) {
        return ! empty( $identity['authenticated'] ) ? array( 'id' => 17 ) : new WP_Error( 'missing_identity', 'Missing authenticated subject' );
    }
    public static function exact_grant( $agent_id, $server, $ability, $provider = 'core' ) {
        if ( empty( $GLOBALS['hybrid_grant'] ) || 17 !== $agent_id || 'mad4b-read' !== $server
            || ! in_array( $provider, array( 'native_mcp_test-peer', 'hybrid_local' ), true )
            || ! in_array( $ability, array( 'demo/inspect', 'demo/offline' ), true ) )
            return new WP_Error( 'no_exact_grant', 'Exact grant missing' );
        return array( 'effect' => 'allow' );
    }
}
$GLOBALS['hybrid_wp_read'] = true;
$GLOBALS['hybrid_authenticated'] = true;
$GLOBALS['hybrid_grant'] = true;
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
    if ( $method === 'server/discover' && 'bad_request' === $revision )
        return array( 'status'=>400, 'headers'=>array( 'content-type'=>'application/json' ), 'body'=>'{}' );
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
    if ( 'legacy' !== $revision ) $result['resultType'] = 'complete';
    $wire = array( 'jsonrpc'=>'2.0', 'id'=>$json['id'], 'result'=>$result );
    if ( $method === 'tools/call' && ! empty( $GLOBALS['mcp_both_result_error'] ) )
        $wire['error'] = array( 'code'=>-32000, 'message'=>'Contradictory RPC result and error' );
    if ( ! empty($GLOBALS['mcp_sse']) )
        return array( 'status'=>200, 'headers'=>array('content-type'=>'text/event-stream','mcp-session-id'=>$session),
            'body'=>"event: message\ndata: ".json_encode($wire)."\n\n" );
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
$GLOBALS['hybrid_isolation_effective'] = true;
$before_isolation = count($GLOBALS['mcp_calls']);
$isolation_denied = $hybrid::catalog('test-peer');
mcp_check(is_wp_error($isolation_denied)
    && $isolation_denied->get_error_code()==='mad4b_mcp_provider_isolated'
    && count($GLOBALS['mcp_calls'])===$before_isolation,
    'Provider isolation blocks same-origin MCP before any outbound call');
$GLOBALS['hybrid_isolation_effective'] = false;
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
mcp_check( $route['selected'] === '' && ! empty($route['native_write_candidate'])
    && $route['write_execution_allowed_by_plan'] === false,
    'Native Write is discoverable but not dispatchable without a governed executor' );
$route = $hybrid::hybrid_plan_from_evidence($catalog,array(),'demo/missing','read');
mcp_check( $route['selected'] === '', 'Unknown tool never auto-enrolled' );
$denied = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>str_repeat('0',64), 'arguments'=>array('q'=>'safe') ));
mcp_check( is_wp_error($denied) && $denied->get_error_code()==='mad4b_native_read_catalog_drift',
    'Stale catalogue snapshot cannot trigger tools/call' );
$before_denied = count($GLOBALS['mcp_calls']);
$GLOBALS['hybrid_grant'] = false;
$no_grant = $hybrid::read_tool(array('peer_id'=>'test-peer','ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256']));
mcp_check(is_wp_error($no_grant) && $no_grant->get_error_code() === 'mad4b_hybrid_exact_grant_required'
    && count($GLOBALS['mcp_calls']) === $before_denied, 'Missing exact grant denies remote IO before negotiation');
$GLOBALS['hybrid_grant'] = true;
$GLOBALS['hybrid_authenticated'] = false;
$no_agent = $hybrid::read_tool(array('peer_id'=>'test-peer','ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256']));
mcp_check(is_wp_error($no_agent) && $no_agent->get_error_code() === 'mad4b_hybrid_subject_required'
    && count($GLOBALS['mcp_calls']) === $before_denied, 'Missing subject denies peer before negotiation');
$GLOBALS['hybrid_authenticated'] = true;
$read = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256'], 'arguments'=>array('q'=>'safe') ));
mcp_check( ! is_wp_error($read) && $read['result']['content'][0]['text']==='SAFE_DATA',
    'Owner-reviewed read-only tools/call executes with exact fingerprint' );
$GLOBALS['mcp_both_result_error'] = true;
$ambiguous = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256'], 'arguments'=>array('q'=>'safe') ));
mcp_check( is_wp_error($ambiguous) && $ambiguous->get_error_code()==='mad4b_mcp_peer_invalid_rpc',
    'Conflicting JSON-RPC result/error envelope must not pass as a successful read' );
$GLOBALS['mcp_both_result_error'] = false;
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
$before_calls = count($GLOBALS['mcp_calls']);
$candidates = $hybrid::candidate_inventory();
mcp_check( $candidates['pinned_count'] === 1 && $candidates['catalog_protocol_probe_performed'] === false
    && count($GLOBALS['mcp_calls']) === $before_calls,
    'Candidate discovery is independent from network probing' );
$readonly_local = array('demo/offline'=>array('registered'=>true,'reviewed'=>true,'dimension'=>'read','source'=>'adapter'));
$route = $hybrid::hybrid_plan_from_evidence(array('complete'=>false,'bindings'=>array()),$readonly_local,'demo/offline','read');
mcp_check( $route['selected'] === 'reviewed_adapter',
    'Hybrid fallback survives unavailable MCP endpoint' );
$GLOBALS['mcp_sse'] = true;
$GLOBALS['mcp_revision'] = 'modern';
$sse = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error($sse) && $sse['tool_count'] === 2,
    'Bounded Streamable HTTP event-stream responses are decoded' );
$GLOBALS['mcp_sse'] = false;
$GLOBALS['mcp_revision'] = 'bad_request';
$bad = $hybrid::catalog('test-peer');
mcp_check( is_wp_error($bad) && $bad->get_error_code() === 'mad4b_mcp_protocol_uncertain',
    'Ambiguous modern bad-request cannot trigger unsafe legacy downgrade' );
$GLOBALS['mcp_revision'] = 'modern';
$GLOBALS['mcp_tools'][0]['inputSchema']['properties']['q']['type'] = 'integer';
$drift = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error($drift) && $drift['bindings']['demo/inspect']['state'] === 'MCP_SCHEMA_DRIFT',
    'Remote native tool schema drift is detected without execution' );
// Simulate an exact previously source-reviewed local adapter. The real
// runtime uses WP_Ability::execute, which enforces validation and permissions.
final class MAD4B_Test_Hybrid_Adapter extends MAD4B_SCP_Adapter_Base {
    public function ability_names() { return array('read'=>array('demo/offline')); }
}
final class MAD4B_Test_Hybrid_Ability {
    protected $execute_callback;
    public function __construct( $tampered = false ) {
        $this->execute_callback = function( $args ) {
            return array( 'ok' => true, 'permission_checked' => true, 'query' => $args['q'] ?? '' );
        };
    }
    public function get_name() { return 'demo/offline'; }
    public function get_meta() { return array( 'annotations' => array( 'readonly' => true, 'destructive' => false ) ); }
    public function execute( $args ) { return call_user_func( $this->execute_callback, $args ); }
}
final class MAD4B_SCP_Adapter_Registry {
    public static function instance() { return new self(); }
    public function all() { return array( new MAD4B_Test_Hybrid_Adapter() ); }
}
function wp_get_ability( $name ) {
    return 'demo/offline' === $name ? new MAD4B_Test_Hybrid_Ability() : null;
}
$before_local = count($GLOBALS['mcp_calls']);
$hybrid_read = $hybrid::execute_hybrid_read(array('ability'=>'demo/offline',
    'arguments'=>array('q'=>'fallback'), 'peer_id'=>''));
mcp_check(!is_wp_error($hybrid_read)
    && $hybrid_read['selected'] === 'reviewed_adapter'
    && $hybrid_read['result']['permission_checked'] === true
    && count($GLOBALS['mcp_calls']) === $before_local,
    'Local fallback executes via registered Ability with no remote request');
$GLOBALS['hybrid_grant'] = false;
$before_local_denied = count($GLOBALS['mcp_calls']);
$local_denied = $hybrid::execute_hybrid_read(array('ability'=>'demo/offline',
    'arguments'=>array('q'=>'blocked'),'peer_id'=>''));
mcp_check(is_wp_error($local_denied) && $local_denied->get_error_code()==='mad4b_hybrid_exact_grant_required'
    && count($GLOBALS['mcp_calls'])===$before_local_denied,
    'Local fallback cannot bypass exact per-ability Agent grant');
$GLOBALS['hybrid_grant'] = true;
$GLOBALS['mcp_tools'][0]['inputSchema'] = $schema;
$native_denied = $hybrid::execute_hybrid_read(array('ability'=>'demo/inspect',
    'peer_id'=>'test-peer', 'arguments'=>array('q'=>'test')));
mcp_check(is_wp_error($native_denied)
    && $native_denied->get_error_code() === 'mad4b_hybrid_read_snapshot_unacknowledged',
    'Hybrid read does not trigger native call without client-acknowledged catalog hash');
unlink($manifest_path);rmdir($manifest_dir);
echo "DYNAMIC_MCP_HYBRID: PASS (modern, legacy, read, denied writes, hybrid fallback)\n";
