<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

class Listing_Terms_Styles {

	public function __construct( $block ) {

		$block->start_section( [
			'id'           => 'terms_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'General', 'jet-smart-filters' )
		] );

		$block->add_control( [
			'id'           => 'terms_typography',
			'label'        => __( 'Typography', 'jet-smart-filters' ),
			'type'         => 'typography',
			'css_var'      => array(
				'full_name' => '--jsf-lt-typography',
			),
		] );

		$block->add_control( [
				'id'           => 'terms_margin',
				'label'        => __( 'Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-lt-margin',
				),
		] );

		$block->add_control( [
			'id'           => 'terms_padding',
			'label'        => __( 'Padding', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_var'      => array(
				'full_name' => '--jsf-lt-padding',
			),
		] );

		$block->add_control( [
				'id'      => 'terms_border',
				'label'   => __( 'Border', 'jet-smart-filters' ),
				'type'    => 'border',
				'css_var' => array(
					'full_name' => '--jsf-lt-border',
				),
		] );

		$block->add_control( [
			'id'      => 'terms_bg_color',
			'label'   => __( 'Background Color', 'jet-smart-filters' ),
			'type'    => 'color-picker',
			'css_var' => array(
				'full_name' => '--jsf-lt-bg',
			),
		] );

		$block->end_section();

		$block->start_section( [
			'id'           => 'terms_item_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'Item', 'jet-smart-filters' )
		] );

		$block->start_tabs( [
			'id'    => 'terms_item_tabs',
		] );

		$block->start_tab( [
			'id'    => 'terms_item_tabs_normal',
			'title' => esc_html__( 'Normal', 'jet-smart-filters' ),
		] );

		$block->add_control( [
			'id'           => 'terms_item_color',
			'label'        => __( 'Text Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lti-color',
			),
		] );

		$block->add_control( [
			'id'           => 'terms_item_bg_color',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lti-bg',
			),
		] );

		$block->end_tab();

		$block->start_tab( [
			'id'    => 'terms_item_tabs_hover',
			'title' => esc_html__( 'Hover', 'jet-smart-filters' ),
		] );

		$block->add_control( [
			'id'           => 'terms_item_color_hover',
			'label'        => __( 'Text Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lti-color__hover',
			),
		] );

		$block->add_control( [
			'id'           => 'terms_item_bg_color_hover',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lti-bg__hover',
			),
		] );

		$block->add_control( [
			'id'           => 'terms_item_border_color_hover',
			'label'        => __( 'Border Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_var'      => array(
				'full_name' => '--jsf-lti-border__hover',
			),
		] );

		$block->end_tab();

		$block->end_tabs();

		$block->add_control( [
				'id'           => 'terms_item_margin',
				'label'        => __( 'Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-lti-margin',
				),
		] );

		$block->add_control( [
			'id'           => 'terms_item_padding',
			'label'        => __( 'Padding', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_var'      => array(
				'full_name' => '--jsf-lti-padding',
			),
		] );

		$block->add_control( [
				'id'      => 'terms_item_border',
				'label'   => __( 'Border', 'jet-smart-filters' ),
				'type'    => 'border',
				'css_var' => array(
					'full_name' => '--jsf-lti-border',
				),
		] );

		$block->end_section();

		$block->start_section( [
			'id'           => 'terms_decorators_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'Decorators', 'jet-smart-filters' )
		] );

		$block->add_control( [
				'id'           => 'terms_prefix_margin',
				'label'        => __( 'Text Before Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-lt-prefix-margin',
				),
		] );

		$block->add_control( [
				'id'           => 'terms_suffix_margin',
				'label'        => __( 'Text After Margin', 'jet-smart-filters' ),
				'type'         => 'dimensions',
				'css_var'      => array(
					'full_name' => '--jsf-lt-suffix-margin',
				),
		] );

		$block->end_section();
	}
}