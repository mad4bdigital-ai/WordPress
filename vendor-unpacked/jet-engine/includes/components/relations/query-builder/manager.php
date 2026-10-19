<?php
namespace Jet_Engine\Relations\Query_Builder;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Manager {

	/**
	 * A reference to an instance of this class.
	 *
	 * @access private
	 * @var    object
	 */
	public static $instance = null;

	public $slug = 'relations-query';

	/**
	 * Class constructor
	 */
	public function __construct() {

		add_action(
			'jet-engine/query-builder/query-editor/register',
			array( $this, 'register_editor_component' )
		);

		add_action(
			'jet-engine/query-builder/queries/register',
			array( $this, 'register_query' )
		);

		add_filter(
			'jet-engine/query-builder/set-props',
			array( $this, 'adjust_query_type_for_filters' ), 0, 4
		);

		add_filter(
			'jet-engine/query-builder/types/sql-query/data',
			array( $this, 'label_sql_relation_tables' )
		);
	}

	/**
	 * Add relation labels to SQL Query Builder table options.
	 *
	 * @param array $data SQL query editor component data.
	 *
	 * @return array
	 */
	public function label_sql_relation_tables( $data ) {

		if ( empty( $data['tables'] ) || ! is_array( $data['tables'] ) ) {
			return $data;
		}

		$relation_tables = $this->get_dedicated_relation_table_labels();

		if ( empty( $relation_tables ) ) {
			return $data;
		}

		foreach ( $data['tables'] as $index => $option ) {

			if ( empty( $option['value'] ) || ! isset( $relation_tables[ $option['value'] ] ) ) {
				continue;
			}

			$data['tables'][ $index ]['label'] = sprintf(
				'%1$s (%2$s)',
				$option['value'],
				$relation_tables[ $option['value'] ]
			);
		}

		return $data;
	}

	/**
	 * Get SQL table option values for dedicated relation tables.
	 *
	 * @return array
	 */
	public function get_dedicated_relation_table_labels() {

		if ( ! jet_engine()->relations ) {
			return array();
		}

		$relations = jet_engine()->relations->get_active_relations();

		if ( empty( $relations ) || ! is_array( $relations ) ) {
			return array();
		}

		$result = array();

		foreach ( $relations as $relation ) {

			if ( ! is_object( $relation ) || ! is_callable( array( $relation, 'get_args' ) ) ) {
				continue;
			}

			if ( ! $relation->get_args( 'db_table' ) ) {
				continue;
			}

			if ( empty( $relation->db ) || ! is_callable( array( $relation->db, 'table' ) ) ) {
				continue;
			}

			if ( ! is_callable( array( $relation, 'get_relation_name' ) ) ) {
				continue;
			}

			$relation_name = trim( $relation->get_relation_name() );

			if ( '' === $relation_name ) {
				continue;
			}

			$table = $this->unprefix_db_table_name( $relation->db->table() );

			if ( ! $table ) {
				continue;
			}

			$result[ $table ] = $relation_name;
		}

		return $result;
	}

	/**
	 * Strip current WordPress DB prefix from full table name.
	 *
	 * @param string $table Full DB table name.
	 *
	 * @return string
	 */
	public function unprefix_db_table_name( $table ) {

		global $wpdb;

		$table  = (string) $table;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : '';

		if ( $prefix && 0 === strpos( $table, $prefix ) ) {
			return substr( $table, strlen( $prefix ) );
		}

		return $table;
	}

	/**
	 * Adjust query type for the filters request
	 *
	 * @param  array  $props
	 * @param  string $query_id
	 * @param  object $query
	 *
	 * @return array
	 */
	public function adjust_query_type_for_filters( $props, $provider, $query_id, $query ) {

		if ( $this->slug !== $query->get_query_type() ) {
			return $props;
		}

		$query_args = ! empty( $query->query ) ? $query->query : array();

		if ( empty( $query_args ) ) {
			return $props;
		}

		$rel_id = isset( $query_args['rel_id'] ) ? $query_args['rel_id'] : false;

		if ( ! $rel_id ) {
			return $props;
		}

		$relation = jet_engine()->relations->get_active_relations( $rel_id );

		if ( ! $relation ) {
			return $props;
		}

		if ( ! $relation ) {
			return $props;
		}

		$rel_object = isset( $query_args['rel_object'] ) ? $query_args['rel_object'] : 'child_object';

		$queried_type =$relation->get_object_type_for( $rel_object );
		$object_name = $relation->get_object_name_for( $rel_object );

		if ( ! $queried_type ) {
			return $props;
		}

		$query_type = $queried_type->get_query_type();

		if ( 'mix' === $query_type ) {
			$query_type = $object_name;
		}

		$props['query_type'] = $query_type;
		$props['query_meta'] = [
			'content_type' => $object_name,
		];

		return $props;
	}

	/**
	 * Register editor component for the query builder
	 *
	 * @param  $manager
	 *
	 * @return void
	 */
	public function register_editor_component( $manager ) {
		require_once jet_engine()->relations->component_path( 'query-builder/editor.php' );
		$manager->register_type( new Query_Editor() );
	}

	/**
	 * Register query class
	 *
	 * @param  $manager
	 *
	 * @return void
	 */
	public function register_query( $manager ) {

		require_once jet_engine()->relations->component_path( 'query-builder/query.php' );
		$type  = $this->slug;
		$class = __NAMESPACE__ . '\Relations_Query';

		$manager::register_query( $type, $class );
	}

	/**
	 * Returns the instance.
	 *
	 * @access public
	 * @return object
	 */
	public static function instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;

	}

}
