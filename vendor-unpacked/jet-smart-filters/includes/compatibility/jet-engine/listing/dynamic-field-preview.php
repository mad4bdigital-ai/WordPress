<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

class Dynamic_Field_Preview {

	public function __construct( $attributes ) {
		add_filter(
			'jet-engine/listings/dynamic-field/custom-value',
			function ( $value ) use ( $attributes ) {

				$attr = '';

				switch ( $attributes['dynamic_field_source'] ) {
					case 'object':
						$attr = $attributes['dynamic_field_post_object'];
						break;
					case 'meta':
						$attr = $attributes['dynamic_field_post_meta'];
						break;
					default:
						$attr = $attributes['dynamic_field_source'];
						break;
				}

				if ( $attr ) {
					$attr = '.' . $attr;
				}

				return '{{dynamic-field' . $attr . '}}';
			}
		);
	}
}