<?php
	use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

	$current_object = Listing_Controller::instance()->render->get_query_object();

	if ( ! $current_object ) {
		global $post;
		$current_object = $post;
	}

	$from_tax  = $attributes['from_tax'];
	$show_all  = $attributes['show_all_terms'];
	$limit     = $attributes['terms_num'];
	$order_by  = $attributes['order_by'];
	$order     = $attributes['order'];
	$is_linked = $attributes['terms_linked'];
	$delimiter = $attributes['terms_delimiter'];
	$prefix    = $attributes['terms_prefix'];
	$suffix    = $attributes['terms_suffix'];

	$delimiter_html = $delimiter
		? '<div class="jsf-listing-terms-delimiter">' . $delimiter . '</div>'
		: '';

	$args = [
		'orderby' => $order_by,
		'order'   => $order,
	];

	if ( ! $show_all && ! empty( $limit ) ) {
		$args['limit'] = intval( $limit );
	}

	$terms = Listing_Controller::instance()->helpers->utils->get_post_terms_list( $current_object, $from_tax, $args );

	if ( empty( $terms ) ) {
		return;
	}

	$term_items = [];

	foreach ( $terms as $term ) {
		$item = esc_html( $term->name );

		if ( $is_linked ) {
			$url = get_term_link( $term );

			if ( ! is_wp_error( $url ) ) {
				$item = sprintf( '<a href="%s" class="jsf-listing-terms-item">%s</a>', esc_url( $url ), $item );
			}
		} else {
			$item = sprintf( '<div class="jsf-listing-terms-item">%s</div>', $item );
		}

		$term_items[] = $item;
	}

	$wrapper_attrs = get_block_wrapper_attributes( [
		'class' => 'jsf-listing-terms-block'
	] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div ' . $wrapper_attrs . '>';
		echo '<div class="jsf-listing-terms">';

			if ( $prefix ) {
				echo '<div class="jsf-listing-terms-prefix">' . wp_kses_post( $prefix ) . '</div>';
			}

			echo wp_kses_post( implode( $delimiter_html, $term_items ) );

			if ( $suffix ) {
				echo '<div class="jsf-listing-terms-suffix">' . wp_kses_post( $suffix ) . '</div>';
			}

		echo '</div>';
	echo '</div>';
?>