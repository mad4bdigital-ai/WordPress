<?php
/**
 * Provider helper: WooCommerce.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_Helper_WooCommerce' ) ) {
	/**
	 * Define WooCommerce provider helper.
	 */
	class Jet_Smart_Filters_Provider_Helper_WooCommerce {
		/**
		 * Check if result count fragment was requested by frontend.
		 *
		 * @return bool
		 */
		public function is_result_count_requested() {

			return filter_var(
				jet_smart_filters()->data->get_request_var( 'has_result_count', false ),
				FILTER_VALIDATE_BOOLEAN
			);
		}

		/**
		 * Append WooCommerce result count fragment to AJAX response.
		 *
		 * @param array $data AJAX response data.
		 * @return array
		 */
		public function add_result_count_fragment( $data ) {

			if ( ! function_exists( 'woocommerce_result_count' ) || ! $this->is_result_count_requested() ) {
				return $data;
			}

			if ( empty( $data['replace_fragments'] ) ) {
				$data['replace_fragments'] = array();
			}

			ob_start();
			woocommerce_result_count();
			$result_count = ob_get_clean();

			if ( ! $result_count ) {
				return $data;
			}

			$data['replace_fragments']['.woocommerce-result-count'] = $result_count;

			return $data;
		}
	}
}
