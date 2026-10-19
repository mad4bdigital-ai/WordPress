<?php
	use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

	$current_object = Listing_Controller::instance()->render->get_query_object();

	if ( ! $current_object ) {
		global $post;
		$current_object = $post;
	}

	$source        = $attributes['source'];
	$tag           = $attributes['tag'];
	$fallback      = $attributes['fallback'];
	$use_filter    = $attributes['use_filter'];
	$custom_format = $attributes['use_custom_format']
		? $attributes['custom_format']
		: false;

	$field_value = '';

	switch ( $source ) {
		case 'object':
			$object      = $attributes['object'];
			$field_value = Listing_Controller::instance()->helpers->utils->get_object_field_value( $object, $current_object );

			break;

		case 'meta':
			$meta_key    = $attributes['meta_key'];
			$field_value = Listing_Controller::instance()->helpers->utils->get_meta_value( $meta_key, $current_object );

			break;

		case 'option':
			$option_name = $attributes['option_name'];
			$field_value = get_option( $option_name );

			break;
	}


	if ( ! $field_value && $fallback ) {
		$field_value = $fallback;
	}

	if ( $use_filter ) {
		$field_value = Listing_Controller::instance()->helpers->utils->apply_filter_callback(
			$attributes['filter_callback'],
			$field_value,
			[
				'date_format'         => $attributes['date_format'],
				'thousands_separator' => $attributes['thousands_separator'],
				'decimal_count'       => $attributes['decimal_count'],
				'decimal_point'       => $attributes['decimal_point'],
			]
		);
	}

	if ( $custom_format ) {
		$field_value = sprintf( $custom_format, $field_value );
	}

	$field_value = apply_filters( 'jet-smart-filters/listing/blocks/listing-field/field_value', $field_value, $attributes );

	if ( $field_value ) {
		$wrapper_attrs = get_block_wrapper_attributes( [
			'class' => 'jsf-listing-field-block'
		] );

		printf(
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<%1$s ' . $wrapper_attrs . '><div class="jsf-listing-field">%2$s</div></%1$s>',
			esc_attr( $tag ),
			esc_html( $field_value )
		);
	}
?>