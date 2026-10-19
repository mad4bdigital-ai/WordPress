<?php
namespace Jet_Smart_Filters\Listing\Helpers;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * H class
 */
class Controller {

	public $utils;
	public $requests;
	public $blocks_options;

	public function __construct() {

		require_once jet_smart_filters()->plugin_path( 'includes/listing/helpers/utils.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/helpers/requests.php' );
		require_once jet_smart_filters()->plugin_path( 'includes/listing/helpers/blocks-options.php' );

		$this->utils          = new Utils();
		$this->requests       = new Requests();
		$this->blocks_options = new Blocks_Options();
	}
}
