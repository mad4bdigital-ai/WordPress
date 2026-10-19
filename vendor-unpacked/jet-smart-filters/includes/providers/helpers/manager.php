<?php
/**
 * Provider helpers manager.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_Helpers_Manager' ) ) {
	/**
	 * Define provider helpers manager class.
	 */
	class Jet_Smart_Filters_Provider_Helpers_Manager {

		/**
		 * Registered helper instances.
		 *
		 * @var array
		 */
		protected $helpers = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->register_default_helpers();
		}

		/**
		 * Register built-in helpers.
		 *
		 * @return void
		 */
		protected function register_default_helpers() {
			require_once jet_smart_filters()->plugin_path( 'includes/providers/helpers/elementor.php' );
			require_once jet_smart_filters()->plugin_path( 'includes/providers/helpers/woocommerce.php' );

			$this->helpers['elementor']   = new Jet_Smart_Filters_Provider_Helper_Elementor();
			$this->helpers['woocommerce'] = new Jet_Smart_Filters_Provider_Helper_WooCommerce();
		}

		/**
		 * Get helper by name.
		 *
		 * @param string $name Helper name.
		 * @return object|false
		 */
		public function get( $name ) {
			return isset( $this->helpers[ $name ] ) ? $this->helpers[ $name ] : false;
		}

		/**
		 * Magic access to registered helpers.
		 *
		 * @param string $name Helper name.
		 * @return object|false
		 */
		public function __get( $name ) {
			return $this->get( $name );
		}
	}
}
