<?php
/**
 * Angie compatibility package
 */

namespace Jet_Engine\Compatibility\Packages;

use Jet_Engine\MCP_Tools\Registry;

class Angie {

	protected $package_url = '';
	protected $package_path = '';

	public function __construct() {

		$this->package_url = jet_engine()->plugin_url( 'includes/compatibility/packages/angie/' );
		$this->package_path = jet_engine()->plugin_path( 'includes/compatibility/packages/angie/' );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function enqueue_scripts() {
		if ( ! \Jet_Engine\MCP_Tools\Registry::instance()->is_features_api_enabled() ) {
			return;
		}
		
		wp_enqueue_script(
			'jet-engine-compatibility-angie',
			$this->package_url . 'assets/js/croco-angie-mcp-server.umd.js',
			array(),
			jet_engine()->get_version(),
			true
		);

		wp_localize_script(
			'jet-engine-compatibility-angie',
			'jetEngineCompatibilityAngie',
			array(
				'features' => Registry::instance()->get_features_array(),
				'api_base' => trailingslashit( rest_url( 'jet-engine/v1/mcp-tools/run' ) ),
				'nonce'    => \Jet_Engine\MCP_Tools\Rest_API\Run_Controller::get_nonce(),
			)
		);
	}
}

new Angie();
