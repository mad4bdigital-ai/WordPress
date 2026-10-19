<?php
/**
 * Data class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Render' ) ) {
	/**
	 * Define Jet_Smart_Filters_Render class
	 */
	class Jet_Smart_Filters_Render {

		private $_rendered_providers = array();
		private $request_query_vars  = array(
			'tax',
			'meta',
			'date',
			'sort',
			'alphabet',
			'_s',
			'search',
			'_sm',
			'search-by-meta',
			'pagenum',
			'plain_query',
		);

		public $use_signature_verification = false;

		/**
		 * Constructor for the class
		 */
		public function __construct() {

			$this->use_signature_verification = filter_var( jet_smart_filters()->settings->get( 'use_signature_verification', false ), FILTER_VALIDATE_BOOLEAN );

			add_action( 'parse_request', array( $this, 'apply_filters_from_request' ) );
			add_action( 'parse_request', array( $this, 'apply_filters_from_permalink' ) );

			// backward compatibility
			add_action( 'parse_request', array( $this, 'apply_filters_from_request_backward_compatibility' ) );

			add_action( 'wp_ajax_jet_smart_filters', array( $this, 'ajax_apply_filters' ) );
			add_action( 'wp_ajax_nopriv_jet_smart_filters', array( $this, 'ajax_apply_filters' ) );

			add_action( 'wp_ajax_jet_smart_filters_get_hierarchy_level', array( $this, 'hierarchy_level' ) );
			add_action( 'wp_ajax_nopriv_jet_smart_filters_get_hierarchy_level', array( $this, 'hierarchy_level' ) );

			add_action( 'wp_ajax_jet_smart_filters_get_indexed_data', array( $this, 'get_indexed_data' ) );
			add_action( 'wp_ajax_nopriv_jet_smart_filters_get_indexed_data', array( $this, 'get_indexed_data' ) );
		}

		public function get_request_query_vars() {
			return apply_filters( 'jet-smart-filters/render/query-vars', $this->request_query_vars );
		}

		/**
		 * Update hierarchy levels starting from depth
		 */
		public function hierarchy_level() {

			$request   = jet_smart_filters()->data->get_request();

			$depth     = isset( $request['depth'] ) ? absint( $request['depth'] ) : false;
			$filter_id = isset( $request['filter_id'] ) ? absint( $request['filter_id'] ) : 0;

			if ( ! $filter_id ) {
				wp_send_json_error();
			}

			$values  = ! empty( $request['values'] ) ? $request['values'] : array();
			$args    = ! empty( $request['args'] ) ? $request['args'] : array();

			require jet_smart_filters()->plugin_path( 'includes/hierarchy.php' );

			$hierarchy = new Jet_Smart_Filters_Hierarchy(
				$filter_id,
				$depth,
				$values,
				$args
			);

			wp_send_json_success( $hierarchy->get_levels() );
		}

		/**
		 * Get indexed data
		 */
		public function get_indexed_data() {

			$request          = jet_smart_filters()->data->get_request();

			$provider_key     = isset( $request['provider'] ) ? $request['provider'] : false;
			$indexing_filters = isset( $request['indexing_filters'] ) ? json_decode( stripcslashes( $request['indexing_filters'] ), true ) : false;
			$query_args       = isset( $request['query_args'] ) ? $request['query_args'] : array();

			if ( ! ( $provider_key && $indexing_filters ) ) {
				return;
			}

			foreach ( $indexing_filters as $filter_id ) {
				jet_smart_filters()->indexer->data->add_indexing_data_from_filter( $provider_key, $filter_id );
			}

			$indexed_data = jet_smart_filters()->indexer->data->get_indexed_data($provider_key, $query_args);

			wp_send_json_success( $indexed_data );
		}

		/**
		 * Returns requested provider ID
		 */
		public function request_provider( $return = null ) {

			return jet_smart_filters()->query->get_current_provider( $return );
		}

		/**
		 * Apply filters form REQUEST parameters.
		 */
		public function apply_filters_from_request() {

			$request = jet_smart_filters()->data->get_request();

			if ( empty( $request['jsf'] ) ) {
				return;
			}

			$provider_name = ! empty( $request['provider'] )
				? $request['provider']
				: $request['jsf'];

			if ( ! is_string( $provider_name ) ) {
				return;
			}

			jet_smart_filters()->query->set_provider_from_request( $provider_name );

			$provider_id = $this->request_provider( 'provider' );
			$provider    = jet_smart_filters()->providers->get_providers( $provider_id );

			if ( ! $provider || ! is_callable( array( $provider, 'apply_filters_in_request' ) ) ) {
				return;
			}

			foreach ( $this->get_request_query_vars() as $query_var ) {
				if ( ! isset( $request[ $query_var ] ) || ! jet_smart_filters()->utils->is_truthy_or_zero( $request[ $query_var ] ) ) {
					continue;
				}

				jet_smart_filters()->query->set_query_var_to_request( $query_var, $request[ $query_var ] );
			}

			jet_smart_filters()->query->get_query_from_request();
			$provider->apply_filters_in_request();
		}

		/**
		 * Apply filters form url permalink.
		 */
		public function apply_filters_from_permalink( $query ) {

			$request = jet_smart_filters()->data->get_request();

			if ( empty( $query->query_vars['jsf'] ) || isset( $request['jsf'] ) ) {
				return;
			}

			if ( apply_filters( 'jet-smart-filters/render/filters-applied', false, $query ) ) {
				return;
			}

			$jsf_query_str = $query->query_vars['jsf'];

			$_REQUEST['jsf'] = strtok( $jsf_query_str, '/' );

			foreach ( $this->get_request_query_vars() as $query_var ) {
				preg_match_all( "/\/$query_var\/([^\/]*)/", $jsf_query_str, $matches );

				if ( ! isset( $matches[1][0] ) || ! jet_smart_filters()->utils->is_truthy_or_zero( $matches[1][0] ) ) {
					continue;
				}

				$_REQUEST[ $query_var ] = apply_filters(
					'jet-smart-filters/render/set-query-var',
					urldecode( $matches[1][0] ),
					$query_var,
					$this
				);
			}

			$this->apply_filters_from_request();
		}

		/**
		 * Apply filters form REQUEST parameters backward compatibility.
		 */
		public function apply_filters_from_request_backward_compatibility() {

			$jsf_request_val = jet_smart_filters()->data->get_request_var( 'jet-smart-filters' );

			if ( ! $jsf_request_val ) {
				return;
			}

			$provider_id = $this->request_provider( 'provider' );
			$provider    = jet_smart_filters()->providers->get_providers( $provider_id );

			if ( ! $provider ) {
				return;
			}

			if ( is_callable( array( $provider, 'apply_filters_in_request' ) ) ) {
				jet_smart_filters()->query->get_query_from_request();
				$provider->apply_filters_in_request();
			}
		}

		/**
		 * Verify request signature.
		 * Request signature made on provider settings store.
		 * It helps to prevent from injecting any 3rd party data into the request.
		 *
		 * @return [type] [description]
		 */
		public function verify_request_signature() {

			$request = jet_smart_filters()->data->get_request();
			$result  = false;

			if ( ! empty( $request['settings']['jsf_signature'] ) ) {

				$request_signature = $request['settings']['jsf_signature'];
				unset( $request['settings']['jsf_signature'] );
				$request_settings = $this->prepare_provider_settings_for_signature( $request['settings'], $this->request_provider( 'provider' ) );
				$check_signature = $this->create_signature( $request_settings );
				$result = ( $check_signature === $request_signature ) ? true : false;

			} elseif ( empty( $request['settings'] ) ) {

				// if settings completely empty - they're cannot be hacked
				$result = true;

			}

			return apply_filters( 'jet-smart-filters/render/ajax/verify-signature', $result );
		}

		/**
		 * Create signature based on input array
		 *
		 * @return [type] [description]
		 */
		public function create_signature( $data = [] ) {

			$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
			$data = $this->prepare_data_for_sign( $data );
			$signature_string = json_encode( $data );

			return md5( $signature_string . $secret );

		}

		/**
		 * Prepare provider settings before creating request signature.
		 */
		public function prepare_provider_settings_for_signature( $settings = array(), $provider_id = false ) {

			if ( ! is_array( $settings ) || ! $provider_id ) {
				return $settings;
			}

			$provider = jet_smart_filters()->providers->get_providers( $provider_id );

			if ( $provider && is_callable( array( $provider, 'prepare_settings_for_signature' ) ) ) {
				return $provider->prepare_settings_for_signature( $settings );
			}

			return $settings;
		}

		/**
		 * Prepare data for signature to ensure consistency
		 *
		 * @param  array  $data [description]
		 * @return [type]       [description]
		 */
		public function prepare_data_for_sign( $data = [] ) {

			$prepared_data = [];

			foreach ( $data as $key => $value ) {

				if ( is_array( $value ) && ! empty( $value ) ) {
					$prepared_data[ $key ] = $this->prepare_data_for_sign( $value );
				} elseif ( ! is_array( $value ) ) {

					// convert booleans into strings manually
					if ( false === $value ) {
						$value = 'false';
					} elseif ( true === $value ) {
						$value = 'true';
					}

					$prepared_data[ $key ] = (string) $value;
				}
			}

			return array_filter( $prepared_data );
		}

		/**
		 * Apply filters in AJAX request
		 */
		public function ajax_apply_filters() {

			$request     = jet_smart_filters()->data->get_request();

			$provider_id = $this->request_provider( 'provider' );
			$query_id    = $this->request_provider( 'query_id' );
			$apply_type  = ! empty( $request['apply_type'] ) ? $request['apply_type'] : 'ajax';
			$provider    = $provider_id ? jet_smart_filters()->providers->get_providers( $provider_id ) : false;

			if ( ! $provider || ! is_object( $provider ) ) {
				wp_send_json_error( __( 'Provider not found.', 'jet-smart-filters' ) );
			}

			do_action( 'jet-smart-filters/render/ajax/before', $this, $provider_id, $query_id, $provider );

			jet_smart_filters()->query->get_query_from_request();

			// verify ajax request signature
			if ( $this->use_signature_verification && ! $this->verify_request_signature( $request ) ) {
				wp_send_json_error( __( 'Request data is incorrect.', 'jet-smart-filters' ) );
			}

			if ( ! empty( $request['props'] ) ) {

				jet_smart_filters()->query->set_props(
					$provider_id,
					$request['props'],
					$query_id
				);
			}

			$args = array(
				'content'    => $this->render_content( $provider ),
				'pagination' => jet_smart_filters()->query->get_current_query_props()
			);

			if ( is_callable( array( $provider, 'is_data' ) ) && $provider->is_data() ) {
				$args['is_data'] = 1;
			}

			$args = apply_filters( 'jet-smart-filters/render/ajax/data', $args );

			if ( ! headers_sent() ) {
				nocache_headers();
			}

			wp_send_json( $args );
		}

		/**
		 * Render content
		 */
		public function render_content( $provider ) {

			ob_start();

			if ( is_callable( array( $provider, 'ajax_get_content' ) ) ) {
				$provider->ajax_get_content();
			} else {
				esc_html_e( 'Incorrect input data', 'jet-smart-filters' );
			}

			return ob_get_clean();
		}
	}
}
