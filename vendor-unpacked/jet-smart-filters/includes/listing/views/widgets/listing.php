<?php

namespace Elementor;

use Jet_Smart_Filters\Listing\Controller as Listing_Controller;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jet_Smart_Filters_Listing_Widget extends Widget_Base {

	public function get_name() {

		return 'jet-smart-filters-listing';
	}

	public function get_title() {

		return __( 'JSF Listing', 'jet-smart-filters' );
	}

	public function get_icon() {

		return 'jet-smart-filters-icon-listing-builder';
	}

	public function get_help_url() {

		return jet_smart_filters()->widgets->prepare_help_url(
			'https://crocoblock.com/knowledge-base/articles/jetsmartfilters-how-to-use-ajax-pagination/',
			$this->get_name()
		);
	}

	public function get_categories() {

		return array( jet_smart_filters()->widgets->get_category() );
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_general',
			array(
				'label' => __( 'Content', 'jet-smart-filters' ),
			)
		);

		$this->add_control(
			'listing_id',
			array(
				'label'       => __( 'Listing', 'jet-smart-filters' ),
				'type'        => Controls_Manager::SELECT,
				'label_block' => true,
				'default'     => 0,
				'options'     => array( 0 => __( 'Select listing...', 'jet-smart-filters' ) ) + Listing_Controller::instance()->helpers->blocks_options->get_listings(),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {

		$settings = apply_filters( 'jet-smart-filters/listing/block/settings', $this->get_settings() );

		$listing_id = esc_html( $settings['listing_id'] ?? '' );
		$listing    = Listing_Controller::instance()->render->init_listing( $listing_id );

		$listing->render();
	}
}
