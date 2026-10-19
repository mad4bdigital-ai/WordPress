<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

class Indexer {

	public function __construct() {

		add_filter(
			'jet-smart-filters/indexer/queried-ids-args',
			array( $this, 'expose_real_query_args' )
		);
	}

	/**
	 * Expose real query args for JE queries in the indexer.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function expose_real_query_args( $args ) {

		if ( empty( $args['type'] )
			|| 'jet_engine_query' !== $args['type']
		) {
			return $args;
		}

		$query_id = ! empty( $args['jet_engine_query_id'] ) ? $args['jet_engine_query_id'] : false;

		if ( ! $query_id || ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) {
			return $args;
		}

		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );

		if ( ! $query || ! is_object( $query ) ) {
			return $args;
		}

		$query->setup_query();

		return $query->final_query;
	}
}
