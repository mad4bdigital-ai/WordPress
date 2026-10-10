<?php
/** Bounded, permission-filtered site signals. No arbitrary storage enumeration or writes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_CSO_Discovery {
	const CONTRACT = 'mad4b.cso01.discovery.v1';
	const MAX_TYPES = 96;
	const MAX_TAXONOMIES = 96;
	public static function discover( array $input = array() ) {
		try {
			if ( ! MAD4B_SCP_CSO_Scope::enabled( 'discovery' ) || ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() || array_diff( array_keys( $input ), array( 'query', 'limit', 'offset' ) ) ) return self::failure( 'discovery_unavailable' );
			$scope = MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error( $scope ) ) return $scope;
			$query = $input['query'] ?? ''; $limit = $input['limit'] ?? 20; $offset = $input['offset'] ?? 0;
			$catalog = MAD4B_SCP_CSO_Registry::catalog( $query, $limit, $offset ); if ( is_wp_error( $catalog ) ) return $catalog;
			if ( ! function_exists( 'get_post_types' ) || ! function_exists( 'get_taxonomies' ) || ! function_exists( 'current_user_can' ) ) return self::failure( 'inventory_unavailable' );
			$native_types = get_post_types( array(), 'objects' ); $native_tax = get_taxonomies( array(), 'objects' );
			if ( ! is_array( $native_types ) || count( $native_types ) > self::MAX_TYPES || ! is_array( $native_tax ) || count( $native_tax ) > self::MAX_TAXONOMIES ) return self::failure( 'inventory_budget' );
			$types = array(); $taxonomies = array();
			foreach ( $native_types as $name => $type ) {
				if ( ! self::name( $name ) || ! is_object( $type ) || ! is_object( $type->cap ?? null ) || ! is_string( $type->cap->edit_posts ?? null ) || ! current_user_can( $type->cap->edit_posts ) ) continue;
				$types[ $name ] = array( 'name' => $name, 'label' => self::label( $type->label ?? $name ), 'public' => true === ( $type->public ?? false ), 'show_in_rest' => true === ( $type->show_in_rest ?? false ) );
			} ksort( $types, SORT_STRING );
			foreach ( $native_tax as $name => $tax ) {
				if ( ! self::name( $name ) || ! is_object( $tax ) || ! is_object( $tax->cap ?? null ) || ! is_string( $tax->cap->assign_terms ?? null ) || ! current_user_can( $tax->cap->assign_terms ) || ! is_array( $tax->object_type ?? null ) || count( $tax->object_type ) > self::MAX_TYPES ) continue;
				$visible = array_values( array_intersect( array_keys( $types ), $tax->object_type ) ); if ( ! $visible ) continue;
				$taxonomies[ $name ] = array( 'name' => $name, 'label' => self::label( $tax->label ?? $name ), 'object_types' => $visible, 'public' => true === ( $tax->public ?? false ), 'show_in_rest' => true === ( $tax->show_in_rest ?? false ) );
			} ksort( $taxonomies, SORT_STRING );
			$inventory = array( 'post_types' => array_values( $types ), 'taxonomies' => array_values( $taxonomies ), 'plugins' => array(), 'plugin_versions' => array(), 'theme' => null, 'plugin_inventory_visible' => false, 'version_evidence_complete' => false );
			if ( current_user_can( 'activate_plugins' ) || current_user_can( 'manage_options' ) ) {
				if ( ! class_exists( 'MAD4B_SCP_Site_Capability_Discovery' ) ) return self::failure( 'inventory_unavailable' );
				$observed = MAD4B_SCP_Site_Capability_Discovery::observe( $scope['origin'] );
				if ( ! is_array( $observed ) || ! is_array( $observed['plugins'] ?? null ) || count( $observed['plugins'] ) > 128 || ! is_array( $observed['plugin_versions'] ?? null ) || count( $observed['plugin_versions'] ) > 128 || ! is_array( $observed['theme'] ?? null ) ) return self::failure( 'inventory_unavailable' );
				// Its unfiltered hash, private CPT names and provider matches are intentionally excluded.
				$inventory['plugins'] = $observed['plugins']; $inventory['plugin_versions'] = $observed['plugin_versions']; $inventory['theme'] = $observed['theme'];
				$inventory['plugin_inventory_visible'] = true; $inventory['version_evidence_complete'] = true === ( $observed['discovery_complete'] ?? false );
			}
			$inventory_sha = MAD4B_SCP_CSO_Scope::digest( $inventory ); $catalog_sha = MAD4B_SCP_CSO_Scope::digest( $catalog['items'] );
			if ( is_wp_error( $inventory_sha ) || is_wp_error( $catalog_sha ) || is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $scope ) ) || true !== MAD4B_SCP_CSO_Scope::safe_data( $inventory, false ) ) return self::failure( 'inventory_changed' );
			return array( 'contract' => self::CONTRACT, 'scope' => $scope, 'inventory' => $inventory, 'inventory_sha256' => $inventory_sha, 'catalog_sha256' => $catalog_sha, 'abilities' => $catalog['items'], 'query' => $query, 'limit' => $limit, 'offset' => $offset, 'has_more' => $catalog['has_more'], 'next_offset' => $catalog['next_offset'], 'non_exhaustive' => true, 'storage_policy' => array( 'unknown' => 'UNSUPPORTED', 'discovered_names_authorize_writes' => false, 'arbitrary_options' => false, 'arbitrary_meta' => false, 'arbitrary_sql' => false ), 'certification_issued' => false, 'read_only' => true, 'mutation_performed' => false, 'authorizing' => false );
		} catch ( Throwable $error ) { return self::failure( 'inventory_unavailable' ); }
	}
	public static function assert_snapshot( array $snapshot ) {
		if ( ( $snapshot['contract'] ?? '' ) !== self::CONTRACT || ! isset( $snapshot['scope'] ) || ! is_array( $snapshot['scope'] ) || is_wp_error( MAD4B_SCP_CSO_Scope::assert_current( $snapshot['scope'] ) ) || ! self::sha( $snapshot['inventory_sha256'] ?? '' ) || ! self::sha( $snapshot['catalog_sha256'] ?? '' ) ) return self::failure( 'inventory_changed' );
		$fresh = self::discover( array( 'query' => $snapshot['query'] ?? '', 'limit' => $snapshot['limit'] ?? 20, 'offset' => $snapshot['offset'] ?? 0 ) );
		if ( is_wp_error( $fresh ) || ! hash_equals( $fresh['inventory_sha256'], $snapshot['inventory_sha256'] ) || ! hash_equals( $fresh['catalog_sha256'], $snapshot['catalog_sha256'] ) ) return self::failure( 'inventory_changed' ); return true;
	}
	private static function name( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D', $v ); }
	private static function sha( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
	private static function label( $v ) { return is_string( $v ) ? MAD4B_SCP_Ability_Contract_Inspector::bounded_metadata( strip_tags( $v ), 160 ) : ''; }
	private static function failure( $reason ) { return MAD4B_SCP_CSO_Scope::error( $reason ); }
}
