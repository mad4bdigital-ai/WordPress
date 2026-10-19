<?php
/**
 * Class: Jet_Smart_Filters_Provider_WooCommerce_Archive_Default
 * Name: Default WooCommerce Archive (Classic)
 * Slug: default-woo-archive
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// For the cases when Jet_Smart_Filters_Provider_WooCommerce_Archive is disabled in the settings
if ( ! class_exists( 'Jet_Smart_Filters_Provider_WooCommerce_Archive' ) ) {
	require_once jet_smart_filters()->plugin_path( 'includes/providers/woocommerce-archive.php' );
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_WooCommerce_Archive_Default' ) ) {
	/**
	 * Define Jet_Smart_Filters_Provider_WooCommerce_Archive_Default class
	 */
	class Jet_Smart_Filters_Provider_WooCommerce_Archive_Default extends Jet_Smart_Filters_Provider_WooCommerce_Archive {

		protected $query_id_class_prefix = 'jsf-query--';

		protected $rendered_block = null;

		/**
		 * Constructor.
		 *
		 * Initializes the provider by calling the parent constructor and
		 * registering necessary filters and actions when WooCommerce is active.
		 */
		public function __construct() {

			parent::__construct();

			if ( class_exists( '\WooCommerce' ) ) {

				add_filter(
					'jet-smart-filters/filters/localized-data',
					array( $this, 'add_js_settings' )
				);

				add_action(
					'jet-smart-filters/referrer/self/before',
					array( $this, 'register_referrer_query' )
				);

				$url_type = jet_smart_filters()->settings->url_structure_type;
				$rewrite_cpt = jet_smart_filters()->settings->get( 'rewritable_post_types' );

				if ( 'permalink' === $url_type
					&& ! empty( $rewrite_cpt )
					&& isset( $rewrite_cpt['product'] )
					&& true === filter_var( $rewrite_cpt['product'], FILTER_VALIDATE_BOOLEAN )
				) {
					add_action( 'pre_get_posts', array( $this, 'fix_reload_pager' ) );

					$query_key_request_val = jet_smart_filters()->data->get_request_var( \Jet_Smart_Filters_Referrer_Manager::$front_query_key );
					if ( $query_key_request_val ) {
						add_filter( 'jet-smart-filters/render/filters-applied', '__return_true' );
					}
				}
			}
		}

		/**
		 * Fix WooCommerce pager for the Default WooCommerce Archive provider.
		 *
		 * This method ensures that the pagination URLs are compatible with the permalink structure.
		 */
		public function fix_reload_pager( $q ) {

			if ( ! $q->is_main_query() ) {
				return;
			}

			if ( jet_smart_filters()->query->get_current_provider( 'provider' ) !== $this->get_id() ) {
				return;
			}

			global $wp;
			$has_page_number = preg_match( '/\/page\/(\d+)/', $wp->request, $page_numbers );

			if ( $has_page_number && ! empty( $page_numbers[1] ) ) {
				$q->set( 'paged', absint( $page_numbers[1] ) );
			} else {
				$q->set( 'paged', 1 );
			}
		}

		/**
		 * Check if we're currently processing Default WooCommerce Archive request.
		 * Register appropriate hooks to add query args
		 *
		 * @param array $query_args Query args
		 */
		public function register_referrer_query() {

			if ( jet_smart_filters()->query->get_current_provider( 'provider' ) !== $this->get_id() ) {
				return;
			}

			add_action( 'pre_get_posts', array( $this, 'set_referrer_query' ), 99 );
		}

		/**
		 * Set the query args for the Default WooCommerce Archive provider.
		 *
		 * @param WP_Query $q The current WP_Query object.
		 */
		public function set_referrer_query( $q ) {

			if ( ! $q->is_main_query() ) {
				return;
			}

			$query_args = jet_smart_filters()->query->get_query_from_request();

			if ( ! empty( $query_args['tax_query'] )
				&& ! empty( $q->query_vars['taxonomy'] )
				&& ! empty( $q->query_vars['term'] ) ) {

				$tax_query = $query_args['tax_query'];
				$found_tax = array();

				foreach ( $tax_query as $key => $tax ) {
					if ( isset( $tax['taxonomy'] ) && ! in_array( $tax['taxonomy'], $found_tax, true ) ) {
						$found_tax[] = $tax['taxonomy'];
					}
				}

				if ( in_array( $q->query_vars['taxonomy'], $found_tax, true ) ) {
					unset( $q->query_vars['taxonomy'] );
					unset( $q->query_vars['term'] );
				}
			}

			$q->query_vars = $this->merge_query( $query_args, $q->query_vars );
		}

		/**
		 * Register 'Default WooCommerce Archive' specific settings for
		 * JS object window.JetSmartFilterSettings
		 *
		 * @param array $data Initial settings
		 * @return array
		 */
		public function add_js_settings( $data ) {

			global $wp;
			$current_url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );

			$data['wc_archive'] = array(
				'pager_selector' => jet_smart_filters()->settings->get( 'wc_archive_pager_item_selector', '.woocommerce-pagination a.page-numbers' ),
				'order_selector' => 'form.woocommerce-ordering',
				'referrer_url'   => $current_url,
				'query_args'     => array(
					\Jet_Smart_Filters_Referrer_Manager::$front_query_key       => 1,
					\Jet_Smart_Filters_Referrer_Manager::$force_referrer_key    => 'self',
					\Jet_Smart_Filters_Referrer_Manager::$sequence_referrer_key => 'late',
				),
			);

			return $data;
		}

		/**
		 * Get the localized name of the WooCommerce Archive Provider.
		 *
		 * @return string The provider name.
		 */
		public function get_name() {
			return __( 'Default WooCommerce Archive (Classic)', 'jet-smart-filters' );
		}

		/**
		 * Get the unique identifier (slug) of the provider.
		 *
		 * @return string The provider slug.
		 */
		public function get_id() {
			return 'default-woo-archive';
		}

		/**
		 * Outputs the WooCommerce products content for AJAX requests.
		 *
		 * This method handles the products loop rendering and sets up filters
		 * to include pagination data in the AJAX response.
		 *
		 * @return void
		 */
		public function ajax_get_content() {

			// Start products loop
			if ( wc_get_loop_prop( 'total' ) ) {
				while ( have_posts() ) {
					the_post();
					// Render products
					wc_get_template_part( 'content', 'product' );
				}
			} else {
				// If no products found, render empty state
				wc_no_products_found();
			}

			add_filter( 'jet-smart-filters/render/ajax/data', array( $this, 'add_pagination' ), 99999 );
		}

		/**
		 * Append pagination and result count HTML fragments to the AJAX response data.
		 *
		 * This method generates updated pagination and result count HTML using WooCommerce
		 * template functions and adds it to the AJAX response under the configured selectors.
		 *
		 * @param array $data The AJAX response data.
		 * @return array The modified AJAX response data including pagination fragments.
		 */
		public function add_pagination( $data ) {

			if ( ! function_exists( 'woocommerce_pagination' ) ) {
				return $data;
			}

			if ( empty( $data['pagination'] ) || ! is_array( $data['pagination'] ) ) {
				$data['pagination'] = array();
			}

			$found_posts   = absint( wc_get_loop_prop( 'total' ) );
			$max_num_pages = absint( wc_get_loop_prop( 'total_pages' ) );
			$current_page  = absint( wc_get_loop_prop( 'current_page' ) );

			$data['pagination'] = array_merge(
				$data['pagination'],
				array(
					'found_posts'   => $found_posts,
					'max_num_pages' => $max_num_pages,
					'page'          => $current_page
						? $current_page
						: jet_smart_filters()->query->get_current_page(),
				)
			);

			if ( empty( $data['replace_fragments'] ) ) {
				$data['replace_fragments'] = array();
			}

			ob_start();
			woocommerce_pagination();
			$pagination = ob_get_clean();

			$selector = jet_smart_filters()->settings->get( 'wc_archive_pager_cont_selector', '.woocommerce-pagination' );

			if ( ! $pagination ) {
				$pagination = $this->render_empty_pagination( $selector );
			}

			$data['replace_fragments'][ $selector ] = $pagination;

			$data = $this->helpers->woocommerce->add_result_count_fragment( $data );

			return $data;
		}

		/**
		 * Render empty pagination wrapper
		 *
		 * @param string $selector
		 * @return void
		 */
		public function render_empty_pagination( $selector ) {

			// First of all we need to get the direct selector of pagination container
			$selector = str_replace( '>', ' ', $selector );
			// replace all duplicating spaces with the single one
			$selector = preg_replace( '/\s+/', ' ', $selector );

			$selectors = explode( ' ', $selector );

			// get last element of $selectors array
			$last_selector = end( $selectors );

			$selector_structure = explode( '.', $last_selector );

			$first_is_tag = false;

			if (
				false === strpos( $selector_structure[0], '.' )
				&& false === strpos( $selector_structure[0], '.' )
			) {
				$tag = $selector_structure[0];
				$first_is_tag = true;
			} else {
				$tag = 'nav';
			}

			$classes = array();
			$id = false;

			for ( $i = 0; $i < count( $selector_structure ); $i++ ) {

				if ( $first_is_tag && 0 === $i ) {
					continue;
				} elseif ( 0 === $i && false !== strpos( $selector_structure[ $i ], '#' ) ) {
					$tag_with_id = explode( '#', $selector_structure[ $i ] );
					$tag = $tag_with_id[0];
					$id  = $tag_with_id[1];
					continue;
				}

				if ( false !== strpos( $selector_structure[ $i ], '#' ) ) {
					$id = ltrim( $selector_structure[ $i ], '#' );
				} else {
					$classes[] = $selector_structure[ $i ];
				}
			}

			$allowed_tags = array( 'div', 'nav', 'ul' );

			if ( ! in_array( $tag, $allowed_tags ) ) {
				$tag = 'nav';
			}

			return sprintf(
				'<%1$s class="%2$s" %3$s style="display:none;"></%1$s>',
				$tag,
				esc_attr( implode( ' ', $classes ) ),
				( $id ? 'id="' . esc_attr( $id ) . '"' : '' )
			);
		}

		/**
		 * Retrieve the CSS selector for the products wrapper.
		 *
		 * This selector is used as the reference point for replacing content on AJAX updates.
		 *
		 * @return string The CSS selector for the WooCommerce products container.
		 */
		public function get_wrapper_selector() {
			return apply_filters(
				'jet-smart-filters/providers/woo-default-archive/wrapper-selector',
				'.woocommerce .products'
			);
		}
	}
}
