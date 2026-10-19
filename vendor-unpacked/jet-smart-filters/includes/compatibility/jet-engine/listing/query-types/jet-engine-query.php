<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing\Query_Types;

use Jet_Smart_Filters\Listing\Render\Query_Types\Base;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Listing query type powered by JetEngine Query Builder.
 */
class Jet_Engine_Query extends Base {

	/**
	 * Cached JetEngine query object.
	 *
	 * @var object|false|null
	 */
	protected $jet_engine_query = null;

	/**
	 * Query type ID.
	 *
	 * @return string
	 */
	public static function get_type() {
		return 'jet_engine_query';
	}

	/**
	 * Normalize saved query args.
	 *
	 * @param array $query_args Raw query args.
	 *
	 * @return array
	 */
	protected function prepare_query_args( $query_args = [] ) {

		$args = parent::prepare_query_args( $query_args );

		$args['type']                = self::get_type();
		$args['jet_engine_query_id'] = ! empty( $args['jet_engine_query_id'] ) ? absint( $args['jet_engine_query_id'] ) : 0;

		return $args;
	}

	/**
	 * Resolve JetEngine query builder instance.
	 *
	 * @return object|false
	 */
	protected function get_jet_engine_query() {

		if ( null !== $this->jet_engine_query ) {
			return $this->jet_engine_query;
		}

		if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			$this->jet_engine_query = false;
			return false;
		}

		$query_id = ! empty( $this->query_args['jet_engine_query_id'] ) ? absint( $this->query_args['jet_engine_query_id'] ) : 0;

		if ( ! $query_id ) {
			$this->jet_engine_query = false;
			return false;
		}

		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );

		if ( ! $query || ! is_object( $query ) ) {
			$this->jet_engine_query = false;
			return false;
		}

		if ( is_callable( array( $query, 'setup_query' ) ) ) {
			$query->setup_query();
		}

		$this->jet_engine_query = $query;

		return $this->jet_engine_query;
	}

	/**
	 * Forward filter args into JetEngine's filtered-props API.
	 *
	 * @param array $query_args Extra query args from JSF request.
	 *
	 * @return void
	 */
	public function add_query_args( $query_args ) {

		$query_args       = is_array( $query_args ) ? $query_args : array();
		$this->query_args = $this->prepare_query_args( array_merge( $this->query_args, $query_args ) );

		$query = $this->get_jet_engine_query();

		if ( ! $query || ! is_callable( array( $query, 'set_filtered_prop' ) ) ) {
			return;
		}

		$filtered_args = $query_args;

		if ( isset( $filtered_args['paged'] ) ) {
			$filtered_args['_page'] = absint( $filtered_args['paged'] );
			unset( $filtered_args['paged'] );
		}

		if ( isset( $filtered_args['posts_per_page'] ) ) {
			$filtered_args['_items_per_page'] = absint( $filtered_args['posts_per_page'] );
			unset( $filtered_args['posts_per_page'] );
		}

		unset( $filtered_args['type'], $filtered_args['jet_engine_query_id'] );

		foreach ( $filtered_args as $prop => $value ) {
			$query->set_filtered_prop( $prop, $value );
		}
	}

	/**
	 * Get query stats for pagination/counters.
	 *
	 * @return array
	 */
	public function get_stats() {

		$query = $this->get_jet_engine_query();

		if ( ! $query ) {
			return parent::get_stats();
		}

		return array(
			'found_posts'   => is_callable( array( $query, 'get_items_total_count' ) ) ? absint( $query->get_items_total_count() ) : 0,
			'max_num_pages' => is_callable( array( $query, 'get_items_pages_count' ) ) ? absint( $query->get_items_pages_count() ) : 0,
			'page'          => is_callable( array( $query, 'get_current_items_page' ) ) ? max( 1, absint( $query->get_current_items_page() ) ) : 1,
		);
	}

	/**
	 * Get items from JetEngine query.
	 *
	 * @return array
	 */
	protected function _get_items() {

		$query = $this->get_jet_engine_query();

		if ( ! $query || ! is_callable( array( $query, 'get_items' ) ) ) {
			return array();
		}

		$items = $query->get_items();

		if ( ! is_array( $items ) ) {
			return array();
		}

		return $items;
	}

	/**
	 * Resolve current item ID.
	 *
	 * @param mixed $item Item object or ID.
	 *
	 * @return int|null
	 */
	public function get_item_id( $item ) {

		if ( $item instanceof \WP_Post ) {
			return $item->ID;
		}

		if ( is_numeric( $item ) ) {
			return absint( $item );
		}

		if ( is_object( $item ) && isset( $item->ID ) ) {
			return absint( $item->ID );
		}

		if (
			function_exists( 'jet_engine' )
			&& isset( jet_engine()->listings )
			&& isset( jet_engine()->listings->data )
			&& is_callable( array( jet_engine()->listings->data, 'get_current_object_id' ) )
		) {
			return absint( jet_engine()->listings->data->get_current_object_id( $item ) );
		}

		return null;
	}
}
