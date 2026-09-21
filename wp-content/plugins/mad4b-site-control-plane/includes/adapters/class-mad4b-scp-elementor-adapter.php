<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Elementor_Adapter extends MAD4B_SCP_Adapter_Base {
	const ROOT_PARENT = '__root__';
	const MAX_STRUCTURAL_ROLLBACK_BYTES = 196608;
	public function id() { return 'elementor'; }
	public function label() { return 'Elementor'; }
	public function is_available() { return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ); }
	public function ability_names() {
		return array(
			'read' => array( 'elementor/status', 'elementor/get-document', 'elementor/list-widgets', 'elementor/get-dynamic-tags', 'elementor/validate-document' ),
			'content' => array( 'elementor/update-widget-settings', 'elementor/clone-subtree', 'elementor/move-element', 'elementor/delete-element', 'elementor/set-dynamic-tag' ),
			'admin' => array(),
		);
	}
	public function reversible_contracts() {
		return array(
			'elementor/update-widget-settings' => 'mad4b.rollback.elementor-widget-settings.v1',
			'elementor/clone-subtree' => 'mad4b.rollback.elementor-clone-subtree.v1',
			'elementor/move-element' => 'mad4b.rollback.elementor-move-element.v1',
			'elementor/delete-element' => 'mad4b.rollback.elementor-delete-element.v1',
			'elementor/set-dynamic-tag' => 'mad4b.rollback.elementor-dynamic-tag.v1',
		);
	}
	protected function detect_plugin_version() { return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : ''; }

	public function register_abilities() {
		$this->add_ability( 'elementor/status', 'Get Elementor Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$post_schema = $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) );
		$this->add_ability( 'elementor/get-document', 'Get Elementor Document', 'get_document', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability( 'elementor/list-widgets', 'List Elementor Widgets', 'list_widgets', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability( 'elementor/get-dynamic-tags', 'Get Elementor Dynamic Tags', 'get_dynamic_tags', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability( 'elementor/validate-document', 'Validate Elementor Document', 'validate_document', array( $this, 'can_read_post' ), $post_schema );
		$this->add_ability(
			'elementor/update-widget-settings',
			'Update Elementor Widget Settings',
			'update_widget_settings',
			array( $this, 'can_edit_post' ),
			$this->schema(
				array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'widget_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
					'settings' => array( 'type' => 'object' ),
					'remove_settings' => array(
						'type' => 'array',
						'maxItems' => 20,
						'uniqueItems' => true,
						'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
						'default' => array(),
					),
					'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				),
				array( 'post_id', 'widget_id', 'settings', 'expected_sha256' )
			),
			'content',
			false,
			true,
			true
		);

		$sha_schema = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$element_id_schema = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 );
		$parent_schema = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 );
		$index_schema = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5000 );

		$this->add_ability(
			'elementor/clone-subtree',
			'Clone Elementor Subtree',
			'clone_subtree',
			array( $this, 'can_clone_subtree' ),
			$this->schema(
				array(
					'source_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'source_element_id' => $element_id_schema,
					'target_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'target_parent_id' => $parent_schema,
					'target_index' => $index_schema,
					'expected_source_sha256' => $sha_schema,
					'expected_target_sha256' => $sha_schema,
				),
				array( 'source_post_id', 'source_element_id', 'target_post_id', 'target_parent_id', 'target_index', 'expected_source_sha256', 'expected_target_sha256' )
			),
			'content', false, true, true
		);

		$this->add_ability(
			'elementor/move-element',
			'Move Elementor Element',
			'move_element',
			array( $this, 'can_edit_post' ),
			$this->schema(
				array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'element_id' => $element_id_schema,
					'target_parent_id' => $parent_schema,
					'target_index' => $index_schema,
					'expected_sha256' => $sha_schema,
				),
				array( 'post_id', 'element_id', 'target_parent_id', 'target_index', 'expected_sha256' )
			),
			'content', false, true, true
		);

		$this->add_ability(
			'elementor/delete-element',
			'Delete Elementor Element',
			'delete_element',
			array( $this, 'can_edit_post' ),
			$this->schema(
				array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'element_id' => $element_id_schema,
					'expected_sha256' => $sha_schema,
				),
				array( 'post_id', 'element_id', 'expected_sha256' )
			),
			'content', false, true, true
		);

		$this->add_ability(
			'elementor/set-dynamic-tag',
			'Set Elementor Dynamic Tag From Exact Source',
			'set_dynamic_tag',
			array( $this, 'can_set_dynamic_tag' ),
			$this->schema(
				array(
					'source_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'source_element_id' => $element_id_schema,
					'source_setting' => $element_id_schema,
					'target_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'target_element_id' => $element_id_schema,
					'target_setting' => $element_id_schema,
					'expected_source_sha256' => $sha_schema,
					'expected_target_sha256' => $sha_schema,
				),
				array( 'source_post_id', 'source_element_id', 'source_setting', 'target_post_id', 'target_element_id', 'target_setting', 'expected_source_sha256', 'expected_target_sha256' )
			),
			'content', false, true, true
		);
	}

	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function can_clone_subtree( $input ) {
		$source = is_array( $input ) && isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;
		$target = is_array( $input ) && isset( $input['target_post_id'] ) ? absint( $input['target_post_id'] ) : 0;
		return $source > 0 && $target > 0 && current_user_can( 'read_post', $source ) && current_user_can( 'edit_post', $target );
	}
	public function can_set_dynamic_tag( $input ) { return $this->can_clone_subtree( $input ); }

	public function status() {
		$status = parent::status();
		$status['native_abilities'] = array(
			'manage_elements' => function_exists( 'wp_has_ability' ) && wp_has_ability( 'elementor/manage-elements' ),
			'get_page_structure' => function_exists( 'wp_has_ability' ) && wp_has_ability( 'elementor/get-page-structure' ),
			'list_widget_schemas' => function_exists( 'wp_has_ability' ) && wp_has_ability( 'elementor/list-widget-schemas' ),
		);
		$certified = isset( $status['provider_certification']['runtime_contract_ok'] ) && true === $status['provider_certification']['runtime_contract_ok'];
		$status['legacy_write_enabled'] = defined( 'MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED' ) && true === MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED;
		$status['mutation_mode'] = $certified ? 'certified_governed_bounded_fallback' : 'read_only_until_exact_provider_certified';
		$status['bounded_widget_settings_policy'] = array(
			'jet-smart-filters-sorting' => array( 'query_id' ),
			'jet-listing-grid' => array( 'custom_post_types', 'posts_query' ),
		);
		$status['legacy_generic_writer_used_by_governed_fallback'] = false;
		return $status;
	}

	public function get_document( $input ) {
		$document = $this->load_document( absint( $input['post_id'] ) ); if ( is_wp_error( $document ) ) return $document;
		return array( 'post_id' => absint( $input['post_id'] ), 'edit_mode' => get_post_meta( absint( $input['post_id'] ), '_elementor_edit_mode', true ), 'template_type' => get_post_meta( absint( $input['post_id'] ), '_elementor_template_type', true ), 'version' => get_post_meta( absint( $input['post_id'] ), '_elementor_version', true ), 'page_settings' => get_post_meta( absint( $input['post_id'] ), '_elementor_page_settings', true ), 'sha256' => $document['sha256'], 'elements' => $document['elements'] );
	}

	public function list_widgets( $input ) {
		$document = $this->load_document( absint( $input['post_id'] ) ); if ( is_wp_error( $document ) ) return $document; $widgets = array();
		$this->walk_elements( $document['elements'], function ( $element ) use ( &$widgets ) { if ( count( $widgets ) >= 500 ) return; if ( isset( $element['widgetType'] ) && '' !== (string) $element['widgetType'] ) $widgets[] = array( 'id' => isset( $element['id'] ) ? $element['id'] : '', 'widget_type' => $element['widgetType'], 'el_type' => isset( $element['elType'] ) ? $element['elType'] : '', 'settings_keys' => isset( $element['settings'] ) && is_array( $element['settings'] ) ? array_keys( $element['settings'] ) : array() ); } );
		return array( 'post_id' => absint( $input['post_id'] ), 'sha256' => $document['sha256'], 'widgets' => $widgets, 'count' => count( $widgets ) );
	}

	public function get_dynamic_tags( $input ) {
		$document = $this->load_document( absint( $input['post_id'] ) ); if ( is_wp_error( $document ) ) return $document; $tags = array();
		$this->walk_elements( $document['elements'], function ( $element ) use ( &$tags ) { if ( count( $tags ) >= 1000 || empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) return; foreach ( $element['settings'] as $key => $value ) if ( '__dynamic__' === $key && is_array( $value ) ) foreach ( $value as $setting => $tag ) { if ( count( $tags ) >= 1000 ) break; $tags[] = array( 'element_id' => isset( $element['id'] ) ? $element['id'] : '', 'setting' => $setting, 'tag' => $tag ); } } );
		return array( 'post_id' => absint( $input['post_id'] ), 'sha256' => $document['sha256'], 'dynamic_tags' => $tags, 'count' => count( $tags ) );
	}

	public function validate_document( $input ) {
		$document = $this->load_document( absint( $input['post_id'] ) ); if ( is_wp_error( $document ) ) return $document;
		$ids = array(); $errors = array(); $this->validate_elements( $document['elements'], $ids, $errors, 0 );
		return array( 'post_id' => absint( $input['post_id'] ), 'valid' => empty( $errors ), 'sha256' => $document['sha256'], 'element_count' => count( $ids ), 'errors' => array_slice( $errors, 0, 100 ) );
	}

	public function update_widget_settings( $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for governed document mutation.' );
		$id = absint( $input['post_id'] );
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		$expected = strtolower( trim( (string) $input['expected_sha256'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $document['sha256'], $expected ) ) return new WP_Error( 'mad4b_elementor_stale_document', 'Elementor document SHA-256 no longer matches.', array( 'current_sha256' => $document['sha256'] ) );

		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$remove = isset( $input['remove_settings'] ) && is_array( $input['remove_settings'] ) ? array_values( array_unique( array_map( 'strval', $input['remove_settings'] ) ) ) : array();
		$target = $this->widget_target( $document['elements'], (string) $input['widget_id'] );
		if ( is_wp_error( $target ) ) return $target;
		$policy = $this->validate_settings_policy( $target['widget_type'], $settings, $remove );
		if ( is_wp_error( $policy ) ) return $policy;

		$ids = array(); $errors = array(); $this->validate_elements( $document['elements'], $ids, $errors, 0 );
		if ( ! empty( $errors ) ) return new WP_Error( 'mad4b_elementor_document_invalid_before_write', 'Elementor document is structurally invalid before mutation.', array( 'errors' => array_slice( $errors, 0, 20 ) ) );

		$elements = $document['elements'];
		$mutated = $this->apply_widget_settings( $elements, (string) $input['widget_id'], $settings, $remove, 0 );
		if ( 1 !== $mutated ) return new WP_Error( 'mad4b_elementor_widget_cardinality_changed', 'Target widget must exist exactly once.' );
		$ids = array(); $errors = array(); $this->validate_elements( $elements, $ids, $errors, 0 );
		if ( ! empty( $errors ) ) return new WP_Error( 'mad4b_elementor_document_invalid_after_write', 'Bounded widget mutation would make the Elementor document structurally invalid.', array( 'errors' => array_slice( $errors, 0, 20 ) ) );
		$encoded = wp_json_encode( $elements );
		if ( false === $encoded || strlen( $encoded ) > 4194304 ) return new WP_Error( 'mad4b_elementor_encode_failed', 'Unable to encode a bounded Elementor document.' );
		$result = update_post_meta( $id, '_elementor_data', wp_slash( $encoded ) );
		if ( false === $result && (string) get_post_meta( $id, '_elementor_data', true ) !== $encoded ) return new WP_Error( 'mad4b_elementor_update_failed', 'Unable to update Elementor document.' );
		$this->clear_elementor_cache();
		$after = $this->load_document( $id );
		if ( is_wp_error( $after ) ) return $after;
		$read_target = $this->widget_target( $after['elements'], (string) $input['widget_id'] );
		if ( is_wp_error( $read_target ) ) return $read_target;
		foreach ( $settings as $key => $value ) {
			if ( ! array_key_exists( $key, $read_target['settings'] ) || $this->hash_value( $read_target['settings'][ $key ] ) !== $this->hash_value( $value ) ) return new WP_Error( 'mad4b_elementor_readback_mismatch', 'Elementor setting readback does not match requested state.' );
		}
		foreach ( $remove as $key ) if ( array_key_exists( $key, $read_target['settings'] ) ) return new WP_Error( 'mad4b_elementor_delete_readback_mismatch', 'Elementor setting removal did not persist.' );
		MAD4B_SCP_Audit::record( 'elementor/update-widget-settings', array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'before_sha256' => $document['sha256'], 'after_sha256' => $after['sha256'], 'set_keys' => array_keys( $settings ), 'removed_keys' => $remove, 'mode' => 'certified_governed_bounded_fallback' ) );
		return array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'updated' => true, 'sha256' => $after['sha256'], 'mode' => 'certified_governed_bounded_fallback', 'removed_settings' => $remove );
	}


	public function clone_subtree( $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for governed structural mutation.' );
		$source_id = absint( $input['source_post_id'] );
		$target_id = absint( $input['target_post_id'] );
		$source = $this->document_with_expected_sha( $source_id, $input['expected_source_sha256'] ); if ( is_wp_error( $source ) ) return $source;
		$target = $this->document_with_expected_sha( $target_id, $input['expected_target_sha256'] ); if ( is_wp_error( $target ) ) return $target;
		$source_element = $this->element_target( $source['elements'], (string) $input['source_element_id'] ); if ( is_wp_error( $source_element ) ) return $source_element;
		$bounded = $this->bounded_element_snapshot( $source_element['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
		$source_ids = $this->subtree_ids( $source_element['element'] );
		$target_ids = $this->document_ids( $target['elements'] );
		$collisions = array_values( array_intersect( $source_ids, $target_ids ) );
		if ( ! empty( $collisions ) ) return new WP_Error( 'mad4b_elementor_clone_id_collision', 'Source subtree element IDs already exist in the target document.', array( 'collision_ids' => array_slice( $collisions, 0, 20 ) ) );
		$parent_id = (string) $input['target_parent_id'];
		$index = (int) $input['target_index'];
		$insert_guard = $this->validate_insert_target( $target['elements'], $parent_id, $index ); if ( is_wp_error( $insert_guard ) ) return $insert_guard;
		$elements = $target['elements'];
		$inserted = $this->insert_element( $elements, $parent_id, $index, $source_element['element'] );
		if ( 1 !== $inserted ) return new WP_Error( 'mad4b_elementor_clone_parent_cardinality', 'Target parent must exist exactly once.' );
		$after = $this->persist_elements( $target_id, $elements ); if ( is_wp_error( $after ) ) return $after;
		$read = $this->element_target( $after['elements'], (string) $input['source_element_id'] ); if ( is_wp_error( $read ) ) return $read;
		if ( $parent_id !== $read['parent_id'] || $index !== (int) $read['index'] || $this->hash_value( $source_element['element'] ) !== $this->hash_value( $read['element'] ) ) return new WP_Error( 'mad4b_elementor_clone_readback_mismatch', 'Cloned subtree readback does not match the exact source and requested location.' );
		MAD4B_SCP_Audit::record( 'elementor/clone-subtree', array( 'source_post_id' => $source_id, 'target_post_id' => $target_id, 'source_element_id' => (string) $input['source_element_id'], 'target_parent_id' => $parent_id, 'target_index' => $index, 'before_sha256' => $target['sha256'], 'after_sha256' => $after['sha256'], 'subtree_sha256' => $this->hash_value( $source_element['element'] ), 'mode' => 'certified_governed_structural_fallback' ) );
		return array( 'source_post_id' => $source_id, 'target_post_id' => $target_id, 'element_id' => (string) $input['source_element_id'], 'target_parent_id' => $parent_id, 'target_index' => $index, 'updated' => true, 'sha256' => $after['sha256'], 'mode' => 'certified_governed_structural_fallback' );
	}

	public function move_element( $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for governed structural mutation.' );
		$id = absint( $input['post_id'] );
		$document = $this->document_with_expected_sha( $id, $input['expected_sha256'] ); if ( is_wp_error( $document ) ) return $document;
		$target = $this->element_target( $document['elements'], (string) $input['element_id'] ); if ( is_wp_error( $target ) ) return $target;
		$bounded = $this->bounded_element_snapshot( $target['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
		$parent_id = (string) $input['target_parent_id'];
		$index = (int) $input['target_index'];
		if ( in_array( $parent_id, $this->subtree_ids( $target['element'] ), true ) ) return new WP_Error( 'mad4b_elementor_move_cycle_denied', 'An element cannot be moved into itself or one of its descendants.' );
		$elements = $document['elements']; $removed = null; $old = array();
		$count = $this->remove_element( $elements, (string) $input['element_id'], self::ROOT_PARENT, 0, $removed, $old );
		if ( 1 !== $count || ! is_array( $removed ) ) return new WP_Error( 'mad4b_elementor_move_cardinality', 'Element to move must exist exactly once.' );
		$insert_guard = $this->validate_insert_target( $elements, $parent_id, $index ); if ( is_wp_error( $insert_guard ) ) return $insert_guard;
		if ( 1 !== $this->insert_element( $elements, $parent_id, $index, $removed ) ) return new WP_Error( 'mad4b_elementor_move_parent_cardinality', 'Target parent must exist exactly once after removal.' );
		$after = $this->persist_elements( $id, $elements ); if ( is_wp_error( $after ) ) return $after;
		$read = $this->element_target( $after['elements'], (string) $input['element_id'] ); if ( is_wp_error( $read ) ) return $read;
		if ( $parent_id !== $read['parent_id'] || $index !== (int) $read['index'] || $this->hash_value( $removed ) !== $this->hash_value( $read['element'] ) ) return new WP_Error( 'mad4b_elementor_move_readback_mismatch', 'Moved element readback does not match the requested final location.' );
		MAD4B_SCP_Audit::record( 'elementor/move-element', array( 'post_id' => $id, 'element_id' => (string) $input['element_id'], 'from_parent_id' => $old['parent_id'], 'from_index' => $old['index'], 'target_parent_id' => $parent_id, 'target_index' => $index, 'before_sha256' => $document['sha256'], 'after_sha256' => $after['sha256'], 'mode' => 'certified_governed_structural_fallback' ) );
		return array( 'post_id' => $id, 'element_id' => (string) $input['element_id'], 'from_parent_id' => $old['parent_id'], 'from_index' => $old['index'], 'target_parent_id' => $parent_id, 'target_index' => $index, 'updated' => true, 'sha256' => $after['sha256'], 'mode' => 'certified_governed_structural_fallback' );
	}

	public function delete_element( $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for governed structural mutation.' );
		$id = absint( $input['post_id'] );
		$document = $this->document_with_expected_sha( $id, $input['expected_sha256'] ); if ( is_wp_error( $document ) ) return $document;
		$target = $this->element_target( $document['elements'], (string) $input['element_id'] ); if ( is_wp_error( $target ) ) return $target;
		$bounded = $this->bounded_element_snapshot( $target['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
		$elements = $document['elements']; $removed = null; $old = array();
		$count = $this->remove_element( $elements, (string) $input['element_id'], self::ROOT_PARENT, 0, $removed, $old );
		if ( 1 !== $count ) return new WP_Error( 'mad4b_elementor_delete_cardinality', 'Element to delete must exist exactly once.' );
		$after = $this->persist_elements( $id, $elements ); if ( is_wp_error( $after ) ) return $after;
		$read = $this->element_target( $after['elements'], (string) $input['element_id'] );
		if ( ! is_wp_error( $read ) || 'mad4b_elementor_element_missing' !== $read->get_error_code() ) return new WP_Error( 'mad4b_elementor_delete_readback_mismatch', 'Deleted element is still present after mutation.' );
		MAD4B_SCP_Audit::record( 'elementor/delete-element', array( 'post_id' => $id, 'element_id' => (string) $input['element_id'], 'from_parent_id' => $old['parent_id'], 'from_index' => $old['index'], 'before_sha256' => $document['sha256'], 'after_sha256' => $after['sha256'], 'deleted_subtree_sha256' => $this->hash_value( $removed ), 'mode' => 'certified_governed_structural_fallback' ) );
		return array( 'post_id' => $id, 'element_id' => (string) $input['element_id'], 'deleted' => true, 'sha256' => $after['sha256'], 'mode' => 'certified_governed_structural_fallback' );
	}

	public function set_dynamic_tag( $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for governed dynamic-tag mutation.' );
		$source_id = absint( $input['source_post_id'] ); $target_id = absint( $input['target_post_id'] );
		$source = $this->document_with_expected_sha( $source_id, $input['expected_source_sha256'] ); if ( is_wp_error( $source ) ) return $source;
		$target_doc = $this->document_with_expected_sha( $target_id, $input['expected_target_sha256'] ); if ( is_wp_error( $target_doc ) ) return $target_doc;
		$source_element = $this->element_target( $source['elements'], (string) $input['source_element_id'] ); if ( is_wp_error( $source_element ) ) return $source_element;
		$target_element = $this->element_target( $target_doc['elements'], (string) $input['target_element_id'] ); if ( is_wp_error( $target_element ) ) return $target_element;
		$bounded = $this->bounded_element_snapshot( $target_element['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
		$tag = $this->dynamic_tag_from_source( $source_element['element'], (string) $input['source_setting'] ); if ( is_wp_error( $tag ) ) return $tag;
		$tag_name = $this->allowed_dynamic_tag_name( $tag );
		if ( '' === $tag_name ) return new WP_Error( 'mad4b_elementor_dynamic_tag_not_allowlisted', 'Source dynamic tag is not in the bounded ETG allowlist.' );
		$setting = (string) $input['target_setting'];
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $setting ) ) return new WP_Error( 'mad4b_elementor_dynamic_setting_invalid', 'Target dynamic setting name is invalid.' );
		$elements = $target_doc['elements'];
		if ( 1 !== $this->apply_dynamic_tag( $elements, (string) $input['target_element_id'], $setting, $tag, 0 ) ) return new WP_Error( 'mad4b_elementor_dynamic_target_cardinality', 'Target dynamic-tag element must exist exactly once.' );
		$after = $this->persist_elements( $target_id, $elements ); if ( is_wp_error( $after ) ) return $after;
		$read = $this->element_target( $after['elements'], (string) $input['target_element_id'] ); if ( is_wp_error( $read ) ) return $read;
		$read_tag = $this->dynamic_tag_from_source( $read['element'], $setting );
		if ( is_wp_error( $read_tag ) || ! hash_equals( $tag, (string) $read_tag ) ) return new WP_Error( 'mad4b_elementor_dynamic_tag_readback_mismatch', 'Dynamic tag readback does not match the exact allowlisted source binding.' );
		MAD4B_SCP_Audit::record( 'elementor/set-dynamic-tag', array( 'source_post_id' => $source_id, 'source_element_id' => (string) $input['source_element_id'], 'source_setting' => (string) $input['source_setting'], 'target_post_id' => $target_id, 'target_element_id' => (string) $input['target_element_id'], 'target_setting' => $setting, 'dynamic_tag_name' => $tag_name, 'before_sha256' => $target_doc['sha256'], 'after_sha256' => $after['sha256'], 'mode' => 'certified_governed_dynamic_tag_fallback' ) );
		return array( 'source_post_id' => $source_id, 'target_post_id' => $target_id, 'target_element_id' => (string) $input['target_element_id'], 'target_setting' => $setting, 'dynamic_tag_name' => $tag_name, 'updated' => true, 'sha256' => $after['sha256'], 'mode' => 'certified_governed_dynamic_tag_fallback' );
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( in_array( $ability_name, array( 'elementor/clone-subtree', 'elementor/move-element', 'elementor/delete-element', 'elementor/set-dynamic-tag' ), true ) ) return $this->capture_structural_state( $ability_name, $input );
		if ( 'elementor/update-widget-settings' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for reversible mutation.' );
		$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $document['sha256'], $expected ) ) return new WP_Error( 'mad4b_elementor_stale_document', 'Current Elementor document SHA-256 is required.', array( 'current_sha256' => $document['sha256'] ) );
		$target = $this->widget_target( $document['elements'], isset( $input['widget_id'] ) ? (string) $input['widget_id'] : '' );
		if ( is_wp_error( $target ) ) return $target;
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$remove = isset( $input['remove_settings'] ) && is_array( $input['remove_settings'] ) ? $input['remove_settings'] : array();
		$policy = $this->validate_settings_policy( $target['widget_type'], $settings, $remove );
		if ( is_wp_error( $policy ) ) return $policy;
		return array(
			'target_type' => 'elementor-widget-settings',
			'target_id' => $id . ':' . (string) $input['widget_id'],
			'target' => array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'widget_type' => $target['widget_type'] ),
			'state' => array( 'document_sha256' => $document['sha256'], 'widget_type' => $target['widget_type'], 'settings' => $target['settings'] ),
		);
	}

	public function read_reversible_state( $ability_name, array $target ) {
		if ( in_array( $ability_name, array( 'elementor/clone-subtree', 'elementor/move-element', 'elementor/delete-element', 'elementor/set-dynamic-tag' ), true ) ) {
			$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
			$element_id = isset( $target['element_id'] ) ? (string) $target['element_id'] : '';
			return $this->structural_observation( $post_id, $element_id );
		}
		if ( 'elementor/update-widget-settings' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		$widget_id = isset( $target['widget_id'] ) ? (string) $target['widget_id'] : '';
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		$widget = $this->widget_target( $document['elements'], $widget_id ); if ( is_wp_error( $widget ) ) return $widget;
		return array( 'document_sha256' => $document['sha256'], 'widget_type' => $widget['widget_type'], 'settings' => $widget['settings'] );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( in_array( $ability_name, array( 'elementor/clone-subtree', 'elementor/move-element', 'elementor/delete-element', 'elementor/set-dynamic-tag' ), true ) ) return $this->restore_structural_state( $ability_name, $target, $state );
		if ( 'elementor/update-widget-settings' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for restore.' );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		$widget_id = isset( $target['widget_id'] ) ? (string) $target['widget_id'] : '';
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! isset( $state['settings'] ) || ! is_array( $state['settings'] ) ) return new WP_Error( 'mad4b_elementor_restore_payload_invalid', 'Elementor rollback target/state is invalid.' );
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		$widget = $this->widget_target( $document['elements'], $widget_id ); if ( is_wp_error( $widget ) ) return $widget;
		if ( isset( $target['widget_type'] ) && (string) $target['widget_type'] !== $widget['widget_type'] ) return new WP_Error( 'mad4b_elementor_restore_widget_type_drift', 'Widget type changed after mutation.' );
		$elements = $document['elements'];
		$replaced = $this->replace_widget_settings( $elements, $widget_id, $state['settings'], 0 );
		if ( 1 !== $replaced ) return new WP_Error( 'mad4b_elementor_restore_cardinality', 'Target widget must exist exactly once during restore.' );
		$ids = array(); $errors = array(); $this->validate_elements( $elements, $ids, $errors, 0 );
		if ( ! empty( $errors ) ) return new WP_Error( 'mad4b_elementor_restore_invalid_document', 'Rollback would create an invalid Elementor document.' );
		$encoded = wp_json_encode( $elements ); if ( false === $encoded || strlen( $encoded ) > 4194304 ) return new WP_Error( 'mad4b_elementor_restore_encode_failed', 'Unable to encode Elementor rollback document.' );
		$result = update_post_meta( $id, '_elementor_data', wp_slash( $encoded ) );
		if ( false === $result && (string) get_post_meta( $id, '_elementor_data', true ) !== $encoded ) return new WP_Error( 'mad4b_elementor_restore_failed', 'Unable to restore Elementor widget settings.' );
		$this->clear_elementor_cache();
		return true;
	}


	private function capture_structural_state( $ability_name, array $input ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for reversible structural mutation.' );
		$post_id = 0; $element_id = ''; $operation = '';
		if ( 'elementor/clone-subtree' === $ability_name ) {
			$source = $this->document_with_expected_sha( absint( $input['source_post_id'] ), $input['expected_source_sha256'] ); if ( is_wp_error( $source ) ) return $source;
			$source_element = $this->element_target( $source['elements'], (string) $input['source_element_id'] ); if ( is_wp_error( $source_element ) ) return $source_element;
			$bounded = $this->bounded_element_snapshot( $source_element['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
			$target = $this->document_with_expected_sha( absint( $input['target_post_id'] ), $input['expected_target_sha256'] ); if ( is_wp_error( $target ) ) return $target;
			$collisions = array_values( array_intersect( $this->subtree_ids( $source_element['element'] ), $this->document_ids( $target['elements'] ) ) );
			if ( ! empty( $collisions ) ) return new WP_Error( 'mad4b_elementor_clone_id_collision', 'Source subtree element IDs already exist in the target document.' );
			$guard = $this->validate_insert_target( $target['elements'], (string) $input['target_parent_id'], (int) $input['target_index'] ); if ( is_wp_error( $guard ) ) return $guard;
			$post_id = absint( $input['target_post_id'] ); $element_id = (string) $input['source_element_id']; $operation = 'clone-subtree';
		} elseif ( 'elementor/set-dynamic-tag' === $ability_name ) {
			$source = $this->document_with_expected_sha( absint( $input['source_post_id'] ), $input['expected_source_sha256'] ); if ( is_wp_error( $source ) ) return $source;
			$source_element = $this->element_target( $source['elements'], (string) $input['source_element_id'] ); if ( is_wp_error( $source_element ) ) return $source_element;
			$tag = $this->dynamic_tag_from_source( $source_element['element'], (string) $input['source_setting'] ); if ( is_wp_error( $tag ) ) return $tag;
			if ( '' === $this->allowed_dynamic_tag_name( $tag ) ) return new WP_Error( 'mad4b_elementor_dynamic_tag_not_allowlisted', 'Source dynamic tag is not in the bounded ETG allowlist.' );
			$target = $this->document_with_expected_sha( absint( $input['target_post_id'] ), $input['expected_target_sha256'] ); if ( is_wp_error( $target ) ) return $target;
			$target_element = $this->element_target( $target['elements'], (string) $input['target_element_id'] ); if ( is_wp_error( $target_element ) ) return $target_element;
			$bounded = $this->bounded_element_snapshot( $target_element['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
			$post_id = absint( $input['target_post_id'] ); $element_id = (string) $input['target_element_id']; $operation = 'set-dynamic-tag';
		} else {
			$post_id = absint( $input['post_id'] ); $element_id = (string) $input['element_id']; $operation = 'elementor/move-element' === $ability_name ? 'move-element' : 'delete-element';
			$document = $this->document_with_expected_sha( $post_id, $input['expected_sha256'] ); if ( is_wp_error( $document ) ) return $document;
			$element = $this->element_target( $document['elements'], $element_id ); if ( is_wp_error( $element ) ) return $element;
			$bounded = $this->bounded_element_snapshot( $element['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
			if ( 'move-element' === $operation ) {
				if ( in_array( (string) $input['target_parent_id'], $this->subtree_ids( $element['element'] ), true ) ) return new WP_Error( 'mad4b_elementor_move_cycle_denied', 'An element cannot be moved into itself or one of its descendants.' );
				$copy = $document['elements']; $removed = null; $location = array();
				if ( 1 !== $this->remove_element( $copy, $element_id, self::ROOT_PARENT, 0, $removed, $location ) ) return new WP_Error( 'mad4b_elementor_move_cardinality', 'Element to move must exist exactly once.' );
				$guard = $this->validate_insert_target( $copy, (string) $input['target_parent_id'], (int) $input['target_index'] ); if ( is_wp_error( $guard ) ) return $guard;
			}
		}
		$state = $this->structural_observation( $post_id, $element_id ); if ( is_wp_error( $state ) ) return $state;
		return array( 'target_type' => 'elementor-structural-element', 'target_id' => $post_id . ':' . $element_id, 'target' => array( 'post_id' => $post_id, 'element_id' => $element_id, 'operation' => $operation ), 'state' => $state );
	}

	private function restore_structural_state( $ability_name, array $target, array $state ) {
		if ( ! $this->exact_provider_certified() ) return new WP_Error( 'mad4b_elementor_provider_not_certified', 'The exact installed Elementor package is not certified for structural restore.' );
		$post_id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		$element_id = isset( $target['element_id'] ) ? (string) $target['element_id'] : '';
		$operation = isset( $target['operation'] ) ? (string) $target['operation'] : '';
		if ( $post_id < 1 || '' === $element_id || ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'mad4b_elementor_restore_payload_invalid', 'Elementor structural rollback target is invalid.' );
		$document = $this->load_document( $post_id ); if ( is_wp_error( $document ) ) return $document;
		$elements = $document['elements'];
		if ( 'clone-subtree' === $operation ) {
			$removed = null; $location = array();
			if ( 1 !== $this->remove_element( $elements, $element_id, self::ROOT_PARENT, 0, $removed, $location ) ) return new WP_Error( 'mad4b_elementor_restore_clone_cardinality', 'Cloned subtree must exist exactly once during restore.' );
		} elseif ( 'delete-element' === $operation ) {
			if ( empty( $state['present'] ) || ! isset( $state['element'] ) || ! is_array( $state['element'] ) ) return new WP_Error( 'mad4b_elementor_restore_deleted_state_missing', 'Deleted subtree rollback state is incomplete.' );
			$existing = $this->element_target( $elements, $element_id );
			if ( ! is_wp_error( $existing ) || 'mad4b_elementor_element_missing' !== $existing->get_error_code() ) return new WP_Error( 'mad4b_elementor_restore_delete_target_present', 'Deleted element ID is no longer absent.' );
			$collisions = array_values( array_intersect( $this->subtree_ids( $state['element'] ), $this->document_ids( $elements ) ) );
			if ( ! empty( $collisions ) ) return new WP_Error( 'mad4b_elementor_restore_id_collision', 'Rollback subtree IDs collide with newer document state.' );
			$guard = $this->validate_insert_target( $elements, (string) $state['parent_id'], (int) $state['index'] ); if ( is_wp_error( $guard ) ) return $guard;
			if ( 1 !== $this->insert_element( $elements, (string) $state['parent_id'], (int) $state['index'], $state['element'] ) ) return new WP_Error( 'mad4b_elementor_restore_parent_cardinality', 'Rollback parent must exist exactly once.' );
		} elseif ( 'move-element' === $operation ) {
			if ( empty( $state['present'] ) || ! isset( $state['element'] ) || ! is_array( $state['element'] ) ) return new WP_Error( 'mad4b_elementor_restore_move_state_missing', 'Move rollback state is incomplete.' );
			$removed = null; $location = array();
			if ( 1 !== $this->remove_element( $elements, $element_id, self::ROOT_PARENT, 0, $removed, $location ) ) return new WP_Error( 'mad4b_elementor_restore_move_cardinality', 'Moved element must exist exactly once during restore.' );
			$guard = $this->validate_insert_target( $elements, (string) $state['parent_id'], (int) $state['index'] ); if ( is_wp_error( $guard ) ) return $guard;
			if ( 1 !== $this->insert_element( $elements, (string) $state['parent_id'], (int) $state['index'], $state['element'] ) ) return new WP_Error( 'mad4b_elementor_restore_parent_cardinality', 'Rollback parent must exist exactly once.' );
		} elseif ( 'set-dynamic-tag' === $operation ) {
			if ( empty( $state['present'] ) || ! isset( $state['element'] ) || ! is_array( $state['element'] ) ) return new WP_Error( 'mad4b_elementor_restore_dynamic_state_missing', 'Dynamic-tag rollback state is incomplete.' );
			if ( 1 !== $this->replace_element( $elements, $element_id, $state['element'], 0 ) ) return new WP_Error( 'mad4b_elementor_restore_dynamic_cardinality', 'Dynamic-tag target must exist exactly once during restore.' );
		} else {
			return new WP_Error( 'mad4b_elementor_restore_operation_invalid', 'Unknown Elementor structural rollback operation.' );
		}
		$after = $this->persist_elements( $post_id, $elements );
		return is_wp_error( $after ) ? $after : true;
	}

	private function document_with_expected_sha( $post_id, $expected ) {
		$document = $this->load_document( absint( $post_id ) ); if ( is_wp_error( $document ) ) return $document;
		$expected = strtolower( trim( (string) $expected ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $document['sha256'], $expected ) ) return new WP_Error( 'mad4b_elementor_stale_document', 'Elementor document SHA-256 no longer matches.', array( 'current_sha256' => $document['sha256'] ) );
		$ids = array(); $errors = array(); $this->validate_elements( $document['elements'], $ids, $errors, 0 );
		if ( ! empty( $errors ) ) return new WP_Error( 'mad4b_elementor_document_invalid_before_write', 'Elementor document is structurally invalid before mutation.', array( 'errors' => array_slice( $errors, 0, 20 ) ) );
		return $document;
	}

	private function persist_elements( $post_id, array $elements ) {
		$ids = array(); $errors = array(); $this->validate_elements( $elements, $ids, $errors, 0 );
		if ( ! empty( $errors ) ) return new WP_Error( 'mad4b_elementor_document_invalid_after_write', 'Bounded structural mutation would make the Elementor document invalid.', array( 'errors' => array_slice( $errors, 0, 20 ) ) );
		$encoded = wp_json_encode( $elements );
		if ( false === $encoded || strlen( $encoded ) > 4194304 ) return new WP_Error( 'mad4b_elementor_encode_failed', 'Unable to encode a bounded Elementor document.' );
		$result = update_post_meta( absint( $post_id ), '_elementor_data', wp_slash( $encoded ) );
		if ( false === $result && (string) get_post_meta( absint( $post_id ), '_elementor_data', true ) !== $encoded ) return new WP_Error( 'mad4b_elementor_update_failed', 'Unable to update Elementor document.' );
		$this->clear_elementor_cache();
		return $this->load_document( absint( $post_id ) );
	}

	private function structural_observation( $post_id, $element_id ) {
		$document = $this->load_document( absint( $post_id ) ); if ( is_wp_error( $document ) ) return $document;
		$canonical = wp_json_encode( $document['elements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $canonical ) return new WP_Error( 'mad4b_elementor_structural_hash_failed', 'Unable to hash Elementor structural state.' );
		$observation = array( 'document_structural_sha256' => hash( 'sha256', $canonical ), 'present' => false, 'parent_id' => '', 'index' => -1, 'element_sha256' => '', 'element' => null );
		$target = $this->element_target( $document['elements'], (string) $element_id );
		if ( is_wp_error( $target ) ) {
			if ( 'mad4b_elementor_element_missing' === $target->get_error_code() ) return $observation;
			return $target;
		}
		$bounded = $this->bounded_element_snapshot( $target['element'] ); if ( is_wp_error( $bounded ) ) return $bounded;
		$observation['present'] = true;
		$observation['parent_id'] = $target['parent_id'];
		$observation['index'] = (int) $target['index'];
		$observation['element_sha256'] = $this->hash_value( $target['element'] );
		$observation['element'] = $target['element'];
		return $observation;
	}

	private function bounded_element_snapshot( array $element ) {
		$json = wp_json_encode( $element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > self::MAX_STRUCTURAL_ROLLBACK_BYTES ) return new WP_Error( 'mad4b_elementor_structural_snapshot_too_large', 'Elementor subtree exceeds the bounded rollback payload limit.' );
		return true;
	}

	private function element_target( array $elements, $element_id ) {
		if ( '' === (string) $element_id || strlen( (string) $element_id ) > 100 ) return new WP_Error( 'mad4b_elementor_element_id_invalid', 'Element ID is invalid.' );
		$matches = array();
		$this->collect_element_targets( $elements, (string) $element_id, self::ROOT_PARENT, 0, $matches );
		if ( 1 !== count( $matches ) ) return new WP_Error( 0 === count( $matches ) ? 'mad4b_elementor_element_missing' : 'mad4b_elementor_element_not_unique', 'Target element must exist exactly once within the bounded element tree.' );
		return $matches[0];
	}

	private function collect_element_targets( array $elements, $element_id, $parent_id, $depth, array &$matches ) {
		if ( $depth > 50 ) return;
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) continue;
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( $id === $element_id ) $matches[] = array( 'element' => $element, 'parent_id' => (string) $parent_id, 'index' => (int) $index );
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $this->collect_element_targets( $element['elements'], $element_id, $id, $depth + 1, $matches );
		}
	}

	private function subtree_ids( array $element ) {
		$ids = array();
		$this->walk_elements( array( $element ), function ( $item ) use ( &$ids ) { if ( isset( $item['id'] ) && '' !== (string) $item['id'] ) $ids[] = (string) $item['id']; } );
		return array_values( array_unique( $ids ) );
	}

	private function document_ids( array $elements ) {
		$ids = array();
		$this->walk_elements( $elements, function ( $item ) use ( &$ids ) { if ( isset( $item['id'] ) && '' !== (string) $item['id'] ) $ids[] = (string) $item['id']; } );
		return array_values( array_unique( $ids ) );
	}

	private function validate_insert_target( array $elements, $parent_id, $index ) {
		$index = (int) $index;
		if ( $index < 0 ) return new WP_Error( 'mad4b_elementor_target_index_invalid', 'Target index is invalid.' );
		if ( self::ROOT_PARENT === (string) $parent_id ) return $index <= count( $elements ) ? true : new WP_Error( 'mad4b_elementor_target_index_out_of_range', 'Target root index exceeds current element count.' );
		$parent = $this->element_target( $elements, (string) $parent_id ); if ( is_wp_error( $parent ) ) return new WP_Error( 'mad4b_elementor_target_parent_invalid', 'Target parent does not exist exactly once.' );
		if ( ! array_key_exists( 'elements', $parent['element'] ) || ! is_array( $parent['element']['elements'] ) ) return new WP_Error( 'mad4b_elementor_target_parent_not_container', 'Target parent does not expose a child elements array.' );
		return $index <= count( $parent['element']['elements'] ) ? true : new WP_Error( 'mad4b_elementor_target_index_out_of_range', 'Target index exceeds current child count.' );
	}

	private function insert_element( array &$elements, $parent_id, $index, array $element ) {
		if ( self::ROOT_PARENT === (string) $parent_id ) { array_splice( $elements, (int) $index, 0, array( $element ) ); return 1; }
		return $this->insert_into_parent( $elements, (string) $parent_id, (int) $index, $element, 0 );
	}

	private function insert_into_parent( array &$elements, $parent_id, $index, array $insert, $depth ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) continue;
			if ( isset( $element['id'] ) && (string) $element['id'] === $parent_id ) {
				++$matches;
				if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) array_splice( $element['elements'], $index, 0, array( $insert ) );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $matches += $this->insert_into_parent( $element['elements'], $parent_id, $index, $insert, $depth + 1 );
		}
		unset( $element );
		return $matches;
	}

	private function remove_element( array &$elements, $element_id, $parent_id, $depth, &$removed, array &$location ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		for ( $i = 0; $i < count( $elements ); ++$i ) {
			if ( ! is_array( $elements[ $i ] ) ) continue;
			$id = isset( $elements[ $i ]['id'] ) ? (string) $elements[ $i ]['id'] : '';
			if ( $id === $element_id ) {
				++$matches; $removed = $elements[ $i ]; $location = array( 'parent_id' => (string) $parent_id, 'index' => $i );
				array_splice( $elements, $i, 1 ); --$i; continue;
			}
			if ( isset( $elements[ $i ]['elements'] ) && is_array( $elements[ $i ]['elements'] ) ) $matches += $this->remove_element( $elements[ $i ]['elements'], $element_id, $id, $depth + 1, $removed, $location );
		}
		return $matches;
	}

	private function replace_element( array &$elements, $element_id, array $replacement, $depth ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) continue;
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) { ++$matches; $element = $replacement; continue; }
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $matches += $this->replace_element( $element['elements'], $element_id, $replacement, $depth + 1 );
		}
		unset( $element );
		return $matches;
	}

	private function dynamic_tag_from_source( array $element, $setting ) {
		$setting = (string) $setting;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $setting ) ) return new WP_Error( 'mad4b_elementor_dynamic_setting_invalid', 'Dynamic setting name is invalid.' );
		$dynamic = isset( $element['settings']['__dynamic__'] ) && is_array( $element['settings']['__dynamic__'] ) ? $element['settings']['__dynamic__'] : array();
		if ( ! isset( $dynamic[ $setting ] ) || ! is_string( $dynamic[ $setting ] ) || '' === trim( $dynamic[ $setting ] ) ) return new WP_Error( 'mad4b_elementor_dynamic_source_missing', 'Exact source element has no dynamic tag for the requested setting.' );
		if ( strlen( $dynamic[ $setting ] ) > 4096 ) return new WP_Error( 'mad4b_elementor_dynamic_tag_too_large', 'Dynamic tag binding exceeds the bounded adapter limit.' );
		return (string) $dynamic[ $setting ];
	}

	private function allowed_dynamic_tag_name( $tag ) {
		$allowed = array( 'etg-filter-title', 'etg-filter-image', 'etg-filter-intro', 'etg-dynamic-content-slot', 'etg-filter-gallery' );
		foreach ( $allowed as $name ) {
			if ( preg_match( '/(^|[^A-Za-z0-9_-])' . preg_quote( $name, '/' ) . '($|[^A-Za-z0-9_-])/i', (string) $tag ) ) return $name;
		}
		return '';
	}

	private function apply_dynamic_tag( array &$elements, $element_id, $setting, $tag, $depth ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) continue;
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				++$matches;
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) $element['settings'] = array();
				if ( ! isset( $element['settings']['__dynamic__'] ) || ! is_array( $element['settings']['__dynamic__'] ) ) $element['settings']['__dynamic__'] = array();
				$element['settings']['__dynamic__'][ $setting ] = $tag;
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $matches += $this->apply_dynamic_tag( $element['elements'], $element_id, $setting, $tag, $depth + 1 );
		}
		unset( $element );
		return $matches;
	}

	private function exact_provider_certified() {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return false;
		$guard = MAD4B_SCP_Provider_Contracts::mutation_guard( 'elementor', (bool) $this->is_available() );
		return true === $guard;
	}

	private function allowed_settings_for_widget( $widget_type ) {
		$policy = array(
			'jet-smart-filters-sorting' => array( 'query_id' ),
			'jet-listing-grid' => array( 'custom_post_types', 'posts_query' ),
		);
		return isset( $policy[ $widget_type ] ) ? $policy[ $widget_type ] : array();
	}

	private function validate_settings_policy( $widget_type, array $settings, array $remove ) {
		$allowed = $this->allowed_settings_for_widget( (string) $widget_type );
		if ( empty( $allowed ) ) return new WP_Error( 'mad4b_elementor_widget_policy_denied', 'This widget type is not allowlisted for bounded governed fallback mutation.' );
		$remove = array_values( array_unique( array_map( 'strval', $remove ) ) );
		foreach ( array_keys( $settings ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) return new WP_Error( 'mad4b_elementor_setting_policy_denied', 'Requested Elementor setting key is not allowlisted for this widget type.' );
			if ( in_array( $key, $remove, true ) ) return new WP_Error( 'mad4b_elementor_setting_conflict', 'The same setting cannot be set and removed in one mutation.' );
			if ( ! $this->safe_setting_value( $settings[ $key ] ) ) return new WP_Error( 'mad4b_elementor_setting_value_denied', 'Elementor setting value exceeds the bounded scalar/list policy.' );
		}
		foreach ( $remove as $key ) if ( ! in_array( $key, $allowed, true ) ) return new WP_Error( 'mad4b_elementor_remove_setting_policy_denied', 'Requested Elementor setting removal is not allowlisted for this widget type.' );
		$json = wp_json_encode( array( 'settings' => $settings, 'remove_settings' => $remove ) );
		if ( false === $json || strlen( $json ) > 65536 ) return new WP_Error( 'mad4b_elementor_settings_payload_too_large', 'Bounded Elementor settings payload exceeds 64 KiB.' );
		return true;
	}

	private function safe_setting_value( $value, $depth = 0 ) {
		if ( $depth > 4 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > 100 ) return false;
			foreach ( $value as $item ) if ( ! $this->safe_setting_value( $item, $depth + 1 ) ) return false;
			return true;
		}
		return is_null( $value ) || is_scalar( $value );
	}

	private function widget_target( array $elements, $widget_id ) {
		if ( '' === $widget_id || strlen( $widget_id ) > 100 ) return new WP_Error( 'mad4b_elementor_widget_id_invalid', 'Widget ID is invalid.' );
		$matches = array();
		$this->walk_elements( $elements, function ( $element ) use ( &$matches, $widget_id ) {
			if ( isset( $element['id'] ) && (string) $element['id'] === $widget_id ) {
				$matches[] = array(
					'widget_type' => isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '',
					'settings' => isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(),
				);
			}
		} );
		if ( 1 !== count( $matches ) ) return new WP_Error( 0 === count( $matches ) ? 'mad4b_elementor_widget_missing' : 'mad4b_elementor_widget_not_unique', 'Target widget must exist exactly once within the bounded element tree.' );
		if ( '' === $matches[0]['widget_type'] ) return new WP_Error( 'mad4b_elementor_widget_type_missing', 'Target element is not a typed Elementor widget.' );
		return $matches[0];
	}

	private function apply_widget_settings( array &$elements, $widget_id, array $settings, array $remove, $depth ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) continue;
			if ( isset( $element['id'] ) && (string) $element['id'] === $widget_id ) {
				++$matches;
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) $element['settings'] = array();
				foreach ( $settings as $key => $value ) $element['settings'][ $key ] = $value;
				foreach ( $remove as $key ) unset( $element['settings'][ $key ] );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $matches += $this->apply_widget_settings( $element['elements'], $widget_id, $settings, $remove, $depth + 1 );
		}
		unset( $element );
		return $matches;
	}

	private function replace_widget_settings( array &$elements, $widget_id, array $settings, $depth ) {
		if ( $depth > 50 ) return 0;
		$matches = 0;
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) continue;
			if ( isset( $element['id'] ) && (string) $element['id'] === $widget_id ) { ++$matches; $element['settings'] = $settings; }
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $matches += $this->replace_widget_settings( $element['elements'], $widget_id, $settings, $depth + 1 );
		}
		unset( $element );
		return $matches;
	}

	private function clear_elementor_cache() {
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	private function load_document( $post_id ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		if ( ! get_post( $post_id ) ) return new WP_Error( 'mad4b_elementor_post_missing', 'Post not found.' );
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === $raw ) return new WP_Error( 'mad4b_elementor_data_missing', 'Elementor data is not present for this post.' );
		if ( strlen( $raw ) > 4194304 ) return new WP_Error( 'mad4b_elementor_document_too_large', 'Elementor document exceeds the 4 MiB adapter limit.' );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) return new WP_Error( 'mad4b_elementor_invalid_json', 'Elementor document JSON is invalid.' );
		return array( 'raw' => $raw, 'sha256' => hash( 'sha256', $raw ), 'elements' => $elements );
	}

	private function walk_elements( array $elements, $callback, $depth = 0 ) {
		if ( $depth > 50 ) return;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) continue;
			call_user_func( $callback, $element );
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $this->walk_elements( $element['elements'], $callback, $depth + 1 );
		}
	}

	private function validate_elements( array $elements, array &$ids, array &$errors, $depth ) {
		if ( $depth > 50 ) { $errors[] = 'Element tree exceeds maximum depth.'; return; }
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) { $errors[] = 'Element at index ' . $index . ' is not an object.'; continue; }
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' === $id ) $errors[] = 'Element is missing an id.';
			elseif ( isset( $ids[ $id ] ) ) $errors[] = 'Duplicate element id: ' . $id;
			else $ids[ $id ] = true;
			if ( count( $ids ) > 5000 ) { $errors[] = 'Element tree exceeds maximum element count.'; return; }
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $this->validate_elements( $element['elements'], $ids, $errors, $depth + 1 );
		}
	}
}
