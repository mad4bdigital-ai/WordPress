<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Execution plane for configuration-driven content experience profiles.
 *
 * The registry owns profile configuration and generated Ability names. This
 * service owns deterministic planning, WordPress writes, readback and rollback.
 * No business content type or provider is hardcoded here.
 */
final class MAD4B_SCP_Content_Experience_Runtime {
	const OPERATION_PLAN_CONTRACT = 'mad4b.content-experience-operation-plan.v1';
	const VERIFY_CONTRACT = 'mad4b.content-experience-verify.v1';
	const MAX_HELPER_BYTES = 65536;
	const MARKER_META = '_mad4b_content_experience_profile';
	const REVISION_META = '_mad4b_content_experience_revision';
	const CREATION_BINDING_META = '_mad4b_content_experience_creation_binding';

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
			$result[ $key ] = $value;
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function allowed_taxonomies( array $profile ) {
		$attached = array_keys( get_object_taxonomies( $profile['post_type'], 'objects' ) );
		$configured = isset( $profile['taxonomies'] ) ? (array) $profile['taxonomies'] : array();
		return $configured ? array_values( array_intersect( $attached, $configured ) ) : $attached;
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
			$planned = apply_filters(
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

	private static function post_state_hash( $post ) {
		if ( ! $post ) return '';
		return hash( 'sha256', wp_json_encode( array(
			'ID' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'post_title' => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_name' => (string) $post->post_name,
			'post_parent' => (int) $post->post_parent,
			'menu_order' => (int) $post->menu_order,
			'modified_gmt' => (string) $post->post_modified_gmt,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function operation_plan( $slug, $operation, $input ) {
		$input = is_array( $input ) ? $input : array();
		unset( $input['plan_sha256'], $input['_mad4b_approval_ticket_id'], $input['_mad4b_context_receipt'] );
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( empty( $profile['enabled'] ) ) return new WP_Error( 'mad4b_content_experience_profile_disabled', 'Experience profile is disabled.' );
		$object = MAD4B_SCP_Content_Experience_Profiles::post_type_object( $profile['post_type'] );
		if ( is_wp_error( $object ) ) return $object;
		if ( ! in_array( $operation, array( 'create', 'update', 'publish' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_operation_invalid', 'Unsupported experience operation.' );
		}

		$post = null;
		$current_state_sha256 = '';
		if ( 'create' !== $operation ) {
			$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || (string) $post->post_type !== (string) $profile['post_type'] ) {
				return new WP_Error( 'mad4b_content_experience_post_mismatch', 'Target post does not belong to the configured experience post type.' );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_content_experience_edit_denied', 'Current user cannot edit the target post.' );
			$expected_modified = isset( $input['expected_modified_gmt'] ) ? (string) $input['expected_modified_gmt'] : '';
			if ( '' === $expected_modified || ! hash_equals( (string) $post->post_modified_gmt, $expected_modified ) ) {
				return new WP_Error(
					'mad4b_content_experience_post_drift',
					'Target post changed since it was read.',
					array( 'current_modified_gmt' => $post->post_modified_gmt )
				);
			}
			$current_state_sha256 = self::post_state_hash( $post );
		}

		if ( 'create' === $operation ) {
			if ( ! current_user_can( MAD4B_SCP_Content_Experience_Profiles::post_type_create_cap( $object ) ) ) {
				return new WP_Error( 'mad4b_content_experience_create_denied', 'Current user cannot create this post type.' );
			}
			if ( empty( $input['post_title'] ) || '' === trim( (string) $input['post_title'] ) ) {
				return new WP_Error( 'mad4b_content_experience_title_required', 'Create requires a non-empty post_title.' );
			}
		}
		if ( 'publish' === $operation
			&& ! current_user_can( MAD4B_SCP_Content_Experience_Profiles::post_type_publish_cap( $object ) ) ) {
			return new WP_Error( 'mad4b_content_experience_publish_denied', 'Current user cannot publish this post type.' );
		}
		if ( 'update' === $operation
			&& $post
			&& in_array( $post->post_status, array( 'publish', 'private' ), true )
			&& 'draft_first' === $profile['live_update_mode']
			&& array_intersect(
				array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'meta', 'taxonomies', 'featured_media_id', 'helpers' ),
				array_keys( $input )
			) ) {
			return new WP_Error(
				'mad4b_content_experience_live_update_requires_draft',
				'This experience profile requires live content to return to draft/pending before content-bearing changes.'
			);
		}

		$meta = self::validate_meta_payload( $profile, isset( $input['meta'] ) ? $input['meta'] : array() );
		if ( is_wp_error( $meta ) ) return $meta;
		$taxonomies = self::normalize_taxonomy_payload( $profile, isset( $input['taxonomies'] ) ? $input['taxonomies'] : array() );
		if ( is_wp_error( $taxonomies ) ) return $taxonomies;
		$helpers = self::normalize_helper_payloads( $profile, $operation, isset( $input['helpers'] ) ? $input['helpers'] : array() );
		if ( is_wp_error( $helpers ) ) return $helpers;

		$featured_media_id = array_key_exists( 'featured_media_id', $input ) ? absint( $input['featured_media_id'] ) : null;
		if ( null !== $featured_media_id ) {
			if ( empty( $profile['featured_media'] ) ) return new WP_Error( 'mad4b_content_experience_featured_media_disabled', 'Featured media is disabled for this experience profile.' );
			if ( $featured_media_id > 0 && 'attachment' !== get_post_type( $featured_media_id ) ) {
				return new WP_Error( 'mad4b_content_experience_featured_media_invalid', 'featured_media_id must reference an attachment.' );
			}
		}

		$post_parent = array_key_exists( 'post_parent', $input ) ? absint( $input['post_parent'] ) : null;
		if ( null !== $post_parent ) {
			if ( empty( $profile['hierarchy'] ) ) return new WP_Error( 'mad4b_content_experience_hierarchy_disabled', 'Hierarchy is disabled for this experience profile.' );
			if ( $post_parent > 0 && $profile['post_type'] !== get_post_type( $post_parent ) ) {
				return new WP_Error( 'mad4b_content_experience_parent_mismatch', 'Parent must use the same post type.' );
			}
		}

		$normalized = array(
			'post_id' => $post ? (int) $post->ID : 0,
			'expected_modified_gmt' => $post ? (string) $post->post_modified_gmt : '',
			'post_title' => array_key_exists( 'post_title', $input ) ? (string) $input['post_title'] : null,
			'post_content' => array_key_exists( 'post_content', $input ) ? (string) $input['post_content'] : null,
			'post_excerpt' => array_key_exists( 'post_excerpt', $input ) ? (string) $input['post_excerpt'] : null,
			'post_name' => array_key_exists( 'post_name', $input ) ? sanitize_title( (string) $input['post_name'] ) : null,
			'post_parent' => $post_parent,
			'menu_order' => array_key_exists( 'menu_order', $input ) ? (int) $input['menu_order'] : null,
			'featured_media_id' => $featured_media_id,
			'meta' => $meta,
			'taxonomies' => $taxonomies,
			'helpers' => $helpers,
			'post_status' => 'publish' === $operation
				? ( isset( $input['post_status'] ) && 'private' === sanitize_key( (string) $input['post_status'] ) ? 'private' : 'publish' )
				: ( 'create' === $operation ? $profile['creation_status'] : null ),
		);

		$profile_digest = hash( 'sha256', wp_json_encode( $profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$plan = array(
			'contract' => self::OPERATION_PLAN_CONTRACT,
			'profile_slug' => (string) $profile['slug'],
			'profile_revision' => (int) $profile['revision'],
			'profile_sha256' => $profile_digest,
			'helper_catalog_sha256' => MAD4B_SCP_Content_Experience_Profiles::helper_catalog_sha256(),
			'operation' => $operation,
			'post_type' => (string) $profile['post_type'],
			'current_state_sha256' => $current_state_sha256,
			'normalized_input' => $normalized,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( 'create' === $operation ) {
			$plan['creation_binding'] = hash( 'sha256', $plan['plan_sha256'] . '|' . $profile['slug'] . '|' . $profile['revision'] );
		}
		return $plan;
	}

	private static function apply_meta( $post_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			$result = update_post_meta( $post_id, $key, $value );
			if ( false === $result && get_post_meta( $post_id, $key, true ) !== $value ) {
				return new WP_Error( 'mad4b_content_experience_meta_apply_failed', 'Unable to apply one post meta value.', array( 'key' => $key ) );
			}
		}
		return true;
	}

	private static function apply_taxonomies( $post_id, array $taxonomies ) {
		foreach ( $taxonomies as $taxonomy => $term_ids ) {
			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) return $result;
		}
		return true;
	}

	private static function apply_featured_media( $post_id, $featured_media_id ) {
		if ( null === $featured_media_id ) return true;
		if ( 0 === (int) $featured_media_id ) return delete_post_thumbnail( $post_id ) || ! has_post_thumbnail( $post_id );
		return set_post_thumbnail( $post_id, (int) $featured_media_id )
			? true
			: new WP_Error( 'mad4b_content_experience_featured_media_apply_failed', 'Unable to set featured media.' );
	}

	private static function apply_helpers( array $profile, $operation, $post_id, array $helper_plans ) {
		$results = array();
		foreach ( $helper_plans as $helper_id => $helper_plan ) {
			$result = apply_filters(
				'mad4b_scp_content_experience_apply_helper',
				null,
				$helper_id,
				$helper_plan,
				array( 'profile' => $profile, 'operation' => $operation, 'post_id' => (int) $post_id )
			);
			if ( ! is_array( $result ) || empty( $result['verified'] ) ) {
				return new WP_Error(
					'mad4b_content_experience_helper_apply_failed',
					'Enabled helper did not complete with verified readback.',
					array( 'helper_id' => $helper_id )
				);
			}
			$results[ $helper_id ] = $result;
		}
		return $results;
	}

	private static function verify_plan_readback( array $profile, array $plan, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || (string) $post->post_type !== (string) $profile['post_type'] ) {
			return new WP_Error( 'mad4b_content_experience_readback_missing', 'Post readback is missing or has the wrong post type.' );
		}
		$input = $plan['normalized_input'];
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $field ) {
			if ( null === $input[ $field ] ) continue;
			if ( (string) $post->{$field} !== (string) $input[ $field ] ) {
				return new WP_Error( 'mad4b_content_experience_readback_mismatch', 'Core post field readback mismatch.', array( 'field' => $field ) );
			}
		}
		if ( null !== $input['post_parent'] && (int) $post->post_parent !== (int) $input['post_parent'] ) {
			return new WP_Error( 'mad4b_content_experience_parent_readback_mismatch', 'Parent readback mismatch.' );
		}
		if ( null !== $input['menu_order'] && (int) $post->menu_order !== (int) $input['menu_order'] ) {
			return new WP_Error( 'mad4b_content_experience_menu_order_readback_mismatch', 'Menu order readback mismatch.' );
		}
		if ( null !== $input['post_status'] && (string) $post->post_status !== (string) $input['post_status'] ) {
			return new WP_Error( 'mad4b_content_experience_status_readback_mismatch', 'Post status readback mismatch.' );
		}
		foreach ( $input['meta'] as $key => $value ) {
			if ( get_post_meta( $post_id, $key, true ) !== $value ) {
				return new WP_Error( 'mad4b_content_experience_meta_readback_mismatch', 'Post meta readback mismatch.', array( 'key' => $key ) );
			}
		}
		foreach ( $input['taxonomies'] as $taxonomy => $expected_ids ) {
			$current = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $current ) ) return $current;
			$current = array_values( array_map( 'absint', (array) $current ) );
			sort( $current, SORT_NUMERIC );
			if ( $current !== $expected_ids ) {
				return new WP_Error( 'mad4b_content_experience_taxonomy_readback_mismatch', 'Taxonomy readback mismatch.', array( 'taxonomy' => $taxonomy ) );
			}
		}
		if ( null !== $input['featured_media_id'] && (int) get_post_thumbnail_id( $post_id ) !== (int) $input['featured_media_id'] ) {
			return new WP_Error( 'mad4b_content_experience_featured_media_readback_mismatch', 'Featured media readback mismatch.' );
		}
		if ( (string) get_post_meta( $post_id, self::MARKER_META, true ) !== (string) $profile['slug'] ) {
			return new WP_Error( 'mad4b_content_experience_marker_readback_mismatch', 'Experience profile marker readback mismatch.' );
		}
		return true;
	}

	private static function find_created_by_binding( array $profile, $binding ) {
		$ids = get_posts( array(
			'post_type' => $profile['post_type'],
			'post_status' => 'any',
			'posts_per_page' => 2,
			'fields' => 'ids',
			'meta_key' => self::CREATION_BINDING_META,
			'meta_value' => (string) $binding,
			'no_found_rows' => true,
		) );
		$ids = array_values( array_map( 'absint', (array) $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public static function operation_apply( $slug, $operation, $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected_plan = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		$plan_input = $input;
		unset( $plan_input['plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = self::operation_plan( $slug, $operation, $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( '' === $expected_plan || ! hash_equals( $plan['plan_sha256'], $expected_plan ) ) {
			return new WP_Error( 'mad4b_content_experience_operation_plan_drift', 'Operation apply does not match the exact reviewed plan.' );
		}
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		$normalized = $plan['normalized_input'];
		$post_id = isset( $normalized['post_id'] ) ? (int) $normalized['post_id'] : 0;

		if ( 'create' === $operation ) {
			$binding = (string) $plan['creation_binding'];
			$existing = self::find_created_by_binding( $profile, $binding );
			if ( count( $existing ) > 1 ) {
				return new WP_Error( 'mad4b_content_experience_creation_binding_collision', 'Creation binding resolves to more than one post.' );
			}
			if ( 1 === count( $existing ) ) {
				$post_id = absint( $existing[0] );
				$verify = self::verify_plan_readback( $profile, $plan, $post_id );
				if ( is_wp_error( $verify ) ) return new WP_Error( 'mad4b_content_experience_create_replay_drift', 'Existing idempotent create result no longer matches the reviewed plan.' );
				return array(
					'post_id' => $post_id,
					'created' => false,
					'idempotent_replay' => true,
					'verified' => true,
					'plan_sha256' => $plan['plan_sha256'],
				);
			}
			$postarr = array(
				'post_type' => $profile['post_type'],
				'post_title' => (string) $normalized['post_title'],
				'post_content' => null === $normalized['post_content'] ? '' : (string) $normalized['post_content'],
				'post_excerpt' => null === $normalized['post_excerpt'] ? '' : (string) $normalized['post_excerpt'],
				'post_status' => (string) $normalized['post_status'],
				'post_name' => null === $normalized['post_name'] ? '' : (string) $normalized['post_name'],
				'post_parent' => null === $normalized['post_parent'] ? 0 : (int) $normalized['post_parent'],
				'menu_order' => null === $normalized['menu_order'] ? 0 : (int) $normalized['menu_order'],
			);
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) ) return $post_id;
			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::CREATION_BINDING_META, $binding );
		} elseif ( 'update' === $operation ) {
			$update = array( 'ID' => $post_id );
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
				if ( null !== $normalized[ $field ] ) $update[ $field ] = $normalized[ $field ];
			}
			if ( count( $update ) > 1 ) {
				$result = wp_update_post( wp_slash( $update ), true );
				if ( is_wp_error( $result ) ) return $result;
			}
		} elseif ( 'publish' === $operation ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $normalized['post_status'] ), true );
			if ( is_wp_error( $result ) ) return $result;
		}

		update_post_meta( $post_id, self::MARKER_META, (string) $profile['slug'] );
		update_post_meta( $post_id, self::REVISION_META, (int) $profile['revision'] );
		$meta_result = self::apply_meta( $post_id, $normalized['meta'] );
		if ( is_wp_error( $meta_result ) ) return $meta_result;
		$term_result = self::apply_taxonomies( $post_id, $normalized['taxonomies'] );
		if ( is_wp_error( $term_result ) ) return $term_result;
		$media_result = self::apply_featured_media( $post_id, $normalized['featured_media_id'] );
		if ( is_wp_error( $media_result ) ) return $media_result;
		$helper_results = self::apply_helpers( $profile, $operation, $post_id, $normalized['helpers'] );
		if ( is_wp_error( $helper_results ) ) return $helper_results;
		$verified = self::verify_plan_readback( $profile, $plan, $post_id );
		if ( is_wp_error( $verified ) ) return $verified;

		$post = get_post( $post_id );
		return array(
			'contract' => MAD4B_SCP_Content_Experience_Profiles::CONTRACT,
			'profile_slug' => $profile['slug'],
			'operation' => $operation,
			'post_id' => $post_id,
			'post_status' => $post ? $post->post_status : '',
			'modified_gmt' => $post ? $post->post_modified_gmt : '',
			'verified' => true,
			'helper_results' => $helper_results,
			'plan_sha256' => $plan['plan_sha256'],
		);
	}

	private static function helper_state_capture( array $profile, $operation, $post_id, array $helper_plans ) {
		$states = array();
		foreach ( $helper_plans as $helper_id => $plan ) {
			$state = apply_filters(
				'mad4b_scp_content_experience_capture_helper_state',
				null,
				$helper_id,
				$plan,
				array( 'profile' => $profile, 'operation' => $operation, 'post_id' => (int) $post_id )
			);
			if ( ! is_array( $state ) ) {
				return new WP_Error(
					'mad4b_content_experience_helper_snapshot_unavailable',
					'Enabled helper did not provide reversible state capture.',
					array( 'helper_id' => $helper_id )
				);
			}
			$states[ $helper_id ] = $state;
		}
		return $states;
	}

	private static function snapshot_post_for_plan( array $profile, array $plan, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return array( 'exists' => false );
		$input = $plan['normalized_input'];
		$meta = array();
		foreach ( array_keys( $input['meta'] ) as $key ) {
			$meta[ $key ] = array(
				'exists' => metadata_exists( 'post', $post_id, $key ),
				'value' => get_post_meta( $post_id, $key, true ),
			);
		}
		foreach ( array( self::MARKER_META, self::REVISION_META, self::CREATION_BINDING_META ) as $key ) {
			$meta[ $key ] = array(
				'exists' => metadata_exists( 'post', $post_id, $key ),
				'value' => get_post_meta( $post_id, $key, true ),
			);
		}
		$terms = array();
		foreach ( array_keys( $input['taxonomies'] ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) return $ids;
			$ids = array_values( array_map( 'absint', (array) $ids ) );
			sort( $ids, SORT_NUMERIC );
			$terms[ $taxonomy ] = $ids;
		}
		$helper_states = self::helper_state_capture( $profile, $plan['operation'], $post_id, $input['helpers'] );
		if ( is_wp_error( $helper_states ) ) return $helper_states;
		return array(
			'exists' => true,
			'post' => array(
				'post_type' => $post->post_type,
				'post_status' => $post->post_status,
				'post_title' => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_name' => $post->post_name,
				'post_parent' => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
			),
			'meta' => $meta,
			'terms' => $terms,
			'featured_media_id' => (int) get_post_thumbnail_id( $post_id ),
			'helper_states' => $helper_states,
		);
	}

	public static function capture_reversible_state( $slug, $operation, array $input ) {
		$plan_input = $input;
		unset( $plan_input['plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = self::operation_plan( $slug, $operation, $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		$expected = isset( $input['plan_sha256'] ) ? strtolower( (string) $input['plan_sha256'] ) : '';
		if ( '' === $expected || ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_content_experience_operation_plan_drift', 'Reversible capture does not match the reviewed plan.' );
		}
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( 'create' === $operation ) {
			$binding = (string) $plan['creation_binding'];
			$existing = self::find_created_by_binding( $profile, $binding );
			if ( count( $existing ) > 1 ) {
				return new WP_Error( 'mad4b_content_experience_creation_binding_collision', 'Creation binding resolves to more than one post before mutation.' );
			}
			$state = array( 'exists' => false, 'post_ids' => array(), 'states' => array() );
			if ( 1 === count( $existing ) ) {
				$id = (int) $existing[0];
				$verified = self::verify_plan_readback( $profile, $plan, $id );
				if ( is_wp_error( $verified ) ) {
					return new WP_Error( 'mad4b_content_experience_create_replay_drift', 'Existing idempotent create result no longer matches the reviewed plan.' );
				}
				$snapshot = self::snapshot_post_for_plan( $profile, $plan, $id );
				if ( is_wp_error( $snapshot ) ) return $snapshot;
				$state = array( 'exists' => true, 'post_ids' => array( $id ), 'states' => array( (string) $id => $snapshot ) );
			}
			return array(
				'target_type' => 'content-experience-create',
				'target_id' => $binding,
				'target' => array( 'kind' => 'operation', 'operation' => 'create', 'slug' => $slug, 'binding' => $binding, 'plan' => $plan ),
				'state' => $state,
			);
		}
		$post_id = (int) $plan['normalized_input']['post_id'];
		$snapshot = self::snapshot_post_for_plan( $profile, $plan, $post_id );
		if ( is_wp_error( $snapshot ) ) return $snapshot;
		return array(
			'target_type' => 'content-experience-post',
			'target_id' => (string) $post_id,
			'target' => array( 'kind' => 'operation', 'operation' => $operation, 'slug' => $slug, 'post_id' => $post_id, 'plan' => $plan ),
			'state' => $snapshot,
		);
	}

	public static function read_reversible_state( array $target ) {
		$slug = isset( $target['slug'] ) ? (string) $target['slug'] : '';
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		$operation = isset( $target['operation'] ) ? sanitize_key( (string) $target['operation'] ) : '';
		if ( 'create' === $operation ) {
			$ids = self::find_created_by_binding( $profile, isset( $target['binding'] ) ? (string) $target['binding'] : '' );
			$states = array();
			foreach ( $ids as $id ) {
				$snapshot = self::snapshot_post_for_plan( $profile, $target['plan'], $id );
				if ( is_wp_error( $snapshot ) ) return $snapshot;
				$states[ (string) $id ] = $snapshot;
			}
			return array( 'exists' => ! empty( $ids ), 'post_ids' => $ids, 'states' => $states );
		}
		$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		return self::snapshot_post_for_plan( $profile, $target['plan'], $post_id );
	}

	private static function restore_helper_states( array $profile, $operation, $post_id, array $helper_states ) {
		foreach ( $helper_states as $helper_id => $state ) {
			$result = apply_filters(
				'mad4b_scp_content_experience_restore_helper_state',
				null,
				$helper_id,
				$state,
				array( 'profile' => $profile, 'operation' => $operation, 'post_id' => (int) $post_id )
			);
			if ( true !== $result ) {
				return new WP_Error(
					'mad4b_content_experience_helper_restore_failed',
					'Experience helper could not restore its previous state.',
					array( 'helper_id' => $helper_id )
				);
			}
		}
		return true;
	}

	private static function restore_post_snapshot( array $profile, $operation, $post_id, array $state ) {
		if ( empty( $state['exists'] ) || empty( $state['post'] ) ) return new WP_Error( 'mad4b_content_experience_restore_state_invalid', 'Post restore state is incomplete.' );
		$postarr = array_merge( array( 'ID' => (int) $post_id ), $state['post'] );
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) return $result;
		foreach ( (array) $state['meta'] as $key => $entry ) {
			if ( ! empty( $entry['exists'] ) ) update_post_meta( $post_id, $key, $entry['value'] );
			else delete_post_meta( $post_id, $key );
		}
		foreach ( (array) $state['terms'] as $taxonomy => $ids ) {
			$set = wp_set_object_terms( $post_id, $ids, $taxonomy, false );
			if ( is_wp_error( $set ) ) return $set;
		}
		$media = isset( $state['featured_media_id'] ) ? (int) $state['featured_media_id'] : 0;
		if ( $media > 0 ) set_post_thumbnail( $post_id, $media ); else delete_post_thumbnail( $post_id );
		return self::restore_helper_states(
			$profile,
			$operation,
			$post_id,
			isset( $state['helper_states'] ) && is_array( $state['helper_states'] ) ? $state['helper_states'] : array()
		);
	}

	public static function restore_reversible_state( array $target, array $state ) {
		$slug = isset( $target['slug'] ) ? (string) $target['slug'] : '';
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		$operation = isset( $target['operation'] ) ? sanitize_key( (string) $target['operation'] ) : '';
		if ( 'create' === $operation ) {
			$ids = self::find_created_by_binding( $profile, isset( $target['binding'] ) ? (string) $target['binding'] : '' );
			if ( ! empty( $state['exists'] ) ) {
				$expected_ids = isset( $state['post_ids'] ) ? array_values( array_map( 'absint', (array) $state['post_ids'] ) ) : array();
				sort( $expected_ids, SORT_NUMERIC );
				if ( $ids !== $expected_ids ) return new WP_Error( 'mad4b_content_experience_create_restore_drift', 'Idempotent create target changed after the recorded no-op.' );
				foreach ( $ids as $id ) {
					$key = (string) $id;
					if ( ! isset( $state['states'][ $key ] ) || ! is_array( $state['states'][ $key ] ) ) return new WP_Error( 'mad4b_content_experience_create_restore_state_invalid', 'Stored idempotent create state is incomplete.' );
					$restored = self::restore_post_snapshot( $profile, 'create', $id, $state['states'][ $key ] );
					if ( is_wp_error( $restored ) ) return $restored;
				}
				return true;
			}
			foreach ( array_reverse( $ids ) as $id ) {
				if ( ! current_user_can( 'delete_post', $id ) ) return new WP_Error( 'mad4b_content_experience_create_restore_denied', 'Current user cannot remove created content during rollback.' );
				if ( ! wp_delete_post( $id, true ) ) return new WP_Error( 'mad4b_content_experience_create_restore_failed', 'Created content could not be removed during rollback.' );
			}
			return true;
		}
		$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'mad4b_content_experience_restore_denied', 'Current user cannot restore the target post.' );
		return self::restore_post_snapshot( $profile, $operation, $post_id, $state );
	}

	public static function verify( $slug, array $input ) {
		$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== $profile['post_type'] || ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error(
				'mad4b_content_experience_verify_target_invalid',
				'Post is missing, unreadable or belongs to a different experience profile post type.'
			);
		}
		$marker = (string) get_post_meta( $post_id, self::MARKER_META, true );
		$revision = (int) get_post_meta( $post_id, self::REVISION_META, true );
		$helper_verification = apply_filters(
			'mad4b_scp_content_experience_verify_helpers',
			array(),
			array( 'profile' => $profile, 'post_id' => $post_id )
		);
		if ( ! is_array( $helper_verification ) ) $helper_verification = array();
		$result = array(
			'contract' => self::VERIFY_CONTRACT,
			'profile_slug' => $slug,
			'profile_revision' => (int) $profile['revision'],
			'post_id' => $post_id,
			'post_type' => $post->post_type,
			'post_status' => $post->post_status,
			'modified_gmt' => $post->post_modified_gmt,
			'marker_match' => hash_equals( $slug, $marker ),
			'stored_profile_revision' => $revision,
			'profile_revision_match' => $revision === (int) $profile['revision'],
			'core_state_sha256' => self::post_state_hash( $post ),
			'featured_media_id' => (int) get_post_thumbnail_id( $post_id ),
			'helper_verification' => $helper_verification,
			'mutation_performed' => false,
		);
		$result['verification_sha256'] = hash( 'sha256', wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $result;
	}
}
