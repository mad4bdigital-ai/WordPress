<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_JetEngine_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'jetengine'; }
	public function label() { return 'JetEngine'; }
	public function is_available() { return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ); }
	public function ability_names() { return array( 'read' => array( 'jetengine/status', 'jetengine/get-post-meta', 'jetengine/list-post-meta', 'jetengine/get-cpt-definition' ), 'content' => array( 'jetengine/update-post-meta' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'jetengine/update-post-meta' => 'mad4b.rollback.jetengine-post-meta.v1' ); }
	protected function detect_plugin_version() { return defined( 'JET_ENGINE_VERSION' ) ? JET_ENGINE_VERSION : ''; }
	public function register_abilities() {
		$this->add_ability( 'jetengine/status', 'Get JetEngine Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$this->add_ability( 'jetengine/get-post-meta', 'Get JetEngine Post Meta', 'get_post_meta_value', array( $this, 'can_read_post' ), $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'field' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ) ), array( 'post_id', 'field' ) ) );
		$this->add_ability( 'jetengine/list-post-meta', 'List JetEngine Post Meta', 'list_post_meta', array( $this, 'can_read_post' ), $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'prefix' => array( 'type' => 'string', 'maxLength' => 100, 'default' => '' ) ), array( 'post_id' ) ) );
		$this->add_ability( 'jetengine/get-cpt-definition', 'Get JetEngine CPT Definition', 'get_cpt_definition', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema( array( 'post_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ) ), array( 'post_type' ) ) );
		$this->add_ability( 'jetengine/update-post-meta', 'Update JetEngine Post Meta', 'update_post_meta_value', array( $this, 'can_edit_post' ), $this->schema( array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'field' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ), 'value' => $this->json_value_schema(), 'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ), 'allow_create' => array( 'type' => 'boolean', 'default' => false )
		), array( 'post_id', 'field', 'value' ) ), 'content', false, true, true );
	}
	public function can_read_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function status() {
		$status = parent::status();
		$status['contracts'] = array( 'jet_engine_function' => function_exists( 'jet_engine' ), 'jet_engine_class' => class_exists( 'Jet_Engine' ) );
		$status['write_mode'] = 'explicit_field_policy_plus_sha_lock_plus_reversible_envelope';
		$status['unknown_field_write_default'] = 'deny';
		$status['sensitive_meta_default'] = 'deny_read_write';
		$status['meta_create_mode'] = 'admin_plus_explicit_create_policy_plus_field_policy';
		return $status;
	}
	private function exact_field_name( $value ) {
		$field = (string) $value;
		if ( '' === $field || strlen( $field ) > 191 || $field !== sanitize_key( $field ) ) return new WP_Error( 'mad4b_jetengine_invalid_field', 'Meta field must already be a canonical WordPress meta key; silent key canonicalization is not allowed.' );
		return $field;
	}
	private function is_sensitive_meta_key( $field ) {
		$sensitive = MAD4B_SCP_Policy::is_sensitive_database_column( (string) $field );
		return (bool) apply_filters( 'mad4b_scp_sensitive_meta_key', $sensitive, 'jetengine', (string) $field );
	}
	private function can_read_sensitive_meta( $field, $post_id ) { return (bool) apply_filters( 'mad4b_scp_allow_sensitive_meta_read', false, 'jetengine', (string) $field, absint( $post_id ), get_current_user_id() ); }
	private function can_write_sensitive_meta( $field, $post_id, $value ) { return (bool) apply_filters( 'mad4b_scp_allow_sensitive_meta_write', false, 'jetengine', (string) $field, absint( $post_id ), $value, get_current_user_id() ); }
	public function get_post_meta_value( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$field = $this->exact_field_name( $input['field'] ); if ( is_wp_error( $field ) ) return $field;
		$id = absint( $input['post_id'] );
		if ( $this->is_sensitive_meta_key( $field ) && ! $this->can_read_sensitive_meta( $field, $id ) ) return new WP_Error( 'mad4b_jetengine_sensitive_meta_read', 'Secret/authentication-like meta is denied by default and requires an explicit site policy override.' );
		if ( 0 === strpos( $field, '_' ) && ! current_user_can( 'manage_options' ) && ! apply_filters( 'mad4b_scp_allow_protected_meta_read', false, 'jetengine', $field, $id ) ) return new WP_Error( 'mad4b_jetengine_protected_meta_read', 'Protected meta reads require administrator permission by default.' );
		$value = get_post_meta( $id, $field, true );
		return array( 'post_id' => $id, 'field' => $field, 'value' => $value, 'sha256' => $this->hash_value( $value ), 'exists' => metadata_exists( 'post', $id, $field ) );
	}
	public function list_post_meta( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = absint( $input['post_id'] ); $all = get_post_meta( $id ); $prefix = isset( $input['prefix'] ) ? (string) $input['prefix'] : ''; $items = array(); $omitted_sensitive = 0;
		foreach ( $all as $key => $values ) {
			if ( 0 === strpos( $key, '_' ) ) continue;
			if ( '' !== $prefix && 0 !== strpos( $key, $prefix ) ) continue;
			if ( $this->is_sensitive_meta_key( $key ) && ! $this->can_read_sensitive_meta( $key, $id ) ) { ++$omitted_sensitive; continue; }
			$value = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			$items[] = array( 'field' => $key, 'value' => $value, 'sha256' => $this->hash_value( $value ) );
			if ( count( $items ) >= 300 ) break;
		}
		return array( 'post_id' => $id, 'meta' => $items, 'count' => count( $items ), 'omitted_sensitive' => $omitted_sensitive );
	}
	public function get_cpt_definition( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$post_type = sanitize_key( $input['post_type'] ); $object = get_post_type_object( $post_type );
		if ( ! $object ) return new WP_Error( 'mad4b_jetengine_cpt_missing', 'Post type is not registered.' );
		return array( 'post_type' => $post_type, 'label' => $object->label, 'public' => (bool) $object->public, 'hierarchical' => (bool) $object->hierarchical, 'show_in_rest' => (bool) $object->show_in_rest, 'taxonomies' => get_object_taxonomies( $post_type ), 'supports' => get_all_post_type_supports( $post_type ) );
	}
	public function update_post_meta_value( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = absint( $input['post_id'] ); $field = $this->validate_write_target( $id, $input );
		if ( is_wp_error( $field ) ) return $field;
		$exists = metadata_exists( 'post', $id, $field ); $current = get_post_meta( $id, $field, true ); $hash = $this->hash_value( $current );
		$result = update_post_meta( $id, $field, $input['value'] );
		if ( false === $result && $current !== $input['value'] ) return new WP_Error( 'mad4b_jetengine_update_failed', 'Unable to update meta.' );
		$new = get_post_meta( $id, $field, true ); $new_hash = $this->hash_value( $new );
		MAD4B_SCP_Audit::record( 'jetengine/update-post-meta', array( 'post_id' => $id, 'field' => $field, 'before_sha256' => $hash, 'after_sha256' => $new_hash, 'created' => ! $exists ) );
		return array( 'post_id' => $id, 'field' => $field, 'updated' => true, 'value' => $new, 'sha256' => $new_hash );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'jetengine/update-post-meta' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; $field = $this->validate_write_target( $id, $input );
		if ( is_wp_error( $field ) ) return $field;
		$exists = metadata_exists( 'post', $id, $field ); $current = get_post_meta( $id, $field, true );
		return array( 'target_type' => 'jetengine-post-meta', 'target_id' => $id . ':' . $field, 'target' => array( 'post_id' => $id, 'field' => $field ), 'state' => array( 'exists' => $exists, 'value' => $current ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'jetengine/update-post-meta' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0; $field = isset( $target['field'] ) ? $this->exact_field_name( $target['field'] ) : new WP_Error( 'mad4b_jetengine_invalid_field', 'Missing field.' );
		if ( is_wp_error( $field ) ) return $field;
		if ( ! $id || ! get_post( $id ) ) return new WP_Error( 'mad4b_post_missing', 'Post not found for JetEngine readback.' );
		if ( $this->is_sensitive_meta_key( $field ) ) return new WP_Error( 'mad4b_jetengine_sensitive_meta_read', 'Sensitive meta cannot participate in normal reversible readback.' );
		return array( 'exists' => metadata_exists( 'post', $id, $field ), 'value' => get_post_meta( $id, $field, true ) );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'jetengine/update-post-meta' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		$id = isset( $target['post_id'] ) ? absint( $target['post_id'] ) : 0; $field = isset( $target['field'] ) ? $this->exact_field_name( $target['field'] ) : new WP_Error( 'mad4b_jetengine_invalid_field', 'Missing field.' );
		if ( is_wp_error( $field ) ) return $field;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_jetengine_restore_denied', 'Current user cannot restore this post meta.' );
		if ( $this->is_sensitive_meta_key( $field ) || 0 === strpos( $field, '_' ) ) return new WP_Error( 'mad4b_jetengine_restore_field_denied', 'Sensitive/protected meta is not restorable through the normal JetEngine adapter contract.' );
		if ( ! array_key_exists( 'exists', $state ) || ! array_key_exists( 'value', $state ) ) return new WP_Error( 'mad4b_jetengine_restore_payload_invalid', 'JetEngine rollback state is incomplete.' );
		if ( $state['exists'] ) update_post_meta( $id, $field, $state['value'] ); else delete_post_meta( $id, $field );
		return true;
	}
	private function validate_write_target( $id, array $input ) {
		$field = isset( $input['field'] ) ? $this->exact_field_name( $input['field'] ) : new WP_Error( 'mad4b_jetengine_invalid_field', 'Meta field is required.' );
		if ( is_wp_error( $field ) ) return $field;
		if ( $this->is_sensitive_meta_key( $field ) && ! $this->can_write_sensitive_meta( $field, $id, isset( $input['value'] ) ? $input['value'] : null ) ) return new WP_Error( 'mad4b_jetengine_sensitive_meta_write', 'Secret/authentication-like meta mutation is denied by default and requires an explicit site policy override.' );
		if ( 0 === strpos( $field, '_' ) && ! apply_filters( 'mad4b_scp_allow_protected_meta_write', false, 'jetengine', $field, $id ) ) return new WP_Error( 'mad4b_jetengine_protected_meta', 'Protected meta writes are denied by default.' );
		$exists = metadata_exists( 'post', $id, $field ); $current = get_post_meta( $id, $field, true ); $hash = $this->hash_value( $current );
		if ( $exists ) {
			$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
			if ( '' === $expected || ! hash_equals( $hash, $expected ) ) return new WP_Error( 'mad4b_jetengine_stale_meta', 'Current meta SHA-256 is required.', array( 'current_sha256' => $hash ) );
		} else {
			if ( empty( $input['allow_create'] ) ) return new WP_Error( 'mad4b_jetengine_create_denied', 'This ability does not create undeclared meta unless explicitly requested.' );
			if ( ! current_user_can( 'manage_options' ) || ! apply_filters( 'mad4b_scp_allow_jetengine_meta_create', false, $field, $id, isset( $input['value'] ) ? $input['value'] : null, get_current_user_id() ) ) return new WP_Error( 'mad4b_jetengine_create_policy_denied', 'Creating a new JetEngine/meta field requires administrator permission and an explicit site policy filter.' );
		}
		if ( ! (bool) apply_filters( 'mad4b_scp_jetengine_field_write_allowed', false, $field, $id, $exists, isset( $input['value'] ) ? $input['value'] : null, get_current_user_id() ) ) return new WP_Error( 'mad4b_jetengine_field_policy_denied', 'JetEngine field mutation is denied unless the exact field is explicitly allowlisted by site policy.' );
		return $field;
	}
}
