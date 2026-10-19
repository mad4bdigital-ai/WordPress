<?php
/**
 * Data class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Data' ) ) {

	/**
	 * Define Jet_Smart_Filters_Data class
	 */
	class Jet_Smart_Filters_Data {

		public $url_symbol = array(
			'provider_id'     => ':',
			'items_separator' => ';',
			'key_value'       => ':',
			'value_separator' => ',',
			'var_suffix'      => '!',
		);

		public function __construct() {

			// set custom url symbols
			if ( jet_smart_filters()->settings->use_url_custom_symbols ) {
				if ( jet_smart_filters()->settings->url_provider_id_delimiter ) {
					$this->url_symbol['provider_id'] = jet_smart_filters()->settings->url_provider_id_delimiter;
				}
				if ( jet_smart_filters()->settings->url_items_separator ) {
					$this->url_symbol['items_separator'] = jet_smart_filters()->settings->url_items_separator;
				}
				if ( jet_smart_filters()->settings->url_key_value_delimiter ) {
					$this->url_symbol['key_value'] = jet_smart_filters()->settings->url_key_value_delimiter;
				}
				if ( jet_smart_filters()->settings->url_value_separator ) {
					$this->url_symbol['value_separator'] = jet_smart_filters()->settings->url_value_separator;
				}
				if ( jet_smart_filters()->settings->url_var_suffix_separator ) {
					$this->url_symbol['var_suffix'] = jet_smart_filters()->settings->url_var_suffix_separator;
				}
			}
		}

		/**
		 * Returns providers lists
		 */
		private $_providers_list = array();
		public function get_providers_list() {

			if ( $this->_providers_list ) {
				return $this->_providers_list;
			}

			foreach ( glob( jet_smart_filters()->plugin_path( 'includes/providers/' ) . '*.php' ) as $file ) {
				$data = get_file_data( $file, array( 'class'=>'Class', 'name' => 'Name', 'slug'=>'Slug' ) );

				if ( $data['name'] ) {
					$this->_providers_list[ $data['class'] ] = $data['name'];
				}
			}

			return $this->_providers_list;
		}

		public function get_avaliable_providers() {

			$result                    = array();
			$saved_avaliable_providers = jet_smart_filters()->settings->get( 'avaliable_providers', array() );

			foreach ( $this->get_providers_list() as $key => $value ) {
				$result[$key] = isset( $saved_avaliable_providers[$key] )
					? $saved_avaliable_providers[$key]
					: 'true';
			}

			return $result;
		}

		/**
		 * Get URL symbol
		 */
		public function get_url_symbol( $name ) {

			$use_tabindex = filter_var( jet_smart_filters()->settings->get( 'use_tabindex', false ), FILTER_VALIDATE_BOOLEAN );

			return $use_tabindex
				? 'tabindex="0"'
				: '';
		}

		/**
		 * Allowed filter types.
		 */
		public function filter_types() {

			$filter_types = jet_smart_filters()->filter_types->get_filter_types();
			$result       = array();

			foreach ( $filter_types as $filter_id => $filter ) {
				if ( ! method_exists( $filter, 'get_name' ) ) {
					continue;
				}

				$result[ $filter_id ] = $filter->get_name();
			}

			return $result;
		}

		/**
		 * Get sitepath.
		 */
		public function get_sitepath() {

			$parsed_home_url = wp_parse_url( home_url() );

			return array_key_exists( 'path', $parsed_home_url ) ? $parsed_home_url['path'] : '';
		}

		/**
		 * Get current url.
		 */
		public function get_current_url() {

			return ( is_ssl() ? 'https://' : 'http://' )
				. $_SERVER['HTTP_HOST'] // phpcs:ignore
				. $_SERVER['REQUEST_URI']; // phpcs:ignore
		}

		/**
		 * Get baseurl.
		 */
		public function get_baseurl() {

			$baseurl        = preg_replace( '/\bjsf[\/|=].*/', '', $_SERVER['REQUEST_URI'], 1 ); // phpcs:ignore
			$parsed_baseurl = wp_parse_url( $baseurl );

			return apply_filters(
				'jet-smart-filters/data/baseurl',
				rtrim( array_key_exists( 'path', $parsed_baseurl ) ? $parsed_baseurl['path'] : $baseurl, '/' ) . '/'
			);
		}

		/**
		 * Return information about compare data by label
		 */
		public function parse_comapre_label( $label ) {

			$result = array(
				'compare' => '=',
			);

			switch ( $label ) {

				case 'less' :
					$result['compare'] = '<=';
					$result['type']    = 'NUMERIC';
					break;

				case 'greater' :
					$result['compare'] = '>=';
					$result['type']    = 'NUMERIC';
					break;

				case 'like' :
					$result['compare'] = 'LIKE';
					break;

				case 'in' :
					$result['compare'] = 'IN';
					break;

				case 'between' :
					$result['compare'] = 'BETWEEN';
					break;

				case 'exists' :
					$result['compare'] = 'EXISTS';
					break;

				case 'regexp' :
					$result['compare'] = 'REGEXP';
					break;

				default:
					$result['compare'] = '=';
					break;

			}

			return $result;
		}

		/**
		 * Returns provider selectors list
		 */
		public function get_provider_selectors() {

			$providers = jet_smart_filters()->providers->get_providers();
			$result    = array();

			foreach ( $providers as $provider_id => $provider ) {
				if ( $provider->get_wrapper_selector() ) {
					$result[ $provider_id ] = array(
						'selector' => $provider->get_wrapper_selector(),
						'action'   => $provider->get_wrapper_action(),
						'inDepth'  => $provider->in_depth(),
						'idPrefix' => $provider->id_prefix(),
					);

					$list = $provider->get_list_selector();
					if ( $list ) {
						$result[ $provider_id ]['list'] = $list;
					}

					$item = $provider->get_item_selector();
					if ( $item ) {
						$result[ $provider_id ]['item'] = $item;
					}
				}
			}

			return $result;
		}

		/**
		 * Find choices for filter from field data
		 */
		public function get_choices_from_field_data( $args = array() ) {

			$result = array();

			$args = wp_parse_args( $args, array(
				'field_key' => false,
				'source'    => 'jet_engine',
			) );

			if ( empty( $args['field_key'] ) ) {
				return $result;
			}

			// trimming accidentally entered spaces in the key field
			$args['field_key'] = trim( $args['field_key'] );

			switch ( $args['source'] ) {
				case 'acf':

					if ( ! function_exists( 'acf_get_field' ) ) {
						return $result;
					}

					$field = acf_get_field( $args['field_key'] );

					if ( $field && is_array( $field ) && ! empty( $field['choices'] ) ) {
						return $field['choices'];
					} else {
						return $result;
					}

				default:

					if ( ! function_exists( 'jet_engine' ) || ! isset( jet_engine()->meta_boxes ) ) {
						return $result;
					}

					$all_fields  = jet_engine()->meta_boxes->get_registered_fields();
					$found_field = null;

					foreach ( $all_fields as $object => $fields ) {
						foreach ( $fields as $field_data ) {
							if ( ! empty( $field_data['name'] ) && $args['field_key'] === $field_data['name'] ) {
								$found_field = $field_data;
							}
						}
					}

					if ( ! empty( $found_field['options'] ) ) {
						foreach ( $found_field['options'] as $option ) {
							$label                  = apply_filters( 'jet-engine/compatibility/translate-string', $option['value'] );
							$result[$option['key']] = $label;
						}
					}

					if ( isset( $found_field['options_source'] ) && $found_field['options_source'] === 'manual_bulk' && ! empty( $found_field['bulk_options'] ) ) {
						$bulk_options = explode( PHP_EOL, $found_field['bulk_options'] );

						$result = array();

						foreach ( $bulk_options as $option ) {
							$parsed_option             = explode( '::', trim( $option ) );
							$result[$parsed_option[0]] = isset( $parsed_option[1] ) ? $parsed_option[1] : $parsed_option[0];
						}
					}

					return $result;
			}
		}

		/**
		 * get raw options list from the DB by field key
		 *
		 * @param  string $field_key Field key to get options by.
		 * @return array
		 */
		public function get_options_by_field_key( $field_key = '' ) {

			global $wpdb;

			$options = array();

			$raw_results = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT `meta_value` FROM {$wpdb->postmeta} WHERE `meta_key` = '%s';",
				sanitize_text_field( $field_key )
			) );

			if ( ! empty( $raw_results ) ) {
				foreach ( $raw_results as $row ) {
					$value = maybe_unserialize( $row->meta_value );
					if ( $value && ! is_array( $value ) && ! is_object( $value ) ) {
						$value = wp_kses( $value, array() );
						$options[ $value ] = $value;
					}
				}
			}

			return $options;
		}

		/**
		 * Find choices for filter from custom content types
		 */
		public function get_choices_from_cct_data( $field_key ) {

			$result = array();

			if ( ! function_exists( 'jet_engine' ) || ! jet_engine()->modules->is_module_active( 'custom-content-types' ) ) {
				return $result;
			}

			$found_field        = null;
			$all_content_types  = jet_engine()->modules->get_module( 'custom-content-types' )->instance->manager->get_content_types();

			foreach ( $all_content_types as $content_type ) {
				$content_type_fields = property_exists( $content_type, 'fields' ) ? $content_type->fields : array();

				foreach ( $content_type_fields as $field_data ) {
					if ( ! empty( $field_data['name'] ) && $field_key === $field_data['name'] ) {
						$found_field = $field_data;
					}
				}
			}

			if ( ! empty( $found_field['options'] ) ) {
				foreach ( $found_field['options'] as $option ) {
					$result[ $option['key'] ] = $option['value'];
				}
			}

			if ( isset( $found_field['options_source'] ) && $found_field['options_source'] === 'manual_bulk' && ! empty( $found_field['bulk_options'] ) ) {
				$bulk_options = explode( PHP_EOL, $found_field['bulk_options'] );

				$result = array();

				foreach ( $bulk_options as $option ) {
					$parsed_option             = explode( '::', trim( $option ) );
					$result[$parsed_option[0]] = isset( $parsed_option[1] ) ? $parsed_option[1] : $parsed_option[0];
				}
			}

			return $result;
		}

		/**
		 * Retrun regitered content providers
		 */
		public function content_providers() {

			$providers = jet_smart_filters()->providers->get_providers();
			$result    = array(
				'' => esc_html__( 'Select...', 'jet-smart-filters' ),
			);

			foreach ( $providers as $provider_id => $provider ) {
				$result[ $provider_id ] = $provider->get_name();
			}

			return $result;
		}

		/**
		 * Retrun filters by passed type
		 */
		public function get_filters_by_type( $type = null ) {

			$args = array(
				'post_type'      => jet_smart_filters()->post_type->slug(),
				'posts_per_page' => -1,
			);

			if ( $type ) {
				$args['meta_query'] = array(
					array(
						'key'     => '_filter_type',
						'value'   => $type,
						'compare' => '=',
					),
				);
			}

			$filters = get_posts( $args );

			if ( empty( $filters ) ) {
				return array();
			}

			return wp_list_pluck( $filters, 'post_title', 'ID' );
		}

		/**
		 * Returns post types list for options
		 */
		public function get_post_types_for_options() {

			$args = array(
				'public' => true,
			);

			$post_types = get_post_types( $args, 'objects', 'and' );
			$post_types = wp_list_pluck( $post_types, 'label', 'name' );

			if ( isset( $post_types[ jet_smart_filters()->post_type->slug() ] ) ) {
				unset( $post_types[jet_smart_filters()->post_type->slug()] );
			}

			return $post_types;
		}

		/**
		 * Get posts order by list for options.
		 */
		public function get_posts_order_by_options() {

			return array(
				'ID'            => 'Order by post id',
				'author'        => 'By author',
				'title'         => 'By title',
				'name'          => 'By post name (post slug)',
				'type'          => 'By post type (available since version 4.0)',
				'date'          => 'By date',
				'modified'      => 'By last modified date',
				'parent'        => 'By post/page parent id',
				'comment_count' => 'By number of comments',
			);
		}

		/**
		 * Get taxonomies list for options.
		 */
		public function get_taxonomies_for_options() {

			$taxonomies         = get_taxonomies( array(), 'objects', 'and' );
			$options_taxonomies = wp_list_pluck( $taxonomies, 'label', 'name' );

			return $options_taxonomies;
		}

		/**
		 * Get grouped taxonomies list for options.
		 */
		public function get_grouped_taxonomies_options() {

			$result     = array();
			$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

			foreach ( $taxonomies as $taxonomy ) {

				if ( empty( $taxonomy->object_type ) || ! is_array( $taxonomy->object_type ) ) {
					continue;
				}

				foreach ( $taxonomy->object_type as $object ) {
					if ( empty( $result[ $object ] ) ) {
						$post_type = get_post_type_object( $object );

						if ( ! $post_type ) {
							continue;
						}

						$result[ $object ] = array(
							'label'   => $post_type->labels->name,
							'options' => array(),
						);
					}

					$result[ $object ]['options'][$taxonomy->name] = $taxonomy->labels->name;
				};
			}

			return $result;
		}

		/**
		 * Get taxonomies list for options.
		 */
		public function get_taxonomy_term_field_for_options() {

			return array(
				'term_id'          => 'Term ID',
				'name'             => 'Name',
				'slug'             => 'Slug',
				'term_taxonomy_id' => 'Term taxonomy ID',
			);
		}

		/**
		 * Get term compare operators for options.
		 */
		public function get_term_compare_operators_for_options() {

			return array(
				'IN'         => 'In',
				'NOT IN'     => 'Not in',
				'AND'        => 'And',
				'EXISTS'     => 'Exists',
				'NOT EXISTS' => 'Not exists',
			);
		}

		/**
		 * Get meta compare operators for options.
		 */
		public function get_meta_compare_operators_for_options() {

			return array(
				'='           => 'Equal (=)',
				'!='          => 'Not equal (!=)',
				'>'           => 'Greater than (>)',
				'>='          => 'Greater or equal (>=)',
				'<'           => 'Less than (<)',
				'<='          => 'Less or equal (<=)',
				'LIKE'        => 'Like',
				'NOT LIKE'    => 'Not like',
				'IN'          => 'In',
				'NOT IN'      => 'Not in',
				'BETWEEN'     => 'Between',
				'NOT BETWEEN' => 'Not between',
				'EXISTS'      => 'Exists',
				'NOT EXISTS'  => 'Not exists',
				'REGEXP'      => 'Regexp',
				'NOT REGEXP'  => 'Not regexp'
			);
		}

		/**
		 * Get meta type for options.
		 */
		public function get_meta_type_for_options() {

			return array(
				'CHAR'      => 'Char',
				'NUMERIC'   => 'Numeric',
				'DATE'      => 'Date',
				'DATETIME'  => 'Datetime',
				'TIMESTAMP' => 'Timestamp',
				'DECIMAL'   => 'Decimal',
				'TIME'      => 'Time',
				'BINARY'    => 'Binary',
				'SIGNED'    => 'Signed',
				'UNSIGNED'  => 'Unsigned'
			);
		}

		/**
		 * Get posts list for options.
		 */
		public function get_posts_for_options( $params = array(), $grouped_by_types = true ) {

			$args = array_merge( array(
				'posts_per_page' => -1,
				'orderby'       => 'title',
				'order'         => 'ASC',
			), $params );

			$post_types = $this->get_post_types_for_options();
			
			if ( ! empty( $args['post_type'] ) ) {
				$post_types = array_intersect_key( $post_types, array_flip( (array) $args['post_type'] ));
			} else {
				$args['post_type'] = array_keys( $post_types );
			}

			function posts_where_search_by_title_filter( $where, $wp_query ) {

				global $wpdb;

				if ( $search_title = $wp_query->get( 's_by_title' ) ) {
					$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $search_title ) . '%' );
				}

				return $where;
			}

			// Add a posts_where filter to search by title only
			if ( ! empty( $args['s_by_title'] ) ) {
				add_filter( 'posts_where', 'posts_where_search_by_title_filter', 10, 2 );
			}
			
			$query = new WP_Query( $args );

			// Remove filter after query execution
			remove_filter( 'posts_where', 'posts_where_search_by_title_filter', 10 );

			$results = [];

			// Grouping the results
			if ( $grouped_by_types ) {
				// Fill in the structure with the names of the post types
				foreach ( $post_types as $type => $type_label ) {
					$results[$type] = [
						'label' => $type_label,
						'posts' => []
					];
				}
				// Add the found posts to the appropriate groups
				if ( ! empty( $query->posts ) ) {
					foreach( $query->posts as $post ) {
						$results[$post->post_type]['posts'][$post->ID] = $post->post_title;
					}
				}
				// Remove empty groups (if there are no results in the record type)
				$results = array_filter( $results, function( $type ) {
					return ! empty( $type['posts'] );
				});
			} else {
				// Add the found posts
				if ( ! empty( $query->posts ) ) {
					foreach( $query->posts as $post ) {
						$results[$post->ID] = $post->post_title;
					}
				}
			}

			return $results;
		}

		/**
		 * Get grouped terms list for options.
		 */
		public function get_grouped_terms_for_options( $params = array() ) {

			$terms         = get_terms( $params );
			$grouped_terms = [];

			if ( empty( $terms ) && !is_wp_error( $terms ) ) {
				return $grouped_terms;
			}

			// Grouping terms by taxonomies
			foreach ( $terms as $term ) {
				$taxonomy = $term->taxonomy;
				$taxonomy_label = get_taxonomy( $taxonomy )->labels->singular_name;
		
				if ( ! isset( $grouped_terms[$taxonomy] ) ) {
					$grouped_terms[$taxonomy] = [
						'label' => $taxonomy_label,
						'terms' => []
					];
				}
		
				$grouped_terms[$taxonomy]['terms'][$term->term_id] = $term->name;
			}

			return $grouped_terms;
		}

		/**
		 * Get post stati list for options.
		 */
		public function get_post_stati_for_options() {

			$post_stati = get_post_stati();
			array_walk( $post_stati, function( &$value ) {
				$value = ucfirst($value);
			});

			return $post_stati;
		}

		/**
		 * Get post stati list for options.
		 */
		public function get_user_for_options( $params = array() ) {

			$args = array_merge(
				array(
					'orderby' => 'display_name',
					'order'   => 'ASC'
				),
				$params
			);

			$users = array();
			foreach ( get_users( $args ) as $user ) {
				$users[$user->ID] = $user->display_name;
			}

			return $users;
		}

		/**
		 * Returns terms objects list
		 */
		public function get_terms_objects( $tax = null, $child_of_current = false, $custom_args = array() ) {

			if ( ! $tax ) {
				return array();
			}

			if ( ! is_array( $custom_args ) ) {
				$custom_args = array();
			}

			$args = array_merge( array( 'taxonomy' => $tax ), $custom_args );

			if ( $child_of_current && ( is_category() || is_tag() || is_tax( $tax ) ) ) {
				$args['child_of'] = get_queried_object_id();
			}

			$terms = get_terms( $args );

			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}

			return apply_filters(
				'jet-smart-filters/data/terms-objects',
				$terms,
				$tax,
				$child_of_current,
				$args
			);
		}

		/**
		 * Get terms of passed taxonomy for checkbox/select/radio options
		 */
		public function get_terms_for_options( $tax = null, $child_of_current = false, $is_slugs = false, $custom_args = array() ) {

			$terms   = $this->get_terms_objects( $tax, $child_of_current, $custom_args );
			$options = array();

			foreach ( $terms as $term ) {
				array_push( $options, array(
					'value' => $term->term_id,
					'label' => $term->name
				) );

				if ( $is_slugs ) {
					$options[count($options) - 1]['data_attrs'] = array(
						'url-value' => $term->slug
					);
				}
			}

			return apply_filters(
				'jet-smart-filters/data/terms-for-options',
				$options,
				$tax,
				$child_of_current
			);
		}

		/**
		 * Prepare repeater options fields
		 */
		public function maybe_parse_repeater_options( $options ) {

			if ( ! is_array( $options ) || empty( $options ) ) {
				return array();
			}

			$option_values = array_values( $options );

			if ( ! is_array( $option_values[0] ) ) {
				return $options;
			}

			$result = array();

			foreach ( $options as $option ) {

				$values = array_values( $option );

				if ( ! isset( $values[0] ) ) {
					continue;
				}

				$result[ $values[0] ] = isset( $values[1] ) ? $values[1] : $values[0];

			}

			return $result;
		}

		/**
		 * Exclude or include items in options list
		 */
		public function maybe_include_exclude_options( $use_exclude_include, $exclude_include_options, $options, $is_grouped = false ) {

			if ( empty( $exclude_include_options ) ){
				return $options;
			}

			switch ( $use_exclude_include ) {
				case 'include' :
					$filtered_options = array();

					foreach ( $options as $key => $value ) {
						$search_key = $key;

						if ( is_object( $value ) ){
							$search_key = $value->term_id;
						}

						if ( is_array( $value ) && isset( $value['value'] ) ) {
							$search_key = $value['value'];
						}

						if ( in_array( $search_key, $exclude_include_options ) ){
							$filtered_options[ $key ] = $options[ $key ];
						}
					}

					$options = $filtered_options;
					break;
				case 'exclude' :
					foreach ( $options as $key => $value ) {
						$search_key = $key;

						if ( is_object( $value ) ){
							$search_key = $value->term_id;
						}

						if ( is_array( $value ) && isset( $value['value'] ) ) {
							$search_key = $value['value'];
						}

						if ( in_array( $search_key, $exclude_include_options ) ){
							unset( $options[ $key ] );
						}

						// If the parent element is excluded, preserve the hierarchy in its descendants
						if ( $is_grouped && is_object( $value ) ) {
							if ( in_array( $value->parent, $exclude_include_options ) ){
								$value->parent = 0;
							}
						}
					}
					break;
			}

			return $options;
		}

		/**
		 * Get tabindex attribute
		 */
		public function get_tabindex_attr() {

			$use_tabindex = filter_var( jet_smart_filters()->settings->get( 'use_tabindex', false ), FILTER_VALIDATE_BOOLEAN );

			return $use_tabindex
				? 'tabindex="0"'
				: '';
		}

		/**
		 * Safely get request array.
		 */
		public function get_request() {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request = $_REQUEST;

			return $request;
		}

		/**
		 * Safely get request parameter.
		 */
		public function get_request_var( $key, $default = NULL ) {

			$value = $default;

			// phpcs:disable
			if ( isset( $_REQUEST[ $key ] ) ) {
				$value = jet_smart_filters()->utils->sanitize_text_field_recursive(
					wp_unslash( $_REQUEST[ $key ] )
				);
			}
			// phpcs:enable

			return $value;
		}
	}
}
