<?php
/**
 * Add/Update post type endpoint
 */

class Jet_Engine_CPT_Rest_Get_Taxonomies extends Jet_Engine_Base_API_Endpoint {

	/**
	 * Returns route name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'get-taxonomies';
	}

	/**
	 * API callback
	 *
	 * @return void
	 */
	public function callback( $request ) {

		$taxonomies = jet_engine()->taxonomies->data->get_items();
		$taxonomies = array_map( array( $this, 'prepare_tax' ), $taxonomies );

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $taxonomies,
		) );

	}

	/**
	 * Prepare post type item to return
	 *
	 * @param  array $item Item data
	 * @return array
	 */
	public function prepare_tax( $item ) {

		$item = jet_engine_prepare_serialized_row(
			$item,
			array(
				'labels'      => array(),
				'args'        => array(),
				'object_type' => array(),
				'meta_fields' => array(),
			),
			'taxonomies_rest'
		);

		return $item;
	}

	/**
	 * Returns endpoint request method - GET/POST/PUT/DELTE
	 *
	 * @return string
	 */
	public function get_method() {
		return 'GET';
	}

	/**
	 * Check user access to current end-popint
	 *
	 * @return bool
	 */
	public function permission_callback( $request ) {
		return current_user_can( 'manage_options' );
	}

}
