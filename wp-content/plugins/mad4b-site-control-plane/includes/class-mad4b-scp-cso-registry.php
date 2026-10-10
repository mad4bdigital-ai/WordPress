<?php
/** Native Ability descriptors; an observed storage name never creates authority. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CSO_Registry {
	const CONTRACT = 'mad4b.cso01.registry.v1';
	const CONSUMER = 'cso01_registry';
	const MAX_ABILITIES = 4096;
	const MAX_SCHEMA_BYTES = 131072;
	public static function describe( $name, array $target = array() ) {
		try { return self::describe_current( $name, $target, true ); } catch ( Throwable $error ) { return self::failure( 'descriptor_unavailable' ); }
	}
	private static function admit() {
		if ( ! MAD4B_SCP_CSO_Scope::enabled( 'discovery' ) || ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() ) return self::failure( 'discovery_disabled' );
		foreach ( array( 'MAD4B_SCP_Capability_Descriptor_Registry', 'MAD4B_SCP_Ability_Contract_Inspector' ) as $class ) if ( ! class_exists( $class ) ) return self::failure( 'descriptor_unavailable' );
		if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) return self::failure( 'descriptor_unavailable' );
		return MAD4B_SCP_CSO_Scope::current();
	}
	private static function describe_current( $name, array $target, $prepare ) {
		$scope = self::admit(); if ( is_wp_error( $scope ) ) return $scope;
		if ( ! is_string( $name ) || 1 !== preg_match( '#^[a-z0-9][a-z0-9._-]{0,95}/[a-z0-9][a-z0-9._-]{0,95}$#D', $name ) || self::fixed_dispatcher( $name ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $target ) || ! wp_has_ability( $name ) ) return self::failure( 'descriptor_unavailable' );
		$ability = wp_get_ability( $name );
		foreach ( array( 'get_input_schema', 'get_output_schema', 'get_meta', 'check_permissions', 'execute' ) as $method ) if ( ! is_object( $ability ) || ! method_exists( $ability, $method ) ) return self::failure( 'descriptor_unavailable' );
		$input = $ability->get_input_schema(); $output = $ability->get_output_schema(); $meta = $ability->get_meta();
		if ( ! self::schema_valid( $input ) || ! self::schema_valid( $output ) || ! is_array( $meta ) || true !== MAD4B_SCP_CSO_Scope::bounded( $meta, 65536 ) ) return self::failure( 'schema_unsupported' );
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $name );
		if ( is_wp_error( $row ) || true !== ( $row['projection_eligible'] ?? null ) || true === ( $row['breakglass'] ?? true ) ) return self::failure( 'descriptor_unavailable' );
		$read = 'read' === ( $row['lane'] ?? '' ) && true === ( $row['readonly'] ?? null );
		$write = in_array( $row['lane'] ?? '', array( 'write', 'content', 'admin' ), true ) && false === ( $row['readonly'] ?? null );
		if ( ! $read && ! $write ) return self::failure( 'descriptor_unavailable' );
		if ( $read ) {
			if ( true !== $ability->check_permissions( $target ? $target : null ) ) return self::failure( 'descriptor_unavailable' );
		} elseif ( ! method_exists( 'MAD4B_SCP_Policy', 'can_plan_mutations' ) || true !== MAD4B_SCP_Policy::can_plan_mutations() || true !== ( $row['execution_boundary_verified'] ?? null ) ) return self::failure( 'descriptor_unavailable' );
		// Never invoke mutation permission callbacks to discover schemas: they may consume approval.
		$binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $name, self::CONSUMER ); if ( is_wp_error( $binding ) ) return self::failure( 'descriptor_unavailable' );
		$provider = self::provider( $row );
		$mounted = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'ability_is_mounted' ) && true === MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-' . $row['lane'], $name );
		$native_route = true === ( $row['execution_eligible'] ?? null ) && ( $read || $mounted );
		$raw = isset( $meta['mad4b_cso'] ) && is_array( $meta['mad4b_cso'] ) ? $meta['mad4b_cso'] : array();
		if ( is_array( $input ) && isset( $input['x-cso'] ) && is_array( $input['x-cso'] ) ) $raw = array_replace( $raw, $input['x-cso'] );
		$kind = isset( $raw['storage_kind'] ) && is_string( $raw['storage_kind'] ) ? $raw['storage_kind'] : 'native_ability';
		$trusted = $mounted && 'native_ability' === $kind;
		$fields = $trusted ? self::fields( $input, $raw ) : array(); if ( is_wp_error( $fields ) ) return $fields;
		$readback = $trusted && $write && isset( $raw['readback'] ) && is_array( $raw['readback'] ) ? self::readback( $name, $input, $raw['readback'] ) : null;
		$writable = $write && $native_route && $trusted && is_array( $readback ) && isset( $readback['revision_input'] );
		$status = 'native_ability' !== $kind ? 'UNSUPPORTED' : ( $writable ? 'WRITE_CANDIDATE' : ( $write ? 'PLAN_ONLY' : 'READ_ONLY' ) );
		$schema = MAD4B_SCP_CSO_Scope::digest( array( 'input_schema' => $input, 'output_schema' => $output ) );
		$adapter = MAD4B_SCP_CSO_Scope::digest( array( 'provider' => $provider, 'capability_binding' => $binding, 'field_metadata' => $fields, 'storage_kind' => $kind, 'native_route_available' => $native_route, 'readback' => $readback ) );
		if ( is_wp_error( $schema ) || is_wp_error( $adapter ) ) return self::failure( 'schema_unsupported' );
		$result = array( 'contract' => self::CONTRACT, 'ability_name' => $name, 'label' => $row['label'] ?? '', 'description' => $row['description'] ?? '', 'input_schema' => $input, 'output_schema' => $output, 'schema_sha256' => $schema, 'adapter_sha256' => $adapter, 'capability_binding' => $binding, 'scope' => $scope, 'lane' => $row['lane'], 'read_only' => $read, 'native_route_available' => $native_route, 'provider' => $provider, 'provenance' => $provider, 'storage_kind' => $kind, 'storage_status' => $status, 'certification_status' => $mounted ? 'existing_governed_mount' : 'unmapped', 'cso_conformance_certified' => false, 'metadata_trusted' => $trusted, 'write_supported' => $writable, 'writable_by_adapter' => $writable, 'field_metadata' => $fields, 'readback' => $readback, 'visibility' => 'permission_filtered', 'authority_revalidation_required' => true, 'authorizing' => false, 'mutation_performed' => false );
		if ( $prepare && $native_route ) {
			if ( ! class_exists( 'MAD4B_SCP_Preparation_Receipt' ) || ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ) return self::failure( 'preparation_unavailable' );
			$receipt = MAD4B_SCP_Preparation_Receipt::issue( $row ); $authority = MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope();
			if ( is_wp_error( $receipt ) || ! is_string( $receipt ) || '' === $receipt || strlen( $receipt ) > 4096 || ! self::sha( $authority ) ) return self::failure( 'preparation_unavailable' );
			$result['preparation'] = array( 'scope' => $scope, 'schema_sha256' => $schema, 'adapter_sha256' => $adapter, 'capability_binding' => $binding, 'expected_input_schema_sha256' => $row['input_schema_sha256'], 'expected_execution_lane' => $row['execution_lane'], 'expected_classification_sha256' => $row['classification_sha256'], 'expected_authority_scope_sha256' => $authority, 'preparation_receipt' => $receipt );
		}
		if ( is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $scope ) ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $result, false ) ) return self::failure( 'descriptor_changed' );
		return $result;
	}
	/** Count and page only after permission filtering; private names/counts are never emitted. */
	public static function catalog( $query = '', $limit = 20, $offset = 0 ) {
		try {
			$scope = self::admit(); if ( is_wp_error( $scope ) ) return $scope;
			if ( ! is_string( $query ) || strlen( $query ) > 640 || 1 !== preg_match( '//u', $query ) || preg_match_all( '/./us', $query ) > 160 || ! is_int( $limit ) || $limit < 1 || $limit > 50 || ! is_int( $offset ) || $offset < 0 || $offset > self::MAX_ABILITIES || true !== MAD4B_SCP_CSO_Scope::safe_data( $query ) || ! function_exists( 'wp_get_abilities' ) ) return self::failure( 'catalog_budget' );
			$abilities = wp_get_abilities(); if ( ! is_array( $abilities ) || count( $abilities ) > self::MAX_ABILITIES ) return self::failure( 'catalog_budget' );
			$names = array(); foreach ( $abilities as $key => $ability ) { $name = is_string( $key ) ? $key : ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '' ); if ( is_string( $name ) ) $names[] = $name; }
			sort( $names, SORT_STRING ); $items = array(); $seen = 0; $more = false; $deadline = microtime( true ) + 5;
			foreach ( $names as $name ) {
				if ( microtime( true ) > $deadline ) return self::failure( 'catalog_budget' );
				$row = self::describe_current( $name, array(), false ); if ( is_wp_error( $row ) ) continue;
				if ( '' !== $query && false === stripos( $row['ability_name'] . ' ' . $row['label'] . ' ' . $row['description'], $query ) ) continue;
				if ( $seen++ < $offset ) continue; if ( count( $items ) === $limit ) { $more = true; break; }
				$items[] = array_intersect_key( $row, array_fill_keys( array( 'ability_name', 'label', 'description', 'lane', 'read_only', 'native_route_available', 'schema_sha256', 'adapter_sha256', 'storage_kind', 'storage_status', 'certification_status', 'write_supported', 'metadata_trusted', 'provider', 'authorizing' ), true ) );
			}
			if ( is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $scope ) ) ) return self::failure( 'scope_changed' );
			return array( 'contract' => 'mad4b.cso01.catalog.v1', 'scope' => $scope, 'items' => $items, 'count' => count( $items ), 'query' => $query, 'limit' => $limit, 'offset' => $offset, 'has_more' => $more, 'next_offset' => $more ? $offset + count( $items ) : null, 'non_exhaustive' => true, 'read_only' => true, 'mutation_performed' => false, 'authorizing' => false );
		} catch ( Throwable $error ) { return self::failure( 'catalog_unavailable' ); }
	}
	/** Exactly prepared native read dispatcher; no target callback bypass or synthesized parent. */
	public static function read( $name, array $params, array $preparation ) {
		try {
			if ( ! isset( $preparation['scope'] ) || ! is_array( $preparation['scope'] ) || is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $preparation['scope'] ) ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $params ) || true !== MAD4B_SCP_CSO_Scope::bounded( $preparation, 32768 ) ) return self::failure( 'preparation_changed' );
			$row = self::describe_current( $name, $params, false );
			if ( is_wp_error( $row ) || 'read' !== $row['lane'] || true !== $row['read_only'] || true !== $row['native_route_available'] || ! self::same_preparation( $name, $row, $preparation ) || ! self::values_safe( $params, $row['input_schema'] ) ) return self::failure( 'read_denied' );
			$input = array( 'ability_name' => $name, 'input' => $params );
			foreach ( array( 'expected_input_schema_sha256', 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' ) as $field ) { if ( ! isset( $preparation[ $field ] ) || ! is_string( $preparation[ $field ] ) || '' === $preparation[ $field ] || strlen( $preparation[ $field ] ) > 4096 ) return self::failure( 'preparation_changed' ); $input[ $field ] = $preparation[ $field ]; }
			$dispatcher = wp_get_ability( 'mad4b/read-execute' ); if ( ! is_object( $dispatcher ) || ! method_exists( $dispatcher, 'execute' ) ) return self::failure( 'read_dispatch_unavailable' );
			$call = static function () use ( $dispatcher, $input ) { return $dispatcher->execute( $input ); };
			$result = class_exists( 'MAD4B_SCP_Execution_Fence' ) && MAD4B_SCP_Execution_Fence::has_active_frame() ? MAD4B_SCP_Execution_Fence::with_governed_child( 'mad4b/read-execute', $input, $call, 'fixed_dispatch' ) : $call();
			if ( is_wp_error( $result ) || ! is_array( $result ) || ( $result['ability_name'] ?? '' ) !== $name || true !== ( $result['read_only'] ?? null ) || false !== ( $result['mutation_performed'] ?? null ) || ! array_key_exists( 'result', $result ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $result['result'] ) || ! self::values_safe( $result['result'], $row['output_schema'] ) ) return self::failure( 'read_result_denied' );
			$after = self::describe_current( $name, $params, false );
			if ( is_wp_error( $after ) || ! self::same_preparation( $name, $after, $preparation ) || is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $preparation['scope'] ) ) ) return self::failure( 'read_changed' );
			return array( 'contract' => 'mad4b.cso01.native-read.v1', 'ability_name' => $name, 'scope' => $row['scope'], 'schema_sha256' => $row['schema_sha256'], 'result' => $result['result'], 'read_only' => true, 'mutation_performed' => false, 'authorizing' => false );
		} catch ( Throwable $error ) { return self::failure( 'read_unavailable' ); }
	}
	private static function same_preparation( $name, array $row, array $prep ) {
		foreach ( array( 'schema_sha256', 'adapter_sha256' ) as $field ) if ( ! self::sha( $prep[ $field ] ?? '' ) || ! hash_equals( $row[ $field ], $prep[ $field ] ) ) return false;
		return isset( $prep['capability_binding'] ) && is_array( $prep['capability_binding'] ) && ! is_wp_error( MAD4B_SCP_Capability_Descriptor_Registry::assert_binding( $name, $prep['capability_binding'], self::CONSUMER ) );
	}
	private static function fields( $input, array $raw ) {
		$fields = $raw['fields'] ?? array(); if ( ! is_array( $fields ) || count( $fields ) > 256 || true !== MAD4B_SCP_CSO_Scope::bounded( $fields, 32768 ) ) return self::failure( 'field_metadata_unsupported' );
		$allowed = array_fill_keys( array( 'sensitivity', 'visible_when', 'required_when', 'suggestion', 'help', 'storage_kind', 'visibility', 'label', 'side_effects', 'sanitizer', 'hooks', 'allowed_hosts', 'field_type', 'resource_type', 'post_type', 'taxonomy', 'exclusive_with' ), true ); $out = array();
		foreach ( $fields as $path => $field ) {
			$shape = self::schema_path( $input, $path, false ); if ( ! is_array( $field ) || null === $shape ) return self::failure( 'field_metadata_unsupported' );
			$field = array_intersect_key( $field, $allowed );
			if ( isset( $field['sensitivity'] ) && ! in_array( $field['sensitivity'], array( 'PUBLIC', 'SITE_INTERNAL', 'PERSONAL', 'SECRET', 'REGULATED' ), true ) ) return self::failure( 'field_metadata_unsupported' );
			if ( isset( $field['visibility'] ) && ! in_array( $field['visibility'], array( 'visible', 'hidden', 'read_only' ), true ) ) return self::failure( 'field_metadata_unsupported' );
			if ( in_array( $field['sensitivity'] ?? '', array( 'PERSONAL', 'SECRET', 'REGULATED' ), true ) && array_intersect( array( 'default', 'const', 'enum', 'examples', 'example' ), array_keys( $shape ) ) ) return self::failure( 'schema_unsupported' );
			if ( true !== MAD4B_SCP_CSO_Scope::safe_data( $field, false ) ) return self::failure( 'field_metadata_unsupported' ); $out[ $path ] = $field;
		}
		ksort( $out, SORT_STRING ); return $out;
	}
	private static function provider( array $row ) {
		$id = $row['execution_provider'] ?? ''; $version = ''; $adapter_id = '';
		if ( '' !== $id && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) foreach ( MAD4B_SCP_Adapter_Registry::instance()->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'provider_key' ) || $adapter->provider_key() !== $id ) continue;
			$adapter_id = method_exists( $adapter, 'id' ) ? (string) $adapter->id() : ''; $version = method_exists( $adapter, 'runtime_version' ) ? (string) $adapter->runtime_version() : ''; break;
		}
		if ( '' === $version && 'core' === $id && defined( 'MAD4B_SCP_VERSION' ) ) $version = (string) MAD4B_SCP_VERSION;
		$generation = '' !== $id && class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ? MAD4B_SCP_Provider_Compatibility_Certification::certification_generation_sha256( $id ) : '';
		return array( 'provider_id' => $id, 'adapter_id' => $adapter_id, 'runtime_version' => $version, 'certification_generation_sha256' => self::sha( $generation ) ? $generation : '', 'source' => '' === $id ? 'unmapped_native_ability' : 'existing_capability_descriptor', 'cso_conformance_certified' => false );
	}
	private static function readback( $write_name, $input, array $raw ) {
		if ( array_diff( array_keys( $raw ), array( 'ability_name', 'input_map', 'revision_path', 'field_map', 'target_map', 'revision_input' ) ) || ! is_string( $raw['ability_name'] ?? null ) || $write_name === $raw['ability_name'] || self::fixed_dispatcher( $raw['ability_name'] ) ) return null;
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $raw['ability_name'] ); $ability = wp_get_ability( $raw['ability_name'] );
		if ( is_wp_error( $row ) || 'read' !== ( $row['lane'] ?? '' ) || true !== ( $row['readonly'] ?? null ) || true !== ( $row['execution_eligible'] ?? null ) || ! is_object( $ability ) || ! method_exists( $ability, 'get_input_schema' ) || ! method_exists( $ability, 'get_output_schema' ) ) return null;
		$ri = $ability->get_input_schema(); $ro = $ability->get_output_schema();
		if ( ! self::schema_valid( $ri ) || ! self::schema_valid( $ro ) || null === self::schema_path( $ro, $raw['revision_path'] ?? null ) ) return null;
		foreach ( array( 'input_map', 'field_map', 'target_map' ) as $map ) if ( ! isset( $raw[ $map ] ) || ! is_array( $raw[ $map ] ) || ! $raw[ $map ] || count( $raw[ $map ] ) > 64 ) return null;
		foreach ( $raw['input_map'] as $a => $b ) if ( ! self::same_type( self::schema_path( $ri, $a ), self::schema_path( $input, $b ) ) ) return null;
		foreach ( $raw['field_map'] as $a => $b ) if ( ! self::same_type( self::schema_path( $input, $a ), self::schema_path( $ro, $b ) ) ) return null;
		foreach ( $raw['target_map'] as $a => $b ) if ( ! self::same_type( self::schema_path( $ro, $a ), self::schema_path( $input, $b ) ) || ! in_array( $b, $raw['input_map'], true ) ) return null;
		if ( isset( $raw['revision_input'] ) && ! self::same_type( self::schema_path( $input, $raw['revision_input'] ), self::schema_path( $ro, $raw['revision_path'] ) ) ) return null;
		$binding = MAD4B_SCP_Capability_Descriptor_Registry::binding( $raw['ability_name'], self::CONSUMER ); if ( is_wp_error( $binding ) ) return null;
		$raw['capability_binding'] = $binding; $raw['independently_read_required'] = true; return $raw;
	}
	private static function same_type( $a, $b ) { return is_array( $a ) && is_array( $b ) && is_string( $a['type'] ?? null ) && $a['type'] === ( $b['type'] ?? null ) && ! self::sensitive( $a ) && ! self::sensitive( $b ); }
	private static function schema_path( $schema, $path, $deny_sensitive = true ) {
		if ( ! is_string( $path ) || strlen( $path ) > 128 || 1 !== preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*){0,7}$/D', $path ) ) return null;
		foreach ( explode( '.', $path ) as $field ) { if ( ! is_array( $schema ) || ! isset( $schema['properties'][ $field ] ) || ! is_array( $schema['properties'][ $field ] ) ) return null; $schema = $schema['properties'][ $field ]; if ( $deny_sensitive && ( self::sensitive_name( $field ) || self::sensitive( $schema ) ) ) return null; } return $schema;
	}
	private static function schema_valid( $schema ) { if ( null === $schema ) return true; if ( ! is_array( $schema ) || true !== MAD4B_SCP_CSO_Scope::bounded( $schema, self::MAX_SCHEMA_BYTES ) ) return false; $nodes = 0; return self::schema_walk( $schema, 0, $nodes, false ); }
	private static function schema_walk( $value, $depth, &$nodes, $secret ) {
		if ( ++$nodes > 4096 || $depth > 16 ) return false; if ( ! is_array( $value ) ) return true;
		$secret = $secret || self::sensitive( $value ) || 'PERSONAL' === ( $value['x-sensitivity'] ?? '' );
		if ( $secret && array_intersect( array( 'default', 'const', 'enum', 'examples', 'example' ), array_keys( $value ) ) ) return false;
		foreach ( $value as $key => $child ) {
			if ( 'properties' === $key && is_array( $child ) ) { foreach ( $child as $name => $shape ) if ( ! self::schema_walk( $shape, $depth + 1, $nodes, $secret || self::sensitive_name( $name ) ) ) return false; }
			elseif ( ! self::schema_walk( $child, $depth + 1, $nodes, $secret ) ) return false;
		} return true;
	}
	private static function values_safe( $value, $schema ) {
		if ( ! is_array( $schema ) ) return true; if ( self::sensitive( $schema ) ) return false;
		if ( is_array( $value ) ) foreach ( $value as $key => $child ) { if ( is_string( $key ) && self::sensitive_name( $key ) ) return false; $shape = $schema['properties'][ $key ] ?? ( $schema['items'] ?? null ); if ( ! self::values_safe( $child, $shape ) ) return false; }
		foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $keyword ) if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) foreach ( $schema[ $keyword ] as $shape ) if ( ! self::values_safe( $value, $shape ) ) return false; return true;
	}
	private static function sensitive( array $s ) { return true === ( $s['writeOnly'] ?? false ) || in_array( $s['x-sensitivity'] ?? ( $s['x-cso']['sensitivity'] ?? '' ), array( 'SECRET', 'REGULATED' ), true ) || in_array( $s['format'] ?? '', array( 'password', 'secret' ), true ); }
	private static function sensitive_name( $v ) { return is_string( $v ) && 1 === preg_match( '/(?:password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|credential|^secret$|^token$|authorization)/i', $v ); }
	private static function fixed_dispatcher( $name ) { return in_array( $name, array( 'mad4b/read-execute', 'mad4b/write-execute', 'mad4b/developer-execute', 'mad4b/enrollment-execute' ), true ); }
	private static function sha( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
	private static function failure( $reason ) { return MAD4B_SCP_CSO_Scope::error( $reason ); }
}
