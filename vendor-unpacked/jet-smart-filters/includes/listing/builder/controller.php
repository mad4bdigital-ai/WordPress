<?php
namespace Jet_Smart_Filters\Listing\Builder;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main listing/storage class
 */
class Controller {

	public $blocks;
	public $style_manager;

	protected $action_key;
	protected $requests;
	protected $actions    = [];
	protected $error      = null;

	public function __construct() {

		$this->action_key = Listing_Controller::instance()->listing_key;
		$this->requests   = Listing_Controller::instance()->helpers->requests;

		$this->actions = [
			'get_listings'        => [ $this->requests, 'get_listings' ],
			'get_listing'         => [ $this->requests, 'get_listing' ],
			'save_listing'        => [ $this->requests, 'save_listing' ],
			'remove_listing'      => [ $this->requests, 'remove_listing' ],
			'get_items'           => [ $this->requests, 'get_items' ],
			'get_listing_item'    => [ $this->requests, 'get_listing_item' ],
			'save_listing_item'   => [ $this->requests, 'save_listing_item' ],
			'remove_listing_item' => [ $this->requests, 'remove_listing_item' ],
			'get_cards'           => [ $this->requests, 'get_cards' ],
			'get_users'           => [ $this->requests, 'get_users' ],
			'get_posts_list'      => [ $this->requests, 'get_posts_list' ],
			'get_terms_list'      => [ $this->requests, 'get_terms_list' ],
			'get_jet_engine_queries' => [ $this->requests, 'get_jet_engine_queries' ],
		];

		foreach ( $this->actions as $action => $callback ) {
			$res = add_action( 'wp_ajax_' . $this->action_key . '_' . $action, $callback );
		}

		require jet_smart_filters()->plugin_path( 'includes/listing/builder/blocks/controller.php' );
		$this->blocks = new Blocks\Controller();

		require jet_smart_filters()->plugin_path( 'includes/listing/builder/view.php' );
		new View();
	}

	/**
	 * Return value of $actions prop
	 *
	 * @return string
	 */
	public function actions() {
		return $this->actions;
	}
}
