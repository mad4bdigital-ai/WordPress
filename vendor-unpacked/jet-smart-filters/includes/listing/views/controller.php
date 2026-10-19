<?php

namespace Jet_Smart_Filters\Listing\Views;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main listing/modules class
 */
class Controller {

	protected $listing_instance;

	public function __construct() {

		$this->listing_instance = Listing_Controller::instance();

		$this->views_registration();
	}

	/**
	 * Views registration
	 */
	private function views_registration( ) {

		// blocks registration
		$block_listing_path = jet_smart_filters()->plugin_path( 'includes/listing/views/blocks' );
		register_block_type( $block_listing_path );

		add_action( 'enqueue_block_editor_assets', [ $this, 'blocks_assets' ] );
	}

	/**
	 * Register blocks assets
	 */
	public function blocks_assets() {

		// Register listing block assets
		$js_listing_block_assets = include jet_smart_filters()->plugin_path( 'includes/listing/render/assets/js/jsf-listing-block.asset.php' );

		wp_enqueue_script(
			'jet-smart-filters-listing-block',
			jet_smart_filters()->plugin_url( 'includes/listing/render/assets/js/jsf-listing-block.js' ),
			$js_listing_block_assets['dependencies'],
			$js_listing_block_assets['version'],
			true
		);

		$localized_data = apply_filters( 'jet-smart-filters/listing/listing-block-localized-data', [
			'listings_options' => $this->listing_instance->helpers->blocks_options->get_listings( [], false )
		] );

		wp_localize_script( 'jet-smart-filters-listing-block', 'JetSmartFilterListingBlockData', $localized_data );
	}
}
