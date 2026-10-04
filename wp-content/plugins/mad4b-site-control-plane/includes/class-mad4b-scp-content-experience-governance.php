<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Authority, historical identity and execution fencing for dynamic Content Experience profiles.
 * This service never grants authority; it only makes profile semantics part of existing
 * descriptor/grant reconciliation and provides bounded target locks.
 */
final class MAD4B_SCP_Content_Experience_Governance {
	const CONTRACT = 'mad4b.content-experience-governance.v1';

	public static function boot() {
		add_filter( 'mad4b_scp_capability_descriptor_generation_roots', array( __CLASS__, 'descriptor_roots' ), 20, 3 );
	}

	public static function authority_payload( array $profile ) {
		$helpers = array();
		$catalog = class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ? MAD4B_SCP_Content_Experience_Profiles::helper_catalog() : array();
		foreach ( isset( $profile['enabled_helpers'] ) ? (array) $profile['enabled_helpers'] : array() as $helper_id ) {
			$row = isset( $catalog[ $helper_id ] ) && is_array( $catalog[ $helper_id ] ) ? $catalog[ $helper_id ] : array();
			$helpers[ (string) $helper_id ] = array(
				'adapter_id' => isset( $row['adapter_id'] ) ? (string) $row['adapter_id'] : '',
				'provider' => isset( $row['provider'] ) ? (string) $row['provider'] : '',
				'certification_ability' => isset( $row['certification_ability'] ) ? (string) $row['certification_ability'] : '',
				'reversible' => ! empty( $row['reversible'] ),
			);
		}
		ksort( $helpers, SORT_STRING );
		$payload = array(
			'contract' => self::CONTRACT,
			'slug' => isset( $profile['slug'] ) ? (string) $profile['slug'] : '',
			'revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'post_type' => isset( $profile['post_type'] ) ? (string) $profile['post_type'] : '',
			'creation_status' => isset( $profile['creation_status'] ) ? (string) $profile['creation_status'] : '',
			'live_update_mode' => isset( $profile['live_update_mode'] ) ? (string) $profile['live_update_mode'] : '',
			'meta_mode' => isset( $profile['meta_mode'] ) ? (string) $profile['meta_mode'] : 'allowlist',
			'meta_keys' => array_values( isset( $profile['meta_keys'] ) ? (array) $profile['meta_keys'] : array() ),
			'protected_meta_keys' => array_values( isset( $profile['protected_meta_keys'] ) ? (array) $profile['protected_meta_keys'] : array() ),
			'taxonomy_mode' => isset( $profile['taxonomy_mode'] ) ? (string) $profile['taxonomy_mode'] : 'allowlist',
			'taxonomies' => array_values( isset( $profile['taxonomies'] ) ? (array) $profile['taxonomies'] : array() ),
			'featured_media' => ! empty( $profile['featured_media'] ),
			'hierarchy' => ! empty( $profile['hierarchy'] ),
			'helpers' => $helpers,
		);
		return $payload;
	}

	public static function authority_sha256( array $profile ) {
		return hash( 'sha256', wp_json_encode( self::authority_payload( $profile ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function snapshot( array $profile ) {
		$copy = $profile;
		$copy['authority_sha256'] = self::authority_sha256( $copy );
		return $copy;
	}

	public static function validate_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['slug'] ) || empty( $snapshot['post_type'] ) || empty( $snapshot['authority_sha256'] ) ) {
			return new WP_Error( 'mad4b_content_experience_profile_snapshot_invalid', 'Historical content experience profile snapshot is incomplete.' );
		}
		$expected = strtolower( (string) $snapshot['authority_sha256'] );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, self::authority_sha256( $snapshot ) ) ) {
			return new WP_Error( 'mad4b_content_experience_profile_snapshot_drift', 'Historical content experience profile snapshot failed its authority digest.' );
		}
		return $snapshot;
	}

	public static function current_guard( array $profile ) {
		$stored = isset( $profile['authority_sha256'] ) ? strtolower( (string) $profile['authority_sha256'] ) : '';
		$current = self::authority_sha256( $profile );
		if ( '' === $stored || ! hash_equals( $stored, $current ) ) {
			return new WP_Error( 'mad4b_content_experience_profile_authority_drift', 'Content experience profile authority fingerprint changed; reconcile the profile before execution.', array( 'current_authority_sha256' => $current ) );
		}
		if ( class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) {
			$catalog = MAD4B_SCP_Content_Experience_Profiles::helper_catalog_sha256();
			$stored_catalog = isset( $profile['helper_catalog_sha256'] ) ? (string) $profile['helper_catalog_sha256'] : '';
			if ( '' === $stored_catalog || ! hash_equals( $stored_catalog, $catalog ) ) {
				return new WP_Error( 'mad4b_content_experience_helper_catalog_drift', 'Content experience helper catalog changed; review and re-apply the profile before execution.' );
			}
		}
		return true;
	}

	public static function descriptor_roots( $roots, $ability_name, $inspected = array() ) {
		$roots = is_array( $roots ) ? $roots : array();
		if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return $roots;
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile_for_ability( $ability_name );
		if ( is_wp_error( $profile ) || ! is_array( $profile ) ) return $roots;
		$roots['content_experience_profile'] = self::authority_sha256( $profile );
		return $roots;
	}

	public static function lock_name( $slug, $post_id = 0, $creation_binding = '' ) {
		$scope = 'content-experience:' . sanitize_key( (string) $slug ) . ':';
		$scope .= $post_id > 0 ? 'post:' . absint( $post_id ) : 'create:' . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $creation_binding ) ), 0, 32 );
		return class_exists( 'MAD4B_SCP_Distributed_Lock' ) ? MAD4B_SCP_Distributed_Lock::catalog_name( $scope ) : '';
	}

	public static function acquire_lock( $slug, $post_id = 0, $creation_binding = '' ) {
		if ( ! class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) return new WP_Error( 'mad4b_content_experience_lock_unavailable', 'Distributed content experience lock service is unavailable.' );
		$name = self::lock_name( $slug, $post_id, $creation_binding );
		$result = MAD4B_SCP_Distributed_Lock::acquire( $name );
		if ( is_wp_error( $result ) ) return new WP_Error( 'mad4b_content_experience_target_busy', 'Another governed content experience mutation is already active for this target.', array( 'status' => 409 ) );
		return $name;
	}

	public static function release_lock( $name ) {
		if ( '' !== (string) $name && class_exists( 'MAD4B_SCP_Distributed_Lock' ) ) MAD4B_SCP_Distributed_Lock::release( $name );
	}
}
