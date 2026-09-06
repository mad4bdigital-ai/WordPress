<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Elementor_Adapter extends MAD4B_SCP_Adapter_Base {

	public function id() { return 'elementor'; }
	public function label() { return 'Elementor'; }
	public function is_available() { return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ); }
	public function ability_names() {
		return array(
			'read'    => array( 'elementor/status', 'elementor/get-document', 'elementor/list-widgets', 'elementor/get-dynamic-tags', 'elementor/validate-document' ),
			'content' => array( 'elementor/update-widget-settings' ),
			'admin'   => array(),
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
		$this->add_ability( 'elementor/update-widget-settings', 'Update Elementor Widget Settings', 'update_widget_settings', array( $this, 'can_edit_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'widget_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ), 'settings' => array( 'type' => 'object' ), 'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 )
		), array( 'post_id', 'widget_id', 'settings', 'expected_sha256' ) ), 'content', false, true, true );
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
		$status['legacy_write_enabled'] = defined( 'MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED' ) && true === MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED;
		$status['mutation_mode'] = ! empty( $status['native_abilities']['manage_elements'] ) ? 'elementor_native_ability' : ( $status['legacy_write_enabled'] ? 'legacy_explicit_opt_in' : 'read_only_until_native_or_explicit_legacy_opt_in' );
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
	public function validate_document( $input ) { $document = $this->load_document( absint( $input['post_id'] ) ); if ( is_wp_error( $document ) ) return $document; $ids = array(); $errors = array(); $this->validate_elements( $document['elements'], $ids, $errors, 0 ); return array( 'post_id' => absint( $input['post_id'] ), 'valid' => empty( $errors ), 'sha256' => $document['sha256'], 'element_count' => count( $ids ), 'errors' => array_slice( $errors, 0, 100 ) ); }
	public function update_widget_settings( $input ) {
		$id = absint( $input['post_id'] );
		$document = $this->load_document( $id ); if ( is_wp_error( $document ) ) return $document;
		if ( ! hash_equals( $document['sha256'], strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_elementor_stale_document', 'Elementor document SHA-256 no longer matches.', array( 'current_sha256' => $document['sha256'] ) );

		$native = function_exists( 'wp_has_ability' ) && wp_has_ability( 'elementor/manage-elements' ) ? wp_get_ability( 'elementor/manage-elements' ) : null;
		if ( $native && method_exists( $native, 'execute' ) ) {
			$result = $native->execute( array(
				'post_id' => $id,
				'operations' => array( array( 'action' => 'update', 'element_id' => (string) $input['widget_id'], 'settings' => $input['settings'] ) ),
			) );
			if ( is_wp_error( $result ) ) return $result;
			$new_raw = get_post_meta( $id, '_elementor_data', true );
			$new_hash = hash( 'sha256', (string) $new_raw );
			MAD4B_SCP_Audit::record( 'elementor/update-widget-settings', array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'sha256' => $new_hash, 'settings' => array_keys( $input['settings'] ), 'mode' => 'native' ) );
			return array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'updated' => true, 'sha256' => $new_hash, 'mode' => 'elementor_native_ability', 'native_result' => $result );
		}

		$legacy_enabled = defined( 'MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED' ) && true === MAD4B_MCP_ELEMENTOR_LEGACY_WRITE_ENABLED;
		$legacy_allowed = $legacy_enabled && current_user_can( 'manage_options' ) && (bool) apply_filters( 'mad4b_scp_allow_elementor_legacy_write', false, $id, $input, get_current_user_id() );
		if ( ! $legacy_allowed ) {
			return new WP_Error( 'mad4b_elementor_legacy_write_denied', 'This Elementor version has no certified native element mutation Ability. Legacy direct document mutation requires an explicit constant, administrator capability, and site policy allowlist.' );
		}
		$elements = $document['elements'];
		if ( ! $this->merge_widget_settings( $elements, (string) $input['widget_id'], $input['settings'], 0 ) ) return new WP_Error( 'mad4b_elementor_widget_missing', 'Widget ID was not found within the bounded element tree.' );
		$encoded = wp_json_encode( $elements ); if ( false === $encoded ) return new WP_Error( 'mad4b_elementor_encode_failed', 'Unable to encode Elementor document.' );
		$result = update_post_meta( $id, '_elementor_data', wp_slash( $encoded ) ); if ( false === $result && (string) get_post_meta( $id, '_elementor_data', true ) !== $encoded ) return new WP_Error( 'mad4b_elementor_update_failed', 'Unable to update Elementor document.' );
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) \Elementor\Plugin::$instance->files_manager->clear_cache();
		$new_raw = get_post_meta( $id, '_elementor_data', true ); $new_hash = hash( 'sha256', (string) $new_raw );
		MAD4B_SCP_Audit::record( 'elementor/update-widget-settings', array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'sha256' => $new_hash, 'settings' => array_keys( $input['settings'] ), 'mode' => 'legacy_explicit_opt_in' ) );
		return array( 'post_id' => $id, 'widget_id' => (string) $input['widget_id'], 'updated' => true, 'sha256' => $new_hash, 'mode' => 'legacy_explicit_opt_in' );
	}
	private function load_document( $post_id ) {
		if ( ! $this->is_available() ) return $this->unavailable_error(); if ( ! get_post( $post_id ) ) return new WP_Error( 'mad4b_elementor_post_missing', 'Post not found.' );
		$raw = get_post_meta( $post_id, '_elementor_data', true ); if ( ! is_string( $raw ) || '' === $raw ) return new WP_Error( 'mad4b_elementor_data_missing', 'Elementor data is not present for this post.' ); if ( strlen( $raw ) > 4194304 ) return new WP_Error( 'mad4b_elementor_document_too_large', 'Elementor document exceeds the 4 MiB adapter limit.' );
		$elements = json_decode( $raw, true ); if ( ! is_array( $elements ) ) return new WP_Error( 'mad4b_elementor_invalid_json', 'Elementor document JSON is invalid.' ); return array( 'raw' => $raw, 'sha256' => hash( 'sha256', $raw ), 'elements' => $elements );
	}
	private function walk_elements( array $elements, $callback, $depth = 0 ) { if ( $depth > 50 ) return; foreach ( $elements as $element ) { if ( ! is_array( $element ) ) continue; call_user_func( $callback, $element ); if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $this->walk_elements( $element['elements'], $callback, $depth + 1 ); } }
	private function validate_elements( array $elements, array &$ids, array &$errors, $depth ) { if ( $depth > 50 ) { $errors[] = 'Element tree exceeds maximum depth.'; return; } foreach ( $elements as $index => $element ) { if ( ! is_array( $element ) ) { $errors[] = 'Element at index ' . $index . ' is not an object.'; continue; } $id = isset( $element['id'] ) ? (string) $element['id'] : ''; if ( '' === $id ) $errors[] = 'Element is missing an id.'; elseif ( isset( $ids[ $id ] ) ) $errors[] = 'Duplicate element id: ' . $id; else $ids[ $id ] = true; if ( count( $ids ) > 5000 ) { $errors[] = 'Element tree exceeds maximum element count.'; return; } if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) $this->validate_elements( $element['elements'], $ids, $errors, $depth + 1 ); } }
	private function merge_widget_settings( array &$elements, $widget_id, array $settings, $depth ) { if ( $depth > 50 ) return false; foreach ( $elements as &$element ) { if ( ! is_array( $element ) ) continue; if ( isset( $element['id'] ) && (string) $element['id'] === $widget_id ) { if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) $element['settings'] = array(); foreach ( $settings as $key => $value ) $element['settings'][ $key ] = $value; return true; } if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && $this->merge_widget_settings( $element['elements'], $widget_id, $settings, $depth + 1 ) ) return true; } unset( $element ); return false; }
}
