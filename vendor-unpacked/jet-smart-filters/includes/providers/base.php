<?php
/**
 * Provider base class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_Base' ) ) {
	/**
	 * Define Jet_Smart_Filters_Provider_Base class
	 */
	abstract class Jet_Smart_Filters_Provider_Base {
		/**
		 * Provider helpers manager instance.
		 *
		 * @var Jet_Smart_Filters_Provider_Helpers_Manager|null
		 */
		private $helpers = null;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->init_helpers();
		}

		/**
		 * Get provider name
		 */
		abstract public function get_name();

		/**
		 * Get provider ID
		 */
		abstract public function get_id();

		/**
		 * Get filtered provider content
		 */
		abstract public function ajax_get_content();

		/**
		 * Get provider wrapper selector
		 */
		abstract public function get_wrapper_selector();

		/**
		 * Get provider list selector
		 */
		public function get_list_selector() {

			return false;
		}

		/**
		 * Get provider list item selector
		 */
		public function get_item_selector() {

			return false;
		}

		/**
		 * Action for wrapper selector - 'insert' into it or 'replace'
		 */
		public function get_wrapper_action() {

			return 'insert';
		}

		/**
		 * If added unique ID this paramter will determine - search selector inside this ID, or is the same element
		 */
		public function in_depth() {

			return false;
		}

		/**
		 * Set prefix for unique ID selector. Mostly is default '#' sign, but sometimes class '.' sign needed
		 */
		public function id_prefix() {

			return '#';
		}

		/**
		 * If true, then only data without content is expected in the ajax response
		 */
		public function is_data() {

			return false;
		}

		/**
		 * Initialize provider helpers manager.
		 *
		 * @return void
		 */
		protected function init_helpers() {

			if ( null !== $this->helpers ) {
				return;
			}

			require_once jet_smart_filters()->plugin_path( 'includes/providers/helpers/manager.php' );

			$this->helpers = new Jet_Smart_Filters_Provider_Helpers_Manager();
		}

		/**
		 * Lazy helper access for providers that do not call parent constructor.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get( $name ) {

			if ( 'helpers' !== $name ) {
				return null;
			}

			$this->init_helpers();

			return $this->helpers;
		}

		public function merge_query( $query_args, $default_query_args ) {

			foreach ( $query_args as $key => $value ) {
				if ( in_array( $key, array( 'tax_query', 'meta_query' ) ) ) {
					$current = $default_query_args[$key];

					if ( ! empty( $current ) ) {
						$query_args[$key] = array_merge( $current, $query_args[$key] );
					}
				}
			}

			return array_merge( $default_query_args, $query_args );
		}

		/**
		 * Sanitize settings before passing into provider render
		 */
		public function sanitize_settings( $settings = array() ) {

			$sanitized = array();

			foreach ( $settings as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = $this->sanitize_settings( $value );
				} else {
					$value = $this->sanitize_setting( $value );
				}

				$sanitized[ $key ] = $value;
			}

			return $sanitized;
		}

		/**
		 * Sanitize single settings value before passing into provider renderer
		 */
		public function sanitize_setting( $value ) {

			$value = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $value );

			return wp_check_invalid_utf8( $value, true );
		}
	}
}
