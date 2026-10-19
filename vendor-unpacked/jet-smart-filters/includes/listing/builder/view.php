<?php
namespace Jet_Smart_Filters\Listing\Builder;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builder view class
 */
class View {

	protected $action_key;
	protected $blocks_options;
	protected $builder_el_id = 'jsf_listing_builder';

	public function __construct() {

		$this->action_key     = Listing_Controller::instance()->listing_key;
		$this->blocks_options = Listing_Controller::instance()->helpers->blocks_options;

		add_action( 'admin_menu', [ $this, 'register_listings_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
		add_action( 'admin_head', function () {

			$screen = get_current_screen();

			if ( isset( $screen->id ) && preg_match( '/_page_' . preg_quote( $this->action_key, '/' ) . '$/', $screen->id ) ) {
				echo '<style>#wpfooter { display: none !important; }</style>';

				remove_all_actions('admin_notices');
				remove_all_actions('all_admin_notices');
			}
		});
	}

	/**
	 * Admin page assets
	 *
	 * @return void
	 */
	public function admin_assets() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || $this->action_key !== $_GET['page'] ) {
			return;
		}

		do_action( 'jet-smart-filters/listing/before-editor-assets' );

		$js_listing_app_assets = include jet_smart_filters()->plugin_path( 'includes/listing/builder/assets/js/jsf-listing-app.asset.php' );

		wp_enqueue_script(
			$this->action_key,
			jet_smart_filters()->plugin_url( 'includes/listing/builder/assets/js/jsf-listing-app.js' ),
			$js_listing_app_assets['dependencies'],
			$js_listing_app_assets['version'],
			true
		);

		wp_add_inline_script(
			$this->action_key,
			'window.JSFListings = window.JSFListings || { _queue: [], registerQueryType: function( descriptor ) { this._queue.push( descriptor ); } };',
			'before'
		);

		wp_enqueue_media();

		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-components' );

		do_action( 'enqueue_block_assets' );
		do_action( 'enqueue_block_editor_assets' );

		$style_listing_app_assets = include jet_smart_filters()->plugin_path( 'includes/listing/builder/assets/css/jsf-listing-app.asset.php' );

		wp_enqueue_style(
			$this->action_key,
			jet_smart_filters()->plugin_url( is_rtl()
				? 'includes/listing/builder/assets/css/style-jsf-listing-app-rtl.css'
				: 'includes/listing/builder/assets/css/style-jsf-listing-app.css'
			),
			$style_listing_app_assets['dependencies'],
			$style_listing_app_assets['version'],
		);

		// Preview styles
		$listing = Listing_Controller::instance()->render->init_listing( 0 );
		$listing->assets();

		wp_localize_script( $this->action_key, 'JSFBuilderData', [
			'endpoints' => $this->get_endpoints(),
			'nonce'     => wp_create_nonce( $this->action_key ),
			'el_id'     => $this->builder_el_id,
			'data'      => array(
				'post_types'             => $this->blocks_options->get_post_types_options(),
				'posts_order_by'         => $this->blocks_options->get_posts_order_by_options(),
				'post_stati'             => $this->blocks_options->get_post_stati_options(),
				'taxonomies'             => $this->blocks_options->get_taxonomies_options(),
				'grouped_taxonomies'     => $this->blocks_options->get_grouped_taxonomies_options(),
				'term_fields'            => $this->blocks_options->get_taxonomy_term_field_options(),
				'term_compare_operators' => $this->blocks_options->get_term_compare_operator_options(),
				'meta_compare_operators' => $this->blocks_options->get_meta_compare_operator_options(),
				'meta_type'              => $this->blocks_options->get_meta_type_options(),
				'field_sources'          => $this->blocks_options->get_field_sources(),
				'object_fields'          => $this->blocks_options->get_object_fields(),
				'filter_сallbacks'       => $this->blocks_options->get_filter_сallbacks(),
				'link_sources'           => $this->blocks_options->get_link_sources(),
				'media_sources'          => $this->blocks_options->get_media_sources(),
				'image_sizes'            => $this->blocks_options->get_image_sizes(),
				'label_types'            => $this->blocks_options->get_label_types(),
				'label_aria_types'       => $this->blocks_options->get_label_aria_types(),
				'rel_attribute_types'    => $this->blocks_options->get_rel_attribute_types(),
				'term_order_by_fields'   => $this->blocks_options->get_term_order_by_fields(),
				'term_order_fields'      =>$this->blocks_options->get_term_order_fields(),
				'jet_engine_query_builder_available' => class_exists( '\Jet_Engine\Query_Builder\Manager' ),
				'blocks_categories'      => Listing_Controller::instance()->builder->blocks->get_categories(),
				'allowed_block'          => Listing_Controller::instance()->builder->blocks->get_allowed_block(),
				'image_placeholder_url'  => jet_smart_filters()->plugin_url( 'assets/images/placeholder.png' ),
			)
		] );

		do_action( 'jet-smart-filters/listing/editor-assets' );
	}

	/**
	 * Get prefixed AJAX actions map to use in JS app.
	 *
	 * @return array
	 */
	public function get_endpoints() {

		$endpoints = [];

		foreach ( Listing_Controller::instance()->builder->actions() as $action => $callback ) {
			$endpoints[ $action ] = $this->action_key . '_' . $action;
		}

		return $endpoints;
	}

	/**
	 * Regsiter Listings submenu page
	 *
	 * @return void
	 */
	public function register_listings_page() {

		add_submenu_page(
			jet_smart_filters()->post_type->slug(),
			esc_html__( 'Listing Builder', 'jet-smart-filters' ),
			esc_html__( 'Listing Builder', 'jet-smart-filters' ),
			'manage_options',
			$this->action_key,
			[ $this, 'render_listings_page' ]
		);
	}

	/**
	 * Render listings admin page
	 *
	 * @return void
	 */
	public function render_listings_page() {

		printf(
			'<div id="%s"></div>',
			esc_attr( $this->builder_el_id )
		);
	}

}
