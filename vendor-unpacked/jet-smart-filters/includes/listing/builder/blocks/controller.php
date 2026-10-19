<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;
use Jet_Smart_Filters\Listing\Render\Listing_CSS;
use PgSql\Lob;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builder blocks class
 */
class Controller {

	protected $categories    = [];
	protected $allowed_block = [];

	public function __construct() {



		add_action(
			'jet-smart-filters/listing/before-editor-assets',
			[ Listing_Controller::instance()->style_manager, 'enqueue_editor_assets' ]
		);

		$this->categories = apply_filters( 'jet-smart-filters/listing/blocks-category', [
			[
				'slug'  => 'jsf-blocks',
				'title' => 'Jet Smart Filters Blocks',
				'icon'  => 'database-add'
			]
		] );

		$this->allowed_block = apply_filters( 'jet-smart-filters/listing/allowed-blocks', [
			'core/columns',
			'core/column',
			'core/spacer',
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/code',
			'core/image'
		] );

		$blocks = apply_filters( 'jet-smart-filters/listing/blocks', [
			jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/listing-field' ),
			jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/listing-image' ),
			jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/listing-link' ),
			jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/listing-terms' ),
			jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/listing-section' ),
		] );

		foreach ( $blocks as $block_path ) {
			$this->block_registration( $block_path );
		}
	}

	/**
	 * Get list of blocks categories
	 *
	 * @return Array Blocks categories
	 */
	public function get_categories( ) {
		return $this->categories;
	}

	/**
	 * Blocks category registration
	 *
	 * @return void
	 */
	public function category_registration( $slug, $title, $icon = null ) {

		$new_cat = [
			'slug'  => $slug,
			'title' => $slug,
		];

		if ( $icon ) {
			$new_cat['icon'] = $icon;
		}

		array_push( $this->categories, $new_cat );
	}

	/**
	 * Block registration
	 *
	 * @return void
	 */
	public function block_registration( $block_path ) {

		$block = register_block_type( $block_path );
		array_push( $this->allowed_block, $block->name );

		Listing_Controller::instance()->style_manager->register_block_support( $block->name );

		$this->load_block_styles(
			Listing_Controller::instance()->style_manager->get_block( $block->name )
		);
	}

	/**
	 * Load block styles
	 *
	 * @return void
	 */
	public function load_block_styles( $block_styles_stack ) {

		$block_name = $block_styles_stack->get_block_name();
		$block_name = explode( '/', $block_name );
		$block_name = end( $block_name );

		$styles_file = jet_smart_filters()->plugin_path(
			'includes/listing/builder/blocks/' . $block_name . '/styles.php'
		);

		if ( file_exists( $styles_file ) ) {

			require_once $styles_file;

			$class_name = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $block_name ) ) );
			$class_name = $class_name . '_Styles';
			$class_name = '\\Jet_Smart_Filters\\Listing\\Builder\\Blocks\\' . $class_name;

			if ( class_exists( $class_name ) ) {
				new $class_name( $block_styles_stack );
			}
		}
	}

	/**
	 * Get allowed block
	 *
	 * @return Array allowed block
	 */
	public function get_allowed_block() {
		return $this->allowed_block;
	}
}