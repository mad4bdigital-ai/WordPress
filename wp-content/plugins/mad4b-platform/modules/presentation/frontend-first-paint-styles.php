<?php
/**
 * Frontend first-paint style order for dynamic Elementor templates.
 *
 * Some Elementor/JetEngine widgets enqueue CSS while body widgets render,
 * causing WordPress to output critical styles during wp_footer. Enqueue
 * already-registered, above-the-fold handles before wp_print_styles() so the
 * browser paints with their final layout. Keep provider-owned registrations.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_head', static function () {
	if ( is_admin() || ! class_exists( '\Elementor\Plugin', false ) ) {
		return;
	}

	// Header switchers are Elementor shortcodes loaded during body rendering.
	// Reuse their exact registered handles/asset versions, without changing
	// shortcode behavior, saved choices or frontend JavaScript.
	$switcher_styles = array(
		array( 'mad4b-country-switcher', 'mad4b-country-switcher/mad4b-country-switcher.php', 'assets/switcher.css', '0.1.0', 'MAD4B_Country_Switcher' ),
		array( 'travel-booking-nationality-switcher', 'etg-traveller-nationality-switcher/etg-traveller-nationality-switcher.php', 'assets/nationality.css', '0.3.0', 'Travel_Booking_Traveller_Nationality_Switcher' ),
	);
	foreach ( $switcher_styles as $style ) {
		list( $handle, $plugin, $asset, $version, $class ) = $style;
		if ( ! class_exists( $class, false ) ) {
			continue;
		}
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, plugins_url( $asset, WP_PLUGIN_DIR . '/' . $plugin ), array(), $version );
		}
		wp_enqueue_style( $handle );
	}

	// These widget assets are loaded at body render-time by Elementor and Jet
	// plugins on the homepage. They are registered before wp_head, so moving
	// their enqueue into the head avoids duplicates in wp_footer.
	if ( ! is_front_page() ) {
		return;
	}
	$critical_handles = array(
		'widget-heading',
		'widget-spacer',
		'widget-call-to-action',
		'widget-nested-tabs',
		'e-shapes',
		'e-swiper',
		'jet-smart-filters',
		'jet-elements',
		'elementor-gf-local-dmsans',
	);
	foreach ( $critical_handles as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}
}, 7 );
