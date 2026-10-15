<?php
/** Bounded Content Experience payload validation; no execution or authorization. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
trait MAD4B_SCP_Content_Experience_Input_Validation {
	private static function validate_meta_payload( array $profile, $meta ) {
		$meta = is_array( $meta ) ? $meta : array();
		if ( count( $meta ) > MAD4B_SCP_Content_Experience_Profiles::MAX_META_KEYS ) {
			return new WP_Error( 'mad4b_content_experience_meta_limit', 'Meta payload exceeds the profile limit.' );
		}
		$result = array();
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				return new WP_Error( 'mad4b_content_experience_meta_key_invalid', 'Meta payload contains an invalid key.' );
			}
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $key ) ) {
				return new WP_Error( 'mad4b_content_experience_sensitive_meta_denied', 'Sensitive/authentication-like metadata is outside the content experience authority.' );
			}
			if ( 0 === strpos( $key, '_' ) && ! in_array( $key, (array) $profile['protected_meta_keys'], true ) ) {
				return new WP_Error(
					'mad4b_content_experience_protected_meta_denied',
					'Protected meta key is not explicitly enabled by the experience profile.',
					array( 'key' => $key )
				);
			}
			if ( 0 === strpos( $key, '_' ) && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'mad4b_content_experience_protected_meta_capability_denied', 'Protected meta writes require administrator capability.' );
			}
			if ( 'allowlist' === $profile['meta_mode']
				&& ! in_array( $key, (array) $profile['meta_keys'], true )
				&& ! in_array( $key, (array) $profile['protected_meta_keys'], true ) ) {
				return new WP_Error(
					'mad4b_content_experience_meta_not_allowlisted',
					'Meta key is outside the configured profile allowlist.',
					array( 'key' => $key )
				);
			}
			$media_fields = isset( $profile['media_meta_fields'] ) && is_array( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array();
			if ( isset( $media_fields[ $key ] ) && is_array( $media_fields[ $key ] ) ) {
				$value = MAD4B_SCP_Content_Experience_Media::normalize_meta_value( $key, $value, $media_fields[ $key ] );
				if ( is_wp_error( $value ) ) return $value;
			}
			if ( ! MAD4B_SCP_Content_Experience_Media::value_within_budget( $value ) ) {
				return new WP_Error( 'mad4b_content_experience_meta_value_too_large', 'Meta value exceeds the bounded content-experience payload size.', array( 'key' => $key ) );
			}
			$result[ $key ] = $value;
		}
		$media_fields = isset( $profile['media_meta_fields'] ) && is_array( $profile['media_meta_fields'] ) ? $profile['media_meta_fields'] : array();
		$usage_guard = MAD4B_SCP_Content_Experience_Media::validate_usage_bindings( $media_fields, $result );
		if ( is_wp_error( $usage_guard ) ) return $usage_guard;
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function allowed_taxonomies( array $profile ) {
		$attached = array_keys( get_object_taxonomies( $profile['post_type'], 'objects' ) );
		$configured = isset( $profile['taxonomies'] ) ? (array) $profile['taxonomies'] : array();
		$mode = isset( $profile['taxonomy_mode'] ) ? (string) $profile['taxonomy_mode'] : 'allowlist';
		return 'all_attached' === $mode ? $attached : array_values( array_intersect( $attached, $configured ) );
	}

	private static function normalize_taxonomy_payload( array $profile, $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		if ( count( $payload ) > MAD4B_SCP_Content_Experience_Profiles::MAX_TAXONOMIES ) {
			return new WP_Error( 'mad4b_content_experience_taxonomy_limit', 'Taxonomy payload exceeds the profile limit.' );
		}
		$allowed = self::allowed_taxonomies( $profile );
		$result = array();
		foreach ( $payload as $taxonomy => $refs ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( ! in_array( $taxonomy, $allowed, true ) ) {
				return new WP_Error(
					'mad4b_content_experience_taxonomy_denied',
					'Taxonomy is not enabled for this experience profile.',
					array( 'taxonomy' => $taxonomy )
				);
			}
			$tax = get_taxonomy( $taxonomy );
			$assign = $tax && isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : 'edit_posts';
			if ( ! $tax || ! current_user_can( $assign ) ) {
				return new WP_Error(
					'mad4b_content_experience_taxonomy_capability_denied',
					'Current user cannot assign one requested taxonomy.',
					array( 'taxonomy' => $taxonomy )
				);
			}
			$ids = array();
			foreach ( (array) $refs as $ref ) {
				$term = null;
				if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
					$term = get_term( absint( $ref ), $taxonomy );
				} else {
					$slug = sanitize_title( (string) $ref );
					if ( '' !== $slug ) $term = get_term_by( 'slug', $slug, $taxonomy );
				}
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error(
						'mad4b_content_experience_term_missing',
						'Taxonomy reference does not resolve to an existing term.',
						array( 'taxonomy' => $taxonomy, 'reference' => $ref )
					);
				}
				$ids[] = (int) $term->term_id;
			}
			$ids = array_values( array_unique( $ids ) );
			sort( $ids, SORT_NUMERIC );
			$result[ $taxonomy ] = $ids;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function normalize_helper_payloads( array $profile, $operation, $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		if ( count( $payload ) > MAD4B_SCP_Content_Experience_Profiles::MAX_HELPERS ) {
			return new WP_Error( 'mad4b_content_experience_helper_limit', 'Helper payload exceeds the profile limit.' );
		}
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_HELPER_BYTES ) {
			return new WP_Error( 'mad4b_content_experience_helper_payload_too_large', 'Helper payload exceeds the bounded canonical size.' );
		}
		$catalog = MAD4B_SCP_Content_Experience_Profiles::helper_catalog();
		$result = array();
		foreach ( $payload as $helper_id => $helper_input ) {
			$helper_id = strtolower( trim( (string) $helper_id ) );
			if ( ! in_array( $helper_id, (array) $profile['enabled_helpers'], true ) || ! isset( $catalog[ $helper_id ] ) ) {
				return new WP_Error(
					'mad4b_content_experience_helper_not_enabled',
					'Helper is not enabled for this experience profile.',
					array( 'helper_id' => $helper_id )
				);
			}
			if ( ! in_array( $operation, (array) $catalog[ $helper_id ]['operations'], true ) ) {
				return new WP_Error(
					'mad4b_content_experience_helper_operation_denied',
					'Helper does not declare support for this operation.',
					array( 'helper_id' => $helper_id, 'operation' => $operation )
				);
			}
			$guard = self::helper_mutation_guard( $helper_id );
			if ( is_wp_error( $guard ) || true !== $guard ) return is_wp_error( $guard ) ? $guard : new WP_Error( 'mad4b_content_experience_helper_not_certified', 'External helper is not certified for mutation.' );
			$adapter = self::helper_adapter( $helper_id );
			$planned = $adapter
				? $adapter->plan_content_experience_helper( $helper_id, $helper_input, $profile, $operation )
				: apply_filters(
					'mad4b_scp_content_experience_plan_helper',
					null,
					$helper_id,
					$helper_input,
					$profile,
					$operation
				);
			if ( ! is_array( $planned )
				|| empty( $planned['ready'] )
				|| empty( $planned['state_sha256'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) $planned['state_sha256'] ) ) {
				return new WP_Error(
					'mad4b_content_experience_helper_plan_unavailable',
					'Enabled helper did not provide an exact reversible plan.',
					array( 'helper_id' => $helper_id )
				);
			}
			$planned['helper_id'] = $helper_id;
			$result[ $helper_id ] = $planned;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

}
