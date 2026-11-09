<?php
/**
 * Plugin Name: MAD4B localized commerce pages
 * Description: Resolve cart and checkout page identities in the current storefront language.
 */

defined( 'ABSPATH' ) || exit;

function mad4b_localized_commerce_page_id( $page_id ) {
	if ( $page_id <= 0 || is_admin() ) {
		return $page_id;
	}

	$language = apply_filters( 'wpml_current_language', null );
	if ( ! $language || 'all' === $language ) {
		return $page_id;
	}

	$translated = apply_filters( 'wpml_object_id', $page_id, 'page', false, $language );
	if ( $translated && (int) $translated !== (int) $page_id && 'publish' === get_post_status( $translated ) ) {
		return (int) $translated;
	}
	return $page_id;
}

add_filter( 'woocommerce_get_cart_page_id', 'mad4b_localized_commerce_page_id', 99 );
add_filter( 'woocommerce_get_checkout_page_id', 'mad4b_localized_commerce_page_id', 99 );
