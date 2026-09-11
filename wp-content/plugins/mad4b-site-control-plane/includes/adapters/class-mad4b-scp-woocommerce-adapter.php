<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_WooCommerce_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'woocommerce'; }
	public function label() { return 'WooCommerce'; }
	public function is_available() { return function_exists( 'wc_get_product' ); }
	public function ability_names() { return array( 'read' => array( 'woocommerce/status', 'woocommerce/get-product' ), 'content' => array( 'woocommerce/update-product' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'woocommerce/update-product' => 'mad4b.rollback.woocommerce-product.v1' ); }
	protected function detect_plugin_version() { return defined( 'WC_VERSION' ) ? WC_VERSION : ''; }
	public function register_abilities() {
		$this->add_ability( 'woocommerce/status', 'Get WooCommerce Status', 'status', array( 'MAD4B_SCP_Policy', 'can_read' ) );
		$schema = $this->schema( array( 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'product_id' ) );
		$this->add_ability( 'woocommerce/get-product', 'Get WooCommerce Product', 'get_product', array( $this, 'can_read_product' ), $schema );
		$this->add_ability( 'woocommerce/update-product', 'Update WooCommerce Product', 'update_product', array( $this, 'can_edit_product' ), $this->schema( array(
			'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'fields' => array( 'type' => 'object' ),
			'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
		), array( 'product_id', 'fields', 'expected_sha256' ) ), 'content', false, false, true );
	}
	public function status() { $status = parent::status(); $status['mutation_scope'] = 'products_only_no_orders_payments_refunds'; $status['external_priority'] = true; return $status; }
	public function can_read_product( $input ) { $id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_product( $input ) { $id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }
	public function get_product( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$product = wc_get_product( absint( $input['product_id'] ) );
		if ( ! $product ) return new WP_Error( 'mad4b_wc_product_missing', 'Product not found.' );
		$data = $this->product_data( $product );
		return array( 'product' => $data, 'sha256' => $this->hash_value( $data ) );
	}
	public function update_product( $input ) {
		if ( ! $this->is_available() ) return $this->unavailable_error();
		$product = wc_get_product( absint( $input['product_id'] ) );
		if ( ! $product ) return new WP_Error( 'mad4b_wc_product_missing', 'Product not found.' );
		$current = $this->product_data( $product );
		$current_hash = $this->hash_value( $current );
		if ( ! hash_equals( $current_hash, strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_wc_stale_product', 'Product changed since it was read.', array( 'current_sha256' => $current_hash ) );
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) return new WP_Error( 'mad4b_wc_empty_fields', 'fields must be a non-empty object.' );

		$allowed = array( 'name', 'description', 'short_description', 'status', 'sku', 'regular_price', 'sale_price', 'stock_status', 'featured' );
		$sanitized = array();
		foreach ( $input['fields'] as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) ) return new WP_Error( 'mad4b_wc_field_denied', 'Unsupported product field: ' . sanitize_key( $field ) );
			$normalized = $this->sanitize_product_field( $product, $field, $value );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$sanitized[ $field ] = $normalized;
		}
		try {
			foreach ( $sanitized as $field => $value ) {
				$method = 'set_' . $field;
				if ( ! is_callable( array( $product, $method ) ) ) return new WP_Error( 'mad4b_wc_setter_missing', 'WooCommerce setter is unavailable for: ' . sanitize_key( $field ) );
				$product->$method( $value );
			}
			$saved = $product->save();
		} catch ( Throwable $e ) {
			MAD4B_SCP_Audit::record( 'woocommerce/update-product', array( 'product_id' => $product->get_id(), 'error_type' => get_class( $e ) ), 'failure' );
			return new WP_Error( 'mad4b_wc_update_failed', 'WooCommerce rejected the bounded product update.' );
		}
		$after = wc_get_product( $product->get_id() );
		$after_data = $after ? $this->product_data( $after ) : array();
		$after_hash = $this->hash_value( $after_data );
		MAD4B_SCP_Audit::record( 'woocommerce/update-product', array( 'product_id' => $product->get_id(), 'fields' => implode( ',', array_keys( $sanitized ) ), 'before_sha256' => $current_hash, 'after_sha256' => $after_hash ) );
		return array( 'product_id' => $saved, 'updated' => true, 'product' => $after_data, 'sha256' => $after_hash );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'woocommerce/update-product' !== $ability_name ) return parent::capture_reversible_state( $ability_name, $input );
		$id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$current = $this->get_product( array( 'product_id' => $id ) );
		if ( is_wp_error( $current ) ) return $current;
		$expected = isset( $input['expected_sha256'] ) ? strtolower( trim( (string) $input['expected_sha256'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $current['sha256'], $expected ) ) return new WP_Error( 'mad4b_wc_stale_product', 'Product changed since it was read.', array( 'current_sha256' => $current['sha256'] ) );
		return array( 'target_type' => 'woocommerce-product', 'target_id' => (string) $id, 'target' => array( 'product_id' => $id ), 'state' => $this->restore_product_state( $current['product'] ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		if ( 'woocommerce/update-product' !== $ability_name ) return parent::read_reversible_state( $ability_name, $target );
		$id = isset( $target['product_id'] ) ? absint( $target['product_id'] ) : 0;
		$current = $this->get_product( array( 'product_id' => $id ) );
		return is_wp_error( $current ) ? $current : $this->restore_product_state( $current['product'] );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'woocommerce/update-product' !== $ability_name ) return parent::restore_reversible_state( $ability_name, $target, $state, $record );
		$id = isset( $target['product_id'] ) ? absint( $target['product_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return new WP_Error( 'mad4b_wc_restore_denied', 'Current user cannot restore this product.' );
		$product = $this->is_available() ? wc_get_product( $id ) : null;
		if ( ! $product ) return new WP_Error( 'mad4b_wc_product_missing', 'Product not found for restore.' );
		$fields = array( 'name', 'description', 'short_description', 'status', 'sku', 'regular_price', 'sale_price', 'stock_status', 'featured' );
		foreach ( $fields as $field ) if ( ! array_key_exists( $field, $state ) ) return new WP_Error( 'mad4b_wc_restore_payload_invalid', 'WooCommerce rollback state is incomplete.' );
		try {
			foreach ( $fields as $field ) {
				$method = 'set_' . $field;
				if ( ! is_callable( array( $product, $method ) ) ) return new WP_Error( 'mad4b_wc_setter_missing', 'WooCommerce restore setter is unavailable for: ' . $field );
				$product->$method( $state[ $field ] );
			}
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'mad4b_wc_restore_failed', 'WooCommerce rejected the certified rollback state.' );
		}
		return true;
	}
	private function sanitize_product_field( $product, $field, $value ) {
		if ( 'status' === $field ) {
			$value = sanitize_key( (string) $value );
			if ( ! in_array( $value, array( 'draft', 'pending', 'private', 'publish' ), true ) ) return new WP_Error( 'mad4b_wc_status_denied', 'Unsupported product status.' );
			if ( 'publish' === $value ) {
				$type = get_post_type_object( 'product' );
				$cap = $type && isset( $type->cap->publish_posts ) ? $type->cap->publish_posts : 'publish_products';
				if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_wc_cannot_publish', 'Current user cannot publish products.' );
			}
			return $value;
		}
		if ( 'stock_status' === $field ) {
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $value : new WP_Error( 'mad4b_wc_stock_status_denied', 'Unsupported stock status.' );
		}
		if ( 'featured' === $field ) return (bool) $value;
		if ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : sanitize_text_field( (string) $value );
		if ( in_array( $field, array( 'description', 'short_description' ), true ) ) return wp_kses_post( (string) $value );
		return sanitize_text_field( (string) $value );
	}
	private function product_data( $product ) {
		$post = get_post( $product->get_id() );
		return array(
			'id' => $product->get_id(), 'type' => $product->get_type(), 'name' => $product->get_name(),
			'description' => $product->get_description(), 'short_description' => $product->get_short_description(),
			'status' => $product->get_status(), 'sku' => $product->get_sku(), 'regular_price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(), 'stock_status' => $product->get_stock_status(), 'featured' => $product->get_featured(),
			'modified_gmt' => $post ? $post->post_modified_gmt : '', 'permalink' => $product->get_permalink(),
		);
	}
	private function restore_product_state( array $data ) {
		$fields = array( 'name', 'description', 'short_description', 'status', 'sku', 'regular_price', 'sale_price', 'stock_status', 'featured' );
		$state = array(); foreach ( $fields as $field ) $state[ $field ] = isset( $data[ $field ] ) ? $data[ $field ] : ( 'featured' === $field ? false : '' ); return $state;
	}
}
