<?php

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

/**
 * Class: Jet_Smart_Filters_Provider_Listing
 * Name: JSF Listing
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_Listing' ) ) {
	/**
	 * Define Jet_Smart_Filters_Provider_Listing class
	 */
	class Jet_Smart_Filters_Provider_Listing extends Jet_Smart_Filters_Provider_Base {

		private $listing_id = null;
		private $query_id   = 'default';

		public function __construct() {

			if ( ! jet_smart_filters()->query->is_ajax_filter() ) {
				add_action( 'jet-smart-filters/listing/block/settings', array( $this, 'store_settings' ) );
				add_filter( 'jet-smart-filters/listing/render/query', array( $this, 'store_default_query' ) );
				add_filter( 'jet-smart-filters/listing/render/query', array( $this, 'store_default_props' ), 99 );
			}
		}

		/**
		 * Get provider name
		 */
		public function get_name() {
			return __( 'JSF Listing', 'jet-smart-filters' );
		}

		/**
		 * Get provider ID
		 */
		public function get_id() {
			return 'jsf-listing';
		}

		/**
		 * Store settings
		 */
		public function store_settings( $settings ) {

			$this->listing_id = ! empty( $settings['listing_id'] ) ? $settings['listing_id'] : null;
			$this->query_id   = ! empty( $settings['_element_id'] ) ? $settings['_element_id'] : 'default';

			if ( ! $this->listing_id ) {
				return $settings;
			}

			$provider_settings = apply_filters(
				'jet-smart-filters/providers/jsf-listing/stored-settings',
				array(
					'lisitng_id' => $this->listing_id,
					'query_id'   => $this->query_id,
				),
			);

			jet_smart_filters()->providers->store_provider_settings(
				$this->get_id(),
				$provider_settings,
				$this->query_id
			);

			return $settings;
		}

		/**
		 * Store default query args
		 */
		public function store_default_query( $query ) {

			if ( ! $query || ! is_object( $query ) || ! is_callable( array( $query, 'get_query_args' ) ) ) {
				return $query;
			}

			$args = $query->get_query_args();

			jet_smart_filters()->query->store_provider_default_query(
				$this->get_id(),
				$args,
				$this->query_id
			);

			return $query;
		}

		/**
		 * Store query properties
		 */
		public function store_default_props( $query ) {

			if ( ! $query || ! is_object( $query ) || ! is_callable( array( $query, 'get_stats' ) ) ) {
				return $query;
			}

			// Set pagination props ( found_posts, max_num_pages, page )
			jet_smart_filters()->query->set_props(
				$this->get_id(),
				$query->get_stats(),
				$this->query_id
			);

			return $query;
		}

		/**
		 * Get filtered provider content
		 */
		public function ajax_get_content() {

			$settings_request_val = jet_smart_filters()->data->get_request_var( 'settings' );
			$listing_id = ! empty( $settings_request_val['lisitng_id'] ) ? absint( $settings_request_val['lisitng_id'] ) : null;
			$query_id   = ! empty( $settings_request_val['query_id'] ) ? $settings_request_val['query_id'] : 'default';

			if ( ! $listing_id ) {
				return;
			}

			$listing = Listing_Controller::instance()->render->init_listing( $listing_id );
			$this->add_query_args( $listing );
			$listing->render();
		}

		/**
		 * Pass args from reuest to provider
		 */
		public function apply_filters_in_request() {

			$args = jet_smart_filters()->query->get_query_args();

			if ( ! $args ) {
				return;
			}

			add_filter(
				'jet-smart-filters/listing/render/query',
				array( $this, 'add_request_query_args' ), 20, 2
			);
		}

		/**
		 * Get provider wrapper selector
		 */
		public function get_wrapper_selector() {

			return apply_filters(
				'jet-smart-filters/providers/jsf-listing/selector',
				'.jsf-listing'
			);
		}

		/**
		 * Get provider list selector
		 */
		/* public function get_list_selector() {

			return '.jsf-listing';
		} */

		/**
		 * Get provider list item selector
		 */
		public function get_item_selector() {

			return '.jsf-listing__item';
		}

		/**
		 * Action for wrapper selector - 'insert' into it or 'replace'
		 */
		public function get_wrapper_action() {

			return 'replace';
		}

		/**
		 * If added unique ID this paramter will determine - search selector inside this ID, or is the same element
		 */
		public function in_depth() {
			return true;
		}

		/**
		 * Add request query args
		 */
		public function add_request_query_args( $query, $listing ) {

			/**
			 * @todo Check if this is required listing
			 */

			if ( $query && is_object( $query ) && is_callable( array( $query, 'add_query_args' ) ) ) {
				$filter_args = jet_smart_filters()->query->get_query_args();
				$query->add_query_args( $filter_args );
			}

			return $query;
		}

		/**
		 * Add custom query arguments
		 */
		public function add_query_args( $listing ) {

			$filter_args   = jet_smart_filters()->query->get_query_args();
			$listing_query = $listing->get_query();

			if ( $listing_query && is_object( $listing_query ) && is_callable( array( $listing_query, 'add_query_args' ) ) ) {
				$listing_query->add_query_args( $filter_args );
			}
		}
	}
}
