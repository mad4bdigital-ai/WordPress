<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only adaptive bootstrap for configuration-driven Content Experience.
 *
 * This service discovers a safe proposal; it never persists profiles or content.
 */
final class MAD4B_SCP_Content_Experience_Bootstrap {
	private static function stored_profiles() {
		$raw = get_option( MAD4B_SCP_Content_Experience_Profiles::OPTION, array() );
		$result = array();
		foreach ( is_array( $raw ) ? array_keys( $raw ) : array() as $slug ) {
			$profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
			if ( ! is_wp_error( $profile ) ) $result[] = $profile;
		}
		return $result;
	}

	private static function routes_for_profile( array $profile ) {
		if ( isset( $profile['routes'] ) && is_array( $profile['routes'] ) && $profile['routes'] ) return $profile['routes'];
		return MAD4B_SCP_Content_Experience_Profiles::profile_routes(
			isset( $profile['slug'] ) ? $profile['slug'] : '',
			isset( $profile['revision'] ) ? (int) $profile['revision'] : 0
		);
	}

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$object = MAD4B_SCP_Content_Experience_Profiles::post_type_object( $post_type );
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
				'contract' => MAD4B_SCP_Content_Experience_Profiles::BOOTSTRAP_PLAN_CONTRACT,
				'state' => 'ALREADY_CONFIGURED',
				'post_type' => $post_type,
				'existing_profile' => $existing,
				'scenarios' => self::scenario_matrix( $existing, true ),
				'workflow_blueprint' => self::workflow_blueprint( $existing, true ),
				'profile_apply_ability' => MAD4B_SCP_Content_Experience_Profiles::PROFILE_APPLY_ABILITY,
				'apply_required' => false,
				'mutation_performed' => false,
			);
		}

		$slug_seed = '' !== $explicit_slug ? $explicit_slug : preg_replace( '/[^a-z0-9]+/', '-', strtolower( $post_type ) );
		$slug_seed = trim( substr( (string) $slug_seed, 0, 48 ), '-' );
		if ( strlen( $slug_seed ) < 2 ) $slug_seed = 'content-' . $slug_seed;
		$slug = MAD4B_SCP_Content_Experience_Profiles::route_slug( $slug_seed );
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
			elseif ( count( $included_taxonomies ) >= MAD4B_SCP_Content_Experience_Profiles::MAX_TAXONOMIES ) $reason = 'profile_taxonomy_limit';
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
		$profile_plan = MAD4B_SCP_Content_Experience_Profiles::profile_plan( array( 'profile' => $profile, 'expected_revision' => $expected_revision ) );
		if ( is_wp_error( $profile_plan ) ) return $profile_plan;

		$helper_candidates = array();
		foreach ( MAD4B_SCP_Content_Experience_Profiles::helper_catalog() as $helper ) {
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
			'contract' => MAD4B_SCP_Content_Experience_Profiles::BOOTSTRAP_PLAN_CONTRACT,
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
			'profile_apply_ability' => MAD4B_SCP_Content_Experience_Profiles::PROFILE_APPLY_ABILITY,
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

	public static function media_field_candidates( $post_type, $deep = false ) {
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
				foreach ( array_slice( $declared, 0, MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_META_FIELDS * 2 ) as $row ) {
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
		foreach ( array_slice( $extended, 0, MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_META_FIELDS * 6 ) as $row ) {
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
			if ( $ids && count( array_filter( $ids, $is_attachment ) ) === count( $ids ) ) return array( 'supported' => true, 'schema_type' => 'string', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'csv_ids', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, max( 1, count( $ids ) ) ) ) );
		}
		if ( is_array( $value ) && array_values( $value ) === $value ) {
			$ids = array_values( array_filter( array_map( 'absint', $value ) ) );
			if ( $ids && count( $ids ) === count( $value ) && count( array_filter( $ids, $is_attachment ) ) === count( $ids ) ) return array( 'supported' => true, 'schema_type' => 'array', 'spec' => array( 'kind' => 'image_gallery', 'storage' => 'ids', 'max_items' => min( MAD4B_SCP_Content_Experience_Profiles::MAX_MEDIA_GALLERY_ITEMS, max( 1, count( $ids ) ) ) ) );
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
				'binding_plan' => MAD4B_SCP_Content_Experience_Profiles::MEDIA_BINDING_PLAN_ABILITY,
			)
			: array(
				'discover' => 'media/remote-source-discover',
				'inspect' => 'media/remote-image-inspect',
				'import_plan' => 'media/remote-import-plan',
				'import_apply' => 'media/remote-import-apply',
				'binding_plan' => 'mad4b/content-experience-media-binding-plan',
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
				array( 'id' => 'plan_post_media_binding', 'ability' => $media['binding_plan'], 'surface' => 'read', 'optional' => true, 'consumes' => array( 'verified_attachment_ids', 'binding_roles', 'target_media_fields' ), 'produces' => array( 'featured_media_id', 'meta_fragment', 'storage_projection' ) ),
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

}
