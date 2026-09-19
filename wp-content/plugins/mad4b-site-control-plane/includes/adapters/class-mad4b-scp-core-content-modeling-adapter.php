<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral governed WordPress content modeling primitives.
 *
 * This adapter intentionally uses WordPress APIs only. Every mutation is routed
 * through the existing MAD4B authorization boundary and reversible adapter
 * mutation envelope, so remote writes still require the exact mad4b-write grant,
 * one-time approval ticket, budget, durable evidence and replay protection.
 */
final class MAD4B_SCP_Core_Content_Modeling_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.core-content-modeling.v1';
	const CREATE_POST_ABILITY = 'mad4b/content-create-post';
	const CREATE_TERM_ABILITY = 'mad4b/taxonomy-create-term';
	const SET_TERMS_ABILITY = 'mad4b/taxonomy-set-object-terms';
	const LIST_TAXONOMIES_ABILITY = 'mad4b/list-taxonomies';
	const LIST_TERMS_ABILITY = 'mad4b/list-terms';
	const POST_ROLLBACK_CONTRACT = 'mad4b.rollback.created-post.v1';
	const TERM_ROLLBACK_CONTRACT = 'mad4b.rollback.created-term.v1';
	const OBJECT_TERMS_ROLLBACK_CONTRACT = 'mad4b.rollback.object-terms.v1';
	const POST_BINDING_META = '_mad4b_content_create_binding';
	const TERM_BINDING_META = '_mad4b_term_create_binding';
	const MAX_TERMS = 100;

	public function id() { return 'core-content-modeling'; }
	public function label() { return 'Core Content Modeling'; }
	public function is_available() {
		return function_exists( 'wp_insert_post' )
			&& function_exists( 'wp_insert_term' )
			&& function_exists( 'wp_set_object_terms' );
	}
	protected function certified_provider_key() { return 'core'; }
	protected function mutation_requires_certification() { return false; }
	protected function detect_plugin_version() { return defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : ''; }

	public function ability_names() {
		return array(
			'read' => array( self::LIST_TAXONOMIES_ABILITY, self::LIST_TERMS_ABILITY ),
			'content' => array( self::CREATE_POST_ABILITY, self::CREATE_TERM_ABILITY, self::SET_TERMS_ABILITY ),
			'admin' => array(),
			'write' => array(),
		);
	}

	public function reversible_contracts() {
		return array(
			self::CREATE_POST_ABILITY => self::POST_ROLLBACK_CONTRACT,
			self::CREATE_TERM_ABILITY => self::TERM_ROLLBACK_CONTRACT,
			self::SET_TERMS_ABILITY => self::OBJECT_TERMS_ROLLBACK_CONTRACT,
		);
	}

	public function register_abilities() {
		if ( ! wp_has_ability( self::LIST_TAXONOMIES_ABILITY ) ) {
			$this->add_ability(
				self::LIST_TAXONOMIES_ABILITY,
				'List Taxonomies',
				'list_taxonomies',
				array( 'MAD4B_SCP_Policy', 'can_read' ),
				$this->schema(
					array(
						'object_type' => array( 'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-zA-Z0-9_-]*$' ),
					)
				),
				'read',
				true,
				false,
				true
			);
		}

		if ( ! wp_has_ability( self::LIST_TERMS_ABILITY ) ) {
			$this->add_ability(
				self::LIST_TERMS_ABILITY,
				'List Taxonomy Terms',
				'list_terms',
				array( 'MAD4B_SCP_Policy', 'can_read' ),
				$this->schema(
					array(
						'taxonomy' => $this->slug_schema(),
						'search' => array( 'type' => 'string', 'maxLength' => 200, 'default' => '' ),
						'parent' => array( 'type' => 'integer', 'minimum' => 0 ),
						'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
					),
					array( 'taxonomy' )
				),
				'read',
				true,
				false,
				true
			);
		}

		if ( ! wp_has_ability( self::CREATE_POST_ABILITY ) ) {
			$this->add_ability(
				self::CREATE_POST_ABILITY,
				'Create Post or Custom Post Type Item',
				'create_post',
				array( $this, 'can_create_post' ),
				$this->schema(
					array(
						'post_type' => $this->slug_schema(),
						'post_title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
						'post_content' => array( 'type' => 'string', 'maxLength' => 2097152, 'default' => '' ),
						'post_excerpt' => array( 'type' => 'string', 'maxLength' => 262144, 'default' => '' ),
						'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'draft' ),
						'post_parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
						'post_author' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					array( 'post_type', 'post_title' )
				),
				'content',
				false,
				true,
				false
			);
		}

		if ( ! wp_has_ability( self::CREATE_TERM_ABILITY ) ) {
			$this->add_ability(
				self::CREATE_TERM_ABILITY,
				'Create Taxonomy Term',
				'create_term',
				array( $this, 'can_manage_terms' ),
				$this->schema(
					array(
						'taxonomy' => $this->slug_schema(),
						'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
						'slug' => array( 'type' => 'string', 'maxLength' => 200 ),
						'description' => array( 'type' => 'string', 'maxLength' => 65535, 'default' => '' ),
						'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					),
					array( 'taxonomy', 'name' )
				),
				'content',
				false,
				true,
				false
			);
		}

		if ( ! wp_has_ability( self::SET_TERMS_ABILITY ) ) {
			$this->add_ability(
				self::SET_TERMS_ABILITY,
				'Set Object Taxonomy Terms',
				'set_object_terms',
				array( $this, 'can_assign_terms' ),
				$this->schema(
					array(
						'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'taxonomy' => $this->slug_schema(),
						'term_ids' => array( 'type' => 'array', 'maxItems' => self::MAX_TERMS, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
						'expected_term_ids' => array( 'type' => 'array', 'maxItems' => self::MAX_TERMS, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
						'append' => array( 'type' => 'boolean', 'default' => false ),
					),
					array( 'object_id', 'taxonomy', 'term_ids', 'expected_term_ids' )
				),
				'content',
				false,
				true,
				false
			);
		}
	}

	private function slug_schema() {
		return array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-zA-Z0-9_-]+$' );
	}

	public function list_taxonomies( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$object_type = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : '';
		if ( '' !== $object_type && ! post_type_exists( $object_type ) ) return new WP_Error( 'mad4b_post_type_missing', 'Requested post type is not registered.' );
		$objects = get_taxonomies( array(), 'objects' );
		$items = array();
		foreach ( is_array( $objects ) ? $objects : array() as $taxonomy => $object ) {
			if ( ! is_object( $object ) ) continue;
			$object_types = isset( $object->object_type ) && is_array( $object->object_type ) ? array_values( array_map( 'strval', $object->object_type ) ) : array();
			if ( '' !== $object_type && ! in_array( $object_type, $object_types, true ) ) continue;
			$manage_cap = isset( $object->cap->manage_terms ) ? (string) $object->cap->manage_terms : 'manage_categories';
			$assign_cap = isset( $object->cap->assign_terms ) ? (string) $object->cap->assign_terms : 'edit_posts';
			$items[] = array(
				'name' => (string) $taxonomy,
				'label' => isset( $object->label ) ? (string) $object->label : (string) $taxonomy,
				'hierarchical' => ! empty( $object->hierarchical ),
				'public' => ! empty( $object->public ),
				'show_ui' => ! empty( $object->show_ui ),
				'object_types' => $object_types,
				'can_manage_terms' => current_user_can( $manage_cap ),
				'can_assign_terms' => current_user_can( $assign_cap ),
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return array( 'contract' => self::CONTRACT, 'object_type' => $object_type, 'count' => count( $items ), 'taxonomies' => $items );
	}

	public function list_terms( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$tax = $this->taxonomy_object( $taxonomy );
		if ( is_wp_error( $tax ) ) return $tax;
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$args = array(
			'taxonomy' => $taxonomy,
			'hide_empty' => ! empty( $input['hide_empty'] ),
			'number' => $limit,
			'orderby' => 'term_id',
			'order' => 'ASC',
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) $args['search'] = sanitize_text_field( (string) $input['search'] );
		if ( isset( $input['parent'] ) ) $args['parent'] = absint( $input['parent'] );
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) return $terms;
		$items = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! is_object( $term ) ) continue;
			$items[] = array(
				'term_id' => (int) $term->term_id,
				'name' => (string) $term->name,
				'slug' => (string) $term->slug,
				'description' => (string) $term->description,
				'parent' => (int) $term->parent,
				'count' => (int) $term->count,
			);
		}
		return array( 'contract' => self::CONTRACT, 'taxonomy' => $taxonomy, 'count' => count( $items ), 'terms' => $items );
	}

	public function can_create_post( $input = array() ) {
		$guard = $this->validate_post_create_input( is_array( $input ) ? $input : array(), false );
		return is_wp_error( $guard ) ? $guard : true;
	}

	public function can_manage_terms( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$tax = $this->taxonomy_object( $taxonomy );
		if ( is_wp_error( $tax ) ) return $tax;
		$cap = isset( $tax->cap->manage_terms ) ? (string) $tax->cap->manage_terms : 'manage_categories';
		return current_user_can( $cap ) ? true : new WP_Error( 'mad4b_taxonomy_manage_denied', 'Current user cannot manage terms in the requested taxonomy.' );
	}

	public function can_assign_terms( $input = array() ) {
		$guard = $this->validate_term_assignment_input( is_array( $input ) ? $input : array(), false );
		return is_wp_error( $guard ) ? $guard : true;
	}

	public function create_post( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$validated = $this->validate_post_create_input( $input, true );
		if ( is_wp_error( $validated ) ) return $validated;
		$binding = $this->operation_binding( self::CREATE_POST_ABILITY, $validated );
		if ( is_wp_error( $binding ) ) return $binding;
		$existing = $this->post_state_for_binding( $validated['post_type'], $binding );
		if ( is_wp_error( $existing ) ) return $existing;
		if ( ! empty( $existing['exists'] ) ) return new WP_Error( 'mad4b_content_create_binding_exists', 'This exact governed create operation already has a bound post.' );

		$postarr = array(
			'post_type' => $validated['post_type'],
			'post_title' => $validated['post_title'],
			'post_content' => $validated['post_content'],
			'post_excerpt' => $validated['post_excerpt'],
			'post_status' => $validated['post_status'],
			'post_parent' => $validated['post_parent'],
			'meta_input' => array( self::POST_BINDING_META => $binding ),
		);
		if ( isset( $validated['post_author'] ) ) $postarr['post_author'] = (int) $validated['post_author'];
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) return $post_id;
		$after = $this->post_state_for_binding( $validated['post_type'], $binding );
		if ( is_wp_error( $after ) || empty( $after['exists'] ) || (int) $after['post_id'] !== (int) $post_id ) {
			wp_delete_post( (int) $post_id, true );
			return new WP_Error( 'mad4b_content_create_verification_failed', 'Created post failed exact read-after-write verification.' );
		}
		return array(
			'contract' => self::CONTRACT,
			'created' => true,
			'post_id' => (int) $post_id,
			'post_type' => $validated['post_type'],
			'post_status' => (string) $after['post_status'],
		);
	}

	public function create_term( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$validated = $this->validate_term_create_input( $input );
		if ( is_wp_error( $validated ) ) return $validated;
		$binding = $this->operation_binding( self::CREATE_TERM_ABILITY, $validated );
		if ( is_wp_error( $binding ) ) return $binding;
		$existing = $this->term_state_for_binding( $validated['taxonomy'], $binding );
		if ( is_wp_error( $existing ) ) return $existing;
		if ( ! empty( $existing['exists'] ) ) return new WP_Error( 'mad4b_term_create_binding_exists', 'This exact governed create operation already has a bound term.' );

		$args = array( 'description' => $validated['description'], 'parent' => $validated['parent'] );
		if ( '' !== $validated['slug'] ) $args['slug'] = $validated['slug'];
		$result = wp_insert_term( $validated['name'], $validated['taxonomy'], $args );
		if ( is_wp_error( $result ) ) return $result;
		$term_id = isset( $result['term_id'] ) ? absint( $result['term_id'] ) : 0;
		if ( $term_id < 1 ) return new WP_Error( 'mad4b_term_create_id_missing', 'WordPress created a term without returning a valid term id.' );
		$meta = add_term_meta( $term_id, self::TERM_BINDING_META, $binding, true );
		if ( is_wp_error( $meta ) || false === $meta ) {
			wp_delete_term( $term_id, $validated['taxonomy'] );
			return new WP_Error( 'mad4b_term_create_binding_failed', 'Created term could not be bound to the governed mutation envelope.' );
		}
		$after = $this->term_state_for_binding( $validated['taxonomy'], $binding );
		if ( is_wp_error( $after ) || empty( $after['exists'] ) || (int) $after['term_id'] !== $term_id ) {
			wp_delete_term( $term_id, $validated['taxonomy'] );
			return new WP_Error( 'mad4b_term_create_verification_failed', 'Created term failed exact read-after-write verification.' );
		}
		return array( 'contract' => self::CONTRACT, 'created' => true, 'taxonomy' => $validated['taxonomy'], 'term_id' => $term_id, 'slug' => (string) $after['slug'] );
	}

	public function set_object_terms( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$validated = $this->validate_term_assignment_input( $input, true );
		if ( is_wp_error( $validated ) ) return $validated;
		$current = $this->object_term_ids( $validated['object_id'], $validated['taxonomy'] );
		if ( is_wp_error( $current ) ) return $current;
		if ( $current !== $validated['expected_term_ids'] ) return new WP_Error( 'mad4b_term_assignment_state_drift', 'Current taxonomy terms do not match expected_term_ids.', array( 'current_term_ids' => $current ) );
		$result = wp_set_object_terms( $validated['object_id'], $validated['term_ids'], $validated['taxonomy'], $validated['append'] );
		if ( is_wp_error( $result ) ) return $result;
		$after = $this->object_term_ids( $validated['object_id'], $validated['taxonomy'] );
		if ( is_wp_error( $after ) ) return $after;
		$expected_after = $validated['append'] ? $this->sorted_ids( array_merge( $current, $validated['term_ids'] ) ) : $validated['term_ids'];
		if ( $after !== $expected_after ) return new WP_Error( 'mad4b_term_assignment_readback_mismatch', 'Taxonomy assignment completed but exact readback did not match the requested term set.', array( 'current_term_ids' => $after ) );
		return array( 'contract' => self::CONTRACT, 'object_id' => $validated['object_id'], 'taxonomy' => $validated['taxonomy'], 'term_ids' => $after, 'verified' => true );
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( self::CREATE_POST_ABILITY === (string) $ability_name ) {
			$validated = $this->validate_post_create_input( $input, true );
			if ( is_wp_error( $validated ) ) return $validated;
			$binding = $this->operation_binding( self::CREATE_POST_ABILITY, $validated );
			if ( is_wp_error( $binding ) ) return $binding;
			$state = $this->post_state_for_binding( $validated['post_type'], $binding );
			if ( is_wp_error( $state ) ) return $state;
			if ( ! empty( $state['exists'] ) ) return new WP_Error( 'mad4b_content_create_binding_exists', 'This exact governed create operation already has a bound post.' );
			return array( 'target_type' => 'post-create', 'target_id' => $binding, 'target' => array( 'kind' => 'post-create', 'binding' => $binding, 'post_type' => $validated['post_type'] ), 'state' => $state );
		}
		if ( self::CREATE_TERM_ABILITY === (string) $ability_name ) {
			$validated = $this->validate_term_create_input( $input );
			if ( is_wp_error( $validated ) ) return $validated;
			$binding = $this->operation_binding( self::CREATE_TERM_ABILITY, $validated );
			if ( is_wp_error( $binding ) ) return $binding;
			$state = $this->term_state_for_binding( $validated['taxonomy'], $binding );
			if ( is_wp_error( $state ) ) return $state;
			if ( ! empty( $state['exists'] ) ) return new WP_Error( 'mad4b_term_create_binding_exists', 'This exact governed create operation already has a bound term.' );
			return array( 'target_type' => 'term-create', 'target_id' => $binding, 'target' => array( 'kind' => 'term-create', 'binding' => $binding, 'taxonomy' => $validated['taxonomy'] ), 'state' => $state );
		}
		if ( self::SET_TERMS_ABILITY === (string) $ability_name ) {
			$validated = $this->validate_term_assignment_input( $input, true );
			if ( is_wp_error( $validated ) ) return $validated;
			$current = $this->object_term_ids( $validated['object_id'], $validated['taxonomy'] );
			if ( is_wp_error( $current ) ) return $current;
			if ( $current !== $validated['expected_term_ids'] ) return new WP_Error( 'mad4b_term_assignment_state_drift', 'Current taxonomy terms do not match expected_term_ids.', array( 'current_term_ids' => $current ) );
			return array(
				'target_type' => 'object-terms',
				'target_id' => $validated['object_id'] . ':' . $validated['taxonomy'],
				'target' => array( 'kind' => 'object-terms', 'object_id' => $validated['object_id'], 'taxonomy' => $validated['taxonomy'] ),
				'state' => array( 'term_ids' => $current ),
			);
		}
		return parent::capture_reversible_state( $ability_name, $input );
	}

	public function read_reversible_state( $ability_name, array $target ) {
		if ( self::CREATE_POST_ABILITY === (string) $ability_name ) {
			if ( 'post-create' !== ( isset( $target['kind'] ) ? (string) $target['kind'] : '' ) ) return new WP_Error( 'mad4b_post_create_target_invalid', 'Recorded post-create target is invalid.' );
			return $this->post_state_for_binding( isset( $target['post_type'] ) ? sanitize_key( $target['post_type'] ) : '', isset( $target['binding'] ) ? (string) $target['binding'] : '' );
		}
		if ( self::CREATE_TERM_ABILITY === (string) $ability_name ) {
			if ( 'term-create' !== ( isset( $target['kind'] ) ? (string) $target['kind'] : '' ) ) return new WP_Error( 'mad4b_term_create_target_invalid', 'Recorded term-create target is invalid.' );
			return $this->term_state_for_binding( isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '', isset( $target['binding'] ) ? (string) $target['binding'] : '' );
		}
		if ( self::SET_TERMS_ABILITY === (string) $ability_name ) {
			if ( 'object-terms' !== ( isset( $target['kind'] ) ? (string) $target['kind'] : '' ) ) return new WP_Error( 'mad4b_object_terms_target_invalid', 'Recorded object-terms target is invalid.' );
			$object_id = isset( $target['object_id'] ) ? absint( $target['object_id'] ) : 0;
			$taxonomy = isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '';
			$guard = $this->validate_object_taxonomy_target( $object_id, $taxonomy );
			if ( is_wp_error( $guard ) ) return $guard;
			$ids = $this->object_term_ids( $object_id, $taxonomy );
			return is_wp_error( $ids ) ? $ids : array( 'term_ids' => $ids );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( self::CREATE_POST_ABILITY === (string) $ability_name ) {
			if ( ! empty( $state['exists'] ) ) return new WP_Error( 'mad4b_post_create_restore_state_invalid', 'Created-post rollback requires an originally absent target.' );
			$current = $this->read_reversible_state( $ability_name, $target );
			if ( is_wp_error( $current ) ) return $current;
			if ( empty( $current['exists'] ) ) return array( 'restored' => true, 'already_absent' => true );
			$post_id = isset( $current['post_id'] ) ? absint( $current['post_id'] ) : 0;
			if ( $post_id < 1 || ! current_user_can( 'delete_post', $post_id ) ) return new WP_Error( 'mad4b_post_create_undo_denied', 'Current user cannot delete the created post.' );
			$deleted = wp_delete_post( $post_id, true );
			if ( false === $deleted || null === $deleted ) return new WP_Error( 'mad4b_post_create_undo_failed', 'Created post could not be deleted during rollback.' );
			$after = $this->read_reversible_state( $ability_name, $target );
			if ( is_wp_error( $after ) || ! empty( $after['exists'] ) ) return new WP_Error( 'mad4b_post_create_undo_verification_failed', 'Created post rollback did not restore the absent before-state.' );
			return array( 'restored' => true, 'deleted_post_id' => $post_id );
		}
		if ( self::CREATE_TERM_ABILITY === (string) $ability_name ) {
			if ( ! empty( $state['exists'] ) ) return new WP_Error( 'mad4b_term_create_restore_state_invalid', 'Created-term rollback requires an originally absent target.' );
			$current = $this->read_reversible_state( $ability_name, $target );
			if ( is_wp_error( $current ) ) return $current;
			if ( empty( $current['exists'] ) ) return array( 'restored' => true, 'already_absent' => true );
			$term_id = isset( $current['term_id'] ) ? absint( $current['term_id'] ) : 0;
			$taxonomy = isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '';
			$tax = $this->taxonomy_object( $taxonomy );
			if ( is_wp_error( $tax ) ) return $tax;
			$manage_cap = isset( $tax->cap->manage_terms ) ? (string) $tax->cap->manage_terms : 'manage_categories';
			if ( ! current_user_can( $manage_cap ) ) return new WP_Error( 'mad4b_term_create_undo_denied', 'Current user cannot delete the created term.' );
			$objects = get_objects_in_term( $term_id, $taxonomy );
			if ( is_wp_error( $objects ) ) return $objects;
			if ( ! empty( $objects ) || ! empty( $current['count'] ) ) return new WP_Error( 'mad4b_term_create_undo_in_use', 'Created term is now assigned to objects; rollback refuses to delete newer use.' );
			$deleted = wp_delete_term( $term_id, $taxonomy );
			if ( is_wp_error( $deleted ) || false === $deleted ) return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'mad4b_term_create_undo_failed', 'Created term could not be deleted during rollback.' );
			$after = $this->read_reversible_state( $ability_name, $target );
			if ( is_wp_error( $after ) || ! empty( $after['exists'] ) ) return new WP_Error( 'mad4b_term_create_undo_verification_failed', 'Created term rollback did not restore the absent before-state.' );
			return array( 'restored' => true, 'deleted_term_id' => $term_id, 'taxonomy' => $taxonomy );
		}
		if ( self::SET_TERMS_ABILITY === (string) $ability_name ) {
			$object_id = isset( $target['object_id'] ) ? absint( $target['object_id'] ) : 0;
			$taxonomy = isset( $target['taxonomy'] ) ? sanitize_key( $target['taxonomy'] ) : '';
			$guard = $this->validate_object_taxonomy_target( $object_id, $taxonomy );
			if ( is_wp_error( $guard ) ) return $guard;
			$tax = get_taxonomy( $taxonomy );
			$assign_cap = isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : 'edit_posts';
			if ( ! current_user_can( 'edit_post', $object_id ) || ! current_user_can( $assign_cap ) ) return new WP_Error( 'mad4b_term_assignment_undo_denied', 'Current user cannot restore terms for this object.' );
			$term_ids = isset( $state['term_ids'] ) && is_array( $state['term_ids'] ) ? $this->sorted_ids( $state['term_ids'] ) : array();
			$result = wp_set_object_terms( $object_id, $term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) return $result;
			$after = $this->object_term_ids( $object_id, $taxonomy );
			if ( is_wp_error( $after ) ) return $after;
			if ( $after !== $term_ids ) return new WP_Error( 'mad4b_term_assignment_undo_verification_failed', 'Taxonomy rollback did not restore the recorded before-state.' );
			return array( 'restored' => true, 'object_id' => $object_id, 'taxonomy' => $taxonomy, 'term_ids' => $after );
		}
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}

	private function validate_post_create_input( array $input, $normalize ) {
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		$object = $this->creatable_post_type_object( $post_type );
		if ( is_wp_error( $object ) ) return $object;
		$create_cap = isset( $object->cap->create_posts ) ? (string) $object->cap->create_posts : ( isset( $object->cap->edit_posts ) ? (string) $object->cap->edit_posts : 'edit_posts' );
		if ( ! current_user_can( $create_cap ) ) return new WP_Error( 'mad4b_post_create_denied', 'Current user cannot create the requested post type.' );
		$title = isset( $input['post_title'] ) ? (string) $input['post_title'] : '';
		if ( '' === trim( $title ) ) return new WP_Error( 'mad4b_post_title_required', 'A non-empty post title is required.' );
		$status = isset( $input['post_status'] ) ? sanitize_key( (string) $input['post_status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) return new WP_Error( 'mad4b_post_status_invalid', 'Requested post status is not allowed by this governed create surface.' );
		if ( in_array( $status, array( 'publish', 'private' ), true ) ) {
			$publish_cap = isset( $object->cap->publish_posts ) ? (string) $object->cap->publish_posts : 'publish_posts';
			if ( ! current_user_can( $publish_cap ) ) return new WP_Error( 'mad4b_post_publish_denied', 'Current user cannot publish/private-create the requested post type.' );
		}
		$parent = isset( $input['post_parent'] ) ? absint( $input['post_parent'] ) : 0;
		if ( $parent > 0 ) {
			if ( empty( $object->hierarchical ) ) return new WP_Error( 'mad4b_post_parent_not_supported', 'post_parent is only supported for hierarchical post types.' );
			$parent_post = get_post( $parent );
			if ( ! $parent_post || $post_type !== (string) $parent_post->post_type ) return new WP_Error( 'mad4b_post_parent_invalid', 'post_parent must reference an existing item of the same post type.' );
			if ( ! current_user_can( 'edit_post', $parent ) ) return new WP_Error( 'mad4b_post_parent_denied', 'Current user cannot use the requested parent.' );
		}
		$author = isset( $input['post_author'] ) ? absint( $input['post_author'] ) : 0;
		if ( $author > 0 && $author !== get_current_user_id() ) {
			$others_cap = isset( $object->cap->edit_others_posts ) ? (string) $object->cap->edit_others_posts : 'edit_others_posts';
			if ( ! current_user_can( $others_cap ) || ! get_userdata( $author ) ) return new WP_Error( 'mad4b_post_author_denied', 'Current user cannot assign the requested post author.' );
		}
		if ( ! $normalize ) return true;
		$out = array(
			'post_type' => $post_type,
			'post_title' => $title,
			'post_content' => isset( $input['post_content'] ) ? (string) $input['post_content'] : '',
			'post_excerpt' => isset( $input['post_excerpt'] ) ? (string) $input['post_excerpt'] : '',
			'post_status' => $status,
			'post_parent' => $parent,
		);
		if ( $author > 0 ) $out['post_author'] = $author;
		return $out;
	}

	private function validate_term_create_input( array $input ) {
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$tax = $this->taxonomy_object( $taxonomy );
		if ( is_wp_error( $tax ) ) return $tax;
		$manage_cap = isset( $tax->cap->manage_terms ) ? (string) $tax->cap->manage_terms : 'manage_categories';
		if ( ! current_user_can( $manage_cap ) ) return new WP_Error( 'mad4b_taxonomy_manage_denied', 'Current user cannot manage terms in the requested taxonomy.' );
		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( '' === $name ) return new WP_Error( 'mad4b_term_name_required', 'A non-empty term name is required.' );
		$parent = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		if ( $parent > 0 ) {
			if ( empty( $tax->hierarchical ) ) return new WP_Error( 'mad4b_term_parent_not_supported', 'Parent terms are only supported for hierarchical taxonomies.' );
			$parent_term = get_term( $parent, $taxonomy );
			if ( is_wp_error( $parent_term ) || ! $parent_term ) return new WP_Error( 'mad4b_term_parent_invalid', 'Parent term does not exist in the requested taxonomy.' );
		}
		$existing = term_exists( $name, $taxonomy, $parent );
		if ( is_array( $existing ) || is_int( $existing ) || ( is_string( $existing ) && '' !== $existing ) ) return new WP_Error( 'mad4b_term_already_exists', 'A matching term already exists in the requested taxonomy.' );
		return array(
			'taxonomy' => $taxonomy,
			'name' => $name,
			'slug' => isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '',
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'parent' => $parent,
		);
	}

	private function validate_term_assignment_input( array $input, $normalize ) {
		$object_id = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0;
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$guard = $this->validate_object_taxonomy_target( $object_id, $taxonomy );
		if ( is_wp_error( $guard ) ) return $guard;
		$tax = get_taxonomy( $taxonomy );
		$assign_cap = isset( $tax->cap->assign_terms ) ? (string) $tax->cap->assign_terms : 'edit_posts';
		if ( ! current_user_can( 'edit_post', $object_id ) || ! current_user_can( $assign_cap ) ) return new WP_Error( 'mad4b_taxonomy_assign_denied', 'Current user cannot assign terms for this object and taxonomy.' );
		if ( ! array_key_exists( 'term_ids', $input ) || ! is_array( $input['term_ids'] ) || ! array_key_exists( 'expected_term_ids', $input ) || ! is_array( $input['expected_term_ids'] ) ) return new WP_Error( 'mad4b_taxonomy_terms_input_invalid', 'term_ids and expected_term_ids arrays are required.' );
		$term_ids = $this->sorted_ids( $input['term_ids'] );
		$expected = $this->sorted_ids( $input['expected_term_ids'] );
		if ( count( $term_ids ) > self::MAX_TERMS || count( $expected ) > self::MAX_TERMS ) return new WP_Error( 'mad4b_taxonomy_terms_too_many', 'Taxonomy assignment exceeds the bounded term limit.' );
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( is_wp_error( $term ) || ! $term ) return new WP_Error( 'mad4b_taxonomy_term_invalid', 'Every assigned term id must exist in the requested taxonomy.', array( 'term_id' => $term_id ) );
		}
		$current = $this->object_term_ids( $object_id, $taxonomy );
		if ( is_wp_error( $current ) ) return $current;
		if ( $current !== $expected ) return new WP_Error( 'mad4b_term_assignment_state_drift', 'Current taxonomy terms do not match expected_term_ids.', array( 'current_term_ids' => $current ) );
		if ( ! $normalize ) return true;
		return array( 'object_id' => $object_id, 'taxonomy' => $taxonomy, 'term_ids' => $term_ids, 'expected_term_ids' => $expected, 'append' => ! empty( $input['append'] ) );
	}

	private function validate_object_taxonomy_target( $object_id, $taxonomy ) {
		$object_id = absint( $object_id );
		$taxonomy = sanitize_key( (string) $taxonomy );
		$post = $object_id > 0 ? get_post( $object_id ) : null;
		if ( ! $post ) return new WP_Error( 'mad4b_taxonomy_object_missing', 'Target object does not exist.' );
		$tax = $this->taxonomy_object( $taxonomy );
		if ( is_wp_error( $tax ) ) return $tax;
		if ( ! is_object_in_taxonomy( (string) $post->post_type, $taxonomy ) ) return new WP_Error( 'mad4b_taxonomy_object_mismatch', 'Requested taxonomy is not registered for the target post type.' );
		return true;
	}

	private function creatable_post_type_object( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		$object = '' !== $post_type ? get_post_type_object( $post_type ) : null;
		if ( ! $object ) return new WP_Error( 'mad4b_post_type_missing', 'Requested post type is not registered.' );
		if ( ! empty( $object->_builtin ) && ! in_array( $post_type, array( 'post', 'page' ), true ) ) return new WP_Error( 'mad4b_post_type_internal_denied', 'Internal WordPress post types are not creatable through this generic surface.' );
		if ( in_array( $post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) return new WP_Error( 'mad4b_post_type_internal_denied', 'Internal WordPress post types are not creatable through this generic surface.' );
		return $object;
	}

	private function taxonomy_object( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		$object = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : null;
		return $object ? $object : new WP_Error( 'mad4b_taxonomy_missing', 'Requested taxonomy is not registered.' );
	}

	private function operation_binding( $ability_name, array $input ) {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_identity_unavailable', 'MAD4B identity context is unavailable.' );
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return is_wp_error( $identity ) ? $identity : new WP_Error( 'mad4b_identity_unavailable', 'MAD4B identity context is unavailable.' );
		$ticket_id = isset( $identity['approval_ticket_id'] ) ? strtolower( trim( (string) $identity['approval_ticket_id'] ) ) : '';
		$request_id = isset( $identity['request_id'] ) ? strtolower( trim( (string) $identity['request_id'] ) ) : '';
		$nonce = '' !== $ticket_id ? 'ticket:' . $ticket_id : 'request:' . $request_id;
		if ( 'request:' === $nonce ) return new WP_Error( 'mad4b_content_create_identity_incomplete', 'A governed mutation identity is required to bind object creation.' );
		$payload = array( 'contract' => self::CONTRACT, 'ability' => (string) $ability_name, 'nonce' => $nonce, 'input' => $this->canonical_value( $input ) );
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? new WP_Error( 'mad4b_content_create_binding_encode_failed', 'Governed create binding could not be encoded.' ) : hash( 'sha256', $json );
	}

	private function post_state_for_binding( $post_type, $binding ) {
		$post_type = sanitize_key( (string) $post_type );
		$binding = strtolower( trim( (string) $binding ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $binding ) || ! post_type_exists( $post_type ) ) return new WP_Error( 'mad4b_post_create_binding_invalid', 'Recorded created-post binding is invalid.' );
		$posts = get_posts( array(
			'post_type' => $post_type,
			'post_status' => array( 'draft', 'pending', 'private', 'publish', 'future', 'trash' ),
			'posts_per_page' => 2,
			'fields' => 'ids',
			'meta_key' => self::POST_BINDING_META,
			'meta_value' => $binding,
			'orderby' => 'ID',
			'order' => 'ASC',
			'suppress_filters' => true,
		) );
		$ids = array_values( array_map( 'absint', is_array( $posts ) ? $posts : array() ) );
		if ( count( $ids ) > 1 ) return new WP_Error( 'mad4b_post_create_binding_not_unique', 'Created-post binding resolved to more than one object.' );
		if ( empty( $ids ) ) return array( 'exists' => false, 'binding' => $binding );
		$post = get_post( $ids[0] );
		if ( ! $post || $post_type !== (string) $post->post_type || ! hash_equals( $binding, strtolower( (string) get_post_meta( $ids[0], self::POST_BINDING_META, true ) ) ) ) return new WP_Error( 'mad4b_post_create_binding_mismatch', 'Created-post binding no longer resolves exactly.' );
		return array(
			'exists' => true,
			'binding' => $binding,
			'post_id' => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'post_title' => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_parent' => (int) $post->post_parent,
			'post_author' => (int) $post->post_author,
			'meta_sha256' => $this->post_meta_hash_state( (int) $post->ID ),
			'terms' => $this->post_terms_state( (int) $post->ID, $post_type ),
		);
	}

	private function term_state_for_binding( $taxonomy, $binding ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		$binding = strtolower( trim( (string) $binding ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $binding ) || ! taxonomy_exists( $taxonomy ) ) return new WP_Error( 'mad4b_term_create_binding_invalid', 'Recorded created-term binding is invalid.' );
		$terms = get_terms( array(
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
			'number' => 2,
			'meta_key' => self::TERM_BINDING_META,
			'meta_value' => $binding,
			'orderby' => 'term_id',
			'order' => 'ASC',
		) );
		if ( is_wp_error( $terms ) ) return $terms;
		if ( count( $terms ) > 1 ) return new WP_Error( 'mad4b_term_create_binding_not_unique', 'Created-term binding resolved to more than one term.' );
		if ( empty( $terms ) ) return array( 'exists' => false, 'binding' => $binding, 'taxonomy' => $taxonomy );
		$term = reset( $terms );
		if ( ! is_object( $term ) || ! hash_equals( $binding, strtolower( (string) get_term_meta( (int) $term->term_id, self::TERM_BINDING_META, true ) ) ) ) return new WP_Error( 'mad4b_term_create_binding_mismatch', 'Created-term binding no longer resolves exactly.' );
		return array(
			'exists' => true,
			'binding' => $binding,
			'taxonomy' => $taxonomy,
			'term_id' => (int) $term->term_id,
			'name' => (string) $term->name,
			'slug' => (string) $term->slug,
			'description' => (string) $term->description,
			'parent' => (int) $term->parent,
			'meta_sha256' => $this->term_meta_hash_state( (int) $term->term_id ),
			'count' => (int) $term->count,
		);
	}

	private function metadata_hash_state( $object_type, $object_id ) {
		$object_id = absint( $object_id );
		$raw = 'term' === $object_type ? get_term_meta( $object_id ) : get_post_meta( $object_id );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $values ) {
			$normalized = array();
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				$normalized[] = $this->canonical_value( maybe_unserialize( $value ) );
			}
			$json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$out[ (string) $key ] = hash( 'sha256', false === $json ? 'null' : $json );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function post_meta_hash_state( $post_id ) {
		return $this->metadata_hash_state( 'post', $post_id );
	}

	private function term_meta_hash_state( $term_id ) {
		return $this->metadata_hash_state( 'term', $term_id );
	}

	private function post_terms_state( $post_id, $post_type ) {
		$out = array();
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		foreach ( is_array( $taxonomies ) ? $taxonomies : array() as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) continue;
			$out[ (string) $taxonomy ] = $this->sorted_ids( is_array( $ids ) ? $ids : array() );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function object_term_ids( $object_id, $taxonomy ) {
		$ids = wp_get_object_terms( absint( $object_id ), sanitize_key( (string) $taxonomy ), array( 'fields' => 'ids' ) );
		return is_wp_error( $ids ) ? $ids : $this->sorted_ids( is_array( $ids ) ? $ids : array() );
	}

	private function sorted_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private function canonical_value( $value ) {
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = $this->canonical_value( $item );
		}
		return $value;
	}
}
