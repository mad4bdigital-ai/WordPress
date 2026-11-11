<?php
/**
 * Plugin Name: MAD4B Company Public Name in Shared Templates
 * Description: Resolves explicitly approved company-name placeholders at render time without changing Elementor widget layouts.
 * Version: 1.0.0
 * Author: MAD4B
 *
 * This MU plugin is intentionally content-only. Do not add pricing, refund,
 * company authorization, or provider-account fallbacks here.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mad4b_company_template_public_name' ) ) {
	/**
	 * Resolve the PUBLIC site/company name from the current WordPress site.
	 *
	 * WordPress blogname is the per-site canonical public brand identity.
	 * "company-communication-operations.company_display_name" is a private
	 * transactional sender label and must never silently override this.
	 *
	 * In a future single-site/multiple-company deployment this resolver must
	 * be replaced by a separately approved, authenticated company_id/site_uuid
	 * resolver. Do NOT use a company-agnostic wp_options fallback then.
	 *
	 * @return string
	 */
	function mad4b_company_template_public_name() {
		$name = get_option( 'blogname', '' );

		if ( ! is_scalar( $name ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( (string) $name ) );
	}
}

if ( ! function_exists( 'mad4b_company_template_legacy_label' ) ) {
	/**
	 * Resolve one exact legacy placeholder pair in EXISTING text-editor HTML.
	 * It historically appeared as "[site_title][copyright]" within a sentence.
	 * Neither shortcode was registered: the pair is NOT a legal copyright.
	 *
	 * No other shortcode, markup, class, link, number, or policy may change.
	 *
	 * @param string $rendered_widget_html Rendered text-editor HTML.
	 * @return string
	 */
	function mad4b_company_template_legacy_label( $rendered_widget_html ) {
		if ( ! is_string( $rendered_widget_html ) ||
			false === strpos( $rendered_widget_html, '[site_title][copyright]' ) ) {
			return $rendered_widget_html;
		}

		$name = mad4b_company_template_public_name();

		// Fail closed: an unset company must never inherit another brand.
		return str_replace(
			'[site_title][copyright]',
			esc_html( $name ),
			$rendered_widget_html
		);
	}
}

add_shortcode(
	'mad4b_company_name',
	static function () {
		return esc_html( mad4b_company_template_public_name() );
	}
);

/**
 * Elementor runs this on each widget's output. Match only text-editor widgets,
 * not headings, tabs, popups, galleries, shortcodes, pricing or form controls.
 * The source _elementor_data JSON and the design remain unchanged.
 */
add_filter(
	'elementor/widget/render_content',
	static function ( $html, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ||
			'text-editor' !== $widget->get_name() ) {
			return $html;
		}

		return mad4b_company_template_legacy_label( $html );
	},
	40,
	2
);
