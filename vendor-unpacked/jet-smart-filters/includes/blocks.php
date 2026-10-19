<?php
/**
 * Gutenberg blocks manager
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Blocks_Manager' ) ) {

	/**
	 * Define Jet_Smart_Filters_Blocks_Manager class
	 */
	class Jet_Smart_Filters_Blocks_Manager {

		protected $available_providers = array();
		protected $blocks_providers    = null;
		public $style_manager = null;
		public $blocks_types = null;

		/**
		 * Constructor for the class
		 */
		function __construct() {

			$module_data = jet_smart_filters()->framework->get_included_module_data( 'style-manager.php' );

			$this->style_manager = new \Crocoblock\Blocks_Style\Manager( array(
				'path' => $module_data['path'],
				'url'  => $module_data['url'],
			) );

			// Rrequire early for 3rd party blocks compatibility
			require jet_smart_filters()->plugin_path( 'includes/blocks/base.php' );

			add_action( 'init', [ $this, 'init' ] );
		}

		/**
		 * Initialize all required logic only when we have supported providers
		 * @return [type] [description]
		 */
		public function init() {

			$this->available_providers = jet_smart_filters()->data->get_avaliable_providers();
			$blocks_providers          = $this->blocks_providers();

			if ( empty( $blocks_providers ) ) {
				return;
			}

			$this->register_block_types();

			add_action( 'enqueue_block_editor_assets', [ $this, 'blocks_assets' ] );
			add_filter( 'block_categories_all', [ $this, 'add_filters_category' ] );
		}

		/**
		 * Register blocks assets
		 */
		public function blocks_assets() {

			// enqueue assets
			jet_smart_filters()->filter_types->filter_scripts();
			jet_smart_filters()->filter_types->filter_styles();

			wp_enqueue_style(
				'jet-smart-filters-gutenberg-editor-styles',
				jet_smart_filters()->plugin_url( 'admin/assets/css/gutenberg.css' ),
				[ 'air-datepicker' ],
				jet_smart_filters()->get_version()
			);

			wp_enqueue_script(
				'jet-smart-filters-blocks',
				jet_smart_filters()->plugin_url( 'assets/js/blocks.js' ),
				[ 'wp-blocks', 'wp-element', 'wp-editor', 'wp-block-editor', 'wp-components', 'wp-i18n', 'jet-smart-filters', 'air-datepicker', 'lodash' ],
				jet_smart_filters()->get_version(),
				true
			);
			/* 'wp-blocks',
			'wp-element',
			'wp-editor',
			'wp-block-editor',
			'wp-components',
			'wp-i18n', */

			$localized_data = $this->get_block_editor_config();

			wp_localize_script( 'jet-smart-filters-blocks', 'JetSmartFilterBlocksData', $localized_data );
		}

		/**
		 * Returns editor config
		 */
		public function get_block_editor_config() {
			return apply_filters( 'jet-smart-filters/blocks/localized-data', [
				'filters'         => $this->get_filter_types_data(),
				'providers'       => $this->get_providers_data(),
				'image_sizes'     => jet_smart_filters()->utils->get_image_sizes(),
				'sorting_orderby' => jet_smart_filters()->filter_types->get_filter_types( 'sorting' )->orderby_options()
			] );
		}

		/**
		 * Returns filters of all types options
		 */
		public function get_filter_types_data() {

			$filter_types_data = [];

			foreach ( array_keys( jet_smart_filters()->data->filter_types() ) as $filter_type ) {
				$filter_types_data[$filter_type] = [ '0' => __( 'Select filter...', 'jet-smart-filters' ) ] + jet_smart_filters()->data->get_filters_by_type( $filter_type );
			}

			return $filter_types_data;
		}

		public function blocks_providers() {

			if ( null === $this->blocks_providers ) {
				$blocks_providers = [];

				if ( filter_var( $this->available_providers['Jet_Smart_Filters_Provider_Listing'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
					$blocks_providers['jsf-listing'] = __( 'JSF Listing', 'jet-smart-filters' );
				}

				if ( class_exists( 'Jet_Engine' ) && filter_var( $this->available_providers['Jet_Smart_Filters_Provider_Jet_Engine'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
					$blocks_providers['jet-engine'] = __( 'Listing Grid', 'jet-smart-filters' );
				}

				if ( class_exists( 'WooCommerce' ) ) {
					if ( filter_var( $this->available_providers['Jet_Smart_Filters_Provider_WooCommerce_Shortcode'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
						$blocks_providers['woocommerce-shortcode'] = __( 'WooCommerce Shortcode', 'jet-smart-filters' );
					}
					if ( filter_var( $this->available_providers['Jet_Smart_Filters_Provider_WooCommerce_Archive_Default'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
						$blocks_providers['default-woo-archive'] = __( 'Default WooCommerce Archive (Classic)', 'jet-smart-filters' );
					}
				}

				$this->blocks_providers = apply_filters( 'jet-smart-filters/blocks/allowed-providers', $blocks_providers );
			}

			return $this->blocks_providers;
		}

		/**
		 * Returns providers options
		 */
		public function get_providers_data() {

			$providers_data = [
				'not-selected' => __( 'Select provider...', 'jet-smart-filters' )
			];

			return array_merge( $providers_data, $this->blocks_providers() );
		}

		/**
		 * Add new category for filters
		 */
		function add_filters_category( $categories ) {

			return array_merge(
				$categories,
				[
					[
						'slug'  => 'jet-smart-filters',
						'title' => __( 'Jet Smart Filters', 'jet-smart-filters' ),
						'icon'  => 'filter',
					],
				]
			);
		}

		/**
		 * Register block types
		 */
		public function register_block_types() {

			if ( ! empty( $this->blocks_types ) ) {
				return;
			}

			$types_dir = jet_smart_filters()->plugin_path( 'includes/blocks/' );

			require $types_dir . 'checkboxes.php';
			require $types_dir . 'select.php';
			require $types_dir . 'range.php';
			require $types_dir . 'check-range.php';
			require $types_dir . 'radio.php';
			require $types_dir . 'date-range.php';
			require $types_dir . 'date-period.php';
			require $types_dir . 'rating.php';
			require $types_dir . 'alphabet.php';
			require $types_dir . 'search.php';
			require $types_dir . 'visual.php';
			require $types_dir . 'sorting.php';
			require $types_dir . 'active-filters.php';
			require $types_dir . 'active-tags.php';
			require $types_dir . 'apply-button.php';
			require $types_dir . 'remove-filters.php';
			require $types_dir . 'pagination.php';
			require $types_dir . 'hidden.php';

			$checkboxes = new Jet_Smart_Filters_Block_Checkboxes();
			$select = new Jet_Smart_Filters_Block_Select();
			$range = new Jet_Smart_Filters_Block_Range();
			$check_range = new Jet_Smart_Filters_Block_Check_Range();
			$radio = new Jet_Smart_Filters_Block_Radio();
			$date_range = new Jet_Smart_Filters_Block_Date_Range();
			$date_period = new Jet_Smart_Filters_Block_Date_Period();
			$rating = new Jet_Smart_Filters_Block_Rating();
			$alphabet = new Jet_Smart_Filters_Block_Alphabet();
			$search = new Jet_Smart_Filters_Block_Search();
			$visual = new Jet_Smart_Filters_Block_Visual();
			$sorting = new Jet_Smart_Filters_Block_Sorting();
			$active_filters = new Jet_Smart_Filters_Block_Active_Filters();
			$active_tags = new Jet_Smart_Filters_Block_Active_Tags();
			$apply_button = new Jet_Smart_Filters_Block_Apply_Button();
			$remove_filters = new Jet_Smart_Filters_Block_Remove_Filters();
			$pagination = new Jet_Smart_Filters_Block_Pagination();
			$hidden = new Jet_Smart_Filters_Block_Hidden();

			$this->blocks_types = [
				$checkboxes->get_name()   => $checkboxes,
				$select->get_name()       => $select,
				$range->get_name()        => $range,
				$check_range->get_name()  => $check_range,
				$radio->get_name()        => $radio,
				$date_range->get_name()   => $date_range,
				$date_period->get_name()  => $date_period,
				$rating->get_name()       => $rating,
				$alphabet->get_name()     => $alphabet,
				$search->get_name()       => $search,
				$visual->get_name()       => $visual,
				$sorting->get_name()      => $sorting,
				$active_filters->get_name() => $active_filters,
				$active_tags->get_name()  => $active_tags,
				$apply_button->get_name() => $apply_button,
				$remove_filters->get_name() => $remove_filters,
				$pagination->get_name()   => $pagination,
				$hidden->get_name()       => $hidden,
			];
		}

		/**
		 * Returns registered block type instance
		 *
		 * @param  string $name Block type name.
		 * @return Jet_Smart_Filters_Block_Base
		 */
		public function get_block_type( $name ) {
			return isset( $this->blocks_types[ $name ] ) ? $this->blocks_types[ $name ] : false;
		}
	}
}
