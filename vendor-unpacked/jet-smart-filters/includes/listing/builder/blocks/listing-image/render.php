<?php
	use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

	$current_object = Listing_Controller::instance()->render->get_query_object();

	if ( ! $current_object ) {
		global $post;
		$current_object = $post;
	}

	$image_source        = $attributes['image_source'];
	$image_meta_key      = $attributes['image_meta_key'];
	$image_option_name   = $attributes['image_option_name'];
	$image_url_prefix    = $attributes['image_url_prefix'];
	$image_size          = $attributes['image_size'];
	$image_fallback      = $attributes['image_fallback'];
	$custom_image_alt    = $attributes['custom_image_alt'];
	$image_is_linked     = $attributes['image_is_linked'];
	$image_possible_keys = ['id'];

	// Image
	$image = null;

	if ( $image_source === 'post_thumbnail' ) {
		$image = Listing_Controller::instance()->helpers->utils->get_object_image( $current_object, [
			'class' => 'jsf-listing-image__img',
			'size'  => $image_size,
			'alt'   => $custom_image_alt
		] );
	} else if ( in_array( $image_source, ['meta', 'options'] ) ) {
		$value = null;

		switch ( $image_source ) {
			case 'meta':
				if ( isset( $current_object->ID ) ) {
					$value = get_post_meta( $current_object->ID, $image_meta_key, true );
				} else {
					$value = Listing_Controller::instance()->helpers->utils->get_meta_value( $image_meta_key, $current_object );
				}
				break;

			case 'options':
				$value = get_option( $image_option_name );
				break;
		}

		if ( $value ) {
			if ( is_numeric( $value ) ) {
				$image_url = wp_get_attachment_image_url( intval( $value ), $image_size );
			} else if ( is_array( $value ) ) {
				foreach ( $image_possible_keys as $key ) {
					if ( isset( $value[$key] ) ) {
						$image_url = wp_get_attachment_image_url( intval( $value[$key] ), $image_size );
						break;
					}
				}
			} else {
				$image_url = $image_url_prefix . $value;
			}

			if ( filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
				$image = sprintf(
					'<img src="%s" class="%s" alt="%s" />',
					esc_url( $image_url ),
					'jsf-listing-image__img',
					esc_attr( $custom_image_alt )
				);
			}
		}
	}

	if ( ! $image && $image_fallback ) {
		$image = wp_get_attachment_image(
			$image_fallback,
			$image_size,
			false,
			array(
				'class' => 'jsf-listing-image__img'
			)
		);
	}

	if ( ! $image ) {
		return;
	}

	// Link
	if ( $image_is_linked ) {
		$link        = '';
		$link_source = $attributes['link_source'];

		switch ( $link_source ) {
			case 'permalink':
				$link = get_permalink( $current_object );

				break;

			case 'attachment':
				$link_attachment_key = $attributes['link_attachment_key'];
				$attachment_id       = Listing_Controller::instance()->helpers->utils->get_meta_value( $link_attachment_key, $current_object );

				$link = wp_get_attachment_url( $attachment_id );

				break;

			case 'meta':
				$link_meta_key = $attributes['link_meta_key'];
				$link          = Listing_Controller::instance()->helpers->utils->get_meta_value( $link_meta_key, $current_object );

				break;

			case 'options':
				$link_option_name = $attributes['link_option_name'];
				$link             = get_option( $link_option_name );

				break;
		}
	}

	$wrapper_attrs = get_block_wrapper_attributes( [
		'class' => 'jsf-listing-image-block'
	] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div ' . $wrapper_attrs  . '>';

		echo ! empty( $link )
			? sprintf(
				'<a href="%s" class="jsf-listing-image">%s</a>',
				esc_url( $link ),
				wp_kses_post( $image )
			)
			: sprintf(
				'<div class="jsf-listing-image">%s</div>',
				wp_kses_post( $image )
			);

	echo '</div>';
?>