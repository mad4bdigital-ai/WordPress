<?php
namespace ETG\DynamicFilterSEOBridge;

final class Compatibility {
	public function jetSmartFilters(): bool { return function_exists( 'jet_smart_filters' ); }
	public function jetEngine(): bool { return function_exists( 'jet_engine' ); }
	public function rankMath(): bool { return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ); }
	public function rankMathPro(): bool { return defined( 'RANK_MATH_PRO_VERSION' ); }
	public function wpml(): bool { return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_current_language' ); }
	public function elementor(): bool { return defined( 'ELEMENTOR_VERSION' ); }
	public function elementorPro(): bool { return defined( 'ELEMENTOR_PRO_VERSION' ); }

	public function report(): array {
		$jsfQuery = null;
		if ( $this->jetSmartFilters() ) {
			$jsf = jet_smart_filters();
			$jsfQuery = is_object( $jsf ) && isset( $jsf->query ) && is_object( $jsf->query ) ? $jsf->query : null;
		}
		$managerClass = '\\Jet_Engine\\Query_Builder\\Manager';
		$sitemapProvider='\\RankMath\\Sitemap\\Providers\\Provider';
		$sitemapRouter='\\RankMath\\Sitemap\\Router';
		$sitemapCache='\\RankMath\\Sitemap\\Cache';
		$capabilities = array(
			'jsf_get_current_provider' => is_object( $jsfQuery ) && method_exists( $jsfQuery, 'get_current_provider' ),
			'jsf_get_query_from_request' => is_object( $jsfQuery ) && method_exists( $jsfQuery, 'get_query_from_request' ),
			'jet_engine_query_manager' => class_exists( $managerClass ) && method_exists( $managerClass, 'instance' ) && method_exists( $managerClass, 'get_query_by_id' ),
			'wpml_current_language_filter' => has_filter( 'wpml_current_language' ) !== false,
			'wpml_object_id_filter' => has_filter( 'wpml_object_id' ) !== false,
			'wpml_active_languages_filter' => has_filter( 'wpml_active_languages' ) !== false,
			'wpml_permalink_filter' => has_filter( 'wpml_permalink' ) !== false,
			'rank_math_sitemap_provider_interface' => interface_exists( $sitemapProvider ),
			'rank_math_sitemap_router' => class_exists( $sitemapRouter ) && method_exists( $sitemapRouter, 'get_base_url' ),
			'rank_math_sitemap_cache' => class_exists( $sitemapCache ) && method_exists( $sitemapCache, 'invalidate_storage' ),
		);
		return array(
			'jet_smart_filters' => $this->jetSmartFilters(),
			'jet_engine' => $this->jetEngine(),
			'rank_math' => $this->rankMath(),
			'rank_math_pro' => $this->rankMathPro(),
			'wpml' => $this->wpml(),
			'elementor' => $this->elementor(),
			'elementor_pro' => $this->elementorPro(),
			'versions' => array(
				'jet_engine' => defined( 'JET_ENGINE_VERSION' ) ? (string) JET_ENGINE_VERSION : '',
				'jet_smart_filters' => defined( 'JET_SMART_FILTERS_VERSION' ) ? (string) JET_SMART_FILTERS_VERSION : '',
				'rank_math' => defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '',
				'rank_math_pro' => defined( 'RANK_MATH_PRO_VERSION' ) ? (string) RANK_MATH_PRO_VERSION : '',
				'wpml' => defined( 'ICL_SITEPRESS_VERSION' ) ? (string) ICL_SITEPRESS_VERSION : '',
				'elementor' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			),
			'capabilities' => $capabilities,
		);
	}
}
