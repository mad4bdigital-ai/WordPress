<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Versioned, private discovery transport. Never executes an Ability or grants authority. */
final class MAD4B_SCP_Ability_Catalog_Transport {
	const CONTRACT = 'mad4b.ability-catalog-transport.v1';

	private static function scope() {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) $identity = array();
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? array_values( array_unique( array_map( 'strval', $identity['token_scopes'] ) ) ) : array();
		sort( $scopes, SORT_STRING );
		$allcaps = wp_get_current_user()->allcaps;
		if ( is_array( $allcaps ) ) ksort( $allcaps, SORT_STRING );
		return hash( 'sha256', wp_json_encode( array(
			MAD4B_SCP_ChatGPT_Tool_Projection::current_binding(),
			get_current_user_id(),
			$allcaps,
			isset( $identity['subject_fingerprint'] ) ? (string) $identity['subject_fingerprint'] : '',
			isset( $identity['issuer_fingerprint'] ) ? (string) $identity['issuer_fingerprint'] : '',
			isset( $identity['client_fingerprint'] ) ? (string) $identity['client_fingerprint'] : '',
			$scopes,
			class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false ) && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ),
		) ) );
	}
	private static function key( $scope, $kind, $digest ) {
		return 'mad4b_ct_' . hash( 'sha256', $scope . ':' . $kind . ':' . $digest );
	}
	private static function ttl() { return max( 60, (int) apply_filters( 'mad4b_scp_catalog_snapshot_ttl', 3600 ) ); }
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonical( $item );
		return $value;
	}
	private static function encode( $value ) { return json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
	private static function cursor( array $payload ) {
		$encoded = rtrim( strtr( base64_encode( self::encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}
	private static function decode_cursor( $cursor, $scope ) {
		$parts = explode( '.', $cursor );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) return new WP_Error( 'mad4b_catalog_cursor_invalid', 'Invalid catalog cursor.' );
		$data = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		if ( ! is_array( $data ) || ( $data['scope'] ?? '' ) !== $scope || ( $data['expires'] ?? 0 ) < time() || ! isset( $data['snapshot'], $data['offset'], $data['limit'], $data['query'], $data['known_snapshot'] ) ) return new WP_Error( 'mad4b_catalog_cursor_expired', 'Cursor expired or belongs to another authority context. Restart discovery.' );
		return $data;
	}
	private static function snapshot( $scope ) {
		$items = array();
		$abilities = array();
		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) $name = $ability->get_name();
			$abilities[ (string) $name ] = $ability;
		}
		ksort( $abilities, SORT_STRING );
		foreach ( $abilities as $name => $ability ) {
			try {
				$json = self::encode( array( 'inputSchema' => $ability->get_input_schema(), 'outputSchema' => $ability->get_output_schema() ) );
				$digest = hash( 'sha256', $json );
				if ( ! set_transient( self::key( $scope, 'schema', $digest ), $json, self::ttl() ) && get_transient( self::key( $scope, 'schema', $digest ) ) !== $json ) throw new RuntimeException( 'schema_cache_unavailable' );
				$items[] = array( 'ability_name' => (string) $name, 'label' => (string) $ability->get_label(), 'schema_sha256' => $digest, 'schema_bytes' => strlen( $json ), 'classification_sha256' => hash( 'sha256', self::encode( array( $ability->get_meta(), $ability->get_category() ) ) ) );
			} catch ( Throwable $error ) { $items[] = array( 'ability_name' => (string) $name, 'unavailable' => true, 'reason' => 'schema_serialization_or_storage_failed' ); }
		}
		$id = hash( 'sha256', self::encode( $items ) );
		$data = array( 'items' => $items, 'expires' => time() + self::ttl() );
		if ( ! set_transient( self::key( $scope, 'snapshot', $id ), $data, self::ttl() ) && get_transient( self::key( $scope, 'snapshot', $id ) ) !== $data ) return new WP_Error( 'mad4b_catalog_storage_unavailable', 'Cannot retain catalog snapshot.' );
		return array( $id, $data );
	}
	public static function prepare_ability( $ability_name ) {
		if ( ! MAD4B_SCP_Policy::can_read() ) return new WP_Error( 'mad4b_catalog_forbidden', 'Catalog read authority required.' );
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_catalog_ability_unavailable', 'Requested Ability is not registered in the current runtime.' );
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' ) || ! method_exists( $ability, 'get_output_schema' ) ) {
			return new WP_Error( 'mad4b_catalog_ability_contract_unavailable', 'Requested Ability does not expose a serializable schema contract.' );
		}
		$scope = self::scope();
		try {
			$json = self::encode( array( 'inputSchema' => $ability->get_input_schema(), 'outputSchema' => $ability->get_output_schema() ) );
			$digest = hash( 'sha256', $json );
			if ( ! set_transient( self::key( $scope, 'schema', $digest ), $json, self::ttl() ) && get_transient( self::key( $scope, 'schema', $digest ) ) !== $json ) {
				return new WP_Error( 'mad4b_catalog_storage_unavailable', 'Cannot retain the selected Ability schema.' );
			}
			$item = array(
				'ability_name' => $ability_name,
				'label' => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $ability_name,
				'schema_sha256' => $digest,
				'schema_bytes' => strlen( $json ),
				'classification_sha256' => hash( 'sha256', self::encode( array(
					method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array(),
					method_exists( $ability, 'get_category' ) ? $ability->get_category() : '',
				) ) ),
			);
			$id = hash( 'sha256', self::encode( array( 'lazy_single_ability' => true, 'item' => $item ) ) );
			$data = array( 'items' => array( $item ), 'expires' => time() + self::ttl() );
			if ( ! set_transient( self::key( $scope, 'snapshot', $id ), $data, self::ttl() ) && get_transient( self::key( $scope, 'snapshot', $id ) ) !== $data ) {
				return new WP_Error( 'mad4b_catalog_storage_unavailable', 'Cannot retain the selected Ability schema snapshot.' );
			}
			return array(
				'contract' => self::CONTRACT,
				'snapshot' => $id,
				'expires_at' => $data['expires'],
				'item' => $item,
				'lazy_single_ability' => true,
				'read_only' => true,
				'authority_effect' => 'none',
			);
		} catch ( Throwable $error ) {
			return new WP_Error( 'mad4b_catalog_schema_serialization_failed', 'Selected Ability schema could not be serialized.' );
		}
	}

	public static function handle( array $input ) {
		if ( ! MAD4B_SCP_Policy::can_read() ) return new WP_Error( 'mad4b_catalog_forbidden', 'Catalog read authority required.' );
		$scope = self::scope();
		$action = $input['transport_action'] ?? 'manifest';
		if ( 'manifest' === $action ) return self::manifest( $input, $scope );
		if ( ! in_array( $action, array( 'schema', 'chunk' ), true ) ) return new WP_Error( 'mad4b_catalog_action_invalid', 'Unknown catalog transport action.' );
		$id = (string) ( $input['snapshot'] ?? '' ); $digest = (string) ( $input['schema_sha256'] ?? '' );
		$snapshot = get_transient( self::key( $scope, 'snapshot', $id ) );
		if ( ! is_array( $snapshot ) ) return new WP_Error( 'mad4b_catalog_snapshot_expired', 'Snapshot unavailable. Restart manifest discovery.' );
		$found = false;
		foreach ( $snapshot['items'] as $item ) if ( ( $item['schema_sha256'] ?? '' ) === $digest ) { $found = true; break; }
		if ( ! $found ) return new WP_Error( 'mad4b_catalog_schema_not_in_snapshot', 'Schema is not part of this authorized snapshot.' );
		$json = get_transient( self::key( $scope, 'schema', $digest ) );
		if ( ! is_string( $json ) || ! hash_equals( $digest, hash( 'sha256', $json ) ) ) return new WP_Error( 'mad4b_catalog_schema_unavailable', 'Schema cache unavailable or corrupt. Refresh the manifest.' );
		$out = array( 'contract' => self::CONTRACT, 'snapshot' => $id, 'schema_sha256' => $digest, 'schema_bytes' => strlen( $json ), 'encoding' => 'utf-8', 'read_only' => true );
		if ( 'schema' === $action ) { $out['schema'] = json_decode( $json, true ); return $out; }
		$size = max( 1024, min( 1048576, (int) ( $input['chunk_bytes'] ?? 32768 ) ) );
		$index = max( 0, (int) ( $input['chunk_index'] ?? 0 ) );
		$count = (int) ceil( strlen( $json ) / $size );
		if ( $index >= $count ) return new WP_Error( 'mad4b_catalog_chunk_invalid', 'Chunk index out of range.' );
		$bytes = substr( $json, $index * $size, $size );
		return array_merge( $out, array( 'encoding' => 'base64', 'chunk_index' => $index, 'chunk_bytes' => $size, 'chunk_count' => $count, 'chunk_sha256' => hash( 'sha256', $bytes ), 'data' => base64_encode( $bytes ), 'next_chunk_index' => $index + 1 < $count ? $index + 1 : null ) );
	}
	private static function manifest( array $input, $scope ) {
		if ( ! empty( $input['cursor'] ) ) {
			$p = self::decode_cursor( (string) $input['cursor'], $scope );
			if ( is_wp_error( $p ) ) return $p;
			$id = $p['snapshot']; $data = get_transient( self::key( $scope, 'snapshot', $id ) );
			if ( ! is_array( $data ) ) return new WP_Error( 'mad4b_catalog_snapshot_expired', 'Snapshot unavailable. Restart manifest discovery.' );
		} else {
			$built = self::snapshot( $scope ); if ( is_wp_error( $built ) ) return $built;
			list( $id, $data ) = $built;
			$p = array( 'scope' => $scope, 'snapshot' => $id, 'offset' => 0, 'limit' => max( 1, min( 100, (int) ( $input['limit'] ?? 50 ) ) ), 'query' => strtolower( trim( (string) ( $input['query'] ?? '' ) ) ), 'known_snapshot' => (string) ( $input['known_snapshot'] ?? '' ), 'expires' => $data['expires'] );
		}
		$items = $data['items']; $removed = array(); $delta = false;
		if ( '' !== $p['known_snapshot'] ) {
			$old = get_transient( self::key( $scope, 'snapshot', $p['known_snapshot'] ) );
			if ( ! is_array( $old ) ) return new WP_Error( 'mad4b_catalog_delta_base_expired', 'Delta base expired. Request a full manifest.' );
			$before = array_column( $old['items'], null, 'ability_name' ); $after = array_column( $items, null, 'ability_name' );
			$removed = array_values( array_diff( array_keys( $before ), array_keys( $after ) ) );
			$items = array_values( array_filter( $items, static function( $item ) use ( $before ) { return ! isset( $before[ $item['ability_name'] ] ) || $before[ $item['ability_name'] ] !== $item; } ) ); $delta = true;
		}
		if ( '' !== $p['query'] ) $items = array_values( array_filter( $items, static function( $item ) use ( $p ) { return false !== strpos( strtolower( $item['ability_name'] . ' ' . ( $item['label'] ?? '' ) ), $p['query'] ); } ) );
		$page = array_slice( $items, $p['offset'], $p['limit'] ); $p['offset'] += count( $page );
		return array( 'contract' => self::CONTRACT, 'snapshot' => $id, 'expires_at' => $p['expires'], 'items' => $page, 'total' => count( $items ), 'delta' => $delta, 'removed' => $removed, 'next_cursor' => $p['offset'] < count( $items ) ? self::cursor( $p ) : null, 'read_only' => true, 'authority_effect' => 'none' );
	}
}
