<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

class Listing_Link_Styles {

	public function __construct( $block ) {

		$block->start_section( [
			'id'           => 'link_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'General', 'jet-smart-filters' )
		] );

		$block->add_control( [
			'id'           => 'link_typography',
			'label'        => __( 'Typography', 'jet-smart-filters' ),
			'type'         => 'typography',
			'css_var'      => array(
				'full_name' => '--jsf-ll-typography',
			),
		] );

		$block->start_tabs( [
			'id'    => 'link_tabs',
		] );

		$block->start_tab( [
			'id'    => 'link_tabs_normal',
			'title' => esc_html__( 'Normal', 'jet-smart-filters' ),
		] );

		$block->add_control( [
			'id'           => 'link_color',
			'label'        => __( 'Text Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-ll-color',
			),
		] );

		$block->add_control( [
			'id'           => 'link_bg_color',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-ll-bg',
			),
		] );

		$block->end_tab();

		$block->start_tab( [
			'id'    => 'link_tabs_hover',
			'title' => esc_html__( 'Hover', 'jet-smart-filters' ),
		] );

		$block->add_control( [
			'id'           => 'link_color_hover',
			'label'        => __( 'Text Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-ll-color__hover',
			),
		] );

		$block->add_control( [
			'id'           => 'link_bg_color_hover',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-ll-bg__hover',
			),
		] );

		$block->end_tab();

		$block->end_tabs();

		$block->add_control( [
				'id'           => 'link_margin',
				'label'        => __( 'Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-ll-margin',
				),
		] );

		$block->add_control( [
			'id'           => 'link_padding',
			'label'        => __( 'Padding', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_var'      => array(
				'full_name' => '--jsf-ll-padding',
			),
		] );

		$block->add_control( [
				'id'      => 'link_border',
				'label'   => __( 'Border', 'jet-smart-filters' ),
				'type'    => 'border',
				'css_var' => array(
					'full_name' => '--jsf-ll-border',
				),
		] );

		$block->end_section();
	}
}