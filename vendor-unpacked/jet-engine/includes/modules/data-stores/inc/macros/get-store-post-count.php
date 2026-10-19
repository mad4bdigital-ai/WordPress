<?php
namespace Jet_Engine\Modules\Data_Stores\Macros;

use Jet_Engine\Modules\Data_Stores\Module;

class Get_Store_Post_Count extends \Jet_Engine_Base_Macros {

	/**
	 * @inheritDoc
	 */
	public function macros_tag() {
		return 'store_post_count';
	}

	/**
	 * @inheritDoc
	 */
	public function macros_name() {
		return esc_html__( 'Store item`s addition count', 'jet-engine' );
	}

	/**
	 * @inheritDoc
	 */
	public function macros_args() {
		return array(
			'store' => array(
				'label'   => __( 'Store', 'jet-engine' ),
				'type'    => 'select',
				'options' => Module::instance()->elementor_integration->get_store_options(),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function macros_callback( $args = array() ) {

		$store = ! empty( $args['store'] ) ? $args['store'] : false;

		if ( ! $store ) {
			return;
		}
		
		$object = $this->get_macros_object();
		
		$object_id = jet_engine()->listings->data->get_current_object_id( $object );
		
		return Module::instance()->render->post_count( $store,  $object_id );
	}
}
