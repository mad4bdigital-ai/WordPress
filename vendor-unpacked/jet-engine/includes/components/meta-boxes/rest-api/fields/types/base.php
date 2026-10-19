<?php
/**
 * Register post meta field for Rest API
 */

namespace Jet_Engine\REST_API_Fields;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

abstract class Base {

	protected $rest_meta;
	protected $object_type;
	protected $object_subtype;
	protected $field;

	protected $data = array();

	/**
	 * @param \Jet_Engine_Rest_Post_Meta $rest_meta
	 */
	public function __construct( $rest_meta ) {
		$this->field = $rest_meta->field;

		if ( empty( $this->field['name'] ) ) {
			return;
		}

		$this->rest_meta      = $rest_meta;
		$this->object_type    = $rest_meta->get_object_type();
		$this->object_subtype = $rest_meta->object_subtype ?? false;

		$this->init();
	}

	public function is_rest() {
		return \Jet_Engine_Tools::wp_doing_rest();
	}

	public function set_data( $name, $value ) {
		$this->data[ $name ] = $value;
	}

	public function get_data( $name ) {
		return $this->data[ $name ] ?? null;
	}

	abstract protected function init();
}
