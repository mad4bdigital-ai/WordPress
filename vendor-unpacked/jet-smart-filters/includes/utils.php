<?php
/**
 * Utils class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Utils' ) ) {
	/**
	 * Define Jet_Smart_Filters_Utils class
	 */
	class Jet_Smart_Filters_Utils {
		/**
		 * Сhecks if the filter exists and that it is published
		 */
		public function is_filter_published( $filter_id ) {

			if ( empty( $filter_id ) ) {
				return false;
			}

			global $wpdb;

			$filter_id = intval( $filter_id );

			$query = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} 
				WHERE post_type = 'jet-smart-filters'
				AND post_status = 'publish'
				AND ID = %d",
				$filter_id
			);

			$published_filter_id = $wpdb->get_var( $query );

			return $published_filter_id ? true : false;
		}

		/**
		 * Returns only published filters from the passed IDs.
		 */
		public function select_published_filters( $filter_ids ) {

			if ( ! is_array( $filter_ids ) || empty( $filter_ids ) ) {
				return array();
			}

			global $wpdb;

			$filter_ids_string = implode( ',', array_map( 'intval', $filter_ids ) );
		
			$query = "SELECT ID FROM {$wpdb->posts}
					  WHERE post_type = 'jet-smart-filters'
					  AND post_status = 'publish'
					  AND ID IN ($filter_ids_string)";
		
			$published_filters = $wpdb->get_col( $query );
		
			return $published_filters;
		}

		/**
		 * Returns HTML as string from file
		 */
		public function get_file_html( $path ) {

			ob_start();
			include jet_smart_filters()->plugin_path( $path );
			return ob_get_clean();
		}

		/**
		 * Returns template content as string
		 */
		public function get_template_html( $template ) {

			ob_start();
			include jet_smart_filters()->get_template( $template );
			$html = ob_get_clean();

			return preg_replace('~>\\s+<~m', '><', $html);
		}

		/**
		 * Returns parsed template
		 */
		public function template_parse( $template ) {

			$html_template = $this->get_template_html( $template );

			preg_match_all( '/\/%(.+?)%\//', $html_template, $matches, PREG_SET_ORDER );
			foreach ( $matches as $item ) {
				$prefix = ! preg_match( '/(if|for|else|{|})/', $item[0] ) ? 'echo ' : '';
				$html_template = str_replace( $item[0], '<?php ' . $prefix . trim( $item[1] ) . ' ?>', $html_template );
			}

			return $html_template;
		}

		/**
		 * Returns parsed template
		 */
		public function template_replace_with_value( $template, $value ) {

			$html_template = $this->get_template_html( $template );

			return preg_replace('/\/\s*%\s*\$value\s*%\s*\//', $value, $html_template );
		}

		/**
		 * Check whether the value is truthy or equals zero.
		 *
		 * Mirrors the frontend JS helper semantics.
		 */
		public function is_truthy_or_zero( $value ) {

			return false !== $value && null !== $value && '' !== $value;
		}

		/**
		 * Creates an associative array with value and label from input data of any type( $data = String / Array )
		 */
		public function сreate_option_data( $key, $data ) {

			$option = array(
				'value' => $key,
				'label' => $data
			);

			if ( is_array( $data ) ) {
				$option['label'] = $key;

				// merge option properties
				$option = array_merge( $option, $data );
			}

			return $option;
		}

		/**
		 * Generates HTML date attributes from array
		 */
		public function generate_data_attrs( $data ) {

			if ( ! is_array( $data ) ) {
				return '';
			}

			$data_attrs = '';

			foreach ( $data as $key => $value ) {
				$data_attrs .= 'data-' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES ) . '" ';
			}

			return trim( $data_attrs );
		}

		/**
		 * Returns image size array in slug => name format
		 */
		public function get_image_sizes() {

			global $_wp_additional_image_sizes;

			$sizes  = get_intermediate_image_sizes();
			$result = array();

			foreach ( $sizes as $size ) {
				if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
					$result[ $size ] = ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $size ) ) );
				} else {
					$result[ $size ] = sprintf(
						'%1$s (%2$sx%3$s)',
						ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $size ) ) ),
						$_wp_additional_image_sizes[ $size ]['width'],
						$_wp_additional_image_sizes[ $size ]['height']
					);
				}
			}

			return array_merge( array( 'full' => esc_html__( 'Full', 'jet-smart-filters' ), ), $result );
		}

		/**
		 * Returns additional providers
		 */
		public function get_additional_providers( $settings ) {

			if ( empty( $settings['additional_providers_enabled'] ) ) {
				return '';
			}

			if ( ! empty( $settings['additional_providers_list'] ) ) {
				$additional_providers = $settings['additional_providers_list'];
			} else if ( ! empty( $settings['additional_providers'] ) ) {
				// backward compatibility
				$additional_providers = array_map( function ( $additional_provider ) {
					return array( 'additional_provider' => $additional_provider );
				}, $settings['additional_providers'] );
			} else {
				return '';
			}

			$output_data      = [];
			$default_query_id = ! empty( $settings['query_id'] ) ? $settings['query_id'] : 'default';

			foreach ( $additional_providers as $additional_provider ) {
				$provider = ! empty( $additional_provider['additional_provider'] ) ? $additional_provider['additional_provider'] : false;
				$query_id = ! empty( $additional_provider['additional_query_id'] ) ? $additional_provider['additional_query_id'] : $default_query_id;

				if ( $provider ) {
					$output_data[] = $provider . ( $query_id ? '/' . $query_id : '' );
				}
			}

			return $output_data ? htmlspecialchars( json_encode( $output_data ) ) : '';
		}

		/**
		 * Merge Query Args
		 */
		public function merge_query_args( $current_query_args, $new_query_args ) {

			$merged_keys = array( 'tax_query', 'meta_query', 'post__not_in' );
			$merged_keys = apply_filters( 'jet-smart-filter/utils/merge-query-args/merged-keys', $merged_keys );
			$merged_keys = is_array( $merged_keys ) ? $merged_keys : array();

			foreach ( $new_query_args as $key => $value ) {
				if ( 'post__in' === $key ) {
					$value = ! is_array( $value ) && empty( $value ) ? array() : wp_parse_id_list( $value );

					if ( ! empty( $current_query_args[ $key ] ) ) {
						$value = array_values( array_intersect( wp_parse_id_list( $current_query_args[ $key ] ), $value ) );

						if ( empty( $value ) ) {
							$value = array( PHP_INT_MAX );
						}
					}

					$current_query_args[ $key ] = $value;

					continue;
				}

				if ( in_array( $key, $merged_keys, true ) && ! empty( $current_query_args[ $key ] ) ) {
					if ( 'post__not_in' === $key ) {
						$value = array_values(
							array_unique(
								array_merge(
									wp_parse_id_list( $current_query_args[ $key ] ),
									wp_parse_id_list( $value )
								)
							)
						);
					} else {
						$value = array_merge(
							is_array( $current_query_args[ $key ] ) ? $current_query_args[ $key ] : array( $current_query_args[ $key ] ),
							is_array( $value ) ? $value : array( $value )
						);
					}
				}

				$current_query_args[$key] = $value;
			}

			if ( ! empty( $current_query_args['post__in'] ) && ! empty( $current_query_args['post__not_in'] ) ) {
				$current_query_args['post__in'] = array_values(
					array_diff(
						wp_parse_id_list( $current_query_args['post__in'] ),
						wp_parse_id_list( $current_query_args['post__not_in'] )
					)
				);

				if ( empty( $current_query_args['post__in'] ) ) {
					$current_query_args['post__in'] = array( PHP_INT_MAX );
				}
			}

			return $current_query_args;
		}

		/**
		 * Remove meta query clauses by meta key.
		 */
		public function remove_meta_from_query( $query_args, $meta_key ) {

			if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
				return $query_args;
			}

			$clean_meta_query = function( $meta_query ) use ( $meta_key, &$clean_meta_query ) {
				$result = array();

				foreach ( $meta_query as $key => $clause ) {
					if ( 'relation' === $key ) {
						$result['relation'] = $clause;

						continue;
					}

					if ( ! is_array( $clause ) ) {
						$result[] = $clause;

						continue;
					}

					if ( isset( $clause['key'] ) && $meta_key === $clause['key'] ) {
						continue;
					}

					if ( isset( $clause['relation'] ) ) {
						$nested = $clean_meta_query( $clause );

						if ( count( $nested ) > 1 ) {
							$result[] = $nested;
						}

						continue;
					}

					$result[] = $clause;
				}

				return $result;
			};

			$query_args['meta_query'] = $clean_meta_query( $query_args['meta_query'] );

			return $query_args;
		}

		/**
		 * Insert in array after key
		 */
		public function array_insert_after( $source = array(), $after = null, $insert = array() ) {

			$index  = array_search( $after, array_keys( $source ) );

			if ( false === $index ) {
				return $source + $insert;
			}

			$offset = $index + 1;

			return array_slice( $source, 0, $offset, true ) + $insert + array_slice( $source, $offset, null, true );
		}

		/**
		 * Normalize redirect path to full URL.
		 */
		public function normalize_redirect_path_to_url( $redirect_path ) {

			$redirect_path = trim( $redirect_path );

			if ( ! $redirect_path || in_array( $redirect_path[0], array( '#', '?' ), true ) ) {
				return false;
			}

			if ( 0 === strpos( $redirect_path, '//' ) ) {
				return false;
			}

			if ( wp_parse_url( $redirect_path, PHP_URL_HOST ) ) {
				return $redirect_path;
			}

			$redirect_path = $this->strip_home_path_from_redirect_path( $redirect_path );

			return home_url( '/' . ltrim( $redirect_path, '/' ) );
		}

		/**
		 * Append query string and fragment from source URL.
		 */
		public function append_url_query_and_fragment( $url, $source_url ) {

			$source_url_parts = wp_parse_url( $source_url );

			if ( ! empty( $source_url_parts['query'] ) ) {
				$url .= false === strpos( $url, '?' ) ? '?' : '&';
				$url .= $source_url_parts['query'];
			}

			if ( ! empty( $source_url_parts['fragment'] ) ) {
				$url .= '#' . $source_url_parts['fragment'];
			}

			return $url;
		}

		/**
		 * Convert URL to relative redirect path.
		 */
		public function get_relative_redirect_path( $redirect_url ) {

			$redirect_path = trim( wp_make_link_relative( $redirect_url ), '/' );
			$redirect_path = $this->strip_home_path_from_redirect_path( $redirect_path );

			return '' === $redirect_path ? '/' : $redirect_path;
		}

		/**
		 * Strip WordPress home path from redirect path.
		 */
		public function strip_home_path_from_redirect_path( $redirect_path ) {

			$home_path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
			$home_path = $home_path ? trim( $home_path, '/' ) : '';

			if ( ! $home_path ) {
				return $redirect_path;
			}

			$redirect_path = ltrim( $redirect_path, '/' );

			if ( $home_path === $redirect_path ) {
				return '';
			}

			foreach ( array( '/', '?', '#' ) as $separator ) {
				if ( 0 === strpos( $redirect_path, $home_path . $separator ) ) {
					return ltrim( substr( $redirect_path, strlen( $home_path ) ), '/' );
				}
			}

			return $redirect_path;
		}

		/**
		 * Returns URL with filters applied
		 */
		public function get_filtered_url( $base_url = false, $query_id = null, $provider = '', $args = array() ) {

			$query_args = array();
			$url_symbols = jet_smart_filters()->data->url_symbol;

			foreach ( $args as $arg ) {
				$value     = $arg['value'];
				$query_var = $arg['query_var'];

				switch ( $arg['query_type'] ) {
					case 'tax_query':
						$query_type = 'tax';

						break;

					case 'meta_query':
						$query_type = 'meta';

						break;

					case 'date_query':
						$query_type = 'date';
						$query_var  = false;
						$value      = str_replace( '/', '-', $value );

						break;

					case 'sort':
						$query_var = false;
						break;

					case '_s':
						$query_var = false;
						break;

					default:
						$query_type = $arg['query_type'];
						break;
				}

				switch ( $arg['filter_type'] ) {
					case 'range':
					case 'check-range':
						$query_var .= '!' . $arg['filter_type'];

						break;

					case 'date-range':
					case 'date-period':
						if ( 'meta' === $query_type ) {
							$query_var .= '!date';
						}

						break;

					case 'pagination':
						$query_type = 'pagenum';

						break;

					case 'search':
						if ( 'meta' === $query_type ) {
							$query_type = '_s';
							$value     .= '!meta=' . $query_var;
							$query_var  = false;
						}

						break;

					default:
						if ( ! empty( $arg['suffix'] ) )
							$query_var .= '!' . $arg['suffix'];

						break;
				}

				if ( $query_var ) {
					$value = $query_var . $url_symbols['key_value'] . $value;
				}

				if ( ! isset( $query_args[ $query_type ] ) ) {
					$query_args[ $query_type ] = $value;
				} else {
					if ( ! is_array( $query_args[ $query_type ] ) ) {
						$query_args[ $query_type ] = array( $query_args[ $query_type ] );
					}

					$query_args[ $query_type ][] = $value;
				}
			}

			/**
			 * @todo Merge smae keys and process hierarchy
			 */
			$url_type = jet_smart_filters()->settings->url_structure_type;

			if ( ! $base_url ) {
				$base_url = $_SERVER['REQUEST_URI']; // phpcs:ignore
			}

			$name_parts = array( $provider );

			if ( $query_id ) {
				$name_parts[] = $query_id;
			}

			$provider_name = implode( $url_symbols['provider_id'], $name_parts );
			$result        = trailingslashit( $base_url );

			switch ( $url_type ) {
				case 'permalink':
					$result .= 'jsf/' . $provider_name . '/';

					if ( isset( $query_args['_s'] ) ) {
						$query_args['search'] = $query_args['_s'];
						unset( $query_args['_s'] );
					}

					foreach ( $query_args as $key => $value ) {
						if ( is_array( $value ) ) {
							$value = implode( $url_symbols['items_separator'], $value );
						}
					
						$encoded_value = rawurlencode( $value );
						$encoded_value = str_replace(
							array(
								rawurlencode( $url_symbols['key_value'] ),
								rawurlencode( $url_symbols['items_separator'] ),
								rawurlencode( $url_symbols['var_suffix'] ),
							),
							array(
								$url_symbols['key_value'],
								$url_symbols['items_separator'],
								$url_symbols['var_suffix'],
							),
							$encoded_value
						);
					
						$result .= rawurlencode( $key ) . '/' . $encoded_value . '/';
					}

					break;

				default:
					foreach ( $query_args as $key => $data ) {
						if ( is_array( $data ) ) {
							$query_args[ $key ] = implode( $url_symbols['items_separator'], $data );
						}
					}

					$query_args = array_merge( array( 'jsf' => $provider_name ), $query_args );
					$result     = add_query_arg( $query_args, $result );

					break;
			}

			return jet_smart_filters()->URL_aliases->use_url_aliases
				? jet_smart_filters()->URL_aliases->apply_aliases_to_url( $result )
				: $result;
		}

		/**
		 * Adds a condition for the control
		 */
		public function add_control_condition( $settings_list, $control_key, $condition_key, $condition_value ) {

			if ( ! isset( $settings_list[$control_key] ) ) {
				return $settings_list;
			}

			if ( ! isset( $settings_list[$control_key]['conditions'] ) ) {
				$settings_list[$control_key]['conditions'] = array();
			}

			if ( ! isset( $settings_list[$control_key]['conditions'][$condition_key] ) ) {
				$settings_list[$control_key]['conditions'][$condition_key] = $condition_value;
			} else {
				if ( ! is_array( $settings_list[$control_key]['conditions'][$condition_key] ) ) {
					$current_condition_value = $settings_list[$control_key]['conditions'][$condition_key];

					$settings_list[$control_key]['conditions'][$condition_key] = array( $current_condition_value );
				}
				
				array_push( $settings_list[$control_key]['conditions'][$condition_key], $condition_value );
			}

			return $settings_list;
		}

		/**
		 * Returns file content
		 */
		public function get_file_content( $file_path ) {

			if ( ! file_exists( $file_path ) ) {
				return false;
			}

			ob_start();
			include $file_path;

			return ob_get_clean();
		}

		/**
		 * Convert hex color to rgba
		 */
		public function hex2rgba( $color, $opacity = false ) {
 
			$default = 'rgb(0,0,0)';
		 
			//Return default if no color provided
			if ( empty( $color ) ) {
				return $default;
			}
		 
			//Sanitize $color if "#" is provided 
			if ( $color[0] == '#' ) {
				$color = substr( $color, 1 );
			}
		
			//Check if color has 6 or 3 characters and get values
			if ( strlen( $color ) == 6 ) {
				$hex = array( $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] );
			} elseif ( strlen( $color ) == 3 ) {
				$hex = array( $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] );
			} else {
				return $default;
			}
		
			//Convert hexadec to rgb
			$rgb = array_map( 'hexdec', $hex );
		
			//Check if opacity is set(rgba or rgb)
			if ( $opacity ) {
				if ( abs( $opacity ) > 1 ) {
					$opacity = 1;
				}
				$output = 'rgba(' . implode( ",", $rgb ). ',' . $opacity . ')';
			} else {
				$output = 'rgb(' . implode( ",", $rgb ) . ')';
			}
		
			//Return rgb(a) color string
			return $output;
		}

		/**
		 * Checks if the current request is a WP REST API request
		 */
		public function is_rest_request() {

			$rest_url    = wp_parse_url( trailingslashit( rest_url() ) );
			$current_url = wp_parse_url( add_query_arg( array() ) );
			
			return strpos( $current_url['path'] ?? '/', $rest_url['path'], 0 ) === 0;
		}

		/**
		 * Recursive stripslashes
		 */
		public function stripslashes( $value ) {
			$value = is_array($value) ?
						array_map( array( $this, 'stripslashes' ), $value ) :
						stripslashes($value);

			return $value;
		}

		/**
		 * Сonvert array with term slugs to ids
		 */
		public function convert_term_slugs_to_ids( $array ) {

			global $wpdb;
		
			// collect all slugs into one array
			$slugs         = [];
			$flatten_array = function( $value ) use ( &$slugs ) {
				if ( is_string( $value ) ) {
					$slugs[] = $value;
				}
			};

			array_walk_recursive( $array, $flatten_array );
		
			// if the array with slugs is empty
			if ( empty( $slugs ) ) {
				return $array;
			}

			// get all term IDs by slugs
			$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
			$query        = $wpdb->prepare(
				"SELECT t.term_id, t.slug FROM {$wpdb->terms} t
				WHERE t.slug IN ($placeholders)",
				...$slugs
			);

			$terms = $wpdb->get_results( $query );
	
			// convert the result to the format [slug => term_id]
			$term_map = [];

			foreach ( $terms as $term ) {
				$term_map[$term->slug] = $term->term_id;
			}
	
			// recursively replace slugs with term_id in the original array
			array_walk_recursive( $array, function( &$value ) use ( $term_map ) {
				if (is_string( $value ) && isset( $term_map[$value] ) ) {
					$value = $term_map[$value];
				}
			});
		
			return $array;
		}

		/**
		 * Check whether the given WP_Query (or the global query) is a WooCommerce products query.
		 */
		public function is_wc_products_query( $q = null ) {

			if ( ! $q ) {
				global $wp_query;
				$q = $wp_query;
			}

			// explicit product post_type
			if ( $q->get( 'post_type' ) === 'product' ) {
				return true;
			}

			// any taxonomy attached to the product post type
			if ( $q->is_tax() ) {
				$tax = $q->get( 'taxonomy' );

				if ( $tax ) {
					$object_types = get_taxonomy( $tax )->object_type;

					if ( in_array( 'product', $object_types, true ) ) {
						return true;
					}
				}
			}

			// shop page
			if ( function_exists( 'is_shop' ) && is_shop() ) {
				return true;
			}

			return false;
		}

		/**
		 * Function to merge arrays by the key of the first array and the value of the second
		 */
		function mergeArraysByKeyAndValue( $firstArray, $secondArray, $valueKey = 'value' ) {

			$resultArray = [];
		
			foreach ( $firstArray as $key => $item ) {
				foreach ( $secondArray as $secondItem ) {
					if ( ! isset( $secondItem[$valueKey] ) || $secondItem[$valueKey] != $key ) {
						continue;
					}

					foreach ( $secondItem as $secondKey => $secondValue ) {
						if ( ! array_key_exists( $secondKey, $item ) || ( ! empty( $secondValue ) && empty( $item[$secondKey] ) ) ) {
							$item[$secondKey] = $secondValue;
						}
					}

					$resultArray[] = $item;

					break;
				}
			}
		
			return $resultArray;
		}

		/**
		 * Recursively strips slashes from strings or arrays.
		 *
		 * @param mixed $value Value to process.
		 * @return mixed Cleaned value.
		 */
		public function stripslashes_deep( $value ) {
			if (is_array( $value ) ) {
				return array_map( 'stripslashes_deep', $value );
			} else {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		}

		/**
		 * Recursively sanitize text fields (string or array)
		 *
		 * @param mixed $value
		 * @return mixed
		 */
		public function sanitize_text_field_recursive( $value ) {

			if ( is_array( $value ) ) {
				return array_map( array( $this, 'sanitize_text_field_recursive' ), $value );
			}

			return sanitize_text_field( $value );
		}

		/**
		 * Prepare text values for safe literal usage in a REGEXP pattern.
		 *
		 * @param mixed $value     Value or nested values to prepare.
		 * @param bool  $lowercase Whether to lowercase values before escaping.
		 * @return array
		 */
		public function prepare_regexp_literal_values( $value, $lowercase = false ) {

			$values = array();

			if ( ! is_array( $value ) ) {
				$value = array( $value );
			}

			array_walk_recursive( $value, function( $item ) use ( &$values, $lowercase ) {

				if ( ! is_scalar( $item ) ) {
					return;
				}

				$item = sanitize_text_field( wp_unslash( (string) $item ) );

				if ( ! $this->is_truthy_or_zero( $item ) ) {
					return;
				}

				if ( $lowercase ) {
					$item = mb_strtolower( $item );
				}

				$values[] = preg_quote( $item, '/' );
			} );

			return array_values( array_unique( $values ) );
		}

		/**
		 * Safely unserialize data without allowing object instantiation.
		 *
		 * @param mixed $value   Serialized value.
		 * @param mixed $default Default value on failure.
		 * @return mixed
		 */
		public function safe_unserialize( $value, $default = false ) {

			if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
				return $default;
			}

			$value  = trim( $value );
			$result = @unserialize( $value, array( 'allowed_classes' => false ) );

			if ( false === $result && 'b:0;' !== $value ) {
				return $default;
			}

			return $result;
		}

		/**
		* Check whether the given array contains at least one nested array.
		*
		* @param array $array The array to inspect.
		* @return bool Returns true if the array contains at least one element that is itself an array, false otherwise.
		*/
		public function hasNestedArray( array $array ): bool {

			foreach ( $array as $value ) {
				if ( is_array( $value ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Safely adds a value to a multidimensional array at the specified path,
		 * creating nested arrays if they do not exist. Does not overwrite existing keys.
		 *
		 * @param array       &$array  Reference to the array where data will be added
		 * @param array       $keys    Array of keys representing the nesting path
		 * @param mixed       $value   Value to add
		 * @param string|null $key     Optional key for the value in the final array
		 */
		function add_nested_array_value( array &$array, array $keys, $value, $key = null ) {

			$temp =& $array;

			// Traverse/create nested arrays
			foreach ( $keys as $k ) {
				if ( !isset( $temp[$k] ) || !is_array( $temp[$k] ) ) {
					$temp[$k] = [];
				}
				$temp =& $temp[$k];
			}

			if ( $key !== null ) {
				// Add only if key does not exist
				if ( !array_key_exists( $key, $temp ) ) {
					$temp[$key] = $value;
				}
			} else {
				// If value is array, merge only new values
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						if ( !in_array( $v, $temp, true ) ) {
							$temp[] = $v;
						}
					}
				} else {
					if ( !in_array( $value, $temp, true ) ) {
						$temp[] = $value;
					}
				}
			}
		}

		/**
		 * Sanitize HTML allowing icons (i, span, svg).
		 */
		function sanitize_icon_html( $html ) {

			if ( empty( $html ) ) {
				return '';
			}

			$allowed_html = array(
				'i' => array(
					'class' => true,
					'aria-hidden' => true,
				),
				'span' => array(
					'class' => true,
					'aria-hidden' => true,
				),
				'svg' => array(
					'class' => true,
					'aria-hidden' => true,
					'role' => true,
					'viewbox' => true,
					'xmlns' => true,
					'width' => true,
					'height' => true,
					'fill' => true,
				),
				'path' => array(
					'd' => true,
					'fill' => true,
				),
			);

			return wp_kses( $html, $allowed_html );
		}

		/**
		 * Build SQL subquery from query args for the given object type.
		 */
		function build_query_sql_from_args( $args, $object_type = 'post' ) {

			$query_type_map = array(
				'post' => 'posts',
				'user' => 'users',
			);

			if ( empty( $args ) || ! is_array( $args ) ) {
				return null;
			}

			unset(
				$args['jet_smart_filters'],
				$args['suppress_filters']
			);

			if ( empty( $args['_query_type'] ) && isset( $query_type_map[ $object_type ] ) ) {
				$args['_query_type'] = $query_type_map[ $object_type ];
			}

			if ( ! empty( $args['_query_type'] ) && 'users' === $args['_query_type'] ) {
				return $this->build_user_query_sql_from_args( $args );
			}

			return $this->build_post_query_sql_from_args( $args );
		}

		/**
		 * Build SQL subquery from post query args.
		 */
		function build_post_query_sql_from_args( $args ) {

			$sql = null;

			unset( $args['_query_type'] );

			$filter = function( $request, $query ) use ( &$sql ) {

				$sql = $request;

				// prevent execution of real SQL
				return "SELECT 1 WHERE 0";

			};

			add_filter( 'posts_request', $filter, 10, 2 );

			$q = new WP_Query();
			$q->query( $args );

			remove_filter( 'posts_request', $filter, 10 );

			return $sql;
		}

		/**
		 * Build SQL subquery from user query args.
		 */
		function build_user_query_sql_from_args( $args ) {

			unset( $args['_query_type'] );

			$user_query = new WP_User_Query();

			if ( ! method_exists( $user_query, 'prepare_query' ) ) {
				return null;
			}

			$user_query->prepare_query(
				wp_parse_args(
					$args,
					array(
						'number'      => -1,
						'count_total' => false,
						'fields'      => array( 'ID' ),
					)
				)
			);

			$user_query->query_fields = 'SELECT ' . $GLOBALS['wpdb']->users . '.ID AS ID';

			$sql_parts = array_filter(
				array(
					$user_query->query_fields,
					$user_query->query_from,
					$user_query->query_where,
					$user_query->query_orderby,
					$user_query->query_limit,
				)
			);

			return implode( ' ', $sql_parts );
		}
	}
}
