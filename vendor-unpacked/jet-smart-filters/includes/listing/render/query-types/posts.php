<?php
namespace Jet_Smart_Filters\Listing\Render\Query_Types;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Posts query type class
 */
class Posts extends Base {

	protected $current_query = null;

	/**
	 * Prepare query args before they reach WP_Query.
	 *
	 * @param array $query_args
	 *
	 * @return array
	 */
	protected function prepare_query_args( $query_args = [] ) {

		$args = parent::prepare_query_args( $query_args );
		$args['type'] = self::get_type();

		if ( ! empty( $args['post_authors'] ) ) {
			$args['author__in'] = $this->normalize_option_values( $args['post_authors'] );
		}

		if ( isset( $args['posts_per_page'] ) ) {
			$args['posts_per_page'] = intval( $args['posts_per_page'] );
		}

		if ( isset( $args['offset'] ) ) {
			$args['offset'] = intval( $args['offset'] );
		}

		if ( ! empty( $args['post__in'] ) ) {
			$args['post__in'] = $this->normalize_option_values( $args['post__in'] );
		}

		if ( ! empty( $args['post__not_in'] ) ) {
			$args['post__not_in'] = $this->normalize_option_values( $args['post__not_in'] );
		}

		if ( isset( $args['ignore_sticky_posts'] ) ) {
			$args['ignore_sticky_posts'] = filter_var( $args['ignore_sticky_posts'], FILTER_VALIDATE_BOOLEAN );
		}

		if ( ! empty( $args['sort'] ) ) {
			if ( ! empty( $args['sort']['orderby'] ) ) {
				$args['orderby'] = sanitize_text_field( $args['sort']['orderby'] );
			}

			if ( ! empty( $args['sort']['order'] ) ) {
				$args['order'] = strtoupper( sanitize_text_field( $args['sort']['order'] ) );
			}
		}

		return $args;
	}

	/**
	 * Query type
	 *
	 * @return string
	 */
	public static function get_type() {
		return 'posts';
	}

	/**
	 * Return query args normalized right before consumption.
	 * This runs AFTER any later add_query_args() merges.
	 */
	public function get_query_args() {

		// Start from Base args, then normalize
		$args = parent::get_query_args();

		if ( ! empty( $args['post_types'] ) ) {
			$args['post_type'] = $args['post_types'];
			unset( $args['post_types'] );
		}

		// Normalize tax_query if presented in args
		if ( ! empty( $args['taxonomies'] ) ) {

			$taxonomies = $this->normalize_clause_groups( $args['taxonomies'] );
			unset( $args['taxonomies'] );
			$tax_query = [];
			foreach ( $taxonomies as $and_group ) {

				$group_count = 0;
				$inner_query = [];

				foreach ( $and_group as $single_tax ) {

					$taxonomy = ! empty( $single_tax['taxonomy'] ) ? $single_tax['taxonomy'] : '';
					$terms    = ! empty( $single_tax['terms'] ) ? $single_tax['terms'] : '';
					$terms    = $this->explode_string( $terms );
					$operator = ! empty( $single_tax['operator'] ) ? $single_tax['operator'] : 'IN';
					$field    = ! empty( $single_tax['term_field'] ) ? $single_tax['term_field'] : 'term_id';
					$include_children = true;

					if ( ! $taxonomy || ! $terms ) {
						continue;
					}

					if ( 'term_id' === $field ) {
						$terms = array_map( 'intval', $terms );
					} else {
						$terms = array_map( 'sanitize_text_field', $terms );
					}

					if ( count( $terms ) === 1 ) {
						$terms = $terms[0];
					}

					if ( isset( $single_tax['child_terms'] ) ) {
						$include_children = filter_var( $single_tax['child_terms'], FILTER_VALIDATE_BOOLEAN );
					}

					$group_count++;

					$inner_query[] = [
						'taxonomy'         => $taxonomy,
						'terms'            => $terms,
						'field'            => $field,
						'operator'         => $operator,
						'include_children' => $include_children,
					];
				}

				if ( ! $group_count ) {
					continue;
				}

				if ( $group_count > 1 ) {
					$tax_query[] = array_merge(
						[ 'relation' => 'AND' ],
						$inner_query
					);
				} else {
					$tax_query[] = $inner_query[0];
				}
			}

			if ( $tax_query ) {
				if ( count( $tax_query ) > 1 ) {
					$tax_query = array_merge(
						[ 'relation' => 'OR' ],
						$tax_query
					);
				} elseif (
					count( $tax_query ) === 1
					&& ! empty( $tax_query[0]['relation'] )
				) {
					$tax_query = $tax_query[0];
				}
			}

			if ( empty( $args['tax_query'] ) ) {
				$args['tax_query'] = $tax_query;
			} else {
				$args['tax_query'] = $this->merge_tax_query( $args['tax_query'], $tax_query );
			}

		}

		// Normalize meta_query if presented in args
		if ( ! empty( $args['custom_fields'] ) ) {
			$custom_fields = $this->normalize_clause_groups( $args['custom_fields'] );
			unset( $args['custom_fields'] );

			$meta_query = [];
			foreach ( $custom_fields as $and_group ) {

				$group_count = 0;
				$inner_query = [];

				foreach ( $and_group as $single_field ) {

					$key      = ! empty( $single_field['key'] ) ? $single_field['key'] : '';
					$value    = isset( $single_field['value'] ) ? $single_field['value'] : '';
					$compare  = ! empty( $single_field['compare'] ) ? $single_field['compare'] : '=';
					$type     = ! empty( $single_field['type'] ) ? $single_field['type'] : 'CHAR';

					if ( ! $key ) {
						continue;
					}

					$group_count++;

					$inner_query[] = [
						'key'     => sanitize_text_field( $key ),
						'value'   => is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value ),
						'compare' => sanitize_text_field( $compare ),
						'type'    => sanitize_text_field( $type ),
					];
				}

				if ( ! $group_count ) {
					continue;
				}

				if ( $group_count > 1 ) {
					$meta_query[] = array_merge(
						[ 'relation' => 'AND' ],
						$inner_query
					);
				} else {
					$meta_query[] = $inner_query[0];
				}
			}

			if ( $meta_query ) {
				if ( count( $meta_query ) > 1 ) {
					$meta_query = array_merge(
						[ 'relation' => 'OR' ],
						$meta_query
					);
				} elseif (
					count( $meta_query ) === 1
					&& ! empty( $meta_query[0]['relation'] )
				) {
					$meta_query = $meta_query[0];
				}

				if ( empty( $args['meta_query'] ) ) {
					$args['meta_query'] = $meta_query;
				} else {
					$args['meta_query'] = $this->merge_meta_query( $args['meta_query'], $meta_query );
				}
			}
		}

		return $this->normalize_pagination_args( is_array( $args ) ? $args : [] );
	}

	/**
	 * Merge two tax_query arrays with proper relation handling.
	 *
	 * @todo Make more specific merge
	 *
	 * @param array $initial_query
	 * @param array $new_query
	 *
	 * @return array
	 */
	public function merge_tax_query( $initial_query, $new_query ) {

		if ( ! is_array( $initial_query ) || ! is_array( $new_query ) ) {
			return $initial_query;
		}

		// If new query has no relation - just merge
		if ( ! isset( $new_query['relation'] ) ) {
			return array_merge( $initial_query, $new_query );
		} else {
			return [
				'relation' => 'AND',
				$initial_query,
				$new_query,
			];
		}
	}

	/**
	 * Merge two meta_query arrays with proper relation handling.
	 *
	 * @todo Make more specific merge
	 *
	 * @param array $initial_query
	 * @param array $new_query
	 *
	 * @return array
	 */
	public function merge_meta_query( $initial_query, $new_query ) {

		if ( ! is_array( $initial_query ) || ! is_array( $new_query ) ) {
			return $initial_query;
		}

		// If new query has no relation - just merge
		if ( ! isset( $new_query['relation'] ) ) {
			return array_merge( $initial_query, $new_query );
		} else {
			return [
				'relation' => 'AND',
				$initial_query,
				$new_query,
			];
		}
	}

	/**
	 * Get current query
	 *
	 * @return \WP_Query|null
	 */
	protected function get_current_query() {

		if ( ! $this->current_query ) {
			$this->current_query = new \WP_Query( $this->get_query_args() );
		}

		return $this->current_query;
	}

	/**
	 * Get item ID from post object.
	 *
	 * @param object $item
	 * @return int|null
	 */
	public function get_item_id( $item ) {

		if ( ! is_object( $item ) || ! isset( $item->ID ) ) {
			return null;
		}

		return $item->ID;
	}

	/**
	 * Get query stats: found posts, max pages, current page
	 *
	 * @return array
	 */
	public function get_stats() {

		$query = $this->get_current_query();

		if ( $query && $query instanceof \WP_Query ) {
			return [
				'found_posts'   => $query->found_posts,
				'max_num_pages' => $query->max_num_pages,
				'page'          => max( 1, $query->get( 'paged' ) ?: 1 ),
			];
		}

		return [
			'found_posts'   => 0,
			'max_num_pages' => 0,
			'page'          => 1,
		];
	}

	/**
	 * Add a new query arguments to the already existing ones.
	 *
	 * @param array $query_args
	 * @return void
	 */
	public function add_query_args( $query_args ) {

		$this->query_args = jet_smart_filters()->utils->merge_query_args(
			$this->query_args,
			$query_args
		);

		// Invalidate cached WP_Query (it depends on args)
		$this->current_query = null;
	}

	/**
	 * Query-type specific items getter
	 *
	 * @return array
	 */
	protected function _get_items() {

		$current_query = $this->get_current_query();

		if ( ! $current_query || ! $current_query->have_posts() ) {
			return [];
		} else {
			return $current_query->posts;
		}
	}

	/**
	 * Normalize pagination-related args.
	 */
	protected function normalize_pagination_args( $args ) {
		// If 'paged' is not set or less than 2, return args as is
		if ( empty( $args['paged'] ) || $args['paged'] < 2 ) {
			return $args;
		}

		$paged          = max( 1, absint( $args['paged'] ) );
		$posts_per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 0;
		$initial_offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		// If no posts_per_page, can't calculate pagination properly
		if ( $posts_per_page <= 0 ) {
			return $args;
		}

		// Calculate new offset:
		$args['offset'] = $initial_offset + ( ( $paged - 1 ) * $posts_per_page );

		return $args;
	}

	/**
	 * Normalize query clause groups to a nested OR/AND structure.
	 *
	 * Legacy saved listings may still contain a flat list of clauses.
	 *
	 * @param mixed $groups
	 *
	 * @return array
	 */
	protected function normalize_clause_groups( $groups ) {

		if ( ! is_array( $groups ) || empty( $groups ) ) {
			return [];
		}

		$first_group = reset( $groups );

		if ( is_array( $first_group ) && $this->is_assoc_array( $first_group ) ) {
			return [ array_values( $groups ) ];
		}

		if ( is_array( $first_group ) ) {
			return array_values( array_filter( array_map( function( $group ) {
				return is_array( $group ) ? array_values( $group ) : [];
			}, $groups ) ) );
		}

		return [];
	}

	/**
	 * Check whether array has associative keys.
	 *
	 * @param array $array
	 *
	 * @return bool
	 */
	protected function is_assoc_array( $array ) {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	/**
	 * Normalize builder option arrays into integer IDs.
	 *
	 * @param array $values
	 *
	 * @return array
	 */
	protected function normalize_option_values( $values = [] ) {

		if ( ! is_array( $values ) ) {
			return [];
		}

		return array_values( array_filter( array_map( function( $value ) {
			if ( is_array( $value ) && isset( $value['value'] ) ) {
				return intval( $value['value'] );
			}

			if ( is_object( $value ) && isset( $value->value ) ) {
				return intval( $value->value );
			}

			return intval( $value );
		}, $values ) ) );
	}
}
