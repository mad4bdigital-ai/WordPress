<?php
/**
 * JetEngine range filter compatibility.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Jet_Smart_Filters_Compatibility_JE_Range class
 */
class Jet_Smart_Filters_Compatibility_JE_Range {

	/**
	 * Constructor for the class
	 */
	public function __construct() {

		add_filter( 'jet-smart-filters/range-filter/source-data', array( $this, 'get_custom_storage_range_data' ), 10, 4 );
	}

	/**
	 * Get query-aware range data for JetEngine Custom Meta Storage.
	 *
	 * @param mixed    $data       Prepared range data.
	 * @param callable $source_cb  Source callback.
	 * @param string   $query_var  Range meta key.
	 * @param array    $query_args Current query args.
	 *
	 * @return array|false|null
	 */
	public function get_custom_storage_range_data( $data, $source_cb, $query_var, $query_args = array() ) {

		if ( false !== $data ) {
			return $data;
		}

		if ( ! is_array( $query_args ) || ! is_array( $source_cb ) || empty( $source_cb[0] ) ) {
			return false;
		}

		if ( empty( $source_cb[1] ) || 'apply_min_max_callback' !== $source_cb[1] ) {
			return false;
		}

		$handler = $source_cb[0];

		if ( ! is_object( $handler ) || empty( $handler->db ) || empty( $handler->fields ) || ! is_array( $handler->fields ) ) {
			return false;
		}

		if ( ! method_exists( $handler->db, 'table' ) || ! in_array( $query_var, $handler->fields, true ) ) {
			return false;
		}

		$table = $handler->db->table();
		$field = esc_sql( $query_var );

		if ( empty( $query_args ) ) {
			return $this->get_range_data_from_table( $table, $field );
		}

		$query_args  = $this->prepare_query_args( $query_args, $handler );
		$object_type = ! empty( $handler->object_type ) ? $handler->object_type : 'post';
		$query_sql   = jet_smart_filters()->utils->build_query_sql_from_args( $query_args, $object_type );

		if ( ! $query_sql ) {
			return false;
		}

		$query_sql = $this->prepare_query_sql( $query_sql );

		$sql = sprintf(
			'SELECT min( FLOOR( storage_table.`%1$s` ) ) as min, ' .
			'max( CEILING( storage_table.`%1$s` ) ) as max ' .
			'FROM %2$s AS storage_table ' .
			'INNER JOIN ( %3$s ) AS jsf_query ON jsf_query.ID = storage_table.object_ID ' .
			'WHERE storage_table.`%1$s` IS NOT NULL ' .
			'AND TRIM( storage_table.`%1$s` ) != ""',
			$field,
			$table,
			$query_sql
		);

		global $wpdb;

		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Get range data directly from the custom storage table.
	 *
	 * @param string $table Custom storage table.
	 * @param string $field Custom storage field.
	 *
	 * @return array|null
	 */
	private function get_range_data_from_table( $table, $field ) {

		global $wpdb;

		$sql = sprintf(
			'SELECT min( FLOOR( storage_table.`%1$s` ) ) as min, ' .
			'max( CEILING( storage_table.`%1$s` ) ) as max ' .
			'FROM %2$s AS storage_table ' .
			'WHERE storage_table.`%1$s` IS NOT NULL ' .
			'AND TRIM( storage_table.`%1$s` ) != ""',
			$field,
			$table
		);

		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Prepare query args for JetEngine Custom Meta Storage query handlers.
	 *
	 * @param array  $query_args Current query args.
	 * @param object $handler    Custom storage query handler.
	 *
	 * @return array
	 */
	private function prepare_query_args( $query_args, $handler ) {

		$object_type = ! empty( $handler->object_type ) ? $handler->object_type : 'post';

		if ( 'post' === $object_type && empty( $query_args['post_type'] ) && ! empty( $handler->object_slug ) ) {
			$query_args['post_type'] = $handler->object_slug;
		}

		return $query_args;
	}

	/**
	 * Strip SQL clauses that are not needed for range min/max subqueries.
	 *
	 * @param string $query_sql SQL query.
	 *
	 * @return string
	 */
	private function prepare_query_sql( $query_sql ) {

		$query_sql = str_replace( 'SQL_CALC_FOUND_ROWS', '', $query_sql );
		$query_sql = preg_replace( '/LIMIT\s+\d+(\s*,\s*\d+)?/i', '', $query_sql );
		$query_sql = preg_replace( '/ORDER BY[\s\S]+$/i', '', $query_sql );

		return $query_sql;
	}
}
