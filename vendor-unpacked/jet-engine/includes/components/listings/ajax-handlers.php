<?php
/**
 * Class description
 *
 * @package   package_name
 * @author    Cherry Team
 * @license   GPL-2.0+
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Engine_Listings_Signatures' ) ) {
	require_once jet_engine()->plugin_path( 'includes/components/listings/signatures.php' );
}

if ( ! class_exists( 'Jet_Engine_Listings_Ajax_Handlers' ) ) {

	class Jet_Engine_Listings_Ajax_Handlers {

		/**
		 * Signature helper instance.
		 *
		 * @var \Jet_Engine_Listings_Signatures
		 */
		protected $signatures = null;

		/**
		 * Whether settings were resolved from a server-side builder document.
		 *
		 * @var bool
		 */
		protected $has_server_widget_settings = false;

		public function __construct() {

			$this->signatures = new Jet_Engine_Listings_Signatures();

			// Additional check to avoid problem if class initiailized directly somewhere
			if ( ! $this->is_listing_ajax() ) {
				return;
			}

			add_action( 'parse_request', array( $this, 'setup_front_referrer' ) );

			add_action( 'wp_ajax_jet_engine_ajax',        array( $this, 'handle_ajax' ) );
			add_action( 'wp_ajax_nopriv_jet_engine_ajax', array( $this, 'handle_ajax' ) );
		}

		/**
		 * Check if is AJAX listing request
		 * Need to duplicate it there because to avoid conflicts with class calling stack
		 */
		public function is_listing_ajax( $handler = false ) {

			// phpcs:disable
			$is_listing_ajax = (
				( ! empty( $_REQUEST['action'] ) && 'jet_engine_ajax' === $_REQUEST['action'] && ! empty( $_REQUEST['handler'] ) )
				|| ! empty( $_REQUEST['jet_engine_action'] )
			);

			if ( $is_listing_ajax && ! empty( $handler ) ) {
				return ( ! empty( $_REQUEST['handler'] ) && $handler === $_REQUEST['handler'] );
			}
			// phpcs:enable

			return $is_listing_ajax;
		}

		/**
		 * Setup front referrer
		 *
		 * @param  [type] $wp [description]
		 * @return [type]     [description]
		 */
		public function setup_front_referrer( $wp ) {

			$wp->query_posts();
			$wp->register_globals();

			define( 'DOING_AJAX', true );

			do_action( 'jet-engine/ajax-handlers/referrer/request' );

			$action = 'jet_engine_ajax';

			// phpcs:disable
			if ( ! empty( $_REQUEST['jet_engine_action'] ) ) {
				$action = sanitize_text_field( $_REQUEST['jet_engine_action'] );
			}

			// `admin-ajax.php` does not fire `parse_request`. The standard listing
			// AJAX action resolves settings and dispatches its pre-AJAX hooks in
			// handle_ajax(), after the widget configuration is trusted.
			if ( 'jet_engine_ajax' !== $action ) {
				$this->add_settings_to_request();

				do_action( 'jet-engine/ajax-handlers/before-do-ajax', $this, $_REQUEST );
			}
			// phpcs:enable

			if ( is_user_logged_in() ) {
				do_action( 'wp_ajax_' . $action );
			} else {
				do_action( 'wp_ajax_nopriv_' . $action );
			}

			die();
		}

		/**
		 * Handle AJAX request
		 *
		 * @return void
		 */
		public function handle_ajax() {

			// phpcs:disable
			if ( ! isset( $_REQUEST['handler'] ) || ! is_callable( array( $this, $_REQUEST['handler'] ) ) ) {
				return;
			}

			$allowed_handlers = array(
				'listing_load_more',
				'get_listing',
			);

			if ( ! in_array( $_REQUEST['handler'], $allowed_handlers, true ) ) {
				wp_send_json_error( __( 'Invalid handler requested', 'jet-engine' ) );
			}

			$this->apply_page_settings_to_request();

			if ( ! $this->prepare_listing_settings_request() ) {
				wp_send_json_error( __( 'Invalid listing configuration', 'jet-engine' ) );
			}

			do_action( 'jet-engine/ajax-handlers/before-do-ajax', $this, $_REQUEST );
			do_action( 'jet-engine/ajax-handlers/before-call-handler', $this, $_REQUEST );

			// Call the requested handler only if it allowed to call
			call_user_func( array( $this, $_REQUEST['handler'] ) );
			// phpcs:enable
		}

		/**
		 * Add parsed settings to the global request
		 *
		 * @return bool Whether settings were resolved from a server-side document.
		 */
		public function add_settings_to_request() {

			$settings = ! empty( $_REQUEST['page_settings'] ) ? $_REQUEST['page_settings'] : $_REQUEST; // phpcs:ignore

			$query            = ! empty( $settings['query'] ) ? $settings['query'] : array();
			$post_id          = ! empty( $settings['post_id'] ) ? absint( $settings['post_id'] ) : false;
			$queried_obj_data = ! empty( $settings['queried_id'] ) ? explode( '|', $settings['queried_id'] ) : false;
			$queried_id       = ! empty( $queried_obj_data[0] ) ? absint( $queried_obj_data[0] ) : false;
			$queried_obj_type = ! empty( $queried_obj_data[1] ) ? $queried_obj_data[1] : 'WP_Post';
			$element_id       = ( ! empty( $settings['element_id'] ) && 'false' !== $settings['element_id'] ) ? $settings['element_id'] : false;

			if ( $queried_id && 'WP_Post' === $queried_obj_type ) {
				global $post;
				$post = get_post( $queried_id );
			}

			if ( $queried_id ) {
				jet_engine()->listings->data->set_current_object_by_id( $queried_id, $queried_obj_type );
			}

			$widget_settings = false;

			if ( $post_id && $element_id ) {

				// Safe: $_REQUEST data is used only for the internal comparison
				$listing_type = ( isset( $_REQUEST['listing_type'] ) && 'false' !== $_REQUEST['listing_type'] ) ? $_REQUEST['listing_type'] : false; // phpcs:ignore

				// Elementor-only legacy code
				if ( jet_engine()->has_elementor() && ( ! $listing_type || 'elementor' === $listing_type ) ) {

					$elementor = \Elementor\Plugin::instance();
					$document = $elementor->documents->get( $post_id );

					if ( $document ) {
						$widget = $this->find_element_recursive( $document->get_elements_data(), $element_id );

						if ( $widget ) {
							$widget_instance = $elementor->elements_manager->create_element_instance( $widget );
							$widget_settings = $widget_instance->get_settings_for_display();
							$widget_settings['_id'] = $element_id;
						}
					}

				} elseif ( $listing_type ) {

					$widget_settings = apply_filters(
						'jet-engine/listings/ajax/settings-by-id/' . $listing_type,
						array(),
						$element_id,
						$post_id
					);

					if ( empty( $widget_settings['columns_mobile'] ) ) {
						$widget_settings['columns_mobile'] = 1;
					}
				}
			}

			if ( $widget_settings ) {
				$_REQUEST['widget_settings'] = $widget_settings;
				$this->has_server_widget_settings = true;
			}

			return $this->has_server_widget_settings;
		}

		/**
		 * Copy frontend page context before resolving trusted widget settings.
		 *
		 * @return void
		 */
		protected function apply_page_settings_to_request() {

			if ( empty( $_REQUEST['page_settings'] ) || ! is_array( $_REQUEST['page_settings'] ) ) {
				return;
			}

			foreach ( $_REQUEST['page_settings'] as $key => $value ) {
				$_REQUEST[ $key ] = $value;
			}
		}

		/**
		 * Ensure AJAX handlers never render with unsigned client widget settings.
		 *
		 * A page/element lookup is preferred because it always reloads the saved
		 * builder configuration. Some integrations have no resolvable element, so
		 * they may replay only a complete settings payload signed during rendering.
		 *
		 * @return bool
		 */
		public function prepare_listing_settings_request() {

			if ( ! $this->has_valid_listing_request_context() ) {
				return false;
			}

			if ( $this->has_server_widget_settings || $this->add_settings_to_request() ) {
				return true;
			}

			if ( $this->can_use_editor_widget_settings() ) {
				return true;
			}

			$widget_settings = ! empty( $_REQUEST['widget_settings'] ) && is_array( $_REQUEST['widget_settings'] )
				? $_REQUEST['widget_settings']
				: array(); // phpcs:ignore

			if ( ! $this->signatures->settings_have_valid_signature( $widget_settings ) ) {
				return false;
			}

			// Match the canonical server-rendered value after WordPress request slashing.
			$_REQUEST['widget_settings'] = wp_unslash( $widget_settings ); // phpcs:ignore

			return true;
		}

		/**
		 * Elementor editor previews may render unsaved settings before they exist in
		 * the server-side document. Keep that behavior for users who can edit the
		 * requested document; public requests must always use trusted settings.
		 *
		 * @return bool
		 */
		protected function can_use_editor_widget_settings() {

			if ( empty( $_REQUEST['isEditMode'] ) || ! filter_var( $_REQUEST['isEditMode'], FILTER_VALIDATE_BOOLEAN ) ) { // phpcs:ignore
				return false;
			}

			$settings = ! empty( $_REQUEST['page_settings'] ) && is_array( $_REQUEST['page_settings'] ) ? $_REQUEST['page_settings'] : $_REQUEST; // phpcs:ignore
			$post_id  = ! empty( $settings['post_id'] ) ? absint( $settings['post_id'] ) : 0;

			return $post_id && current_user_can( 'edit_post', $post_id );
		}

		/**
		 * Ensure request context cannot be used to load a non-public document or post.
		 *
		 * @return bool
		 */
		protected function has_valid_listing_request_context() {

			$settings          = ! empty( $_REQUEST['page_settings'] ) && is_array( $_REQUEST['page_settings'] ) ? $_REQUEST['page_settings'] : $_REQUEST; // phpcs:ignore
			$post_id           = ! empty( $settings['post_id'] ) ? absint( $settings['post_id'] ) : 0;
			$queried_obj_data  = ! empty( $settings['queried_id'] ) ? explode( '|', $settings['queried_id'] ) : array();
			$queried_id        = ! empty( $queried_obj_data[0] ) ? absint( $queried_obj_data[0] ) : 0;
			$queried_obj_type  = ! empty( $queried_obj_data[1] ) ? $queried_obj_data[1] : 'WP_Post';

			if ( $post_id && ! $this->can_access_listing_request_post( $post_id ) ) {
				return false;
			}

			if ( $queried_id && 'WP_Post' === $queried_obj_type && ! $this->can_access_listing_request_post( $queried_id ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Check access to a post used as public listing render context.
		 *
		 * @param int $post_id Post ID.
		 * @return bool
		 */
		protected function can_access_listing_request_post( $post_id ) {

			if ( current_user_can( 'edit_post', $post_id ) ) {
				return true;
			}

			if ( function_exists( 'is_post_publicly_viewable' ) ) {
				return is_post_publicly_viewable( $post_id );
			}

			return 'publish' === get_post_status( $post_id );
		}

		/**
		 * Get validated query from request
		 *
		 * @return array
		 */
		public function get_validated_query_from_request() {

			$query = ! empty( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array(); // phpcs:ignore

			// there is nothing to validate
			if ( empty( $query ) ) {
				return $query;
			}

			if ( ! $this->signatures->query_has_valid_signature( $query ) ) {
				wp_send_json_error( __( 'Invalid query signature', 'jet-engine' ) );
			}

			$widget_settings = ! empty( $_REQUEST['widget_settings'] ) ? $_REQUEST['widget_settings'] : array(); // phpcs:ignore

			if ( ! $this->query_matches_widget_settings( $query, $widget_settings ) ) {
				wp_send_json_error( __( 'Invalid query configuration', 'jet-engine' ) );
			}

			if ( ! $this->signatures->query_has_valid_mutable_signature( $query, $widget_settings ) ) {
				wp_send_json_error( __( 'Invalid filtered query signature', 'jet-engine' ) );
			}

			return $query;
		}

		/**
		 * Validate signed query arguments against server-loaded widget settings.
		 *
		 * @param array $query Signed query payload from the request.
		 * @param array $widget_settings Widget settings resolved on the server.
		 * @return bool
		 */
		public function query_matches_widget_settings( $query, $widget_settings ) {

			if ( empty( $widget_settings ) || ! is_array( $widget_settings ) ) {
				return true;
			}

			$is_custom_query = ! empty( $widget_settings['custom_query'] ) ? filter_var( $widget_settings['custom_query'], FILTER_VALIDATE_BOOLEAN ) : false;

			if ( $is_custom_query && in_array( $widget_settings['custom_query_id'], $query['allowed_query_ids'] ?? array() ) ) {
				return true;
			}

			if ( ! $is_custom_query || empty( $widget_settings['custom_query_id'] ) ) {
				return empty( $query['custom_query_id'] );
			}

			if ( empty( $query['custom_query_id'] ) ) {
				return false;
			}
			return absint( $query['custom_query_id'] ) === absint( $widget_settings['custom_query_id'] );
		}

		/**
		 * Build the signed query binding required by lazy-loaded custom queries.
		 *
		 * @param array $widget_settings Server-side listing widget settings.
		 * @return array
		 */
		public function get_lazy_load_custom_query( $widget_settings ) {

			$is_custom_query = ! empty( $widget_settings['custom_query'] ) ? filter_var( $widget_settings['custom_query'], FILTER_VALIDATE_BOOLEAN ) : false;

			if ( ! $is_custom_query || empty( $widget_settings['custom_query_id'] ) ) {
				return array();
			}

			$query_id = absint( $widget_settings['custom_query_id'] );
			$query    = array(
				'query_id'        => $query_id,
				'custom_query_id' => $query_id,
			);

			$query['signature'] = $this->generate_signature( $query );

			return $query;
		}

		/**
		 * Check if query has signature ad passed paramters match this signature
		 *
		 * @param array $query Query to validate.
		 * @return bool
		 */
		public function query_has_valid_signature( $query ) {
			return $this->signatures->query_has_valid_signature( $query );
		}

		/**
		 * Generate signature for the query
		 *
		 * @param array $query Query to generate signature for.
		 *
		 * @return string
		 */
		public function generate_signature( $query ) {
			return $this->signatures->generate_signature( $query );
		}

		/**
		 * Sign widget settings embedded in a rendered listing response.
		 *
		 * @param array $settings Widget settings.
		 * @return array
		 */
		public function maybe_add_settings_signature( $settings ) {
			return $this->signatures->maybe_add_settings_signature( $settings );
		}

		/**
		 * Add a server-side signature for mutable listing query state.
		 *
		 * @param array $query Query arguments.
		 * @param array $settings Listing widget settings.
		 * @return array
		 */
		public function maybe_add_mutable_query_signature( $query, $settings = array() ) {
			return $this->signatures->maybe_add_mutable_query_signature( $query, $settings );
		}

		/**
		 * Create signature for mutable listing query state.
		 *
		 * @param array $payload Mutable query payload.
		 * @param array $context Stable listing/query context.
		 * @return string
		 */
		public function create_mutable_query_signature( $payload, $context = array() ) {
			return $this->signatures->create_mutable_query_signature( $payload, $context );
		}

		/**
		 * Verify mutable listing query state signature.
		 *
		 * @param array $query Request query.
		 * @param array $settings Listing widget settings.
		 * @return bool
		 */
		public function query_has_valid_mutable_signature( $query, $settings = array() ) {
			return $this->signatures->query_has_valid_mutable_signature( $query, $settings );
		}

		/**
		 * Normalize array for consistent serialization
		 */
		protected function normalize($data) {
			return $this->signatures->normalize( $data );
		}

		/**
		 * Load more handler.
		 */
		public function listing_load_more() {

			$query = $this->get_validated_query_from_request();

			// Safe: $_REQUEST['widget_settings'] is set in the
			// `add_settings_to_request` method by the internal data, not user input.
			$widget_settings = ! empty( $_REQUEST['widget_settings'] ) ? $_REQUEST['widget_settings'] : array(); // phpcs:ignore

			$response = array();

			if ( jet_engine()->has_elementor() ) {

				$data = array(
					'id'         => 'jet-listing-grid',
					'elType'     => 'widget',
					'settings'   => $widget_settings,
					'elements'   => array(),
					'widgetType' => 'jet-listing-grid',
				);

				$widget = Elementor\Plugin::$instance->elements_manager->create_element_instance( $data );

				if ( ! $widget ) {
					throw new \Exception( 'Widget not found.' );
				}

				do_action( 'jet-engine/elementor-views/ajax/load-more', $widget );
			}

			do_action( 'jet-engine/listings/ajax/load-more' );

			ob_start();

			$base_class        = 'jet-listing-grid';
			$equal_cols_class  = '';
			$equal_cols_height = ! empty( $widget_settings['equal_columns_height'] ) ? filter_var( $widget_settings['equal_columns_height'], FILTER_VALIDATE_BOOLEAN ) : false;

			if ( $equal_cols_height ) {
				$equal_cols_class = 'jet-equal-columns';
			}

			$listing_id = absint( $widget_settings['lisitng_id'] );

			jet_engine()->listings->data->set_listing_by_id( $listing_id );

			$listing_source = jet_engine()->listings->data->get_listing_source();
			$page           = ! empty( $_REQUEST['page'] ) ? absint( $_REQUEST['page'] ) : 1; // phpcs:ignore
			$query['paged'] = $page;

			$render_instance = jet_engine()->listings->get_render_instance( 'listing-grid', $widget_settings );

			$listing_source = apply_filters(
				'jet-engine/listing/grid/source',
				$listing_source,
				$widget_settings,
				$render_instance
			);

			switch ( $listing_source ) {

				case 'posts':
					$widget_settings['posts_num'] = $query['posts_per_page'];

					$query = apply_filters(
						'jet-engine/listing/grid/posts-query-args',
						$query,
						$render_instance,
						$widget_settings
					);

					$offset          = ! empty( $query['offset'] ) ? absint( $query['offset'] ) : 0;
					$query['offset'] = $offset + ( $page - 1 ) * absint( $widget_settings['posts_num'] );

					// Added to remove slash from regex meta-query
					if ( jet_engine_should_unslash_regex( 'load_more/posts' ) && ! empty( $query['meta_query'] ) ) {
						$query['meta_query'] = wp_unslash( $query['meta_query'] );
					}

					if ( isset( $query['suppress_filters'] ) ) {
						$query['suppress_filters'] = filter_var( $query['suppress_filters'], FILTER_VALIDATE_BOOLEAN );
					}

					$posts_query     = new WP_Query( $query );
					$posts           = $posts_query->posts;
					break;

				case 'terms':
					$offset          = ! empty( $query['offset'] ) ? absint( $query['offset'] ) : 0;
					$query['offset'] = $offset + ( $page - 1 ) * absint( $widget_settings['posts_num'] );
					$posts           = get_terms( $query );
					break;

				case 'users':

					$query['offset'] = ( $page - 1 ) * absint( $widget_settings['posts_num'] );
					$user_query      = new WP_User_Query( $query );
					$posts           = (array) $user_query->get_results();

					break;

				default:

					$posts = apply_filters(
						'jet-engine/listing/grid/query/' . $listing_source,
						array(),
						$widget_settings,
						$render_instance
					);

					break;
			}

			if ( 1 < $query['paged'] ) {
				$start_from = ( $query['paged'] - 1 ) * absint( $widget_settings['posts_num'] ) + 1;
			} else {
				$start_from = false;
			}

			if ( jet_engine()->has_elementor() ) {
				Elementor\Plugin::instance()->frontend->start_excerpt_flag( null );
			}

			$view_type = jet_engine()->listings->data->get_listing_type( $listing_id );
			$render_instance->view = $view_type;
			$render_instance->listing_id = $listing_id;

			if ( isset( $render_instance->query_vars ) ) {
				$render_instance->query_vars['request']['posts_per_page'] = $query['posts_per_page'] ?? 3;
				$render_instance->query_vars['page'] = $page;
			}

			$render_instance->posts_loop(
				$posts,
				$widget_settings,
				$base_class,
				$equal_cols_class,
				$start_from
			);

			$response['html'] = ob_get_clean();

			self::maybe_add_enqueue_assets_data( $response );

			$response = apply_filters( 'jet-engine/ajax/listing_load_more/response', $response, $widget_settings );

			wp_send_json_success( $response );
		}

		/**
		 * Get whole listing through AJAX
		 */
		public function get_listing() {

			$query = $this->get_validated_query_from_request();

			// Safe: $_REQUEST['widget_settings'] is set in the
			// `add_settings_to_request` method by the internal data, not user input.
			$widget_settings  = ! empty( $_REQUEST['widget_settings'] ) ? $_REQUEST['widget_settings'] : array(); // phpcs:ignore

			$response = array();

			$_widget_settings = $widget_settings;
			$is_lazy_load     = ! empty( $widget_settings['lazy_load'] ) ? filter_var( $widget_settings['lazy_load'], FILTER_VALIDATE_BOOLEAN ) : false;

			// Reset `lazy_load` to avoid looping.
			if ( $is_lazy_load ) {

				$widget_settings['lazy_load'] = '';

				if ( jet_engine()->has_elementor() ) {
					Elementor\Plugin::instance()->frontend->start_excerpt_flag( null );
				}

			}

			if ( empty( $widget_settings['lisitng_id'] ) ) {
				wp_send_json_success( array( 'html' => __( 'Request data is incorrect', 'jet-engine' ) ) );
			}

			ob_start();

			/**
			 * @var \Jet_Engine_Render_Listing_Grid
			 */
			$render_instance = jet_engine()->listings->get_render_instance( 'listing-grid', $widget_settings );

			if ( $is_lazy_load && ! empty( $query ) && empty( $query['query_id'] ) ) { // for Archive pages

				jet_engine()->listings->data->set_listing_by_id( $widget_settings['lisitng_id'] );

				if ( isset( $query['suppress_filters'] ) ) {
					$query['suppress_filters'] = filter_var( $query['suppress_filters'], FILTER_VALIDATE_BOOLEAN );
				}

				$posts_query = new WP_Query( $query );
				$posts       = $posts_query->posts;

				$render_instance->posts_query = $posts_query;

				$render_instance->query_vars['page']    = $posts_query->get( 'paged' ) ? $posts_query->get( 'paged' ) : 1;
				$render_instance->query_vars['pages']   = $posts_query->max_num_pages;
				$render_instance->query_vars['request'] = $query;

				$render_instance->posts_template( $posts, $widget_settings );

			} else {
				$render_instance->render_content();
			}

			$response['html'] = ob_get_clean();

			self::maybe_add_enqueue_assets_data( $response );

			$response = apply_filters( 'jet-engine/ajax/get_listing/response', $response, $_widget_settings, $query );

			wp_send_json_success( $response );
		}

		/**
		 * Find element by ID in the Elementor elements tree.
		 * Legacy Elementor-only method
		 *
		 * @param  array  $elements   Elements tree.
		 * @param  string $element_id Element ID.
		 * @return array|bool
		 */
		public function find_element_recursive( $elements, $element_id ) {

			foreach ( $elements as $element ) {

				if ( $element_id === $element['id'] ) {
					return $element;
				}

				if ( ! empty( $element['elements'] ) ) {

					$element = $this->find_element_recursive( $element['elements'], $element_id );

					if ( $element ) {
						return $element;
					}
				}
			}

			return false;
		}

		/**
		 * Add enqueued assets data to the response.
		 *
		 * @param array $response Response data.
		 */
		public static function maybe_add_enqueue_assets_data( &$response ) {

			// phpcs:disable
			if ( isset( $_REQUEST['isEditMode'] ) && filter_var( $_REQUEST['isEditMode'], FILTER_VALIDATE_BOOLEAN ) ) {
				return;
			}
			// phpcs:enable

			// Ensure registered `jet-plugins` script.
			if ( ! wp_script_is( 'jet-plugins', 'registered' )  ) {
				jet_engine()->frontend->register_jet_plugins_js();
			}

			wp_scripts()->done[] = 'jquery';
			wp_scripts()->done[] = 'jet-plugins';
			wp_scripts()->done[] = 'jet-engine-frontend';

			$scripts = wp_scripts()->queue;
			$styles  = wp_styles()->queue;

			if ( ! empty( $scripts ) ) {
				$response['scripts'] = array();

				foreach ( (array) $scripts as $script ) {

					ob_start();
					wp_scripts()->do_items( $script );
					$script_html = ob_get_clean();

					$response['scripts'][ $script ] = $script_html;
				}
			}

			if ( ! empty( $styles ) ) {
				$response['styles'] = array();

				foreach ( (array) $styles as $style ) {

					ob_start();
					wp_styles()->do_items( $style );
					$style_html = ob_get_clean();

					$response['styles'][ $style ] = $style_html;
				}
			}
		}
	}
}
