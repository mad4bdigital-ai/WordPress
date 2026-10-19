<?php
namespace Jet_Engine\CPT\Custom_Tables;

/**
 * @property Preset form_preset
 *
 * Class Module
 * @package Jet_Engine\Modules\Custom_Content_Types
 */
class Manager {

	/**
	 * A reference to an instance of this class.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    object
	 */
	private static $instance = null;

	public $storages = [];
	public $suffix = '_meta';

	/**
	 * Constructor for the class
	 */
	public function __construct() {
		add_action( 'jet-engine/post-types/registered', [ $this, 'init' ] );
	}

	/**
	 * Make sure DB class is correctlry loaded
	 * @return [type] [description]
	 */
	public function ensure_has_db_class() {
		if ( ! class_exists( '\Jet_Engine\CPT\Custom_Tables\DB' ) ) {
			require_once jet_engine()->cpt->component_path( 'custom-tables/db.php' );
		}
	}

	/**
	 * Initialize all handlers for registered CPTs
	 * @return [type] [description]
	 */
	public function init() {

		$this->ensure_has_db_class();
		
		if ( empty( $this->storages ) ) {
			return;
		}

		require_once jet_engine()->cpt->component_path( 'custom-tables/meta-storage.php' );
		require_once jet_engine()->cpt->component_path( 'custom-tables/meta-query.php' );
		require_once jet_engine()->cpt->component_path( 'custom-tables/query.php' );

		foreach ( $this->storages as $data ) {

			$object_type = $data['object_type'];
			$object_slug = $data['object_slug'];

			$db = $this->get_db_instance( $object_slug, $data['fields'] );

			new Meta_Storage( $db, $object_type, $object_slug, $data['fields'] );
			new Query( $db, $object_type, $object_slug, $data['fields'] );

		}

		add_filter( 'posts_clauses', [ $this, 'add_posts_clauses' ], 10, 2 );

	}

	/**
	 * Public function get table name from oject slug
	 * @return [type] [description]
	 */
	public function get_table_name( $slug = '' ) {

		$table_name = str_replace( '-', '_', $slug );

		return apply_filters( 
			'jet-engine/custom-meta-tables/table-name-for-object-slug',
			$table_name . $this->suffix,
			$slug
		);

	}

	/**
	 * Returns a DB manager instance for given config
	 * 
	 * @param  string $object_slug              Object slug
	 * @param  array  $fields                   Array of fields (columns)
	 * 
	 * @return \Jet_Engine\CPT\Custom_Tables\DB DB instance
	 */
	public function get_db_instance( $object_slug, $fields = [] ) {

		$this->ensure_has_db_class();

		$schema = [
			'meta_ID'   => 'bigint(20) NOT NULL AUTO_INCREMENT',
			'object_ID' => 'bigint(20)',
		];

		if ( ! empty( $fields ) ) {
			foreach( $fields as $field ) {
				$schema[ $field ] = false;
			}
		}

		$schema = apply_filters(
			'jet-engine/custom-meta-tables/object-schema/' . $object_slug,
			$schema
		);

		return new DB( $this->get_table_name( $object_slug ), $schema );

	}

	/**
	 * Add custom posts query clauses to default WP query
	 * 
	 * @param [type] $clauses [description]
	 * @param [type] $query   [description]
	 */
	public function add_posts_clauses( $clauses, $query ) {

		$custom_table_query = $query->get( 'custom_table_query' );
		$custom_table_query = $this->sanitize_custom_table_query( $custom_table_query );

		if ( $custom_table_query ) {

			global $wpdb;

			$custom_query = new Meta_Query( $custom_table_query['query'] );
			$custom_query->set_custom_table( $custom_table_query['table'] );
			$custom_clauses = $custom_query->get_sql( 'post', $wpdb->posts, 'ID', $query );
			$table_name     = $this->quote_sql_identifier( $custom_table_query['table'] );

			if ( ! empty( $custom_clauses['join'] ) ) {
				$clauses['join'] .= $custom_clauses['join'];
			}

			if ( ! empty( $custom_clauses['where'] ) ) {
				$clauses['where'] .= $custom_clauses['where'];
			}

			$clauses['fields'] .= sprintf( ', %s.*', $table_name );

			if ( ! empty( $custom_table_query['order'] ) ) {
				$order_list = $custom_table_query['order'];

				foreach ( $order_list as $clause ) {
					if ( ! empty( $clause['custom_key'] ) && ! empty( $clause['replacement'] ) ) {
						$r = $clause['replacement'];
						$o = $clause['order'];
						$orderby_fragment = sprintf(
							'%1$s.%2$s %3$s',
							$table_name,
							$this->quote_custom_table_column( $clause['custom_key'] ),
							esc_sql( $clause['order'] )
						);
						
						$clauses['orderby'] = str_replace(
							"$r $o",
							$orderby_fragment,
							$clauses['orderby']
						);
					}
				}
			}

		}

		return $clauses;
	}

	/**
	 * Normalize custom table query payload before it reaches SQL assembly.
	 *
	 * @param mixed $custom_table_query Query data from WP_Query.
	 * @return array|false
	 */
	protected function sanitize_custom_table_query( $custom_table_query ) {

		if ( empty( $custom_table_query ) || ! is_array( $custom_table_query ) ) {
			return false;
		}

		if ( empty( $custom_table_query['table'] ) || ! is_string( $custom_table_query['table'] ) ) {
			return false;
		}

		$storage = $this->get_storage_by_table_name( $custom_table_query['table'] );

		if ( ! $storage ) {
			return false;
		}

		$custom_table_query['table'] = $storage['table'];
		$custom_table_query['query'] = $this->sanitize_custom_table_meta_query(
			$custom_table_query['query'] ?? [],
			$storage['fields']
		);
		$custom_table_query['order'] = $this->sanitize_custom_table_order_clauses(
			$custom_table_query['order'] ?? [],
			$storage['fields']
		);

		return $custom_table_query;
	}

	/**
	 * Validate custom table order clauses before ORDER BY rewriting.
	 *
	 * @param mixed $order_list Raw order clauses.
	 * @param array $allowed_fields Registered custom table fields.
	 * @return array
	 */
	protected function sanitize_custom_table_order_clauses( $order_list, $allowed_fields = [] ) {

		if ( ! is_array( $order_list ) ) {
			return [];
		}

		$prepared = [];

		foreach ( $order_list as $clause ) {

			if ( ! is_array( $clause ) ) {
				continue;
			}

			$replacement = isset( $clause['replacement'] ) ? trim( (string) $clause['replacement'] ) : '';
			$order       = isset( $clause['order'] ) ? strtoupper( trim( (string) $clause['order'] ) ) : '';
			$custom_key  = isset( $clause['custom_key'] ) ? trim( (string) $clause['custom_key'] ) : '';

			if ( '' === $replacement || '' === $custom_key || ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9_().]+$/', $replacement ) ) {
				continue;
			}

			$base_custom_key = preg_replace( '/\+0$/', '', $custom_key );

			if ( ! preg_match( '/^[A-Za-z0-9_]+(?:\+0)?$/', $custom_key ) || ! in_array( $base_custom_key, $allowed_fields, true ) ) {
				continue;
			}

			$prepared[] = [
				'replacement' => $replacement,
				'order'       => $order,
				'custom_key'  => $custom_key,
			];
		}

		return $prepared;
	}

	/**
	 * Sanitize custom table meta query clauses.
	 *
	 * @param mixed $query Raw custom table meta query.
	 * @param array $allowed_fields Registered custom table fields.
	 * @return array
	 */
	protected function sanitize_custom_table_meta_query( $query, $allowed_fields = [] ) {

		if ( ! is_array( $query ) ) {
			return [];
		}

		$prepared = [];
		$relation = isset( $query['relation'] ) ? strtoupper( trim( (string) $query['relation'] ) ) : '';

		if ( in_array( $relation, [ 'AND', 'OR' ], true ) ) {
			$prepared['relation'] = $relation;
		}

		foreach ( $query as $key => $clause ) {

			if ( 'relation' === $key ) {
				continue;
			}

			if ( ! is_array( $clause ) ) {
				continue;
			}

			if ( array_key_exists( 'key', $clause ) ) {
				$clause = $this->sanitize_custom_table_meta_clause( $clause, $allowed_fields );

				if ( $clause ) {
					$prepared[ $key ] = $clause;
				}

				continue;
			}

			$nested = $this->sanitize_custom_table_meta_query( $clause, $allowed_fields );

			if ( ! empty( $nested ) ) {
				$prepared[ $key ] = $nested;
			}
		}

		return $prepared;
	}

	/**
	 * Sanitize a single custom table meta query clause.
	 *
	 * @param mixed $clause Raw clause.
	 * @param array $allowed_fields Registered custom table fields.
	 * @return array|false
	 */
	protected function sanitize_custom_table_meta_clause( $clause, $allowed_fields = [] ) {

		if ( empty( $clause['key'] ) || ! is_string( $clause['key'] ) ) {
			return false;
		}

		$field = trim( $clause['key'] );

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $field ) || ! in_array( $field, $allowed_fields, true ) ) {
			return false;
		}

		$prepared        = $clause;
		$prepared['key'] = $field;

		if ( isset( $prepared['type'] ) ) {
			$prepared['type'] = $this->sanitize_custom_table_meta_type( $prepared['type'] );
		}

		if ( isset( $prepared['compare'] ) ) {
			$prepared['compare'] = strtoupper( trim( (string) $prepared['compare'] ) );
		}

		if ( isset( $prepared['compare_key'] ) ) {
			$prepared['compare_key'] = strtoupper( trim( (string) $prepared['compare_key'] ) );
		}

		return $prepared;
	}

	/**
	 * Sanitize custom-table meta cast type while preserving valid decimal precision.
	 *
	 * @param mixed $type Raw cast type.
	 * @return string
	 */
	protected function sanitize_custom_table_meta_type( $type ) {

		$type = strtoupper( preg_replace( '/\s+/', '', (string) $type ) );

		if ( preg_match( '/^DECIMAL\(\d+,\d+\)$/', $type ) ) {
			return $type;
		}

		return preg_replace( '/[^A-Z_]/', '', $type );
	}

	/**
	 * Quote a sanitized SQL identifier.
	 *
	 * @param string $identifier Sanitized identifier.
	 * @return string
	 */
	protected function quote_sql_identifier( $identifier ) {
		return sprintf( '`%s`', esc_sql( $identifier ) );
	}

	/**
	 * Quote a sanitized custom-table column, preserving the numeric suffix cast shortcut.
	 *
	 * @param string $column Sanitized column name.
	 * @return string
	 */
	protected function quote_custom_table_column( $column ) {

		$suffix = '';

		if ( str_ends_with( $column, '+0' ) ) {
			$column = substr( $column, 0, -2 );
			$suffix = '+0';
		}

		return $this->quote_sql_identifier( $column ) . $suffix;
	}

	/**
	 * Get registered custom storage config for the given table.
	 *
	 * @param string $table Raw table name.
	 * @return array|false
	 */
	protected function get_storage_by_table_name( $table ) {
		$table = trim( (string) $table );

		foreach ( $this->storages as $storage ) {
			$storage_table = $this->get_db_instance( $storage['object_slug'], $storage['fields'] )->table();

			if ( $storage_table === $table ) {
				return [
					'table'  => $storage_table,
					'fields' => isset( $storage['fields'] ) && is_array( $storage['fields'] ) ? $storage['fields'] : [],
				];
			}
		}

		return false;
	}

	/**
	 * Register new custom sotrage
	 * 
	 * @param  string $post_type [description]
	 * @param  string $table     [description]
	 * @param  array  $fields    [description]
	 * @return [type]            [description]
	 */
	public function register_storage( $object_type = 'post', $object_slug = '', $fields = [] ) {

		$fields_data = $this->prepare_fields( $fields, $object_slug );

		$this->storages[] = apply_filters( 'jet-engine/custom-meta-tables/storage-data', [
			'object_type' => $object_type,
			'object_slug' => $object_slug,
			'fields'      => $fields_data['as_columns'],
			'raw_fields'  => $fields_data['raw'],
		], $this );

	}

	/**
	 * Ensure fields will be registered in correct format
	 * 
	 * @param  array  $fields [description]
	 * @return array  $prepared_fields        [description]
	 */
	public function prepare_fields( $fields = [], $object_slug = '' ) {

		$prepared_fields = [
			'as_columns' => [],
			'raw'        => [],
		];

		if ( ! empty( $fields ) ) {
			foreach( $fields as $field ) {
				if ( is_string( $field ) ) {
					$prepared_fields['as_columns'][] = $this->sanitize_field_name( $field );
					$prepared_fields['raw'][] = [
						'field_name' => $field,
						'type'       => 'text',
					];
				} elseif ( is_array( $field ) && isset( $field['name'] ) ) {
					if ( ! isset( $field['object_type'] ) || 'field' === $field['object_type'] ) {
						$prepared_fields['as_columns'][] = $this->sanitize_field_name( $field['name'] );
						$prepared_fields['raw'][] = [
							'name' => $field['name'],
							'type' => $field['type'],
						];
					}
				}
			}
		}

		$prepared_fields = apply_filters( 'jet-engine/custom-meta-tables/prepared_fields', $prepared_fields, $object_slug );

		$prepared_fields['as_columns'] = array_unique( $prepared_fields['as_columns'], SORT_STRING );

		return $prepared_fields;
	}

	/**
	 * Ensure field name can be used as column
	 * 
	 * @param  string $field [description]
	 * @return [type]        [description]
	 */
	public function sanitize_field_name( $field = '' ) {

		$field = str_replace( '-', '_', $field );

		// Remove any characters that are not alphanumeric or underscore
		$field = str_replace(
			[ '(', ')', '{', '}', '<', '>', ' ', '.', ',', ';', '=', '+', '*', '&', '^', '%', '$', '#', '@', '!', '~', '`', '?', '/', '\\'], 
			'', 
			$field
		);

		// Ensure the column name is not empty
		if ( empty( $field ) ) {
			return false;
		}

		// Ensure the column name does not start with a number
		if ( ctype_digit( substr( $field, 0, 1 ) ) ) {
			// If the column name starts with a number, prefix it with an underscore or another character
			$field = '_' . $field;
		}

		// Return the sanitized column name
		return $field;

	}

	/**
	 * Returns the instance.
	 *
	 * @since  1.0.0
	 * @access public
	 * @return \Jet_Engine\CPT\Custom_Tables\Manager
	 */
	public static function instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

}
