<?php
/**
 * Misc funcitons
 */

require_once __DIR__ . '/filters/helpers/range-dynamic-data.php';

/**
 * Get min/max for meta key.
 */
function get_indexed_range( $args = array() ) {
	return Jet_Smart_Filters_Range_Dynamic_Data_Helper::get_indexed_range( $args );
}

/**
 * Get min/max price for WooCommerce products.
 */
function jet_smart_filters_woo_prices( $args = array() ) {
	return Jet_Smart_Filters_Range_Dynamic_Data_Helper::woo_prices( $args );
}

/**
 * Get min/max for post meta.
 */
function jet_smart_filters_meta_values( $args = array() ) {
	return Jet_Smart_Filters_Range_Dynamic_Data_Helper::meta_values( $args );
}

/**
 * Get min/max for user meta.
 */
function jet_smart_filters_user_meta_values( $args = array() ) {
	return Jet_Smart_Filters_Range_Dynamic_Data_Helper::user_meta_values( $args );
}

/**
 * Get min/max for term meta.
 */
function jet_smart_filters_term_meta_values( $args = array() ) {
	return Jet_Smart_Filters_Range_Dynamic_Data_Helper::term_meta_values( $args );
}

/**
 * Returns current currency symbol
 */
function jet_smart_filters_woo_currency_symbol() {

	$currency = apply_filters( 'jet-smart-filters/woocommerce/currency-symbol', get_woocommerce_currency_symbol() );

	return $currency;
}

/**
 * Do macros inside string
 */
function jet_smart_filters_macros( $string, $field_value = null ) {

	$macros = apply_filters( 'jet-smart-filters/macros/macros-list', array(
		'woocommerce_currency_symbol' => 'jet_smart_filters_woo_currency_symbol',
	) );

	return preg_replace_callback(
		'/%([a-z_-]+)(\|[a-z0-9_-]+)?%/',
		function ( $matches ) use ( $macros, $field_value ) {

			$found = $matches[1];

			if ( ! isset( $macros[ $found ] ) ) {
				return $matches[0];
			}

			$cb = $macros[ $found ];

			if ( ! is_callable( $cb ) ) {
				return $matches[0];
			}

			$args = isset( $matches[2] ) ? ltrim( $matches[2], '|' ) : false;

			return call_user_func( $cb, $field_value, $args );

		}, $string
	);
}
