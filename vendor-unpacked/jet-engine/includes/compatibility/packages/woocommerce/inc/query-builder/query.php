<?php

namespace Jet_Engine\Compatibility\Packages\Jet_Engine_Woo_Package\Query_Builder;

class WC_Product_Query extends \Jet_Engine\Query_Builder\Queries\Base_Query {

	use \Jet_Engine\Query_Builder\Queries\Traits\Meta_Query_Trait;
	use \Jet_Engine\Query_Builder\Queries\Traits\Tax_Query_Trait;

	public $current_wc_query = null;
	public $current_wp_query = null;

	/**
	 * Returns queries items
	 *
	 * @return array|object
	 */
	public function _get_items() {

		$query      = $this->get_current_wc_query();
		$query_vars = $query->get_query_vars();

		$result = [];

		if ( ! $query ) {
			return $result;
		}

		if ( $query_vars['paginate'] ) {
			$result = $query->get_products()->products;
		} else {
			$result = $query->get_products();
		}

		return $result;

	}

	/**
	 * Returns current query arguments
	 *
	 * @return array
	 */
	public function get_query_args() {

		if ( null === $this->final_query ) {
			$this->setup_query();
		}

		$args = $this->final_query;

		if ( empty( $args['type'] ) ) {
			$args['type'] = array_merge( array_keys( wc_get_product_types() ) );
		}

		if ( empty( $args['limit'] ) ) {
			$args['limit'] = $this->get_default_wc_product_per_page();;
		}

		if ( ! empty( $args['average_rating'] ) ) {
			$args['average_rating'] = number_format( $args['average_rating'], 2 );
		}

		if ( ! empty( $args['specific_query'] ) ) {
			$row = $args['specific_query'];

			foreach ( $row as $row_query ) {
				$args[ $row_query['feature'] ] = $row_query['status'];
			}
		} elseif ( isset( $args['specific_query'] ) ) {
			unset( $args['specific_query'] );
		}

		if ( ! empty( $args['date_query'] ) ) {
			foreach ( $args['date_query'] as $row_query ) {
				$date_query_arg = $this->prepare_product_date_query_arg( $row_query );

				if ( empty( $date_query_arg ) ) {
					continue;
				}

				$args[ $date_query_arg['column'] ] = $date_query_arg['value'];
			}

			unset( $args['date_query'] );
		} elseif ( isset( $args['date_query'] ) ) {
			unset( $args['date_query'] );
		}

		if ( isset( $args['date_query_relation'] ) ) {
			unset( $args['date_query_relation'] );
		}

		if ( ! empty( $args['tax_query'] ) ) {
			$raw               = $args['tax_query'];
			$args['tax_query'] = [];

			if ( isset( $args['tax_query_relation'] ) ) {
				unset( $args['tax_query_relation'] );
			}
			// Uncomment when WooCommerce will handle `tax_query` relation.
			/*if ( ! empty( $args['tax_query_relation'] ) ) {
				$args['tax_query']['relation'] = $args['tax_query_relation'];
			}*/

			foreach ( $raw as $query_row ) {
				// 'exclude_children' => true  is replaced to 'include_children' => false
				// 'exclude_children' => false is replaced to 'include_children' => true
				if ( isset( $query_row['exclude_children'] ) ) {
					$query_row['include_children'] = ! $query_row['exclude_children'];
					unset( $query_row['exclude_children'] );
				}

				if ( empty( $query_row['operator'] ) || in_array( $query_row['operator'], [ 'IN', 'NOT IN' ] ) ) {
					if ( ! empty( $query_row['terms'] ) && ! is_array( $query_row['terms'] ) ) {
						$query_row['terms'] = $this->explode_string( $query_row['terms'] );
					}
				}

				if ( empty( $query_row['terms'] ) ) {
					continue;
				}

				$args['tax_query'][] = $query_row;
			}
		}

		if ( ! empty( $args['search_query'] ) && get_search_query() ) {
			$args['s'] = get_search_query();
		}

		return apply_filters( 'jet-engine/query-builder/wc-product-query/args', $args, $this );
	}

	/**
	 * Prepare product date query argument.
	 *
	 * @param array $query_row Date query row.
	 *
	 * @return array|false
	 */
	protected function prepare_product_date_query_arg( $query_row = array() ) {

		if ( ! is_array( $query_row ) ) {
			return false;
		}

		$column = ! empty( $query_row['column'] ) ? $query_row['column'] : '';

		$columns = apply_filters(
			'jet-engine/query-builder/wc-product-query/date-query-columns',
			$this->get_product_date_query_columns(),
			$this,
			$query_row
		);

		if ( ! in_array( $column, (array) $columns, true ) ) {
			return false;
		}

		$compare = ! empty( $query_row['compare'] ) ? strtoupper( trim( $query_row['compare'] ) ) : '=';

		if ( ! in_array( $compare, $this->get_product_date_query_operators(), true ) ) {
			$compare = '=';
		}

		if ( 'BETWEEN' === $compare ) {
			$value = $this->prepare_product_date_query_range_value( $query_row, $compare );
		} else {
			$value = $this->prepare_product_date_query_parts_value( $query_row, $compare );

			if ( false === $value ) {
				$value = $this->prepare_product_date_query_range_value( $query_row, $compare );
			}
		}

		if ( false === $value ) {
			return false;
		}

		return array(
			'column' => $column,
			'value'  => $value,
		);
	}

	/**
	 * Returns allowed WC product date query columns.
	 *
	 * @return string[]
	 */
	protected function get_product_date_query_columns() {
		return array(
			'date_created',
			'date_modified',
			'date_on_sale_from',
			'date_on_sale_to',
		);
	}

	/**
	 * Returns allowed WC product date query operators.
	 *
	 * @return string[]
	 */
	protected function get_product_date_query_operators() {
		return array(
			'=',
			'>',
			'>=',
			'<',
			'<=',
			'BETWEEN',
		);
	}

	/**
	 * Prepare product date query value from year/month/day fields.
	 *
	 * @param array  $query_row Date query row.
	 * @param string $compare   Compare operator.
	 *
	 * @return string|false
	 */
	protected function prepare_product_date_query_parts_value( $query_row = array(), $compare = '=' ) {

		$year  = $this->get_product_date_query_part( $query_row, 'year' );
		$month = $this->get_product_date_query_part( $query_row, 'month' );
		$day   = $this->get_product_date_query_part( $query_row, 'day' );

		if ( null === $year && null === $month && null === $day ) {
			return false;
		}

		if ( null === $year || ( null !== $day && null === $month ) ) {
			return false;
		}

		if ( $year < 1 || $year > 9999 || ( null !== $month && ( $month < 1 || $month > 12 ) ) ) {
			return false;
		}

		if ( null !== $day && ( $day < 1 || $day > 31 ) ) {
			return false;
		}

		if ( null === $month ) {
			return $this->format_product_date_query_interval_value(
				sprintf( '%04d-01-01', $year ),
				sprintf( '%04d-12-31', $year ),
				$compare
			);
		}

		if ( null === $day ) {
			$month_date = \DateTime::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-01', $year, $month ) );

			if ( false === $month_date ) {
				return false;
			}

			$last_day = (int) $month_date->format( 't' );

			return $this->format_product_date_query_interval_value(
				sprintf( '%04d-%02d-01', $year, $month ),
				sprintf( '%04d-%02d-%02d', $year, $month, $last_day ),
				$compare
			);
		}

		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}

		$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

		if ( '=' === $compare ) {
			return $date;
		}

		return $compare . $date;
	}

	/**
	 * Prepare product date query value from after/before fields.
	 *
	 * @param array  $query_row Date query row.
	 * @param string $compare   Compare operator.
	 *
	 * @return string|false
	 */
	protected function prepare_product_date_query_range_value( $query_row = array(), $compare = 'BETWEEN' ) {

		$after  = $this->get_product_date_query_string( $query_row, 'after' );
		$before = $this->get_product_date_query_string( $query_row, 'before' );

		if ( false !== $after && false !== $before ) {
			return $after . '...' . $before;
		}

		if ( false !== $after ) {
			$operator = in_array( $compare, array( '>', '>=' ), true ) ? $compare : '>';

			return $operator . $after;
		}

		if ( false !== $before ) {
			$operator = in_array( $compare, array( '<', '<=' ), true ) ? $compare : '<';

			return $operator . $before;
		}

		return false;
	}

	/**
	 * Format date interval according to WC product query date syntax.
	 *
	 * @param string $start   Interval start date.
	 * @param string $end     Interval end date.
	 * @param string $compare Compare operator.
	 *
	 * @return string
	 */
	protected function format_product_date_query_interval_value( $start = '', $end = '', $compare = '=' ) {

		switch ( $compare ) {
			case '>':
				return '>' . $end;

			case '>=':
				return '>=' . $start;

			case '<':
				return '<' . $start;

			case '<=':
				return '<=' . $end;
		}

		return $start . '...' . $end;
	}

	/**
	 * Returns product date query integer part.
	 *
	 * @param array  $query_row Date query row.
	 * @param string $part      Part key.
	 *
	 * @return int|null
	 */
	protected function get_product_date_query_part( $query_row = array(), $part = '' ) {

		if ( \Jet_Engine_Tools::is_empty( $query_row, $part ) || ! is_numeric( $query_row[ $part ] ) ) {
			return null;
		}

		return (int) $query_row[ $part ];
	}

	/**
	 * Returns product date query string.
	 *
	 * @param array  $query_row Date query row.
	 * @param string $key       Date key.
	 *
	 * @return string|false
	 */
	protected function get_product_date_query_string( $query_row = array(), $key = '' ) {

		if ( \Jet_Engine_Tools::is_empty( $query_row, $key ) || ! is_scalar( $query_row[ $key ] ) ) {
			return false;
		}

		$value = trim( (string) $query_row[ $key ] );

		return '' === $value ? false : $value;
	}

	/**
	 * Returns `WC_Product_Query`
	 *
	 * @since 3.0.8 Added search query handling. Hook `jet-engine/query-builder/wc-product-query/args` for further query
	 *        arguments transformation.
	 *
	 * @return \WC_Product_Query|null
	 */
	public function get_current_wc_query() {

		if ( null !== $this->current_wc_query ) {
			return $this->current_wc_query;
		}

		$this->current_wc_query = new \WC_Product_Query( $this->get_query_args() );

		return $this->current_wc_query;
	}

	/**
	 * Returns WP Query object for current query
	 *
	 * @return \WP_Query
	 */
	public function get_current_wp_query() {

		if ( null !== $this->current_wp_query ) {
			return $this->current_wp_query;
		}

		$wp_query_args = \WC_Data_Store::load( 'product' )->get_wp_query_args( $this->get_query_args() );

		$this->current_wp_query = new \WP_Query( $wp_query_args );

		return $this->current_wp_query;
	}

	/**
	 * Returns total found items count
	 *
	 * @return mixed
	 */
	public function get_items_total_count() {

		$cached     = $this->get_cached_data( 'count' );
		$query      = $this->get_current_wc_query();
		$query_vars = $query->get_query_vars();

		if ( false !== $cached || ! $query ) {
			return $cached;
		}

		if ( $query_vars['paginate'] ) {
			$result = $query->get_products()->total;
		} else {
			$result = count( $query->get_products() );
		}

		$this->update_query_cache( $result, 'count' );

		return $result;

	}

	/**
	 * Return current listing grid page
	 *
	 * @return false|float|int
	 */
	public function get_current_items_page() {

		$query      = $this->get_current_wc_query();
		$query_vars = $query->get_query_vars();
		$page       = ! empty( $this->final_query['paged'] ) ? $this->final_query['paged'] : false;

		if ( ! $page && ! empty( $this->final_query['page'] ) ) {
			$page = $this->final_query['page'];
		}

		if ( ! $page && ! empty( $query_vars['paged'] ) ) {
			$page = $query_vars['paged'];
		}

		if ( ! $page ) {
			$page = 1;
		}

		return $page;

	}

	/**
	 * Returns count of the items visible per single listing grid loop/page
	 *
	 * @return int
	 */
	public function get_items_per_page() {

		$this->setup_query();

		$query      = $this->get_current_wc_query();
		$query_vars = $query->get_query_vars();

		if ( ! empty( $query_vars['limit'] ) ) {
			$limit = $query_vars['limit'];
		} else {
			$limit = $this->get_default_wc_product_per_page();
		}

		return $limit;

	}

	/**
	 * Returns queried items count per page
	 *
	 * @return mixed
	 */
	public function get_items_page_count() {

		$result   = $this->get_items_total_count();
		$per_page = $this->get_items_per_page();

		if ( $per_page < $result ) {
			$page  = $this->get_current_items_page();
			$pages = $this->get_items_pages_count();

			if ( $page < $pages ) {
				$result = $per_page;
			} elseif ( $page == $pages ) {
				$offset = ! empty( $this->final_query['offset'] ) ? absint( $this->final_query['offset'] ) : 0;

				if ( $result % $per_page > 0 ) {
					$result = ( $result % $per_page ) - $offset;
				} else {
					$result = $per_page - $offset;
				}
			}
		}

		return $result;

	}

	/**
	 * Returns queried items pages count
	 *
	 * @return false|float|int
	 */
	public function get_items_pages_count() {

		$per_page = $this->get_items_per_page();
		$total    = $this->get_items_total_count();

		if ( ! $per_page || ! $total ) {
			return 1;
		} else {
			return ceil( $total / $per_page );
		}

	}

	/**
	 * Set filtered prop in specific for current query type way
	 *
	 * @param string $prop
	 * @param null   $value
	 */
	public function set_filtered_prop( $prop = '', $value = null ) {
		switch ( $prop ) {
			case '_page':
				$this->final_query['page'] = $value;
				break;

			case '_items_per_page':
				$this->final_query['limit'] = $value;
				break;

			case 'post__in':

				if ( ! empty( $this->final_query['include'] ) ) {
					$this->final_query['include'] = array_intersect( $this->final_query['include'], $value );

					if ( empty( $this->final_query['include'] ) ) {
						$this->final_query['include'] = array( PHP_INT_MAX );
					}

				} else {
					$this->final_query['include'] = $value;
				}

				break;

			case 'post__not_in':

				if ( ! empty( $this->final_query['exclude'] ) ) {
					$this->final_query['exclude'] = array_merge( $this->final_query['exclude'], $value );
				} else {
					$this->final_query['exclude'] = $value;
				}

				break;

			case 'orderby':
			case 'order':
			case 'meta_key':
				$this->set_filtered_order( $prop, $value );
				break;

			case 'meta_query':
				$this->replace_meta_query_row( $value );
				break;

			case 'tax_query':
				$this->replace_tax_query_row( $value );
				break;

			default:
				$this->final_query[ $prop ] = $value;
				break;
		}
	}

	/**
	 * Set filtering order for current query type way
	 *
	 * @param $key
	 * @param $value
	 */
	public function set_filtered_order( $key, $value ) {

		if ( empty( $this->final_query['orderby'] ) ) {
			$this->final_query['orderby'] = 'ID';
		}

		$this->final_query[ $key ] = $value;

	}

	/**
	 * Returns default WC_Query product per page.
	 *
	 * @return float|int
	 */
	public function get_default_wc_product_per_page() {
		return wc_get_default_products_per_row() * wc_get_default_product_rows_per_page();
	}

	/**
	 * Array of arguments where string should be exploded into array
	 *
	 * @return string[]
	 */
	public function get_args_to_explode() {
		return [
			'include',
			'exclude',
			'parent_exclude',
			'shipping_class',
		];
	}

	/**
	 * Reset Query.
	 *
	 * Reset WC Product Query in the loop.
	 *
	 * @since  3.0.6
	 * @access public
	 *
	 * @return void
	 */
	public function reset_query() {
		$this->current_wc_query = null;
		$this->current_wp_query = null;
	}

}
