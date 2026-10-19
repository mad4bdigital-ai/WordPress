<?php
namespace Jet_Smart_Filters\Compatibility\Jet_Engine\Listing;

class Dynamic_Image_Preview {

	public function __construct( $attributes ) {
		add_filter(
			'jet-engine/listings/dynamic-image/custom-fallback',
			[ $this, 'preview_image' ]
		);

		add_filter(
			'jet-engine/listings/dynamic-image/custom-image',
			[ $this, 'preview_image' ]
		);
	}

	/**
	 * Provide a placeholder image for dynamic image block preview in listing builder.
	 *
	 * @param string $image Original image URL.
	 *
	 * @return string Modified image URL for preview.
	 */
	public function preview_image( $image ) {
		$image = esc_url( jet_smart_filters()->plugin_url( 'assets/images/placeholder.png' ) );
		$result = sprintf( '<img src="%s" alt="">', $image );
		return $result;
	}
}