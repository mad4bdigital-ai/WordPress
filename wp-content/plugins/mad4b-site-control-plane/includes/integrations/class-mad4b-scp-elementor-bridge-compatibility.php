<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Optional provider compatibility; never required by generic source-tag copying. */
final class MAD4B_SCP_Elementor_Bridge_Compatibility {
	public static function available() { return defined( 'ETG_DFSB_VERSION' ) && class_exists( '\\ETG\\DynamicFilterSEOBridge\\Config\\Configuration' ); }
	public static function tag_names() { return array( 'etg-filter-title', 'etg-filter-intro', 'etg-filter-image', 'etg-filter-gallery', 'etg-filter-result-summary' ); }
	public static function settings( $tag_name, array $input ) {
		if ( ! self::available() ) return new WP_Error( 'mad4b_optional_bridge_unavailable', 'Optional dynamic-tag integration is not installed.' );
		$tag_name = sanitize_key( (string) $tag_name );
		$allowed = array( 'etg-filter-title', 'etg-filter-intro', 'etg-filter-image', 'etg-filter-gallery', 'etg-filter-result-summary' );
		if ( ! in_array( $tag_name, $allowed, true ) ) return new WP_Error( 'mad4b_elementor_etg_dynamic_tag_not_allowlisted', 'ETG dynamic tag name is not allowlisted.' );
		$settings = array( 'preview_url' => '' );
		if ( in_array( $tag_name, array( 'etg-filter-title', 'etg-filter-intro', 'etg-filter-result-summary' ), true ) ) {
			if ( isset( $input['mode'] ) || isset( $input['limit'] ) ) return new WP_Error( 'mad4b_elementor_etg_dynamic_tag_settings_invalid', 'Text ETG dynamic tags do not accept media mode or limit settings.' );
			$settings['live_update'] = 'yes';
			$settings['live_group'] = '';
			return $settings;
		}
		$modes = array( 'priority', 'combined', 'all_terms', 'galleries_only', 'primary_images', 'balanced', 'role_priority' );
		$default_mode = 'etg-filter-gallery' === $tag_name ? 'combined' : 'priority';
		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : $default_mode;
		if ( ! in_array( $mode, $modes, true ) ) return new WP_Error( 'mad4b_elementor_etg_dynamic_tag_mode_invalid', 'ETG media dynamic-tag mode is not allowlisted.' );
		$settings['mode'] = $mode;
		if ( 'etg-filter-image' === $tag_name ) {
			if ( isset( $input['limit'] ) ) return new WP_Error( 'mad4b_elementor_etg_dynamic_tag_settings_invalid', 'ETG Filter Image does not accept a gallery limit.' );
			$settings['fallback_image'] = array( 'id' => 0, 'url' => '' );
			return $settings;
		}
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 9;
		if ( $limit < 1 || $limit > 30 ) return new WP_Error( 'mad4b_elementor_etg_dynamic_tag_limit_invalid', 'ETG Filter Gallery limit must be between 1 and 30.' );
		$settings['limit'] = $limit;
		return $settings;
	}

}
