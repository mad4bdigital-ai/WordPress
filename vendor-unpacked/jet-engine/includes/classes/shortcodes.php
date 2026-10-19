<?php
/**
 * Register and hadle jet-engine related shortcodes
 */

use Jet_Engine\Modules\Dynamic_Visibility;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Jet_Engine_Shortcodes {

	private $controls = array();

	public function __construct() {
		add_shortcode( 'jet_engine', array( $this, 'base_shortcode' ) );
		add_shortcode( 'jet_engine_data', array( $this, 'data_shortcode' ) );
	}

	/**
	 * Handle shortcode
	 *
	 * @param  array  $atts [description]
	 * @return [type]       [description]
	 */
	public function data_shortcode( $atts = array() ) {

		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$sanitize = isset( $atts['sanitize'] ) ? $atts['sanitize'] : null;
		unset( $atts['sanitize'] );

		$atts = array_merge( array(
			'dynamic_field_post_meta' => '',
		), $atts );

		foreach ( $atts as $key => $value ) {
			// Ensure boolean values correctly pased
			if ( in_array( $value, array( 'true', 'false', 'yes', 'no' ) ) ) {
				$atts[ $key ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			}
		}

		$add_wrap = false;

		// Convert filter callbacks string into array
		if ( ! empty( $atts['filter_callbacks'] ) ) {
			$filter_callbacks = str_replace( '&amp;', '&', $atts['filter_callbacks'] );
			$filter_callbacks = rtrim( ltrim( $filter_callbacks, '{' ), '}' );
			$filter_callbacks = explode( '},{', $filter_callbacks );
			
			$atts['filter_callbacks'] = array_map( function( $row ) {
				parse_str( $row, $parsed_row );
				return $parsed_row;
			}, $filter_callbacks );

			foreach ( $atts['filter_callbacks'] as $cb_args ) {

				if ( empty( $cb_args['filter_callback'] ) ) {
					continue;
				}

				if ( 'jet_engine_img_gallery_slider' === $cb_args['filter_callback'] ) {
					$add_wrap = true;
				}
			}

		}
		
		$renderer = jet_engine()->listings->get_render_instance( 'dynamic-field', $atts );

		if ( ! $renderer ) {
			return '';
		}

		ob_start();
		$renderer->render_field_content( $renderer->get_settings() );
		$content = ob_get_clean();

		if ( $add_wrap ) {
			$content = sprintf( '<div data-is-block="jet-engine/dynamic-field">%s</div>', $content );
		}

		$settings = $renderer->get_settings();
		$default  = ! empty( $settings['dynamic_field_source'] ) && 'options_page' === $settings['dynamic_field_source']
			? 'wp_kses_post'
			: 'raw';

		return $this->sanitize_shortcode_result( $content, $this->get_shortcode_sanitize( $sanitize, $default ) );

	}

	/**
	 * Get sanitizers allowed for shortcode output.
	 *
	 * Keep this closed list separate from options-page field sanitization. Shortcode
	 * attributes are public input and must never select an arbitrary PHP callback.
	 *
	 * @return array
	 */
	private function get_shortcode_sanitizers() {
		return array(
			'raw'                 => false,
			'wp_kses_post'        => 'wp_kses_post',
			'esc_html'            => 'esc_html',
			'esc_attr'            => 'esc_attr',
			'esc_url'             => 'esc_url',
			'sanitize_text_field' => 'sanitize_text_field',
			'sanitize_email'      => 'sanitize_email',
			'sanitize_key'        => 'sanitize_key',
			'sanitize_title'      => 'sanitize_title',
			'absint'              => 'absint',
			'floatval'            => 'floatval',
		);
	}

	/**
	 * Get sanitize options for the shortcode generator.
	 *
	 * @return array
	 */
	public function get_shortcode_sanitize_options() {
		$options = array();

		foreach ( array_keys( $this->get_shortcode_sanitizers() ) as $sanitizer ) {
			$options[] = array(
				'value' => $sanitizer,
				'label' => $sanitizer,
			);
		}

		return $options;
	}

	/**
	 * Resolve a shortcode sanitizer from the closed allowlist.
	 *
	 * @param mixed  $sanitize Requested sanitizer.
	 * @param string $default  Effective component/source default.
	 * @return string
	 */
	private function get_shortcode_sanitize( $sanitize, $default = 'raw' ) {
		$sanitizers = $this->get_shortcode_sanitizers();

		if ( is_string( $sanitize ) && isset( $sanitizers[ $sanitize ] ) ) {
			return $sanitize;
		}

		return $default;
	}

	/**
	 * Sanitize shortcode output without passing arrays to scalar callbacks.
	 *
	 * @param mixed  $result   Shortcode result.
	 * @param string $sanitize Allowlisted sanitizer.
	 * @return mixed
	 */
	private function sanitize_shortcode_result( $result, $sanitize ) {
		if ( is_array( $result ) ) {
			foreach ( $result as $key => $value ) {
				$result[ $key ] = $this->sanitize_shortcode_result( $value, $sanitize );
			}

			return $result;
		}

		if ( ! is_string( $result ) || 'raw' === $sanitize ) {
			return $result;
		}

		$sanitizers = $this->get_shortcode_sanitizers();
		$callback   = isset( $sanitizers[ $sanitize ] ) ? $sanitizers[ $sanitize ] : false;

		return $callback ? $callback( $result ) : $result;
	}

	/**
	 * Convert shortcode arrays to the legacy comma-separated result safely.
	 *
	 * @param mixed $value Result value.
	 * @return string
	 */
	private function stringify_shortcode_result( $value ) {
		if ( is_array( $value ) ) {
			$items = array();

			foreach ( $value as $item ) {
				$items[] = $this->stringify_shortcode_result( $item );
			}

			return implode( ', ', $items );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	public function catch_source_controls() {
		do_action( 'jet-engine/listings/dynamic-field/source-controls', $this );
		return $this->controls;
	}

	public function add_responsive_control( $name, $data ) {
		$this->add_control( $name, $data );
		return $this->controls;
	}

	public function add_control( $name, $data = array() ) {
		$this->controls[] = array(
			'name' => $name,
			'data' => $data,
		);
	}

	/**
	 * Sanitize user-controlled shortcode output at the render boundary.
	 *
	 * @param mixed $result Shortcode result.
	 * @return mixed
	 */
	private function sanitize_meta_field_result( $result ) {

		if ( is_string( $result ) ) {
			return wp_kses_post( $result );
		}

		if ( is_array( $result ) ) {
			return array_map( function( $item ) {
				return is_string( $item ) ? wp_kses_post( $item ) : $item;
			}, $result );
		}

		return $result;
	}

	/**
	 * Get shortcode types isn't allowed to create with generator.
	 *
	 * @return array
	 */
	public function get_shortcode_types() {
		return apply_filters( 'jet-engine/shortcodes/generator-shortcode-types', [
			'jet_engine_data' => 'JetEngine Data',
		] );
	}

	public function get_generator_config() {

		$sources = jet_engine()->listings->data->get_field_sources();

		// remove legacy Relations Hierarchy source for shortcode
		if ( isset( $sources['relations_hierarchy'] ) ) {
			unset( $sources['relations_hierarchy'] );
		}

		$sources = \Jet_Engine_Tools::prepare_list_for_js( $sources, ARRAY_A );
		$shortcode_types = [];

		foreach ( $this->get_shortcode_types() as $shortcode => $shortcode_name ) {
			$shortcode_types[] = [
				'value' => $shortcode,
				'label' => $shortcode_name,
			];
		}

		return apply_filters( 'jet-engine/shortcodes/generator-config', [
			'shortcode_types' => $shortcode_types,
			'tag_type' => [
				[
					'value' => 'enclosed',
					'label' => 'Enclosing (e.g. [shortcode attrs]content...[/shortcode])'
				],
				[
					'value' => 'selfclosed',
					'label' => 'Selfclosing (e.g. [shortcode attrs])'
				],
			],
			'sources'       => $sources,
			'object_fields' => jet_engine()->listings->data->get_object_fields( 'blocks', 'options' ),
			'source_args'   => $this->catch_source_controls(),
			'meta_fields'   => jet_engine()->meta_boxes->get_fields_for_select( 'all', 'blocks' ),
			'options_pages' => jet_engine()->options_pages->get_options_for_select( 'all', 'blocks' ),
			'callbacks'     => \Jet_Engine_Tools::prepare_list_for_js( 
				jet_engine()->listings->get_allowed_callbacks(), 
				ARRAY_A 
			),
			'cb_args'       => jet_engine()->listings->get_callbacks_args(),
			'context_list'  => jet_engine()->listings->allowed_context_list( 'blocks' ),
			'sanitize_values' => $this->get_shortcode_sanitize_options(),
			'labels'        => $this->get_controls_labels(),
		] );
	}

	public function get_controls_labels() {
		return array(
			'dynamic_field_source' => array(
				'label' => __( 'Source', 'jet-engine' ),
			),
			'sanitize' => array(
				'label' => __( 'Sanitize value', 'jet-engine' ),
			),
			'dynamic_field_post_object' => array(
				'label' => __( 'Object Field', 'jet-engine' ),
			),
			'dynamic_field_wp_excerpt' => array(
				'label' => __( 'Automatically generated excerpt', 'jet-engine' ),
			),
			'dynamic_excerpt_more' => array(
				'label' => __( 'More string', 'jet-engine' ),
			),
			'dynamic_excerpt_length' => array(
				'label' => __( 'Custom length', 'jet-engine' ),
			),
			'dynamic_field_post_meta' => array(
				'label' => __( 'Meta Field', 'jet-engine' ),
			),
			'dynamic_field_option' => array(
				'label' => __( 'Option', 'jet-engine' ),
			),
			'dynamic_field_var_name' => array(
				'label' => __( 'Variable Name', 'jet-engine' ),
			),
			'dynamic_field_post_meta_custom' => array(
				'label'       => __( 'Custom Object Field / Meta field / Repeater key', 'jet-engine' ),
				'description' => __( 'Note: this field will override Object Field / Meta Field value', 'jet-engine' ),
			),
			'dynamic_field_filter' => array(
				'label' => esc_html__( 'Filter Field Output', 'jet-engine' ),
			),
			'hide_if_empty' => array(
				'label' => esc_html__( 'Hide if Empty', 'jet-engine' ),
			),
			'field_fallback' => array(
				'label' => esc_html__( 'Fallback Value', 'jet-engine' ),
			),
			'filter_callback' => array(
				'label' => __( 'Callback', 'jet-engine' ),
			),
			'dynamic_field_custom' => array(
				'label' => esc_html__( 'Customize field output', 'jet-engine' ),
			),
			'dynamic_field_format' => array(
				'label'       => __( 'Field format', 'jet-engine' ),
				'description' => __( '%s will be replaced with field value. If you need use plain % sign, replace it with %% (for example for JetEngine macros wrappers)', 'jet-engine' ),
			),
			'object_context' => array(
				'label' => __( 'Context', 'jet-engine' ),
			),
			'shortcode_types' => array(
				'label' => __( 'Shortcode', 'jet-engine' ),
				'description' => __( 'Select a shortcode you want to generate' ),
			),
			'tag_type' => array(
				'label' => __( 'Tag Enclosing Type', 'jet-engine' ),
				'description' => __( 'Does the shortcode have an enclosing tag or not' ),
			),
		);
	}

	/**
	 * Handle shortcode
	 *
	 * @param  array  $atts [description]
	 * @return [type]       [description]
	 */
	public function base_shortcode( $atts = array() ) {
		$sanitize = is_array( $atts ) && isset( $atts['sanitize'] ) ? $atts['sanitize'] : null;

		$atts = shortcode_atts( apply_filters( 'jet-engine/shortcodes/default-atts', array(
			'component' => 'meta_field',
			'field'     => false,
			'page'      => false,
			'post_id'   => false,
			'sanitize'  => '',
		) ), $atts, 'jet_engine' );

		$result = '';

		switch ( $atts['component'] ) {

			case 'option':
				if ( ! empty( $atts['page'] ) && ! empty( $atts['field'] ) ) {
					$result = jet_engine()->listings->data->get_option( $atts['page'] . '::' . $atts['field'] );
				}
				break;

			case 'meta_field':
				if ( ! empty( $atts['field'] ) ) {
					$post_id = ! empty( $atts['post_id'] ) ? absint( $atts['post_id'] ) : get_the_ID();
					$result = get_post_meta( $post_id, $atts['field'], true );
					// Stored meta remains untrusted until it is rendered by the shortcode.
					$result = $this->sanitize_meta_field_result( $result );
				}

				break;

			default:
				$result = apply_filters( 'jet-engine/shortcodes/' . $atts['component'] . '/result', $result, $atts );
		}

		$default = 'option' === $atts['component'] ? 'wp_kses_post' : 'raw';
		$result  = $this->sanitize_shortcode_result( $result, $this->get_shortcode_sanitize( $sanitize, $default ) );

		if ( ! empty( $result ) && is_array( $result ) ) {
			$result = $this->stringify_shortcode_result( $result );
		}

		return $result;

	}

}
