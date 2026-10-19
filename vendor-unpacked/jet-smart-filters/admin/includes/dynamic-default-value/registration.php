<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

if ( ! class_exists( 'Jet_Smart_Filters_Admin_Dynamic_Default_Value_Registration' ) ) {
	/**
	 * Jet Smart Filters Admin Dynamic Default Value Registration class
	 */
	class Jet_Smart_Filters_Admin_Dynamic_Default_Value_Registration {

		public $manager;
		
		public function __construct() {
			// Init dynamic query data
			require jet_smart_filters()->plugin_path( 'admin/includes/dynamic-default-value/manager.php' );
			$this->manager = new Jet_Smart_Filters_Admin_Dynamic_Default_Value_Manager();

			// Insert default filter value queries to localized data
			add_filter( 'jet-smart-filters/admin/localized-data', array( $this, 'insert_default_value_queries' ), -999 );
		}

		public function insert_default_value_queries( $data ) {

			if ( ! $this->manager->list || ! isset( $data['filter_settings']['settings_block']['settings']['_default_filter_value'] ) ) {
				return $data;
			}

			$queries = isset( $data['filter_settings']['settings_block']['settings']['_default_filter_value']['options'] )
				? $data['filter_settings']['settings_block']['settings']['_default_filter_value']['options']
				: [];

			foreach ( $this->manager->list as $query_item ) {
				array_push( $queries, $query_item );
			}

			// Add options for Default Filter Value
			$data['filter_settings']['settings_block']['settings']['_default_filter_value']['options'] = $queries ;

			return $data;
		}
	}
}