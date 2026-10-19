<?php
namespace Jet_Smart_Filters\Listing\Render;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main listing/render class
 */
class Controller {

	protected $current_object = null;

	public function __construct() {

		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/query-factory.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/listing-base.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/render/listing-css.php' );

		add_action( 'init', [ Query_Factory::class, 'register_query_types' ], 999 );

		// Register listings for the admin bar module on render
		add_action(
			'jet-smart-filters/listing/render/init-listing',
			[ $this, 'register_listings_for_admin_bar' ]
		);
	}

	/**
	 * Register listings for the admin bar module
	 */
	public function register_listings_for_admin_bar( $listing ) {
		jet_smart_filters()->admin_bar->register_item(
			'jsf-listing-' . $listing->get_id(),
			[
				'title'     => $listing->get_name(),
				'sub_title' => 'JSF Listing',
				'href'      => admin_url( 'admin.php?page=jsf-listing-builder#/edit-listing/' . $listing->get_id() ),
			]
		);

		if ( is_callable( [ $listing, 'get_card_id' ] ) && $listing->get_card_id() ) {
			jet_smart_filters()->admin_bar->register_item(
				'jsf-card-' . $listing->get_card_id(),
				[
					'title'     => $listing->get_card_name(),
					'sub_title' => 'JSF Item',
					'href'      => admin_url( 'admin.php?page=jsf-listing-builder#/edit-item/' . $listing->get_card_id() ),
				]
			);
		}
	}

	/**
	 * Init listing
	 */
	public function init_listing( $list_id ) {
		return new Listing_Base( $list_id );
	}

	/**
	 * Set current object
	 *
	 * @param mixed $object
	 */
	public function setup_query_object( $object ) {

		if ( ! is_object( $object ) ) {
			return;
		}

		$this->current_object = $object;

		// Set up the global post object if it's a WP_Post instance
		if ( $object instanceof \WP_Post ) {
			global $post;
			$post = $object;
			setup_postdata( $post );
		}

		do_action( 'jet-smart-filters/listing/render/setup-query-object', $object );
	}

	/**
	 * Get current object
	 *
	 * @return mixed
	 */
	public function get_query_object() {

		if ( ! is_object( $this->current_object ) ) {
			return null;
		}

		return $this->current_object;
	}

	/**
	 * Reset current query object
	 *
	 * This method resets the current query object and the global post object if applicable.
	 */
	public function reset_query_object() {

		if ( ! is_object( $this->current_object ) ) {
			return;
		}

		// Reset the global post object
		if ( $this->current_object instanceof \WP_Post ) {
			global $post;
			$post = null;
			wp_reset_postdata();
		}

		$this->current_object = null;

		do_action( 'jet-smart-filters/listing/render/reset-query-object' );
	}
}
