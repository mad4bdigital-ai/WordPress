<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral compiled resource constraints.
 *
 * The set is derived from the actual mutation input and repository-owned selector
 * contracts. Client-declared limits never grant or widen authority.
 */
final class MAD4B_SCP_Resource_Constraint_Set {
	const CONTRACT = 'mad4b.resource-constraint-set.v1';
	const CONFIG_CONTRACT = 'mad4b.resource-constraint-contracts.v1';
	const PREPARATION_EVIDENCE_CONTRACT = 'mad4b.resource-preparation-evidence.v1';

	private static $config = null;

	public static function clear_cache() { self::$config = null; }

	public static function compile( $ability_name, $provider, $input ) {
		$ability_name = trim( (string) $ability_name );
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$input = is_array( $input ) ? $input : array();
		$config = self::config();
		if ( is_wp_error( $config ) ) return $config;

		$canonical_input = self::canonicalize( $input );
		if ( is_wp_error( $canonical_input ) ) return $canonical_input;
		$json = self::json( $canonical_input );
		$max_input = (int) $config['limits']['max_canonical_input_bytes'];
		if ( ! is_string( $json ) || strlen( $json ) > $max_input ) {
			return new WP_Error( 'mad4b_resource_input_too_large', 'Mutation input exceeds the certified resource-constraint bound.' );
		}

		$selectors = $config['selectors'];
		$post_ids = self::positive_ints( self::select( $input, $selectors['post_ids'] ) );
		$post_types = self::names( self::select( $input, $selectors['post_types'] ), 'post_type' );
		$taxonomies = self::names( self::select( $input, $selectors['taxonomy_names'] ), 'taxonomy' );
		$term_ids = self::positive_ints( self::select( $input, $selectors['term_ids'] ) );
		$provider_objects = self::opaque_ids( self::select( $input, $selectors['provider_object_ids'] ), (int) $config['limits']['max_identifier_bytes'] );
		if ( is_wp_error( $provider_objects ) ) return $provider_objects;

		$zone_values = self::select( $input, $selectors['filesystem_zone'] );
		$zones = self::names( $zone_values, 'filesystem_zone' );
		$path_values = self::select( $input, $selectors['filesystem_paths'] );
		$filesystem = array();
		if ( ! empty( $zones ) || ! empty( $path_values ) ) {
			if ( 1 !== count( $zones ) || empty( $path_values ) ) return new WP_Error( 'mad4b_resource_filesystem_binding_invalid', 'Filesystem resources require exactly one certified zone and at least one relative path.' );
			if ( ! in_array( $zones[0], $config['allowed_filesystem_zones'], true ) ) return new WP_Error( 'mad4b_resource_filesystem_zone_denied', 'Filesystem resource zone is not certified.' );
			foreach ( $path_values as $path ) {
				$normalized = self::relative_path( $path, (int) $config['limits']['max_relative_path_bytes'] );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$filesystem[] = array( 'zone' => $zones[0], 'path' => $normalized );
			}
			$filesystem = self::unique_rows( $filesystem );
		}

		$table_values = self::select( $input, $selectors['database_tables'] );
		$column_values = self::select( $input, $selectors['database_columns'] );
		$tables = self::database_identifiers( $table_values, 'table' );
		if ( is_wp_error( $tables ) ) return $tables;
		$columns = self::database_identifiers( $column_values, 'column' );
		if ( is_wp_error( $columns ) ) return $columns;
		$database = array();
		foreach ( $tables as $table ) $database[] = array( 'table' => $table, 'columns' => $columns );

		$max_leaf = self::max_leaf_bytes( $input );
		if ( $max_leaf > (int) $config['limits']['max_leaf_value_bytes'] ) return new WP_Error( 'mad4b_resource_value_too_large', 'Mutation input contains a value above the certified per-value bound.' );

		$target_count = count( $post_ids ) + count( $term_ids ) + count( $provider_objects ) + count( $filesystem ) + count( $database );
		if ( 0 === $target_count ) $target_count = max( 1, count( $post_types ) + count( $taxonomies ) );
		if ( $target_count > (int) $config['limits']['max_resource_values'] ) return new WP_Error( 'mad4b_resource_set_too_large', 'Compiled mutation resource set exceeds the certified target bound.' );

		$resources = array(
			'post_ids' => $post_ids,
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
			'term_ids' => $term_ids,
			'filesystem' => $filesystem,
			'database' => $database,
			'provider_objects' => array_map( static function ( $id ) use ( $provider ) { return array( 'provider_id' => $provider, 'object_id' => $id ); }, $provider_objects ),
		);
		$limits = array(
			'mutation_count_max' => $target_count,
			'canonical_input_bytes_max' => strlen( $json ),
			'leaf_value_bytes_max' => $max_leaf,
		);
		$material = array(
			'contract' => self::CONTRACT,
			'ability_name' => $ability_name,
			'provider_id' => $provider,
			'resources' => $resources,
			'limits' => $limits,
			'wildcard_policy' => 'deny',
			'path_alias_policy' => 'deny',
			'user_declared_limits_authorizing' => false,
			'authorizing' => false,
		);
		$material['resource_set_sha256'] = self::digest( $material );
		return $material;
	}

	public static function assert_same( array $expected, $ability_name, $provider, $input ) {
		$current = self::compile( $ability_name, $provider, $input );
		if ( is_wp_error( $current ) ) return $current;
		$expected_sha = isset( $expected['resource_set_sha256'] ) ? strtolower( trim( (string) $expected['resource_set_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) || ! hash_equals( $expected_sha, (string) $current['resource_set_sha256'] ) ) {
			return new WP_Error(
				'mad4b_resource_set_drift',
				'Compiled mutation resource set changed after preparation or approval.',
				array( 'expected_resource_set_sha256' => $expected_sha, 'current_resource_set_sha256' => (string) $current['resource_set_sha256'], 'blind_retry_allowed' => false )
			);
		}
		return $current;
	}

	public static function preparation_evidence( $ability_name, $provider, $input ) {
		$set = self::compile( $ability_name, $provider, $input );
		if ( is_wp_error( $set ) ) return $set;
		$evidence = array(
			'contract' => self::PREPARATION_EVIDENCE_CONTRACT,
			'ability_name' => (string) $set['ability_name'],
			'provider_id' => (string) $set['provider_id'],
			'resource_set_sha256' => (string) $set['resource_set_sha256'],
			'mutation_count_max' => (int) $set['limits']['mutation_count_max'],
			'canonical_input_bytes_max' => (int) $set['limits']['canonical_input_bytes_max'],
			'leaf_value_bytes_max' => (int) $set['limits']['leaf_value_bytes_max'],
			'authorizing' => false,
		);
		$evidence['evidence_sha256'] = self::digest( $evidence );
		return $evidence;
	}

	private static function config() {
		if ( null !== self::$config ) return self::$config;
		$path = dirname( __DIR__ ) . '/config/resource-constraint-contracts.json';
		if ( ! is_readable( $path ) ) return self::$config = new WP_Error( 'mad4b_resource_contracts_missing', 'Resource-constraint contract registry is unavailable.' );
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || self::CONFIG_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) return self::$config = new WP_Error( 'mad4b_resource_contracts_invalid', 'Resource-constraint contract registry is invalid.' );
		foreach ( array( 'limits', 'selectors', 'allowed_filesystem_zones' ) as $key ) if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) return self::$config = new WP_Error( 'mad4b_resource_contracts_invalid', 'Resource-constraint registry is incomplete.' );
		return self::$config = $data;
	}

	private static function select( array $input, array $selectors ) {
		$out = array();
		foreach ( $selectors as $selector ) $out = array_merge( $out, self::select_path( $input, explode( '.', (string) $selector ) ) );
		return $out;
	}

	private static function select_path( $value, array $segments ) {
		if ( empty( $segments ) ) return array( $value );
		if ( ! is_array( $value ) ) return array();
		$segment = array_shift( $segments );
		$out = array();
		if ( '*' === $segment ) {
			foreach ( $value as $item ) $out = array_merge( $out, self::select_path( $item, $segments ) );
			return $out;
		}
		if ( ! array_key_exists( $segment, $value ) ) return array();
		return self::select_path( $value[ $segment ], $segments );
	}

	private static function positive_ints( array $values ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) continue;
			$id = (int) $value;
			if ( $id > 0 ) $out[] = $id;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	private static function names( array $values, $kind ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) continue;
			$name = strtolower( trim( (string) $value ) );
			if ( '' === $name ) continue;
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $name ) ) continue;
			$out[] = $name;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private static function opaque_ids( array $values, $max_bytes ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) continue;
			$id = trim( (string) $value );
			if ( '' === $id ) continue;
			if ( strlen( $id ) > $max_bytes || preg_match( '/[\x00-\x1F\x7F]/', $id ) || self::has_wildcard( $id ) ) return new WP_Error( 'mad4b_resource_provider_object_invalid', 'Provider object identifier is not a bounded exact identifier.' );
			$out[] = $id;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private static function database_identifiers( array $values, $kind ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) continue;
			$id = trim( (string) $value );
			if ( '' === $id ) continue;
			if ( self::has_wildcard( $id ) || 1 !== preg_match( '/^[A-Za-z0-9_$-]{1,191}$/', $id ) ) return new WP_Error( 'mad4b_resource_database_identifier_invalid', 'Database ' . $kind . ' must be an exact bounded identifier.' );
			$out[] = $id;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private static function relative_path( $value, $max_bytes ) {
		$path = trim( (string) $value );
		if ( '' === $path || strlen( $path ) > $max_bytes || false !== strpos( $path, "\0" ) || self::has_wildcard( $path ) ) return new WP_Error( 'mad4b_resource_path_invalid', 'Filesystem resource path must be a bounded exact relative path.' );
		if ( false !== strpos( $path, '\\' ) || 0 === strpos( $path, '/' ) || false !== strpos( $path, '//' ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) || preg_match( '/%2e|%2f|%5c/i', $path ) ) return new WP_Error( 'mad4b_resource_path_alias_denied', 'Filesystem resource path aliases or traversal are denied.' );
		return $path;
	}

	private static function has_wildcard( $value ) { return 1 === preg_match( '/[\*\?\[\]\{\}]/', (string) $value ); }

	private static function max_leaf_bytes( $value, $depth = 0 ) {
		if ( $depth > 12 ) return PHP_INT_MAX;
		if ( ! is_array( $value ) ) return strlen( is_scalar( $value ) || null === $value ? (string) $value : '' );
		$max = 0;
		foreach ( $value as $item ) $max = max( $max, self::max_leaf_bytes( $item, $depth + 1 ) );
		return $max;
	}

	private static function unique_rows( array $rows ) {
		$map = array();
		foreach ( $rows as $row ) $map[ self::digest( $row ) ] = $row;
		ksort( $map, SORT_STRING );
		return array_values( $map );
	}

	private static function canonicalize( $value, $depth = 0 ) {
		if ( $depth > 12 ) return new WP_Error( 'mad4b_resource_input_too_deep', 'Mutation input exceeds the resource-constraint canonical depth.' );
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			$out = array();
			foreach ( $value as $key => $item ) {
				$normalized = self::canonicalize( $item, $depth + 1 );
				if ( is_wp_error( $normalized ) ) return $normalized;
				if ( $is_list ) $out[] = $normalized; else $out[ (string) $key ] = $normalized;
			}
			return $out;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_resource_input_invalid', 'Mutation input contains a value unsupported by resource canonicalization.' );
	}

	private static function digest( $value ) {
		$canonical = self::canonicalize( $value );
		if ( is_wp_error( $canonical ) ) return '';
		$json = self::json( $canonical );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function json( $value ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
