<?php
/**
 * Provider base class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Filter_Base' ) ) {
	/**
	 * Define Jet_Smart_Filters_Filter_Base class
	 */
	abstract class Jet_Smart_Filters_Filter_Base {
		/**
		 * Get filter name
		 */
		abstract public function get_name();

		/**
		 * Get filter ID
		 */
		abstract public function get_id();

		/**
		 * Get filter JS files
		 */
		abstract public function get_scripts();

		/**
		 * Return arguments
		 */
		public function get_args() {

			return array();
		}

		/**
		 * Get filtered provider content
		 */
		public function get_template( $args = array() ) {

			if ( isset( $args['dropdown_enabled'] ) && $args['dropdown_enabled'] ) {
				return jet_smart_filters()->get_template( 'common/filter-items-dropdown.php' );
			} else {
				return jet_smart_filters()->get_template( 'filters/' . $this->get_id() . '.php' );
			}
		}

		/**
		 * Get filter widget file
		 */
		public function widget() {

			return jet_smart_filters()->plugin_path( 'includes/widgets/' . $this->get_id() . '.php' );
		}

		/**
		 * Get custom query variable
		 */
		public function get_custom_query_var( $filter_id ) {

			$custom_query_var = false;

			if ( filter_var( get_post_meta( $filter_id, '_is_custom_query_var', true ), FILTER_VALIDATE_BOOLEAN ) ) {
				$custom_query_var = get_post_meta( $filter_id, '_custom_query_var', true );
			}

			return $custom_query_var;
		}

		/**
		 * Get filter accessibility label
		 */
		public function get_accessibility_label( $filter_id ) {

			$label = get_post_meta( $filter_id, '_filter_label', true );

			if ( !$label ) {
				$label = get_the_title( $filter_id );
			}

			return esc_attr( wp_strip_all_tags( $label ) );
		}

		/**
		 * Get default filter value
		 */
		public function get_predefined_value( $filter_id ) {

			if ( ! filter_var( get_post_meta( $filter_id, '_is_default_filter_value', true ), FILTER_VALIDATE_BOOLEAN ) ) {
				return false;
			}

			$predefined_value = apply_filters( 'jet-smart-filters/filters/predefined-value',
				get_post_meta( $filter_id, '_default_filter_value', true ),
				$filter_id,
				$this->get_id()
			);

			if ( ! $predefined_value ) {
				return false;
			}
			
			$dynamic_predefined_value = $this->get_dynamic_predefined_value( $predefined_value );

			return $dynamic_predefined_value === false
				? $predefined_value
				: $dynamic_predefined_value;
		}

		/**
		 * Get dynamic default filter value
		 */
		private function get_dynamic_predefined_value( $value ) {

			$dynamic_default_value_types = array( 'request', 'cookie', 'shortcode' );
			$pattern = '/^__(' . implode( '|', array_map( 'preg_quote', $dynamic_default_value_types ) ) . ')::(.+)$/';

			if ( ! preg_match( $pattern, $value, $matches ) ) {
				return false;
			}

			$type  = $matches[1];
			$value = $matches[2];

			if ( $value === '' ) {
				return '';
			}

			switch ( $type ) {
				case 'request':
					if ( isset( $_REQUEST[$value] ) ) { // phpcs:ignore
						return $_REQUEST[$value]; // phpcs:ignore
					}

					break;
				
				case 'cookie':
					if ( isset( $_COOKIE[$value] ) ) { // phpcs:ignore
						return sanitize_text_field( wp_unslash( $_COOKIE[$value] ) ); // phpcs:ignore
					}

					break;

				case 'shortcode':
					$shortcode_result = do_shortcode( wp_kses_post( $value ) );

					if ( $shortcode_result && $shortcode_result !== $value ) {
						return $shortcode_result;
					}

					break;
			}

			return '';
		}
	}
}
