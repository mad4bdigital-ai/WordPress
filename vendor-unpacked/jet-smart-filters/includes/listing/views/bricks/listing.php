<?php

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Jet_Smart_Filters_Pagination_Widget extends \Jet_Engine\Bricks_Views\Elements\Base {
	// Element properties
	public $category = 'jetsmartfilters';
	public $name = 'jet-smart-filters-listing';
	public $icon = 'jet-smart-filters-icon-listing-builder';

	// Return localised element label
	public function get_label() {
		return esc_html__( 'JSF Listing', 'jet-smart-filters' );
	}

	// Set builder control groups
	public function set_control_groups() {

		$this->register_jet_control_group(
			'section_general',
			[
				'title' => esc_html__( 'General', 'jet-smart-filters' ),
				'tab'   => 'content',
			]
		);
	}

	// Set builder controls
	public function set_controls() {

		$this->start_jet_control_group( 'section_general' );

		$this->register_jet_control(
			'listing_id',
			[
				'tab'        => 'content',
				'label'      => esc_html__( 'Listing', 'jet-smart-filters' ),
				'type'       => 'select',
				'options'    => Listing_Controller::instance()->helpers->blocks_options->get_listings(),
				'searchable' => true,
			]
		);

		$this->end_jet_control_group();
	}

	// Render element HTML
	public function render() {

		$widget_settings = $this->get_jet_settings();

		if ( isset( $widget_settings['_cssId'] ) ) {
			$widget_settings['_element_id'] = $widget_settings['_cssId'];
			unset( $widget_settings['_cssId'] );
		}

		$settings = apply_filters( 'jet-smart-filters/listing/block/settings', $widget_settings );

		$listing_id = esc_html( $settings['listing_id'] ?? '' );
		$listing    = Listing_Controller::instance()->render->init_listing( $listing_id );

		printf(
			'<div %s>',
			$this->render_attributes( '_root' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		$listing->render();

		echo '</div>';
	}
}