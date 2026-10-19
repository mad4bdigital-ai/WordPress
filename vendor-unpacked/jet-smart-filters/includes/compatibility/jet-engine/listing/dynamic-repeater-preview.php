<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

class Dynamic_Repeater_Preview {

	public function __construct( $attributes ) {
		add_filter(
			'jet-engine/listings/dynamic-repeater/pre-get-saved',
			function ( $value ) use ( $attributes ) {

				$preview = [];

				$format = $attributes['dynamic_field_format'] ?? '';

				if ( ! empty( $format ) ) {
					// Extract all placeholders like %field_name% from the format string
					preg_match_all( '/%([^%]+)%/', $format, $matches );
					if ( ! empty( $matches[1] ) ) {
						for ( $i = 0; $i < 2; $i++ ) {
							foreach ( $matches[1] as $field_name ) {
								$preview[ $i ][ $field_name ] = sprintf( '%%%s%%', $field_name );
							}
						}

					}
				}

				return $preview;
			}
		);
	}
}