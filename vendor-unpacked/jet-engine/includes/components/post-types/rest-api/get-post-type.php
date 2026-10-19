<?php
/**
 * Add/Update post type endpoint
 */

class Jet_Engine_CPT_Rest_Get_Post_Type extends Jet_Engine_Base_API_Endpoint {

	/**
	 * Returns route name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'get-post-type';
	}

	public function safe_get( $args = array(), $group = '', $key = '', $default = false ) {
		return isset( $args[ $group ][ $key ] ) ? $args[ $group ][ $key ] : $default;
	}

	/**
	 * API callback
	 *
	 * @return void
	 */
	public function callback( $request ) {

		$params = $request->get_params();
		$id     = isset( $params['id'] ) ? intval( $params['id'] ) : false;

		if ( ! $id ) {

			jet_engine()->cpt->add_notice(
				'error',
				__( 'Item ID not found in request', 'jet-engine' )
			);

			return rest_ensure_response( array(
				'success' => false,
				'notices' => jet_engine()->cpt->get_notices(),
			) );

		}

		$post_type_data = jet_engine()->cpt->data->get_item_for_edit( $id );

		if ( ! $post_type_data ) {

			jet_engine()->cpt->add_notice(
				'error',
				__( 'Post type not found', 'jet-engine' )
			);

			return rest_ensure_response( array(
				'success' => false,
				'notices' => jet_engine()->cpt->get_notices(),
			) );

		}

		if ( empty( $post_type_data['labels'] ) ) {
			$post_type_data['labels']['singular_name'] = '';
		}

		$table_notice = false;

		if ( ! empty( $this->safe_get( $post_type_data, 'general_settings', 'custom_storage' ) ) ) {
			$slug = $this->safe_get( $post_type_data, 'general_settings', 'slug' );
			$fields = \Jet_Engine\CPT\Custom_Tables\Manager::instance()->prepare_fields( $post_type_data['meta_fields'], $slug )['as_columns'];
			$db = \Jet_Engine\CPT\Custom_Tables\Manager::instance()->get_db_instance( $slug, $fields );
			$table = $db->table();
			$table_exists = $table === $db::wpdb()->get_var( "SHOW TABLES LIKE '$table'" );
			$schema_valid = $db->has_columns_by_schema();

			if ( ! $table_exists ) {
				$table_notice = esc_html__( 'DB table does not exist. Please, check the field names for MySQL reserved words and update this CPT.', 'jet-engine' );
			} elseif( ! $schema_valid ) {
				$table_notice = esc_html__( 'DB schema does not correspond to CPT settings. Please, check the field names for MySQL reserved words and update this CPT.', 'jet-engine' );
			}
		}

		return rest_ensure_response( array(
			'success'      => true,
			'data'         => $post_type_data,
			'table_notice' => $table_notice,
		) );

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

	/**
	 * Get query param. Regex with query parameters
	 *
	 * @return string
	 */
	public function get_query_params() {
		return '(?P<id>[\d]+)';
	}

}