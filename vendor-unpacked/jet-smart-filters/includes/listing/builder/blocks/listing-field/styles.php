<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

class Listing_Field_Styles {

	public function __construct( $block ) {

		$block->start_section( [
			'id'           => 'field_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'General', 'jet-smart-filters' )
		] );

		$block->add_control( [
			'id'           => 'field_color',
			'label'        => __( 'Text Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lf-color',
			),
		] );

		$block->add_control( [
			'id'           => 'field_typography',
			'label'        => __( 'Typography', 'jet-smart-filters' ),
			'type'         => 'typography',
			'css_var'      => array(
				'full_name' => '--jsf-lf-typography',
			),
		] );

		$block->add_control( [
				'id'           => 'field_margin',
				'label'        => __( 'Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-lf-margin',
				),
		] );

		$block->add_control( [
			'id'           => 'field_padding',
			'label'        => __( 'Padding', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_var'      => array(
				'full_name' => '--jsf-lf-padding',
			),
		] );

		$block->add_control( [
				'id'      => 'field_border',
				'label'   => __( 'Border', 'jet-smart-filters' ),
				'type'    => 'border',
				'css_var' => array(
					'full_name' => '--jsf-lf-border',
				),
		] );

		$block->add_control( [
			'id'           => 'field_bg_color',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lf-bg',
			),
		] );

		$block->end_section();
	}
}