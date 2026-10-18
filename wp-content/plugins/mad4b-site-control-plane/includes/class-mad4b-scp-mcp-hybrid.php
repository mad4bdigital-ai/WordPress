<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-mcp-hybrid-authority.php';

/**
 * Owner-pinned native MCP discovery and hybrid routing.
 *
 * Does not derive an endpoint, permission, write contract, or secret from a
 * provider's self-reported tool listing. Reads only exact operator-pinned HTTPS
 * peers; unknown/missing tools fall back per capability to an already-registered
 * WordPress Ability or source-reviewed adapter.
 */
final class MAD4B_SCP_MCP_Hybrid {
    const CONTRACT = 'mad4b.mcp-hybrid.v1';
    const MAX_PEERS = 24;
    const MAX_TOOLS = 256;
    const MAX_PAGES = 4;
    const MAX_BYTES = 262144;
    private static $manifest = null;
    private static $rpc_started_at = null;
    private static $rpc_sequence = 0;
    private static $rpc_budget_seconds = 6.0;
    private static $request_catalogs = array();
    const REQUEST_CATALOG_MAX_AGE_SECONDS = 1.0;
    public static function boot() {
        if ( function_exists( 'add_action' ) )
            add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 33 );
    }
    private static function input_schema( $kind ) {
        $properties = array(
            'peer_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
            'ability' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
            'dimension' => array( 'type' => 'string', 'enum' => array( 'read', 'write' ) ),
            'expected_catalog_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
            'arguments' => array( 'type' => 'object', 'additionalProperties' => true ),
        );
        $required = 'candidates' === $kind ? array() : array( 'peer_id' );
        if ( 'plan' === $kind ) $required = array( 'ability' );
        if ( 'call' === $kind ) $required = array( 'peer_id', 'ability', 'expected_catalog_sha256' );
        if ( 'hybrid_read' === $kind ) $required = array( 'ability' );
        return array( 'type' => 'object', 'properties' => $properties, 'required' => $required,
            'additionalProperties' => false );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $definitions = array(
            'mad4b/native-mcp-candidates' => array( 'candidate_inventory', 'Observe local provider MCP route candidates without credentials, HTTP requests or auto-admission.' ),
            'mad4b/native-mcp-catalog' => array( 'read_catalog', 'Observe an exact owner-pinned native MCP peer tools/list catalog.' ),
            'mad4b/native-mcp-hybrid-plan' => array( 'hybrid_plan', 'Plan native MCP versus reviewed WordPress Ability or PHP Adapter per capability.' ),
            'mad4b/native-mcp-read-call' => array( 'read_tool', 'Call a reviewed native MCP read tool with an exact catalog fingerprint.' ),
            'mad4b/native-mcp-hybrid-read' => array( 'execute_hybrid_read', 'Execute a reviewed native MCP read or a separately reviewed registered WordPress read Ability, without mutation fallback retries.' ),
        );
        foreach ( $definitions as $name => $details ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            $kind = 'mad4b/native-mcp-candidates' === $name ? 'candidates'
                : ( 'mad4b/native-mcp-catalog' === $name ? 'catalog'
                    : ( 'mad4b/native-mcp-hybrid-plan' === $name ? 'plan' :
                        ( 'mad4b/native-mcp-hybrid-read' === $name ? 'hybrid_read' : 'call' ) ) );
            wp_register_ability( $name, array(
                'label' => ucwords( str_replace( array( '/', '-' ), ' ', $name ) ),
                'description' => $details[1],
                'category' => 'mad4b-read',
                'execute_callback' => array( __CLASS__, $details[0] ),
                'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
                'input_schema' => self::input_schema( $kind ),
                'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
                'meta' => array( 'public' => false, 'show_in_rest' => false,
                    'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
                    'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => false ) ),
            ) );
        }
    }
    private static function valid_id( $id ) {
        return is_string( $id ) && (bool) preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id );
    }
    private static function valid_ability( $id ) {
        return is_string( $id ) && (bool) preg_match( '~^[a-z0-9_-]{1,64}/[a-z0-9._/-]{1,191}$~D', $id );
    }
    private static function is_plain_https( $url, $check_network = true ) {
        if ( ! is_string( $url ) || strlen( $url ) > 1024 || false !== strpos( $url, '#' ) ) return false;
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
            || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
            || ! empty( $parts['port'] ) && 443 !== (int) $parts['port'] ) return false;
        $host = strtolower( (string) $parts['host'] );
        if ( ! preg_match( '/^(?=.{4,253}$)[a-z0-9.-]+\\.[a-z]{2,}$/D', $host ) || false !== strpos( $host, '..' )
            || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) return false;
        if ( $check_network && ( ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) ) return false;
        return true;
    }
    /** Block loops into WordPress MCP endpoints already suppressed by the
     * canonical Provider Isolation policy. Never bypass through outbound HTTP.
     */
    private static function transport_isolation_guard( array $peer ) {
        if ( ! class_exists( 'MAD4B_SCP_MCP_Provider_Isolation', false )
            || ! MAD4B_SCP_MCP_Provider_Isolation::effective() ) return true;
        if ( ! function_exists( 'site_url' ) )
            return new WP_Error( 'mad4b_mcp_isolation_origin_unknown', 'Cannot verify site origin while provider isolation is enabled.' );
        $site = parse_url( (string) site_url() );
        $target = parse_url( (string) ( $peer['endpoint'] ?? '' ) );
        if ( ! is_array( $site ) || ! is_array( $target )
            || empty( $site['host'] ) || empty( $target['host'] ) )
            return new WP_Error( 'mad4b_mcp_isolation_origin_unknown', 'Cannot verify canonical MCP origin.' );
        if ( strtolower( $site['host'] ) === strtolower( $target['host'] )
            && (int) ( $target['port'] ?? 443 ) === (int) ( $site['port'] ?? 443 ) )
            return new WP_Error( 'mad4b_mcp_provider_isolated',
                'Provider MCP endpoint belongs to this site and is isolated; use its reviewed local transport.' );
        return true;
    }
    private static function canonical( $value ) {
        if ( ! is_array( $value ) ) return $value;
        $keys = array_keys( $value );
        if ( $keys !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
        foreach ( $value as $key => $item ) $value[$key] = self::canonical( $item );
        return $value;
    }
    private static function digest( $value ) {
        return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    public static function manifest() {
        if ( null !== self::$manifest ) return self::$manifest;
        self::$manifest = array();
        if ( ! defined( 'MAD4B_SCP_MCP_PEER_MANIFEST_PATH' )
            || ! defined( 'MAD4B_SCP_MCP_PEER_MANIFEST_SHA256' ) ) return self::$manifest;
        $file = MAD4B_SCP_MCP_PEER_MANIFEST_PATH;
        $pin = strtolower( (string) MAD4B_SCP_MCP_PEER_MANIFEST_SHA256 );
        if ( ! is_string( $file ) || ! preg_match( '/^[a-f0-9]{64}$/D', $pin ) ) return self::$manifest;
        $real = realpath( $file );
        if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) return self::$manifest;
        foreach ( array( 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
            if ( ! defined( $constant ) ) continue;
            $root = realpath( constant( $constant ) );
            if ( false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) return self::$manifest;
        }
        $hash = hash_file( 'sha256', $real );
        if ( ! is_string( $hash ) || ! hash_equals( $pin, $hash ) ) return self::$manifest;
        if ( filesize( $real ) > 65536 ) return self::$manifest;
        $json = json_decode( (string) file_get_contents( $real ), true );
        if ( ! is_array( $json ) || ( $json['contract'] ?? '' ) !== self::CONTRACT
            || ! isset( $json['peers'] ) || ! is_array( $json['peers'] ) || count( $json['peers'] ) > self::MAX_PEERS ) return self::$manifest;
        $rows = array();
        foreach ( $json['peers'] as $peer ) {
            if ( ! is_array( $peer ) || ! self::valid_id( $peer['id'] ?? null )
                || ! self::is_plain_https( $peer['endpoint'] ?? null, false )
                || ! isset( $peer['bindings'] ) || ! is_array( $peer['bindings'] )
                || count( $peer['bindings'] ) > self::MAX_TOOLS || isset( $rows[$peer['id']] ) ) return self::$manifest;
            $bindings = array();
            foreach ( $peer['bindings'] as $row ) {
                if ( ! is_array( $row ) || ! self::valid_ability( $row['ability'] ?? null )
                    || ! is_string( $row['tool'] ?? null ) || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $row['tool'] )
                    || ! in_array( $row['dimension'] ?? '', array( 'read', 'write' ), true )
                    || ! is_string( $row['schema_sha256'] ?? null )
                    || ! preg_match( '/^[a-f0-9]{64}$/D', $row['schema_sha256'] )
                    || isset( $bindings[$row['ability']] ) ) return self::$manifest;
                $bindings[$row['ability']] = array(
                    'ability' => $row['ability'], 'tool' => $row['tool'],
                    'dimension' => $row['dimension'], 'schema_sha256' => $row['schema_sha256'],
                    'read_execution_reviewed' => true === ( $row['read_execution_reviewed'] ?? false ),
                    'output_schema_sha256' => isset( $row['output_schema_sha256'] ) && is_string( $row['output_schema_sha256'] )
                        && preg_match( '/^[a-f0-9]{64}$/D', $row['output_schema_sha256'] )
                        ? $row['output_schema_sha256'] : '',
                );
            }
            ksort( $bindings, SORT_STRING );
            $rows[$peer['id']] = array( 'id' => $peer['id'], 'endpoint' => $peer['endpoint'],
                'bindings' => $bindings );
        }
        ksort( $rows, SORT_STRING );
        self::$manifest = $rows;
        return self::$manifest;
    }
    private static function peer_token( $id ) {
        // Credentials are operator-provided, never read from plugin options or
        // copied from the user's MAD4B bearer. Never include in evidence.
        if ( ! defined( 'MAD4B_SCP_MCP_PEER_TOKENS' ) ) return '';
        $tokens = MAD4B_SCP_MCP_PEER_TOKENS;
        if ( ! is_array( $tokens ) || ! isset( $tokens[$id] ) || ! is_string( $tokens[$id] ) ) return '';
        $token = $tokens[$id];
        return strlen( $token ) <= 4096 && false === strpos( $token, "\r" )
            && false === strpos( $token, "\n" ) ? $token : '';
    }
    private static function rpc( array $peer, $method, $params, $version, $session = '' ) {
        if ( ! self::is_plain_https( $peer['endpoint'] ) ) return new WP_Error( 'mad4b_mcp_peer_url_unsafe', 'Pinned MCP endpoint is not eligible for HTTPS transport.' );
        $isolation = self::transport_isolation_guard( $peer );
        if ( is_wp_error( $isolation ) ) return $isolation;
        if ( ! function_exists( 'wp_safe_remote_post' ) ) return new WP_Error( 'mad4b_mcp_http_unavailable', 'Safe HTTP transport is unavailable.' );
        if ( null === self::$rpc_started_at ) self::$rpc_started_at = microtime( true );
        $elapsed = microtime( true ) - self::$rpc_started_at;
        if ( $elapsed >= self::$rpc_budget_seconds || ++self::$rpc_sequence > 100 )
            return new WP_Error( 'mad4b_mcp_request_budget_exceeded', 'Bounded native MCP request budget exceeded; use reviewed local fallback.' );
        $remaining = self::$rpc_budget_seconds - $elapsed;
        $timeout = max( 1, min( 2, (int) ceil( $remaining ) ) );
        $id = substr( hash( 'sha256', $peer['id'] . "|" . $method . "|" . self::$rpc_sequence . "|" . (string) wp_json_encode( $params ) ), 0, 16 );
        $headers = array(
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version,
            'Mcp-Method' => $method,
        );
        if ( '2026-07-28' === $version && 'tools/call' === $method
            && is_array( $params ) && is_string( $params['name'] ?? null )
            && preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $params['name'] ) )
            $headers['Mcp-Name'] = $params['name'];
        if ( '' !== $session ) $headers['Mcp-Session-Id'] = $session;
        $token = self::peer_token( $peer['id'] );
        if ( '' !== $token ) $headers['Authorization'] = 'Bearer ' . $token;
        if ( '2026-07-28' === $version ) {
            $params = is_array( $params ) ? $params : array();
            $params['_meta'] = array(
                'io.modelcontextprotocol/protocolVersion' => $version,
                'io.modelcontextprotocol/clientInfo' => array( 'name' => 'MAD4B WordPress MCP Hybrid', 'version' => '1.0.0' ),
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            );
        }
        $request = array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params );
        $response = wp_safe_remote_post( $peer['endpoint'], array(
            'headers' => $headers, 'body' => wp_json_encode( $request ),
            'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => self::MAX_BYTES, 'blocking' => true,
        ) );
        if ( is_wp_error( $response ) ) return new WP_Error( 'mad4b_mcp_peer_transport_failed', 'Peer MCP transport failed; no route can be certified.' );
        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) return new WP_Error( 'mad4b_mcp_peer_http_' . (int) $status, 'Peer MCP HTTP status prevents catalog verification.' );
        $body = wp_remote_retrieve_body( $response );
        if ( ! is_string( $body ) || strlen( $body ) >= self::MAX_BYTES ) return new WP_Error( 'mad4b_mcp_peer_response_unbounded', 'Peer MCP response was incomplete or too large.' );
        $type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( false !== strpos( $type, 'text/event-stream' ) ) {
            // The bounded response may contain comments/keepalive and multiple events.
            $frames = preg_split( "/\\r?\\n\\r?\\n/", $body );
            $decoded = null;
            foreach ( $frames as $frame ) {
                $data = array();
                foreach ( preg_split( "/\\r?\\n/", (string) $frame ) as $line ) {
                    if ( 0 === strpos( $line, 'data:' ) ) $data[] = trim( substr( $line, 5 ) );
                }
                if ( ! $data ) continue;
                $candidate = json_decode( implode( "\n", $data ), true );
                if ( is_array( $candidate ) && (string) ( $candidate['id'] ?? '' ) === $id ) {
                    if ( null !== $decoded ) return new WP_Error( 'mad4b_mcp_duplicate_response', 'Multiple responses used one RPC identity.' );
                    $decoded = $candidate;
                }
            }
            $wire = $decoded;
        } else {
            $wire = json_decode( $body, true );
        }
        // A JSON-RPC response contains exactly one of result or error, never both.
        // Mixed envelopes must not be accepted as successful tools/call reads.
        $has_result = is_array( $wire ) && array_key_exists( 'result', $wire );
        $has_error = is_array( $wire ) && array_key_exists( 'error', $wire );
        if ( ! is_array( $wire ) || (string) ( $wire['id'] ?? '' ) !== $id || ( $wire['jsonrpc'] ?? '' ) !== '2.0'
            || $has_result === $has_error
            || ( $has_error && ( ! is_array( $wire['error'] )
                || ! is_int( $wire['error']['code'] ?? null )
                || ! is_string( $wire['error']['message'] ?? null ) ) ) )
            return new WP_Error( 'mad4b_mcp_peer_invalid_rpc', 'MCP JSON-RPC envelope does not match the request.' );
        $next_session = (string) wp_remote_retrieve_header( $response, 'mcp-session-id' );
        if ( strlen( $next_session ) > 191 || false !== strpos( $next_session, "\n" ) ) $next_session = '';
        return array( 'wire' => $wire, 'session' => $next_session, 'http_status' => (int) $status );
    }
    private static function notify_legacy_initialized( array $peer, $version, $session ) {
        // Legacy MCP requires initialized notification after initialize. A
        // notification has no request id or reply payload.
        if ( ! self::is_plain_https( $peer['endpoint'] ) ) return false;
        if ( is_wp_error( self::transport_isolation_guard( $peer ) ) ) return false;
        $headers = array( 'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version, 'Accept' => 'application/json, text/event-stream' );
        if ( '' !== $session ) $headers['Mcp-Session-Id'] = $session;
        $token = self::peer_token( $peer['id'] );
        if ( '' !== $token ) $headers['Authorization'] = 'Bearer ' . $token;
        if ( null === self::$rpc_started_at ) self::$rpc_started_at = microtime( true );
        $remaining = self::$rpc_budget_seconds - ( microtime( true ) - self::$rpc_started_at );
        if ( $remaining <= 0 ) return false;
        $reply = wp_safe_remote_post( $peer['endpoint'], array(
            'headers' => $headers,
            'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ),
            'timeout' => max( 1, min( 2, (int) ceil( $remaining ) ) ), 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 1024, 'blocking' => true,
        ) );
        return ! is_wp_error( $reply ) && in_array( (int) wp_remote_retrieve_response_code( $reply ), array( 200, 202, 204 ), true );
    }
    private static function negotiate( array $peer ) {
        $modern = self::rpc( $peer, 'server/discover', array(), '2026-07-28' );
        if ( ! is_wp_error( $modern ) && is_array( $modern['wire']['result'] ?? null )
            && ( $modern['wire']['result']['resultType'] ?? '' ) === 'complete'
            && in_array( '2026-07-28', $modern['wire']['result']['supportedVersions'] ?? array(), true ) )
            return array( 'version' => '2026-07-28', 'session' => '', 'discovered' => $modern['wire']['result'] );
        // On modern credential failures, bad JSON/schema, 5xx etc. DO NOT
        // downgrade the protocol. Only explicit unknown-method/version error
        // means the peer may speak legacy MCP.
        $fallback = false;
        if ( is_wp_error( $modern ) ) {
            $fallback = in_array( $modern->get_error_code(), array( 'mad4b_mcp_peer_http_404', 'mad4b_mcp_peer_http_405', 'mad4b_mcp_peer_http_426' ), true );
        } else {
            $error = $modern['wire']['error'] ?? array();
            $fallback = is_array( $error ) && in_array( (int) ( $error['code'] ?? 0 ), array( -32601, -32001 ), true );
        }
        if ( ! $fallback ) return new WP_Error( 'mad4b_mcp_protocol_uncertain', 'Cannot safely verify the MCP protocol revision.' );
        $initial = self::rpc( $peer, 'initialize', array(
            'protocolVersion' => '2025-11-25',
            'capabilities' => new stdClass(),
            'clientInfo' => array( 'name' => 'MAD4B WordPress MCP Hybrid', 'version' => '1.0.0' ),
        ), '2025-11-25' );
        if ( is_wp_error( $initial ) || ! is_array( $initial['wire']['result'] ?? null ) )
            return new WP_Error( 'mad4b_mcp_legacy_init_failed', 'Legacy MCP handshake did not complete.' );
        $revision = (string) ( $initial['wire']['result']['protocolVersion'] ?? '' );
        if ( ! in_array( $revision, array( '2025-11-25', '2025-06-18', '2025-03-26' ), true ) )
            return new WP_Error( 'mad4b_mcp_legacy_version_unsupported', 'Legacy MCP protocol revision is unsupported.' );
        if ( ! self::notify_legacy_initialized( $peer, $revision, $initial['session'] ) )
            return new WP_Error( 'mad4b_mcp_legacy_notify_failed', 'Legacy MCP initialized notification failed.' );
        return array( 'version' => $revision, 'session' => $initial['session'], 'discovered' => $initial['wire']['result'] );
    }
    /**
     * Request-only cache: never persists provider credentials, protocol
     * sessions or tool schemas. One freshly negotiated MCP session may serve
     * the hybrid planner and the immediate read call for <=1 second.
     * Drift is still checked by exact owner-pinned schema and client catalog
     * hash; tools/call invalidates this cache regardless of its result.
     */
    private static function prepared_catalog( $peer_id ) {
        $peers = self::manifest();
        if ( ! isset( $peers[$peer_id] ) )
            return new WP_Error( 'mad4b_mcp_peer_not_pinned', 'Provider MCP endpoint is not owner-pinned.' );
        $cache_key = hash( 'sha256', $peer_id . '|' . $peers[$peer_id]['endpoint'] );
        $cached = self::$request_catalogs[$cache_key] ?? null;
        if ( is_array( $cached ) && isset( $cached['created_at'] )
            && microtime( true ) - $cached['created_at'] < self::REQUEST_CATALOG_MAX_AGE_SECONDS )
            return $cached;
        unset( self::$request_catalogs[$cache_key] );
        $prepared = self::prepared_catalog( $peer_id );
        if ( is_wp_error( $prepared ) ) return $prepared;
        $session = $prepared['session'];
        $catalog = $prepared['catalog'];
        $record = array( 'session' => $session, 'catalog' => $catalog,
            'created_at' => microtime( true ), 'cache_key' => $cache_key );
        self::$request_catalogs[$cache_key] = $record;
        return $record;
    }
    public static function catalog( $peer_id ) {
        $prepared = self::prepared_catalog( $peer_id );
        return is_wp_error( $prepared ) ? $prepared : $prepared['catalog'];
    }
    private static function catalog_in_session( array $peer, array $session ) {
        $peer_id = $peer['id'];
        $result = array(); $cursor = ''; $seen = array(); $done = false;
        for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
            $params = '' === $cursor ? array() : array( 'cursor' => $cursor );
            $response = self::rpc( $peer, 'tools/list', $params, $session['version'], $session['session'] );
            if ( is_wp_error( $response ) || ! is_array( $response['wire']['result'] ?? null ) )
                return new WP_Error( 'mad4b_mcp_tools_list_failed', 'Provider MCP tools/list could not be verified.' );
            $part = $response['wire']['result'];
            if ( '2026-07-28' === $session['version'] && ( $part['resultType'] ?? '' ) !== 'complete' )
                return new WP_Error( 'mad4b_mcp_catalog_not_complete', 'Modern tools/list is not a complete result.' );
            if ( ! isset( $part['tools'] ) || ! is_array( $part['tools'] ) ) return new WP_Error( 'mad4b_mcp_tools_list_invalid', 'Invalid MCP tool collection.' );
            foreach ( $part['tools'] as $tool ) {
                if ( ! is_array( $tool ) || ! is_string( $tool['name'] ?? null )
                    || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $tool['name'] )
                    || ! is_array( $tool['inputSchema'] ?? null ) || isset( $result[$tool['name']]) )
                    return new WP_Error( 'mad4b_mcp_tools_list_ambiguous', 'Duplicate or malformed MCP tool identity.' );
                $result[$tool['name']] = array(
                    'name' => $tool['name'],
                    'input_schema' => $tool['inputSchema'],
                    'output_schema' => is_array( $tool['outputSchema'] ?? null ) ? $tool['outputSchema'] : null,
                    'schema_sha256' => self::digest( $tool['inputSchema'] ),
                    'annotations' => is_array( $tool['annotations'] ?? null ) ? array_intersect_key( $tool['annotations'],
                        array_flip( array( 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ) ) ) : array(),
                );
                if ( count( $result ) > self::MAX_TOOLS ) return new WP_Error( 'mad4b_mcp_tools_list_overflow', 'MCP catalog exceeded bounded inventory.' );
            }
            $next = $part['nextCursor'] ?? '';
            if ( ! is_string( $next ) || strlen( $next ) > 512 ) return new WP_Error( 'mad4b_mcp_cursor_invalid', 'MCP pagination cursor invalid.' );
            if ( '' === $next ) { $done = true; break; }
            if ( isset( $seen[$next] ) ) return new WP_Error( 'mad4b_mcp_cursor_cycle', 'MCP pagination cycled without terminating.' );
            $seen[$next] = true;
            $cursor = $next;
        }
        if ( ! $done ) return new WP_Error( 'mad4b_mcp_catalog_partial', 'MCP catalog remained incomplete after bounded pagination.' );
        ksort( $result, SORT_STRING );
        $reviewed = array();
        foreach ( $peer['bindings'] as $ability => $binding ) {
            $tool = $result[$binding['tool']] ?? null;
            $matched = is_array( $tool ) && hash_equals( $binding['schema_sha256'], $tool['schema_sha256'] );
            if ( $matched && is_array( $tool['output_schema'] ?? null ) ) {
                $expected_output = (string) ( $binding['output_schema_sha256'] ?? '' );
                $matched = '' !== $expected_output
                    && hash_equals( $expected_output, self::digest( $tool['output_schema'] ) );
            }
            if ( $matched && ! is_array( $tool['output_schema'] ?? null )
                && ! empty( $binding['output_schema_sha256'] ) ) $matched = false;
            $reviewed[$ability] = array(
                'ability' => $ability, 'tool' => $binding['tool'], 'dimension' => $binding['dimension'],
                'state' => !$tool ? 'MCP_TOOL_MISSING' : ( $matched ? 'MCP_SCHEMA_REVIEWED' : 'MCP_SCHEMA_DRIFT' ),
                'schema_sha256' => $binding['schema_sha256'],
                'output_schema_sha256' => isset( $binding['output_schema_sha256'] ) ? $binding['output_schema_sha256'] : '',
                'read_execution_reviewed' => $binding['read_execution_reviewed'],
                'read_execution_eligible' => $matched
                    && 'read' === $binding['dimension']
                    && ! empty( $binding['read_execution_reviewed'] )
                    && true === ( $tool['annotations']['readOnlyHint'] ?? null )
                    && true !== ( $tool['annotations']['destructiveHint'] ?? null ),
            );
        }
        return array(
            'contract' => self::CONTRACT, 'peer_id' => $peer_id,
            'protocol' => $session['version'],
            'capability_snapshot_sha256' => self::digest( $result ),
            'tools' => array_values( $result ), 'bindings' => $reviewed,
            'tool_count' => count( $result ), 'complete' => true,
            'native_tool_execution_performed' => false, 'mutations_performed' => false,
        );
    }
    public static function hybrid_plan_from_evidence( array $native, array $local, $ability, $dimension = 'read' ) {
        $result = array( 'contract' => self::CONTRACT . '.route.v1',
            'ability' => $ability, 'dimension' => $dimension,
            'state' => 'NO_REVIEWED_ROUTE', 'selected' => '',
            'fallback' => array(), 'non_authorizing' => true, 'mutation_performed' => false );
        if ( ! self::valid_ability( $ability ) || ! in_array( $dimension, array( 'read', 'write' ), true ) ) return $result;
        $binding = $native['bindings'][$ability] ?? null;
        if ( 'write' === $dimension && is_array( $binding )
            && ( $binding['state'] ?? '' ) === 'MCP_SCHEMA_REVIEWED' )
            $result['native_write_candidate'] = true;
        if ( 'read' === $dimension && ! empty( $native['complete'] )
            && is_array( $binding ) && ! empty( $binding['read_execution_eligible'] )
            && ( $binding['dimension'] ?? '' ) === 'read'
            && ( $binding['state'] ?? '' ) === 'MCP_SCHEMA_REVIEWED' ) {
            $result['selected'] = 'native_mcp';
            $result['state'] = 'NATIVE_MCP_REVIEWED_AUTHORIZATION_REQUIRED';
            $result['peer_id'] = (string) ( $native['peer_id'] ?? '' );
            $result['tool'] = $binding['tool'];
            $result['catalog_sha256'] = (string) ( $native['capability_snapshot_sha256'] ?? '' );
        }
        // A fallback is independently reviewed and must be registered, not a
        // guess by tool name, namespace or output schema.
        $local_state = $local[$ability] ?? array();
        if ( ! empty( $local_state['registered'] ) && ! empty( $local_state['reviewed'] )
            && ( $local_state['dimension'] ?? '' ) === $dimension ) {
            $local_kind = ( $local_state['source'] ?? '' ) === 'adapter' ? 'reviewed_adapter' : 'wordpress_ability';
            if ( '' === $result['selected'] ) {
                $result['selected'] = $local_kind;
                $result['state'] = 'LOCAL_GOVERNED_ROUTE_AUTHORIZATION_REQUIRED';
            } else $result['fallback'][] = $local_kind;
        }
        if ( 'write' === $dimension ) {
            $result['required_gates'] = array( 'exact_owner_approval', 'provider_behavior_certification',
                'governed_write_grant', 'audit_journal', 'readback_and_compensation' );
            // This resolver MUST NOT treat native discovery as execution or
            // promote an unreviewed tools/call into a write dispatcher.
            $result['write_execution_allowed_by_plan'] = false;
        }
        return $result;
    }
    public static function hybrid_plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $peer_id = (string) ( $input['peer_id'] ?? '' );
        $ability = (string) ( $input['ability'] ?? '' );
        $dimension = (string) ( $input['dimension'] ?? 'read' );
        if ( ! self::valid_ability( $ability ) || ! in_array( $dimension, array( 'read', 'write' ), true ) )
            return new WP_Error( 'mad4b_mcp_hybrid_request_invalid', 'Exact semantic Ability and dimension are required.' );
        $peers = self::manifest();
        $native = ! empty( $peer_id ) && isset( $peers[$peer_id] )
            ? self::catalog( $peer_id ) : new WP_Error( 'mad4b_mcp_peer_not_pinned', 'No owner-pinned native peer configured.' );
        if ( is_wp_error( $native ) ) $native = array( 'complete' => false, 'bindings' => array(), 'peer_id' => $peer_id,
            'unavailable_reason' => $native->get_error_code() );
        $local = array();
        // WordPress-local registered Abilities are observable; ability
        // publisher provenance is NOT proven by namespace or category.
        if ( function_exists( 'wp_get_ability' ) && wp_get_ability( $ability ) ) {
            $local[$ability] = array( 'registered' => true, 'reviewed' => false,
                'dimension' => $dimension, 'source' => 'wordpress' );
        }
        // Do not trust a matching ability name. Verify the *registered*
        // WP_Ability execute callback and annotations against a single
        // source-reviewed Adapter. Unknown native WordPress abilities remain
        // candidates, not executable local fallback.
        if ( 'read' === $dimension && isset( $local[$ability] ) ) {
            $verified = MAD4B_SCP_MCP_Hybrid_Authority::matching_adapter( wp_get_ability( $ability ), $ability );
            if ( null !== $verified ) {
                $local[$ability]['reviewed'] = true;
                $local[$ability]['source'] = 'adapter';
            }
        }
        $plan = self::hybrid_plan_from_evidence( $native, $local, $ability, $dimension );
        $plan['peer_id'] = $peer_id;
        if ( ! empty( $native['unavailable_reason'] ) ) $plan['native_unavailable_reason'] = $native['unavailable_reason'];
        return $plan;
    }
    /**
     * Hybrid read dispatcher: choose once; never retry after native execution
     * failure, and never invoke a local Ability unless an approved Adapter
     * independently declares exactly that registered read Ability.
     */
    public static function execute_hybrid_read( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $ability = (string) ( $input['ability'] ?? '' );
        $arguments = $input['arguments'] ?? array();
        $peer_id = (string) ( $input['peer_id'] ?? '' );
        if ( ! self::valid_ability( $ability ) || ! is_array( $arguments )
            || strlen( (string) wp_json_encode( $arguments ) ) > 8192 )
            return new WP_Error( 'mad4b_hybrid_read_invalid', 'Exact reviewed read Ability and bounded JSON arguments required.' );
        $plan = self::hybrid_plan( array( 'peer_id' => $peer_id,
            'ability' => $ability, 'dimension' => 'read' ) );
        if ( is_wp_error( $plan ) ) return $plan;
        $selected = (string) ( $plan['selected'] ?? '' );
        if ( 'native_mcp' === $selected ) {
            $expected = (string) ( $input['expected_catalog_sha256'] ?? '' );
            if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
                || ! hash_equals( $expected, (string) ( $plan['catalog_sha256'] ?? '' ) ) )
                return new WP_Error( 'mad4b_hybrid_read_snapshot_unacknowledged',
                    'Native read requires client-acknowledged exact catalog fingerprint.' );
            // No fallback after invoking a remote tool: partial effects are
            // unknown even if its response is a transport error.
            return self::read_tool( array( 'peer_id' => $peer_id, 'ability' => $ability,
                'expected_catalog_sha256' => $expected, 'arguments' => $arguments ) );
        }
        if ( 'reviewed_adapter' !== $selected || ! function_exists( 'wp_get_ability' ) )
            return new WP_Error( 'mad4b_hybrid_no_eligible_read', 'Neither an approved native read nor registered reviewed adapter read was available.' );
        $authorized = MAD4B_SCP_MCP_Hybrid_Authority::authorize_read( '', $ability, 'local' );
        if ( is_wp_error( $authorized ) ) return $authorized;
        $local = wp_get_ability( $ability );
        if ( ! is_object( $local ) || ! method_exists( $local, 'execute' ) )
            return new WP_Error( 'mad4b_hybrid_local_disappeared', 'Reviewed local Ability disappeared before dispatch.' );
        if ( null === MAD4B_SCP_MCP_Hybrid_Authority::matching_adapter( $local, $ability ) )
            return new WP_Error( 'mad4b_hybrid_local_provenance_changed',
                'Local Ability callback provenance changed between planning and execution.' );
        // WP_Ability::execute validates input/output and independently checks
        // permission_callback. Never call an Adapter method directly.
        $value = $local->execute( $arguments );
        if ( is_wp_error( $value ) ) return $value;
        return array( 'contract' => self::CONTRACT . '.read-execution.v1',
            'ability' => $ability, 'selected' => 'reviewed_adapter',
            'arguments_sha256' => self::digest( $arguments ),
            'response_sha256' => self::digest( $value ),
            'result' => $value,
            'native_execution_performed' => false, 'write_authority_created' => false,
            'mutation_intended' => false );
    }

    /**
     * Reviewed read-only tools/call. A remote annotation is not enough:
     * exact owner-pinned tool schema, read contract and positive readonly
     * annotation must agree; writes are NEVER called from this API.
     */
    public static function read_tool( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $peer_id = (string) ( $input['peer_id'] ?? '' );
        $ability = (string) ( $input['ability'] ?? '' );
        $expected = strtolower( (string) ( $input['expected_catalog_sha256'] ?? '' ) );
        $arguments = $input['arguments'] ?? array();
        if ( ! self::valid_ability( $ability ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected )
            || ! is_array( $arguments ) || strlen( (string) wp_json_encode( $arguments ) ) > 8192 )
            return new WP_Error( 'mad4b_native_read_input_invalid', 'Native read call requires exact reviewed catalog and bounded JSON arguments.' );
        $peers = self::manifest();
        if ( ! isset( $peers[$peer_id] ) || ! isset( $peers[$peer_id]['bindings'][$ability] ) )
            return new WP_Error( 'mad4b_native_read_binding_missing', 'No owner-pinned native tool binding for the Ability.' );
        $binding = $peers[$peer_id]['bindings'][$ability];
        if ( 'read' !== $binding['dimension'] || empty( $binding['read_execution_reviewed'] ) )
            return new WP_Error( 'mad4b_native_read_not_certified', 'Native MCP tool has no explicit read-execution certification.' );
        // The exact per-peer Agent grant must be checked BEFORE outbound IO.
        $authorized = MAD4B_SCP_MCP_Hybrid_Authority::authorize_read( $peer_id, $ability, 'native' );
        if ( is_wp_error( $authorized ) ) return $authorized;
        $session = self::negotiate( $peers[$peer_id] );
        if ( is_wp_error( $session ) ) return $session;
        $catalog = self::catalog_in_session( $peers[$peer_id], $session );
        if ( is_wp_error( $catalog ) ) return $catalog;
        if ( ! hash_equals( $expected, (string) $catalog['capability_snapshot_sha256'] ) )
            return new WP_Error( 'mad4b_native_read_catalog_drift', 'Native catalog changed since review; execute nothing.' );
        $row = $catalog['bindings'][$ability] ?? array();
        if ( ( $row['state'] ?? '' ) !== 'MCP_SCHEMA_REVIEWED' )
            return new WP_Error( 'mad4b_native_read_tool_drift', 'Tool schema or identity no longer matches reviewed binding.' );
        $tool = null;
        foreach ( $catalog['tools'] as $candidate ) {
            if ( $candidate['name'] === $binding['tool'] ) { $tool = $candidate; break; }
        }
        $annotations = is_array( $tool['annotations'] ?? null ) ? $tool['annotations'] : array();
        // WordPress REST JSON Schema validator is the existing trusted schema
        // gate. Do not invoke third-party callbacks on unvalidated input.
        if ( ! function_exists( 'rest_validate_value_from_schema' ) )
            return new WP_Error( 'mad4b_native_schema_validator_unavailable', 'WordPress schema validation is unavailable.' );
        $validation = rest_validate_value_from_schema( $arguments, $tool['input_schema'], 'arguments' );
        if ( is_wp_error( $validation ) || true !== $validation )
            return new WP_Error( 'mad4b_native_arguments_invalid', 'Native tool arguments do not match the reviewed input schema.' );
        if ( true !== ( $annotations['readOnlyHint'] ?? null )
            || true === ( $annotations['destructiveHint'] ?? null ) )
            return new WP_Error( 'mad4b_native_read_annotation_missing', 'Remote tool did not affirm non-destructive read-only behavior.' );
        // Invoke on the very same negotiated MCP session as the verified
        // tools/list snapshot; never substitute a stale session from a prior request.
        $reply = self::rpc( $peers[$peer_id], 'tools/call',
            array( 'name' => $binding['tool'], 'arguments' => $arguments ),
            $session['version'], $session['session'] );
        unset( self::$request_catalogs[$prepared['cache_key']] );
        if ( is_wp_error( $reply ) ) return $reply;
        if ( ! is_array( $reply['wire']['result'] ?? null ) )
            return new WP_Error( 'mad4b_native_read_call_failed', 'The MCP tool call produced no verified result.' );
        if ( ( $reply['wire']['result']['resultType'] ?? ( '2026-07-28' === $session['version'] ? '' : 'complete' ) ) !== 'complete'
            || ! empty( $reply['wire']['result']['isError'] ) )
            return new WP_Error( 'mad4b_native_read_result_incomplete', 'MCP tool requires further interaction or returned an error; no automatic retries.' );
        if ( is_array( $tool['output_schema'] ?? null ) ) {
            $structured = $reply['wire']['result']['structuredContent'] ?? null;
            if ( ! is_array( $structured ) )
                return new WP_Error( 'mad4b_native_output_missing', 'Reviewed structured MCP result is missing.' );
            $validated = rest_validate_value_from_schema( $structured, $tool['output_schema'], 'structuredContent' );
            if ( is_wp_error( $validated ) || true !== $validated )
                return new WP_Error( 'mad4b_native_output_schema_mismatch', 'MCP response violates reviewed output schema.' );
        }
        return array( 'contract' => self::CONTRACT . '.read-execution.v1',
            'peer_id' => $peer_id, 'ability' => $ability, 'tool' => $binding['tool'],
            'protocol' => $session['version'], 'catalog_sha256' => $expected,
            'arguments_sha256' => self::digest( $arguments ),
            'response_sha256' => self::digest( $reply['wire']['result'] ),
            'result' => $reply['wire']['result'],
            'authorization_source' => 'read_gate_plus_operator_reviewed_tool_contract',
            'write_authority_created' => false, 'mutation_intended' => false );
    }

    public static function candidate_inventory( $input = array() ) {
        unset( $input );
        $pinned = array_keys( self::manifest() );
        $known = array();
        if ( class_exists( 'MAD4B_SCP_Provider_Transport_Registry', false ) ) {
            foreach ( MAD4B_SCP_Provider_Transport_Registry::descriptors() as $row ) {
                if ( ! is_array( $row ) || empty( $row['provider_id'] ) ) continue;
                $known[] = array(
                    'provider_id' => (string) $row['provider_id'],
                    'transport_kind' => (string) ( $row['kind'] ?? '' ),
                    'state' => 'DECLARED_PATTERN_UNVERIFIED',
                    'pattern_count' => count( $row['routes'] ?? array() ),
                    'requires_independent_native_probe' => true,
                );
            }
        }
        $foreign = array();
        $runtime_ready = false;
        if ( class_exists( 'MAD4B_SCP_MCP_Peer_Governance', false )
            && function_exists( 'did_action' ) && did_action( 'wp_loaded' ) > 0 ) {
            $observed = MAD4B_SCP_MCP_Peer_Governance::foreign_transport_inventory();
            if ( is_array( $observed ) && ! empty( $observed['inventory_ready'] ) ) {
                $foreign = array_slice( $observed['foreign_routes'] ?? array(), 0, 100 );
                $runtime_ready = true;
            }
        }
        return array( 'contract' => self::CONTRACT . '.candidates.v1',
            'pinned_peer_ids' => $pinned, 'pinned_count' => count( $pinned ),
            'source_reviewed_provider_patterns' => $known,
            'observed_unreviewed_rest_routes' => $foreign,
            'runtime_rest_inventory_ready' => $runtime_ready,
            'catalog_protocol_probe_performed' => false,
            'unverified_candidate_auto_authorized' => false,
            'write_authority_created' => false, 'mutation_performed' => false );
    }
    public static function read_catalog( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        return self::catalog( (string) ( $input['peer_id'] ?? '' ) );
    }
}
