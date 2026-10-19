<?php
/**
 * Range dynamic data helper.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Range_Dynamic_Data_Helper' ) ) {
	/**
	 * Range dynamic data helper class.
	 */
	class Jet_Smart_Filters_Range_Dynamic_Data_Helper {

		/**
		 * Get min/max for meta key.
		 *
		 * @param array  $query Query arguments.
		 * @param string $type  Object type.
		 *
		 * @return array
		 */
		public static function normalize_indexed_range_query( $query = array(), $type = 'post' ) {

			if ( empty( $query ) || ! is_array( $query ) ) {
				return array();
			}

			$ignored_keys = array(
				'jet_smart_filters',
				'suppress_filters',
				'paged',
				'page',
				'offset',
				'posts_per_page',
				'posts_per_archive_page',
				'nopaging',
				'no_found_rows',
				'cache_results',
				'update_post_term_cache',
				'update_menu_item_cache',
				'lazy_load_term_meta',
				'update_post_meta_cache',
				'ignore_sticky_posts',
				'comments_per_page',
				'order',
				'orderby',
				'fields',
				'wc_query',
				'error',
				'm',
				'second',
				'minute',
				'hour',
				'day',
				'monthnum',
				'year',
				'w',
				'preview',
				'sentence',
				'title',
				'embed',
			);

			foreach ( $ignored_keys as $key ) {
				unset( $query[ $key ] );
			}

			foreach ( $query as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = array_filter( $value, function( $item ) {
						return '' !== $item && null !== $item && false !== $item;
					} );
				}

				if ( '' === $value || null === $value || false === $value || array() === $value ) {
					unset( $query[ $key ] );
				}
			}

			if ( isset( $query['post_status'] ) ) {
				$post_status = (array) $query['post_status'];
				$post_status = array_values( array_unique( $post_status ) );

				if ( 1 === count( $post_status ) && 'publish' === $post_status[0] ) {
					unset( $query['post_status'] );
				}
			}

			if ( 'post' === $type && isset( $query['post_type'] ) ) {
				$post_type = (array) $query['post_type'];
				$post_type = array_values( array_unique( $post_type ) );

				if ( 1 === count( $post_type ) && 'post' === $post_type[0] ) {
					unset( $query['post_type'] );
				}
			}

			return apply_filters( 'jet-smart-filters/indexed-range/normalized-query', $query, $type );
		}

		/**
		 * Check if indexed range queries can use the indexer table.
		 *
		 * @return bool
		 */
		public static function can_use_indexed_range( $query = array(), $type = 'post' ) {

			if ( ! self::can_use_indexer_table() ) {
				return false;
			}

			return self::is_query_indexable( $query, $type );
		}

		/**
		 * Check if the indexer table can be queried.
		 *
		 * @return bool
		 */
		private static function can_use_indexer_table() {

			global $wpdb;

			static $can_use_table = null;

			if ( null !== $can_use_table ) {
				return $can_use_table;
			}

			if ( ! filter_var( jet_smart_filters()->settings->get( 'use_indexed_filters' ), FILTER_VALIDATE_BOOLEAN ) ) {
				$can_use_table = false;

				return $can_use_table;
			}

			$table = $wpdb->prefix . 'jet_smart_filters_indexer';
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			$can_use_table = $found && strtolower( $table ) === strtolower( $found );

			return $can_use_table;
		}

		/**
		 * Check if the given object query is covered by the indexer settings.
		 *
		 * @param array  $query Query arguments.
		 * @param string $type  Object type.
		 *
		 * @return bool
		 */
		private static function is_query_indexable( $query = array(), $type = 'post' ) {

			$indexed_post_types = jet_smart_filters()->settings->get( 'avaliable_post_types', array() );

			if ( 'user' === $type || ( ! empty( $query['_query_type'] ) && 'users' === $query['_query_type'] ) ) {
				return ! empty( $indexed_post_types['users'] ) && filter_var( $indexed_post_types['users'], FILTER_VALIDATE_BOOLEAN );
			}

			if ( 'post' !== $type ) {
				return true;
			}

			if ( empty( $query ) || ! is_array( $query ) ) {
				return true;
			}

			if ( ! self::is_post_status_indexable( $query ) ) {
				return false;
			}

			if ( empty( $query['post_type'] ) ) {
				return true;
			}

			$post_types = array_filter( (array) $query['post_type'] );

			if ( empty( $post_types ) || in_array( 'any', $post_types, true ) ) {
				return true;
			}

			foreach ( $post_types as $post_type ) {
				if ( empty( $indexed_post_types[ $post_type ] ) || ! filter_var( $indexed_post_types[ $post_type ], FILTER_VALIDATE_BOOLEAN ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Check if the requested post statuses are stored in the indexer table.
		 *
		 * @param array $query Query arguments.
		 *
		 * @return bool
		 */
		private static function is_post_status_indexable( $query ) {

			if ( empty( $query['post_status'] ) ) {
				return true;
			}

			$post_statuses = array_filter( array_map( 'sanitize_key', (array) $query['post_status'] ) );

			if ( empty( $post_statuses ) ) {
				return true;
			}

			return array( 'publish' ) === array_values( array_unique( $post_statuses ) );
		}

		/**
		 * Prepare comma-separated meta keys for min/max SQL.
		 *
		 * @param string $meta_key Meta key or comma-separated meta keys.
		 *
		 * @return array
		 */
		private static function prepare_meta_keys( $meta_key ) {

			$meta_keys = array_map( 'trim', explode( ',', $meta_key ) );
			$meta_keys = array_filter( $meta_keys, function( $key ) {
				return '' !== $key;
			} );

			return array_values( $meta_keys );
		}

		/**
		 * Strip SQL clauses that are not needed for range min/max subqueries.
		 *
		 * @param string $query_sql SQL query.
		 *
		 * @return string
		 */
		private static function prepare_query_sql( $query_sql ) {

			$query_sql = str_replace( 'SQL_CALC_FOUND_ROWS', '', $query_sql );
			$query_sql = preg_replace( '/LIMIT\s+\d+(\s*,\s*\d+)?/i', '', $query_sql );
			$query_sql = preg_replace( '/ORDER BY[\s\S]+$/i', '', $query_sql );

			return $query_sql;
		}

		/**
		 * Get post statuses available for public dynamic range bounds.
		 *
		 * @return array
		 */
		private static function get_search_post_statuses() {

			$search_in_statuses = apply_filters( 'jet-smart-filters/dynamic-min-max/search-statuses', array( 'publish' ) );
			$search_in_statuses = array_filter( array_map( 'sanitize_key', (array) $search_in_statuses ) );

			if ( empty( $search_in_statuses ) ) {
				$search_in_statuses = array( 'publish' );
			}

			return array_values( $search_in_statuses );
		}

		/**
		 * Append public post statuses to SQL prepare values and return placeholders.
		 *
		 * @param array $prepare_values SQL prepare values.
		 *
		 * @return string
		 */
		private static function append_search_post_statuses( &$prepare_values ) {

			$search_in_statuses = self::get_search_post_statuses();
			$prepare_values     = array_merge( $prepare_values, $search_in_statuses );

			return implode( ', ', array_fill( 0, count( $search_in_statuses ), '%s' ) );
		}

		/**
		 * Restore explicit post status removed during query normalization.
		 *
		 * @param array $query     Normalized query arguments.
		 * @param array $raw_query Raw query arguments.
		 *
		 * @return array
		 */
		private static function restore_normalized_post_status( $query, $raw_query ) {

			if ( ! empty( $raw_query['post_status'] ) && empty( $query['post_status'] ) ) {
				$query['post_status'] = $raw_query['post_status'];
			}

			return $query;
		}

		/**
		 * Get min/max price for WooCommerce products without the indexer table.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		private static function get_woo_prices_range( $args = array() ) {

			global $wpdb;

			$raw_query = ! empty( $args['query'] ) ? $args['query'] : array();
			$query     = self::normalize_indexed_range_query( $raw_query, 'post' );
			$query     = self::restore_normalized_post_status( $query, $raw_query );

			if ( ! empty( $raw_query['post_type'] ) && empty( $query['post_type'] ) ) {
				$query['post_type'] = $raw_query['post_type'];
			}

			if ( empty( $query ) ) {
				return $wpdb->get_row(
					"
					SELECT
						MIN(min_price) AS min,
						MAX(max_price) AS max
					FROM {$wpdb->wc_product_meta_lookup}
					INNER JOIN {$wpdb->postmeta} AS pm
						ON pm.post_id = product_id
						AND pm.meta_key = '_price'
						AND pm.meta_value != ''
					",
					ARRAY_A
				);
			}

			$query_sql = jet_smart_filters()->utils->build_query_sql_from_args( $query, 'post' );

			if ( ! $query_sql ) {
				return false;
			}

			$query_sql = self::prepare_query_sql( $query_sql );

			$sql = "
				SELECT
					MIN(lookup.min_price) AS min,
					MAX(lookup.max_price) AS max
				FROM ( $query_sql ) AS products
				INNER JOIN {$wpdb->wc_product_meta_lookup} lookup
					ON products.ID = lookup.product_id
				INNER JOIN {$wpdb->postmeta} AS pm
					ON pm.post_id = lookup.product_id
					AND pm.meta_key = '_price'
					AND pm.meta_value != ''
			";

			if ( empty( $query['post_status'] ) ) {
				$prepare_values      = array();
				$status_placeholders = self::append_search_post_statuses( $prepare_values );

				$sql .= " INNER JOIN {$wpdb->posts} AS p ON p.ID = lookup.product_id";
				$sql .= " AND p.post_status IN ( $status_placeholders )";

				return $wpdb->get_row( $wpdb->prepare( $sql, $prepare_values ), ARRAY_A );
			}

			return $wpdb->get_row( $sql, ARRAY_A );
		}

		/**
		 * Get min/max for post meta without the indexer table.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		private static function get_post_meta_range( $args = array() ) {

			global $wpdb;

			$meta_keys = self::prepare_meta_keys( $args['meta_key'] );

			if ( empty( $meta_keys ) ) {
				return false;
			}

			$raw_query = ! empty( $args['query'] ) ? $args['query'] : array();
			$query     = self::normalize_indexed_range_query( $raw_query, 'post' );
			$query     = self::restore_normalized_post_status( $query, $raw_query );

			if ( ! empty( $raw_query['post_type'] ) && empty( $query['post_type'] ) ) {
				$query['post_type'] = $raw_query['post_type'];
			}
			$meta_key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

			if ( ! empty( $query ) ) {
				$query_sql = jet_smart_filters()->utils->build_query_sql_from_args( $query, 'post' );

				if ( ! $query_sql ) {
					return false;
				}

				$query_sql = self::prepare_query_sql( $query_sql );

				$sql = "
					SELECT
						MIN(FLOOR(pm.meta_value + 0)) AS min,
						MAX(CEILING(pm.meta_value + 0)) AS max
					FROM {$wpdb->postmeta} AS pm
					INNER JOIN ( $query_sql ) q
						ON q.ID = pm.post_id
					INNER JOIN {$wpdb->posts} AS p
						ON p.ID = pm.post_id
					WHERE pm.meta_key IN ( $meta_key_placeholders )
					AND pm.meta_value != ''
				";

				$prepare_values = $meta_keys;

				if ( empty( $query['post_status'] ) ) {
					$status_placeholders = self::append_search_post_statuses( $prepare_values );

					$sql .= " AND p.post_status IN ( $status_placeholders )";
				}

				return $wpdb->get_row( $wpdb->prepare( $sql, $prepare_values ), ARRAY_A );
			}

			$queried_object = get_queried_object();
			$tax_query      = array();

			if ( ! empty( $queried_object->taxonomy ) && ! empty( $queried_object->term_id ) ) {
				$tax_query[] = array(
					'taxonomy' => $queried_object->taxonomy,
					'terms'    => array( $queried_object->term_id ),
					'field'    => 'term_id',
				);
			}

			$tax_query     = new WP_Tax_Query( $tax_query );
			$tax_query_sql = $tax_query->get_sql( 'pm', 'post_id' );

			$search_in_statuses = self::get_search_post_statuses();
			$status_placeholders = implode( ', ', array_fill( 0, count( $search_in_statuses ), '%s' ) );

			$sql  = "SELECT MIN(FLOOR(pm.meta_value + 0)) AS min, MAX(CEILING(pm.meta_value + 0)) AS max FROM {$wpdb->postmeta} AS pm";
			$sql .= " INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id";
			$sql .= $tax_query_sql['join'];
			$sql .= " WHERE pm.meta_key IN ( $meta_key_placeholders )";
			$sql .= " AND pm.meta_value != ''";
			$sql .= " AND p.post_status IN ( $status_placeholders )";
			$sql .= $tax_query_sql['where'];

			return $wpdb->get_row(
				$wpdb->prepare(
					$sql,
					array_merge( $meta_keys, $search_in_statuses )
				),
				ARRAY_A
			);
		}

		/**
		 * Get min/max for user meta without the indexer table.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		private static function get_user_meta_range( $args = array() ) {

			global $wpdb;

			$meta_keys = self::prepare_meta_keys( $args['meta_key'] );

			if ( empty( $meta_keys ) ) {
				return false;
			}

			$query = ! empty( $args['query'] ) ? $args['query'] : array();
			$query = self::normalize_indexed_range_query( $query, 'user' );
			$meta_key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

			if ( ! empty( $query ) ) {
				$query_sql = jet_smart_filters()->utils->build_query_sql_from_args( $query, 'user' );

				if ( ! $query_sql ) {
					return false;
				}

				$query_sql = self::prepare_query_sql( $query_sql );

				$sql = "
					SELECT
						MIN(FLOOR(um.meta_value + 0)) AS min,
						MAX(CEILING(um.meta_value + 0)) AS max
					FROM {$wpdb->usermeta} AS um
					INNER JOIN ( $query_sql ) q
						ON q.ID = um.user_id
					WHERE um.meta_key IN ( $meta_key_placeholders )
					AND um.meta_value != ''
				";

				return $wpdb->get_row( $wpdb->prepare( $sql, $meta_keys ), ARRAY_A );
			}

			$sql = "
				SELECT
					MIN(FLOOR(um.meta_value + 0)) AS min,
				MAX(CEILING(um.meta_value + 0)) AS max
			FROM {$wpdb->usermeta} AS um
			WHERE um.meta_key IN ( $meta_key_placeholders )
			AND um.meta_value != ''
		";

			return $wpdb->get_row( $wpdb->prepare( $sql, $meta_keys ), ARRAY_A );
		}

		/**
		 * Get min/max for term meta without the indexer table.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		private static function get_term_meta_range( $args = array() ) {

			global $wpdb;

			$meta_keys = self::prepare_meta_keys( $args['meta_key'] );

			if ( empty( $meta_keys ) ) {
				return false;
			}

			$meta_key_placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
			$sql = "
				SELECT
					MIN(FLOOR(tm.meta_value + 0)) AS min,
			MAX(CEILING(tm.meta_value + 0)) AS max
		FROM {$wpdb->termmeta} AS tm
		WHERE tm.meta_key IN ( $meta_key_placeholders )
		AND tm.meta_value != ''
	";

			return $wpdb->get_row( $wpdb->prepare( $sql, $meta_keys ), ARRAY_A );
		}

		/**
		 * Get min/max from source tables without the indexer table.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		private static function get_unindexed_range( $args = array() ) {

			$defaults = array(
				'meta_key' => '',
				'type'     => 'post',
				'query'    => array(),
			);

			$args = wp_parse_args( $args, $defaults );

			if ( empty( $args['meta_key'] ) && ! empty( $args['key'] ) ) {
				$args['meta_key'] = $args['key'];
			}

			if ( empty( $args['meta_key'] ) ) {
				return false;
			}

			switch ( $args['type'] ) {
				case 'user':
					return self::get_user_meta_range( $args );

				case 'term':
					return self::get_term_meta_range( $args );

				case 'post':
				default:
					return self::get_post_meta_range( $args );
			}
		}

		/**
		 * Check if range query result contains usable bounds.
		 *
		 * @param array|null $range Range query result.
		 *
		 * @return bool
		 */
		private static function is_empty_range_result( $range ) {

			return (
				empty( $range )
				|| ! is_array( $range )
				|| ! isset( $range['min'], $range['max'] )
			);
		}

		/**
		 * Get min/max for meta key.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		public static function get_indexed_range( $args = array() ) {

			global $wpdb;

			$table = $wpdb->prefix . 'jet_smart_filters_indexer';

			$defaults = array(
				'meta_key' => '',
				'type'     => 'post',
				'query'    => array(),
			);

			$args = wp_parse_args( $args, $defaults );

			if ( empty( $args['meta_key'] ) && ! empty( $args['key'] ) ) {
				$args['meta_key'] = $args['key'];
			}

			$meta_key = $args['meta_key'];
			$type     = $args['type'];
			$query    = self::normalize_indexed_range_query( $args['query'], $type );

			if ( 'post' === $type ) {
				$query = self::restore_normalized_post_status( $query, $args['query'] );
			}

			if ( ! $meta_key ) {
				return false;
			}

			if ( ! self::can_use_indexed_range( $args['query'], $type ) ) {
				return self::get_unindexed_range( $args );
			}

			// Fast query when no WP_Query args.
			if ( empty( $query ) ) {
				$sql            = "
						SELECT
							MIN(i.item_value_num) AS min,
							MAX(i.item_value_num) AS max
						FROM $table AS i
				";
				$prepare_values = array();

				if ( 'post' === $type ) {
					$status_placeholders = self::append_search_post_statuses( $prepare_values );

					$sql .= " INNER JOIN {$wpdb->posts} AS p ON p.ID = i.item_id";
					$sql .= " AND p.post_status IN ( $status_placeholders )";
				}

				$sql .= "
						WHERE i.item_key = %s
						AND i.type = %s
						AND i.item_value_num IS NOT NULL
				";

				$prepare_values[] = $meta_key;
				$prepare_values[] = $type;

				$range = $wpdb->get_row( $wpdb->prepare( $sql, $prepare_values ), ARRAY_A );

				if ( self::is_empty_range_result( $range ) ) {
					return self::get_unindexed_range( $args );
				}

				return $range;
			}

			// Build a subquery for the current object type from query args.
			$query_sql = jet_smart_filters()->utils->build_query_sql_from_args( $query, $type );

			if ( ! $query_sql ) {
				return false;
			}

			$query_sql = self::prepare_query_sql( $query_sql );

			$sql = "
				SELECT
					MIN(i.item_value_num) AS min,
					MAX(i.item_value_num) AS max
				FROM $table i
				INNER JOIN ( $query_sql ) q
					ON q.ID = i.item_id
			";

			if ( 'post' === $type && empty( $query['post_status'] ) ) {
				$sql .= " INNER JOIN {$wpdb->posts} AS p ON p.ID = i.item_id";
			}

			$sql .= "
				WHERE i.item_key = %s
				AND i.type = %s
				AND i.item_value_num IS NOT NULL
			";

			$prepare_values = array(
				$meta_key,
				$type,
			);

			if ( 'post' === $type && empty( $query['post_status'] ) ) {
				$status_placeholders = self::append_search_post_statuses( $prepare_values );

				$sql .= " AND p.post_status IN ( $status_placeholders )";
			}

			return $wpdb->get_row(
				$wpdb->prepare( $sql, $prepare_values ),
				ARRAY_A
			);
		}

		/**
		 * Get min/max price for WooCommerce products.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		public static function woo_prices( $args = array() ) {

			$query = array();

			if ( ! empty( $args['query'] ) ) {
				$query = $args['query'];
			} elseif ( ! isset( $args['meta_key'] ) && ! isset( $args['type'] ) ) {
				$query = $args;
			}

			if ( empty( $query['post_type'] ) ) {
				$query['post_type'] = 'product';
			}

			if ( empty( $query['post_status'] ) ) {
				$query['post_status'] = 'publish';
			}

			$indexed_range_args = array(
				'meta_key' => '_price',
				'type'     => 'post',
			);

			$indexed_range_args['query'] = $query;

			$indexable_query = ! empty( $query ) ? $query : array(
				'post_type' => 'product',
			);

			if ( ! self::can_use_indexed_range( $indexable_query, 'post' ) ) {
				return self::get_woo_prices_range( $indexed_range_args );
			}

			return self::get_indexed_range( $indexed_range_args );
		}

		/**
		 * Get min/max for post meta.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		public static function meta_values( $args = array() ) {

			return self::get_meta_values( $args, 'post' );
		}

		/**
		 * Get min/max for user meta.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		public static function user_meta_values( $args = array() ) {

			return self::get_meta_values( $args, 'user' );
		}

		/**
		 * Get min/max for term meta.
		 *
		 * @param array $args Query arguments.
		 *
		 * @return array|false|null
		 */
		public static function term_meta_values( $args = array() ) {

			return self::get_meta_values( $args, 'term' );
		}

		/**
		 * Get min/max for meta.
		 *
		 * @param array  $args Query arguments.
		 * @param string $type Object type.
		 *
		 * @return array|false|null
		 */
		private static function get_meta_values( $args = array(), $type = 'post' ) {

			$meta_key = ! empty( $args['meta_key'] ) ? $args['meta_key'] : false;

			if ( ! $meta_key && ! empty( $args['key'] ) ) {
				$meta_key = $args['key'];
			}

			if ( ! $meta_key ) {
				return array();
			}

			$indexed_range_args = array(
				'meta_key' => $meta_key,
				'type'     => $type,
			);

			if ( ! empty( $args['query'] ) ) {
				$indexed_range_args['query'] = $args['query'];
			}

			return self::get_indexed_range( $indexed_range_args );
		}
	}
}
