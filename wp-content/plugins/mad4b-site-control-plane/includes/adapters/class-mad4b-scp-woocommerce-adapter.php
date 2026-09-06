<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_WooCommerce_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'woocommerce'; }
	public function label() { return 'WooCommerce'; }
	public function is_available() { return function_exists( 'wc_get_product' ); }
	public function ability_names() { return array( 'read' => array( 'woocommerce/status', 'woocommerce/get-product' ), 'content' => array( 'woocommerce/update-product' ), 'admin' => array() ); }
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

		$allowed = array( 'name', 'status', 'sku', 'regular_price', 'sale_price', 'stock_status', 'featured' );
		try {
			foreach ( $input['fields'] as $field => $value ) {
				if ( ! in_array( $field, $allowed, true ) ) return new WP_Error( 'mad4b_wc_field_denied', 'Unsupported product field: ' . sanitize_key( $field ) );
				if ( 'status' === $field ) {
					if ( ! in_array( $value, array( 'draft', 'pending', 'private', 'publish' ), true ) ) return new WP_Error( 'mad4b_wc_status_denied', 'Unsupported product status.' );
					if ( 'publish' === $value ) {
						$type = get_post_type_object( 'product' );
						$cap = $type && isset( $type->cap->publish_posts ) ? $type->cap->publish_posts : 'publish_products';
						if ( ! current_user_can( $cap ) ) return new WP_Error( 'mad4b_wc_cannot_publish', 'Current user cannot publish products.' );
					}
				}
				if ( 'stock_status' === $field && ! in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) return new WP_Error( 'mad4b_wc_stock_status_denied', 'Unsupported stock status.' );
				$method = 'set_' . $field;
				if ( ! is_callable( array( $product, $method ) ) ) return new WP_Error( 'mad4b_wc_setter_missing', 'WooCommerce setter is unavailable for: ' . sanitize_key( $field ) );
				if ( 'featured' === $field ) $value = (bool) $value;
				elseif ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) $value = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : sanitize_text_field( $value );
				else $value = sanitize_text_field( $value );
				$product->$method( $value );
			}
			$saved = $product->save();
		} catch ( Throwable $e ) {
			MAD4B_SCP_Audit::record( 'woocommerce/update-product', array( 'product_id' => $product->get_id(), 'error_type' => get_class( $e ) ), 'failure' );
			return new WP_Error( 'mad4b_wc_update_failed', $e->getMessage() );
		}
		$after = wc_get_product( $product->get_id() );
		$after_data = $after ? $this->product_data( $after ) : array();
		$after_hash = $this->hash_value( $after_data );
		MAD4B_SCP_Audit::record( 'woocommerce/update-product', array( 'product_id' => $product->get_id(), 'fields' => implode( ',', array_keys( $input['fields'] ) ), 'before_sha256' => $current_hash, 'after_sha256' => $after_hash ) );
		return array( 'product_id' => $saved, 'updated' => true, 'product' => $after_data, 'sha256' => $after_hash );
	}
	private function product_data( $product ) {
		$post = get_post( $product->get_id() );
		return array(
			'id' => $product->get_id(),
			'type' => $product->get_type(),
			'name' => $product->get_name(),
			'status' => $product->get_status(),
			'sku' => $product->get_sku(),
			'regular_price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(),
			'stock_status' => $product->get_stock_status(),
			'featured' => $product->get_featured(),
			'modified_gmt' => $post ? $post->post_modified_gmt : '',
			'permalink' => $product->get_permalink(),
		);
	}
}
