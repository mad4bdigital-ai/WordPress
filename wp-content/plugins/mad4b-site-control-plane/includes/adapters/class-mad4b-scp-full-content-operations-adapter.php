<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Full governed WordPress content/model operations that are intentionally
 * provider-neutral. Raw SQL, executable code and credential surfaces remain out.
 */
final class MAD4B_SCP_Full_Content_Operations_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.full-content-operations.v1';
	const EXPORT_CONTRACT = 'mad4b.content-export-bundle.v2';
	const LEGACY_EXPORT_CONTRACT = 'mad4b.content-export-bundle.v1';
	const MAX_EXPORT_POSTS = 25;
	const MAX_IMPORT_POSTS = 20;
	const IMPORT_BINDING_META = '_mad4b_content_import_binding';
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 5, 1 );
		add_filter( 'mad4b_scp_mutation_impact', array( __CLASS__, 'raise_impact' ), 20, 4 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public static function raise_impact( $impact, $ability_name, $provider, $input ) {
		$high = array(
			'mad4b/content-trash-post',
			'mad4b/taxonomy-delete-term',
			'mad4b/content-import-bundle',
		);
		return in_array( (string) $ability_name, $high, true ) ? 'high' : $impact;
	}

	public function id() { return 'full-content-operations'; }
	public function label() { return 'Full Content Operations'; }
	public function is_available() { return function_exists( 'wp_insert_post' ) && function_exists( 'wp_get_object_terms' ); }
	protected function certified_provider_key() { return 'core'; }
	protected function mutation_requires_certification() { return false; }

	public function ability_names() {
		return array(
			'read' => array(
				'mad4b/content-modeling-context',
				'mad4b/content-get-meta',
				'mad4b/content-list-meta',
				'mad4b/taxonomy-get-term',
				'mad4b/taxonomy-get-object-terms',
				'mad4b/content-export-bundle',
			),
			'content' => array(
				'mad4b/content-trash-post',
				'mad4b/content-set-meta',
				'mad4b/content-delete-meta',
				'mad4b/taxonomy-update-term',
				'mad4b/taxonomy-delete-term',
				'mad4b/content-import-bundle',
			),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function reversible_contracts() {
		return array(
			'mad4b/content-trash-post' => 'mad4b.rollback.trashed-post.v1',
			'mad4b/content-set-meta' => 'mad4b.rollback.post-meta.v1',
			'mad4b/content-delete-meta' => 'mad4b.rollback.post-meta.v1',
			'mad4b/taxonomy-update-term' => 'mad4b.rollback.updated-term.v1',
			'mad4b/content-import-bundle' => 'mad4b.rollback.created-content-bundle.v2',
		);
	}

	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$this->add_ability( 'mad4b/content-modeling-context', 'Content Modeling Context', 'content_modeling_context', $read, $this->schema( array(
			'post_type' => $this->slug_schema(),
			'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), array( 'post_type' ) ) );
		$this->add_ability( 'mad4b/content-get-meta', 'Get Post Meta', 'content_get_meta', array( $this, 'can_read_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'key' => $this->meta_key_schema(),
		), array( 'post_id', 'key' ) ) );
		$this->add_ability( 'mad4b/content-list-meta', 'List Safe Post Meta', 'content_list_meta', array( $this, 'can_read_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'prefix' => array( 'type' => 'string', 'maxLength' => 100, 'default' => '' ),
			'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 100 ),
		), array( 'post_id' ) ) );
		$this->add_ability( 'mad4b/taxonomy-get-term', 'Get Taxonomy Term', 'taxonomy_get_term', $read, $this->schema( array(
			'taxonomy' => $this->slug_schema(),
			'term_id' => array( 'type' => 'integer', 'minimum' => 1 ),
		), array( 'taxonomy', 'term_id' ) ) );
		$this->add_ability( 'mad4b/taxonomy-get-object-terms', 'Get Object Taxonomy Terms', 'taxonomy_get_object_terms', array( $this, 'can_read_object_terms' ), $this->schema( array(
			'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'taxonomy' => $this->slug_schema(),
		), array( 'object_id', 'taxonomy' ) ) );
		$this->add_ability( 'mad4b/content-export-bundle', 'Export Content Bundle', 'content_export_bundle', $read, $this->schema( array(
			'post_ids' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_EXPORT_POSTS, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'include_meta' => array( 'type' => 'boolean', 'default' => true ),
			'include_terms' => array( 'type' => 'boolean', 'default' => true ),
		), array( 'post_ids' ) ) );

		$this->add_ability( 'mad4b/content-trash-post', 'Trash Post', 'content_trash_post', array( $this, 'can_delete_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
		), array( 'post_id', 'expected_modified_gmt' ) ), 'content', false, true, false );
		$this->add_ability( 'mad4b/content-set-meta', 'Set Post Meta', 'content_set_meta', array( $this, 'can_edit_post' ), $this->meta_write_schema( true ), 'content', false, true, true );
		$this->add_ability( 'mad4b/content-delete-meta', 'Delete Post Meta', 'content_delete_meta', array( $this, 'can_edit_post' ), $this->meta_write_schema( false ), 'content', false, true, true );
		$this->add_ability( 'mad4b/taxonomy-update-term', 'Update Taxonomy Term', 'taxonomy_update_term', array( $this, 'can_manage_term' ), $this->schema( array(
			'taxonomy' => $this->slug_schema(),
			'term_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_sha256' => $this->sha_schema(),
			'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
			'slug' => array( 'type' => 'string', 'maxLength' => 200 ),
			'description' => array( 'type' => 'string', 'maxLength' => 65535 ),
			'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
		), array( 'taxonomy', 'term_id', 'expected_sha256' ) ), 'content', false, true, true );
		$this->add_ability( 'mad4b/taxonomy-delete-term', 'Delete Taxonomy Term', 'taxonomy_delete_term', array( $this, 'can_manage_term' ), $this->schema( array(
			'taxonomy' => $this->slug_schema(),
			'term_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_sha256' => $this->sha_schema(),
			'expected_object_ids' => array( 'type' => 'array', 'maxItems' => 500, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		), array( 'taxonomy', 'term_id', 'expected_sha256', 'expected_object_ids' ) ), 'content', false, true, false );
		$this->add_ability( 'mad4b/content-import-bundle', 'Import Content Bundle', 'content_import_bundle', array( 'MAD4B_SCP_Policy', 'can_admin' ), $this->schema( array(
			'bundle' => array( 'type' => 'object', 'additionalProperties' => true ),
			'force_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private' ), 'default' => 'draft' ),
		), array( 'bundle' ) ), 'content', false, true, false );
	}

	private function slug_schema() { return array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z0-9_-]+$' ); }
	private function meta_key_schema() { return array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9_-]+$' ); }
	private function sha_schema() { return array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-f0-9]{64}$' ); }
	private function meta_write_schema( $with_value ) {
		$props = array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'key' => $this->meta_key_schema(),
			'expected_sha256' => $this->sha_schema(),
			'expected_exists' => array( 'type' => 'boolean' ),
		);
		if ( $with_value ) $props['value'] = $this->json_value_schema();
		$required = array( 'post_id', 'key', 'expected_sha256', 'expected_exists' );
		if ( $with_value ) $required[] = 'value';
		return $this->schema( $props, $required );
	}

	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function can_delete_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'delete_post', $id ); }
	public function can_manage_term( $input ) {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$tax = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
		$cap = is_object( $tax ) && isset( $tax->cap->manage_terms ) ? (string) $tax->cap->manage_terms : 'manage_categories';
		return is_object( $tax ) && current_user_can( $cap );
	}
	public function can_read_object_terms( $input ) {
		$id = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0;
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$post = $id ? get_post( $id ) : null;
		return $post && taxonomy_exists( $taxonomy ) && is_object_in_taxonomy( $post->post_type, $taxonomy ) && current_user_can( 'read_post', $id );
	}

	private function is_sensitive_meta_key( $key ) { return MAD4B_SCP_Policy::is_sensitive_database_column( (string) $key ); }
	private function validate_meta_key( $key, $post_id, $write ) {
		$key = (string) $key;
		if ( '' === $key || strlen( $key ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) return new WP_Error( 'mad4b_content_meta_key_invalid', 'Meta key is invalid.' );
		if ( $this->is_sensitive_meta_key( $key ) ) return new WP_Error( 'mad4b_content_sensitive_meta_denied', 'Secret/authentication-like metadata is outside the normal content authority.' );
		if ( 0 === strpos( $key, '_' ) ) {
			$filter = $write ? 'mad4b_scp_allow_protected_content_meta_write' : 'mad4b_scp_allow_protected_content_meta_read';
			if ( ! current_user_can( 'manage_options' ) || ! apply_filters( $filter, false, $key, absint( $post_id ), get_current_user_id() ) ) return new WP_Error( 'mad4b_content_protected_meta_denied', 'Protected metadata requires administrator permission and an explicit site policy allowlist.' );
		}
		return $key;
	}
	private function meta_state( $post_id, $key ) {
		$key = $this->validate_meta_key( $key, $post_id, false );
		if ( is_wp_error( $key ) ) return $key;
		$exists = metadata_exists( 'post', $post_id, $key );
		$value = get_post_meta( $post_id, $key, true );
		return array( 'exists' => $exists, 'value' => $value, 'sha256' => hash( 'sha256', wp_json_encode( $value ) ) );
	}
	private function check_meta_expected( $post_id, $key, array $input ) {
		$state = $this->meta_state( $post_id, $key );
		if ( is_wp_error( $state ) ) return $state;
		$expected_exists = ! empty( $input['expected_exists'] );
		$expected_sha = strtolower( (string) $input['expected_sha256'] );
		if ( (bool) $state['exists'] !== $expected_exists || ! hash_equals( (string) $state['sha256'], $expected_sha ) ) return new WP_Error( 'mad4b_content_meta_state_drift', 'Post meta changed since it was read.', array( 'current_exists' => $state['exists'], 'current_sha256' => $state['sha256'] ) );
		return $state;
	}

	public function content_modeling_context( $input ) {
		$post_type = sanitize_key( (string) $input['post_type'] );
		$type = post_type_exists( $post_type ) ? get_post_type_object( $post_type ) : null;
		if ( ! is_object( $type ) ) return new WP_Error( 'mad4b_post_type_missing', 'Requested post type is not registered.' );
		$create_cap = isset( $type->cap->create_posts ) ? (string) $type->cap->create_posts : ( isset( $type->cap->edit_posts ) ? (string) $type->cap->edit_posts : 'edit_posts' );
		$taxonomies = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $tax ) {
			$manage = isset( $tax->cap->manage_terms ) ? (string) $tax->cap->manage_terms : 'manage_categories';
			$assign = isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : 'edit_posts';
			$taxonomies[] = array( 'name' => (string) $name, 'label' => (string) $tax->label, 'hierarchical' => ! empty( $tax->hierarchical ), 'can_manage_terms' => current_user_can( $manage ), 'can_assign_terms' => current_user_can( $assign ) );
		}
		$result = array(
			'contract' => self::CONTRACT,
			'post_type' => array( 'name' => $post_type, 'label' => (string) $type->label, 'public' => ! empty( $type->public ), 'show_ui' => ! empty( $type->show_ui ), 'show_in_rest' => ! empty( $type->show_in_rest ), 'hierarchical' => ! empty( $type->hierarchical ) ),
			'can_create' => current_user_can( $create_cap ),
			'taxonomies' => $taxonomies,
		);
		if ( isset( $input['object_id'] ) ) {
			$id = absint( $input['object_id'] );
			$post = get_post( $id );
			if ( ! $post || $post_type !== $post->post_type || ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'mad4b_content_context_object_invalid', 'object_id is not a readable item of the requested post type.' );
			$result['object'] = array( 'object_id' => $id, 'post_status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt );
		}
		return $result;
	}

	public function content_get_meta( $input ) {
		$id = absint( $input['post_id'] );
		$state = $this->meta_state( $id, $input['key'] );
		return is_wp_error( $state ) ? $state : array_merge( array( 'post_id' => $id, 'key' => (string) $input['key'] ), $state );
	}

	public function content_list_meta( $input ) {
		$id = absint( $input['post_id'] );
		$prefix = isset( $input['prefix'] ) ? (string) $input['prefix'] : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 300, absint( $input['limit'] ) ) ) : 100;
		$items = array();
		$omitted = 0;
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( '' !== $prefix && 0 !== strpos( (string) $key, $prefix ) ) continue;
			$valid = $this->validate_meta_key( $key, $id, false );
			if ( is_wp_error( $valid ) ) { ++$omitted; continue; }
			$value = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			$items[] = array( 'key' => $key, 'value' => $value, 'sha256' => hash( 'sha256', wp_json_encode( $value ) ) );
			if ( count( $items ) >= $limit ) break;
		}
		return array( 'post_id' => $id, 'meta' => $items, 'count' => count( $items ), 'omitted_protected_or_sensitive' => $omitted );
	}

	public function taxonomy_get_term( $input ) {
		$state = $this->term_state( sanitize_key( $input['taxonomy'] ), absint( $input['term_id'] ) );
		return $state;
	}
	public function taxonomy_get_object_terms( $input ) { return $this->object_terms_payload( absint( $input['object_id'] ), sanitize_key( $input['taxonomy'] ) ); }

	private function object_terms_payload( $object_id, $taxonomy ) {
		$post = get_post( $object_id );
		if ( ! $post || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) return new WP_Error( 'mad4b_taxonomy_object_mismatch', 'Taxonomy is not registered for this object.' );
		$terms = wp_get_object_terms( $object_id, $taxonomy, array( 'orderby' => 'term_id', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) return $terms;
		$items = array();
		$ids = array();
		foreach ( $terms as $term ) {
			$ids[] = (int) $term->term_id;
			$items[] = array( 'term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent' => (int) $term->parent );
		}
		sort( $ids, SORT_NUMERIC );
		return array( 'object_id' => $object_id, 'taxonomy' => $taxonomy, 'term_ids' => $ids, 'terms' => $items, 'state_sha256' => hash( 'sha256', wp_json_encode( $ids ) ) );
	}

	private function term_state( $taxonomy, $term_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) return new WP_Error( 'mad4b_taxonomy_missing', 'Taxonomy is not registered.' );
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) return is_wp_error( $term ) ? $term : new WP_Error( 'mad4b_term_missing', 'Term not found.' );
		$state = array(
			'taxonomy' => $taxonomy,
			'term_id' => (int) $term->term_id,
			'name' => (string) $term->name,
			'slug' => (string) $term->slug,
			'description' => (string) $term->description,
			'parent' => (int) $term->parent,
			'count' => (int) $term->count,
		);
		$state['sha256'] = hash( 'sha256', wp_json_encode( array( $state['name'], $state['slug'], $state['description'], $state['parent'] ) ) );
		return $state;
	}

	private function portable_term_refs( $post_id, $post_type ) {
		$result = array();
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$payload = $this->object_terms_payload( $post_id, $taxonomy );
			if ( is_wp_error( $payload ) || empty( $payload['terms'] ) ) continue;
			$refs = array();
			foreach ( $payload['terms'] as $term ) {
				$refs[] = array( 'slug' => (string) $term['slug'], 'name' => (string) $term['name'] );
			}
			$result[ $taxonomy ] = $refs;
		}
		return $result;
	}

	public function content_export_bundle( $input ) {
		$posts = array();
		foreach ( array_values( array_unique( array_map( 'absint', (array) $input['post_ids'] ) ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'mad4b_export_post_denied', 'One requested post is missing or unreadable.', array( 'post_id' => $id ) );
			$item = array(
				'source_post_id' => $id,
				'source_parent_post_id' => (int) $post->post_parent,
				'post_type' => $post->post_type,
				'post_title' => $post->post_title,
				'post_name' => $post->post_name,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status' => $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'modified_gmt' => $post->post_modified_gmt,
			);
			if ( ! isset( $input['include_meta'] ) || $input['include_meta'] ) {
				$meta = $this->content_list_meta( array( 'post_id' => $id, 'limit' => 300 ) );
				$item['meta'] = is_wp_error( $meta ) ? array() : $meta['meta'];
			}
			if ( ! isset( $input['include_terms'] ) || $input['include_terms'] ) $item['terms'] = $this->portable_term_refs( $id, $post->post_type );
			$item = apply_filters( 'mad4b_scp_content_export_item', $item, $post, $input );
			$posts[] = $item;
		}
		$bundle = array(
			'contract' => self::EXPORT_CONTRACT,
			'created_at' => gmdate( 'c' ),
			'source_home' => home_url( '/' ),
			'identity_mode' => 'source_post_ids_plus_taxonomy_term_slugs',
			'count' => count( $posts ),
			'posts' => $posts,
		);
		$bundle['bundle_sha256'] = hash( 'sha256', wp_json_encode( $bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $bundle;
	}

	public function content_trash_post( $input ) {
		$id = absint( $input['post_id'] );
		$post = get_post( $id );
		if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		if ( (string) $post->post_modified_gmt !== (string) $input['expected_modified_gmt'] ) return new WP_Error( 'mad4b_post_state_drift', 'Post changed since it was read.', array( 'current_modified_gmt' => $post->post_modified_gmt ) );
		$result = wp_trash_post( $id );
		if ( ! $result ) return new WP_Error( 'mad4b_post_trash_failed', 'WordPress could not move the post to Trash.' );
		$after = get_post( $id );
		return array( 'post_id' => $id, 'trashed' => $after && 'trash' === $after->post_status, 'post_status' => $after ? $after->post_status : '' );
	}

	public function content_set_meta( $input ) {
		$id = absint( $input['post_id'] );
		$key = $this->validate_meta_key( $input['key'], $id, true );
		if ( is_wp_error( $key ) ) return $key;
		$expected = $this->check_meta_expected( $id, $key, $input );
		if ( is_wp_error( $expected ) ) return $expected;
		$result = update_post_meta( $id, $key, $input['value'] );
		if ( false === $result && $expected['value'] !== $input['value'] ) return new WP_Error( 'mad4b_content_meta_update_failed', 'Unable to update post meta.' );
		return $this->content_get_meta( array( 'post_id' => $id, 'key' => $key ) );
	}

	public function content_delete_meta( $input ) {
		$id = absint( $input['post_id'] );
		$key = $this->validate_meta_key( $input['key'], $id, true );
		if ( is_wp_error( $key ) ) return $key;
		$expected = $this->check_meta_expected( $id, $key, $input );
		if ( is_wp_error( $expected ) ) return $expected;
		if ( $expected['exists'] && ! delete_post_meta( $id, $key ) ) return new WP_Error( 'mad4b_content_meta_delete_failed', 'Unable to delete post meta.' );
		return array( 'post_id' => $id, 'key' => $key, 'exists' => metadata_exists( 'post', $id, $key ), 'deleted' => true );
	}

	public function taxonomy_update_term( $input ) {
		$taxonomy = sanitize_key( $input['taxonomy'] );
		$id = absint( $input['term_id'] );
		$state = $this->term_state( $taxonomy, $id );
		if ( is_wp_error( $state ) ) return $state;
		if ( ! hash_equals( $state['sha256'], strtolower( (string) $input['expected_sha256'] ) ) ) return new WP_Error( 'mad4b_term_state_drift', 'Term changed since it was read.', array( 'current_sha256' => $state['sha256'] ) );
		$args = array();
		foreach ( array( 'name','slug','description','parent' ) as $field ) if ( array_key_exists( $field, $input ) ) $args[ $field ] = 'parent' === $field ? absint( $input[ $field ] ) : (string) $input[ $field ];
		if ( empty( $args ) ) return new WP_Error( 'mad4b_term_update_empty', 'At least one term field must be supplied.' );
		$result = wp_update_term( $id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) return $result;
		return $this->term_state( $taxonomy, $id );
	}

	public function taxonomy_delete_term( $input ) {
		$taxonomy = sanitize_key( $input['taxonomy'] );
		$id = absint( $input['term_id'] );
		$state = $this->term_state( $taxonomy, $id );
		if ( is_wp_error( $state ) ) return $state;
		if ( ! hash_equals( $state['sha256'], strtolower( (string) $input['expected_sha256'] ) ) ) return new WP_Error( 'mad4b_term_state_drift', 'Term changed since it was read.', array( 'current_sha256' => $state['sha256'] ) );
		$current = get_objects_in_term( $id, $taxonomy );
		if ( is_wp_error( $current ) ) return $current;
		$current = array_values( array_map( 'absint', $current ) );
		sort( $current, SORT_NUMERIC );
		$expected = array_values( array_map( 'absint', (array) $input['expected_object_ids'] ) );
		sort( $expected, SORT_NUMERIC );
		if ( $current !== $expected ) return new WP_Error( 'mad4b_term_usage_drift', 'Term assignments changed since planning.', array( 'current_object_ids' => $current ) );
		$result = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $result ) || false === $result ) return is_wp_error( $result ) ? $result : new WP_Error( 'mad4b_term_delete_failed', 'Term could not be deleted.' );
		return array( 'taxonomy' => $taxonomy, 'term_id' => $id, 'deleted' => true, 'previous_object_ids' => $current );
	}

	private function normalized_home( $url ) { return untrailingslashit( strtolower( (string) $url ) ); }

	private function resolve_import_terms( $post_type, array $terms, $contract ) {
		$resolved = array();
		foreach ( $terms as $taxonomy => $references ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) return new WP_Error( 'mad4b_import_taxonomy_unavailable', 'Import references a taxonomy that is missing or not attached to the target post type.', array( 'taxonomy' => $taxonomy, 'post_type' => $post_type ) );
			$tax = get_taxonomy( $taxonomy );
			$assign_cap = is_object( $tax ) && isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : 'edit_posts';
			if ( ! current_user_can( $assign_cap ) ) return new WP_Error( 'mad4b_import_term_assign_denied', 'Current user cannot assign terms in one imported taxonomy.', array( 'taxonomy' => $taxonomy ) );
			$ids = array();
			if ( self::EXPORT_CONTRACT === $contract ) {
				foreach ( (array) $references as $reference ) {
					if ( ! is_array( $reference ) || empty( $reference['slug'] ) ) return new WP_Error( 'mad4b_import_term_reference_invalid', 'Portable term references require a non-empty slug.', array( 'taxonomy' => $taxonomy ) );
					$slug = sanitize_title( (string) $reference['slug'] );
					$term = get_term_by( 'slug', $slug, $taxonomy );
					if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'mad4b_import_term_missing', 'A portable term reference does not exist on the target site. Create/map the term explicitly before import.', array( 'taxonomy' => $taxonomy, 'slug' => $slug ) );
					$ids[] = (int) $term->term_id;
				}
			} else {
				foreach ( (array) $references as $term_id ) {
					$term_id = absint( $term_id );
					$term = $term_id ? get_term( $term_id, $taxonomy ) : null;
					if ( ! $term || is_wp_error( $term ) ) return new WP_Error( 'mad4b_import_legacy_term_missing', 'Legacy same-site bundle references a term id that is no longer valid.', array( 'taxonomy' => $taxonomy, 'term_id' => $term_id ) );
					$ids[] = $term_id;
				}
			}
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			sort( $ids, SORT_NUMERIC );
			$resolved[ $taxonomy ] = $ids;
		}
		return $resolved;
	}

	private function normalize_import_bundle( $bundle, $force_status ) {
		if ( ! is_array( $bundle ) || empty( $bundle['posts'] ) || ! is_array( $bundle['posts'] ) ) return new WP_Error( 'mad4b_import_bundle_invalid', 'Import bundle must contain a posts array.' );
		if ( count( $bundle['posts'] ) > self::MAX_IMPORT_POSTS ) return new WP_Error( 'mad4b_import_bundle_too_large', 'Import bundle exceeds the governed item limit.' );
		$contract = isset( $bundle['contract'] ) ? (string) $bundle['contract'] : '';
		if ( ! in_array( $contract, array( self::EXPORT_CONTRACT, self::LEGACY_EXPORT_CONTRACT ), true ) ) return new WP_Error( 'mad4b_import_bundle_contract_unsupported', 'Import bundle contract is not supported.' );
		if ( self::LEGACY_EXPORT_CONTRACT === $contract ) {
			$source_home = isset( $bundle['source_home'] ) ? $this->normalized_home( $bundle['source_home'] ) : '';
			if ( '' === $source_home || $source_home !== $this->normalized_home( home_url( '/' ) ) ) return new WP_Error( 'mad4b_import_legacy_bundle_cross_site_denied', 'Legacy v1 bundles use source-local numeric identities and may only be imported back into the same site. Re-export as v2 for portable import.' );
		}

		$items = array();
		$source_ids = array();
		foreach ( array_values( $bundle['posts'] ) as $index => $item ) {
			if ( ! is_array( $item ) ) return new WP_Error( 'mad4b_import_item_invalid', 'Each imported post must be an object.' );
			$type = isset( $item['post_type'] ) ? sanitize_key( $item['post_type'] ) : '';
			$object = post_type_exists( $type ) ? get_post_type_object( $type ) : null;
			if ( ! $object || in_array( $type, array( 'attachment','revision','nav_menu_item' ), true ) ) return new WP_Error( 'mad4b_import_post_type_denied', 'Import item uses a missing or internal post type.' );
			$cap = isset( $object->cap->create_posts ) ? (string) $object->cap->create_posts : 'edit_posts';
			if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_import_create_denied', 'Current user cannot create one requested post type.' );
			$title = isset( $item['post_title'] ) ? (string) $item['post_title'] : '';
			if ( '' === trim( $title ) ) return new WP_Error( 'mad4b_import_title_required', 'Every imported post requires a title.' );

			$source_id = isset( $item['source_post_id'] ) ? absint( $item['source_post_id'] ) : 0;
			if ( self::EXPORT_CONTRACT === $contract ) {
				if ( $source_id < 1 ) return new WP_Error( 'mad4b_import_source_id_required', 'Portable v2 bundle items require source_post_id.' );
				if ( isset( $source_ids[ $source_id ] ) ) return new WP_Error( 'mad4b_import_duplicate_source_id', 'Portable bundle contains duplicate source_post_id values.' );
				$source_ids[ $source_id ] = $index;
			}
			$terms = isset( $item['terms'] ) && is_array( $item['terms'] ) ? $this->resolve_import_terms( $type, $item['terms'], $contract ) : array();
			if ( is_wp_error( $terms ) ) return $terms;
			$items[] = array(
				'contract' => $contract,
				'source_post_id' => $source_id,
				'source_parent_post_id' => self::EXPORT_CONTRACT === $contract ? ( isset( $item['source_parent_post_id'] ) ? absint( $item['source_parent_post_id'] ) : 0 ) : 0,
				'legacy_parent_id' => self::LEGACY_EXPORT_CONTRACT === $contract ? ( isset( $item['post_parent'] ) ? absint( $item['post_parent'] ) : 0 ) : 0,
				'post_type' => $type,
				'post_title' => $title,
				'post_name' => isset( $item['post_name'] ) ? sanitize_title( (string) $item['post_name'] ) : '',
				'post_content' => isset( $item['post_content'] ) ? (string) $item['post_content'] : '',
				'post_excerpt' => isset( $item['post_excerpt'] ) ? (string) $item['post_excerpt'] : '',
				'post_status' => $force_status,
				'menu_order' => isset( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
				'meta' => isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array(),
				'terms' => $terms,
				'index' => $index,
			);
		}

		if ( self::EXPORT_CONTRACT === $contract ) {
			foreach ( $items as $item ) {
				$parent_source = (int) $item['source_parent_post_id'];
				if ( $parent_source < 1 ) continue;
				if ( ! isset( $source_ids[ $parent_source ] ) ) return new WP_Error( 'mad4b_import_parent_not_in_bundle', 'Portable parent reference must point to another post included in the same bundle.', array( 'source_parent_post_id' => $parent_source ) );
				$parent_item = $items[ $source_ids[ $parent_source ] ];
				if ( $parent_item['post_type'] !== $item['post_type'] ) return new WP_Error( 'mad4b_import_parent_type_mismatch', 'Portable parent and child posts must use the same post type.' );
			}
		}
		return $items;
	}

	private function import_binding( array $items ) {
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		return hash( 'sha256', wp_json_encode( array(
			'ability' => 'mad4b/content-import-bundle',
			'items' => $items,
			'request_id' => isset( $identity['request_id'] ) ? $identity['request_id'] : MAD4B_SCP_Identity_Context::request_id(),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function imported_posts_for_binding( $binding ) {
		$query = new WP_Query( array(
			'post_type' => 'any',
			'post_status' => 'any',
			'posts_per_page' => self::MAX_IMPORT_POSTS + 1,
			'fields' => 'ids',
			'meta_key' => self::IMPORT_BINDING_META,
			'meta_value' => (string) $binding,
			'no_found_rows' => true,
		) );
		$ids = array_values( array_map( 'absint', (array) $query->posts ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private function imported_post_fingerprints_for_binding( $binding ) {
		$fingerprints = array();
		foreach ( $this->imported_posts_for_binding( $binding ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) continue;
			$meta = get_post_meta( $id );
			ksort( $meta, SORT_STRING );
			$meta_hashes = array();
			foreach ( $meta as $key => $values ) {
				$normalized = array_map( 'maybe_unserialize', (array) $values );
				$meta_hashes[ (string) $key ] = hash( 'sha256', wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$ids = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $ids ) ) continue;
				$ids = array_values( array_map( 'absint', (array) $ids ) );
				sort( $ids, SORT_NUMERIC );
				$terms[ $taxonomy ] = $ids;
			}
			ksort( $terms, SORT_STRING );
			$state = array(
				'post_type' => $post->post_type,
				'post_title' => $post->post_title,
				'post_name' => $post->post_name,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_status' => $post->post_status,
				'post_parent' => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
				'meta_hashes' => $meta_hashes,
				'terms' => $terms,
			);
			$fingerprints[ (string) $id ] = hash( 'sha256', wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		ksort( $fingerprints, SORT_STRING );
		return $fingerprints;
	}

	private function cleanup_created_posts( array $ids ) {
		foreach ( array_reverse( $ids ) as $id ) wp_delete_post( (int) $id, true );
	}

	public function content_import_bundle( $input ) {
		$status = isset( $input['force_status'] ) ? sanitize_key( $input['force_status'] ) : 'draft';
		$items = $this->normalize_import_bundle( $input['bundle'], $status );
		if ( is_wp_error( $items ) ) return $items;
		$binding = $this->import_binding( $items );
		if ( is_wp_error( $binding ) ) return $binding;
		if ( $this->imported_posts_for_binding( $binding ) ) return new WP_Error( 'mad4b_import_binding_exists', 'This exact governed import already created posts.' );

		$created = array();
		$created_by_index = array();
		$source_map = array();
		foreach ( $items as $item ) {
			$postarr = array(
				'post_type' => $item['post_type'],
				'post_title' => $item['post_title'],
				'post_name' => $item['post_name'],
				'post_content' => $item['post_content'],
				'post_excerpt' => $item['post_excerpt'],
				'post_status' => $item['post_status'],
				'post_parent' => self::LEGACY_EXPORT_CONTRACT === $item['contract'] ? $item['legacy_parent_id'] : 0,
				'menu_order' => $item['menu_order'],
				'meta_input' => array( self::IMPORT_BINDING_META => $binding ),
			);
			$id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) { $this->cleanup_created_posts( $created ); return $id; }
			$id = (int) $id;
			$created[] = $id;
			$created_by_index[ (int) $item['index'] ] = $id;
			if ( $item['source_post_id'] > 0 ) $source_map[ (string) $item['source_post_id'] ] = $id;
		}

		// Portable v2 parents are remapped only after every destination post exists.
		foreach ( $items as $item ) {
			if ( self::EXPORT_CONTRACT !== $item['contract'] || $item['source_parent_post_id'] < 1 ) continue;
			$id = $created_by_index[ (int) $item['index'] ];
			$parent_id = isset( $source_map[ (string) $item['source_parent_post_id'] ] ) ? (int) $source_map[ (string) $item['source_parent_post_id'] ] : 0;
			if ( $parent_id < 1 ) { $this->cleanup_created_posts( $created ); return new WP_Error( 'mad4b_import_parent_mapping_missing', 'Portable parent mapping disappeared during import.' ); }
			$updated = wp_update_post( array( 'ID' => $id, 'post_parent' => $parent_id ), true );
			if ( is_wp_error( $updated ) ) { $this->cleanup_created_posts( $created ); return $updated; }
		}

		foreach ( $items as $item ) {
			$id = $created_by_index[ (int) $item['index'] ];
			foreach ( $item['meta'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! array_key_exists( 'value', $entry ) ) continue;
				$key = $this->validate_meta_key( $entry['key'], $id, true );
				if ( is_wp_error( $key ) ) { $this->cleanup_created_posts( $created ); return $key; }
				update_post_meta( $id, $key, $entry['value'] );
			}
			foreach ( $item['terms'] as $taxonomy => $term_ids ) {
				$set = wp_set_object_terms( $id, $term_ids, $taxonomy, false );
				if ( is_wp_error( $set ) ) { $this->cleanup_created_posts( $created ); return $set; }
			}
		}

		$current = $this->imported_posts_for_binding( $binding );
		if ( count( $current ) !== count( $created ) ) { $this->cleanup_created_posts( $created ); return new WP_Error( 'mad4b_import_readback_failed', 'Imported bundle failed exact read-after-write verification.' ); }
		return array(
			'contract' => self::CONTRACT,
			'bundle_contract' => isset( $items[0]['contract'] ) ? $items[0]['contract'] : '',
			'binding' => $binding,
			'created_post_ids' => $current,
			'source_post_map' => $source_map,
			'count' => count( $current ),
			'status' => $status,
			'post_fingerprints' => $this->imported_post_fingerprints_for_binding( $binding ),
		);
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( in_array( $ability_name, array( 'mad4b/content-set-meta','mad4b/content-delete-meta' ), true ) ) {
			$id = absint( $input['post_id'] );
			$key = $this->validate_meta_key( $input['key'], $id, true );
			if ( is_wp_error( $key ) ) return $key;
			$state = $this->check_meta_expected( $id, $key, $input );
			if ( is_wp_error( $state ) ) return $state;
			return array( 'target_type' => 'post-meta', 'target_id' => $id . ':' . $key, 'target' => array( 'post_id' => $id, 'key' => $key ), 'state' => array( 'exists' => $state['exists'], 'value' => $state['value'] ) );
		}
		if ( 'mad4b/content-trash-post' === $ability_name ) {
			$id = absint( $input['post_id'] );
			$post = get_post( $id );
			if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
			if ( $post->post_modified_gmt !== (string) $input['expected_modified_gmt'] ) return new WP_Error( 'mad4b_post_state_drift', 'Post changed since it was read.' );
			return array( 'target_type' => 'post-trash', 'target_id' => (string) $id, 'target' => array( 'post_id' => $id ), 'state' => array( 'exists' => true, 'post_status' => $post->post_status ) );
		}
		if ( 'mad4b/taxonomy-update-term' === $ability_name ) {
			$taxonomy = sanitize_key( $input['taxonomy'] );
			$id = absint( $input['term_id'] );
			$state = $this->term_state( $taxonomy, $id );
			if ( is_wp_error( $state ) ) return $state;
			if ( ! hash_equals( $state['sha256'], strtolower( $input['expected_sha256'] ) ) ) return new WP_Error( 'mad4b_term_state_drift', 'Term changed since it was read.' );
			return array( 'target_type' => 'taxonomy-term', 'target_id' => $taxonomy . ':' . $id, 'target' => array( 'taxonomy' => $taxonomy, 'term_id' => $id ), 'state' => array( 'name' => $state['name'], 'slug' => $state['slug'], 'description' => $state['description'], 'parent' => $state['parent'] ) );
		}
		if ( 'mad4b/content-import-bundle' === $ability_name ) {
			$status = isset( $input['force_status'] ) ? sanitize_key( $input['force_status'] ) : 'draft';
			$items = $this->normalize_import_bundle( $input['bundle'], $status );
			if ( is_wp_error( $items ) ) return $items;
			$binding = $this->import_binding( $items );
			if ( is_wp_error( $binding ) ) return $binding;
			$fingerprints = $this->imported_post_fingerprints_for_binding( $binding );
			if ( $fingerprints ) return new WP_Error( 'mad4b_import_binding_exists', 'This exact governed import already exists.' );
			return array( 'target_type' => 'content-import-bundle', 'target_id' => $binding, 'target' => array( 'binding' => $binding ), 'state' => array( 'posts' => array() ) );
		}
		return parent::capture_reversible_state( $ability_name, $input );
	}

	public function read_reversible_state( $ability_name, array $target ) {
		if ( in_array( $ability_name, array( 'mad4b/content-set-meta','mad4b/content-delete-meta' ), true ) ) {
			$id = absint( $target['post_id'] );
			$key = $this->validate_meta_key( $target['key'], $id, false );
			if ( is_wp_error( $key ) ) return $key;
			$state = $this->meta_state( $id, $key );
			return is_wp_error( $state ) ? $state : array( 'exists' => $state['exists'], 'value' => $state['value'] );
		}
		if ( 'mad4b/content-trash-post' === $ability_name ) {
			$id = absint( $target['post_id'] );
			$post = get_post( $id );
			return array( 'exists' => (bool) $post, 'post_status' => $post ? $post->post_status : '' );
		}
		if ( 'mad4b/taxonomy-update-term' === $ability_name ) {
			$state = $this->term_state( sanitize_key( $target['taxonomy'] ), absint( $target['term_id'] ) );
			return is_wp_error( $state ) ? $state : array( 'name' => $state['name'], 'slug' => $state['slug'], 'description' => $state['description'], 'parent' => $state['parent'] );
		}
		if ( 'mad4b/content-import-bundle' === $ability_name ) {
			return array( 'posts' => $this->imported_post_fingerprints_for_binding( isset( $target['binding'] ) ? (string) $target['binding'] : '' ) );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( in_array( $ability_name, array( 'mad4b/content-set-meta','mad4b/content-delete-meta' ), true ) ) {
			$id = absint( $target['post_id'] );
			$key = $this->validate_meta_key( $target['key'], $id, true );
			if ( is_wp_error( $key ) ) return $key;
			if ( ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_content_meta_restore_denied', 'Current user cannot restore post meta.' );
			if ( ! empty( $state['exists'] ) ) update_post_meta( $id, $key, $state['value'] ); else delete_post_meta( $id, $key );
			return true;
		}
		if ( 'mad4b/content-trash-post' === $ability_name ) {
			$id = absint( $target['post_id'] );
			if ( ! current_user_can( 'delete_post', $id ) ) return new WP_Error( 'mad4b_post_restore_denied', 'Current user cannot restore this post.' );
			$post = get_post( $id );
			if ( ! $post || 'trash' !== $post->post_status ) return new WP_Error( 'mad4b_post_restore_state_invalid', 'Post is no longer in Trash.' );
			$restored = wp_untrash_post( $id );
			if ( ! $restored ) return new WP_Error( 'mad4b_post_untrash_failed', 'WordPress could not restore the post.' );
			$expected = isset( $state['post_status'] ) ? sanitize_key( $state['post_status'] ) : 'draft';
			$current = get_post( $id );
			if ( $current && $current->post_status !== $expected ) wp_update_post( array( 'ID' => $id, 'post_status' => $expected ) );
			return true;
		}
		if ( 'mad4b/taxonomy-update-term' === $ability_name ) {
			$taxonomy = sanitize_key( $target['taxonomy'] );
			$id = absint( $target['term_id'] );
			$result = wp_update_term( $id, $taxonomy, array( 'name' => $state['name'], 'slug' => $state['slug'], 'description' => $state['description'], 'parent' => absint( $state['parent'] ) ) );
			return is_wp_error( $result ) ? $result : true;
		}
		if ( 'mad4b/content-import-bundle' === $ability_name ) {
			$binding = isset( $target['binding'] ) ? (string) $target['binding'] : '';
			$ids = $this->imported_posts_for_binding( $binding );
			foreach ( array_reverse( $ids ) as $id ) {
				if ( ! current_user_can( 'delete_post', $id ) ) return new WP_Error( 'mad4b_import_undo_denied', 'Current user cannot delete an imported post.' );
				if ( ! wp_delete_post( $id, true ) ) return new WP_Error( 'mad4b_import_undo_failed', 'Imported post could not be deleted.' );
			}
			return true;
		}
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}
}
