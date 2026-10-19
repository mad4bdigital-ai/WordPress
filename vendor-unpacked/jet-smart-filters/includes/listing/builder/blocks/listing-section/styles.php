<?php
namespace Jet_Smart_Filters\Listing\Builder\Blocks;

class Listing_Section_Styles {

	public function __construct( $block ) {

		$block->start_section( [
			'id'           => 'link_style_controls',
			'initial_open' => true,
			'title'        => esc_html__( 'General', 'jet-smart-filters' )
		] );

		$block->add_control( [
			'id' => 'section_min_height',
			'label' => __( 'Min Height', 'jet-smart-filters' ),
			'type' => 'range',
			'units' => [
				[
					'value' => 'px',
					'intervals' => [
						'min' => 0,
						'max' => 500,
						'step' => 1,
					]
				]
			],
			'css_selector' => array(
				'{{WRAPPER}}.jsf-listing-section' => 'min-height: {{VALUE}}',
			),
		] );

		$block->add_control( [
			'id' => 'section_gap',
			'label' => __( 'Gap', 'jet-smart-filters' ),
			'type' => 'range',
			'units' => [
				[
					'value' => 'px',
					'intervals' => [
						'min' => 0,
						'max' => 100,
						'step' => 1,
					]
				],
				[
					'value' => 'rem',
					'intervals' => [
						'min' => 0,
						'max' => 100,
						'step' => 1,
					]
				]
			],
			'css_selector' => array(
				'{{WRAPPER}}.jsf-listing-section > .block-editor-inner-blocks > .block-editor-block-list__layout' => 'gap: {{VALUE}}',
				'{{WRAPPER}}.jsf-listing-section' => 'gap: {{VALUE}}',
			),
		] );

		$block->add_control( [
			'id'           => 'section_bg_color',
			'label'        => __( 'Background Color', 'jet-smart-filters' ),
			'type'         => 'color-picker',
			'css_selector' => array(
				'{{WRAPPER}}.jsf-listing-section' => 'background-color: {{VALUE}}',
			),
		] );

		$block->add_control( [
			'id'           => 'section_margin',
			'label'        => __( 'Margin', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_selector' => array(
				'{{WRAPPER}}.jsf-listing-section' => 'margin: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}}',
			),
		] );

		$block->add_control( [
			'id'           => 'section_padding',
			'label'        => __( 'Padding', 'jet-smart-filters' ),
			'type'         => 'dimensions',
			'css_selector' => array(
				'{{WRAPPER}}.jsf-listing-section' => 'padding: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}}',
			),
		] );

		$block->add_control( [
				'id'           => 'section_border',
				'label'        => __( 'Border', 'jet-smart-filters' ),
				'type'         => 'border',
				'css_selector' => array(
					'{{WRAPPER}}.jsf-listing-section' => '{{VALUE}}',
				),
		] );

		$block->end_section();
	}
}