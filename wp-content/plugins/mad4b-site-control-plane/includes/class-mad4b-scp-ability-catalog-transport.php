<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Private central transport. No execution and no authority grants. */
final class MAD4B_SCP_Ability_Catalog_Transport {
	const CONTRACT = 'mad4b.ability-catalog-transport.v2';
	const BLOCK_BYTES = 32768;
	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_binary' ), 10, 4 );
		add_action( 'mad4b_catalog_gc', array( 'MAD4B_SCP_Catalog_Object_Store', 'collect_expired' ) );
		add_action( 'init', array( __CLASS__, 'schedule_gc' ) );
	}
	public static function schedule_gc() { if ( ! wp_next_scheduled( 'mad4b_catalog_gc' ) ) wp_schedule_event( time() + 3600, 'hourly', 'mad4b_catalog_gc' ); }
	private static function ttl() { return max( 60, (int) apply_filters( 'mad4b_scp_catalog_snapshot_ttl', 3600 ) ); }
	private static function retention() { return max( self::ttl() + 600, (int) apply_filters( 'mad4b_scp_catalog_retention_seconds', 604800 ) ); }
	private static function scope() {
		$context = apply_filters( 'mad4b_scp_authenticated_subject_context', array() );
		if ( ! is_array( $context ) ) $context = array();
		if ( isset( $context['token_scopes'] ) && is_array( $context['token_scopes'] ) ) { $context['token_scopes'] = array_values( array_unique( array_map( 'strval', $context['token_scopes'] ) ) ); sort( $context['token_scopes'], SORT_STRING ); }
		$identity = array_intersect_key( $context, array_flip( array( 'subject_type', 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'token_scopes' ) ) );
		return hash( 'sha256', self::encode( array( MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(), get_current_user_id(), wp_get_current_user()->allcaps, $identity ) ) );
	}
	private static function key( $scope, $kind, $id ) { return hash( 'sha256', $scope . ':' . $kind . ':' . $id ); }
	private static function canonical( $value, $depth = 0 ) {
		if ( $depth > 512 ) throw new JsonException( 'schema_depth_or_cycle' );
		if ( is_object( $value ) ) {
			if ( ! $value instanceof stdClass ) throw new JsonException( 'unsupported_schema_object' );
			$vars = get_object_vars( $value ); ksort( $vars, SORT_STRING ); $out = new stdClass();
			foreach ( $vars as $key => $item ) $out->$key = self::canonical( $item, $depth + 1 );
			return $out;
		}
		if ( ! is_array( $value ) ) return $value;
		if ( ! array_is_list( $value ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item, $depth + 1 );
		return $value;
	}
	private static function encode( $value ) { return json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
	private static function error( $code, $status = 400 ) { return new WP_Error( $code, 'Catalog request unavailable. Refresh discovery if its revision expired.', array( 'status' => $status ) ); }
	private static function cursor( $payload ) {
		$encoded = rtrim( strtr( base64_encode( self::encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}
	private static function decode_cursor( $cursor, $scope ) {
		if ( ! is_string( $cursor ) || strlen( $cursor ) > 4096 ) return self::error( 'mad4b_catalog_cursor_invalid' );
		$parts = explode( '.', $cursor );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) return self::error( 'mad4b_catalog_cursor_invalid' );
		$p = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		if ( ! is_array( $p ) || ( $p['scope'] ?? '' ) !== $scope || ( $p['contract'] ?? '' ) !== self::CONTRACT || ( $p['expires'] ?? 0 ) <= time() ) return self::error( 'mad4b_catalog_cursor_expired', 410 );
		return $p;
	}
	private static function publish_schema( $schema, $store, $force ) {
		$json = self::encode( $schema ); $digest = hash( 'sha256', $json ); $key = self::key( '', 'schema', $digest );
		$existing = $store->get( $key );
		if ( ! $force && is_array( $existing ) && $existing['retain_until'] > time() + self::ttl() ) return $existing;
		$blocks = array();
		foreach ( str_split( $json, self::BLOCK_BYTES ) as $bytes ) { $hash = hash( 'sha256', $bytes ); $blocks[] = $hash; $store->put( self::key( '', 'block', $hash ), $bytes, self::retention() ); }
		$out = array( 'sha256' => $digest, 'bytes' => strlen( $json ), 'blocks' => $blocks, 'retain_until' => time() + self::retention() );
		$store->put( $key, $out, self::retention() ); return $out;
	}
	private static function snapshot( $scope, $store, $force ) {
		$abilities = array(); $definitions = array();
		foreach ( wp_get_abilities() as $name => $a ) { if ( is_object( $a ) && method_exists( $a, 'get_name' ) ) $name = $a->get_name(); $abilities[ $name ] = $a; }
		ksort( $abilities, SORT_STRING );
		foreach ( $abilities as $name => $a ) {
			try { $definitions[ $name ] = hash( 'sha256', serialize( array( $a->get_input_schema(), $a->get_output_schema(), $a->get_meta(), $a->get_category(), $a->get_label() ) ) ); }
			catch ( Throwable $e ) { $definitions[ $name ] = 'unavailable'; }
		}
		$generation = apply_filters( 'mad4b_scp_catalog_wire_generation', self::CONTRACT . ':' . ( defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '' ) );
		$fingerprint = hash( 'sha256', self::encode( array( $definitions, $generation ) ) ); $current_key = self::key( $scope, 'current', '' ); $current = $store->get( $current_key );
		if ( ! $force && is_array( $current ) && $current['fingerprint'] === $fingerprint && $current['retain_until'] > time() + self::ttl() ) $items = $current['items'];
		else {
			$items = array();
			foreach ( $abilities as $name => $a ) {
				try {
					$source = self::publish_schema( array( 'inputSchema' => $a->get_input_schema(), 'outputSchema' => $a->get_output_schema() ), $store, $force );
					$row = array( 'ability_name' => $name, 'label' => (string) $a->get_label(), 'schema_sha256' => $source['sha256'], 'schema_bytes' => $source['bytes'], 'source' => array( 'sha256' => $source['sha256'], 'bytes' => $source['bytes'] ), 'classification_sha256' => $definitions[ $name ] );
					try {
						if ( class_exists( 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' ) ) {
							$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $a );
							if ( ! is_wp_error( $built ) ) { $dto = $built['tool']->toArray(); $wire = self::publish_schema( array( 'inputSchema' => $dto['inputSchema'], 'outputSchema' => $dto['outputSchema'] ?? null ), $store, $force ); $row['wire'] = array( 'sha256' => $wire['sha256'], 'bytes' => $wire['bytes'], 'tool_name' => $dto['name'] ); }
						}
					} catch ( Throwable $e ) { $row['wire_unavailable'] = true; }
					$classification = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $name );
					if ( ! is_wp_error( $classification ) ) $row['execution'] = array_intersect_key( $classification, array_flip( array( 'lane', 'readonly', 'execution_eligible', 'execution_blocker', 'input_schema_sha256', 'classification_sha256' ) ) );
					$items[] = $row;
				} catch ( Throwable $e ) { $items[] = array( 'ability_name' => $name, 'unavailable' => true, 'reason' => 'schema_serialization_failed' ); }
			}
			$store->put( $current_key, array( 'fingerprint' => $fingerprint, 'items' => $items, 'retain_until' => time() + self::retention() ), self::retention() );
		}
		$id = hash( 'sha256', self::encode( array( self::CONTRACT, $items ) ) ); $data = array( 'items' => $items );
		$store->put( self::key( $scope, 'snapshot', $id ), $data, self::ttl() ); $store->flush(); return array( $id, $data );
	}
	/** Prepare only the selected Ability; no full-universe serialization. */
	public static function prepare_ability( $name ) {
		if ( true !== MAD4B_SCP_Policy::can_read() ) return self::error( 'mad4b_catalog_forbidden', 403 );
		if ( ! is_string( $name ) || ! wp_has_ability( $name ) ) return self::error( 'mad4b_catalog_ability_unavailable', 404 );
		$store = new MAD4B_SCP_Catalog_Object_Store();
		try {
			$a = wp_get_ability( $name ); $scope = self::scope();
			$source = self::publish_schema( array( 'inputSchema' => $a->get_input_schema(), 'outputSchema' => $a->get_output_schema() ), $store, false );
			$item = array( 'ability_name' => $name, 'schema_sha256' => $source['sha256'], 'schema_bytes' => $source['bytes'], 'source' => array( 'sha256' => $source['sha256'], 'bytes' => $source['bytes'] ) );
			try {
				if ( class_exists( 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' ) ) {
					$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $a );
					if ( ! is_wp_error( $built ) ) { $dto = $built['tool']->toArray(); $wire = self::publish_schema( array( 'inputSchema' => $dto['inputSchema'], 'outputSchema' => $dto['outputSchema'] ?? null ), $store, false ); $item['wire'] = array( 'sha256' => $wire['sha256'], 'bytes' => $wire['bytes'], 'tool_name' => $dto['name'] ); }
				}
			} catch ( Throwable $e ) { $item['wire_unavailable'] = true; }
			$id = hash( 'sha256', self::encode( array( self::CONTRACT, $item ) ) ); $key = self::key( $scope, 'snapshot', $id );
			$store->put( $key, array( 'items' => array( $item ) ), self::ttl() ); $store->flush();
			return array( 'contract' => self::CONTRACT, 'snapshot' => $id, 'expires_at' => $store->expires( $key ), 'authority_scope_sha256' => $scope, 'item' => $item );
		} catch ( Throwable $e ) { return self::error( 'mad4b_catalog_storage_unavailable', 503 ); }
	}
	public static function capabilities() {
		return array( 'contract' => self::CONTRACT, 'authority_scope_sha256' => self::scope(), 'rest_base_url' => rest_url( 'mad4b/v1/ability-catalog/' ), 'transports' => array( 'authenticated_rest_binary', 'mcp_base64' ), 'schema_formats' => array( 'source', 'wire' ), 'block_bytes' => self::BLOCK_BYTES, 'snapshot_ttl_seconds' => self::ttl(), 'dispatch' => array( 'read' => 'mad4b/read-execute', 'write' => 'mad4b/write-execute', 'developer' => 'mad4b/developer-execute', 'enrollment' => 'mad4b/enrollment-execute' ), 'authority_effect' => 'none' );
	}
	public static function handle( array $input, $binary = false ) {
		if ( true !== MAD4B_SCP_Policy::can_read() ) return self::error( 'mad4b_catalog_forbidden', 403 );
		foreach ( array( 'snapshot', 'known_snapshot', 'schema_sha256' ) as $f ) if ( ! empty( $input[ $f ] ) && ( ! is_string( $input[ $f ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $input[ $f ] ) ) ) return self::error( 'mad4b_catalog_reference_invalid' );
		if ( isset( $input['query'] ) && ( ! is_string( $input['query'] ) || strlen( $input['query'] ) > 640 ) ) return self::error( 'mad4b_catalog_query_invalid' );
		if ( isset( $input['schema_format'] ) && ! in_array( $input['schema_format'], array( 'source', 'wire' ), true ) ) return self::error( 'mad4b_catalog_format_invalid' );
		foreach ( array( 'limit', 'chunk_index', 'chunk_bytes' ) as $f ) if ( isset( $input[ $f ] ) && ( false === filter_var( $input[ $f ], FILTER_VALIDATE_INT ) || $input[ $f ] < 0 || $input[ $f ] > 2147483647 ) ) return self::error( 'mad4b_catalog_integer_invalid' );
		$store = new MAD4B_SCP_Catalog_Object_Store();
		try {
			$scope = self::scope(); $action = $input['transport_action'] ?? 'manifest';
			if ( 'capabilities' === $action ) return self::capabilities();
			if ( 'manifest' === $action ) $result = self::manifest( $input, $scope, $store );
			elseif ( in_array( $action, array( 'schema', 'chunk' ), true ) ) $result = self::schema( $input, $scope, $store, $binary );
			else return self::error( 'mad4b_catalog_action_invalid' );
			if ( ! is_wp_error( $result ) ) $result['storage_metrics'] = $store->metrics();
			return $result;
		} catch ( Throwable $e ) { return self::error( 'mad4b_catalog_storage_unavailable', 503 ); }
	}
	private static function manifest( $input, $scope, $store ) {
		if ( ! empty( $input['cursor'] ) ) {
			$p = self::decode_cursor( $input['cursor'], $scope ); if ( is_wp_error( $p ) ) return $p;
			$id = $p['snapshot']; $data = $store->get( self::key( $scope, 'snapshot', $id ) ); if ( ! is_array( $data ) ) return self::error( 'mad4b_catalog_snapshot_expired', 410 );
		} else {
			list( $id, $data ) = self::snapshot( $scope, $store, ! empty( $input['force_refresh'] ) );
			$lease = $store->expires( self::key( $scope, 'snapshot', $id ) );
			$p = array( 'contract' => self::CONTRACT, 'scope' => $scope, 'snapshot' => $id, 'offset' => 0, 'limit' => max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) ), 'query' => strtolower( trim( $input['query'] ?? '' ) ), 'known_snapshot' => $input['known_snapshot'] ?? '', 'expires' => $lease );
		}
		$filter = static function( $items ) use ( $p ) { return array_column( array_values( array_filter( $items, static function( $item ) use ( $p ) { return '' === $p['query'] || false !== strpos( strtolower( $item['ability_name'] . ' ' . ( $item['label'] ?? '' ) ), $p['query'] ); } ) ), null, 'ability_name' ); };
		$after = $filter( $data['items'] ); $before = array(); $delta = '' !== $p['known_snapshot'];
		if ( $delta ) { $old = $store->get( self::key( $scope, 'snapshot', $p['known_snapshot'] ) ); if ( ! is_array( $old ) ) return self::error( 'mad4b_catalog_delta_base_expired', 410 ); $before = $filter( $old['items'] ); }
		$events = array(); foreach ( $before as $name => $item ) if ( ! isset( $after[ $name ] ) ) $events[ $name ] = array( 'removed' => $name );
		foreach ( $after as $name => $item ) if ( ! isset( $before[ $name ] ) || $before[ $name ] !== $item ) $events[ $name ] = array( 'item' => $item );
		ksort( $events, SORT_STRING ); $page = array_slice( array_values( $events ), $p['offset'], $p['limit'] ); $p['offset'] += count( $page );
		return array( 'contract' => self::CONTRACT, 'authority_scope_sha256' => $scope, 'snapshot' => $id, 'expires_at' => $p['expires'], 'items' => array_values( array_column( $page, 'item' ) ), 'removed' => array_values( array_column( $page, 'removed' ) ), 'total' => count( $events ), 'delta' => $delta, 'next_cursor' => $p['offset'] < count( $events ) ? self::cursor( $p ) : null, 'read_only' => true, 'authority_effect' => 'none' );
	}
	private static function schema( $input, $scope, $store, $binary ) {
		$id = $input['snapshot'] ?? ''; $digest = $input['schema_sha256'] ?? ''; $format = $input['schema_format'] ?? 'source';
		$snapshot = $store->get( self::key( $scope, 'snapshot', $id ) ); if ( ! is_array( $snapshot ) ) return self::error( 'mad4b_catalog_snapshot_expired', 410 );
		$found = false; foreach ( $snapshot['items'] as $item ) if ( ( $item[ $format ]['sha256'] ?? '' ) === $digest ) $found = true;
		if ( ! $found ) return self::error( 'mad4b_catalog_schema_not_in_snapshot', 403 );
		$d = $store->get( self::key( '', 'schema', $digest ) ); if ( ! is_array( $d ) ) return self::error( 'mad4b_catalog_schema_unavailable', 410 );
		$chunk = 'chunk' === $input['transport_action']; $size = max( 1024, min( 1048576, (int) ( $input['chunk_bytes'] ?? self::BLOCK_BYTES ) ) ); $index = (int) ( $input['chunk_index'] ?? 0 );
		$count = (int) ceil( $d['bytes'] / $size ); if ( $chunk && $index >= $count ) return self::error( 'mad4b_catalog_chunk_invalid' );
		$start = $chunk ? $index * $size : 0; $length = $chunk ? min( $size, $d['bytes'] - $start ) : $d['bytes']; $raw = '';
		for ( $n = intdiv( $start, self::BLOCK_BYTES ); $n <= intdiv( $start + $length - 1, self::BLOCK_BYTES ); ++$n ) {
			$hash = $d['blocks'][ $n ]; $bytes = $store->get( self::key( '', 'block', $hash ) ); if ( ! is_string( $bytes ) || ! hash_equals( $hash, hash( 'sha256', $bytes ) ) ) return self::error( 'mad4b_catalog_schema_corrupt', 503 );
			$from = max( 0, $start - $n * self::BLOCK_BYTES ); $take = min( strlen( $bytes ) - $from, $start + $length - ( $n * self::BLOCK_BYTES + $from ) ); $raw .= substr( $bytes, $from, $take );
		}
		if ( ! $chunk && ! hash_equals( $digest, hash( 'sha256', $raw ) ) ) return self::error( 'mad4b_catalog_schema_corrupt', 503 );
		$out = array( 'contract' => self::CONTRACT, 'snapshot' => $id, 'schema_sha256' => $digest, 'schema_bytes' => $d['bytes'], 'schema_format' => $format, 'read_only' => true );
		if ( $binary ) return $out + array( 'raw' => $raw, 'chunk_sha256' => hash( 'sha256', $raw ), 'chunk_count' => $count );
		if ( ! $chunk ) return $out + array( 'encoding' => 'utf-8', 'schema' => json_decode( $raw, false, 512, JSON_THROW_ON_ERROR ) );
		return $out + array( 'encoding' => 'base64', 'chunk_index' => $index, 'chunk_bytes' => $size, 'chunk_count' => $count, 'chunk_sha256' => hash( 'sha256', $raw ), 'data' => base64_encode( $raw ), 'next_chunk_index' => $index + 1 < $count ? $index + 1 : null );
	}
	public static function routes() { foreach ( array( 'capabilities', 'manifest', 'schemas/(?P<schema_sha256>[a-f0-9]{64})', 'schemas/(?P<schema_sha256>[a-f0-9]{64})/chunks/(?P<chunk_index>[0-9]+)' ) as $route ) register_rest_route( 'mad4b/v1', '/ability-catalog/' . $route, array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_read' ), 'permission_callback' => array( __CLASS__, 'rest_permission' ) ) ); }
	public static function rest_permission( $request ) {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return self::error( 'mad4b_catalog_forbidden', 403 );
		if ( $request->get_header( 'authorization' ) && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return self::error( 'mad4b_catalog_forbidden', 401 );
		return true === MAD4B_SCP_Policy::can_read() ? true : self::error( 'mad4b_catalog_forbidden', 403 );
	}
	public static function rest_read( $request ) {
		$path = $request->get_url_params(); $input = array_merge( $request->get_params(), $path ); $route = $request->get_route();
		$input['transport_action'] = str_ends_with( $route, '/capabilities' ) ? 'capabilities' : ( isset( $path['schema_sha256'] ) ? ( isset( $path['chunk_index'] ) ? 'chunk' : 'schema' ) : 'manifest' );
		if ( isset( $input['force_refresh'] ) ) $input['force_refresh'] = rest_sanitize_boolean( $input['force_refresh'] );
		$result = self::handle( $input, isset( $path['schema_sha256'] ) ); if ( is_wp_error( $result ) ) return $result;
		$headers = array( 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization, Cookie', 'X-Content-Type-Options' => 'nosniff' );
		if ( isset( $result['raw'] ) ) $headers += array( 'Content-Type' => 'application/octet-stream', 'X-MAD4B-Content-SHA256' => $result['chunk_sha256'], 'X-MAD4B-Schema-SHA256' => $result['schema_sha256'], 'X-MAD4B-Chunk-Count' => (string) $result['chunk_count'] );
		return new WP_REST_Response( $result['raw'] ?? $result, 200, $headers );
	}
	public static function serve_binary( $served, $response, $request, $server ) {
		if ( $served || 0 !== strpos( $request->get_route(), '/mad4b/v1/ability-catalog/schemas/' ) || 200 !== $response->get_status() || ! is_string( $response->get_data() ) ) return $served;
		if ( 'HEAD' !== $request->get_method() ) echo $response->get_data(); return true;
	}
}
