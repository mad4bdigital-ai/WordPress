<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Blocks {

	public function __construct() {

		add_action(
			'jet-smart-filters/listing/render/setup-query-object',
			[ $this, 'set_jet_engine_object' ]
		);

		add_action(
			'jet-smart-filters/listing/render/reset-query-object',
			[ $this, 'reset_jet_engine_object' ]
		);

		add_filter(
			'jet-smart-filters/listing/blocks-category',
			[ $this, 'add_jet_engine_blocks_category' ]
		);

		add_filter(
			'jet-smart-filters/listing/allowed-blocks',
			[ $this, 'allow_jet_engine_dynamic_blocks' ]
		);

		add_action(
			'jet-smart-filters/listing/editor-assets',
			[ $this, 'enqueue_jet_engine_blocks_assets' ]
		);

		add_action(
			'jet-engine/blocks-views/render-block-preview',
			[ $this, 'setup_preview' ], 0, 2
		);
	}

	/**
	 * Reset JetEngine query builder's current object reference.
	 *
	 * @return void
	 */
	public function set_jet_engine_object( $object ) {
		if ( is_object( $object ) ) {
			jet_engine()->listings->data->set_current_object( $object );
		}
	}

	/**
	 * Reset JetEngine query builder's current object reference.
	 *
	 * @return void
	 */
	public function reset_jet_engine_object() {
		jet_engine()->listings->data->reset_current_object();
	}

	/**
	 * Add a custom category for JetEngine blocks in listing builder.
	 *
	 * @param array $categories Existing block categories.
	 *
	 * @return array Modified block categories including JetEngine category.
	 */
	public function add_jet_engine_blocks_category( $categories = [] ) {

		$categories[] = [
			'slug'  => 'jet-engine',
			'title' => 'Jet Engine Blocks',
			'icon'  => 'database-add'
		];

		return $categories;
	}

	/**
	 * Setup block preview for JetEngine dynamic blocks in listing builder.
	 *
	 * @param object $block      Block object.
	 * @param array  $attributes Block attributes.
	 *
	 * @return void
	 */
	public function setup_preview( $block, $attributes ) {

		$referer = wp_get_referer();

		if ( ! $referer
			|| false === strpos( $referer, 'page=' . Listing_Controller::instance()->listing_key )
		) {
			return;
		}

		switch ( $block->get_name() ) {
			case 'dynamic-field':
				require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/dynamic-field-preview.php' );
				new Dynamic_Field_Preview( $attributes );
				break;
			case 'dynamic-image':
				require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/dynamic-image-preview.php' );
				new Dynamic_Image_Preview( $attributes );
				break;
			case 'dynamic-terms':
				require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/dynamic-terms-preview.php' );
				new Dynamic_Terms_Preview( $attributes );
				break;
			case 'dynamic-repeater':
				require_once jet_smart_filters()->plugin_path( 'includes/compatibility/jet-engine/listing/dynamic-repeater-preview.php' );
				new Dynamic_Repeater_Preview( $attributes );
				break;
			case 'dynamic-link':
				// Can be previewed without additional modifications
				break;
		}
	}

	/**
	 * Allow JetEngine dynamic blocks in listing builder.
	 *
	 * @param array $allowed_blocks List of allowed blocks.
	 *
	 * @return array
	 */
	public function allow_jet_engine_dynamic_blocks( $allowed_blocks = [] ) {

		$allowed_blocks[] = 'jet-engine/dynamic-field';
		$allowed_blocks[] = 'jet-engine/dynamic-link';
		$allowed_blocks[] = 'jet-engine/dynamic-image';
		$allowed_blocks[] = 'jet-engine/dynamic-repeater';
		$allowed_blocks[] = 'jet-engine/dynamic-terms';

		return $allowed_blocks;
	}

	/**
	 * Enqueue assets for JetEngine blocks in listing builder.
	 *
	 * @return void
	 */
	public function enqueue_jet_engine_blocks_assets() {

		if ( ! isset( jet_engine()->blocks_views ) || ! isset( jet_engine()->blocks_views->editor ) ) {
			return;
		}

		wp_enqueue_script( 'underscore' );
		wp_enqueue_script( 'wp-server-side-render' );
		wp_enqueue_script( 'block-editor' );

		jet_engine()->blocks_views->editor->blocks_scripts();
		jet_engine()->blocks_views->editor->blocks_styles();
	}
}