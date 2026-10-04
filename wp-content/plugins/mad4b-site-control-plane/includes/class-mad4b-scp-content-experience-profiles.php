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
	const MAX_PROFILES = 64;
	const MAX_META_KEYS = 128;
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

	public static function profile_routes( $slug ) {
		$slug = self::route_slug( $slug );
		if ( is_wp_error( $slug ) ) return array();
		$base = 'mad4b/' . $slug . '-';
		return array(
			'helpers' => $base . 'helpers',
			'create_plan' => $base . 'create-plan',
			'create_apply' => $base . 'create-apply',
			'update_plan' => $base . 'update-plan',
			'update_apply' => $base . 'update-apply',
			'publish_plan' => $base . 'publish-plan',
			'publish_apply' => $base . 'publish-apply',
			'verify' => $base . 'verify',
		);
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
		$meta_mode = isset( $raw['meta_mode'] ) ? sanitize_key( (string) $raw['meta_mode'] ) : 'safe_any';
		if ( ! in_array( $meta_mode, array( 'safe_any', 'allowlist' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_meta_mode_invalid', 'meta_mode must be safe_any or allowlist.' );
		}
		$meta_keys = self::normalize_string_list( isset( $raw['meta_keys'] ) ? $raw['meta_keys'] : array(), self::MAX_META_KEYS, '/^[A-Za-z0-9_-]+$/' );
		$protected_meta_keys = self::normalize_string_list( isset( $raw['protected_meta_keys'] ) ? $raw['protected_meta_keys'] : array(), self::MAX_META_KEYS, '/^_[A-Za-z0-9_-]+$/' );
		foreach ( $protected_meta_keys as $key ) {
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $key ) ) {
				return new WP_Error( 'mad4b_content_experience_sensitive_meta_denied', 'Sensitive/authentication-like metadata cannot be enabled by an experience profile.' );
			}
		}

		$available_taxonomies = array_keys( get_object_taxonomies( $post_type, 'objects' ) );
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

		$creation_status = isset( $raw['creation_status'] ) ? sanitize_key( (string) $raw['creation_status'] ) : 'draft';
		if ( ! in_array( $creation_status, array( 'draft', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_creation_status_invalid', 'New content must start as draft, pending or private.' );
		}
		$live_update_mode = isset( $raw['live_update_mode'] ) ? sanitize_key( (string) $raw['live_update_mode'] ) : 'draft_first';
		if ( ! in_array( $live_update_mode, array( 'draft_first', 'direct' ), true ) ) {
			return new WP_Error( 'mad4b_content_experience_live_update_mode_invalid', 'live_update_mode must be draft_first or direct.' );
		}

		return array(
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
			'taxonomies' => $taxonomies,
			'featured_media' => ! array_key_exists( 'featured_media', $raw ) || ! empty( $raw['featured_media'] ),
			'hierarchy' => ! empty( $raw['hierarchy'] ) && ! empty( $object->hierarchical ),
			'enabled_helpers' => $enabled_helpers,
			'helper_catalog_sha256' => self::helper_catalog_sha256(),
			'routes' => self::profile_routes( $slug ),
		);
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
		$profile = self::normalize_profile( $raw, $current_revision + 1 );
		if ( is_wp_error( $profile ) ) return $profile;
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

	public static function profile_status( $input = array() ) {
		$profiles = array();
		foreach ( self::stored_profiles() as $slug => $profile ) {
			$profiles[] = array(
				'slug' => $slug,
				'label' => isset( $profile['label'] ) ? (string) $profile['label'] : $slug,
				'post_type' => (string) $profile['post_type'],
				'enabled' => ! empty( $profile['enabled'] ),
				'revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
				'runtime_post_type_ready' => ! is_wp_error( self::post_type_object( $profile['post_type'] ) ),
				'helper_catalog_match' => isset( $profile['helper_catalog_sha256'] ) && hash_equals( (string) $profile['helper_catalog_sha256'], self::helper_catalog_sha256() ),
				'routes' => self::profile_routes( $slug ),
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
			$items[] = array(
				'post_type' => (string) $post_type,
				'label' => (string) $object->label,
				'public' => ! empty( $object->public ),
				'show_ui' => ! empty( $object->show_ui ),
				'show_in_rest' => ! empty( $object->show_in_rest ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'can_create' => current_user_can( self::post_type_create_cap( $object ) ),
				'can_publish' => current_user_can( self::post_type_publish_cap( $object ) ),
				'suggested_profile_slug' => substr( sanitize_title( $post_type ), 0, 48 ),
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
			'mutation_performed' => false,
		);
	}

	public static function ability_names( $surface ) {
		$surface = sanitize_key( (string) $surface );
		$read = array( 'mad4b/content-experience-discover', 'mad4b/content-experience-profile-status', 'mad4b/content-experience-profile-plan' );
		$content = array( self::PROFILE_APPLY_ABILITY );
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::profile_routes( $profile['slug'] );
			$read = array_merge( $read, array( $routes['helpers'], $routes['create_plan'], $routes['update_plan'], $routes['publish_plan'], $routes['verify'] ) );
			$content = array_merge( $content, array( $routes['create_apply'], $routes['update_apply'], $routes['publish_apply'] ) );
		}
		if ( 'read' === $surface ) return array_values( array_unique( $read ) );
		if ( 'content' === $surface ) return array_values( array_unique( $content ) );
		return array();
	}

	public static function reversible_contracts() {
		$result = array( self::PROFILE_APPLY_ABILITY => 'mad4b.rollback.content-experience-profile.v1' );
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::profile_routes( $profile['slug'] );
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
			array( 'name' => 'mad4b/content-experience-profile-status', 'label' => 'Content Experience Profile Status', 'callback' => array( __CLASS__, 'profile_status' ), 'permission' => $read, 'schema' => self::schema( array() ), 'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			array(
				'name' => 'mad4b/content-experience-profile-plan', 'label' => 'Plan Content Experience Profile', 'callback' => array( __CLASS__, 'profile_plan' ), 'permission' => $read,
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ) ), array( 'profile' ) ),
				'surface' => 'read', 'readonly' => true, 'destructive' => false, 'idempotent' => true,
			),
			array(
				'name' => self::PROFILE_APPLY_ABILITY, 'label' => 'Apply Content Experience Profile', 'callback' => array( __CLASS__, 'profile_apply' ), 'permission' => array( __CLASS__, 'can_manage_profiles' ),
				'schema' => self::schema( array( 'profile' => array( 'type' => 'object', 'additionalProperties' => true ), 'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ), 'plan_sha256' => self::sha_schema() ), array( 'profile', 'plan_sha256' ) ),
				'surface' => 'content', 'readonly' => false, 'destructive' => true, 'idempotent' => true,
			),
		);
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$slug = (string) $profile['slug'];
			$routes = self::profile_routes( $slug );
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
		return array(
			'name' => $name, 'label' => $label, 'callback' => $callback,
			'permission' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : self::operation_permission( $slug, $operation ),
			'schema' => $schema, 'surface' => $readonly ? 'read' : 'content',
			'readonly' => $readonly, 'destructive' => ! $readonly, 'idempotent' => true,
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
			$routes = self::profile_routes( $profile['slug'] );
			$abilities[] = $routes['create_apply'];
			$abilities[] = $routes['update_apply'];
			$abilities[] = $routes['publish_apply'];
		}
		$abilities = array_values( array_unique( $abilities ) );
		sort( $abilities, SORT_STRING );
		return $abilities;
	}

	public static function semantic_contract_for_ability( $ability_name ) {
		if ( ! in_array( (string) $ability_name, self::brand_bearing_mutation_abilities(), true ) ) return array();
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
			'operational_key_regex' => '^(?:term_ids?|taxonomy|taxonomies|post_status|post_id|featured_media_id|menu_order|post_parent|expected_modified_gmt|plan_sha256)
	private static function dynamic_context( $ability_name ) {
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::profile_routes( $profile['slug'] );
			foreach ( array( 'create_apply' => 'create', 'update_apply' => 'update', 'publish_apply' => 'publish' ) as $key => $operation ) {
				if ( $routes[ $key ] === $ability_name ) return array( 'slug' => $profile['slug'], 'operation' => $operation );
			}
		}
		return new WP_Error( 'mad4b_content_experience_ability_unresolved', 'Experience mutation ability does not resolve to a configured route.' );
	}

	public static function capture_reversible_state( $ability_name, array $input ) {
		if ( self::PROFILE_APPLY_ABILITY === $ability_name ) {
			$slug = self::route_slug( isset( $input['profile']['slug'] ) ? $input['profile']['slug'] : '' );
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
,
			'root_operational_paths' => array(
				'_mad4b_approval_ticket_id',
				'_mad4b_context_receipt',
				'expected_modified_gmt',
				'plan_sha256',
			),
			'dynamic_profile_contract' => true,
		);
	}

	public static function owns_ability( $ability_name ) {
		return in_array( (string) $ability_name, array_merge( self::ability_names( 'read' ), self::ability_names( 'content' ) ), true );
	}

	private static function dynamic_context( $ability_name ) {
		foreach ( self::stored_profiles() as $profile ) {
			if ( empty( $profile['enabled'] ) ) continue;
			$routes = self::profile_routes( $profile['slug'] );
			foreach ( array( 'create_apply' => 'create', 'update_apply' => 'update', 'publish_apply' => 'publish' ) as $key => $operation ) {
				if ( $routes[ $key ] === $ability_name ) return array( 'slug' => $profile['slug'], 'operation' => $operation );
			}
		}
		return new WP_Error( 'mad4b_content_experience_ability_unresolved', 'Experience mutation ability does not resolve to a configured route.' );
	}

	public static function capture_reversible_state( $ability_name, array $input ) {
		if ( self::PROFILE_APPLY_ABILITY === $ability_name ) {
			$slug = self::route_slug( isset( $input['profile']['slug'] ) ? $input['profile']['slug'] : '' );
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
