<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

class Dynamic_Terms_Preview {

	public function __construct( $attributes ) {

		jet_engine()->listings->data->set_current_object( new \WP_Post( (object) [
			'ID' => PHP_INT_MAX,
		] ) );

		add_filter(
			'jet-engine/listings/dynamic-terms/items',
			function ( $terms ) use ( $attributes ) {

				if ( empty( $terms ) ) {
					$terms = [
						new \WP_Term( (object) [
							'term_id' => 1,
							'name'    => 'Term 1',
							'slug'    => 'term-1',
						] ),
						new \WP_Term( (object) [
							'term_id' => 2,
							'name'    => 'Term 2',
							'slug'    => 'term-2',
						] ),
					];
				}

				return $terms;
			}
		);
	}
}