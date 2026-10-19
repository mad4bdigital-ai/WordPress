<?php
/**
 * Options Pages field sanitization policy.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Engine_Options_Page_Sanitize' ) ) {

	/**
	 * Resolves the closed sanitization-policy allowlist for Options Pages.
	 */
	class Jet_Engine_Options_Page_Sanitize {

		/**
		 * Return supported policy callbacks.
		 *
		 * `default` is resolved by the prepared field's established sanitizer.
		 * `raw` is an explicit no-op and all other entries are built-in callbacks.
		 *
		 * @return array
		 */
		public static function get_callbacks() {
			return array(
				'default'             => false,
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
		 * Normalize a requested policy to the compatibility-safe default.
		 *
		 * @param mixed $sanitize Requested policy.
		 * @return string
		 */
		public static function normalize( $sanitize ) {
			$callbacks = self::get_callbacks();

			if ( is_string( $sanitize ) && isset( $callbacks[ $sanitize ] ) ) {
				return $sanitize;
			}

			return 'default';
		}

		/**
		 * Sanitize a string using an allowlisted explicit policy.
		 *
		 * @param string $value    Value to sanitize.
		 * @param string $sanitize Normalized policy.
		 * @return mixed
		 */
		public static function sanitize_value( $value, $sanitize ) {
			$callbacks = self::get_callbacks();
			$sanitize  = self::normalize( $sanitize );
			$callback  = $callbacks[ $sanitize ];

			if ( ! $callback ) {
				return $value;
			}

			return $callback( $value );
		}
	}
}
