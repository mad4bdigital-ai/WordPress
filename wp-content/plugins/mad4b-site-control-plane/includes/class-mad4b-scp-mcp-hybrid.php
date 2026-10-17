<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
        $required = array( 'peer_id' );
        if ( 'plan' === $kind ) $required[] = 'ability';
        if ( 'call' === $kind ) $required = array( 'peer_id', 'ability', 'expected_catalog_sha256' );
        return array( 'type' => 'object', 'properties' => $properties, 'required' => $required,
            'additionalProperties' => false );
    }
    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $definitions = array(
            'mad4b/native-mcp-catalog' => array( 'read_catalog', 'Observe an exact owner-pinned native MCP peer tools/list catalog.' ),
            'mad4b/native-mcp-hybrid-plan' => array( 'hybrid_plan', 'Plan native MCP versus reviewed WordPress Ability or PHP Adapter per capability.' ),
            'mad4b/native-mcp-read-call' => array( 'read_tool', 'Call a reviewed native MCP read tool with an exact catalog fingerprint.' ),
        );
        foreach ( $definitions as $name => $details ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            $kind = 'mad4b/native-mcp-catalog' === $name ? 'catalog' :
                ( 'mad4b/native-mcp-hybrid-plan' === $name ? 'plan' : 'call' );
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
    private static function is_plain_https( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 1024 || false !== strpos( $url, '#' ) ) return false;
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
            || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
            || ! empty( $parts['port'] ) && 443 !== (int) $parts['port'] ) return false;
        $host = strtolower( (string) $parts['host'] );
        if ( ! preg_match( '/^(?=.{4,253}$)[a-z0-9.-]+\\.[a-z]{2,}$/D', $host ) || false !== strpos( $host, '..' )
            || 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) return false;
        if ( ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) return false;
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
                || ! self::is_plain_https( $peer['endpoint'] ?? null )
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
        if ( ! function_exists( 'wp_safe_remote_post' ) ) return new WP_Error( 'mad4b_mcp_http_unavailable', 'Safe HTTP transport is unavailable.' );
        $id = substr( hash( 'sha256', $peer['id'] . "|" . $method . "|" . (string) wp_json_encode( $params ) ), 0, 16 );
        $headers = array(
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version,
            'Mcp-Method' => $method,
        );
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
            'timeout' => 7, 'redirection' => 0, 'sslverify' => true,
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
        if ( ! is_array( $wire ) || (string) ( $wire['id'] ?? '' ) !== $id || ( $wire['jsonrpc'] ?? '' ) !== '2.0'
            || ( ! isset( $wire['result'] ) && ! isset( $wire['error'] ) ) ) return new WP_Error( 'mad4b_mcp_peer_invalid_rpc', 'MCP JSON-RPC envelope does not match the request.' );
        $next_session = (string) wp_remote_retrieve_header( $response, 'mcp-session-id' );
        if ( strlen( $next_session ) > 191 || false !== strpos( $next_session, "\n" ) ) $next_session = '';
        return array( 'wire' => $wire, 'session' => $next_session, 'http_status' => (int) $status );
    }
    private static function notify_legacy_initialized( array $peer, $version, $session ) {
        // Legacy MCP requires initialized notification after initialize. A
        // notification has no request id or reply payload.
        if ( ! self::is_plain_https( $peer['endpoint'] ) ) return false;
        $headers = array( 'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version, 'Accept' => 'application/json, text/event-stream' );
        if ( '' !== $session ) $headers['Mcp-Session-Id'] = $session;
        $token = self::peer_token( $peer['id'] );
        if ( '' !== $token ) $headers['Authorization'] = 'Bearer ' . $token;
        $reply = wp_safe_remote_post( $peer['endpoint'], array(
            'headers' => $headers,
            'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ),
            'timeout' => 7, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 1024, 'blocking' => true,
        ) );
        return ! is_wp_error( $reply ) && in_array( (int) wp_remote_retrieve_response_code( $reply ), array( 200, 202, 204 ), true );
    }
    private static function negotiate( array $peer ) {
        $modern = self::rpc( $peer, 'server/discover', array(), '2026-07-28' );
        if ( ! is_wp_error( $modern ) && is_array( $modern['wire']['result'] ?? null )
            && in_array( '2026-07-28', $modern['wire']['result']['supportedVersions'] ?? array(), true ) )
            return array( 'version' => '2026-07-28', 'session' => '', 'discovered' => $modern['wire']['result'] );
        // On modern credential failures, bad JSON/schema, 5xx etc. DO NOT
        // downgrade the protocol. Only explicit unknown-method/version error
        // means the peer may speak legacy MCP.
        $fallback = false;
        if ( is_wp_error( $modern ) ) {
            $fallback = in_array( $modern->get_error_code(), array( 'mad4b_mcp_peer_http_400', 'mad4b_mcp_peer_http_404', 'mad4b_mcp_peer_http_405', 'mad4b_mcp_peer_http_426' ), true );
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
    public static function catalog( $peer_id ) {
        $peers = self::manifest();
        if ( ! isset( $peers[$peer_id] ) ) return new WP_Error( 'mad4b_mcp_peer_not_pinned', 'Provider MCP endpoint is not owner-pinned.' );
        $peer = $peers[$peer_id];
        $session = self::negotiate( $peer );
        if ( is_wp_error( $session ) ) return $session;
        return self::catalog_in_session( $peer, $session );
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
            if ( ! isset( $part['tools'] ) || ! is_array( $part['tools'] ) ) return new WP_Error( 'mad4b_mcp_tools_list_invalid', 'Invalid MCP tool collection.' );
            foreach ( $part['tools'] as $tool ) {
                if ( ! is_array( $tool ) || ! is_string( $tool['name'] ?? null )
                    || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $tool['name'] )
                    || ! is_array( $tool['inputSchema'] ?? null ) || isset( $result[$tool['name']]) )
                    return new WP_Error( 'mad4b_mcp_tools_list_ambiguous', 'Duplicate or malformed MCP tool identity.' );
                $result[$tool['name']] = array(
                    'name' => $tool['name'],
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
            $reviewed[$ability] = array(
                'ability' => $ability, 'tool' => $binding['tool'], 'dimension' => $binding['dimension'],
                'state' => !$tool ? 'MCP_TOOL_MISSING' : ( $matched ? 'MCP_SCHEMA_REVIEWED' : 'MCP_SCHEMA_DRIFT' ),
                'schema_sha256' => $binding['schema_sha256'],
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
        if ( class_exists( 'MAD4B_SCP_Adapter_Registry', false ) ) {
            foreach ( MAD4B_SCP_Adapter_Registry::instance()->all() as $adapter ) {
                $map = $adapter->ability_names();
                $surface = 'read' === $dimension ? array( 'read' ) : array( 'content', 'admin', 'write' );
                foreach ( $surface as $key ) {
                    if ( ! empty( $map[$key] ) && in_array( $ability, $map[$key], true ) ) {
                        if ( isset( $local[$ability] ) ) $local[$ability]['reviewed'] = true;
                        $local[$ability]['source'] = 'adapter';
                    }
                }
            }
        }
        $plan = self::hybrid_plan_from_evidence( $native, $local, $ability, $dimension );
        $plan['peer_id'] = $peer_id;
        if ( ! empty( $native['unavailable_reason'] ) ) $plan['native_unavailable_reason'] = $native['unavailable_reason'];
        return $plan;
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
        if ( true !== ( $annotations['readOnlyHint'] ?? null )
            || true === ( $annotations['destructiveHint'] ?? null ) )
            return new WP_Error( 'mad4b_native_read_annotation_missing', 'Remote tool did not affirm non-destructive read-only behavior.' );
        // Invoke on the very same negotiated MCP session as the verified
        // tools/list snapshot; never substitute a stale session from a prior request.
        $reply = self::rpc( $peers[$peer_id], 'tools/call',
            array( 'name' => $binding['tool'], 'arguments' => $arguments ),
            $session['version'], $session['session'] );
        if ( is_wp_error( $reply ) ) return $reply;
        if ( ! is_array( $reply['wire']['result'] ?? null ) )
            return new WP_Error( 'mad4b_native_read_call_failed', 'The MCP tool call produced no verified result.' );
        return array( 'contract' => self::CONTRACT . '.read-execution.v1',
            'peer_id' => $peer_id, 'ability' => $ability, 'tool' => $binding['tool'],
            'protocol' => $session['version'], 'catalog_sha256' => $expected,
            'result' => $reply['wire']['result'],
            'authorization_source' => 'read_gate_plus_operator_reviewed_tool_contract',
            'write_authority_created' => false, 'mutation_intended' => false );
    }

    public static function read_catalog( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        return self::catalog( (string) ( $input['peer_id'] ?? '' ) );
    }
}
