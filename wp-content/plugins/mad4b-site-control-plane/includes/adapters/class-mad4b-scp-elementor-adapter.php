<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Elementor_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'elementor'; }
	public function label() { return 'Elementor'; }
	public function is_available() { return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ); }
	public function ability_names() {
		return array(
			'read' => array( 'elementor/status', 'elementor/get-document', 'elementor/list-widgets', 'elementor/get-dynamic-tags', 'elementor/validate-document' ),
			'content' => array( 'elementor/update-widget-settings' ),
			'admin' => array(),
		);
	}
	public function reversible_contracts() { return array( 'elementor/update-widget-settings' => 'mad4b.rollback.elementor-widget-settings.v1' ); }
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
	}

	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }

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

	public function capture_reversible_state( $ability_name, array $input ) {
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
		if ( 'elementor/update-widget-settings' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0;
		$widget_id = isset( $target['widget_id'] ) ? (string) $target['widget_id'] : '';
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		$widget = $this->widget_target( $document['elements'], $widget_id ); if ( is_wp_error( $widget ) ) return $widget;
		return array( 'document_sha256' => $document['sha256'], 'widget_type' => $widget['widget_type'], 'settings' => $widget['settings'] );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
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
