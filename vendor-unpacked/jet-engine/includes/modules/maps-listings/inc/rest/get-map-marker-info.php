<?php
namespace Jet_Engine\Modules\Maps_Listings;

/**
 * Get_Map_Marker_Info endpoint
 */
class Get_Map_Marker_Info extends \Jet_Engine_Base_API_Endpoint {

	/**
	 * Returns route name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'get-map-marker-info';
	}

	/**
	 * API callback
	 *
	 * @return void|\WP_Error|\WP_REST_Response
	 */
	public function callback( $request ) {

		$params     = $request->get_params();
		$listing_id = $params['listing_id'] ?? 0;
		$post_id    = $params['post_id'] ?? 0;

		if ( ! $listing_id || ! $post_id ) {
			return rest_ensure_response( array(
				'success' => false,
				'html'    => __( 'Required parameters is not found in request', 'jet-engine' ),
			) );
		}

		// For private listings or posts - also check user permissions.
		$listing_status  = get_post_status( $listing_id );
		$listing_source  = ( ! empty( $params['source'] ) && 'null' !== $params['source'] ) ? $params['source'] : 'posts';
		$source_instance = Module::instance()->sources->get_source( $listing_source );

		if ( ! $source_instance ) {
			return rest_ensure_response( array(
				'success' => false,
				'html'    => __( 'Unknown source. Please, check Map Listing settings.', 'jet-engine' ),
			) );
		}

		if ( ! $this->has_valid_query_context( $params, $listing_source ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'html'    => __( 'Invalid map marker request', 'jet-engine' ),
			) );
		}

		if ( in_array( $listing_status, array( 'private', 'draft', 'pending' ), true )
			|| ! $source_instance->is_item_published( $post_id )
		) {
			if ( ! $source_instance->is_item_accessible( $post_id )
				|| ! current_user_can( 'edit_post', $listing_id )
			) {
				return rest_ensure_response( array(
					'success' => false,
					'html'    => __( 'You do not have permissions to view this content', 'jet-engine' ),
				) );
			}
		}

		$this->maybe_apply_jet_smart_filters( $params );

		$queried_id = 0;

		// Set the current queried object.
		if ( ! empty( $params['queried_id'] ) ) {
			$queried_obj_data = explode( '|', $params['queried_id'] );
			$queried_id       = ! empty( $queried_obj_data[0] ) ? absint( $queried_obj_data[0] ) : false;
			$queried_class    = ! empty( $queried_obj_data[1] ) ? $queried_obj_data[1] : 'WP_Post';

			if ( $queried_id ) {
				jet_engine()->listings->data->set_current_object_by_id( $queried_id, $queried_class );
			}
		}

		// Set the current element id.
		$element_id = $params['element_id'] ?? '';

		jet_engine()->listings->data->set_listing_by_id( $listing_id );

		$post_obj = false;

		if ( $source_instance ) {
			$post_obj = $source_instance->get_obj_by_id( $post_id );
		}

		// For backward compatibility.
		$post_obj = apply_filters(
			'jet-engine/maps-listing/rest/object/' . $listing_source,
			$post_obj,
			$post_id
		);

		if ( ! $post_obj || is_wp_error( $post_obj ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'html'    => __( 'Requested post not found', 'jet-engine' ),
			) );
		}

		$additional_attrs = array();

		if ( isset( $params['geo_query_distance'] ) && $params['geo_query_distance'] >= 0 ) {
			$post_obj->geo_query_distance = $params['geo_query_distance'];
		}

		jet_engine()->frontend->set_listing( $listing_id );

		do_action( 'jet-engine/maps-listings/get-map-marker', $listing_id, $queried_id, $element_id );

		ob_start();

		$content = jet_engine()->frontend->get_listing_item( $post_obj );

		$additional_attrs = apply_filters( 'jet-engine/maps-listings/map-popup-additional-attrs', $additional_attrs, $params, $post_obj );

		$content = sprintf(
			'<div class="jet-map-popup-%1$s jet-listing-dynamic-post-%1$s" data-item-object="%1$s" data-render-type="jet-engine-maps" data-additional-map-popup-data="%3$s">%2$s</div>',
			$post_id,
			$content,
			! empty( $additional_attrs ) ? htmlspecialchars( json_encode( $additional_attrs ) ) : '{}'
		);
		$content = apply_filters( 'jet-engine/maps-listings/marker-content', $content, $post_obj, $listing_id );

		$content .= ob_get_clean();

		$result = array(
			'success' => true,
			'html'    => $content,
		);

		return rest_ensure_response( $result );

	}

	public function maybe_apply_jet_smart_filters( $params ) {
		if ( ! function_exists( 'jet_smart_filters' ) ) {
			return;
		}

		if ( false === strpos( $params['post_id'], '-' ) ) {
			return;
		}

		if ( ! empty( $params['jsf'] ) ) {
			$url = $params['jsf'];

			$server_uri = $_SERVER['REQUEST_URI'];

			global $wp;

			$_SERVER['REQUEST_URI'] = preg_replace(
				site_url(),
				'',
				$url
			);

			$wp->parse_request();
			$wp->query_posts();
			wp_reset_postdata();

			jet_smart_filters()->render->apply_filters_from_permalink( $wp );

			$_SERVER['REQUEST_URI'] = $server_uri;
		} elseif ( ! empty( $params['jsf_query'] ) ) {
			$query_id      = explode( '-', $params['post_id'] )[0];
			$query_manager = \Jet_Engine\Query_Builder\Manager::instance();
			$query         = $query_manager->get_query_by_id( $query_id );

			if ( ! $query ) {
				return;
			}

			$filters_manager = $query_manager->listings->filters;

			$filtered_query = $params['jsf_query'];
			$filtered_query = json_decode( $filtered_query, true );
			$filtered_query = jet_smart_filters()->query->get_query_from_request( $filtered_query );

			$filters_manager->set_filtered_props( $query, $filtered_query );
		}
	}

	/**
	 * Check that request-reachable Query Builder map sources are bound to the
	 * query context rendered by the original map instance.
	 *
	 * @param array  $params Request parameters.
	 * @param string $source Listing source.
	 * @return bool
	 */
	public function has_valid_query_context( $params, $source ) {

		$protected_sources = apply_filters(
			'jet-engine/maps-listings/rest/protected-query-sources',
			array( 'sql', 'rest_api', 'repeater' )
		);

		if ( ! in_array( $source, $protected_sources, true ) ) {
			return true;
		}

		$post_id_data = explode( '-', (string) ( $params['post_id'] ?? '' ) );
		$marker_query_id = ! empty( $post_id_data[0] ) ? absint( $post_id_data[0] ) : 0;
		$request_query_id = ! empty( $params['query_id'] ) ? absint( $params['query_id'] ) : 0;

		if ( ! $marker_query_id || ! $request_query_id || $marker_query_id !== $request_query_id ) {
			return false;
		}

		if ( ! $this->has_valid_source_query_type( $request_query_id, $source ) ) {
			return false;
		}

		$signature = ! empty( $params['query_signature'] ) ? (string) $params['query_signature'] : '';

		if ( ! $signature ) {
			return false;
		}

		$expected = $this->generate_query_context_signature(
			array(
				'context'    => 'map_marker_info',
				'listing_id' => absint( $params['listing_id'] ?? 0 ),
				'source'     => $source,
				'query_id'   => $request_query_id,
			)
		);

		return $expected && hash_equals( $expected, $signature );
	}

	/**
	 * Validate Query Builder query type for protected map sources.
	 *
	 * @param int    $query_id Query ID.
	 * @param string $source   Map source.
	 * @return bool
	 */
	protected function has_valid_source_query_type( $query_id, $source ) {

		$source_query_types = array(
			'sql'      => 'sql',
			'repeater' => 'repeater',
		);

		if ( empty( $source_query_types[ $source ] ) ) {
			return true;
		}

		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			return false;
		}

		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );

		return $query && ! empty( $query->query_type ) && $source_query_types[ $source ] === $query->query_type;
	}

	/**
	 * Generate signature for Query Builder map marker context.
	 *
	 * @param array $context Context payload.
	 * @return string
	 */
	protected function generate_query_context_signature( $context ) {
		if ( ! empty( jet_engine()->listings->ajax_handlers )
			&& method_exists( jet_engine()->listings->ajax_handlers, 'generate_signature' )
		) {
			return jet_engine()->listings->ajax_handlers->generate_signature( $context );
		}

		return '';
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
	 * This is public endpoint so it always accessible
	 *
	 * @return bool
	 */
	public function permission_callback( $request ) {
		return true;
	}

	/**
	 * Returns arguments config
	 *
	 * @return array
	 */
	public function get_args() {
		return array(
			'listing_id' => array(
				'default'  => 0,
				'required' => true,
			),
			'post_id' => array(
				'default'  => 0,
				'required' => true,
			),
			'source' => array(
				'default'  => 'posts',
				'required' => false,
			),
			'queried_id' => array(
				'default'  => '',
				'required' => false,
			),
			'geo_query_distance' => array(
				'default'  => -1,
				'required' => false,
			),
			'query_id' => array(
				'default'  => 0,
				'required' => false,
			),
			'query_signature' => array(
				'default'  => '',
				'required' => false,
			),
		);
	}

}
