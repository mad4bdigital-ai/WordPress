<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runtime object schemas and language identities own breadth; no business names are embedded. */
final class MAD4B_SCP_Domain_WordPress {
	public static function validate( $profile, array $desired, array $facts ) {
		if ( 'wordpress_hierarchy' === $profile ) return self::hierarchy( $desired, $facts );
		if ( 'wordpress_object' === $profile ) return self::object( $desired, $facts );
		if ( 'wordpress_private_collection' === $profile ) return self::collection( $desired, $facts );
		return MAD4B_SCP_Domain_Contracts::error( 'wordpress_profile' );
	}

	private static function hierarchy( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'source_language','nodes' ), array( 'source_language','nodes' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! is_string( $desired['source_language'] ) || '' === $desired['source_language'] || $desired['source_language'] !== ( $facts['source_language'] ?? '' ) || true !== ( $facts['hierarchy_inventory_complete'] ?? null ) || ! is_array( $desired['nodes'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'language_or_inventory' );
		$check = MAD4B_SCP_Domain_Contracts::hierarchy( $desired['nodes'], $facts['known_parent_ids'] ?? array(), $facts['existing_parents'] ?? array() );
		if ( is_wp_error( $check ) ) return $check;
		$groups = array();
		foreach ( $desired['nodes'] as $node ) {
			$check = MAD4B_SCP_Domain_Contracts::keys( $node, array( 'id','parent','language','translation_group' ), array( 'id','parent','language','translation_group' ) );
			if ( is_wp_error( $check ) ) return $check;
			$object = $facts['objects'][ $node['id'] ] ?? array();
			if ( true !== ( $object['authorized'] ?? null ) || ! is_string( $node['language'] ) || $node['language'] !== $desired['source_language'] || $node['language'] !== ( $object['language'] ?? null ) || ! is_string( $node['translation_group'] ) || $node['translation_group'] !== ( $object['translation_group'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'hierarchy_ownership_or_language' );
			if ( '' !== $node['parent'] ) {
				$parent = $facts['objects'][ $node['parent'] ] ?? array();
				if ( true !== ( $parent['authorized'] ?? null ) || $node['language'] !== ( $parent['language'] ?? null ) ) return MAD4B_SCP_Domain_Contracts::error( 'hierarchy_foreign_parent' );
			}
			if ( '' !== $node['translation_group'] ) {
				$key = $node['translation_group'] . ':' . $node['language'];
				if ( isset( $groups[ $key ] ) ) return MAD4B_SCP_Domain_Contracts::error( 'translation_group_duplicate' );
				$groups[ $key ] = true;
			}
		}
		return MAD4B_SCP_Domain_Contracts::result( 'wordpress_hierarchy', $desired, array( 'explicit_source_language','exact_parent_identity','no_cycles_or_orphans','translation_group_preserved','object_ownership' ), 'governed_hierarchy' );
	}

	private static function object( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'kind','object_id','fields' ), array( 'kind','object_id','fields' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( ! is_string( $desired['kind'] ) || ! in_array( $desired['kind'], $facts['admitted_object_kinds'] ?? array(), true ) || ! MAD4B_SCP_Domain_Contracts::identifier( $desired['object_id'] ) || $desired['object_id'] !== ( $facts['object_id'] ?? null ) || true !== ( $facts['object_access'] ?? null ) || ! is_array( $desired['fields'] ) ) return MAD4B_SCP_Domain_Contracts::error( 'wordpress_object_scope' );
		foreach ( array_keys( $desired['fields'] ) as $field ) if ( ! is_string( $field ) || in_array( $field, $facts['role_or_sensitive_fields'] ?? array(), true ) || preg_match( '/(?:^|_)(?:roles?|capabilities|user_level|user_pass|session_tokens)$/i', $field ) ) return MAD4B_SCP_Domain_Contracts::error( 'wordpress_role_or_sensitive_field' );
		$check = MAD4B_SCP_Domain_Contracts::fields( $desired['fields'], $facts, array( 'local_content','local_metadata','local_menu','local_media' ) );
		if ( is_wp_error( $check ) ) return $check;
		return MAD4B_SCP_Domain_Contracts::result( 'wordpress_object', $desired, array( 'runtime_object_and_field_schema','object_and_field_authority','no_role_escalation','provider_ownership','field_effect_bounds' ), 'governed_object' );
	}

	private static function collection( array $desired, array $facts ) {
		$check = MAD4B_SCP_Domain_Contracts::keys( $desired, array( 'object_ids','limit','offset','timezone','masked' ), array( 'object_ids','limit','offset','timezone','masked' ) );
		if ( is_wp_error( $check ) ) return $check;
		if ( true !== ( $facts['provider_available'] ?? null ) || true !== $desired['masked'] || ! is_int( $desired['limit'] ) || $desired['limit'] < 1 || $desired['limit'] > 50 || ! is_int( $desired['offset'] ) || $desired['offset'] < 0 || $desired['offset'] > 10000 || ! is_string( $desired['timezone'] ) || $desired['timezone'] !== ( $facts['timezone'] ?? null ) || ! in_array( $desired['timezone'], timezone_identifiers_list(), true ) ) return MAD4B_SCP_Domain_Contracts::error( 'collection_runtime_bound_or_timezone' );
		if ( ! is_array( $desired['object_ids'] ) || ! MAD4B_SCP_Domain_Contracts::is_list( $desired['object_ids'] ) || ! $desired['object_ids'] || count( $desired['object_ids'] ) > $desired['limit'] ) return MAD4B_SCP_Domain_Contracts::error( 'collection_object_bound' );
		$seen = array();
		foreach ( $desired['object_ids'] as $id ) {
			if ( ! MAD4B_SCP_Domain_Contracts::identifier( $id ) || isset( $seen[ $id ] ) || true !== ( $facts['object_access'][ $id ] ?? null ) || true !== ( $facts['mask_verified'][ $id ] ?? null ) || true === ( $facts['private_communication'][ $id ] ?? false ) ) return MAD4B_SCP_Domain_Contracts::error( 'collection_private_object' );
			$seen[ $id ] = true;
		}
		return MAD4B_SCP_Domain_Contracts::result( 'wordpress_private_collection', $desired, array( 'optional_provider_readiness','private_object_authority','masked_only','bounded_paging','exact_timezone','no_private_communications' ), 'private_read' );
	}
}
