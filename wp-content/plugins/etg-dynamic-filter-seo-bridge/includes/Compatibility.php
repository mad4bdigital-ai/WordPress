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
		return array(
			'jet_smart_filters' => $this->jetSmartFilters(),
			'jet_engine' => $this->jetEngine(),
			'rank_math' => $this->rankMath(),
			'rank_math_pro' => $this->rankMathPro(),
			'wpml' => $this->wpml(),
			'elementor' => $this->elementor(),
			'elementor_pro' => $this->elementorPro(),
		);
	}
}
