<?php
	use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

	$current_object = Listing_Controller::instance()->render->get_query_object();

	if ( ! $current_object ) {
		global $post;
		$current_object = $post;
	}

	$source          = $attributes['source'];
	$labelType       = $attributes['label_type'];
	$label_aria_type = $attributes['label_aria_type'];
	$is_new_window   = $attributes['is_new_window'];
	$rel_attribute   = $attributes['rel_attribute_type'];

	// Link
	$link_settings = [
		'listing_link_source'         => $source,
		'listing_link_attachment_key' => $attributes['attachment_key'] ?? '',
		'listing_link_meta_key'       => $attributes['meta_key'] ?? '',
		'listing_link_option_name'    => $attributes['option_name'] ?? '',
		'listing_link_fallback'       => $attributes['link_fallback'] ?? '',
		'listing_link_url_prefix'     => $attributes['url_prefix'] ?? '',
		'listing_link_url_anchor'     => $attributes['url_anchor'] ?? '',
	];


	$link = Listing_Controller::instance()->helpers->utils->get_listing_item_link_url( $link_settings, $current_object );

	$link = apply_filters( 'jet-smart-filters/listing/blocks/listing-link/link-value', $link, $attributes );

	// Label
	$label = '';

	switch ( $labelType ) {
		case 'static':
			$label = $attributes['label_text'];

			break;

		case 'dynamic':
			$label_source = $attributes['label_source'];

			switch ( $label_source ) {
				case 'object':
					$label_object = $attributes['label_object'];
					$label        = Listing_Controller::instance()->helpers->utils->get_object_field_value( $label_object, $current_object );

					break;

				case 'meta':
					$label_meta_key = $attributes['label_meta_key'];
					$label          = Listing_Controller::instance()->helpers->utils->get_meta_value( $label_meta_key, $current_object );

					break;

				case 'option':
					$label_option_name = $attributes['label_option_name'];
					$label             = get_option( $label_option_name );

					break;
			}

			break;
	}

	$label = apply_filters( 'jet-smart-filters/listing/blocks/listing-link/label-value', $label, $attributes );

	// Aria Label
	$label_aria = '';

	switch ( $label_aria_type ) {
		case 'inherit':
			$label_aria = $label;

			break;

		case 'custom':
			$label_aria = $attributes['label_aria_text'];

			break;
	}


	$attributes = [];

	if ( ! empty( $label_aria ) ) {
		$attributes[] = 'aria-label="' . esc_attr( $label_aria ) . '"';
	}

	if ( ! empty( $is_new_window ) ) {
		$attributes[] = 'target="_blank"';
	}

	if ( ! empty( $rel_attribute ) ) {
		$attributes[] = 'rel="' . esc_attr( $rel_attribute ) . '"';
	}

	$attr_string = $attributes ? ' ' . implode( ' ', $attributes ) : '';

	$wrapper_attrs = get_block_wrapper_attributes( [
		'class' => 'jsf-listing-link-block'
	] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div ' . $wrapper_attrs . '>';

	echo sprintf(
		'<a href="%s" class="jsf-listing-link"%s>%s</a>',
		esc_url( $link ),
		esc_attr( $attr_string ),
		esc_html( $label )
	);

	echo '</div>';
?>
