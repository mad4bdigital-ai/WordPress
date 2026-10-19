<?php
namespace Jet_Engine\Modules\Custom_Content_Types;

/**
 * Database manager class for Custom Content Types.
 *
 * Handles all low-level CRUD operations, schema management, and query building
 * for a single CCT database table. Each CCT instance gets its own DB object
 * bound to the corresponding table and field schema.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * CCT-specific database layer that extends the base JetEngine DB class.
 *
 * Provides insert, update, query, schema generation, column management,
 * data migration, and search sanitization for a single Custom Content Type table.
 */
class DB extends \Jet_Engine_Base_DB {

	public $defaults = array(
		'cct_status' => 'publish',
	);

	public static $prefix = 'jet_cct_';

	public $query_object = null;

	/**
	 * Stores map removed fields to new on DB schema update to try keep the data
	 * @var array
	 */
	public $adjust_fields_map = array();

	public $last_query = '';

	/**
	 * Map of field names that are expected to store serialized arrays.
	 *
	 * @var array
	 */
	protected $serialized_fields = array();

	/**
	 * Initialise the DB instance for a specific CCT table.
	 *
	 * Stores the table name and applies the `jet-engine/custom-content-types/table-schema`
	 * filter to the field schema so that third-party code can extend it.
	 * When the `jet_cct_install_tables` GET parameter is present the table will
	 * be (re-)created on the `init` hook, which is useful for manual re-installs.
	 *
	 * @param string|null $table  Table name without the wpdb prefix (the static
	 *                            $prefix constant is applied separately).
	 * @param array       $schema Associative array of column name => SQL type
	 *                            that describes the CCT-specific fields.
	 */
	public function __construct( $table = null, $schema = array() ) {

		$this->table  = $table;
		$this->schema = apply_filters( 'jet-engine/custom-content-types/table-schema', $schema, $this );

		if ( ! empty( $_GET['jet_cct_install_tables'] ) ) {
			add_action( 'init', array( $this, 'install_table' ) );
		}
	}

	/**
	 * Attach a query object to this DB instance.
	 *
	 * Allows the higher-level query layer (e.g. a CCT_Query instance) to be
	 * referenced from within the DB class, so that filters and hooks fired
	 * during SQL execution can access query-level context.
	 *
	 * @param object $query_object The query object to associate with this instance.
	 */
	public function set_query_object( $query_object ) {
		$this->query_object = $query_object;
	}

	/**
	 * Store field definitions used to safely decode item values on reads.
	 *
	 * @param array $fields Raw CCT field definitions.
	 * @return void
	 */
	public function set_field_definitions( $fields = array() ) {
		$this->serialized_fields = array();

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || empty( $field['type'] ) ) {
				continue;
			}

			if ( $this->is_serialized_field_type( $field ) ) {
				$this->serialized_fields[ $field['name'] ] = true;
			}
		}

		$this->serialized_fields = apply_filters(
			'jet-engine/custom-content-types/db/serialized-fields',
			$this->serialized_fields,
			$fields,
			$this
		);
	}

	/**
	 * Check whether the field stores serialized array values.
	 *
	 * @param array $field CCT field config.
	 * @return bool
	 */
	protected function is_serialized_field_type( $field = array() ) {
		switch ( $field['type'] ) {
			case 'checkbox':
			case 'gallery':
			case 'repeater':
				return true;

			case 'media':
				return ! empty( $field['value_format'] ) && 'id' !== $field['value_format'];

			case 'posts':
			case 'select':
				return ! empty( $field['is_multiple'] ) && filter_var( $field['is_multiple'], FILTER_VALIDATE_BOOLEAN );
		}

		return false;
	}

	/**
	 * Decode serialized array values only for known array-backed fields.
	 *
	 * @param string $key   Column name.
	 * @param mixed  $value Raw DB value.
	 * @return mixed
	 */
	protected function maybe_unserialize_item_value( $key, $value ) {
		if ( empty( $this->serialized_fields[ $key ] ) || ! $this->is_serialized_array( $value ) ) {
			return $value;
		}

		$unserialized = jet_engine_safe_unserialize( $value, 'cct_item_field' );

		return is_array( $unserialized ) ? $unserialized : $value;
	}

	/**
	 * Fast check for serialized array payloads.
	 *
	 * @param mixed $value Raw DB value.
	 * @return bool
	 */
	protected function is_serialized_array( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( $value );

		return 0 === strpos( $value, 'a:' ) && is_serialized( $value );
	}

	/**
	 * Insert a new CCT item into the database.
	 *
	 * Merges default field values (e.g. `cct_status => publish`) into the
	 * supplied data array if those keys are not already set. Timestamps
	 * `cct_created` and `cct_modified` are populated automatically with the
	 * current MySQL time when absent. Array field values are serialized before
	 * storage.
	 *
	 * @param array $data Associative array of column => value pairs to insert.
	 * @return int|false  The auto-increment ID of the newly inserted row,
	 *                    or false on failure.
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

		if ( empty( $data['cct_created'] ) ) {
			$data['cct_created'] = $time;
		}

		if ( empty( $data['cct_modified'] ) ) {
			$item['cct_modified'] = $time;
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
	 * Update an existing CCT item in the database.
	 *
	 * Array field values are serialized before being passed to `wpdb::update`.
	 * After a successful update the found-items transient cache is invalidated
	 * so that subsequent queries reflect the change.
	 *
	 * @param array $new_data Associative array of column => value pairs to write.
	 * @param array $where    Associative array of column => value pairs that
	 *                        identify the row(s) to update (passed directly to
	 *                        `wpdb::update`).
	 * @return void
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

		if ( empty( $data['cct_modified'] ) ) {
			$item['cct_modified'] = current_time( 'mysql' );
		}

		self::wpdb()->update( $this->table(), $new_data, $where );

		/**
		 * https://github.com/Crocoblock/suggestions/issues/7774
		 */
		$this->reset_found_items_cache();
	}

	/**
	 * Build the CREATE TABLE SQL statement for this CCT table.
	 *
	 * Always includes the built-in `_ID` (primary key) and `cct_status` columns.
	 * Additional columns are appended from `$this->schema`, skipping any that
	 * would duplicate a built-in column. Column definitions default to `text`
	 * when an empty value is provided in the schema.
	 *
	 * Intended for use with `dbDelta()` during table installation or upgrade.
	 *
	 * @return string Full CREATE TABLE SQL string.
	 */
	public function get_table_schema() {

		$charset_collate = $this->wpdb()->get_charset_collate();
		$table           = $this->table();
		$default_columns = array(
			'_ID'        => 'bigint(20) NOT NULL AUTO_INCREMENT',
			'cct_status' => 'text'
		);

		$additional_columns = $this->schema;
		$columns_schema     = '';

		foreach ( $default_columns as $column => $desc ) {
			$columns_schema .= $column . ' ' . $desc . ',';
		}

		if ( is_array( $additional_columns ) && ! empty( $additional_columns ) ) {

			foreach ( $additional_columns as $column => $definition ) {

				if ( isset( $default_columns[ $column ] ) ) {
					continue;
				}

				if ( ! $definition ) {
					$definition = 'text';
				}

				$columns_schema .= $column . ' ' . $definition . ',';

			}

		}

		return "CREATE TABLE $table (
			$columns_schema
			PRIMARY KEY (_ID)
		) $charset_collate;";

	}

	/**
	 * Build a mapping of old field names to new field names for schema updates.
	 *
	 * Compares the old and new field definitions by their unique `id` values and
	 * populates `$this->adjust_fields_map` with `old_name => new_name` entries.
	 * This map is later consumed by `maybe_transfer_data()` and
	 * `adjusted_fields_types()` to copy data and convert column types safely
	 * before the old columns are removed.
	 *
	 * @param array $old_fields List of old field definition arrays, each expected
	 *                          to contain at least `id` and `name` keys.
	 * @param array $new_fields List of new field definition arrays, same shape.
	 * @return void
	 */
	public function adjusted_fields_map( $old_fields = array(), $new_fields = array() ) {

		if ( empty( $old_fields ) || empty( $new_fields ) ) {
			return;
		}

		$old_fields_by_id = $this->get_fields_grouped_by_id( $old_fields );
		$new_fields_by_id = $this->get_fields_grouped_by_id( $new_fields );

		foreach ( $old_fields_by_id as $id => $field ) {
			if ( isset( $new_fields_by_id[ $id ] ) ) {
				$this->adjust_fields_map[ $field ] = $new_fields_by_id[ $id ];
			}
		}

	}

	/**
	 * Migrate column data when field SQL types change during a schema update.
	 *
	 * Iterates over every column in `$old_schema` and checks whether the
	 * corresponding column in the new schema (`$this->schema`) has a different
	 * SQL type. When the types differ the method:
	 *
	 *  1. For date/datetime fields being converted from TEXT → BIGINT, converts
	 *     existing string values to Unix timestamps via `UNIX_TIMESTAMP`.
	 *  2. Issues an `ALTER TABLE … MODIFY COLUMN` to change the column type.
	 *  3. For date/datetime fields being converted from BIGINT → TEXT, converts
	 *     timestamp integers back to the appropriate date/datetime string format
	 *     via `FROM_UNIXTIME` / `DATE_FORMAT`.
	 *
	 * Column renames tracked in `$this->adjust_fields_map` are respected so that
	 * renamed fields are looked up under their new names.
	 *
	 * @param array $old_schema     Associative array of column name => SQL type
	 *                              reflecting the table structure before the update.
	 * @param array $old_fields     Field definition list before the update (used to
	 *                              determine the semantic field type, e.g. 'date').
	 * @param array $new_fields     Field definition list after the update.
	 * @return void
	 */
	public function adjusted_fields_types( $old_schema = array(), $old_fields = array(), $new_fields = array() ) {

		if ( empty( $old_schema ) || empty( $this->schema ) ) {
			return;
		}

		$old_fields_types_by_name = wp_list_pluck( $old_fields, 'type', 'name' );
		$new_fields_types_by_name = wp_list_pluck( $new_fields, 'type', 'name' );

		foreach ( $old_schema as $col => $type ) {

			$new_col = isset( $this->adjust_fields_map[ $col ] ) ? $this->adjust_fields_map[ $col ] : $col;

			if ( isset( $this->schema[ $new_col ] ) && $type !== $this->schema[ $new_col ] ) {

				$new_type = $this->schema[ $new_col ];
				$table    = $this->table();

				$old_field_type = $old_fields_types_by_name[ $col ];
				$new_field_type = $new_fields_types_by_name[ $new_col ];

				// Convert datetime string to timestamp string
				if ( in_array( $old_field_type, array( 'date', 'datetime', 'datetime-local' ) )
					 && in_array( $new_field_type, array( 'date', 'datetime', 'datetime-local' ) )
					 && 'TEXT' === $type && 'BIGINT' === $new_type
				) {
					self::wpdb()->query( "UPDATE $table SET $col = UNIX_TIMESTAMP( CONVERT_TZ( $col, '+00:00', @@global.time_zone ) ) WHERE $col IS NOT NULL;" );
				}

				// Change column datatype
				self::wpdb()->query( "ALTER TABLE $table MODIFY COLUMN $col $new_type;" );

				// Convert timestamp string to datetime string
				if ( in_array( $old_field_type, array( 'date', 'datetime', 'datetime-local' ) )
					 && in_array( $new_field_type, array( 'date', 'datetime', 'datetime-local' ) )
					 && 'BIGINT' === $type && 'TEXT' === $new_type
				) {

					switch ( $new_field_type ) {
						case 'date':
							self::wpdb()->query( "UPDATE $table SET $col = FROM_UNIXTIME( $col, '%Y-%m-%d' ) WHERE $col IS NOT NULL;" );
							break;

						case 'datetime':
						case 'datetime-local':
							self::wpdb()->query( "UPDATE $table SET $col = DATE_FORMAT( CONVERT_TZ( FROM_UNIXTIME( $col ), @@global.time_zone, '+00:00' ), '%Y-%m-%dT%H:%i' ) WHERE $col IS NOT NULL;" );
							break;
					}
				}
			}
		}
	}

	/**
	 * Index a field definition list by each field's unique ID.
	 *
	 * Returns an associative array of `field_id => field_name` derived from
	 * the supplied list of field definition arrays. When a field has no `id`
	 * key its numeric list index is used as the key instead.
	 *
	 * Used internally by `adjusted_fields_map()` to correlate old and new
	 * field definitions across a schema update.
	 *
	 * @param array $fields_list List of field definition arrays, each expected
	 *                           to contain at least a `name` key and optionally
	 *                           an `id` key.
	 * @return array Associative array of field_id => field_name.
	 */
	public function get_fields_grouped_by_id( $fields_list = array() ) {

		$result = array();

		foreach ( $fields_list as $index => $field ) {
			$index = isset( $field['id'] ) ? $field['id'] : $index;
			$result[ $index ] = $field['name'];
		}

		return $result;

	}

	/**
	 * Copy data from old columns into their renamed counterparts before removal.
	 *
	 * For each column in `$old_columns` that has an entry in
	 * `$this->adjust_fields_map`, an `UPDATE … SET new_col = old_col` query is
	 * executed so that existing data is preserved when a field is renamed during
	 * a schema update. Columns without a mapping are silently skipped.
	 *
	 * Should be called before the old columns are dropped. Override this method
	 * in child classes to add additional transfer logic.
	 *
	 * @param array $old_columns List of old column names that are about to be removed.
	 * @param array $new_columns List of new column names that have been added.
	 * @return void
	 */
	public function maybe_transfer_data( $old_columns = array(), $new_columns = array() ) {

		if ( empty( $old_columns ) || empty( $new_columns ) ) {
			return;
		}

		foreach ( $old_columns as $index => $col ) {

			$new_col = isset( $this->adjust_fields_map[ $col ] ) ? $this->adjust_fields_map[ $col ] : false;

			if ( $new_col ) {
				$table = $this->table();
				$sql   = "UPDATE $table SET $new_col = $col WHERE $col IS NOT NULL;";
				self::wpdb()->query( $sql );
			}

		}

	}

	/**
	 * Retrieve the current column names and SQL types from the live database table.
	 *
	 * Executes a `SHOW COLUMNS` query against the actual table, excluding the
	 * primary key column `_ID` and any additional fields filtered out via the
	 * `jet-engine/custom-content-types/db/exclude-fields` hook.
	 *
	 * @return array Associative array of column_name => SQL_type (e.g. `text`, `bigint(20)`).
	 *               Returns an empty array if the table has no columns or does not exist.
	 */
	public function get_columns_list() {

		$table = $this->table();
		$sql   = "SHOW COLUMNS FROM `$table` WHERE field NOT LIKE '_ID'";

		$exclude_fields = apply_filters( 'jet-engine/custom-content-types/db/exclude-fields', array() );

		if ( ! empty( $exclude_fields ) ) {
			foreach ( $exclude_fields as $exclude_field ) {
				$sql .= " AND field NOT LIKE '$exclude_field'";
			}
		}

		$columns = self::wpdb()->get_results( $sql );
		$result  = array();


		if ( ! empty( $columns ) ) {
			foreach ( $columns as $column ) {
				$result[ $column->Field ] = $column->Type;
			}
		}

		return $result;
	}

	/**
	 * Return a stable random seed for ORDER BY RAND() queries.
	 *
	 * The seed is cached in a transient for 3 minutes so that paginated
	 * randomised queries return a consistent order within the same browsing
	 * session. A new random integer is generated once the transient expires.
	 *
	 * The final value can be customised via the
	 * `jet-engine/custom-content-types/db/random-seed` filter.
	 *
	 * @return int Random seed value.
	 */
	public function get_random_seed() {

		$transient_key  = 'jet_cct_random_seed';
		$transient_time = 3 * MINUTE_IN_SECONDS;

		$seed = get_transient( $transient_key );

		if ( empty( $seed ) ) {
			$seed = rand();
			set_transient( $transient_key, $seed, $transient_time );
		}

		return apply_filters( 'jet-engine/custom-content-types/db/random-seed', $seed, $transient_key, $transient_time );
	}

	/**
	 * Return the list of column names that are allowed inside SQL search clauses.
	 *
	 * Combines all user-defined schema columns with a fixed set of built-in
	 * service fields (`_ID`, `cct_status`, `cct_author_id`, `cct_created`,
	 * `cct_modified`, `cct_single_post_id`). Duplicates are removed.
	 *
	 * This whitelist is used by `sanitize_search_args()` to prevent arbitrary
	 * column names from being injected into search queries.
	 *
	 * @return array Flat list of allowed column name strings.
	 */
	public function get_searchable_fields() {

		$service_fields = array(
			'_ID',
			'cct_status',
			'cct_author_id',
			'cct_created',
			'cct_modified',
			'cct_single_post_id',
		);

		return array_unique( array_merge( array_keys( $this->schema ), $service_fields ) );
	}

	/**
	 * Validate and normalize search arguments before they are used in a SQL query.
	 *
	 * Accepts either a plain scalar (treated as the search keyword) or an
	 * associative array with the following keys:
	 *  - `keyword` (string, required) – the text to search for.
	 *  - `fields`  (array|string, optional) – column names to search in;
	 *    defaults to all schema columns. A comma-separated string is also
	 *    accepted and will be split automatically.
	 *
	 * Each supplied field name is run through `sanitize_sql_field()` and
	 * checked against the whitelist returned by `get_searchable_fields()` to
	 * guard against SQL injection. Fields that fail validation are silently
	 * removed. Returns `false` if the keyword is missing or no valid fields
	 * remain after filtering.
	 *
	 * @param  mixed $search Raw search argument – scalar keyword or array with
	 *                       `keyword` and optional `fields` keys.
	 * @return array|false   Normalised array with `keyword` (string) and
	 *                       `fields` (array of sanitized column names), or
	 *                       false when the arguments are invalid.
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
	 * Return the total number of rows matching the given filter arguments.
	 *
	 * Builds a `SELECT count(*) FROM …` query using the same WHERE clause logic
	 * as `query()`. The `jet-engine/custom-content-types/sql-count-query` filter
	 * is applied to the WHERE string before execution, allowing third-party
	 * code to append additional SQL conditions.
	 *
	 * @param array  $args Associative array of column => value filter pairs
	 *                     forwarded to `add_where_args()`.
	 * @param string $rel  Logical operator used to join WHERE clauses: 'AND'
	 *                     (default) or 'OR'.
	 * @return string|null Row count as a string, or null on failure.
	 */
	public function count( $args = array(), $rel = 'AND' ) {

		$table = $this->table();

		$query = "SELECT count(*) FROM $table";

		if ( ! $rel ) {
			$rel = 'AND';
		}

		$where = $this->add_where_args( $args, $rel );

		if ( ! $where ) {
			$where = " WHERE 1=1 ";
		}

		$query .= apply_filters( 'jet-engine/custom-content-types/sql-count-query', $where, $table, $args, $this );

		return self::wpdb()->get_var( $query );
	}

	/**
	 * Query CCT items from the database.
	 *
	 * Builds and executes a `SELECT * FROM …` statement using the supplied
	 * filter arguments, optional full-text search, ordering, and pagination.
	 *
	 * Special handling:
	 *  - `$args['_cct_search']` is extracted before WHERE processing and used
	 *    to append an `OR field LIKE %keyword%` clause via `sanitize_search_args()`.
	 *  - When `$order` is empty, results are sorted by `_ID DESC` by default.
	 *  - `$limit` and `$offset` are cast to absolute integers; pagination is
	 *    omitted entirely when `$limit` is 0 or negative.
	 *  - The assembled query parts array is passed through the
	 *    `jet-engine/custom-content-types/sql-query-parts` filter before being
	 *    joined and executed.
	 *  - Each returned row has its array/object values unserialized and a
	 *    `cct_slug` property added that contains the table name (used by
	 *    higher-level layers to identify the originating CCT).
	 *
	 * @param array  $args   Associative array of column => value filter pairs.
	 *                       Use the special key `_cct_search` to add a keyword
	 *                       search clause (scalar keyword or array with `keyword`
	 *                       and optional `fields` keys).
	 * @param int    $limit  Maximum number of rows to return. 0 means no limit.
	 * @param int    $offset Row offset for pagination.
	 * @param array  $order  List of ordering definitions. Each entry is an array
	 *                       with `orderby` and `order` keys. Defaults to
	 *                       `[['orderby' => '_ID', 'order' => 'desc']]`.
	 * @param string $rel    Logical operator joining WHERE clauses: 'AND' (default)
	 *                       or 'OR'.
	 * @return array         List of result rows as arrays or objects (depending on
	 *                       `get_format_flag()`), each with unserialized values and
	 *                       a `cct_slug` key/property.
	 */
	public function query( $args = array(), $limit = 0, $offset = 0, $order = array(), $rel = 'AND' ) {

		$table = $this->table();
		$query = array();

		$query['select'] = "SELECT * FROM $table";

		if ( ! $rel ) {
			$rel = 'AND';
		}

		$search = ! empty( $args['_cct_search'] ) ? $this->sanitize_search_args( $args['_cct_search'] ) : false;

		if ( isset( $args['_cct_search'] ) ) {
			unset( $args['_cct_search'] );
		}

		$where = $this->add_where_args( $args, $rel );

		$query['where'] = ! empty( $where ) ? $where : " WHERE 1=1";

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

				$search_sql = ' ' . $rel;
				$query['search'] = $search_sql . ' (' . $search_str . ')';

			}
		}

		if ( empty( $order ) ) {
			$order = array( array(
				'orderby' => '_ID',
				'order'   => 'desc',
			) );
		}

		$query['order'] = $this->add_order_args( $order );

		if ( intval( $limit ) > 0 ) {
			$limit          = absint( $limit );
			$offset         = absint( $offset );
			$query['limit'] = " LIMIT $offset, $limit";
		}

		$query = apply_filters( 'jet-engine/custom-content-types/sql-query-parts', $query, $table, $args, $this );
		$query = implode( '', $query );

		$this->last_query = $query;

		$raw = self::wpdb()->get_results( $query, $this->get_format_flag() );

		return array_map( function( $item ) {

			if ( is_array( $item ) ) {
				foreach ( $item as $key => $value ) {
					$value = $this->maybe_unserialize_item_value( $key, $value );

					// Removed wp_unslash() since data is already stored without slashes in the database
					// https://github.com/Crocoblock/issues-tracker/issues/15447#issuecomment-2804553957
					$item[ $key ] = $value;
					/*if ( is_string( $value ) ) {
						$item[ $key ] = wp_unslash( $value );
					} else {
						$item[ $key ] = $value;
					}*/
				}

				$item['cct_slug'] = $this->table;

			} elseif ( is_object( $item ) ) {

				foreach ( get_object_vars( $item )  as $key => $value ) {
					$value = $this->maybe_unserialize_item_value( $key, $value );

					// Removed wp_unslash() since data is already stored without slashes in the database
					// https://github.com/Crocoblock/issues-tracker/issues/15447#issuecomment-2804553957
					$item->$key = $value;
					/*if ( is_string( $value ) ) {
						$item->$key = wp_unslash( $value );
					} else {
						$item->$key = $value;
					}*/

				}

				$item->cct_slug = $this->table;
			}

			return $item;
		}, $raw );

	}

}
