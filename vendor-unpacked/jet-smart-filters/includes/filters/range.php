<?php
/**
 * Range filter class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Range_Filter' ) ) {
	/**
	 * Define Jet_Smart_Filters_Range_Filter class
	 */
	class Jet_Smart_Filters_Range_Filter extends Jet_Smart_Filters_Filter_Base {

		private $_pending_dynamic_ranges = array();

		/**
		 * Constructor for the class
		 */
		public function __construct() {

			// Update dynamic price range via AJAX request
			add_filter(
				'jet-smart-filters/render/ajax/data',
				function ( $render_data ) {
					$request = jet_smart_filters()->data->get_request();

					if ( ! empty( $request['dynamic_range'] ) ) {
						foreach ( $request['dynamic_range'] as $dynamic_key => $dynamic_data ) {
							$dynamic_cb = apply_filters( 'jet-smart-filters/range-filter/string-callback-callable', $dynamic_key );

							if ( ! is_callable( $dynamic_cb ) ) {
								continue;
							}

							foreach ( $dynamic_data as $query_var ) {
								$query_args = $this->get_ajax_dynamic_range_query_args( $query_var );

								if ( ! $this->is_dynamic_range_update_enabled( $query_args ) ) {
									continue;
								}

								$data = $this->get_range_source_data( $dynamic_cb, $query_var, $query_args );

								$min  = isset( $data['min'] ) ? $data['min'] : false;
								$max  = isset( $data['max'] ) ? $data['max'] : false;

								if ( false === $min || false === $max ) {
									continue;
								}

								$min_max_data = array(
									'min' => $min,
									'max' => $max
								);

								jet_smart_filters()->utils->add_nested_array_value( $render_data, array( 'dynamic_range' ), $min_max_data, $query_var );
							}
						}
					}

					return $render_data;
				}
			);

			add_filter( 'jet-smart-filters/filters/localized-data', array( $this, 'prepare_localized_data' ) );
		}

		/**
		 * Get provider name
		 */
		public function get_name() {

			return __( 'Range', 'jet-smart-filters' );
		}

		/**
		 * Get provider ID
		 */
		public function get_id() {

			return 'range';
		}

		/**
		 * Get icon URL
		 */
		public function get_icon_url() {

			return jet_smart_filters()->plugin_url( 'admin/assets/img/filter-types/range.png' );
		}

		/**
		 * Get provider wrapper selector
		 */
		public function get_scripts() {

			return false;
		}

		private function max_value_for_current_step( $max, $min, $step ) {

			$max  = (float) $max;
			$min  = (float) $min;
			$step = (float) $step;

			if ( $step <= 0 ) {
				return $max;
			}

			$steps_count = ceil( round( ( $max - $min ) / $step, 10 ) );

			return $steps_count * $step + $min;
		}

		/**
		 * Check if dynamic range bounds should react to provider queries.
		 *
		 * @return bool
		 */
		private function is_dynamic_range_update_enabled( $query_args = array() ) {

			return Jet_Smart_Filters_Range_Dynamic_Data_Helper::can_use_indexed_range( $query_args, 'post' );
		}

		/**
		 * Build query args used to check whether dynamic range can rely on indexed data.
		 *
		 * @param string $source_cb        Dynamic range callback.
		 * @param string $source_post_type Filter source post type.
		 * @param array  $provider_query   Provider query args.
		 *
		 * @return array
		 */
		private function get_dynamic_range_indexer_query_args( $source_cb, $source_post_type = '', $provider_query = array() ) {

			$query_args = ! empty( $provider_query ) && is_array( $provider_query ) ? $provider_query : array();

			if ( empty( $query_args['post_type'] ) ) {
				if ( 'jet_smart_filters_woo_prices' === $source_cb ) {
					$query_args['post_type'] = 'product';
				} elseif ( $source_post_type && 'post' !== $source_post_type ) {
					// Range filters can keep `post` as an admin default even when the provider uses another CPT.
					$query_args['post_type'] = $source_post_type;
				}
			}

			return $query_args;
		}

		/**
		 * Check if query contains meaningful taxonomy or meta clauses.
		 *
		 * @param array  $query_args Current query args.
		 * @param string $query_key  Query key to inspect.
		 *
		 * @return bool
		 */
		private function has_query_clauses( $query_args, $query_key ) {

			if ( empty( $query_args[ $query_key ] ) || ! is_array( $query_args[ $query_key ] ) ) {
				return false;
			}

			foreach ( $query_args[ $query_key ] as $key => $clause ) {
				if ( 'relation' === $key ) {
					continue;
				}

				if ( ! empty( $clause ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get min/max data from the range source callback.
		 *
		 * @param callable $source_cb  Source callback.
		 * @param string   $query_var  Range meta key.
		 * @param array    $query_args Current query args.
		 *
		 * @return array|false|null
		 */
		private function get_range_source_data( $source_cb, $query_var, $query_args = array() ) {

			$callback_args = array(
				'meta_key' => $query_var,
				'key'      => $query_var,
				'query'    => $query_args,
			);

			$data = apply_filters( 'jet-smart-filters/range-filter/source-data', false, $source_cb, $query_var, $query_args );

			if ( false !== $data ) {
				return $data;
			}

			return call_user_func( $source_cb, $callback_args );
		}

		/**
		 * Prepare filter template argumnets
		 */
		public function prepare_args( $args ) {

			$filter_id            = $args['filter_id'];
			$content_provider     = isset( $args['content_provider'] ) ? $args['content_provider'] : false;
			$query_id             = isset( $args['query_id'] ) ? $args['query_id'] : 'default';
			$additional_providers = isset( $args['additional_providers'] ) ? $args['additional_providers'] : false;
			$apply_type           = isset( $args['apply_type'] ) ? $args['apply_type'] : false;
			$apply_on             = isset( $args['apply_on'] ) ? $args['apply_on'] : false;

			if ( ! $filter_id ) {
				return false;
			}

			$query_type                = 'meta_query';
			$query_var                 = get_post_meta( $filter_id, '_query_var', true );
			$inputs_enabled            = filter_var( get_post_meta( $filter_id, '_range_inputs_enabled', true ), FILTER_VALIDATE_BOOLEAN );
			$inputs_separators_enabled = filter_var( get_post_meta( $filter_id, '_range_inputs_separators_enabled', true ), FILTER_VALIDATE_BOOLEAN );
			$prefix                    = get_post_meta( $filter_id, '_values_prefix', true );
			$suffix                    = get_post_meta( $filter_id, '_values_suffix', true );
			$source_cb                 = get_post_meta( $filter_id, '_source_callback', true );
			$source_cb_id              = $source_cb;
			$source_post_type          = get_post_meta( $filter_id, '_source_post_type', true );
			$min                       = false;
			$max                       = false;
			$step                      = get_post_meta( $filter_id, '_source_step', true );
			$decimal_num               = get_post_meta( $filter_id, '_values_decimal_num', true );
			$decimal_sep               = get_post_meta( $filter_id, '_values_decimal_sep', true );
			$thousand_sep              = get_post_meta( $filter_id, '_values_thousand_sep', true );
			$format                    = array(
				'decimal_num'   => $decimal_num ? absint( $decimal_num ) : 0,
				'decimal_sep'   => $decimal_sep ? $decimal_sep : '.',
				'thousands_sep' => $thousand_sep ? $thousand_sep : ''
			);
			$predefined_value          = $this->get_predefined_value( $filter_id );
			$provider_query_args       = $this->get_provider_dynamic_range_query_args( $content_provider, $query_id );

			$step = (float) $step;

			if ( $step <= 0 ) {
				$step = 1;
			}

			/**
			 * Allow to convert non-callable string callback name to callable array/object
			 */
			$source_cb = apply_filters( 'jet-smart-filters/range-filter/string-callback-callable', $source_cb );
			$indexer_query_args    = $this->get_dynamic_range_indexer_query_args( $source_cb, $source_post_type, $provider_query_args );
			$update_dynamic_range = $this->is_dynamic_range_update_enabled( $indexer_query_args );

			if ( is_callable( $source_cb ) ) {
				if ( $update_dynamic_range && jet_smart_filters()->query->is_ajax_filter() ) {
					$data = $this->get_range_source_data( $source_cb, $query_var, $this->get_ajax_dynamic_range_query_args( $query_var ) );

					$min  = isset( $data['min'] ) ? $data['min'] : false;
					$max  = isset( $data['max'] ) ? $this->max_value_for_current_step( $data['max'], $min, $step ) : false;
				} else {
					$range_query_args = $update_dynamic_range
						? array()
						: $this->prepare_dynamic_range_query_args( $indexer_query_args, $query_var );

					$data = $this->get_range_source_data( $source_cb, $query_var, $range_query_args );

					$min  = isset( $data['min'] ) ? $data['min'] : false;
					$max  = isset( $data['max'] ) ? $this->max_value_for_current_step( $data['max'], $min, $step ) : false;

					if ( $update_dynamic_range ) {
						$this->register_dynamic_range_for_preload( $content_provider, $query_id, $source_cb, $query_var, $step );
					}
				}
			}

			if ( false === $min ) {
				$min = (float)get_post_meta( $filter_id, '_source_min', true );
			}

			if ( false === $max ) {
				$max = get_post_meta( $filter_id, '_source_max', true );

				if ( $max === '' ) {
					$max = 100;
				} else {
					$max = (float)$max;
				}
			}

			$result = array(
				'options'                   => false,
				'min'                       => $min,
				'max'                       => $max,
				'step'                      => $step,
				'format'                    => $format,
				'query_type'                => $query_type,
				'query_var'                 => $query_var,
				'query_var_suffix'          => jet_smart_filters()->filter_types->get_filter_query_var_suffix( $filter_id ),
				'content_provider'          => $content_provider,
				'additional_providers'      => $additional_providers,
				'apply_type'                => $apply_type,
				'apply_on'                  => $apply_on,
				'inputs_enabled'            => $inputs_enabled,
				'inputs_separators_enabled' => $inputs_separators_enabled,
				'prefix'                    => jet_smart_filters_macros( $prefix ),
				'suffix'                    => jet_smart_filters_macros( $suffix ),
				'filter_id'                 => $filter_id,
				'accessibility_label'       => $this->get_accessibility_label( $filter_id )
			);

			if ( $update_dynamic_range && $source_cb_id && $source_cb_id !== 'none' ) {
				$result['dynamic_range'] = $source_cb_id;
			}

			if ( $predefined_value !== false ) {
				$result['predefined_value'] = $predefined_value;
			}

			return $result;
		}

		public function additional_filter_data_atts( $args ) {

			$additional_filter_data_atts = array();

			if ( ! empty( $args['format'] ) ) {
				$additional_filter_data_atts['data-format'] = $args['format'];
			}

			return $additional_filter_data_atts;
		}

		/**
		 * Prepare dynamic range data for localized frontend settings.
		 *
		 * @param array $args Localized data.
		 *
		 * @return array
		 */
		public function prepare_localized_data( $args ) {

			if ( ! $this->is_dynamic_range_update_enabled() ) {
				return $args;
			}

			if ( empty( $this->_pending_dynamic_ranges ) ) {
				return $args;
			}

			$dynamic_ranges = array();

			foreach ( $this->_pending_dynamic_ranges as $provider => $provider_queries ) {
				foreach ( $provider_queries as $query_id => $provider_dynamic_ranges ) {
					$query_args = $this->get_provider_dynamic_range_query_args( $provider, $query_id );

					$provider_key = $provider . '/' . $query_id;

					foreach ( $provider_dynamic_ranges as $query_var => $range_settings ) {
						$prepared_query_args = $this->prepare_dynamic_range_query_args( $query_args, $query_var );

						if ( empty( $prepared_query_args ) ) {
							continue;
						}

						if ( ! $this->has_query_clauses( $prepared_query_args, 'tax_query' ) && ! $this->has_query_clauses( $prepared_query_args, 'meta_query' ) ) {
							continue;
						}

						$data = $this->get_range_source_data( $range_settings['source_cb'], $query_var, $prepared_query_args );

						$min  = isset( $data['min'] ) ? $data['min'] : false;
						$max  = isset( $data['max'] ) ? $this->max_value_for_current_step( $data['max'], $min, $range_settings['step'] ) : false;

						if ( false === $min || false === $max ) {
							continue;
						}

						jet_smart_filters()->utils->add_nested_array_value(
							$dynamic_ranges,
							array( $provider_key ),
							array(
								'min' => $min,
								'max' => $max,
							),
							$query_var
						);
					}
				}
			}

			if ( ! empty( $dynamic_ranges ) ) {
				$args['jetFiltersDynamicRange'] = $dynamic_ranges;
			}

			return $args;
		}

		/**
		 * Register rendered dynamic range filter for footer preload.
		 *
		 * @param string $content_provider Current provider ID.
		 * @param string $query_id         Current provider query ID.
		 * @param string $source_cb        Dynamic range callback.
		 * @param string $query_var        Range meta key.
		 * @param int    $step             Range step.
		 *
		 * @return void
		 */
		private function register_dynamic_range_for_preload( $content_provider, $query_id, $source_cb, $query_var, $step ) {

			if ( ! $content_provider || ! $query_id || ! $source_cb || ! $query_var ) {
				return;
			}

			if ( empty( $this->_pending_dynamic_ranges[ $content_provider ] ) ) {
				$this->_pending_dynamic_ranges[ $content_provider ] = array();
			}

			if ( empty( $this->_pending_dynamic_ranges[ $content_provider ][ $query_id ] ) ) {
				$this->_pending_dynamic_ranges[ $content_provider ][ $query_id ] = array();
			}

			$this->_pending_dynamic_ranges[ $content_provider ][ $query_id ][ $query_var ] = array(
				'source_cb' => $source_cb,
				'step'      => $step,
			);
		}

		/**
		 * Build dynamic range query args for AJAX requests.
		 *
		 * @param string $query_var Current range meta key.
		 *
		 * @return array
		 */
		private function get_ajax_dynamic_range_query_args( $query_var ) {

			return $this->prepare_dynamic_range_query_args(
				jet_smart_filters()->query->get_query_args(),
				$query_var
			);
		}

		/**
		 * Build provider query args for initial dynamic range preload.
		 *
		 * @param string $content_provider Current provider ID.
		 * @param string $query_id         Current provider query ID.
		 *
		 * @return array
		 */
		private function get_provider_dynamic_range_query_args( $content_provider, $query_id ) {

			$executed_query = jet_smart_filters()->query->get_executed_query( $content_provider, $query_id );

			if ( ! empty( $executed_query ) ) {
				$request_query_args = $this->get_current_provider_request_query_args( $content_provider, $query_id );

				if ( ! empty( $request_query_args ) ) {
					$executed_query = jet_smart_filters()->utils->merge_query_args( $executed_query, $request_query_args );
				}

				return $executed_query;
			}

			$default_queries = jet_smart_filters()->query->get_default_queries();

			if ( empty( $default_queries[ $content_provider ][ $query_id ] ) ) {
				return $this->get_current_provider_request_query_args( $content_provider, $query_id );
			}

			$query_args = $default_queries[ $content_provider ][ $query_id ];
			$request_query_args = jet_smart_filters()->query->get_query_args();

			if ( ! empty( $request_query_args ) ) {
				$query_args = jet_smart_filters()->utils->merge_query_args( $query_args, $request_query_args );
			}

			return $query_args;
		}

		/**
		 * Get current request query args for the requested provider/query ID pair.
		 *
		 * @param string $content_provider Current provider ID.
		 * @param string $query_id         Current provider query ID.
		 *
		 * @return array
		 */
		private function get_current_provider_request_query_args( $content_provider, $query_id ) {

			$current_provider = jet_smart_filters()->query->get_current_provider();

			if ( empty( $current_provider['provider'] ) || empty( $current_provider['query_id'] ) ) {
				return array();
			}

			if ( $content_provider !== $current_provider['provider'] || $query_id !== $current_provider['query_id'] ) {
				return array();
			}

			$request_query_args = jet_smart_filters()->query->get_query_args();

			return ! empty( $request_query_args ) ? $request_query_args : array();
		}

		/**
		 * Cleanup query args before dynamic range calculation.
		 *
		 * @param array  $query_args Current query args.
		 * @param string $query_var  Current range meta key.
		 *
		 * @return array
		 */
		private function prepare_dynamic_range_query_args( $query_args, $query_var ) {

			if ( empty( $query_args ) || ! is_array( $query_args ) ) {
				return array();
			}

			$query_args = jet_smart_filters()->utils->remove_meta_from_query( $query_args, $query_var );

			unset(
				$query_args['paged'],
				$query_args['page'],
				$query_args['offset'],
				$query_args['found_posts'],
				$query_args['max_num_pages']
			);

			return $query_args;
		}
	}
}
