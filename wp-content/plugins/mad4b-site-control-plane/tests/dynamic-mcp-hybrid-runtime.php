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
function rest_validate_value_from_schema( $value, $schema, $key = '' ) {
    if ( ! is_array( $schema ) || ! is_array( $value ) ) return new WP_Error('bad_schema','Invalid structure');
    if ( ( $schema['type'] ?? '' ) !== 'object' ) return new WP_Error('schema_not_object','Invalid object schema');
    foreach ( $schema['required'] ?? array() as $required ) {
        if ( ! array_key_exists( $required, $value ) ) return new WP_Error('required_missing','Required property missing');
    }
    foreach ( $schema['properties'] ?? array() as $name => $rule ) {
        if ( ! array_key_exists( $name, $value ) ) continue;
        if ( ( $rule['type'] ?? '' ) === 'string' && ! is_string($value[$name]) ) return new WP_Error('bad_type','String expected');
        if ( ( $rule['type'] ?? '' ) === 'integer' && ! is_int($value[$name]) ) return new WP_Error('bad_type','Integer expected');
    }
    return true;
}

function wp_http_validate_url( $url ) { return strpos( $url, 'https://reviewed.example/' ) === 0; }
$GLOBALS['mcp_auth_mode'] = 'valid';
function ci_mcp_test_credential_resolver( $peer_id, $audience, $required_scopes ) {
    if ( 'test-peer' !== $peer_id ) throw new RuntimeException( 'Unknown peer' );
    $mode = $GLOBALS['mcp_auth_mode'];
    return array( 'access_token'=>'TEST_REDACTED_TOKEN',
        'expires_at' => time() + ( 'expired' === $mode ? -20 : 3600 ),
        'audience' => 'wrong-audience' === $mode ? 'https://different.example' : $audience,
        'scopes' => 'missing-scope' === $mode ? array() : array( 'tools.read' ) );
}
define( 'MAD4B_SCP_MCP_PEER_AUTH_RESOLVER', 'ci_mcp_test_credential_resolver' );
$GLOBALS['mcp_transients'] = array();
function get_transient( $key ) { return $GLOBALS['mcp_transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) {
    $GLOBALS['mcp_transients'][$key] = $value;
    return true;
}
function delete_transient( $key ) { unset( $GLOBALS['mcp_transients'][$key] ); return true; }
$GLOBALS['mcp_forced_transport_failures'] = 0;
$GLOBALS['mcp_forced_call_failures'] = 0;
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
            || ! in_array( $ability, array( 'demo/inspect', 'demo/offline', 'demo/native-local', 'mad4b/native-mcp-catalog' ), true ) )
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
    mcp_check( ( $args['headers']['Authorization'] ?? '' ) === 'Bearer TEST_REDACTED_TOKEN',
        'Only operator-resolved scoped expiring provider bearer is sent to pinned HTTPS origin' );
    $json = json_decode( $args['body'], true );
    $method = (string) ( $json['method'] ?? '' );
    $revision = $GLOBALS['mcp_revision'];
    $GLOBALS['mcp_calls'][] = $method;
    if ( 'tools/call' === $method && $GLOBALS['mcp_forced_call_failures'] > 0 ) {
        --$GLOBALS['mcp_forced_call_failures'];
        return new WP_Error( 'mock_read_outage', 'Simulated tool-call-only transport outage' );
    }
    if ( $GLOBALS['mcp_forced_transport_failures'] > 0 ) {
        --$GLOBALS['mcp_forced_transport_failures'];
        return new WP_Error( 'mock_network_down', 'Simulated transport outage' );
    }
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
        if ( $revision === 'modern' && $GLOBALS['mcp_forced_call_failures'] === 0 ) {
            $args_value = $json['params']['arguments']['q'] ?? null;
            $expected_header = is_string( $args_value ) ? $args_value : '';
            mcp_check( ( $args['headers']['Mcp-Param-Query'] ?? '' ) === $expected_header,
                'SEP-2243 custom Mcp-Param-Query header matches reviewed input schema value' );
        }
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
$schema = array( 'type'=>'object', 'properties'=>array('q'=>array('type'=>'string','x-mcp-header'=>'Query')) );
$schema_digest = hash( 'sha256', json_encode( mcp_canonical($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$GLOBALS['mcp_tools'] = array(
    array( 'name'=>'read_inspect', 'inputSchema'=>$schema, 'annotations'=>array('readOnlyHint'=>true, 'destructiveHint'=>false) ),
    array( 'name'=>'unsafe_delete', 'inputSchema'=>array('type'=>'object'), 'annotations'=>array('readOnlyHint'=>false, 'destructiveHint'=>true) )
);
$manifest_dir = sys_get_temp_dir() . '/mad4b-mcp-hybrid-' . getmypid();
mcp_check( ! is_dir($manifest_dir) && mkdir($manifest_dir, 0700), 'Unique fixture directory' );
$local_plugins = $manifest_dir . '/plugins';
mcp_check( mkdir( $local_plugins . '/demo', 0700, true ), 'Local WordPress Ability fixture plugin created' );
define( 'WP_PLUGIN_DIR', $local_plugins );
function get_option( $key, $fallback = array() ) {
    return 'active_plugins' === $key ? array( 'demo/main.php' ) : $fallback;
}
$entry_php = '<?php // test plugin package';
$reader_php = '<?php function ci_native_wp_read( $args ) { return array("ok" => true, "checked" => true, "q" => $args["q"] ?? ""); }';
file_put_contents( $local_plugins . '/demo/main.php', $entry_php );
file_put_contents( $local_plugins . '/demo/reader.php', $reader_php );
require_once $local_plugins . '/demo/reader.php';
$native_local_input = $schema;
$native_local_output = array( 'type' => 'object' );
$native_local_tree = array(
    'main.php' => hash( 'sha256', $entry_php ),
    'reader.php' => hash( 'sha256', $reader_php )
);
$manifest_path = $manifest_dir . '/reviewed.json';
$manifest = array( 'contract'=>'mad4b.mcp-hybrid.v1', 'peers'=>array( array(
    'id'=>'test-peer', 'endpoint'=>'https://reviewed.example/mcp',
    'audience'=>'https://reviewed.example', 'required_scopes'=>array('tools.read'),
    'bindings'=>array(
        array('ability'=>'demo/inspect', 'tool'=>'read_inspect', 'dimension'=>'read',
            'schema_sha256'=>$schema_digest, 'read_execution_reviewed'=>true),
        array('ability'=>'demo/fallback', 'tool'=>'missing_tool', 'dimension'=>'read',
            'schema_sha256'=>$schema_digest, 'read_execution_reviewed'=>true),
        array('ability'=>'demo/delete', 'tool'=>'unsafe_delete', 'dimension'=>'write',
            'schema_sha256'=>hash('sha256',json_encode(array('type'=>'object')))),
    ),
) ), 'local_abilities'=>array( array(
    'ability'=>'demo/native-local',
    'plugin_basename'=>'demo/main.php',
    'callback_source_relpath'=>'demo/reader.php',
    'callback_source_sha256'=>hash( 'sha256', $reader_php ),
    'php_source_tree_sha256'=>hash( 'sha256', json_encode( $native_local_tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
    'input_schema_sha256'=>hash( 'sha256', json_encode( mcp_canonical($native_local_input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
    'output_schema_sha256'=>hash( 'sha256', json_encode( mcp_canonical($native_local_output), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
    'read_execution_reviewed'=>true
) ) );
file_put_contents( $manifest_path, json_encode( $manifest ) );
define( 'MAD4B_SCP_MCP_PEER_MANIFEST_PATH', $manifest_path );
define( 'MAD4B_SCP_MCP_PEER_MANIFEST_SHA256', hash_file( 'sha256', $manifest_path ) );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-hybrid.php';
function mcp_new_request_epoch() {
    $cache = new ReflectionProperty( 'MAD4B_SCP_MCP_Hybrid', 'request_catalogs' );
    $cache->setAccessible( true );
    $cache->setValue( null, array() );
}
$mirrored = MAD4B_SCP_MCP_Header_Parameters::from_schema( $schema, array('q'=>"Hello, 世界") );
mcp_check( ! is_wp_error($mirrored) && $mirrored['Mcp-Param-Query'] ===
    '=?base64?' . base64_encode("Hello, 世界") . '?=',
    'Non-ASCII MCP routing headers use the exact Base64 sentinel' );
$invalid_header_schema = array('type'=>'object','properties'=>array(
    'q'=>array('type'=>'number','x-mcp-header'=>'Unsafe')
));
mcp_check( is_wp_error(MAD4B_SCP_MCP_Header_Parameters::definition($invalid_header_schema)),
    'Floating point and invalid custom header annotations are rejected' );
$duplicate_header_schema = array('type'=>'object','properties'=>array(
    'x'=>array('type'=>'string','x-mcp-header'=>'Tenant'),
    'y'=>array('type'=>'string','x-mcp-header'=>'tenant')
));
mcp_check( is_wp_error(MAD4B_SCP_MCP_Header_Parameters::definition($duplicate_header_schema)),
    'Case-insensitive duplicate mirrored headers are rejected' );
$unreachable_header_schema = array('type'=>'object','properties'=>array(
    'x'=>array('type'=>'array','items'=>array('type'=>'string','x-mcp-header'=>'Region'))
));
mcp_check( is_wp_error(MAD4B_SCP_MCP_Header_Parameters::definition($unreachable_header_schema)),
    'Annotations inside items/ref/composition are not valid statically reachable parameters' );
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
$first_roundtrips = count($GLOBALS['mcp_calls']);
$again = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error( $again ) && count($GLOBALS['mcp_calls']) === $first_roundtrips
    && $again['capability_snapshot_sha256'] === $catalog['capability_snapshot_sha256'],
    'Request-local catalog caching avoids redundant native negotiations' );
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
$before_invalid_args = count($GLOBALS['mcp_calls']);
$invalid_args = $hybrid::read_tool(array( 'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$catalog['capability_snapshot_sha256'], 'arguments'=>array('q'=>42) ));
mcp_check(is_wp_error($invalid_args) && $invalid_args->get_error_code()==='mad4b_native_arguments_invalid'
    && ! in_array('tools/call',array_slice($GLOBALS['mcp_calls'],$before_invalid_args),true),
    'Input Schema validation refuses invalid arguments before MCP tools/call');
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
mcp_new_request_epoch();
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
mcp_new_request_epoch();
$GLOBALS['mcp_revision'] = 'modern';
$sse = $hybrid::catalog('test-peer');
mcp_check( ! is_wp_error($sse) && $sse['tool_count'] === 2,
    'Bounded Streamable HTTP event-stream responses are decoded' );
$GLOBALS['mcp_sse'] = false;
$GLOBALS['mcp_revision'] = 'bad_request';
mcp_new_request_epoch();
$bad = $hybrid::catalog('test-peer');
mcp_check( is_wp_error($bad) && $bad->get_error_code() === 'mad4b_mcp_protocol_uncertain',
    'Ambiguous modern bad-request cannot trigger unsafe legacy downgrade' );
$GLOBALS['mcp_revision'] = 'modern';
$GLOBALS['mcp_tools'][0]['inputSchema']['properties']['q']['type'] = 'integer';
mcp_new_request_epoch();
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
final class MAD4B_Test_Native_WordPress_Ability {
    protected $execute_callback = 'ci_native_wp_read';
    public function get_name() { return 'demo/native-local'; }
    public function get_meta() { return array( 'annotations'=>array( 'readonly'=>true, 'destructive'=>false ) ); }
    public function get_input_schema() { return $GLOBALS['native_local_input']; }
    public function get_output_schema() { return array( 'type'=>'object' ); }
    public function execute( $args ) { return call_user_func( $this->execute_callback, $args ); }
}
$GLOBALS['native_local_input'] = $native_local_input;
final class MAD4B_SCP_Adapter_Registry {
    public static function instance() { return new self(); }
    public function all() { return array( new MAD4B_Test_Hybrid_Adapter() ); }
}
function wp_get_ability( $name ) {
    if ( 'demo/native-local' === $name ) return new MAD4B_Test_Native_WordPress_Ability();
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
$native_local_plan = $hybrid::hybrid_plan(array('ability'=>'demo/native-local','dimension'=>'read','peer_id'=>''));
mcp_check( ! is_wp_error($native_local_plan) && $native_local_plan['selected'] === 'wordpress_ability',
    'Native WordPress Ability receives an independent reviewed local route without a vendor PHP Adapter' );
$native_local = $hybrid::execute_hybrid_read(array(
    'ability'=>'demo/native-local', 'arguments'=>array('q'=>'native'), 'peer_id'=>''
));
mcp_check( ! is_wp_error( $native_local ) && $native_local['selected'] === 'wordpress_ability'
    && $native_local['result']['checked'] === true,
    'Native WordPress Ability executes only after operator source, schema and exact Agent-grant attestation' );
$changed_source = $local_plugins . '/demo/reader.php';
file_put_contents( $changed_source, $reader_php . "\n// changed file" );
mcp_check( ! MAD4B_SCP_MCP_Local_Ability_Trust::verified_read(
    wp_get_ability('demo/native-local'), 'demo/native-local' ),
    'Source drift prevents a native WordPress Ability fallback even when its semantic name remains stable' );
file_put_contents( $changed_source, $reader_php );
$trusted = new MAD4B_Test_Hybrid_Ability();
$spoofed = new MAD4B_Test_Hybrid_Ability();
$callback_ref = new ReflectionProperty( $spoofed, 'execute_callback' );
$callback_ref->setAccessible( true );
$callback_ref->setValue( $spoofed, 'strlen' );
mcp_check( MAD4B_SCP_MCP_Hybrid_Authority::verified_local_read(
    $trusted, new MAD4B_Test_Hybrid_Adapter(), 'demo/offline' ),
    'Valid callback source from reviewed Adapter can be attested' );
mcp_check( ! MAD4B_SCP_MCP_Hybrid_Authority::verified_local_read(
    $spoofed, new MAD4B_Test_Hybrid_Adapter(), 'demo/offline' ),
    'Foreign eval-backed WordPress Ability callback cannot impersonate reviewed Adapter' );
$GLOBALS['hybrid_grant'] = false;
$before_local_denied = count($GLOBALS['mcp_calls']);
$local_denied = $hybrid::execute_hybrid_read(array('ability'=>'demo/offline',
    'arguments'=>array('q'=>'blocked'),'peer_id'=>''));
mcp_check(is_wp_error($local_denied) && $local_denied->get_error_code()==='mad4b_hybrid_exact_grant_required'
    && count($GLOBALS['mcp_calls'])===$before_local_denied,
    'Local fallback cannot bypass exact per-ability Agent grant');
$GLOBALS['hybrid_grant'] = true;
$GLOBALS['mcp_tools'][0]['inputSchema'] = $schema;
mcp_new_request_epoch();
$native_denied = $hybrid::execute_hybrid_read(array('ability'=>'demo/inspect',
    'peer_id'=>'test-peer', 'arguments'=>array('q'=>'test')));
mcp_check(is_wp_error($native_denied)
    && $native_denied->get_error_code() === 'mad4b_hybrid_read_snapshot_unacknowledged',
    'Hybrid read does not trigger native call without client-acknowledged catalog hash');
mcp_new_request_epoch();
$GLOBALS['hybrid_grant'] = false;
$before_catalog_denial = count($GLOBALS['mcp_calls']);
$catalog_denied = $hybrid::catalog('test-peer');
mcp_check( is_wp_error($catalog_denied) && $catalog_denied->get_error_code() === 'mad4b_hybrid_exact_grant_required'
    && count($GLOBALS['mcp_calls']) === $before_catalog_denial,
    'Standalone MCP catalog requires an exact per-peer Agent grant BEFORE network IO' );
$plan_denied = $hybrid::hybrid_plan(array('peer_id'=>'test-peer',
    'ability'=>'demo/inspect', 'dimension'=>'read'));
mcp_check( ! is_wp_error($plan_denied)
    && $plan_denied['selected'] === ''
    && $plan_denied['native_unavailable_reason'] === 'mad4b_hybrid_exact_grant_required'
    && count($GLOBALS['mcp_calls']) === $before_catalog_denial,
    'Hybrid planning cannot probe pinned remote provider without exact per-tool Grant' );
$GLOBALS['hybrid_grant'] = true;
$before_auth_rejection = count( $GLOBALS['mcp_calls'] );
foreach ( array( 'expired' => 'mad4b_mcp_credential_invalid',
    'wrong-audience' => 'mad4b_mcp_credential_invalid',
    'missing-scope' => 'mad4b_mcp_credential_scope_missing' ) as $mode => $error_code ) {
    $GLOBALS['mcp_auth_mode'] = $mode;
    mcp_new_request_epoch();
    $auth_denied = $hybrid::catalog( 'test-peer' );
    mcp_check( is_wp_error( $auth_denied ) && $auth_denied->get_error_code() === $error_code
        && count($GLOBALS['mcp_calls']) === $before_auth_rejection,
        'Expired, mismatched-audience or insufficient-scope credentials deny all provider IO' );
}
$GLOBALS['mcp_auth_mode'] = 'valid';
mcp_new_request_epoch();
// Repeated peer transport failures trip a bounded circuit. The native
// transport is skipped while open, but the separately reviewed local
// route remains selectable without any extra outbound request.
mcp_new_request_epoch();
$GLOBALS['mcp_forced_transport_failures'] = 3;
for ( $attempt = 0; $attempt < 3; ++$attempt ) {
    $failed = $hybrid::catalog( 'test-peer' );
    mcp_check( is_wp_error( $failed ), 'Unavailable native peer must not return a false catalog' );
}
$breaker_status = $hybrid::candidate_inventory();
mcp_check( $breaker_status['peer_transport_health']['test-peer']['state'] === 'OPEN',
    'Three transport failures open only the affected pinned peer circuit' );
$before_open_call = count( $GLOBALS['mcp_calls'] );
$open = $hybrid::catalog( 'test-peer' );
mcp_check( is_wp_error( $open ) && count( $GLOBALS['mcp_calls'] ) === $before_open_call,
    'Open circuit prevents external HTTP rather than repeatedly hammering provider' );
$local_during_outage = $hybrid::hybrid_plan_from_evidence(
    array('complete'=>false,'bindings'=>array()),
    array('demo/offline'=>array('registered'=>true,'reviewed'=>true,'dimension'=>'read','source'=>'adapter')),
    'demo/offline', 'read'
);
mcp_check( $local_during_outage['selected'] === 'reviewed_adapter',
    'Approved local fallback survives native transport outage' );
// Simulate expiry of the bounded breaker window, not an actual clock wait.
foreach ( $GLOBALS['mcp_transients'] as $key => $status ) {
    $GLOBALS['mcp_transients'][$key]['open_until'] = time() - 1;
    $GLOBALS['mcp_transients'][$key]['started'] = time() - 200;
}
mcp_new_request_epoch();
$recovered = $hybrid::catalog( 'test-peer' );
mcp_check( ! is_wp_error( $recovered )
    && $hybrid::candidate_inventory()['peer_transport_health']['test-peer']['state'] === 'CLOSED',
    'Healthy provider recovers after breaker cooldown without persistent credential changes' );
unlink( $local_plugins . '/demo/reader.php' );
unlink( $local_plugins . '/demo/main.php' );
rmdir( $local_plugins . '/demo' );
rmdir( $local_plugins );
mcp_new_request_epoch();
$GLOBALS['mcp_forced_call_failures'] = 3;
for ( $trial = 0; $trial < 3; ++$trial ) {
    $fresh = $hybrid::catalog( 'test-peer' );
    mcp_check( ! is_wp_error($fresh), 'Healthy tool listing is still available during failing tool calls' );
    $failed_call = $hybrid::read_tool( array(
        'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
        'expected_catalog_sha256'=>$fresh['capability_snapshot_sha256'],
        'arguments'=>array('q'=>'simulated') ) );
    mcp_check( is_wp_error( $failed_call ), 'Native tool-call outage must be surfaced' );
}
$separate = $hybrid::candidate_inventory()['peer_transport_health']['test-peer'];
mcp_check( $separate['catalog']['state'] === 'CLOSED' && $separate['read']['state'] === 'OPEN',
    'Successful tools/list cannot reset repeated tools/call failures' );
$count_before_read_circuit = count( array_filter( $GLOBALS['mcp_calls'],
    static function ( $method ) { return 'tools/call' === $method; } ) );
$blocked_call = $hybrid::read_tool( array(
    'peer_id'=>'test-peer', 'ability'=>'demo/inspect',
    'expected_catalog_sha256'=>$fresh['capability_snapshot_sha256'],
    'arguments'=>array('q'=>'blocked') ) );
$count_after_read_circuit = count( array_filter( $GLOBALS['mcp_calls'],
    static function ( $method ) { return 'tools/call' === $method; } ) );
mcp_check( is_wp_error($blocked_call)
    && $blocked_call->get_error_code() === 'mad4b_mcp_peer_circuit_open'
    && $count_before_read_circuit === $count_after_read_circuit,
    'Read call circuit blocks new tools/call before outbound IO' );
unlink($manifest_path);rmdir($manifest_dir);
echo "DYNAMIC_MCP_HYBRID: PASS (modern, legacy, read, denied writes, hybrid fallback)\n";
