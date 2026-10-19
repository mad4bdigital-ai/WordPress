<?php
namespace Jet_Engine\Relations\Storage;

/**
 * Database manager class
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Base DB class
 */
class DB extends \Jet_Engine_Base_DB {

	public static $prefix = 'jet_rel_';
	public $relations_cache = [];
	public $table_keys = false;

	/**
	 * Normalize fetched DB value without blanket unserialization.
	 *
	 * @param  mixed  $value Column value.
	 * @param  string $key   Column name.
	 * @return mixed
	 */
	protected function normalize_column_value( $value, $key = '' ) {

		if ( is_string( $value ) ) {
			return wp_unslash( $value );
		}

		return $value;
	}

	/**
	 * Normalize fetched DB row.
	 *
	 * @param  array|object $item Raw DB row.
	 * @return array|object
	 */
	protected function normalize_item( $item ) {

		if ( is_array( $item ) ) {
			foreach ( $item as $key => $value ) {
				$item[ $key ] = $this->normalize_column_value( $value, $key );
			}
		} elseif ( is_object( $item ) ) {
			foreach ( get_object_vars( $item ) as $key => $value ) {
				$item->$key = $this->normalize_column_value( $value, $key );
			}
		}

		return $item;
	}

	/**
	 * Insert booking
	 *
	 * @param  array  $booking [description]
	 * @return [type]          [description]
	 */
	public function insert( $data = array() ) {

		if ( ! empty( $this->defaults ) ) {
			foreach ( $this->defaults as $default_key => $default_value ) {
				if ( ! isset( $data[ $default_key ] ) ) {
					$data[ $default_key ] = $default_value;
				}
			}
		}

		$time = current_time( 'mysql' );

		if ( empty( $data['created'] ) ) {
			$data['created'] = $time;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value        = maybe_serialize( $value );
				$data[ $key ] = $value;
			}
		}

		$inserted = self::wpdb()->insert( $this->table(), $data );

		if ( $inserted ) {
			return self::wpdb()->insert_id;
		} else {
			return false;
		}
	}

	/**
	 * Update appointment info
	 *
	 * @param  array  $new_data [description]
	 * @param  array  $where    [description]
	 * @return [type]           [description]
	 */
	public function update( $new_data = array(), $where = array() ) {

		if ( ! empty( $this->defaults ) ) {
			foreach ( $this->defaults as $default_key => $default_value ) {
				if ( ! isset( $data[ $default_key ] ) ) {
					$data[ $default_key ] = $default_value;
				}
			}
		}

		foreach ( $new_data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value            = maybe_serialize( $value );
				$new_data[ $key ] = $value;
			}
		}

		$result = self::wpdb()->update( $this->table(), $new_data, $where );

		/**
		 * https://github.com/Crocoblock/suggestions/issues/7774
		 */
		$this->reset_found_items_cache();

		return $result;
	}

	/**
	 * Set table keys to create indexes with the table creation
	 *
	 * @param boolean $keys [description]
	 */
	public function set_table_keys( $keys = false ) {
		$this->table_keys = $keys;
	}

	/**
	 * Returns table columns schema
	 *
	 * @return [type] [description]
	 */
	public function get_table_schema() {

		$charset_collate = $this->wpdb()->get_charset_collate();
		$table           = $this->table();
		$default_columns = array(
			'_ID'     => 'bigint(20) NOT NULL AUTO_INCREMENT',
			'created' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
		);

		$additional_columns = $this->schema;
		$columns_schema     = '';

		foreach ( $default_columns as $column => $desc ) {
			$columns_schema .= $column . ' ' . $desc . ',';
		}

		if ( is_array( $additional_columns ) && ! empty( $additional_columns ) ) {
			foreach ( $additional_columns as $column => $definition ) {

				if ( ! $definition ) {
					$definition = 'text';
				}

				$columns_schema .= $column . ' ' . $definition . ',';

			}
		}

		$keys = $this->table_keys;

		return "CREATE TABLE $table (
			$columns_schema
			PRIMARY KEY ( _ID ),
			$keys
		) $charset_collate;";

	}

	/**
	 * Return the list of column names allowed in SQL search clauses.
	 *
	 * @return array
	 */
	public function get_searchable_fields() {
		return array_unique( array_merge( array_keys( $this->schema ), array( '_ID', 'created' ) ) );
	}

	/**
	 * Validate and normalize search arguments before they are used in a SQL query.
	 *
	 * @param  mixed $search Raw search argument.
	 * @return array|false
	 */
	public function sanitize_search_args( $search = false ) {

		if ( ! is_array( $search ) ) {
			$search = array(
				'keyword' => $search,
			);
		}

		if ( ! isset( $search['keyword'] ) || ! is_scalar( $search['keyword'] ) ) {
			return false;
		}

		$keyword = (string) $search['keyword'];
		$fields  = ! empty( $search['fields'] ) ? $search['fields'] : array_keys( $this->schema );

		if ( is_string( $fields ) ) {
			$fields = explode( ',', str_replace( ' ', '', $fields ) );
		}

		if ( ! is_array( $fields ) ) {
			return false;
		}

		$allowed_fields = array_flip( $this->get_searchable_fields() );
		$fields         = array_filter( $fields, function( $field ) use ( $allowed_fields ) {

			if ( ! is_scalar( $field ) ) {
				return false;
			}

			$field = $this->sanitize_sql_field( $field );

			return $field && isset( $allowed_fields[ $field ] );
		} );

		$fields = array_values( array_unique( array_map( function( $field ) {
			return $this->sanitize_sql_field( $field );
		}, $fields ) ) );

		if ( empty( $fields ) ) {
			return false;
		}

		return array(
			'keyword' => $keyword,
			'fields'  => $fields,
		);
	}

	/**
	 * Get unique string cache key for given relations request
	 *
	 * @param  array   $args   [description]
	 * @param  integer $limit  [description]
	 * @param  integer $offset [description]
	 * @param  array   $order  [description]
	 * @param  string  $rel    [description]
	 * @return [type]          [description]
	 */
	public function get_cache_key( $args = array(), $limit = 0, $offset = 0, $order = array(), $rel = 'AND' ) {
		return apply_filters(
			'jet-engine/relations/db/cache-key',
			md5( json_encode( $args ) . json_encode( $order ) . $limit . $offset . $rel ),
			$args = array(), $limit = 0, $offset = 0, $order = array(), $rel = 'AND'
		);
	}

	/**
	 * Reset relations object cache
	 * @return [type] [description]
	 */
	public function reset_cache() {
		$this->relations_cache = [];
	}

	/**
	 * Query data from db table
	 *
	 * @return [type] [description]
	 */
	public function query( $args = array(), $limit = 0, $offset = 0, $order = array(), $filter = false, $rel = 'AND' ) {

		$cache_key = $this->get_cache_key( $args, $limit, $offset, $order, $rel );
		$raw       = isset( $this->relations_cache[ $cache_key ] ) ? $this->relations_cache[ $cache_key ] : false;

		if ( false === $raw ) {

			$table = $this->table();

			$query = "SELECT * FROM $table";

			if ( ! $rel ) {
				$rel = 'AND';
			}

			$search = ! empty( $args['_search'] ) ? $this->sanitize_search_args( $args['_search'] ) : false;

			if ( $search ) {
				unset( $args['_search'] );
			}

			$where  = $this->add_where_args( $args, $rel );
			$query .= $where;

			if ( $search ) {

				$search_str = array();
				$keyword    = $search['keyword'];
				$fields     = ! empty( $search['fields'] ) ? $search['fields'] : false;

				if ( ! $fields ) {
					$fields = array_keys( $this->schema );
				}

				if ( $fields ) {
					foreach ( $fields as $field ) {
						$field = $this->quote_sql_field( $field );

						$search_str[] = self::wpdb()->prepare(
							"$field LIKE %s",
							'%' . self::wpdb()->esc_like( $keyword ) . '%'
						);
					}

					$search_str = implode( ' OR ', $search_str );
				}

				if ( ! empty( $search_str ) ) {

					if ( $where ) {
						$query .= ' ' . $rel;
					} else {
						$query .= ' WHERE';
					}

					$query .= ' (' . $search_str . ')';

				}
			}

			if ( empty( $order ) ) {
				$order = array( array(
					'orderby' => '_ID',
					'order'   => 'desc',
				) );
			}

			$query .= $this->add_order_args( $order );

			if ( intval( $limit ) > 0 ) {
				$limit  = absint( $limit );
				$offset = absint( $offset );
				$query .= " LIMIT $offset, $limit";
			}

			$raw = self::wpdb()->get_results( $query, $this->get_format_flag() );
			$this->relations_cache[ $cache_key ] = $raw;

		}

		if ( $filter && is_callable( $filter ) ) {
			return array_map( $filter, $raw );
		} else {
			return array_map( array( $this, 'normalize_item' ), $raw );
		}

	}

}
