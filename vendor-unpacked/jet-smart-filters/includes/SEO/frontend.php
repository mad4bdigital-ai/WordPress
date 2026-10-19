<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

if ( ! class_exists( 'Jet_Smart_Filters_SEO_Frontend' ) ) {

	/**
	 * Define Jet_Smart_Filters_SEO_Frontend class
	 */
	class Jet_Smart_Filters_SEO_Frontend {

		private $current_page_url;
		private $current_provider_data;
		private $current_query;
		private $current_rules;
		private $current_title;
		private $current_description;
		private $url_query;

		public $title_class       = 'jet-smart-filters-seo-rules-title';
		public $description_class = 'jet-smart-filters-seo-rules-description';

		public function __construct() {

			if ( ! jet_smart_filters()->settings->is_seo_enabled ) {
				return;
			}

			add_action( 'parse_request', array( $this, 'init_SEO_rules' ) );
			add_filter( 'jet-smart-filters/filters/localized-data',  array( $this, 'seo_localized_data' ) );
			add_filter( 'jet-smart-filters/render/ajax/data', array( $this, 'add_ajax_response_data' ) );
		}

		public function init_SEO_rules() {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_REQUEST['jsf'] ) || jet_smart_filters()->query->is_ajax_filter() || ! $this->get_current_rules() ) {
				return;
			}

			do_action( 'jet-smart-filters/seo/frontend/init-rule', $this );

			add_filter( 'document_title', array( $this, 'modify_document_title' ) );
			add_action( 'wp_head', array( $this, 'modify_document_description' ), 1 );
			add_filter('wpseo_canonical', '__return_false');
			add_action( 'wp_head', array( $this, 'modify_canonical_url' ), 1 );
		}

		public function modify_document_title( $title ) {

			$current_title = $this->get_current_title();

			if ( ! $current_title ) {
				return $title;
			}

			$title .= ( $title ? ' | ' : '' ) . esc_html( wp_strip_all_tags( $current_title ) );

			return $title;
		}

		public function modify_document_description() {

			$current_description = $this->get_current_description();

			if ( ! $current_description ) {
				return;
			}

			echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $current_description ) ) . '">' . "\n";

		}

		public function modify_canonical_url( ) {
			if ( ! $this->has_active_rule_filters() && ! $this->has_active_pagination() ) {
				return;
			}

			remove_action( 'wp_head', 'rel_canonical' );

			echo '<link rel="canonical" href="' . esc_url( jet_smart_filters()->data->get_current_url() ) . '">';

		}

		public function has_active_pagination() {

			return $this->get_current_rules() && $this->get_current_pagination_page() > 1;
		}

		public function has_active_rule_filters() {

			foreach ( $this->get_current_rules() as $rule ) {
				foreach ( $rule['filters'] as $filter_id ) {
					if ( $this->get_filter_current_val( $filter_id ) ) {
						return true;
					}
				}
			}

			return false;
		}

		public function get_rules( $page_url, $provider, $query_id = '' ) {

			$rules = array();

			if ( ! $page_url && ! $provider ) {
				return $rules;
			}

			foreach( jet_smart_filters()->seo->sitemap->rules as $item ) {

				$item_url = trim($item['url'], '/');

				if (
					$item_url === $page_url
					&& $item['provider'] === $provider
					&& $item['query_id'] === $query_id
				) {
					$rules[] = $item;
				}
			}
		
			return $rules;
		}

		public function replaceMacros( $text ) {

			return preg_replace_callback(
				'/\{\{filter_(\d+)(?:::(.*?))?(?:::(.*?))?(?:::(.*?))?(?:::(.*?))?(?:::(.*?))?\}\}/',
				function ( $matches ) {
					// Extract parameters with default values if they are empty
					$filter_id      = $matches[1];
					$text_before    = isset( $matches[2] ) && $matches[2] !== '' ? $matches[2] : '';
					$text_after     = isset( $matches[3] ) && $matches[3] !== '' ? $matches[3] : '';
					$fallback       = isset( $matches[4] ) && $matches[4] !== '' ? $matches[4] : '';
					$delimiter      = isset( $matches[5] ) && $matches[5] !== '' ? $matches[5] : ', ';
					$last_delimiter = isset( $matches[6] ) && $matches[6] !== '' ? $matches[6] : $delimiter;
					$options        = $this->get_filter_current_val( $filter_id );

					if ( ! $options ) {
						return $fallback;
					}

					$optionCount = count( $options );
		
					if ( $optionCount > 1 ) {
						$options_string = implode( $delimiter, array_slice( $options, 0, -1 ) )
							. "$last_delimiter"
							. end( $options );
					} else {
						$options_string = reset( $options );
					}

					$result_text =
						( $text_before ? $text_before . " " : "" )
						. $options_string .
						( $text_after ? " " . $text_after : "" );

					return $result_text;
				},
				$text
			);
		}

		public function get_filter_current_val( $filter_id ) {

			$filter_current_val = array();
			$filter_instance    = jet_smart_filters()->filter_types->get_filter_instance( $filter_id );

			if ( ! $filter_instance ) {
				return $filter_current_val;
			}

			$filter_args    = $filter_instance->args;
			$filter_options = $filter_args['options'];
			$current_val    = array();

			foreach ( $this->get_current_query() as $key => $value ) {
				if ( strpos( $key, "_{$filter_args['query_type']}_{$filter_args['query_var']}" ) !== 0 ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$current_val = $value;
				} else {
					array_push( $current_val, $value );
				}
			}

			if ( empty( $current_val ) ) {
				return $filter_current_val;
			}

			if ( $current_val && $filter_options ) {
				foreach ( $filter_options as $optionKey  => $optionValue ) {
					$value = null;
					$label = null;

					if ( is_object( $optionValue ) ) {
						if ( isset( $optionValue->name ) && isset( $optionValue->term_id ) ) {
							$value = $optionValue->term_id;
							$label = $optionValue->name;
						}
					} else if ( is_array( $optionValue ) ) {
						if ( isset( $optionValue['value'] ) && isset( $optionValue['label'] ) ) {
							$value = $optionValue['value'];
							$label = $optionValue['label'];
						} else {
							$value = $optionKey;
							$label = $optionValue['label']
								? $optionValue['label']
								: $optionValue;
						}
					} else {
						if ( ! empty( $optionKey ) && ! empty( $optionValue ) ) {
							$value = $optionKey;
							$label = $optionValue;
						}
					}

					if ( $value && $label && in_array( $value, $current_val ) ) {
						$filter_current_val[$value] = $label;
					}
				}
			}

			$max_filter_options_to_show = apply_filters( 'jet-smart-filters/seo/max-filters-options-to-show', 5, $filter_id );

			return $max_filter_options_to_show > 0
				? array_slice( $filter_current_val, 0, $max_filter_options_to_show )
				: $filter_current_val;
		}

		public function get_current_pagination_page() {

			return jet_smart_filters()->query->get_current_page();
		}

		public function seo_localized_data( $data ) {

			$data['seo'] = array(
				'current_page' => $this->get_current_page_url(),
				'selectors'    => array(
					'title'       => '.' . $this->title_class,
					'description' => '.' . $this->description_class,
				)
			);

			return $data;
		}

		public function add_ajax_response_data( $args ) {

			if ( ! $this->get_current_rules() ) {
				return $args;
			}

			$seo_request_val = jet_smart_filters()->data->get_request_var( 'seo' );

			$is_title = isset( $seo_request_val['is_title_enabled'] )
				? filter_var(
					sanitize_text_field( wp_unslash( $seo_request_val['is_title_enabled'] ) ),
					FILTER_VALIDATE_BOOLEAN
				)
				: false;
			$is_description = isset( $seo_request_val['is_description_enabled'] )
				? filter_var(
					sanitize_text_field( wp_unslash( $seo_request_val['is_description_enabled'] ) ),
					FILTER_VALIDATE_BOOLEAN
				)
				: false;

			if ( $is_title || $is_description ) {
				$args['seo'] = array();
			}

			if ( $is_title ) {
				$args['seo']['title'] = wp_kses_post( $this->get_current_title() );
			}

			if ( $is_description ) {
				$args['seo']['description'] = wp_kses_post( $this->get_current_description() );
			}

			return $args;
		}

		// Getters
		public function get_current_page_url() {

			if ( $this->current_page_url ) {
				return $this->current_page_url;
			}

			global $wp;
			$this->current_page_url = $wp->request;

			$seo_request_val = jet_smart_filters()->data->get_request_var( 'seo' );

			if ( isset( $seo_request_val['current_page'] ) ) {
				$this->current_page_url = sanitize_text_field(
					wp_unslash( $seo_request_val['current_page'] )
				);
			} else if ( jet_smart_filters()->settings->url_structure_type === 'permalink' ) {
				$this->current_page_url = preg_replace( '/\/jsf\/.*/', '', $this->current_page_url );
			}

			if ( $this->current_page_url ) {
				$this->current_page_url = trim( $this->current_page_url, '/' );
			}

			return $this->current_page_url;
		}

		public function get_current_provider_data() {

			if ( $this->current_provider_data ) {
				return $this->current_provider_data;
			}

			$jsf_request_val      = jet_smart_filters()->data->get_request_var( 'jsf' );
			$action_request_val   = jet_smart_filters()->data->get_request_var( 'action' );
			$provider_request_val = jet_smart_filters()->data->get_request_var( 'provider' );

			if ( $action_request_val && $action_request_val === 'jet_smart_filters' && $provider_request_val ) {
				$this->current_provider_data = explode(
					'/',
					sanitize_text_field( wp_unslash( $provider_request_val ) )
				);

				if ( isset( $this->current_provider_data[1] ) && $this->current_provider_data[1] === 'default' ) {
					unset( $this->current_provider_data[1] );
				}
			} else if ( $jsf_request_val && is_string( $jsf_request_val ) ) {
				$this->current_provider_data = explode(
					':',
					sanitize_text_field( wp_unslash( $jsf_request_val ) )
				);
			} else {
				$this->current_provider_data = array();
			}

			return $this->current_provider_data;
		}

		public function get_current_query() {

			if ( $this->current_query ) {
				return $this->current_query;
			}

			$this->current_query = array();

			$queries = array( 'tax_query', 'meta_query' );
			$request = jet_smart_filters()->data->get_request();

			if ( jet_smart_filters()->query->is_ajax_filter() ) {
				$request = isset( $request['query'] )
					? $request['query']
					: array();
			}

			foreach ( $queries as $query_type ) {
				foreach ( $request as $key => $value ) {
					if ( strpos( $key, "_{$query_type}_" ) !== 0 ) {
						continue;
					}

					$this->current_query[$key] = $value;
				}
			}

			return $this->current_query;
		}

		public function get_current_rules() {

			if ( $this->current_rules ) {
				return $this->current_rules;
			}

			$provider_data = $this->get_current_provider_data();
			$provider      = isset( $provider_data[0] ) ? $provider_data[0] : '';
			$query_id      = isset( $provider_data[1] ) ? $provider_data[1] : '';

			$this->current_rules = $this->get_rules( $this->get_current_page_url(), $provider, $query_id );
		
			return $this->current_rules;
		}

		public function get_current_title() {

			if ( $this->current_title ) {
				return $this->current_title;
			}

			$this->current_title = '';

			if ( ! $this->get_current_query() ) {
				return $this->current_title;
			}

			foreach ( $this->get_current_rules() as $rule ) {
				if ( ! empty( $rule['title'] ) ) {
					$this->current_title .= ( $this->current_title ? ' ' : '' ) . $rule['title'];
				}
			}

			$this->current_title = $this->replaceMacros( $this->current_title );

			return $this->current_title;
		}

		public function get_current_description() {

			if ( $this->current_description ) {
				return $this->current_description;
			}

			$this->current_description = '';

			if ( ! $this->get_current_query() ) {
				return $this->current_description;
			}

			foreach ( $this->get_current_rules() as $rule ) {
				if ( ! empty( $rule['description'] ) ) {
					$this->current_description .= ( $this->current_description ? ' ' : '' ) . $rule['description'];
				}
			}

			$this->current_description = $this->replaceMacros( $this->current_description );

			return $this->current_description;
		}
	}
}
