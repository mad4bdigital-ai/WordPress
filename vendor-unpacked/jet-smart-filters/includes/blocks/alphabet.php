<?php
/**
 * Alphabet Filter
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Block_Alphabet' ) ) {
	/**
	 * Define Jet_Smart_Filters_Block_Alphabet class
	 */
	class Jet_Smart_Filters_Block_Alphabet extends Jet_Smart_Filters_Block_Base {
		/**
		 * Returns block name
		 */
		public function get_name() {

			return 'alphabet';
		}

		public function set_css_scheme() {

			$this->css_scheme = apply_filters(
				'jet-smart-filters/widgets/alphabet/css-scheme',
				array(
					'list-wrapper'         => '.jet-alphabet-list-wrapper',
					'list-fieldset'        => '.jet-alphabet-list-wrapper > fieldset',
					'list-item'            => '.jet-alphabet-list__row',
					'item'                 => '.jet-alphabet-list__item',
					'button'               => '.jet-alphabet-list__button',
					'filters-label'        => '.jet-filter-label',
					'apply-filters'        => '.apply-filters',
					'apply-filters-button' => '.apply-filters__button'
				)
			);
		}

		public function add_style_manager_options() {

			$this->controls_manager->start_section(
				'style_controls',
				[
					'id'          => 'items_style',
					'initialOpen' => false,
					'title'       => esc_html__( 'Items', 'jet-smart-filters' )
				]
			);

			$this->controls_manager->add_control([
				'id'    => 'items_space_between',
				'type'  => 'range',
				'label' => esc_html__( 'Space Between', 'jet-smart-filters' ),
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['list-fieldset'] => 'gap: {{VALUE}}{{UNIT}};'
				],
				'attributes' => [
					'default' => [
						'value' => 10,
						'unit'  => 'px'
					]
				],
				'units' => [
					[
						'value'     => 'px',
						'intervals' => [
							'step' => 1,
							'min'  => 0,
							'max'  => 50
						]
					]
				]
			]);

			$this->controls_manager->add_control([
				'id'        => 'items_alignment',
				'type'      => 'choose',
				'label'     => esc_html__( 'Alignment', 'jet-smart-filters' ),
				'separator' => 'before',
				'options'   =>[
					'flex-start' => array(
						'title' => esc_html__( 'Left', 'jet-smart-filters' ),
						'icon'  => 'dashicons-editor-alignleft'
					),
					'center' => array(
						'title' => esc_html__( 'Center', 'jet-smart-filters' ),
						'icon'  => 'dashicons-editor-aligncenter'
					),
					'flex-end' => array(
						'title' => esc_html__( 'Right', 'jet-smart-filters' ),
						'icon'  => 'dashicons-editor-alignright'
					),
				],
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['list-fieldset'] => 'justify-content: {{VALUE}};'
				],
				'attributes' => [
					'default' => [
						'value' => 'left'
					]
				]
			]);

			$this->controls_manager->end_section();

			$this->controls_manager->start_section(
				'style_controls',
				[
					'id'          => 'item_style',
					'initialOpen' => false,
					'title'       => esc_html__( 'Item', 'jet-smart-filters' )
				]
			);

			$this->controls_manager->add_control([
				'id'           => 'item_min_width',
				'type'         => 'range',
				'label'        => esc_html__( 'Minimal Width', 'jet-smart-filters' ),
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'min-width: {{VALUE}}{{UNIT}};'
				],
				'attributes' => [
					'default' => [
						'value' => 10,
						'unit'  => 'px'
					]
				],
				'units' => [
					[
						'value'     => 'px',
						'intervals' => [
							'step' => 1,
							'min'  => 0,
							'max'  => 500
						]
					]
				]
			]);

			$this->controls_manager->add_control([
				'id'           => 'item_typography',
				'type'         => 'typography',
				'separator'    => 'before',
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'font-family: {{FAMILY}}; font-weight: {{WEIGHT}}; text-transform: {{TRANSFORM}}; font-style: {{STYLE}}; text-decoration: {{DECORATION}}; line-height: {{LINEHEIGHT}}{{LH_UNIT}}; letter-spacing: {{LETTERSPACING}}{{LS_UNIT}}; font-size: {{SIZE}}{{S_UNIT}};'
				]
			]);

			$this->controls_manager->start_tabs(
				'style_controls',
				[
					'id'        => 'item_style_tabs',
					'separator' => 'both'
				]
			);

			$this->controls_manager->start_tab(
				'style_controls',
				[
					'id'    => 'item_normal_styles',
					'title' => esc_html__( 'Normal', 'jet-smart-filters' )
				]
			);

			$this->controls_manager->add_control([
				'id'           => 'item_normal_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Normal Color', 'jet-smart-filters' ),
				'separator'    => 'after',
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'           => 'item_normal_background_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Normal Background Color', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'background-color: {{VALUE}}'
				),
				'attributes' => [
					'default' => [
						'value' => ''
					]
				]
			]);

			$this->controls_manager->end_tab();

			$this->controls_manager->start_tab(
				'style_controls',
				[
					'id'    => 'item_checked_styles',
					'title' => esc_html__( 'Checked', 'jet-smart-filters' )
				]
			);

			$this->controls_manager->add_control([
				'id'         => 'item_checked_color',
				'type'       => 'color-picker',
				'label'      => esc_html__( 'Checked Color', 'jet-smart-filters' ),
				'separator'  => 'after',
				'attributes' => [
					'default' => [
						'value' => ''
					]
				],
				'css_selector' => array(
					'{{WRAPPER}} .jet-alphabet-list__input:checked ~ ' . $this->css_scheme['button'] => 'color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'         => 'item_checked_background_color',
				'type'       => 'color-picker',
				'label'      => esc_html__( 'Checked Background Color', 'jet-smart-filters' ),
				'separator'  => 'after',
				'attributes' => [
					'default' => [
						'value' => ''
					]
				],
				'css_selector' => array(
					'{{WRAPPER}} .jet-alphabet-list__input:checked ~ ' . $this->css_scheme['button'] => 'background-color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'         => 'item_checked_border_color',
				'type'       => 'color-picker',
				'label'      => esc_html__( 'Checked Border Color', 'jet-smart-filters' ),
				'attributes' => [
					'default' => [
						'value' => ''
					]
				],
				'css_selector' => array(
					'{{WRAPPER}} .jet-alphabet-list__input:checked ~ ' . $this->css_scheme['button'] => 'border-color: {{VALUE}}'
				)
			]);

			$this->controls_manager->end_tab();

			$this->controls_manager->end_tabs();

			$this->controls_manager->add_control([
				'id'           => 'item_padding',
				'type'         => 'dimensions',
				'label'        => esc_html__( 'Padding', 'jet-smart-filters' ),
				'units'        => array( 'px', '%' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'padding: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}};'
				),
				'separator'    => 'after'
			]);

			$this->controls_manager->add_control([
				'id'           => 'item_border',
				'type'         => 'border',
				'label'        => esc_html__( 'Border', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['button'] => 'border-style: {{STYLE}}; border-width: {{WIDTH}}; border-radius: {{RADIUS}}; border-color: {{COLOR}}'
				)
			]);

			$this->controls_manager->end_section();

			$this->controls_manager->start_section(
				'style_controls',
				[
					'id'          => 'label_style',
					'initialOpen' => false,
					'title'       => esc_html__( 'Label', 'jet-smart-filters' ),
					'condition'   => [
						'show_label' => true
					]
				]
			);

			$this->controls_manager->add_control([
				'id'           => 'label_typography',
				'type'         => 'typography',
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['filters-label'] => 'font-family: {{FAMILY}}; font-weight: {{WEIGHT}}; text-transform: {{TRANSFORM}}; font-style: {{STYLE}}; text-decoration: {{DECORATION}}; line-height: {{LINEHEIGHT}}{{LH_UNIT}}; letter-spacing: {{LETTERSPACING}}{{LS_UNIT}}; font-size: {{SIZE}}{{S_UNIT}};'
				]
			]);

			$this->controls_manager->add_control([
				'id'        => 'label_alignment',
				'type'      => 'choose',
				'label'     => esc_html__( 'Alignment', 'jet-smart-filters' ),
				'separator' => 'both',
				'options'   =>[
					'left' => [
						'shortcut' => esc_html__( 'Left', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-alignleft'
					],
					'center' => [
						'shortcut' => esc_html__( 'Center', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-aligncenter'
					],
					'right' => [
						'shortcut' => esc_html__( 'Right', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-alignright'
					],
				],
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['filters-label']  => 'text-align: {{VALUE}};'
				],
				'attributes' => [
					'default' => [
						'value' => 'left'
					]
				]
			]);

			$this->controls_manager->add_control([
				'id'         => 'label_color',
				'type'       => 'color-picker',
				'label'      => esc_html__( 'Color', 'jet-smart-filters' ),
				'attributes' => [
					'default' => ''
				],
				'css_selector' => array(
					'{{WRAPPER}}  ' . $this->css_scheme['filters-label'] => 'color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'           => 'label_border',
				'type'         => 'border',
				'label'        => esc_html__( 'Border', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['filters-label'] =>'border-style: {{STYLE}}; border-width: {{WIDTH}}; border-radius: {{RADIUS}}; border-color: {{COLOR}}'
				),
				'separator'    => 'before'
			]);

			$this->controls_manager->add_control([
				'id'           => 'label_padding',
				'type'         => 'dimensions',
				'label'        => esc_html__( 'Padding', 'jet-smart-filters' ),
				'units'        => array( 'px', '%' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['filters-label'] => 'padding: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}};'
				),
				'separator'    => 'before'
			]);

			$this->controls_manager->add_control([
				'id'           => 'label_margin',
				'type'         => 'dimensions',
				'label'        => esc_html__( 'Margin', 'jet-smart-filters' ),
				'units'        => array( 'px', '%' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['filters-label'] => 'margin: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}};'
				),
				'separator'    => 'before'
			]);

			$this->controls_manager->end_section();

			$this->controls_manager->start_section(
				'style_controls',
				[
					'id'          => 'button_style',
					'initialOpen' => false,
					'title'       => esc_html__( 'Button', 'jet-smart-filters' ),
					'condition'   => [
						'apply_button' => true
					]
				]
			);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_typography',
				'type'         => 'typography',
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'font-family: {{FAMILY}}; font-weight: {{WEIGHT}}; text-transform: {{TRANSFORM}}; font-style: {{STYLE}}; text-decoration: {{DECORATION}}; line-height: {{LINEHEIGHT}}{{LH_UNIT}}; letter-spacing: {{LETTERSPACING}}{{LS_UNIT}}; font-size: {{SIZE}}{{S_UNIT}};'
				]
			]);

			$this->controls_manager->start_tabs(
				'style_controls',
				[
					'id'        => 'filter_apply_button_style_tabs',
					'separator' => 'both'
				]
			);

			$this->controls_manager->start_tab(
				'style_controls',
				[
					'id'    => 'filter_apply_button_normal_styles',
					'title' => esc_html__( 'Normal', 'jet-smart-filters' )
				]
			);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_normal_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Text Color', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_normal_background_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Background Color', 'jet-smart-filters' ),
				'separator'    => 'before',
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'background-color: {{VALUE}}'
				),
				'attributes' => [
					'default' => [
						'value' => ''
					]
				]
			]);

			$this->controls_manager->end_tab();

			$this->controls_manager->start_tab(
				'style_controls',
				[
					'id'    => 'filter_apply_button_hover_styles',
					'title' => esc_html__( 'Hover', 'jet-smart-filters' )
				]
			);
			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_hover_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Text Color', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] . ':hover' => 'color: {{VALUE}}'
				)
			]);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_hover_background_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Background Color', 'jet-smart-filters' ),
				'separator'    => 'before',
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] . ':hover' => 'background-color: {{VALUE}}'
				),
				'attributes' => [
					'default' => [
						'value' => ''
					]
				]
			]);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_hover_border_color',
				'type'         => 'color-picker',
				'label'        => esc_html__( 'Border Color', 'jet-smart-filters' ),
				'separator'    => 'before',
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] . ':hover' => 'border-color: {{VALUE}}'
				)
			]);

			$this->controls_manager->end_tab();

			$this->controls_manager->end_tabs();

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_border',
				'type'         => 'border',
				'label'        => esc_html__( 'Border', 'jet-smart-filters' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] =>'border-style: {{STYLE}}; border-width: {{WIDTH}}; border-radius: {{RADIUS}}; border-color: {{COLOR}}',
				),
			]);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_padding',
				'type'         => 'dimensions',
				'label'        => esc_html__( 'Padding', 'jet-smart-filters' ),
				'units'        => array( 'px', '%' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'padding: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}};'
				),
				'separator'    => 'before'
			]);

			$this->controls_manager->add_control([
				'id'           => 'filter_apply_button_margin',
				'type'         => 'dimensions',
				'label'        => esc_html__( 'Margin', 'jet-smart-filters' ),
				'units'        => array( 'px', '%' ),
				'css_selector' => array(
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'margin: {{TOP}} {{RIGHT}} {{BOTTOM}} {{LEFT}};'
				),
				'separator'    => 'before'
			]);

			$this->controls_manager->add_control([
				'id'        => 'filter_apply_button_alignment',
				'type'      => 'choose',
				'label'     => esc_html__( 'Alignment', 'jet-smart-filters' ),
				'separator' => 'before',
				'options'   =>[
					'flex-start' => [
						'shortcut' => esc_html__( 'Left', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-alignleft'
					],
					'center' => [
						'shortcut' => esc_html__( 'Center', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-aligncenter'
					],
					'flex-end' => [
						'shortcut' => esc_html__( 'Right', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-alignright'
					],
					'stretch' => [
						'shortcut' => esc_html__( 'Stretch', 'jet-smart-filters' ),
						'icon'     => 'dashicons-editor-justify'
					],
				],
				'css_selector' => [
					'{{WRAPPER}} ' . $this->css_scheme['apply-filters-button'] => 'align-self: {{VALUE}};'
				],
				'attributes' => [
					'default' => [
						'value' => 'flex-start'
					]
				]
			]);

			$this->controls_manager->end_section();
		}
	}
}
