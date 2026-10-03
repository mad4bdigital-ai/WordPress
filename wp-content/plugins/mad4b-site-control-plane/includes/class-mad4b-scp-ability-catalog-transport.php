<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Private central transport. No execution and no authority grants. */
final class MAD4B_SCP_Ability_Catalog_Transport {
	const CONTRACT = 'mad4b.ability-catalog-transport.v2';
	const OBJECT_CONTRACT = 'mad4b.catalog-object-envelope.v1';
	const OBJECT_VERSION = 1;
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
	public static function wire_generation() {
		$default = self::CONTRACT . ':' . ( defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '' );
		$value = apply_filters( 'mad4b_scp_catalog_wire_generation', $default );
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 191 ) return $default;
		return trim( $value );
	}
	public static function current_authority_scope() { return self::scope(); }
	private static function scope() {
		$context = apply_filters( 'mad4b_scp_authenticated_subject_context', array() );
		if ( ! is_array( $context ) ) $context = array();
		if ( isset( $context['token_scopes'] ) && is_array( $context['token_scopes'] ) ) { $context['token_scopes'] = array_values( array_unique( array_map( 'strval', $context['token_scopes'] ) ) ); sort( $context['token_scopes'], SORT_STRING ); }
		$identity = array_intersect_key( $context, array_flip( array( 'subject_type', 'subject_fingerprint', 'issuer_fingerprint', 'client_fingerprint', 'token_scopes' ) ) );
		return hash( 'sha256', self::encode( array( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1, MAD4B_SCP_Ability_Contract_Inspector::site_binding(), get_current_user_id(), wp_get_current_user()->allcaps, $identity ) ) );
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
		if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item, $depth + 1 );
		return $value;
	}
	private static function encode( $value ) { return json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
	private static function error( $code, $status = 400 ) { return new WP_Error( $code, 'Catalog request unavailable. Refresh discovery if its revision expired.', array( 'status' => $status ) ); }
	private static function object_envelope( $kind, array $payload ) {
		return array(
			'contract' => self::OBJECT_CONTRACT,
			'version' => self::OBJECT_VERSION,
			'kind' => sanitize_key( (string) $kind ),
			'wire_generation' => self::wire_generation(),
			'payload' => $payload,
		);
	}
	private static function unwrap_object( $value, $kind ) {
		if ( ! is_array( $value )
			|| self::OBJECT_CONTRACT !== ( isset( $value['contract'] ) ? (string) $value['contract'] : '' )
			|| self::OBJECT_VERSION !== (int) ( isset( $value['version'] ) ? $value['version'] : 0 )
			|| sanitize_key( (string) $kind ) !== ( isset( $value['kind'] ) ? sanitize_key( (string) $value['kind'] ) : '' )
			|| ! isset( $value['wire_generation'] )
			|| ! hash_equals( self::wire_generation(), (string) $value['wire_generation'] )
			|| ! isset( $value['payload'] )
			|| ! is_array( $value['payload'] ) ) return false;
		return $value['payload'];
	}
	/** The pinned Adapter carries tool errors as text. Preserve a bounded contract. */
	public static function mcp_result( $result ) {
		if ( ! is_wp_error( $result ) ) return $result;
		$data = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$code = sanitize_key( $result->get_error_code() );
		$message = wp_json_encode( array( 'contract' => 'mad4b.catalog-error.v1', 'code' => $code, 'status' => $status, 'message' => 'Catalog request unavailable.' ) );
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
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
		if ( ! isset( $p['wire_generation'] ) || ! hash_equals( self::wire_generation(), (string) $p['wire_generation'] ) ) return self::error( 'mad4b_catalog_cursor_generation_mismatch', 410 );
		return $p;
	}
	private static function publish_schema( $schema, $store, $force ) {
		unset( $force );
		$json = self::encode( $schema );
		$digest = hash( 'sha256', $json );
		$key = self::key( '', 'schema', $digest );
		$retention = self::retention();
		$retain_until = time() + $retention;
		$blocks = array();
		foreach ( str_split( $json, self::BLOCK_BYTES ) as $bytes ) {
			$hash = hash( 'sha256', $bytes );
			$blocks[] = $hash;
			$store->put( self::key( '', 'block', $hash ), $bytes, $retention, $retain_until );
		}
		$out = array( 'sha256' => $digest, 'bytes' => strlen( $json ), 'blocks' => $blocks, 'retain_until' => $retain_until );
		$store->put( $key, self::object_envelope( 'schema', $out ), $retention, $retain_until );
		return $out;
	}
	private static function snapshot( $scope, $store, $force ) {
		$lock = MAD4B_SCP_Distributed_Lock::catalog_name( $scope );
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			return self::build_snapshot( $scope, $store, $force, $lock );
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}
	private static function build_snapshot( $scope, $store, $force, $lock ) {
		$deadline = microtime( true ) + max( 0.001, min( 30, (float) apply_filters( 'mad4b_scp_catalog_build_seconds', 10 ) ) );
		$max_abilities = max( 1, min( 10000, (int) apply_filters( 'mad4b_scp_catalog_build_max_abilities', 5000 ) ) );
		$max_bytes = max( 1024, min( 67108864, (int) apply_filters( 'mad4b_scp_catalog_build_max_bytes', 33554432 ) ) );
		$definition_bytes = 0;
		$abilities = array(); $definitions = array(); $definition_failures = array();
		foreach ( wp_get_abilities() as $name => $a ) { if ( is_object( $a ) && method_exists( $a, 'get_name' ) ) $name = $a->get_name(); $abilities[ $name ] = $a; }
		if ( count( $abilities ) > $max_abilities ) return self::error( 'mad4b_catalog_build_ability_budget', 413 );
		ksort( $abilities, SORT_STRING );
		foreach ( $abilities as $name => $a ) {
			if ( microtime( true ) >= $deadline ) return self::error( 'mad4b_catalog_build_time_budget', 503 );
			try {
				$definition = array( $a->get_input_schema(), $a->get_output_schema(), $a->get_meta(), $a->get_category(), $a->get_label() );
				$encoded = self::encode( $definition );
				$definition_bytes += strlen( $encoded );
				if ( $definition_bytes > $max_bytes ) return self::error( 'mad4b_catalog_build_byte_budget', 413 );
				$definitions[ $name ] = hash( 'sha256', $encoded );
			} catch ( Throwable $e ) {
				$definition_failures[ $name ] = 'schema_serialization_failed';
				$marker = self::encode( array(
					'contract' => 'mad4b.catalog-definition-unavailable.v1',
					'ability_name' => (string) $name,
					'reason' => 'schema_serialization_failed',
				) );
				$definitions[ $name ] = hash( 'sha256', $marker );
			}
		}
		$generation = self::wire_generation();
		$fingerprint = hash( 'sha256', self::encode( array( $definitions, $generation ) ) ); $current_key = self::key( $scope, 'current', '' ); $current = self::unwrap_object( $store->get( $current_key ), 'current' );
		$force_interval = max( 1, min( 300, (int) apply_filters( 'mad4b_scp_catalog_force_refresh_interval', 30 ) ) );
		if ( is_array( $current ) && (int) ( $current['built_at'] ?? 0 ) > time() - $force_interval ) $force = false;
		if ( ! $force && is_array( $current ) && $current['fingerprint'] === $fingerprint && $current['retain_until'] > time() + self::ttl() ) $items = $current['items'];
		else {
			$items = array(); $retain_until = time() + self::retention();
			foreach ( $abilities as $name => $a ) {
				if ( microtime( true ) >= $deadline ) return self::error( 'mad4b_catalog_build_time_budget', 503 );
				if ( isset( $definition_failures[ $name ] ) ) {
					$items[] = array(
						'ability_name' => $name,
						'unavailable' => true,
						'reason' => $definition_failures[ $name ],
						'classification_sha256' => $definitions[ $name ],
					);
					continue;
				}
				try {
					$source = self::publish_schema( array( 'inputSchema' => $a->get_input_schema(), 'outputSchema' => $a->get_output_schema() ), $store, $force );
					$retain_until = min( $retain_until, $source['retain_until'] );
					$row = array( 'ability_name' => $name, 'label' => (string) $a->get_label(), 'schema_sha256' => $source['sha256'], 'schema_bytes' => $source['bytes'], 'source' => array( 'sha256' => $source['sha256'], 'bytes' => $source['bytes'] ), 'classification_sha256' => $definitions[ $name ] );
					try {
						if ( class_exists( 'WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool' ) ) {
							$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $a );
							if ( ! is_wp_error( $built ) ) { $dto = $built['tool']->toArray(); $wire = self::publish_schema( array( 'inputSchema' => $dto['inputSchema'], 'outputSchema' => $dto['outputSchema'] ?? null ), $store, $force ); $row['wire'] = array( 'sha256' => $wire['sha256'], 'bytes' => $wire['bytes'], 'tool_name' => $dto['name'] ); }
						}
					} catch ( Throwable $e ) { $row['wire_unavailable'] = true; }
					$classification = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
					if ( ! is_wp_error( $classification ) ) {
						$row['execution'] = array_intersect_key( $classification, array_flip( array( 'lane', 'readonly', 'execution_eligible', 'execution_blocker', 'input_schema_sha256', 'classification_sha256' ) ) );
						if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) ) $row['execution'] += MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $classification );
					}
					$items[] = $row;
				} catch ( Throwable $e ) { $items[] = array( 'ability_name' => $name, 'unavailable' => true, 'reason' => 'schema_serialization_failed' ); }
			}
			$store->put( $current_key, self::object_envelope( 'current', array( 'fingerprint' => $fingerprint, 'items' => $items, 'retain_until' => $retain_until, 'built_at' => time() ) ), self::retention() );
		}
		$id = hash( 'sha256', self::encode( array( self::CONTRACT, $items ) ) ); $data = array( 'items' => $items );
		if ( microtime( true ) >= $deadline ) return self::error( 'mad4b_catalog_build_time_budget', 503 );
		if ( ! MAD4B_SCP_Distributed_Lock::owns( $lock ) ) return self::error( 'mad4b_catalog_build_lock_lost', 503 );
		$store->put( self::key( $scope, 'snapshot', $id ), self::object_envelope( 'snapshot', $data ), self::ttl() ); $store->flush(); return array( $id, $data );
	}
	/** Prepare only the selected Ability; no full-universe serialization. */
	public static function prepare_ability( $name ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return self::error( 'mad4b_catalog_blog_switch_denied', 409 );
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
			$store->put( $key, self::object_envelope( 'snapshot', array( 'items' => array( $item ) ) ), self::ttl() ); $store->flush();
			return array( 'contract' => self::CONTRACT, 'snapshot' => $id, 'expires_at' => $store->expires( $key ), 'authority_scope_sha256' => $scope, 'item' => $item );
		} catch ( Throwable $e ) { return self::error( 'mad4b_catalog_storage_unavailable', 503 ); }
	}
	public static function capabilities() {
		return array( 'contract' => self::CONTRACT, 'authority_scope_sha256' => self::scope(), 'rest_base_url' => rest_url( 'mad4b/v1/ability-catalog/' ), 'transports' => array( 'authenticated_rest_binary', 'mcp_base64' ), 'schema_formats' => array( 'source', 'wire' ), 'block_bytes' => self::BLOCK_BYTES, 'snapshot_ttl_seconds' => self::ttl(), 'wire_generation' => self::wire_generation(), 'object_envelope' => array( 'contract' => self::OBJECT_CONTRACT, 'version' => self::OBJECT_VERSION ), 'rest_auth_modes' => array( 'oauth_bearer', 'authenticated_wordpress_session' ), 'remote_client_auth_mode' => 'oauth_bearer', 'build_policy' => array( 'single_flight' => true, 'force_refresh_min_interval_seconds' => 30, 'default_max_abilities' => 5000, 'default_max_seconds' => 10, 'default_max_bytes' => 33554432 ), 'dispatch' => array( 'read' => 'mad4b/read-execute', 'write' => 'mad4b/write-execute', 'developer' => 'mad4b/developer-execute', 'enrollment' => 'mad4b/enrollment-execute' ), 'authority_effect' => 'none' );
	}
	public static function handle( array $input, $binary = false ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return self::error( 'mad4b_catalog_blog_switch_denied', 409 );
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
			$id = $p['snapshot']; $data = self::unwrap_object( $store->get( self::key( $scope, 'snapshot', $id ) ), 'snapshot' ); if ( ! is_array( $data ) ) return self::error( 'mad4b_catalog_object_generation_mismatch', 410 );
		} else {
			$snapshot = self::snapshot( $scope, $store, ! empty( $input['force_refresh'] ) );
			if ( is_wp_error( $snapshot ) ) return $snapshot;
			list( $id, $data ) = $snapshot;
			$lease = $store->expires( self::key( $scope, 'snapshot', $id ) );
			$p = array( 'contract' => self::CONTRACT, 'wire_generation' => self::wire_generation(), 'scope' => $scope, 'snapshot' => $id, 'offset' => 0, 'limit' => max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) ), 'query' => strtolower( trim( $input['query'] ?? '' ) ), 'known_snapshot' => $input['known_snapshot'] ?? '', 'expires' => $lease );
		}
		$filter = static function( $items ) use ( $p ) { return array_column( array_values( array_filter( $items, static function( $item ) use ( $p ) { return '' === $p['query'] || false !== strpos( strtolower( $item['ability_name'] . ' ' . ( $item['label'] ?? '' ) ), $p['query'] ); } ) ), null, 'ability_name' ); };
		$after = $filter( $data['items'] ); $before = array(); $delta = '' !== $p['known_snapshot'];
		if ( $delta ) { $old = self::unwrap_object( $store->get( self::key( $scope, 'snapshot', $p['known_snapshot'] ) ), 'snapshot' ); if ( ! is_array( $old ) ) return self::error( 'mad4b_catalog_delta_base_expired', 410 ); $before = $filter( $old['items'] ); }
		$events = array(); foreach ( $before as $name => $item ) if ( ! isset( $after[ $name ] ) ) $events[ $name ] = array( 'removed' => $name );
		foreach ( $after as $name => $item ) if ( ! isset( $before[ $name ] ) || $before[ $name ] !== $item ) $events[ $name ] = array( 'item' => $item );
		ksort( $events, SORT_STRING ); $page = array_slice( array_values( $events ), $p['offset'], $p['limit'] ); $p['offset'] += count( $page );
		return array( 'contract' => self::CONTRACT, 'authority_scope_sha256' => $scope, 'snapshot' => $id, 'expires_at' => $p['expires'], 'items' => array_values( array_column( $page, 'item' ) ), 'removed' => array_values( array_column( $page, 'removed' ) ), 'total' => count( $events ), 'delta' => $delta, 'next_cursor' => $p['offset'] < count( $events ) ? self::cursor( $p ) : null, 'read_only' => true, 'authority_effect' => 'none' );
	}
	private static function schema( $input, $scope, $store, $binary ) {
		$id = $input['snapshot'] ?? ''; $digest = $input['schema_sha256'] ?? ''; $format = $input['schema_format'] ?? 'source';
		$snapshot = self::unwrap_object( $store->get( self::key( $scope, 'snapshot', $id ) ), 'snapshot' ); if ( ! is_array( $snapshot ) ) return self::error( 'mad4b_catalog_object_generation_mismatch', 410 );
		$found = false; foreach ( $snapshot['items'] as $item ) if ( ( $item[ $format ]['sha256'] ?? '' ) === $digest ) $found = true;
		if ( ! $found ) return self::error( 'mad4b_catalog_schema_not_in_snapshot', 403 );
		$d = self::unwrap_object( $store->get( self::key( '', 'schema', $digest ) ), 'schema' ); if ( ! is_array( $d ) ) return self::error( 'mad4b_catalog_object_generation_mismatch', 410 );
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
		$input['transport_action'] = '/capabilities' === substr( $route, -13 ) ? 'capabilities' : ( isset( $path['schema_sha256'] ) ? ( isset( $path['chunk_index'] ) ? 'chunk' : 'schema' ) : 'manifest' );
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
