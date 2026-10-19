<?php
/**
 * Woocommerce compatibility class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Smart_Filters_Compatibility_Woocommerce class
 */
class Jet_Smart_Filters_Compatibility_WC {
	/**
	 * Constructor for the class
	 */
	function __construct() {

		add_action( 'jet-smart-filters/referrer/request', array( $this, 'setup_wc_product' ) );
		add_action( 'woocommerce_updated_product_price', array( $this, 'reindex_updated_variable_product_price' ) );
		add_filter( 'jet-engine/listing/grid/posts-query-args', array( $this, 'wc_modify_sort_query_args' ), 20 );
		add_filter( 'the_title', array( $this, 'maybe_apply_frontend_title_format' ), 9, 2 );
		add_filter( 'jet-smart-filters/data/terms-objects', array( $this, 'exclude_empty_out_of_stock_terms' ), 10, 4 );

		if ( jet_smart_filters()->settings->wc_hide_out_of_stock_variations ) {
			add_filter( 'jet-smart-filters/query/final-query', array( $this, 'hide_out_of_stock_variations_modify_query' ) );
			add_filter( 'jet-smart-filters/filters/indexed-data', array( $this, 'hide_out_of_stock_variations_indexed' ), 10, 2 );
		}
	}

	public function reindex_updated_variable_product_price( $product_id ) {

		if (
			! jet_smart_filters()->indexer->is_indexer_enabled
			|| ! filter_var( jet_smart_filters()->settings->get( 'use_auto_indexing' ), FILTER_VALIDATE_BOOLEAN )
			|| ! in_array( 'product', jet_smart_filters()->indexer->indexed_post_types, true )
		) {
			return;
		}

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return;
		}

		jet_smart_filters()->indexer->remove_single_data( $product_id, 'post' );
		jet_smart_filters()->indexer->add_single_data( $product_id, 'post' );
	}

	public function maybe_apply_frontend_title_format( $title, $post_id ) {

		if ( ! wp_doing_ajax() || ! jet_smart_filters()->query->is_ajax_filter() ) {
			return $title;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'product' !== $post->post_type ) {
			return $title;
		}

		if ( ! empty( $post->post_password ) ) {
			return sprintf( apply_filters( 'protected_title_format', __( 'Protected: %s' ), $post ), $title );
		}

		if ( 'private' === $post->post_status ) {
			return sprintf( apply_filters( 'private_title_format', __( 'Private: %s' ), $post ), $title );
		}

		return $title;
	}

	public function setup_wc_product() {

		global $wp;

		if ( ! function_exists( 'wc_setup_product_data' ) ) {
			return;
		}

		if ( empty( $wp->query_vars['post_type'] ) || 'product' !== $wp->query_vars['post_type'] ) {
			return;
		}

		if ( empty( $wp->query_vars['product'] ) ) {
			return;
		}

		$posts = get_posts( [
			'post_type' => 'product',
			'name' => $wp->query_vars['product'],
			'posts_per_page' => 1
		] );

		if ( empty( $posts ) ) {
			return;
		}

		global $post;
		$post = $posts[0];

		wc_setup_product_data( $post );
	}

	public function wc_modify_sort_query_args( $args ) {

		if ( ! isset( $args['jet_smart_filters'] ) || ! jet_smart_filters()->query->get_query_args() ) {
			return $args;
		}

		if ( isset( $args['wc_query'] ) ) {
			if ( isset( $args['orderby'] ) && isset( $args['order'] ) ) {
				$ordering_args = WC()->query->get_catalog_ordering_args( $args['orderby'], $args['order'] );

				// Prevent rewrite the order only to DESC if the orderby is relevance.
				if ( 'relevance' === $args['orderby'] && ! empty( $args['order'] ) ) {
					$ordering_args['order'] = $args['order'];
				}
			} else {
				$ordering_args = WC()->query->get_catalog_ordering_args();
			}

			$args['orderby'] = $ordering_args['orderby'];
			$args['order']   = $ordering_args['order'];

			if ( $ordering_args['meta_key'] ) {
				$args['meta_key'] = $ordering_args['meta_key'];
			}
		}

		return $args;
	}

	/**
	 * Exclude terms that WooCommerce marks as empty after catalog visibility rules.
	 */
	public function exclude_empty_out_of_stock_terms( $terms, $tax, $child_of_current, $args = array() ) {

		if ( ! $this->should_exclude_empty_out_of_stock_terms( $terms, $tax, $args ) ) {
			return $terms;
		}

		return $this->exclude_terms_without_catalog_count( $terms );
	}

	/**
	 * Check whether WooCommerce catalog counts should be used to hide empty terms.
	 */
	public function should_exclude_empty_out_of_stock_terms( $terms, $tax, $args ) {

		if (
			empty( $terms )
			|| empty( $args['hide_empty'] )
			|| 'product_visibility' === $tax
			|| 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' )
			|| ! taxonomy_exists( $tax )
		) {
			return false;
		}

		$taxonomy = get_taxonomy( $tax );

		if ( empty( $taxonomy->object_type ) || ! in_array( 'product', $taxonomy->object_type, true ) ) {
			return false;
		}

		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->count ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove terms with zero catalog count from the terms list.
	 */
	public function exclude_terms_without_catalog_count( $terms ) {

		foreach ( $terms as $key => $term ) {
			if ( isset( $term->count ) && 1 > absint( $term->count ) ) {
				unset( $terms[ $key ] );
			}
		}

		return array_values( $terms );
	}

	public function hide_out_of_stock_variations_modify_query( $query ) {

		if ( ! isset( $query['tax_query'] ) ) {
			return $query;
		}

		$attribute_terms = $this->get_product_attribute_terms_from_tax_query( $query['tax_query'] );

		if ( empty( $attribute_terms ) ) {
			return $query;
		}

		if ( count( $attribute_terms ) > 1 ) {
			$post_in = $this->get_in_stock_product_ids_by_variation_attribute_combination( $attribute_terms );
		} else {
			$post_in = $this->get_in_stock_product_ids_by_attribute_terms( reset( $attribute_terms ) );
		}

		if ( ! empty( $post_in ) && ! empty( $query['post__in'] ) ) {
			$query['post__in'] = array_values( array_intersect( wp_parse_id_list( $query['post__in'] ), $post_in ) );
		} elseif ( ! empty( $post_in ) ) {
			$query['post__in'] = $post_in;
		}

		if ( empty( $query['post__in'] ) ) {
			$query['post__in'] = array( 0 );
		}

		return $query;
	}

	public function get_product_attribute_terms_from_tax_query( $tax_query ) {

		$attribute_terms = array();

		foreach ( $tax_query as $item ) {

			if ( ! is_array( $item ) || empty( $item['taxonomy'] ) || ! taxonomy_is_product_attribute( $item['taxonomy'] ) ) {
				continue;
			}

			if ( ! empty( $item['operator'] ) && in_array( strtoupper( $item['operator'] ), array( 'NOT IN', 'NOT EXISTS' ), true ) ) {
				continue;
			}

			$taxonomy = $item['taxonomy'];
			$terms    = ! empty( $item['terms'] ) ? (array) $item['terms'] : array();
			$field    = ! empty( $item['field'] ) ? $item['field'] : 'term_id';

			if ( empty( $terms ) ) {
				continue;
			}

			if ( 'slug' === $field ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'slug'       => array_map( 'sanitize_title', $terms ),
						'fields'     => 'ids',
						'hide_empty' => false,
					)
				);
			} else {
				$terms = wp_parse_id_list( $terms );
			}

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			if ( ! isset( $attribute_terms[ $taxonomy ] ) ) {
				$attribute_terms[ $taxonomy ] = array();
			}

			$attribute_terms[ $taxonomy ] = array_unique( array_merge( $attribute_terms[ $taxonomy ], wp_parse_id_list( $terms ) ) );
		}

		return array_filter( $attribute_terms );
	}

	public function get_in_stock_product_ids_by_attribute_terms( $terms ) {

		$terms = wp_parse_id_list( $terms );

		if ( empty( $terms ) ) {
			return array();
		}

		global $wpdb;

		$terms_placeholders = implode( ',', array_fill( 0, count( $terms ), '%d' ) );

		return wp_parse_id_list(
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT product_or_parent_id
					FROM {$wpdb->prefix}wc_product_attributes_lookup
					WHERE term_id IN ( $terms_placeholders )
					AND in_stock = %d",
					array_merge( $terms, array( 1 ) )
				)
			)
		);
	}

	public function get_in_stock_product_ids_by_variation_attribute_combination( $attribute_terms ) {

		global $wpdb;

		$conditions               = $this->get_product_attribute_lookup_conditions( $attribute_terms );
		$non_variation_conditions = $this->get_product_attribute_lookup_conditions( $attribute_terms, 'non_variation' );

		if ( empty( $conditions ) || empty( $non_variation_conditions ) ) {
			return array();
		}

		$taxonomies_count = count( $conditions );

		return wp_parse_id_list(
			$wpdb->get_col(
				$wpdb->prepare(
					"
					SELECT product_or_parent_id
					FROM (
						/* Parent-level attribute rows are projected onto each in-stock variation to support mixed parent/variation filters. */
						SELECT product_or_parent_id, product_id, COUNT( DISTINCT taxonomy ) AS matched_taxonomies
						FROM (
							SELECT product_or_parent_id, product_id, taxonomy
							FROM {$wpdb->prefix}wc_product_attributes_lookup
							WHERE is_variation_attribute = 1
								AND in_stock = %d
								AND ( " . implode( ' OR ', $conditions ) . " )
							UNION ALL
							SELECT non_variation.product_or_parent_id,
								COALESCE( variations.product_id, non_variation.product_id ) AS product_id,
								non_variation.taxonomy
							FROM {$wpdb->prefix}wc_product_attributes_lookup AS non_variation
							LEFT JOIN (
								SELECT product_or_parent_id, product_id
								FROM {$wpdb->prefix}wc_product_attributes_lookup
								WHERE is_variation_attribute = 1
									AND in_stock = %d
								GROUP BY product_or_parent_id, product_id
							) AS variations
								ON variations.product_or_parent_id = non_variation.product_or_parent_id
							WHERE non_variation.is_variation_attribute = 0
								AND non_variation.in_stock = %d
								AND ( " . implode( ' OR ', $non_variation_conditions ) . " )
						) AS matched_rows
						GROUP BY product_or_parent_id, product_id
						HAVING matched_taxonomies = %d
					) AS matched_variations
					GROUP BY product_or_parent_id
					",
					1,
					1,
					1,
					$taxonomies_count
				)
			)
		);
	}

	public function get_product_attribute_lookup_conditions( $attribute_terms, $table_alias = '' ) {

		global $wpdb;

		$conditions = array();
		$prefix     = $table_alias ? $table_alias . '.' : '';

		foreach ( $attribute_terms as $taxonomy => $term_ids ) {
			$term_ids = wp_parse_id_list( $term_ids );

			if ( empty( $term_ids ) ) {
				continue;
			}

			// Match both the original taxonomy and the value WooCommerce may store
			// in the attributes lookup table for non-ASCII taxonomy names.
			$lookup_taxonomies       = $this->get_product_attribute_lookup_taxonomies( $taxonomy );
			$taxonomy_placeholders   = implode( ',', array_fill( 0, count( $lookup_taxonomies ), '%s' ) );
			$term_placeholders       = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$conditions[]            = $wpdb->prepare(
				"( {$prefix}taxonomy IN ( $taxonomy_placeholders ) AND {$prefix}term_id IN ( $term_placeholders ) )",
				array_merge( $lookup_taxonomies, $term_ids )
			);
		}

		return $conditions;
	}

	/**
	 * Get taxonomy names that can identify a product attribute in WooCommerce lookup data.
	 *
	 * WooCommerce keeps the public taxonomy name in WordPress APIs, but its
	 * product attributes lookup table can contain a URL-encoded and truncated
	 * taxonomy value for non-ASCII attribute slugs.
	 */
	public function get_product_attribute_lookup_taxonomies( $taxonomy ) {

		$lookup_taxonomies = array( $taxonomy );

		// WooCommerce lookup regeneration URL-encodes taxonomy names and the
		// lookup table stores them in a varchar(32) column.
		$encoded_taxonomy  = substr( strtolower( rawurlencode( $taxonomy ) ), 0, 32 );

		if ( $encoded_taxonomy && $encoded_taxonomy !== $taxonomy ) {
			$lookup_taxonomies[] = $encoded_taxonomy;
		}

		return $lookup_taxonomies;
	}

	public function hide_out_of_stock_variations_indexed( $indexedData, $props ) {

		if ( empty( $indexedData['tax_query'] ) || ! is_array( $indexedData['tax_query'] ) ) {
			return $indexedData;
		}

		$variations_indexing_data = array();

		foreach ( $indexedData['tax_query'] as $key => $value ) {
			if ( taxonomy_is_product_attribute( $key ) ) {
				$variations_indexing_data[$key] = array_keys( $value );
			}
		}

		if ( empty( $variations_indexing_data ) ) {
			return $indexedData;
		}

		global $wpdb;

		$queried_ids = ! empty( $props['queried_ids'] ) ? wp_parse_id_list( $props['queried_ids'] ) : array();

		if ( empty( $queried_ids ) ) {
			return $indexedData;
		}

		$conditions          = $this->get_product_attribute_lookup_conditions( $variations_indexing_data );
		$lookup_taxonomy_map = array();

		foreach ( $variations_indexing_data as $taxonomy => $term_ids ) {
			$term_ids = wp_parse_id_list( $term_ids );

			if ( empty( $term_ids ) ) {
				continue;
			}

			// Query lookup data by both possible taxonomy values, then map the
			// matched lookup value back to the original JSF indexed data key.
			$lookup_taxonomies = $this->get_product_attribute_lookup_taxonomies( $taxonomy );

			foreach ( $lookup_taxonomies as $lookup_taxonomy ) {
				$lookup_taxonomy_map[ $lookup_taxonomy ] = $taxonomy;
			}
		}

		if ( empty( $conditions ) ) {
			return $indexedData;
		}

		$query_attribute_terms = ! empty( $props['query_args']['tax_query'] )
			? $this->get_product_attribute_terms_from_tax_query( $props['query_args']['tax_query'] )
			: array();

		if ( ! empty( $query_attribute_terms ) ) {
			$result = $this->get_indexed_variation_attribute_counts( $variations_indexing_data, $query_attribute_terms, $queried_ids );
		} else {
			$queried_ids_placeholders = implode( ',', array_fill( 0, count( $queried_ids ), '%d' ) );
			$sql = $wpdb->prepare(
				"
				SELECT taxonomy, term_id, COUNT(*) AS count
					FROM (
						SELECT taxonomy, term_id
							FROM {$wpdb->prefix}wc_product_attributes_lookup
								WHERE in_stock != 0
									AND ( " . implode( ' OR ', $conditions ) . " )
									AND product_or_parent_id IN ( $queried_ids_placeholders )
								GROUP BY
									taxonomy,
									product_or_parent_id,
									term_id
					) AS subquery
						GROUP BY
							taxonomy,
							term_id",
				$queried_ids
			);
			$result = $wpdb->get_results( $sql, ARRAY_A );
		}

		// reset previous indexer values
		foreach ( $variations_indexing_data as $taxonomy => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				$indexedData['tax_query'][$taxonomy][$term_id] = 0;
			}
		}

		// set new indexer values
		foreach ( $result as $row ) {
			// SQL returns the taxonomy value stored in WooCommerce lookup table,
			// but JSF indexed data is keyed by the original taxonomy name.
			$taxonomy = isset( $lookup_taxonomy_map[ $row['taxonomy'] ] ) ? $lookup_taxonomy_map[ $row['taxonomy'] ] : $row['taxonomy'];

			if ( isset( $indexedData['tax_query'][$taxonomy][$row['term_id']] ) ) {
				$indexedData['tax_query'][$taxonomy][$row['term_id']] = $row['count'];
			}
		}

		return $indexedData;
	}

	/**
	 * Count indexed WooCommerce attribute terms against the currently selected attribute context.
	 *
	 * Product attribute counters must use the same variation-aware matching as filtered results:
	 * a candidate term is counted only when it belongs to an in-stock variation that also matches
	 * the already selected attributes. Parent/simple attribute rows are projected onto in-stock
	 * variations so mixed parent/variation attribute filters keep working.
	 */
	public function get_indexed_variation_attribute_counts( $indexing_data, $query_attribute_terms, $queried_ids ) {

		global $wpdb;

		$result = array();
		$available_rows_sql = "
			SELECT product_or_parent_id, product_id, taxonomy, term_id
			FROM {$wpdb->prefix}wc_product_attributes_lookup
			WHERE is_variation_attribute = 1
				AND in_stock = 1
			UNION ALL
			SELECT non_variation.product_or_parent_id,
				COALESCE( variations.product_id, non_variation.product_id ) AS product_id,
				non_variation.taxonomy,
				non_variation.term_id
			FROM {$wpdb->prefix}wc_product_attributes_lookup AS non_variation
			LEFT JOIN (
				SELECT product_or_parent_id, product_id
				FROM {$wpdb->prefix}wc_product_attributes_lookup
				WHERE is_variation_attribute = 1
					AND in_stock = 1
				GROUP BY product_or_parent_id, product_id
			) AS variations
				ON variations.product_or_parent_id = non_variation.product_or_parent_id
			WHERE non_variation.is_variation_attribute = 0
				AND non_variation.in_stock = 1
		";
		$queried_ids = wp_parse_id_list( $queried_ids );

		if ( empty( $queried_ids ) ) {
			return $result;
		}

		$queried_ids_placeholders = implode( ',', array_fill( 0, count( $queried_ids ), '%d' ) );

		foreach ( $indexing_data as $taxonomy => $term_ids ) {
			$term_ids = wp_parse_id_list( $term_ids );

			if ( empty( $term_ids ) ) {
				continue;
			}

			$lookup_taxonomies     = $this->get_product_attribute_lookup_taxonomies( $taxonomy );
			$taxonomy_placeholders = implode( ',', array_fill( 0, count( $lookup_taxonomies ), '%s' ) );
			$term_placeholders     = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$candidate_where       = $wpdb->prepare(
				"candidate.taxonomy IN ( $taxonomy_placeholders ) AND candidate.term_id IN ( $term_placeholders )",
				array_merge( $lookup_taxonomies, $term_ids )
			);
			$context_terms     = $query_attribute_terms;

			unset( $context_terms[ $taxonomy ] );

			if ( empty( $context_terms ) ) {
				$sql = $wpdb->prepare(
					"
					SELECT candidate.taxonomy, candidate.term_id, COUNT( DISTINCT candidate.product_or_parent_id ) AS count
					FROM ( $available_rows_sql ) AS candidate
					WHERE candidate.product_or_parent_id IN ( $queried_ids_placeholders )
						AND $candidate_where
					GROUP BY candidate.taxonomy, candidate.term_id
					",
					$queried_ids
				);
			} else {
				$context_conditions = $this->get_product_attribute_lookup_conditions( $context_terms, 'context_rows' );

				if ( empty( $context_conditions ) ) {
					continue;
				}

				$context_taxonomies_count = count( $context_conditions );
				$sql                      = $wpdb->prepare(
					"
					SELECT candidate.taxonomy, candidate.term_id, COUNT( DISTINCT candidate.product_or_parent_id ) AS count
					FROM ( $available_rows_sql ) AS candidate
					INNER JOIN (
						SELECT context_rows.product_or_parent_id, context_rows.product_id, COUNT( DISTINCT context_rows.taxonomy ) AS matched_taxonomies
						FROM ( $available_rows_sql ) AS context_rows
						WHERE " . implode( ' OR ', $context_conditions ) . "
						GROUP BY context_rows.product_or_parent_id, context_rows.product_id
						HAVING matched_taxonomies = %d
					) AS context
						ON context.product_or_parent_id = candidate.product_or_parent_id
						AND context.product_id = candidate.product_id
					WHERE candidate.product_or_parent_id IN ( $queried_ids_placeholders )
						AND $candidate_where
					GROUP BY candidate.taxonomy, candidate.term_id
					",
					array_merge( array( $context_taxonomies_count ), $queried_ids )
				);
			}

			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( ! empty( $rows ) ) {
				$result = array_merge( $result, $rows );
			}
		}

		return $result;
	}
}
