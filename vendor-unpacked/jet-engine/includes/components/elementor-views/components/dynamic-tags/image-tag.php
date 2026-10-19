<?php
namespace Jet_Engine\Elementor_Views\Components\Dynamic_Tags;

use Jet_Engine_Dynamic_Tags_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'jet-component-tag-image';
	}

	public function get_title() {
		return __( 'Component Control Image', 'jet-engine' );
	}

	public function get_group() {
		return Jet_Engine_Dynamic_Tags_Module::JET_GROUP;
	}

	public function get_categories() {
		return [
			Jet_Engine_Dynamic_Tags_Module::IMAGE_CATEGORY
		];
	}

	public function is_settings_required() {
		return true;
	}

	protected function register_controls() {
		$this->add_control(
			'control_name',
			[
				'label'  => __( 'Control Name', 'jet-engine' ),
				'type'   => \Elementor\Controls_Manager::TEXT,
			]
		);
	}

	public function get_value( array $options = array() ) {

		$empty_value = [
			'id'   => '',
			'url'  => '',
			'size' => '',
			'src'  => '',
		];

		$control_name = $this->get_settings( 'control_name' );

		if ( empty( $control_name ) ) {
			return $empty_value;
		}

		$value = jet_engine()->listings->components->state->get( $control_name );

		if ( empty( $value ) ) {
			return $empty_value;
		}

		if ( is_numeric( $value ) ) {
			$image_url = wp_get_attachment_image_url( absint( $value ), 'full' );
			$value     = array_merge( $empty_value, [
				'id' => absint( $value ),
			] );

			if ( $image_url ) {
				$value['url'] = esc_url_raw( $image_url );
				$value['src'] = $value['url'];
			}
		}

		if ( is_string( $value ) ) {
			if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return $empty_value;
			}

			$image_url = esc_url_raw( $value );

			if ( ! $image_url ) {
				return $empty_value;
			}

			return array_merge( $empty_value, [
				'url' => $image_url,
				'src' => $image_url,
			] );
		}

		if ( ! is_array( $value ) ) {
			return $empty_value;
		}

		$value = array_merge( $empty_value, $value );

		if ( empty( $value['url'] ) && ! empty( $value['id'] ) ) {
			$image_url = wp_get_attachment_image_url( absint( $value['id'] ), 'full' );

			if ( $image_url ) {
				$value['url'] = esc_url_raw( $image_url );
			}
		}

		if ( ! empty( $value['url'] ) ) {
			$image_url = esc_url_raw( $value['url'] );

			if ( $image_url ) {
				$value['url'] = $image_url;
			} else {
				$value['url'] = '';
			}
		}

		if ( ! empty( $value['src'] ) ) {
			$image_url = esc_url_raw( $value['src'] );

			if ( $image_url ) {
				$value['src'] = $image_url;
			} else {
				$value['src'] = '';
			}
		}

		if ( empty( $value['src'] ) && ! empty( $value['url'] ) ) {
			$value['src'] = $value['url'];
		}

		if ( empty( $value['url'] ) && ! empty( $value['src'] ) ) {
			$value['url'] = $value['src'];
		}

		return $value;

	}
}
