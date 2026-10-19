<?php
namespace Jet_Smart_Filters\Listing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main listing class
 */
class Controller {

	/**
	 * A reference to an instance of this class.
	 */
	private static $instance = null;

	public $listing_key = 'jsf-listing-builder';

	public $builder;
	public $storage;
	public $helpers;
	public $views;
	public $render;
	public $modules;
	public $style_manager;

	public function __construct() {

		if ( ! apply_filters( 'jet-smart-filters/listing/enabled', true ) ) {
			return;
		}

		add_action( 'init', [ $this, 'init_components' ] );
	}

	/**
	 * Initialize components
	 *
	 * @return void
	 */
	public function init_components() {

		$module_data = jet_smart_filters()->framework->get_included_module_data( 'style-manager.php' );

		$this->style_manager = new \Crocoblock\Blocks_Style\Manager( [
			'path' => $module_data['path'],
			'url'  => $module_data['url'],
		] );

		require jet_smart_filters()->plugin_path( 'includes/listing/storage/controller.php' );
		require jet_smart_filters()->plugin_path( 'includes/listing/helpers/controller.php' );
		require jet_smart_filters()->plugin_path( 'includes/listing/views/controller.php' );
		require jet_smart_filters()->plugin_path( 'includes/listing/modules/controller.php' );
		require jet_smart_filters()->plugin_path( 'includes/listing/builder/controller.php' );
		require jet_smart_filters()->plugin_path( 'includes/listing/render/controller.php' );

		$this->storage = new Storage\Controller();
		$this->helpers = new Helpers\Controller();
		$this->views   = new Views\Controller();
		$this->modules = new Modules\Controller();
		$this->builder = new Builder\Controller();
		$this->render  = new Render\Controller();
	}

	/**
	 * Returns the instance.
	 */
	public static function instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
}
