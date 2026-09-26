<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_JetSmartFilters_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'jetsmartfilters'; }
	public function label() { return 'JetSmartFilters'; }
	public function is_available() { return class_exists( 'Jet_Smart_Filters' ) || function_exists( 'jet_smart_filters' ) || null !== $this->filter_post_type(); }
	public function ability_names() { return array( 'read' => array( 'jetsmartfilters/status', 'jetsmartfilters/list-filters', 'jetsmartfilters/get-filter', 'jetsmartfilters/get-query-binding' ), 'content' => array(), 'admin' => array( 'jetsmartfilters/update-filter-meta' ) ); }
	public function reversible_contracts() { return array( 'jetsmartfilters/update-filter-meta' => 'mad4b.rollback.jetsmartfilters-filter-meta.v1' ); }
	protected function detect_plugin_version() { return defined( 'JET_SMART_FILTERS_VERSION' ) ? JET_SMART_FILTERS_VERSION : ''; }
	public function register_abilities() {
		$this->add_ability( 'jetsmartfilters/status', 'Get JetSmartFilters Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$this->add_ability( 'jetsmartfilters/list-filters', 'List JetSmartFilters Filters', 'list_filters', array( 'MAD4B_SCP_Policy', 'can_read' ), $this->schema( array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ) ) ) );
		$filter_schema = $this->schema( array( 'filter_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'filter_id' ) );
		$this->add_ability( 'jetsmartfilters/get-filter', 'Get JetSmartFilters Filter', 'get_filter', array( $this, 'can_read_filter' ), $filter_schema );
		$this->add_ability( 'jetsmartfilters/get-query-binding', 'Get JetSmartFilters Query Binding', 'get_query_binding', array( $this, 'can_read_filter' ), $filter_schema );
		$this->add_ability( 'jetsmartfilters/update-filter-meta', 'Update JetSmartFilters Filter Meta', 'update_filter_meta', array( 'MAD4B_SCP_Policy', 'can_admin' ), $this->schema( array(
			'filter_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'meta_key' => array( 'type' => 'string', 'enum' => array( '_query_var' ) ),
			'value' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9_-]+$' ),
			'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[a-fA-F0-9]{64}$' ),
		), array( 'filter_id', 'meta_key', 'value', 'expected_sha256' ) ), 'admin', false, false, true );
	}
	public function status() {
		$status = parent::status();
		$status['filter_post_type'] = $this->filter_post_type();
		$status['bounded_write_policy'] = array( 'existing_only' => true, 'allowed_meta_keys' => array( '_query_var' ), 'scalar_only' => true, 'requires_exact_sha256' => true, 'reversible' => true );
		return $status;
	}
	public function can_read_filter( $input ) { $id = isset( $input['filter_id'] ) ? absint( $input['filter_id'] ) : 0; return $id > 0 && current_user_can( 'manage_options' ) && current_user_can( 'read_post', $id ); }
	public function list_filters( $input ) { $post_type = $this->filter_post_type(); if ( ! $post_type ) return $this->unavailable_error(); $limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50; $posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'DESC' ) ); $items = array(); foreach ( $posts as $post ) $items[] = array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt ); return array( 'post_type' => $post_type, 'filters' => $items, 'count' => count( $items ) ); }
	public function get_filter( $input ) { $post = $this->load_filter_post( absint( $input['filter_id'] ) ); if ( is_wp_error( $post ) ) return $post; $meta = get_post_meta( $post->ID ); $items = array(); foreach ( $meta as $key => $values ) { if ( '_edit_lock' === $key || '_edit_last' === $key || 0 === strpos( $key, '_wp_' ) ) continue; $value = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values ); $items[ $key ] = array( 'value' => $value, 'sha256' => $this->hash_value( $value ) ); } return array( 'filter' => array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status, 'meta' => $items ) ); }
	public function get_query_binding( $input ) { $data = $this->get_filter( $input ); if ( is_wp_error( $data ) ) return $data; $bindings = array(); foreach ( $data['filter']['meta'] as $key => $item ) { $haystack = strtolower( $key . ' ' . wp_json_encode( $item['value'] ) ); if ( false !== strpos( $haystack, 'query' ) || false !== strpos( $haystack, 'provider' ) || false !== strpos( $haystack, 'listing' ) ) $bindings[ $key ] = $item; } return array( 'filter_id' => absint( $input['filter_id'] ), 'bindings' => $bindings, 'count' => count( $bindings ) ); }
	public function update_filter_meta( $input ) {
		$target = $this->validated_write_target( $input ); if ( is_wp_error( $target ) ) return $target;
		$id = $target['filter_id']; $key = $target['meta_key']; $current = $target['current'];
		$result = update_post_meta( $id, $key, $target['value'] );
		if ( false === $result && $current !== $target['value'] ) return new WP_Error( 'mad4b_jetsmartfilters_update_failed', 'Unable to update filter meta.' );
		$new = get_post_meta( $id, $key, true );
		if ( $new !== $target['value'] ) return new WP_Error( 'mad4b_jetsmartfilters_readback_mismatch', 'Filter meta read-after-write did not match the requested bounded value.' );
		MAD4B_SCP_Audit::record( 'jetsmartfilters/update-filter-meta', array( 'filter_id' => $id, 'meta_key' => $key, 'before_sha256' => $target['current_sha256'], 'after_sha256' => $this->hash_value( $new ) ) );
		return array( 'filter_id' => $id, 'meta_key' => $key, 'updated' => true, 'sha256' => $this->hash_value( $new ) );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'jetsmartfilters/update-filter-meta' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		$target = $this->validated_write_target( $input ); if ( is_wp_error( $target ) ) return $target;
		return array( 'target_type' => 'jetsmartfilters-filter-meta', 'target_id' => $target['filter_id'] . ':' . $target['meta_key'], 'target' => array( 'filter_id' => $target['filter_id'], 'meta_key' => $target['meta_key'] ), 'state' => array( 'exists' => true, 'value' => $target['current'] ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'jetsmartfilters/update-filter-meta' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$id = isset( $target['filter_id'] ) ? absint( $target['filter_id'] ) : 0; $post = $this->load_filter_post( $id ); if ( is_wp_error( $post ) ) return $post;
		$key = isset( $target['meta_key'] ) ? (string) $target['meta_key'] : '';
		if ( '_query_var' !== $key || ! metadata_exists( 'post', $id, $key ) ) return new WP_Error( 'mad4b_jetsmartfilters_reversible_target_denied', 'Reversible readback is limited to existing _query_var filter meta.' );
		return array( 'exists' => true, 'value' => get_post_meta( $id, $key, true ) );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'jetsmartfilters/update-filter-meta' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		$id = isset( $target['filter_id'] ) ? absint( $target['filter_id'] ) : 0; $post = $this->load_filter_post( $id ); if ( is_wp_error( $post ) ) return $post;
		$key = isset( $target['meta_key'] ) ? (string) $target['meta_key'] : '';
		if ( '_query_var' !== $key || empty( $state['exists'] ) || ! array_key_exists( 'value', $state ) || ! $this->valid_query_var( $state['value'] ) ) return new WP_Error( 'mad4b_jetsmartfilters_restore_denied', 'Rollback is limited to a previously existing bounded _query_var value.' );
		$result = update_post_meta( $id, $key, (string) $state['value'] );
		if ( false === $result && (string) get_post_meta( $id, $key, true ) !== (string) $state['value'] ) return new WP_Error( 'mad4b_jetsmartfilters_restore_failed', 'Unable to restore filter query variable.' );
		return true;
	}
	private function validated_write_target( array $input ) {
		$id = isset( $input['filter_id'] ) ? absint( $input['filter_id'] ) : 0; $post = $this->load_filter_post( $id ); if ( is_wp_error( $post ) ) return $post;
		$key = isset( $input['meta_key'] ) ? (string) $input['meta_key'] : '';
		if ( '_query_var' !== $key ) return new WP_Error( 'mad4b_jetsmartfilters_meta_denied', 'The governed filter-meta writer is allowlisted to _query_var only.' );
		if ( ! metadata_exists( 'post', $id, $key ) ) return new WP_Error( 'mad4b_jetsmartfilters_meta_missing', 'Meta key does not already exist. This ability does not create arbitrary filter internals.' );
		$value = isset( $input['value'] ) ? $input['value'] : null;
		if ( ! $this->valid_query_var( $value ) ) return new WP_Error( 'mad4b_jetsmartfilters_query_var_invalid', '_query_var must be a bounded canonical query variable string.' );
		$current = get_post_meta( $id, $key, true ); $hash = $this->hash_value( $current ); $expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $hash, $expected ) ) return new WP_Error( 'mad4b_jetsmartfilters_stale_meta', 'Filter meta SHA-256 no longer matches.', array( 'current_sha256' => $hash ) );
		return array( 'filter_id' => $id, 'meta_key' => $key, 'value' => (string) $value, 'current' => $current, 'current_sha256' => $hash );
	}
	private function valid_query_var( $value ) { return is_string( $value ) && '' !== $value && strlen( $value ) <= 191 && (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $value ); }
	private function load_filter_post( $id ) { $post_type = $this->filter_post_type(); $post = get_post( absint( $id ) ); if ( ! $post_type || ! $post || $post->post_type !== $post_type ) return new WP_Error( 'mad4b_jetsmartfilters_filter_missing', 'Filter not found.' ); return $post; }
	private function filter_post_type() { foreach ( array( 'jet-smart-filters', 'jet-smart-filter' ) as $post_type ) if ( post_type_exists( $post_type ) ) return $post_type; return null; }
}
