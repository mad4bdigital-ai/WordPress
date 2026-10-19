<?php
use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

$listing_settings = apply_filters( 'jet-smart-filters/listing/block/settings', $attributes );

$listing_id = esc_html( $attributes['listing_id'] ?? '' );
$element_id = esc_html( $attributes['_element_id'] ?? '' );
$listing    = Listing_Controller::instance()->render->init_listing( $listing_id );

printf(
	'<div class="jsf-listing--blocks"%s>',
	! empty( $element_id ) ? ' id="' . esc_attr( $element_id ) . '"' : ''
);

$listing->render();

echo '</div>';
