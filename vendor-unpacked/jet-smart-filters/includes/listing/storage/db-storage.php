<?php
namespace Jet_Smart_Filters\Listing\Storage;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class DB_Storage {

	protected $table_name;
	protected $columns;
	protected $wpdb;

	protected $table_exists = null;
	protected $last_error = null;

	/**
	 * Constructor.
	 *
	 * @param string $table_name The name of the table (without prefix).
	 * @param array $columns An associative array of columns and their SQL definitions.
	 */
	public function __construct( $table_name, $columns ) {

		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'jsf_' . $table_name;
		$this->columns    = array_merge(
			[ 'ID' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' ],
			$columns
		);
	}

	/**
	 * Returns last catched SQL error or null
	 *
	 * @return WP_Error|null
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool True if the table exists, false otherwise.
	 */
	public function table_exists() {

		if ( null!== $this->table_exists ) {
			return $this->table_exists;
		}

		$query = $this->wpdb->prepare(
			"SHOW TABLES LIKE %s",
			$this->table_name
		);

		$this->table_exists = ( $this->wpdb->get_var( $query ) === $this->table_name );

		return $this->table_exists;
	}

	/**
	 * Create the table if it does not exist.
	 *
	 * @return bool True if the table was created, false if it already exists.
	 */
	public function create_table() {

		if ( $this->table_exists() ) {
			return false; // Table already exists
		}

		$charset_collate = $this->wpdb->get_charset_collate();
		$columns_sql     = [];

		foreach ($this->columns as $column => $definition) {
			$columns_sql[] = "`$column` $definition";
		}

		$sql = "CREATE TABLE {$this->table_name} (" . implode(', ', $columns_sql) . ") $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		if ( $this->wpdb->last_error && ! $this->last_error ) {
			$this->last_error = new \WP_Error( 'cant_create_table', $this->wpdb->last_error );
			return false;
		}

		$this->table_exists = true;
		return true;
	}

	/**
	 * Query multiple items from the table based on conditions.
	 *
	 * @param array $args Associative array of conditions (column => value).
	 * @return array Array of results.
	 */
	public function query_items( $args = [] ) {

		$order_by = 'created';
		$order    = 'DESC';
		$per_page = 10;
		$page     = 1;

		$where_clauses = [];
		$values        = [];
		$columns       = array_keys( $this->columns );

		// Search
		if ( ! empty( $args['search'] ) ) {
			$where_clauses[] = "name LIKE %s";
			$values[]        = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
		}
		unset( $args['search'] );

		// Sort
		if ( ! empty( $args['order_by'] ) && in_array( $args['order_by'], $columns, true ) ) {
			$order_by = $args['order_by'];
		}
		unset( $args['order_by'] );

		if ( ! empty( $args['order'] ) && in_array( strtoupper( $args['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			$order = strtoupper( $args['order'] );
		}
		unset( $args['order'] );

		$order_sql = "ORDER BY `$order_by` $order";

		// Pagiantion
		if ( ! empty( $args['per_page'] ) ) {
			$per_page = max( 1, intval( $args['per_page'] ) );
		}
		unset( $args['per_page'] );

		if ( ! empty( $args['page'] ) ) {
			$page = max( 1, intval( $args['page'] ) );
		}
		unset( $args['page'] );

		$limit_sql = 'LIMIT ' . $per_page . ' OFFSET ' . ( $page - 1 ) * $per_page;

		foreach ($args as $key => $value) {
			if ( ! in_array( $key, $columns, true ) ) {
				continue;
			}

			$where_clauses[] = "`$key` = %s";
			$values[]        = $value;
		}

		$where_sql   = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

		$query       = "SELECT ID, name, created FROM {$this->table_name} $where_sql $order_sql $limit_sql";
		$query_count = "SELECT COUNT(*) FROM {$this->table_name} $where_sql";

		$prepared_query       = $values ? $this->wpdb->prepare( $query, $values ) : $query;
		$prepared_query_count = $values ? $this->wpdb->prepare( $query_count, $values ) : $query_count;

		$items = array_map(
			[ $this, 'filter_item' ],
			$this->wpdb->get_results( $prepared_query, ARRAY_A )
		);
		$count = (int) $this->wpdb->get_var( $prepared_query_count );

		return [
			'items' => $items,
			'count' => $count
		];
	}

	/**
	 * Query multiple items from the table based on conditions.
	 *
	 * @param array $args Associative array of conditions (column => value).
	 * @param Boolean $as_pairs If true, return a key-value pair (default), and if false , return an array with value and label..
	 * @return array Array of results.
	 */
	public function query_items_list( $args = [], $as_pairs = true ) {

		$order_by = 'name';
		$order    = 'ASC';

		$values    = array();
		$where_sql = '';
		$order_sql = "ORDER BY $order_by $order";

		if ( ! empty( $args['search'] ) ) {
			$search = preg_replace( '/^\*+|\*+$/', '', $args['search'] );
			$where_sql = 'WHERE name LIKE %s ';
			$values[]  = '%' . $this->wpdb->esc_like( $search ) . '%';
		}

		$query = "SELECT ID, name FROM {$this->table_name} $where_sql $order_sql";
		$items = $this->wpdb->get_results( $values ? $this->wpdb->prepare( $query, $values ) : $query );

		$result = [];

		if ( empty( $items ) ) {
			return $result;
		}

		foreach ( $items as $row ) {
			if ( $as_pairs ) {
				$result[$row->ID] = $row->name;
			} else {
				$result[] = array(
					'value' => $row->ID,
					'label' => $row->name
				);
			}
		}

		return $result;
	}

	/**
	 * Query a single item from the table by its ID.
	 *
	 * @param int $id The ID of the item.
	 * @return array|null The result row as an associative array, or null if not found.
	 */
	public function get_item( $id ) {

		$query  = "SELECT * FROM {$this->table_name} WHERE ID = %d";
		$result = $this->wpdb->get_row( $this->wpdb->prepare( $query, $id ), ARRAY_A );

		return $result
			? $this->filter_item( $result )
			: [];
	}

	/**
	 * Insert a new item into the table.
	 *
	 * @param array $data Associative array of column-value pairs.
	 * @return int The ID of the inserted item, or 0 on failure.
	 */
	public function insert_item($data) {

		if ( !is_array( $data ) ) {

			if ( ! $this->last_error ) {
				$this->last_error = new \WP_Error( 'incorrect_item_data', 'Incorrect item data' );
			}

			return 0;
		}

		$result = $this->wpdb->insert($this->table_name, $this->serialize_data( $data ) );

		if ( false === $result ) {
			if ( $this->wpdb->last_error && ! $this->last_error ) {
				$this->last_error = new \WP_Error( 'cant_insert_item', $this->wpdb->last_error );
			}
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an item in the table by its ID.
	 *
	 * @param int   $id   The ID of the item to update.
	 * @param array $data Associative array of column-value pairs.
	 * @return int The ID of the updated item, or 0 on failure.
	 */
	public function update_item( $id, $data ) {

		if ( ! is_array( $data ) ) {
			if ( ! $this->last_error ) {
				$this->last_error = new \WP_Error( 'incorrect_item_data', 'Incorrect item data' );
			}
			return 0;
		}

		$result = $this->wpdb->update(
			$this->table_name,
			$this->serialize_data( $data ),
			[ 'ID' => $id ]
		);

		if ( $this->wpdb->last_error && ! $this->last_error ) {
			$this->last_error = new \WP_Error( 'cant_update_item', $this->wpdb->last_error );
		}

		return ( false !== $result ) ? (int) $id : 0;
	}

	/**
	 * Remove element from table.
	 *
	 * @param int $id Item ID.
	 * @param array $args Associative array of conditions.
	 * @return int||array The ID of the removed item, or 0 on failure. || Array of results if $args setted
	 */
	public function remove_item( $id, $args = [] ) {

		$deleted = $this->wpdb->delete(
			$this->table_name,
			[ 'ID' => $id ],
			[ '%d' ]
		);

		if ( $deleted && ! empty( $args ) ) {
			$items_data = $this->query_items( $args );

			return [
				'items'        => $items_data['items'],
				'count'        => $items_data['count']
			];
		}

		if ( $this->wpdb->last_error && ! $this->last_error ) {
			$this->last_error = new \WP_Error( 'cant_remove_item', $this->wpdb->last_error );
		}

		return ( false !== $result ) ? (int) $id : 0;
	}

	/**
	 * Filter single item before return it.
	 *
	 * @param  array $item Item to filter.
	 * @return array
	 */
	public function filter_item( $item = [] ) {

		foreach ( $item as $key => $value ) {
			$item[ $key ] = maybe_unserialize( $value );
		}

		return $item;
	}

	/**
	 * Serialize given data.
	 *
	 * @return string
	 */
	public function serialize_data( $data = [] ) {

		foreach ($data as $key => $value) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$data[ $key ] = maybe_serialize( $value );
			}
		}

		return $data;
	}
}
