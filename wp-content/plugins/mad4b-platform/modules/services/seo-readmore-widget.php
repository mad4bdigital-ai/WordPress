<?php
/**
 * Plugin Name: SEO Read More Elementor Widget
 * Description: SEO-friendly expandable text widget for Elementor with style controls and smooth full-content expansion.
 * Version: 3.0.0
 * Author: OpenAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'elementor/widgets/register', function( $widgets_manager ) {

	if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
		return;
	}

	if ( ! class_exists( 'SEO_ReadMore_Widget' ) ) {

	class SEO_ReadMore_Widget extends \Elementor\Widget_Base {

		public function get_name() {
			return 'seo_readmore';
		}

		public function get_title() {
			return esc_html__( 'SEO Read More Text', 'seo-readmore-widget' );
		}

		public function get_icon() {
			return 'eicon-editor-paragraph';
		}

		public function get_categories() {
			return [ 'basic' ];
		}

		protected function register_controls() {

			$this->start_controls_section(
				'content_section',
				[
					'label' => esc_html__( 'Content', 'seo-readmore-widget' ),
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				]
			);

			$this->add_control(
				'text_content',
				[
					'label'   => esc_html__( 'Text', 'seo-readmore-widget' ),
					'type'    => \Elementor\Controls_Manager::WYSIWYG,
					'default' => 'Discover the best luxury Egypt tours and carefully crafted Egypt tour packages across Egypt and the Middle East. Explore destinations included in our luxury travel packages, from Egypt tour packages to unforgettable Egypt and Jordan tour packages across the Middle East.',
				]
			);

			$this->add_control(
				'visible_rows',
				[
					'label'       => esc_html__( 'Visible Rows', 'seo-readmore-widget' ),
					'type'        => \Elementor\Controls_Manager::NUMBER,
					'default'     => 2,
					'min'         => 1,
					'max'         => 10,
					'step'        => 1,
					'description' => esc_html__( 'Number of text lines visible before expanding.', 'seo-readmore-widget' ),
				]
			);

			$this->add_control(
				'read_more_text',
				[
					'label'   => esc_html__( 'Read More Label', 'seo-readmore-widget' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => esc_html__( 'Read More', 'seo-readmore-widget' ),
				]
			);

			$this->add_control(
				'read_less_text',
				[
					'label'   => esc_html__( 'Read Less Label', 'seo-readmore-widget' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => esc_html__( 'Read Less', 'seo-readmore-widget' ),
				]
			);

			$this->end_controls_section();

			$this->start_controls_section(
				'style_text_section',
				[
					'label' => esc_html__( 'Text', 'seo-readmore-widget' ),
					'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
				]
			);

			$this->add_control(
				'text_color',
				[
					'label'     => esc_html__( 'Text Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-content' => 'color: {{VALUE}};',
						'{{WRAPPER}} .seo-readmore-content *' => 'color: inherit;',
					],
				]
			);

			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => 'text_typography',
					'selector' => '{{WRAPPER}} .seo-readmore-content',
				]
			);

			$this->add_responsive_control(
				'text_align',
				[
					'label'     => esc_html__( 'Alignment', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::CHOOSE,
					'options'   => [
						'left' => [
							'title' => esc_html__( 'Left', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-left',
						],
						'center' => [
							'title' => esc_html__( 'Center', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-center',
						],
						'right' => [
							'title' => esc_html__( 'Right', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-right',
						],
						'justify' => [
							'title' => esc_html__( 'Justified', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-justify',
						],
					],
					'default'   => 'center',
					'selectors' => [
						'{{WRAPPER}} .seo-readmore' => 'text-align: {{VALUE}};',
					],
				]
			);

			$this->end_controls_section();

			$this->start_controls_section(
				'style_button_section',
				[
					'label' => esc_html__( 'Button', 'seo-readmore-widget' ),
					'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
				]
			);

			$this->add_responsive_control(
				'button_align',
				[
					'label'     => esc_html__( 'Button Alignment', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::CHOOSE,
					'options'   => [
						'left' => [
							'title' => esc_html__( 'Left', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-left',
						],
						'center' => [
							'title' => esc_html__( 'Center', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-center',
						],
						'right' => [
							'title' => esc_html__( 'Right', 'seo-readmore-widget' ),
							'icon'  => 'eicon-text-align-right',
						],
					],
					'default'   => 'center',
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn-wrap' => 'text-align: {{VALUE}};',
					],
				]
			);

			$this->start_controls_tabs( 'button_style_tabs' );

			$this->start_controls_tab(
				'button_normal_tab',
				[
					'label' => esc_html__( 'Normal', 'seo-readmore-widget' ),
				]
			);

			$this->add_control(
				'button_text_color',
				[
					'label'     => esc_html__( 'Text Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn' => 'color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'button_bg_color',
				[
					'label'     => esc_html__( 'Background Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn' => 'background-color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'button_border_color',
				[
					'label'     => esc_html__( 'Border Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn' => 'border-color: {{VALUE}};',
					],
				]
			);

			$this->end_controls_tab();

			$this->start_controls_tab(
				'button_hover_tab',
				[
					'label' => esc_html__( 'Hover', 'seo-readmore-widget' ),
				]
			);

			$this->add_control(
				'button_hover_text_color',
				[
					'label'     => esc_html__( 'Text Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn:hover' => 'color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'button_hover_bg_color',
				[
					'label'     => esc_html__( 'Background Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn:hover' => 'background-color: {{VALUE}};',
					],
				]
			);

			$this->add_control(
				'button_hover_border_color',
				[
					'label'     => esc_html__( 'Border Color', 'seo-readmore-widget' ),
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .seo-readmore-btn:hover' => 'border-color: {{VALUE}};',
					],
				]
			);

			$this->end_controls_tab();

			$this->end_controls_tabs();

			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => 'button_typography',
					'selector' => '{{WRAPPER}} .seo-readmore-btn',
				]
			);

			$this->add_responsive_control(
				'button_padding',
				[
					'label'      => esc_html__( 'Padding', 'seo-readmore-widget' ),
					'type'       => \Elementor\Controls_Manager::DIMENSIONS,
					'size_units' => [ 'px', 'em', 'rem', '%' ],
					'selectors'  => [
						'{{WRAPPER}} .seo-readmore-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					],
				]
			);

			$this->add_responsive_control(
				'button_border_radius',
				[
					'label'      => esc_html__( 'Border Radius', 'seo-readmore-widget' ),
					'type'       => \Elementor\Controls_Manager::DIMENSIONS,
					'size_units' => [ 'px', '%', 'em', 'rem' ],
					'selectors'  => [
						'{{WRAPPER}} .seo-readmore-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					],
				]
			);

			$this->add_responsive_control(
				'button_border_width',
				[
					'label'      => esc_html__( 'Border Width', 'seo-readmore-widget' ),
					'type'       => \Elementor\Controls_Manager::DIMENSIONS,
					'size_units' => [ 'px' ],
					'selectors'  => [
						'{{WRAPPER}} .seo-readmore-btn' => 'border-style: solid; border-width: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
					],
				]
			);

			$this->end_controls_section();
		}

		protected function render() {
			$settings       = $this->get_settings_for_display();
			$widget_id      = 'seo-readmore-' . $this->get_id();
			$visible_rows   = ! empty( $settings['visible_rows'] ) ? absint( $settings['visible_rows'] ) : 2;
			$read_more_text = ! empty( $settings['read_more_text'] ) ? $settings['read_more_text'] : 'Read More';
			$read_less_text = ! empty( $settings['read_less_text'] ) ? $settings['read_less_text'] : 'Read Less';
			$full_html      = wp_kses_post( (string) ( $settings['text_content'] ?? '' ) );
			// Dynamic Elementor tags can render an empty span while the provider is
			// held. Do not output an orphan Read More button in that case.
			$dynamic_source = (string) ( $settings['__dynamic__']['text_content'] ?? '' );
			$is_live_intro = false !== strpos( $dynamic_source, 'etg-filter-intro' )
				|| false !== strpos( $dynamic_source, 'dfsb-filter-intro' )
				|| false !== strpos( $full_html, 'data-etg-dfsb-token="intro"' )
				|| false !== strpos( $full_html, 'data-dfsb-token="intro"' );
			$has_text = '' !== trim( wp_strip_all_tags( $full_html ) );
		// The first canonical taxonomy archive render can use WordPress term
		// content immediately rather than showing a blank AJAX-bound widget.
		// Never infer this fallback for /jsf/ faceted routes.
		if ( $is_live_intro && ! $has_text && function_exists('is_tax') && is_tax() ) {
			$archive_term = get_queried_object();
			$archive_path = (string) wp_parse_url( (string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH );
			if ( $archive_term instanceof \WP_Term && false === strpos( $archive_path, '/jsf/' )
				&& ! empty( $archive_term->description ) ) {
				$full_html = wp_kses_post( $archive_term->description );
				$has_text = '' !== trim( wp_strip_all_tags( $full_html ) );
			}
		}

			$has_media = (bool) preg_match( '/<(?:img|picture|video|audio|iframe)\b/i', $full_html );
			if ( ! $has_text && ! $has_media && ! $is_live_intro ) {
				return;
			}
			// Only this dynamic source is bound; ordinary static copy keeps its
			// Elementor-controlled content and is never routed to DFSB.
			$intro_group = 'auto';
		if ( preg_match( '/settings="([^"]+)"/', $dynamic_source, $tag_settings_match ) ) {
			$tag_settings = json_decode( rawurldecode( html_entity_decode( $tag_settings_match[1], ENT_QUOTES, 'UTF-8' ) ), true );
			$tag_group = is_array( $tag_settings ) ? (string) ($tag_settings['live_group'] ?? '') : '';
			if ( preg_match( '~^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+$~D', $tag_group ) ) {
				$intro_group = $tag_group;
			}
		}
		$bind_intro = $is_live_intro && false === strpos( $full_html, 'data-etg-dfsb-token="intro"' )
				&& false === strpos( $full_html, 'data-dfsb-token="intro"' );
			?>
			<div
				class="seo-readmore"
				<?php if ( ! $has_text && ! $has_media ) : ?>hidden<?php endif; ?>
				data-visible-rows="<?php echo esc_attr( $visible_rows ); ?>"
				data-more-label="<?php echo esc_attr( $read_more_text ); ?>"
				data-less-label="<?php echo esc_attr( $read_less_text ); ?>"
			>
				<div class="seo-readmore-content-wrap">
					<div class="seo-readmore-content" id="<?php echo esc_attr( $widget_id ); ?>"<?php if ( $bind_intro ) : ?> data-etg-dfsb-token="intro" data-etg-dfsb-group="<?php echo esc_attr( $intro_group ); ?>"<?php endif; ?>>
						<?php echo $full_html; ?>
					</div>
				</div>

				<div class="seo-readmore-btn-wrap" hidden>
					<button
						class="seo-readmore-btn"
						type="button"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $widget_id ); ?>"
					>
						<?php echo esc_html( $read_more_text ); ?>
					</button>
				</div>
			</div>
			<?php
		}
	}

	}

	if ( class_exists( 'SEO_ReadMore_Widget' ) ) {
		$widgets_manager->register( new SEO_ReadMore_Widget() );
	}
} );

add_action( 'wp_footer', function() {
	if ( is_admin() ) {
		return;
	}
	?>
	<style>
		.seo-readmore {
			width: 100%;
		}
		.seo-readmore[hidden], .seo-readmore-btn-wrap[hidden] {
			display: none !important;
		}

		.seo-readmore-content-wrap {
			overflow: hidden;
			transition: max-height 0.35s ease;
		}

		.seo-readmore-content {
			display: block;
		}

		.seo-readmore-content > *:first-child {
			margin-top: 0;
		}

		.seo-readmore-content > *:last-child {
			margin-bottom: 0;
		}

		.seo-readmore-btn-wrap {
			margin-top: 12px;
		}

		.seo-readmore-btn {
			display: inline-block;
			cursor: pointer;
			font: inherit;
			background: transparent;
			border: 1px solid currentColor;
			padding: 8px 16px;
			transition: all 0.25s ease;
		}

		.seo-readmore-btn:focus-visible {
			outline: 2px solid currentColor;
			outline-offset: 3px;
		}
	</style>

	<script>
		document.addEventListener('DOMContentLoaded', function () {
			const widgets = document.querySelectorAll('.seo-readmore');

			widgets.forEach(function (widget) {
				const wrap = widget.querySelector('.seo-readmore-content-wrap');
				const content = widget.querySelector('.seo-readmore-content');
				const btn = widget.querySelector('.seo-readmore-btn');
				const btnWrap = widget.querySelector('.seo-readmore-btn-wrap');

				if (!wrap || !content || !btn || !btnWrap) {
					return;
				}

				const visibleRows = parseInt(widget.dataset.visibleRows || '2', 10);
				const readMoreLabel = widget.dataset.moreLabel || 'Read More';
				const readLessLabel = widget.dataset.lessLabel || 'Read Less';

				function getLineHeight(element) {
					const computed = window.getComputedStyle(element);
					let lineHeight = parseFloat(computed.lineHeight);

					if (isNaN(lineHeight)) {
						const fontSize = parseFloat(computed.fontSize) || 16;
						lineHeight = fontSize * 1.4;
					}

					return lineHeight;
				}

				function hasContent() {
					return !!(content.textContent || '').trim() || !!content.querySelector('img, picture, video, audio, iframe');
				}

				function setCollapsedState() {
					const lineHeight = getLineHeight(content.querySelector('p, li') || content);
					const collapsedHeight = Math.ceil(lineHeight * visibleRows);
					wrap.style.maxHeight = collapsedHeight + 'px';
					btn.textContent = readMoreLabel;
					btn.setAttribute('aria-expanded', 'false');
					widget.classList.remove('open');
				}

				function setExpandedState() {
					wrap.style.maxHeight = content.scrollHeight + 'px';
					btn.textContent = readLessLabel;
					btn.setAttribute('aria-expanded', 'true');
					widget.classList.add('open');
				}

				function initialize() {
					// Preserve the user-expanded state when DFSB refreshes the same
					// content asynchronously, including during mobile scroll/lazy load.
					const wasExpanded = widget.classList.contains('open');
					// Check current contents on every update; do not reveal any button
					// or empty wrapper while an async dynamic tag has no material.
					if (!hasContent()) {
						widget.hidden = true;
						btnWrap.hidden = true;
						widget.classList.remove('open');
						wrap.style.maxHeight = 'none';
						return;
					}
					widget.hidden = false;
					btnWrap.hidden = true;
					wrap.style.maxHeight = 'none';
					const fullHeight = content.scrollHeight;
					const lineHeight = getLineHeight(content.querySelector('p, li') || content);
					const collapsedHeight = Math.ceil(lineHeight * visibleRows);

					if (fullHeight <= collapsedHeight + 4) {
						wrap.style.maxHeight = 'none';
						btnWrap.hidden = true;
						widget.classList.remove('open');
						return;
					}

					btnWrap.hidden = false;
					if (wasExpanded) { setExpandedState(); }
					else { setCollapsedState(); }
				}

				btn.addEventListener('click', function () {
					if (widget.classList.contains('open')) {
						setCollapsedState();
					} else {
						setExpandedState();
					}
				});

				window.addEventListener('resize', function () {
					if (widget.classList.contains('open')) {
						wrap.style.maxHeight = content.scrollHeight + 'px';
					} else {
						setCollapsedState();
					}
				});

				// The DFSB async bridge mutates content after DOMContentLoaded.
				// Reevaluate only this instance and keep its button/content synced.
				document.addEventListener('dfsb/ajax-presentation-updated', initialize);
				document.addEventListener('dfsb/ajax-presentation-reset', initialize);
				if (typeof MutationObserver === 'function') {
					const observer = new MutationObserver(initialize);
					observer.observe(content, {childList: true, characterData: true, subtree: true});
				}
				initialize();
			});
		});
	</script>
	<?php
} );