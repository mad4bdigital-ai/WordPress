<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

class Listing_Image_Styles {

	public function __construct( $block ) {

		$block->start_section( [
			'id'           => 'image_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'General', 'jet-smart-filters' )
		] );

		$block->add_control( [
			'id'      => 'image_width',
			'label'   => __( 'Width', 'jet-smart-filters' ),
			'type'    => 'range',
			'units'   => array(
				array(
					'value'     => '%',
					'intervals' => array(
						'step' => 10,
						'min'  => 1,
						'max'  => 100,
					),
				),
			),
			'css_var' => array(
				'full_name' => '--jsf-li-width',
			),
		] );

		$block->add_control( [
			'id'      => 'image_max_width',
			'label'   => __( 'Max Width', 'jet-smart-filters' ),
			'type'    => 'range',
			'units'   => array(
				array(
					'value'     => 'px',
					'intervals' => array(
						'step' => 10,
						'min'  => 1,
						'max'  => 1000,
					),
				),
			),
			'css_var' => array(
				'full_name' => '--jsf-li-max-width',
			),
		] );

		$block->add_control( [
			'id'      => 'image_height',
			'label'   => __( 'Height', 'jet-smart-filters' ),
			'type'    => 'range',
			'units'   => array(
				array(
					'value'     => 'px',
					'intervals' => array(
						'step' => 10,
						'min'  => 1,
						'max'  => 1000,
					),
				),
			),
			'css_var' => array(
				'full_name' => '--jsf-li-height',
			),
		] );

		$block->add_control( [
			'id'      => 'image_margin',
			'label'   => __( 'Margin', 'jet-smart-filters' ),
			'type'    => 'dimensions',
			'css_var' => array(
				'full_name' => '--jsf-li-margin',
			),
		] );

		$block->add_control( [
			'id'      => 'image_padding',
			'label'   => __( 'Padding', 'jet-smart-filters' ),
			'type'    => 'dimensions',
			'css_var' => array(
				'full_name' => '--jsf-li-padding',
			),
		] );

		$block->add_control( [
			'id'      => 'image_border',
			'label'   => __( 'Border', 'jet-smart-filters' ),
			'type'    => 'border',
			'css_var' => array(
				'full_name' => '--jsf-li-border',
			),
		] );

		$block->add_control( [
			'id'      => 'image_bg_color',
			'label'   => __( 'Background Color', 'jet-smart-filters' ),
			'type'    => 'color-picker',
			'css_var' => array(
				'full_name' => '--jsf-li-bg',
			),
		] );

		$block->end_section();
	}
}