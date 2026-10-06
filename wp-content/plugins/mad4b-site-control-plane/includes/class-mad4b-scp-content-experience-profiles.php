<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Configuration-driven content experience registry.
 *
 * Business content types are data, not PHP branches. A saved profile binds one
 * registered post type to a generated family of governed MCP Abilities.
 */
final class MAD4B_SCP_Content_Experience_Profiles {
	const CONTRACT = 'mad4b.content-experience-profiles.v1';
	const PROFILE_PLAN_CONTRACT = 'mad4b.content-experience-profile-plan.v1';
	const OPTION = 'mad4b_content_experience_profiles_v1';
	const PROFILE_APPLY_ABILITY = 'mad4b/content-experience-profile-apply';
	const PROFILE_CLONE_APPLY_ABILITY = 'mad4b/content-experience-profile-clone-apply';
	const PROFILE_DELETE_APPLY_ABILITY = 'mad4b/content-experience-profile-delete-apply';
	const BOOTSTRAP_PLAN_ABILITY = 'mad4b/content-experience-bootstrap-plan';
	const BOOTSTRAP_PLAN_CONTRACT = 'mad4b.content-experience-bootstrap-plan.v1';
	const MAX_PROFILES = 64;
	const MAX_META_KEYS = 128;
	const MAX_MEDIA_META_FIELDS = 32;
	const MAX_MEDIA_GALLERY_ITEMS = 100;
	const MAX_TAXONOMIES = 32;
	const MAX_HELPERS = 32;

	private static $profiles = null;

	public static function reset_request_cache() { self::$profiles = null; return true; }

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private static function json_schema() {
		return array( 'anyOf' => array(
			array( 'type' => 'string' ), array( 'type' => 'number' ), array( 'type' => 'integer' ),
			array( 'type' => 'boolean' ), array( 'type' => 'array' ), array( 'type' => 'object' ), array( 'type' => 'null' ),
		) );
	}

	private static function sha_schema() {
		return array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$' );
	}

	public static function route_slug( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,47}$/', $value ) ) {
			return new WP_Error( 'mad4b_content_experience_slug_invalid', 'Experience slug must be 2-48 lowercase ASCII letters, digits or hyphens.' );
		}
		return $value;
	}

	public static function profile_routes( $slug, $revision = 0 ) {
		$slug = self::route_slug( $slug );
		if ( is_wp_error( $slug ) ) return array();
		$base = 'mad4b/' . $slug . '-';
		$generation = max( 0, (int) $revision );
		$apply_base = $base . ( $generation > 0 ? 'r' . $generation . '-' : '' );
		return array(
			'helpers' => $base . 'helpers',
			'create_plan' => $base . 'create-plan',
			'create_apply' => $apply_base . 'create-apply',
			'update_plan' => $base . 'update-plan',
			'update_apply' => $apply_base . 'update-apply',
			'publish_plan' => $base . 'publish-plan',
			'publish_apply' => $apply_base . 'publish-apply',
			'verify' => $base . 'verify',
		);
	}

	private static function routes_for_profile( array $profile ) {
		return isset( $profile['routes'] ) && is_array( $profile['routes'] ) && ! empty( $profile['routes'] )
			? $profile['routes']
			: self::profile_routes( isset( $profile['slug'] ) ? $profile['slug'] : '', isset( $profile['revision'] ) ? (int) $profile['revision'] : 0 );
	}

	private static function stored_profiles() {
		if ( null !== self::$profiles ) return self::$profiles;
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) $raw = array();
		$result = array();
		foreach ( $raw as $slug => $profile ) {
			if ( ! is_array( $profile ) ) continue;
			$slug_guard = self::route_slug( $slug );
			if ( is_wp_error( $slug_guard ) ) continue;
			$post_type = isset( $profile['post_type'] ) ? sanitize_key( (string) $profile['post_type'] ) : '';
			if ( '' === $post_type ) continue;
			$profile['slug'] = $slug_guard;
			$result[ $slug_guard ] = $profile;
		}
		ksort( $result, SORT_STRING );
		self::$profiles = $result;
		return self::$profiles;
	}

	public static function profile( $slug ) {
		$slug = self::route_slug( $slug );
		if ( is_wp_error( $slug ) ) return $slug;
		$profiles = self::stored_profiles();
		return isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : new WP_Error(
			'mad4b_content_experience_profile_missing',
			'Requested content experience profile is not configured.'
		);
	}

	public static function post_type_object( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		$object = post_type_exists( $post_type ) ? get_post_type_object( $post_type ) : null;
		if ( ! is_object( $object ) ) return new WP_Error( 'mad4b_content_experience_post_type_missing', 'Configured post type is not registered.' );
		if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_post_type_internal', 'Internal WordPress post types cannot be exposed as content experience profiles.' );
		}
		return $object;
	}

	public static function post_type_create_cap( $object ) {
		if ( is_object( $object ) && isset( $object->cap->create_posts ) ) return (string) $object->cap->create_posts;
		if ( is_object( $object ) && isset( $object->cap->edit_posts ) ) return (string) $object->cap->edit_posts;
		return 'edit_posts';
	}

	public static function post_type_publish_cap( $object ) {
		return is_object( $object ) && isset( $object->cap->publish_posts ) ? (string) $object->cap->publish_posts : 'publish_posts';
	}

	private static function normalize_string_list( $items, $limit, $pattern = '/^[A-Za-z0-9_.:-]+$/' ) {
		$result = array();
		foreach ( (array) $items as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value || strlen( $value ) > 191 || ! preg_match( $pattern, $value ) ) continue;
			$result[] = $value;
			if ( count( $result ) >= $limit ) break;
		}
		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );
		return $result;
	}

	public static function helper_catalog() {
		$catalog = array(
			'core.meta' => array(
				'id' => 'core.meta', 'label' => 'Post meta', 'provider' => 'core',
				'operations' => array( 'create', 'update', 'verify' ), 'reversible' => true, 'built_in' => true,
			),
			'core.taxonomies' => array(
				'id' => 'core.taxonomies', 'label' => 'Taxonomy assignments', 'provider' => 'core',
				'operations' => array( 'create', 'update', 'verify' ), 'reversible' => true, 'built_in' => true,
			),
			'core.featured-media' => array(
				'id' => 'core.featured-media', 'label' => 'Featured media', 'provider' => 'core',
				'operations' => array( 'create', 'update', 'verify' ), 'reversible' => true, 'built_in' => true,
			),
			'core.hierarchy' => array(
				'id' => 'core.hierarchy', 'label' => 'Hierarchy and menu order', 'provider' => 'core',
				'operations' => array( 'create', 'update', 'verify' ), 'reversible' => true, 'built_in' => true,
			),
		);
		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			foreach ( $registry->all() as $adapter_id => $adapter ) {
				if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base || ! method_exists( $adapter, 'content_experience_helpers' ) ) continue;
				$rows = $adapter->content_experience_helpers();
				if ( ! is_array( $rows ) ) continue;
				foreach ( $rows as $row_id => $row ) {
					if ( ! is_array( $row ) ) continue;
					$id = is_string( $row_id ) ? $row_id : ( isset( $row['id'] ) ? (string) $row['id'] : '' );
					if ( '' === $id || isset( $catalog[ $id ] ) ) continue;
					$row['id'] = $id;
					$row['adapter_id'] = (string) $adapter_id;
					$row['built_in'] = false;
					$catalog[ $id ] = $row;
				}
			}
		}
		$extended = apply_filters( 'mad4b_scp_content_experience_helper_catalog', $catalog );
		if ( ! is_array( $extended ) ) $extended = $catalog;
		$clean = array();
		foreach ( $extended as $id => $row ) {
			$id = strtolower( trim( is_string( $id ) ? $id : ( isset( $row['id'] ) ? (string) $row['id'] : '' ) ) );
			if ( '' === $id || strlen( $id ) > 96 || ! preg_match( '/^[a-z0-9][a-z0-9._:-]*$/', $id ) || ! is_array( $row ) ) continue;
			$row['id'] = $id;
			$row['provider'] = isset( $row['provider'] ) ? sanitize_key( (string) $row['provider'] ) : 'extension';
			$row['adapter_id'] = isset( $row['adapter_id'] ) ? sanitize_key( (string) $row['adapter_id'] ) : '';
			$row['certification_ability'] = isset( $row['certification_ability'] ) ? trim( (string) $row['certification_ability'] ) : '';
			$row['operations'] = self::normalize_string_list( isset( $row['operations'] ) ? $row['operations'] : array(), 8, '/^[a-z][a-z0-9_-]*$/' );
			$row['reversible'] = ! empty( $row['reversible'] );
			$row['built_in'] = ! empty( $row['built_in'] );
			$clean[ $id ] = $row;
		}
		ksort( $clean, SORT_STRING );
		return $clean;
	}

	public static function helper_catalog_sha256() {
		return hash( 'sha256', wp_json_encode( self::helper_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function normalize_profile( array $raw, $next_revision ) {
		$slug = self::route_slug( isset( $raw['slug'] ) ? $raw['slug'] : '' );
		if ( is_wp_error( $slug ) ) return $slug;
		$post_type = isset( $raw['post_type'] ) ? sanitize_key( (string) $raw['post_type'] ) : '';
		$object = self::post_type_object( $post_type );
		if ( is_wp_error( $object ) ) return $object;

		$label = isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : (string) $object->label;
		if ( '' === $label ) $label = $post_type;
		$meta_mode = isset( $raw['meta_mode'] ) ? sanitize_key( (string) $raw['meta_mode'] ) : 'allowlist';
		if ( ! in_array( $meta_mode, array( 'safe_any', 'allowlist' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_meta_mode_invalid', 'meta_mode must be safe_any or allowlist.' );
		}
		$meta_keys = self::normalize_string_list( isset( $raw['meta_keys'] ) ? $raw['meta_keys'] : array(), self::MAX_META_KEYS, '/^[A-Za-z0-9_-]+$/' );
		$protected_meta_keys = self::normalize_string_list( isset( $raw['protected_meta_keys'] ) ? $raw['protected_meta_keys'] : array(), self::MAX_META_KEYS, '/^_[A-Za-z0-9_-]+$/' );
		$media_meta_fields = MAD4B_SCP_Content_Experience_Media::normalize_field_specs( isset( $raw['media_meta_fields'] ) ? $raw['media_meta_fields'] : array(), $meta_keys, $protected_meta_keys );
		if ( is_wp_error( $media_meta_fields ) ) return $media_meta_fields;
		foreach ( $protected_meta_keys as $key ) {
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $key ) ) {
				return new WP_Error( 'mad4b_content_experience_sensitive_meta_denied', 'Sensitive/authentication-like metadata cannot be enabled by an experience profile.' );
			}
		}

		$available_taxonomies = array_keys( get_object_taxonomies( $post_type, 'objects' ) );
		$taxonomy_mode = isset( $raw['taxonomy_mode'] ) ? sanitize_key( (string) $raw['taxonomy_mode'] ) : 'allowlist';
		if ( ! in_array( $taxonomy_mode, array( 'allowlist', 'all_attached' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_taxonomy_mode_invalid', 'taxonomy_mode must be allowlist or all_attached.' );
		}
		$taxonomies = self::normalize_string_list( isset( $raw['taxonomies'] ) ? $raw['taxonomies'] : array(), self::MAX_TAXONOMIES, '/^[a-zA-Z0-9_-]+$/' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! in_array( $taxonomy, $available_taxonomies, true ) ) {
				return new WP_Error( 'mad4b_content_experience_taxonomy_mismatch', 'Configured taxonomy is not attached to the selected post type.', array( 'taxonomy' => $taxonomy ) );
			}
		}

		$catalog = self::helper_catalog();
		$enabled_helpers = self::normalize_string_list( isset( $raw['enabled_helpers'] ) ? $raw['enabled_helpers'] : array(), self::MAX_HELPERS );
		foreach ( $enabled_helpers as $helper_id ) {
			if ( ! isset( $catalog[ $helper_id ] ) ) return new WP_Error( 'mad4b_content_experience_helper_missing', 'Configured helper is not registered.', array( 'helper_id' => $helper_id ) );
			if ( ! empty( $catalog[ $helper_id ]['built_in'] ) ) return new WP_Error( 'mad4b_content_experience_builtin_helper_implicit', 'Built-in helpers are always available through their typed fields and must not be enabled as extension helpers.', array( 'helper_id' => $helper_id ) );
			if ( empty( $catalog[ $helper_id ]['adapter_id'] ) ) return new WP_Error( 'mad4b_content_experience_helper_adapter_required', 'External helpers must be owned by a registered MAD4B adapter.', array( 'helper_id' => $helper_id ) );
			$certification_ability = isset( $catalog[ $helper_id ]['certification_ability'] ) ? (string) $catalog[ $helper_id ]['certification_ability'] : '';
			if ( '' === $certification_ability || ! preg_match( '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $certification_ability ) ) {
				return new WP_Error( 'mad4b_content_experience_helper_certification_required', 'External helper must bind to one exact provider Ability used for capability certification.', array( 'helper_id' => $helper_id ) );
			}
			if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $certification_ability ) ) {
				return new WP_Error( 'mad4b_content_experience_helper_certification_ability_missing', 'External helper certification Ability is not registered in the current runtime.', array( 'helper_id' => $helper_id, 'ability' => $certification_ability ) );
			}
			if ( empty( $catalog[ $helper_id ]['reversible'] ) ) return new WP_Error( 'mad4b_content_experience_helper_not_reversible', 'Experience mutation helpers must declare a reversible contract.', array( 'helper_id' => $helper_id ) );
		}
		$helper_bindings = array();
		foreach ( $enabled_helpers as $helper_id ) {
			$row = $catalog[ $helper_id ];
			$binding = array(
				'helper_id' => $helper_id,
				'adapter_id' => (string) $row['adapter_id'],
				'provider' => (string) $row['provider'],
				'certification_ability' => (string) $row['certification_ability'],
				'operations' => array_values( (array) $row['operations'] ),
				'reversible' => ! empty( $row['reversible'] ),
			);
			$binding['binding_sha256'] = hash( 'sha256', wp_json_encode( $binding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$helper_bindings[ $helper_id ] = $binding;
		}
		ksort( $helper_bindings, SORT_STRING );

		$creation_status = isset( $raw['creation_status'] ) ? sanitize_key( (string) $raw['creation_status'] ) : 'draft';
		if ( ! in_array( $creation_status, array( 'draft', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_creation_status_invalid', 'New content must start as draft, pending or private.' );
		}
		$live_update_mode = isset( $raw['live_update_mode'] ) ? sanitize_key( (string) $raw['live_update_mode'] ) : 'draft_first';
		if ( ! in_array( $live_update_mode, array( 'draft_first', 'direct' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_live_update_mode_invalid', 'live_update_mode must be draft_first or direct.' );
		}

		$profile = array(
			'contract' => self::CONTRACT,
			'slug' => $slug,
			'label' => $label,
			'post_type' => $post_type,
			'enabled' => ! array_key_exists( 'enabled', $raw ) || ! empty( $raw['enabled'] ),
			'revision' => max( 1, (int) $next_revision ),
			'creation_status' => $creation_status,
			'live_update_mode' => $live_update_mode,
			'meta_mode' => $meta_mode,
			'meta_keys' => $meta_keys,
			'protected_meta_keys' => $protected_meta_keys,
			'media_meta_fields' => $media_meta_fields,
			'taxonomy_mode' => $taxonomy_mode,
			'taxonomies' => $taxonomies,
			'featured_media' => ! array_key_exists( 'featured_media', $raw ) || ! empty( $raw['featured_media'] ),
			'hierarchy' => ! empty( $raw['hierarchy'] ) && ! empty( $object->hierarchical ),
			'enabled_helpers' => $enabled_helpers,
			'helper_bindings' => $helper_bindings,
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'routes' => self::profile_routes( $slug, max( 1, (int) $next_revision ) ),
		);
		$profile['authority_sha256'] = class_exists( 'MAD4B_SCP_Content_Experience_Governance' )
			? MAD4B_SCP_Content_Experience_Governance::authority_sha256( $profile )
			: hash( 'sha256', wp_json_encode( $profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $profile;
	}

	public static function profile_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$raw = isset( $input['profile'] ) && is_array( $input['profile'] ) ? $input['profile'] : array();
		$slug = self::route_slug( isset( $raw['slug'] ) ? $raw['slug'] : '' );
		if ( is_wp_error( $slug ) ) return $slug;
		$profiles = self::stored_profiles();
		$current = isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : null;
		$current_revision = is_array( $current ) && isset( $current['revision'] ) ? (int) $current['revision'] : 0;
		$expected_revision = isset( $input['expected_revision'] ) ? (int) $input['expected_revision'] : $current_revision;
		if ( $expected_revision !== $current_revision ) {
			return new WP_Error( 'mad4b_content_experience_profile_revision_drift', 'Experience profile changed since planning.', array( 'current_revision' => $current_revision ) );
		}

		// post_type is part of the profile's semantic identity. Reject a widening
		// before normalizing any post-type-dependent fields (taxonomies, caps,
		// hierarchy, helpers), otherwise a secondary validation error can mask the
		// immutable-identity violation and make plan semantics order-dependent.
		if ( is_array( $current ) && isset( $current['post_type'] ) ) {
			$requested_post_type = isset( $raw['post_type'] ) ? sanitize_key( (string) $raw['post_type'] ) : '';
			if ( '' !== $requested_post_type && (string) $current['post_type'] !== $requested_post_type ) {
				return new WP_Error( 'mad4b_content_experience_post_type_immutable', 'An existing experience slug cannot change post_type. Create a new profile slug to change content type.' );
			}
		}
		$profile = self::normalize_profile( $raw, $current_revision + 1 );
		if ( is_wp_error( $profile ) ) return $profile;
		if ( is_array( $current ) && isset( $current['post_type'] ) && (string) $current['post_type'] !== (string) $profile['post_type'] ) {
			return new WP_Error( 'mad4b_content_experience_post_type_immutable', 'An existing experience slug cannot change post_type. Create a new profile slug to change content type.' );
		}
		$current_routes = is_array( $current ) ? array_values( self::routes_for_profile( $current ) ) : array();
		foreach ( array_values( $profile['routes'] ) as $route ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $route ) && ! in_array( $route, $current_routes, true ) ) {
				return new WP_Error(
					'mad4b_content_experience_route_collision',
					'Generated experience route collides with an Ability already registered by this site.',
					array( 'ability' => $route )
				);
			}
		}
		$plan = array(
			'contract' => self::PROFILE_PLAN_CONTRACT,
			'profile' => $profile,
			'current_revision' => $current_revision,
			'expected_revision' => $expected_revision,
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $plan;
	}

	public static function profile_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		$plan_input = $input;
		unset( $plan_input['plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = self::profile_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( '' === $expected || ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_content_experience_profile_plan_drift', 'Experience profile apply does not match the exact reviewed plan.' );
		}
		$profiles = self::stored_profiles();
		$profile = $plan['profile'];
		$profiles[ $profile['slug'] ] = $profile;
		if ( count( $profiles ) > self::MAX_PROFILES ) return new WP_Error( 'mad4b_content_experience_profile_limit', 'Configured content experience profile limit has been reached.' );
		ksort( $profiles, SORT_STRING );
		if ( ! update_option( self::OPTION, $profiles, false ) && get_option( self::OPTION, array() ) !== $profiles ) {
			return new WP_Error( 'mad4b_content_experience_profile_write_failed', 'Unable to persist content experience profile.' );
		}
		self::$profiles = $profiles;
		if ( class_exists( 'MAD4B_SCP_Servers' ) ) MAD4B_SCP_Servers::reset_request_cache();
		if ( class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) && method_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'reset_request_cache' ) ) {
			MAD4B_SCP_ChatGPT_Tool_Projection::reset_request_cache();
		}
		return array(
			'contract' => self::CONTRACT,
			'profile' => $profile,
			'applied' => true,
			'routes_active_next_request' => ! empty( $profile['enabled'] ),
			'plan_sha256' => $plan['plan_sha256'],
		);
	}

	public static function profile_clone_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$source = self::profile( isset( $input['source_slug'] ) ? $input['source_slug'] : '' );
		if ( is_wp_error( $source ) ) return $source;
		$new_slug = self::route_slug( isset( $input['new_slug'] ) ? $input['new_slug'] : '' );
		if ( is_wp_error( $new_slug ) ) return $new_slug;
		if ( ! is_wp_error( self::profile( $new_slug ) ) ) return new WP_Error( 'mad4b_content_experience_clone_target_exists', 'Clone target profile already exists.' );
		$copy = $source;
		unset( $copy['revision'], $copy['routes'], $copy['authority_sha256'], $copy['helper_catalog_sha256'] );
		$copy['slug'] = $new_slug;
		if ( isset( $input['label'] ) ) $copy['label'] = sanitize_text_field( (string) $input['label'] );
		if ( isset( $input['post_type'] ) ) $copy['post_type'] = sanitize_key( (string) $input['post_type'] );
		$profile_plan = self::profile_plan( array( 'profile' => $copy, 'expected_revision' => 0 ) );
		if ( is_wp_error( $profile_plan ) ) return $profile_plan;
		return array(
			'contract' => self::PROFILE_PLAN_CONTRACT,
			'operation' => 'clone',
			'source_slug' => (string) $source['slug'],
			'source_authority_sha256' => isset( $source['authority_sha256'] ) ? (string) $source['authority_sha256'] : '',
			'profile_plan' => $profile_plan,
			'plan_sha256' => $profile_plan['plan_sha256'],
			'content_copied' => false,
			'mutation_performed' => false,
		);
	}

	public static function profile_clone_apply( $input ) {
		$plan = self::profile_clone_plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		$expected = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $plan['plan_sha256'], $expected ) ) return new WP_Error( 'mad4b_content_experience_clone_plan_drift', 'Profile clone apply does not match the exact reviewed plan.' );
		$p = $plan['profile_plan'];
		return self::profile_apply( array( 'profile' => $p['profile'], 'expected_revision' => 0, 'plan_sha256' => $p['plan_sha256'] ) );
	}

	public static function profile_delete_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$profile = self::profile( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $profile ) ) return $profile;
		$expected_revision = isset( $input['expected_revision'] ) ? (int) $input['expected_revision'] : (int) $profile['revision'];
		if ( $expected_revision !== (int) $profile['revision'] ) return new WP_Error( 'mad4b_content_experience_profile_revision_drift', 'Experience profile changed since delete planning.' );
		$plan = array(
			'contract' => self::PROFILE_PLAN_CONTRACT,
			'operation' => 'delete',
			'slug' => (string) $profile['slug'],
			'expected_revision' => $expected_revision,
			'profile_authority_sha256' => isset( $profile['authority_sha256'] ) ? (string) $profile['authority_sha256'] : '',
			'content_preserved' => true,
			'routes_removed_next_request' => true,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = hash( 'sha256', wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $plan;
	}

	public static function profile_delete_apply( $input ) {
		$plan = self::profile_delete_plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		$expected = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $plan['plan_sha256'], $expected ) ) return new WP_Error( 'mad4b_content_experience_delete_plan_drift', 'Profile delete apply does not match the exact reviewed plan.' );
		$profiles = self::stored_profiles();
		unset( $profiles[ $plan['slug'] ] );
		ksort( $profiles, SORT_STRING );
		if ( ! update_option( self::OPTION, $profiles, false ) && get_option( self::OPTION, array() ) !== $profiles ) return new WP_Error( 'mad4b_content_experience_profile_delete_failed', 'Unable to persist profile decommission.' );
		self::$profiles = $profiles;
		if ( class_exists( 'MAD4B_SCP_Servers' ) ) MAD4B_SCP_Servers::reset_request_cache();
		if ( class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) && method_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection', 'reset_request_cache' ) ) MAD4B_SCP_ChatGPT_Tool_Projection::reset_request_cache();
		return array( 'contract' => self::CONTRACT, 'slug' => $plan['slug'], 'deleted' => true, 'content_preserved' => true, 'plan_sha256' => $plan['plan_sha256'] );
	}

	public static function profile_status( $input = array() ) {
		$profiles = array();
		foreach ( self::stored_profiles() as $slug => $profile ) {
			$authority_guard = class_exists( 'MAD4B_SCP_Content_Experience_Governance' ) ? MAD4B_SCP_Content_Experience_Governance::current_guard( $profile ) : new WP_Error( 'mad4b_content_experience_governance_unavailable', 'Content experience governance unavailable.' );
			$profiles[] = array(
				'slug' => $slug,
				'label' => isset( $profile['label'] ) ? (string) $profile['label'] : $slug,
				'post_type' => (string) $profile['post_type'],
				'enabled' => ! empty( $profile['enabled'] ),
				'revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
				'runtime_post_type_ready' => ! is_wp_error( self::post_type_object( $profile['post_type'] ) ),
				'helper_catalog_match' => isset( $profile['helper_catalog_sha256'] ) && hash_equals( (string) $profile['helper_catalog_sha256'], self::helper_catalog_sha256() ),
				'authority_sha256' => isset( $profile['authority_sha256'] ) ? (string) $profile['authority_sha256'] : '',
				'authority_current' => ! is_wp_error( $authority_guard ),
				'migration_required' => empty( $profile['authority_sha256'] ),
				'authority_blocker' => is_wp_error( $authority_guard ) ? $authority_guard->get_error_code() : '',
				'executor_generation' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
				'routes' => self::routes_for_profile( $profile ),
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'profiles' => $profiles,
			'count' => count( $profiles ),
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'dynamic_routes_are_configuration_driven' => true,
			'hardcoded_business_content_types' => false,
		);
	}

	public static function discover( $input = array() ) {
		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $post_type => $object ) {
			if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) continue;
			$taxonomies = array();
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $taxonomy ) {
				$taxonomies[] = array(
					'name' => (string) $name,
					'label' => (string) $taxonomy->label,
					'hierarchical' => ! empty( $taxonomy->hierarchical ),
					'can_assign' => current_user_can( isset( $taxonomy->cap->assign_terms ) ? $taxonomy->cap->assign_terms : 'edit_posts' ),
				);
			}
			$configured_profiles = array();
			foreach ( self::stored_profiles() as $stored_slug => $stored_profile ) if ( isset( $stored_profile['post_type'] ) && (string) $post_type === (string) $stored_profile['post_type'] ) $configured_profiles[] = (string) $stored_slug;
			$items[] = array(
				'post_type' => (string) $post_type,
				'label' => (string) $object->label,
				'public' => ! empty( $object->public ),
				'show_ui' => ! empty( $object->show_ui ),
				'show_in_rest' => ! empty( $object->show_in_rest ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'can_create' => current_user_can( self::post_type_create_cap( $object ) ),
				'can_publish' => current_user_can( self::post_type_publish_cap( $object ) ),
				'suggested_profile_slug' => trim( substr( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $post_type ) ), 0, 48 ), '-' ),
				'configured_profile_slugs' => $configured_profiles,
				'bootstrap_plan_ability' => self::BOOTSTRAP_PLAN_ABILITY,
				'media_field_candidates' => self::media_field_candidates( (string) $post_type, false ),
				'taxonomies' => $taxonomies,
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'post_types' => $items,
			'profiles' => array_values( self::stored_profiles() ),
			'helper_catalog' => array_values( self::helper_catalog() ),
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'profile_configuration_required_before_routes_exist' => true,
			'bootstrap_plan_ability' => self::BOOTSTRAP_PLAN_ABILITY,
			'supported_scenarios' => array( 'remote_media_library_first', 'create_nonpublic', 'create_structured', 'update_existing', 'publish_or_private', 'verify', 'rollback' ),
			'safe_defaults' => array( 'meta_mode' => 'allowlist', 'taxonomy_mode' => 'allowlist', 'live_update_mode' => 'draft_first' ),
			'mutation_performed' => false,
		);
	}

	/**
	 * Build a safe, non-authorizing profile proposal from the live post-type model.
	 *
	 * This intentionally does not infer business-specific meta keys or enable
	 * external helpers. Those remain explicit operator decisions. The goal is to
	 * remove expert-only boilerplate while preserving exact profile authority.
	 */
	public static function bootstrap_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$object = self::post_type_object( $post_type );
		if ( is_wp_error( $object ) ) return $object;

		$explicit_slug = isset( $input['profile_slug'] ) ? trim( (string) $input['profile_slug'] ) : '';
		$existing_for_type = array();
		foreach ( self::stored_profiles() as $stored ) {
			if ( isset( $stored['post_type'] ) && $post_type === (string) $stored['post_type'] ) $existing_for_type[] = $stored;
		}
		if ( '' === $explicit_slug && $existing_for_type ) {
			usort( $existing_for_type, static function ( $a, $b ) {
				$ar = is_array( $a ) && isset( $a['revision'] ) ? (int) $a['revision'] : 0;
				$br = is_array( $b ) && isset( $b['revision'] ) ? (int) $b['revision'] : 0;
				return $br <=> $ar;
			} );
			$existing = $existing_for_type[0];
			return array(
				'contract' => self::BOOTSTRAP_PLAN_CONTRACT,
				'state' => 'ALREADY_CONFIGURED',
				'post_type' => $post_type,
				'existing_profile' => $existing,
				'scenarios' => self::scenario_matrix( $existing, true ),
				'workflow_blueprint' => self::workflow_blueprint( $existing, true ),
				'profile_apply_ability' => self::PROFILE_APPLY_ABILITY,
				'apply_required' => false,
				'mutation_performed' => false,
			);
		}

		$slug_seed = '' !== $explicit_slug ? $explicit_slug : preg_replace( '/[^a-z0-9]+/', '-', strtolower( $post_type ) );
		$slug_seed = trim( substr( (string) $slug_seed, 0, 48 ), '-' );
		if ( strlen( $slug_seed ) < 2 ) $slug_seed = 'content-' . $slug_seed;
		$slug = self::route_slug( $slug_seed );
		if ( is_wp_error( $slug ) ) return $slug;

		$taxonomy_strategy = isset( $input['taxonomy_strategy'] ) ? sanitize_key( (string) $input['taxonomy_strategy'] ) : 'public_assignable';
		if ( ! in_array( $taxonomy_strategy, array( 'none', 'public_assignable', 'all_assignable' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_bootstrap_taxonomy_strategy_invalid', 'taxonomy_strategy must be none, public_assignable or all_assignable.' );
		}

		$included_taxonomies = array();
		$excluded_taxonomies = array();
		$taxonomy_objects = get_object_taxonomies( $post_type, 'objects' );
		if ( is_array( $taxonomy_objects ) ) ksort( $taxonomy_objects, SORT_STRING );
		foreach ( is_array( $taxonomy_objects ) ? $taxonomy_objects : array() as $name => $taxonomy ) {
			$name = sanitize_key( (string) $name );
			$assign_cap = is_object( $taxonomy ) && isset( $taxonomy->cap->assign_terms ) ? (string) $taxonomy->cap->assign_terms : 'edit_posts';
			$assignable = current_user_can( $assign_cap );
			$operator_visible = is_object( $taxonomy ) && ( ! empty( $taxonomy->public ) || ! empty( $taxonomy->show_ui ) || ! empty( $taxonomy->show_in_rest ) );
			$reason = '';
			if ( ! $assignable ) $reason = 'assignment_capability_missing';
			elseif ( 'none' === $taxonomy_strategy ) $reason = 'strategy_none';
			elseif ( 'public_assignable' === $taxonomy_strategy && ! $operator_visible ) $reason = 'internal_taxonomy_excluded';
			elseif ( count( $included_taxonomies ) >= self::MAX_TAXONOMIES ) $reason = 'profile_taxonomy_limit';
			if ( '' !== $reason ) {
				$excluded_taxonomies[] = array( 'taxonomy' => $name, 'reason' => $reason );
				continue;
			}
			$included_taxonomies[] = $name;
		}

		$supports_featured_media = function_exists( 'post_type_supports' ) ? (bool) post_type_supports( $post_type, 'thumbnail' ) : false;
		$featured_media = array_key_exists( 'featured_media', $input ) ? (bool) $input['featured_media'] : $supports_featured_media;
		$warnings = array();
		if ( $featured_media && ! $supports_featured_media ) {
			$featured_media = false;
			$warnings[] = 'featured_media_requested_but_post_type_does_not_support_thumbnail';
		}

		$creation_status = isset( $input['creation_status'] ) ? sanitize_key( (string) $input['creation_status'] ) : 'draft';
		if ( ! in_array( $creation_status, array( 'draft', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_bootstrap_creation_status_invalid', 'creation_status must be draft, pending or private.' );
		}
		$live_update_mode = isset( $input['live_update_mode'] ) ? sanitize_key( (string) $input['live_update_mode'] ) : 'draft_first';
		if ( ! in_array( $live_update_mode, array( 'draft_first', 'direct' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_bootstrap_live_update_mode_invalid', 'live_update_mode must be draft_first or direct.' );
		}

		$media_field_candidates = self::media_field_candidates( $post_type, true );
		$requested_media_fields = isset( $input['media_meta_fields'] ) ? $input['media_meta_fields'] : array();
		if ( ! is_array( $requested_media_fields ) ) return new WP_Error( 'mad4b_content_experience_bootstrap_media_fields_invalid', 'media_meta_fields must be an object keyed by post meta key.' );
		$allow_protected_media_meta = ! empty( $input['allow_protected_media_meta'] );
		$meta_keys = array(); $protected_meta_keys = array();
		foreach ( array_keys( $requested_media_fields ) as $media_key ) {
			$media_key = (string) $media_key;
			if ( '' === $media_key || strlen( $media_key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $media_key ) ) {
				return new WP_Error( 'mad4b_content_experience_bootstrap_media_key_invalid', 'Requested media meta mapping contains an invalid key.' );
			}
			if ( 0 === strpos( $media_key, '_' ) ) {
				if ( ! $allow_protected_media_meta ) return new WP_Error( 'mad4b_content_experience_bootstrap_protected_media_requires_opt_in', 'Protected media meta requires allow_protected_media_meta=true.' );
				$protected_meta_keys[] = $media_key;
			} else $meta_keys[] = $media_key;
		}
		$meta_keys = array_values( array_unique( $meta_keys ) );
		$protected_meta_keys = array_values( array_unique( $protected_meta_keys ) );

		$label = isset( $input['label'] ) ? sanitize_text_field( (string) $input['label'] ) : ( isset( $object->label ) ? (string) $object->label : $post_type );
		$profile = array(
			'slug' => $slug,
			'label' => $label,
			'post_type' => $post_type,
			'enabled' => true,
			'creation_status' => $creation_status,
			'live_update_mode' => $live_update_mode,
			'meta_mode' => 'allowlist',
			'meta_keys' => $meta_keys,
			'protected_meta_keys' => $protected_meta_keys,
			'media_meta_fields' => $requested_media_fields,
			'taxonomy_mode' => 'allowlist',
			'taxonomies' => $included_taxonomies,
			'featured_media' => $featured_media,
			'hierarchy' => ! empty( $object->hierarchical ),
			'enabled_helpers' => array(),
		);

		$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0;
		$profile_plan = self::profile_plan( array( 'profile' => $profile, 'expected_revision' => $expected_revision ) );
		if ( is_wp_error( $profile_plan ) ) return $profile_plan;

		$helper_candidates = array();
		foreach ( self::helper_catalog() as $helper ) {
			if ( ! is_array( $helper ) || ! empty( $helper['built_in'] ) ) continue;
			$certification_ability = isset( $helper['certification_ability'] ) ? (string) $helper['certification_ability'] : '';
			$helper_candidates[] = array(
				'id' => isset( $helper['id'] ) ? (string) $helper['id'] : '',
				'label' => isset( $helper['label'] ) ? (string) $helper['label'] : '',
				'provider' => isset( $helper['provider'] ) ? (string) $helper['provider'] : '',
				'operations' => isset( $helper['operations'] ) ? array_values( (array) $helper['operations'] ) : array(),
				'reversible' => ! empty( $helper['reversible'] ),
				'certification_ability' => $certification_ability,
				'certification_ability_registered' => '' !== $certification_ability && ( ! function_exists( 'wp_has_ability' ) || wp_has_ability( $certification_ability ) ),
				'auto_enabled' => false,
			);
		}

		return array(
			'contract' => self::BOOTSTRAP_PLAN_CONTRACT,
			'state' => 'PROFILE_PROPOSED',
			'post_type' => $post_type,
			'taxonomy_strategy' => $taxonomy_strategy,
			'included_taxonomies' => $included_taxonomies,
			'excluded_taxonomies' => $excluded_taxonomies,
			'supports_featured_media' => $supports_featured_media,
			'media_field_candidates' => $media_field_candidates,
			'requested_media_meta_fields' => array_keys( $requested_media_fields ),
			'helper_candidates' => $helper_candidates,
			'warnings' => $warnings,
			'safe_defaults' => array(
				'new_content_is_nonpublic' => true,
				'meta_allowlist_starts_empty_unless_explicit_media_mapping_is_supplied' => true,
				'external_helpers_start_disabled' => true,
				'taxonomies_are_explicit_allowlist' => true,
			),
			'profile_plan' => $profile_plan,
			'profile_apply_ability' => self::PROFILE_APPLY_ABILITY,
			'apply_input' => array(
				'profile' => $profile_plan['profile'],
				'expected_revision' => $profile_plan['expected_revision'],
				'plan_sha256' => $profile_plan['plan_sha256'],
			),
			'scenarios' => self::scenario_matrix( $profile_plan['profile'], false ),
			'workflow_blueprint' => self::workflow_blueprint( $profile_plan['profile'], false ),
			'routes_active_after_profile_apply_and_next_request' => true,
			'mutation_performed' => false,
		);
	}

	private static function media_field_candidates( $post_type, $deep = false ) {
		$post_type = sanitize_key( (string) $post_type );
		$candidates = array();

		$append = static function ( array &$rows, $key, $source, $type, array $spec, $protected = false, array $evidence = array() ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) return;
			if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::is_sensitive_database_column( $key ) ) return;
			$rows[] = array(
				'key' => $key,
				'source' => sanitize_key( (string) $source ),
				'schema_type' => sanitize_key( (string) $type ),
				'protected' => (bool) $protected || 0 === strpos( $key, '_' ),
				'suggested_spec' => $spec,
				'evidence' => $evidence,
				'auto_enabled' => false,
			);
		};

		$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $post_type ) : array();
		foreach ( is_array( $registered ) ? $registered : array() as $key => $schema ) {
			$key = (string) $key;
			if ( ! preg_match( '/(?:image|gallery|media|photo|thumbnail|hero|banner)/i', $key ) ) continue;
			$type = is_array( $schema ) && isset( $schema['type'] ) ? sanitize_key( (string) $schema['type'] ) : '';
			$is_gallery = (bool) preg_match( '/(?:gallery|images|photos|media_ids)/i', $key ) || 'array' === $type;
			$spec = $is_gallery
				? array( 'kind' => 'image_gallery', 'storage' => 'array' === $type ? 'ids' : 'csv_ids', 'max_items' => 50 )
				: array( 'kind' => 'image_id', 'storage' => 'id', 'max_items' => 1 );
			$append( $candidates, $key, 'registered_post_meta', $type, $spec, 0 === strpos( $key, '_' ), array( 'registered_schema' => true ) );
		}

		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			if ( method_exists( $registry, 'register_defaults' ) ) $registry->register_defaults();
			foreach ( method_exists( $registry, 'all' ) ? $registry->all() : array() as $adapter_id => $adapter ) {
				if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'content_experience_media_field_candidates' ) ) continue;
				$declared = $adapter->content_experience_media_field_candidates( $post_type );
				if ( ! is_array( $declared ) ) continue;
				foreach ( array_slice( $declared, 0, self::MAX_MEDIA_META_FIELDS * 2 ) as $row ) {
					if ( ! is_array( $row ) || empty( $row['key'] ) ) continue;
					$append(
						$candidates,
						$row['key'],
						'provider_' . sanitize_key( (string) $adapter_id ),
						isset( $row['schema_type'] ) ? $row['schema_type'] : '',
						isset( $row['suggested_spec'] ) && is_array( $row['suggested_spec'] ) ? $row['suggested_spec'] : array(),
						! empty( $row['protected'] ),
						array( 'provider_declared' => true )
					);
				}
			}
		}

		// Deep bootstrap can learn from existing content without returning values.
		// Global discovery stays shallow to avoid N×CPT query amplification.
		if ( $deep ) {
			$post_ids = get_posts( array(
				'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => 8,
				'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC',
				'no_found_rows' => true, 'suppress_filters' => true,
			) );
			$sampled = array();
			foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
				foreach ( (array) get_post_meta( $post_id ) as $key => $raw_values ) {
					$key = (string) $key;
					if ( ! preg_match( '/(?:image|gallery|media|photo|thumbnail|hero|banner)/i', $key ) ) continue;
					if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::is_sensitive_database_column( $key ) ) continue;
					$value = get_post_meta( $post_id, $key, true );
					$inference = self::infer_sampled_media_meta( $value );
					if ( empty( $inference['supported'] ) ) continue;
					if ( ! isset( $sampled[ $key ] ) ) $sampled[ $key ] = array( 'count' => 0, 'inference' => $inference );
					if ( $sampled[ $key ]['inference']['spec'] === $inference['spec'] ) ++$sampled[ $key ]['count'];
				}
			}
			foreach ( $sampled as $key => $row ) {
				$append(
					$candidates,
					$key,
					'sampled_post_meta',
					$row['inference']['schema_type'],
					$row['inference']['spec'],
					0 === strpos( $key, '_' ),
					array( 'sampled_match_count' => (int) $row['count'], 'values_disclosed' => false )
				);
			}
		}

		$extended = apply_filters( 'mad4b_scp_content_experience_media_field_candidates', $candidates, $post_type, (bool) $deep );
		$extended = is_array( $extended ) ? $extended : $candidates;
		$out = array(); $seen = array();
		foreach ( array_slice( $extended, 0, self::MAX_MEDIA_META_FIELDS * 6 ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) || ! is_string( $row['key'] ) ) continue;
			$key = (string) $row['key'];
			if ( strlen( $key ) > 191 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) continue;
			if ( isset( $seen[ $key ] ) ) {
				$index = $seen[ $key ];
				$existing_sources = isset( $out[ $index ]['sources'] ) ? $out[ $index ]['sources'] : array( $out[ $index ]['source'] );
				$existing_sources[] = isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'provider';
				$out[ $index ]['sources'] = array_values( array_unique( array_filter( $existing_sources ) ) );
				continue;
			}
			$seen[ $key ] = count( $out );
			$out[] = array(
				'key' => $key,
				'source' => isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'provider',
				'sources' => array( isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : 'provider' ),
				'schema_type' => isset( $row['schema_type'] ) ? sanitize_key( (string) $row['schema_type'] ) : '',
				'protected' => ! empty( $row['protected'] ) || 0 === strpos( $key, '_' ),
				'suggested_spec' => isset( $row['suggested_spec'] ) && is_array( $row['suggested_spec'] ) ? $row['suggested_spec'] : array(),
				'evidence' => isset( $row['evidence'] ) && is_array( $row['evidence'] ) ? $row['evidence'] : array(),
				'auto_enabled' => false,
			);
		}
		return $out;
	}

	private static function infer_sampled_media_meta( $value ) {
		$is_attachment = static function ( $id ) {
			$id = absint( $id );
			return $id > 0 && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id );
		};
		if ( is_numeric( $value ) && $is_attachment( $value ) ) {
			return array( 'supported' => true, 'schema_type' => 'integer', 'spec' => array( 'kind' => 'image_id', 'storage' => 'id', 'max_items' => 1 ) );
		}
		if ( is_string( $value ) && preg_match( '/^\s*\d+(?:\s*,\s*\d+)+\s*$/', $value ) ) {
			$ids = array_values( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', trim( $value ) ) ) ) );
			if ( $ids && count( array_filter( $ids, $is_attachment ) ) === count( $ids ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'csv_ids', 'max_items' => min( self::MAX_MEDIA_GALLERY_ITEMS, max( 1, count( $ids ) ) ) ) );
		}
		if ( is_array( $value ) && array_values( $value ) === $value ) {
			$ids = array_values( array_filter( array_map( 'absint', $value ) ) );
			if ( $ids && count( $ids ) === count( $value ) && count( array_filter( $ids, $is_attachment ) ) === count( $ids ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'ids', 'max_items' => min( self::MAX_MEDIA_GALLERY_ITEMS, max( 1, count( $ids ) ) ) ) );
		}
		return array( 'supported' => false );
	}

	private static function workflow_blueprint( array $profile, $active ) {
		$routes = self::routes_for_profile( $profile );
		$media = class_exists( 'MAD4B_SCP_Remote_Media_Adapter' )
			? array(
				'discover' => MAD4B_SCP_Remote_Media_Adapter::REMOTE_DISCOVER_ABILITY,
				'inspect' => MAD4B_SCP_Remote_Media_Adapter::REMOTE_INSPECT_ABILITY,
				'import_plan' => MAD4B_SCP_Remote_Media_Adapter::REMOTE_IMPORT_PLAN_ABILITY,
				'import_apply' => MAD4B_SCP_Remote_Media_Adapter::REMOTE_IMPORT_APPLY_ABILITY,
			)
			: array(
				'discover' => 'media/remote-source-discover',
				'inspect' => 'media/remote-image-inspect',
				'import_plan' => 'media/remote-import-plan',
				'import_apply' => 'media/remote-import-apply',
			);
		return array(
			'contract' => 'mad4b.content-experience-ingestion-workflow.v1',
			'profile_slug' => isset( $profile['slug'] ) ? (string) $profile['slug'] : '',
			'post_type' => isset( $profile['post_type'] ) ? (string) $profile['post_type'] : '',
			'active_now' => (bool) $active,
			'activation_barrier' => $active ? 'none' : 'profile_apply_then_next_request',
			'steps' => array(
				array( 'id' => 'discover_source_media', 'ability' => $media['discover'], 'surface' => 'read', 'optional' => true, 'repeat' => 'per_source_page' ),
				array( 'id' => 'inspect_selected_media', 'ability' => $media['inspect'], 'surface' => 'read', 'optional' => true, 'repeat' => 'per_selected_candidate', 'produces' => array( 'expected_content_sha256', 'expected_content_bytes', 'expected_mime_type', 'expected_width', 'expected_height' ) ),
				array( 'id' => 'plan_media_import', 'ability' => $media['import_plan'], 'surface' => 'read', 'optional' => true, 'repeat' => 'per_selected_candidate', 'requires' => array( 'rights_basis', 'exact_content_evidence_or_existing_library_identity' ) ),
				array( 'id' => 'apply_media_import', 'ability' => $media['import_apply'], 'surface' => 'content', 'optional' => true, 'repeat' => 'per_selected_candidate', 'produces' => array( 'attachment_id' ), 'ordering' => 'before_post_media_binding' ),
				array( 'id' => 'plan_content_create', 'ability' => $routes['create_plan'], 'surface' => 'read', 'optional' => false ),
				array( 'id' => 'apply_content_create', 'ability' => $routes['create_apply'], 'surface' => 'content', 'optional' => false, 'consumes' => array( 'attachment_ids_as_featured_media_or_configured_media_meta' ) ),
				array( 'id' => 'verify_content', 'ability' => $routes['verify'], 'surface' => 'read', 'optional' => false ),
				array( 'id' => 'plan_publish', 'ability' => $routes['publish_plan'], 'surface' => 'read', 'optional' => true, 'ordering' => 'final_visible_transition_only' ),
				array( 'id' => 'apply_publish', 'ability' => $routes['publish_apply'], 'surface' => 'content', 'optional' => true, 'ordering' => 'after_successful_verify_only' ),
			),
			'data_bindings' => array(
				'imported_attachment_ids' => array( 'featured_media_id', 'configured_media_meta_fields' ),
				'imported_media_provenance' => 'publish_time_remote_provenance_guard',
				'created_post_id' => array( 'verify', 'update', 'publish' ),
			),
			'failure_semantics' => array(
				'imported_assets_survive_later_post_failure' => true,
				'publish_is_never_implicit' => true,
				'gallery_order_is_client_selected_attachment_order' => true,
				'blind_retry_allowed' => false,
			),
			'hardcoded_business_content_types' => false,
			'authorizing' => false,
		);
	}

	private static function scenario_matrix( array $profile, $active ) {
		$routes = self::routes_for_profile( $profile );
		return array(
			'remote_media_library_first' => array(
				'supported' => true,
				'discover' => class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_DISCOVER_ABILITY : 'media/remote-source-discover',
				'import_plan' => class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_IMPORT_PLAN_ABILITY : 'media/remote-import-plan',
				'import_apply' => class_exists( 'MAD4B_SCP_Remote_Media_Adapter' ) ? MAD4B_SCP_Remote_Media_Adapter::REMOTE_IMPORT_APPLY_ABILITY : 'media/remote-import-apply',
				'bind_after_import' => array( 'featured_media_id', 'configured media_meta_fields' ),
				'rights_confirmation_required_before_import' => true,
				'active_now' => true,
			),
			'create_nonpublic' => array(
				'supported' => true,
				'planner' => $routes['create_plan'],
				'executor' => $routes['create_apply'],
				'initial_status' => isset( $profile['creation_status'] ) ? (string) $profile['creation_status'] : 'draft',
				'active_now' => (bool) $active,
			),
			'create_structured' => array(
				'supported' => true,
				'fields' => array( 'title', 'content', 'excerpt', 'slug', 'parent', 'menu_order', 'meta', 'taxonomies', 'featured_media', 'helpers' ),
				'planner' => $routes['create_plan'],
				'executor' => $routes['create_apply'],
				'active_now' => (bool) $active,
			),
			'update_existing' => array(
				'supported' => true,
				'optimistic_concurrency_required' => true,
				'planner' => $routes['update_plan'],
				'executor' => $routes['update_apply'],
				'active_now' => (bool) $active,
			),
			'publish_or_private' => array(
				'supported' => true,
				'final_visible_transition' => true,
				'planner' => $routes['publish_plan'],
				'executor' => $routes['publish_apply'],
				'active_now' => (bool) $active,
			),
			'verify' => array(
				'supported' => true,
				'ability' => $routes['verify'],
				'active_now' => (bool) $active,
			),
			'rollback' => array(
				'supported' => true,
				'contract' => 'mad4b.rollback.content-experience.v1',
				'active_now' => (bool) $active,
			),
		);
	}

	public static function ability_names( $surface ) {
		$surface = sanitize_key( (string) $surface );
		$read = array( 'mad4b/content-experience-discover', self::BOOTSTRAP_PLAN_ABILITY, 'mad4b/content-experience-profile-status', 'mad4b/content-experience-profile-plan', 'mad4b/content-experience-profile-clone-plan', 'mad4b/content-experience-profile-delete-plan' );
		$content = array( self::PROFILE_APPLY_ABILITY, self::PROFILE_CLONE_APPLY_ABILITY, self::PROFILE_DELETE_APPLY_ABILITY );
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::routes_for_profile( $profile );
			$read = array_merge( $read, array( $routes['helpers'], $routes['create_plan'], $routes['update_plan'], $routes['publish_plan'], $routes['verify'] ) );
			$content = array_merge( $content, array( $routes['create_apply'], $routes['update_apply'], $routes['publish_apply'] ) );
		}
		if ( 'read' === $surface ) return array_values( array_unique( $read ) );
		if ( 'content' === $surface ) return array_values( array_unique( $content ) );
		return array();
	}

	public static function high_impact_abilities() {
		$abilities = array( self::PROFILE_APPLY_ABILITY, self::PROFILE_CLONE_APPLY_ABILITY, self::PROFILE_DELETE_APPLY_ABILITY );
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::routes_for_profile( $profile );
			if ( ! empty( $routes['publish_apply'] ) ) $abilities[] = $routes['publish_apply'];
		}
		$abilities = array_values( array_unique( array_map( 'strval', $abilities ) ) );
		sort( $abilities, SORT_STRING );
		return $abilities;
	}

	public static function reversible_contracts() {
		$result = array(
			self::PROFILE_APPLY_ABILITY => 'mad4b.rollback.content-experience-profile.v1',
			self::PROFILE_CLONE_APPLY_ABILITY => 'mad4b.rollback.content-experience-profile.v1',
			self::PROFILE_DELETE_APPLY_ABILITY => 'mad4b.rollback.content-experience-profile.v1',
		);
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::routes_for_profile( $profile );
			foreach ( array( 'create_apply', 'update_apply', 'publish_apply' ) as $key ) $result[ $routes[ $key ] ] = 'mad4b.rollback.content-experience.v1';
		}
		return $result;
	}

	private static function common_payload_schema( $require_post_id ) {
		$properties = array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
			'post_title' => array( 'type' => 'string' ),
			'post_content' => array( 'type' => 'string' ),
			'post_excerpt' => array( 'type' => 'string' ),
			'post_name' => array( 'type' => 'string', 'maxLength' => 200 ),
			'post_parent' => array( 'type' => 'integer', 'minimum' => 0 ),
			'menu_order' => array( 'type' => 'integer' ),
			'featured_media_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'meta' => array( 'type' => 'object', 'additionalProperties' => self::json_schema() ),
			'taxonomies' => array( 'type' => 'object', 'additionalProperties' => true ),
			'helpers' => array( 'type' => 'object', 'additionalProperties' => true ),
			'plan_sha256' => self::sha_schema(),
		);
		return self::schema( $properties, $require_post_id ? array( 'post_id', 'expected_modified_gmt' ) : array( 'post_title' ) );
	}

	private static function publish_schema() {
		return self::schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
			'post_status' => array( 'type' => 'string', 'enum' => array( 'publish', 'private' ), 'default' => 'publish' ),
			'helpers' => array( 'type' => 'object', 'additionalProperties' => true ),
			'plan_sha256' => self::sha_schema(),
		), array( 'post_id', 'expected_modified_gmt' ) );
	}

	public static function ability_definitions() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$definitions = array(
			array( 'name' => 'mad4b/content-experience-discover', 'label' => 'Discover Content Experience Profiles', 'callback' => array( __CLASS__, 'discover' ), 'permission' => $read, 'schema' => self::schema( array() ), 'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array(
				'name' => self::BOOTSTRAP_PLAN_ABILITY, 'label' => 'Plan Content Experience Bootstrap', 'callback' => array( __CLASS__, 'bootstrap_plan' ), 'permission' => $read,
				'schema' => self::schema( array(
					'post_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z0-9_-]+$' ),
					'profile_slug' => array( 'type' => 'string', 'maxLength' => 48 ),
					'label' => array( 'type' => 'string', 'maxLength' => 200 ),
					'taxonomy_strategy' => array( 'type' => 'string', 'enum' => array( 'none', 'public_assignable', 'all_assignable' ), 'default' => 'public_assignable' ),
					'featured_media' => array( 'type' => 'boolean' ),
					'creation_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private' ), 'default' => 'draft' ),
					'live_update_mode' => array( 'type' => 'string', 'enum' => array( 'draft_first', 'direct' ), 'default' => 'draft_first' ),
					'media_meta_fields' => array( 'type' => 'object', 'additionalProperties' => true ),
					'allow_protected_media_meta' => array( 'type' => 'boolean', 'default' => false ),
					'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
				), array( 'post_type' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array( 'name' => 'mad4b/content-experience-profile-status', 'label' => 'Content Experience Profile Status', 'callback' => array( __CLASS__, 'profile_status' ), 'permission' => $read, 'schema' => self::schema( array() ), 'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array(
				'name' => 'mad4b/content-experience-profile-plan', 'label' => 'Plan Content Experience Profile', 'callback' => array( __CLASS__, 'profile_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'profile' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => 'mad4b/content-experience-profile-clone-plan', 'label' => 'Plan Content Experience Profile Clone', 'callback' => array( __CLASS__, 'profile_clone_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'source_slug' => array( 'type' => 'string' ), 'new_slug' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'post_type' => array( 'type' => 'string' ) ), array( 'source_slug', 'new_slug' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::PROFILE_CLONE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile Clone', 'callback' => array( __CLASS__, 'profile_clone_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'source_slug' => array( 'type' => 'string' ), 'new_slug' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'post_type' => array( 'type' => 'string' ), 'plan_sha256' => self::sha_schema() ), array( 'source_slug', 'new_slug', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
			array(
				'name' => 'mad4b/content-experience-profile-delete-plan', 'label' => 'Plan Content Experience Profile Decommission', 'callback' => array( __CLASS__, 'profile_delete_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'slug' => array( 'type' => 'string' ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'slug' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::PROFILE_DELETE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile Decommission', 'callback' => array( __CLASS__, 'profile_delete_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'slug' => array( 'type' => 'string' ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ), 'plan_sha256' => self::sha_schema() ), array( 'slug', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
			array(
				'name' => self::PROFILE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile', 'callback' => array( __CLASS__, 'profile_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ), 'plan_sha256' => self::sha_schema() ), array( 'profile', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => false,
			),
		);
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$slug = (string) $profile['slug'];
			$routes = self::routes_for_profile( $profile );
			$label = isset( $profile['label'] ) ? (string) $profile['label'] : $slug;
			$definitions[] = self::route_definition( $routes['helpers'], $label . ' Helpers', $slug, 'helpers', 'read', self::schema( array() ) );
			$definitions[] = self::route_definition( $routes['create_plan'], 'Plan ' . $label . ' Create', $slug, 'create', 'plan', self::common_payload_schema( false ) );
			$definitions[] = self::route_definition( $routes['create_apply'], 'Apply ' . $label . ' Create', $slug, 'create', 'apply', self::common_payload_schema( false ) );
			$definitions[] = self::route_definition( $routes['update_plan'], 'Plan ' . $label . ' Update', $slug, 'update', 'plan', self::common_payload_schema( true ) );
			$definitions[] = self::route_definition( $routes['update_apply'], 'Apply ' . $label . ' Update', $slug, 'update', 'apply', self::common_payload_schema( true ) );
			$definitions[] = self::route_definition( $routes['publish_plan'], 'Plan ' . $label . ' Publish', $slug, 'publish', 'plan', self::publish_schema() );
			$definitions[] = self::route_definition( $routes['publish_apply'], 'Apply ' . $label . ' Publish', $slug, 'publish', 'apply', self::publish_schema() );
			$definitions[] = self::route_definition( $routes['verify'], 'Verify ' . $label, $slug, 'verify', 'read', self::schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) ) );
		}
		return $definitions;
	}

	private static function route_definition( $name, $label, $slug, $operation, $phase, $schema ) {
		$readonly = in_array( $phase, array( 'plan', 'read' ), true );
		$callback = static function ( $input = array() ) use ( $slug, $operation, $phase ) {
			if ( 'helpers' === $operation ) return MAD4B_SCP_Content_Experience_Profiles::helpers_for_profile( $slug );
			if ( 'verify' === $operation ) return MAD4B_SCP_Content_Experience_Runtime::verify( $slug, is_array( $input ) ? $input : array() );
			if ( 'plan' === $phase ) return MAD4B_SCP_Content_Experience_Runtime::operation_plan( $slug, $operation, is_array( $input ) ? $input : array() );
			return MAD4B_SCP_Content_Experience_Runtime::operation_apply( $slug, $operation, is_array( $input ) ? $input : array() );
		};
		$idempotent = $readonly || ( 'apply' === $phase && 'create' === $operation );
		return array(
			'name' => $name, 'label' => $label, 'callback' => $callback,
			'permission' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : self::operation_permission( $slug, $operation ),
			'schema' => $schema, 'surface' => $readonly ? 'read' : 'content',
			'readonly' => $readonly, 'destructive' => ! $readonly, 'idempotent' => $idempotent,
		);
	}

	public static function can_manage_profiles( $input = null ) { return current_user_can( 'manage_options' ); }

	private static function operation_permission( $slug, $operation ) {
		return static function ( $input = array() ) use ( $slug, $operation ) {
			$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
			if ( is_wp_error( $profile ) || empty( $profile['enabled'] ) ) return false;
			$object = MAD4B_SCP_Content_Experience_Profiles::post_type_object( $profile['post_type'] );
			if ( is_wp_error( $object ) ) return false;
			if ( 'create' === $operation ) return current_user_can( MAD4B_SCP_Content_Experience_Profiles::post_type_create_cap( $object ) );
			$id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) return false;
			if ( 'publish' === $operation && ! current_user_can( MAD4B_SCP_Content_Experience_Profiles::post_type_publish_cap( $object ) ) ) return false;
			return true;
		};
	}

	public static function helpers_for_profile( $slug ) {
		$profile = self::profile( $slug );
		if ( is_wp_error( $profile ) ) return $profile;
		$catalog = self::helper_catalog();
		$built_in = array();
		$enabled = array();
		foreach ( $catalog as $row ) if ( ! empty( $row['built_in'] ) ) $built_in[] = $row;
		foreach ( (array) $profile['enabled_helpers'] as $helper_id ) if ( isset( $catalog[ $helper_id ] ) ) $enabled[] = $catalog[ $helper_id ];
		return array(
			'contract' => self::CONTRACT,
			'profile_slug' => $slug,
			'built_in_helpers' => $built_in,
			'enabled_extension_helpers' => $enabled,
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'mutation_performed' => false,
		);
	}

	public static function brand_bearing_mutation_abilities() {
		$abilities = array();
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::routes_for_profile( $profile );
			$abilities[] = $routes['create_apply'];
			$abilities[] = $routes['update_apply'];
			$abilities[] = $routes['publish_apply'];
		}
		$abilities = array_values( array_unique( $abilities ) );
		sort( $abilities, SORT_STRING );
		return $abilities;
	}

	public static function semantic_contract_for_ability( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( in_array( $ability_name, array( self::PROFILE_APPLY_ABILITY, self::PROFILE_CLONE_APPLY_ABILITY, self::PROFILE_DELETE_APPLY_ABILITY ), true ) ) {
			return array(
				'provider' => 'core',
				'mode' => 'object_fields',
				'container_path' => '',
				'brand_fields' => array(),
				'operational_fields' => array( 'profile', 'expected_revision', 'plan_sha256', 'source_slug', 'new_slug', 'slug', 'label', 'post_type' ),
				'brand_key_regex' => '',
				'operational_key_regex' => '.*',
				'root_operational_paths' => array( 'profile', 'profile.*', 'expected_revision', 'plan_sha256', 'source_slug', 'new_slug', 'slug', 'label', 'post_type', '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ),
				'dynamic_profile_contract' => true,
				'profile_configuration_only' => true,
			);
		}
		if ( ! in_array( $ability_name, self::brand_bearing_mutation_abilities(), true ) ) return array();

		/*
		 * The dynamic mutation payload itself is the semantic container. The first
		 * pass classifies every leaf as brand-bearing, explicitly operational, or
		 * fallback-review evidence. root_operational_paths below prevents the
		 * generic outer-container pass from classifying the same root payload a
		 * second time; it does not make brand fields operational.
		 */
		return array(
			'provider' => 'core',
			'mode' => 'object_fields',
			'container_path' => '',
			'brand_fields' => array( 'post_title', 'post_content', 'post_excerpt' ),
			'brand_key_regex' => '(^|[_-])(title|headline|heading|subtitle|content|body|description|excerpt|summary|text|copy|caption|label|tagline|slogan|bio|about|intro|overview|details|message|note|notes|question|answer|faq|cta|button_text|placeholder|keyword|keywords|editor|html|wysiwyg)([_-]|$)',
			'operational_fields' => array(
				'post_id', 'expected_modified_gmt', 'post_name', 'post_parent', 'menu_order',
				'featured_media_id', 'taxonomies', 'post_status', 'plan_sha256',
			),
			'operational_key_regex' => '^(?:term_ids?|taxonomy|taxonomies|post_status|post_id|featured_media_id|menu_order|post_parent|expected_modified_gmt|plan_sha256|id|ids|uuid|hash|checksum|status|enabled|disabled|price|amount|count|order|priority|color|size|width|height|position|timestamp|date|url|path)$',
			'root_operational_paths' => array(
				'post_id', 'expected_modified_gmt',
				'post_title', 'post_content', 'post_excerpt',
				'post_name', 'post_parent', 'menu_order', 'featured_media_id',
				'post_status', 'plan_sha256',
				'meta.*', 'taxonomies.*', 'helpers.*',
				'_mad4b_approval_ticket_id', '_mad4b_context_receipt',
			),
			'dynamic_profile_contract' => true,
		);
	}
	public static function profile_for_ability( $ability_name ) {
		$ability_name = (string) $ability_name;
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			if ( in_array( $ability_name, array_values( self::routes_for_profile( $profile ) ), true ) ) return $profile;
		}
		return new WP_Error( 'mad4b_content_experience_profile_for_ability_missing', 'Ability is not owned by an enabled content experience profile.' );
	}

	public static function owns_ability( $ability_name ) {
		return in_array( (string) $ability_name, array_merge( self::ability_names( 'read' ), self::ability_names( 'content' ) ), true );
	}

	private static function dynamic_context( $ability_name ) {
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::routes_for_profile( $profile );
			foreach ( array( 'create_apply' => 'create', 'update_apply' => 'update', 'publish_apply' => 'publish' ) as $key => $operation ) {
				if ( $routes[ $key ] === $ability_name ) return array( 'slug' => $profile['slug'], 'operation' => $operation );
			}
		}
		return new WP_Error( 'mad4b_content_experience_ability_unresolved', 'Experience mutation ability does not resolve to a configured route.' );
	}

	public static function capture_reversible_state( $ability_name, array $input ) {
		if ( in_array( $ability_name, array( self::PROFILE_APPLY_ABILITY, self::PROFILE_CLONE_APPLY_ABILITY, self::PROFILE_DELETE_APPLY_ABILITY ), true ) ) {
			$raw_slug = self::PROFILE_CLONE_APPLY_ABILITY === $ability_name ? ( isset( $input['new_slug'] ) ? $input['new_slug'] : '' ) : ( self::PROFILE_DELETE_APPLY_ABILITY === $ability_name ? ( isset( $input['slug'] ) ? $input['slug'] : '' ) : ( isset( $input['profile']['slug'] ) ? $input['profile']['slug'] : '' ) );
			$slug = self::route_slug( $raw_slug );
			if ( is_wp_error( $slug ) ) return $slug;
			$profiles = self::stored_profiles();
			return array(
				'target_type' => 'content-experience-profile',
				'target_id' => $slug,
				'target' => array( 'kind' => 'profile', 'slug' => $slug ),
				'state' => array( 'exists' => isset( $profiles[ $slug ] ), 'profile' => isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : null ),
			);
		}
		$context = self::dynamic_context( $ability_name );
		return is_wp_error( $context ) ? $context : MAD4B_SCP_Content_Experience_Runtime::capture_reversible_state( $context['slug'], $context['operation'], $input );
	}

	public static function read_reversible_state( $ability_name, array $target ) {
		if ( isset( $target['kind'] ) && 'profile' === $target['kind'] ) {
			$slug = self::route_slug( isset( $target['slug'] ) ? $target['slug'] : '' );
			if ( is_wp_error( $slug ) ) return $slug;
			$profiles = self::stored_profiles();
			return array( 'exists' => isset( $profiles[ $slug ] ), 'profile' => isset( $profiles[ $slug ] ) ? $profiles[ $slug ] : null );
		}
		return MAD4B_SCP_Content_Experience_Runtime::read_reversible_state( $target );
	}

	public static function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( isset( $target['kind'] ) && 'profile' === $target['kind'] ) {
			$slug = self::route_slug( isset( $target['slug'] ) ? $target['slug'] : '' );
			if ( is_wp_error( $slug ) ) return $slug;
			$profiles = self::stored_profiles();
			if ( ! empty( $state['exists'] ) && is_array( $state['profile'] ) ) $profiles[ $slug ] = $state['profile']; else unset( $profiles[ $slug ] );
			ksort( $profiles, SORT_STRING );
			update_option( self::OPTION, $profiles, false );
			self::$profiles = $profiles;
			return true;
		}
		return MAD4B_SCP_Content_Experience_Runtime::restore_reversible_state( $target, $state );
	}
}
