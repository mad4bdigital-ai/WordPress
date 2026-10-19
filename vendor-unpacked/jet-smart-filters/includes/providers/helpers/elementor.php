<?php
/**
 * Provider helper: Elementor.
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Jet_Smart_Filters_Provider_Helper_Elementor' ) ) {
	/**
	 * Define Elementor provider helper.
	 */
	class Jet_Smart_Filters_Provider_Helper_Elementor {
		/**
		 * Get widget query ID from widget instance or settings array.
		 *
		 * @param object|array $widget_or_settings Elementor widget or settings array.
		 * @return string
		 */
		public function get_widget_query_id( $widget_or_settings ) {

			$settings = is_array( $widget_or_settings )
				? $widget_or_settings
				: $widget_or_settings->get_settings();

			return ! empty( $settings['_element_id'] ) ? $settings['_element_id'] : 'default';
		}

		/**
		 * Extract widget settings for provider storage.
		 *
		 * @param object        $widget         Elementor widget instance.
		 * @param array         $store_settings Settings keys to store.
		 * @param callable|null $resolver       Optional custom value resolver.
		 * @return array
		 */
		public function get_widget_settings_for_storage( $widget, $store_settings, $resolver = null ) {

			$settings         = $widget->get_settings();
			$default_settings = array();

			foreach ( $store_settings as $key ) {
				if ( is_callable( $resolver ) ) {
					$value = call_user_func( $resolver, $key, $settings, $widget );

					if ( null !== $value ) {
						$default_settings[ $key ] = $value;
						continue;
					}
				}

				$default_settings[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
			}

			return $default_settings;
		}

		/**
		 * Ensure all declared settings exist.
		 *
		 * @param array $settings       Raw settings.
		 * @param array $store_settings Settings keys to ensure.
		 * @return array
		 */
		public function ensure_settings( $settings, $store_settings ) {

			foreach ( $store_settings as $setting ) {
				if ( isset( $settings[ $setting ] ) ) {
					continue;
				}

				if ( false !== strpos( $setting, '_meta_data' ) ) {
					$settings[ $setting ] = array();
				} else {
					$settings[ $setting ] = false;
				}
			}

			return $settings;
		}

		/**
		 * Check if an Elementor widget uses the current query source.
		 *
		 * @param object|array $widget_or_settings Elementor widget or settings array.
		 * @return bool
		 */
		public function is_current_query_widget( $widget_or_settings ) {

			if ( is_array( $widget_or_settings ) ) {
				$settings = $widget_or_settings;
			} elseif ( is_callable( array( $widget_or_settings, 'get_settings_for_display' ) ) ) {
				$settings = $widget_or_settings->get_settings_for_display();
			} elseif ( is_callable( array( $widget_or_settings, 'get_settings' ) ) ) {
				$settings = $widget_or_settings->get_settings();
			} else {
				return false;
			}

			foreach ( $settings as $setting_key => $setting_value ) {
				if ( 'current_query' !== $setting_value ) {
					continue;
				}

				if ( 'query_post_type' === $setting_key || false !== strpos( $setting_key, '_query_post_type' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Create Elementor widget instance from stored settings.
		 *
		 * @param string $widget_name    Elementor widget name.
		 * @param array  $settings       Widget settings.
		 * @param string $widget_id_key  Widget ID setting key.
		 * @return object|false
		 */
		public function create_widget_instance( $widget_name, $settings, $widget_id_key = '_el_widget_id' ) {

			if ( empty( $settings[ $widget_id_key ] ) ) {
				return false;
			}

			$widget_id = $settings[ $widget_id_key ];

			unset( $settings[ $widget_id_key ] );

			$data = array(
				'id'         => $widget_id,
				'elType'     => 'widget',
				'settings'   => $settings,
				'elements'   => array(),
				'widgetType' => $widget_name,
			);

			return \Elementor\Plugin::$instance->elements_manager->create_element_instance( $data );
		}

		/**
		 * Get the current filtered post ID.
		 *
		 * Prefer the queried object on singular pages because Elementor templates can
		 * temporarily switch the current document during rendering.
		 *
		 * @return int|false
		 */
		public function get_filtered_post_id() {

			if ( is_singular() ) {
				$post_id = get_queried_object_id();

				if ( $post_id ) {
					return $post_id;
				}
			}

			$current_document = \Elementor\Plugin::$instance->documents->get_current();

			if ( $current_document ) {
				return $current_document->get_main_id();
			}

			$post_id = get_the_ID();

			if ( $post_id ) {
				return $post_id;
			}

			return false;
		}

		/**
		 * Get the current Elementor document ID.
		 *
		 * @return int|false
		 */
		public function get_current_document_id() {

			$current_document = \Elementor\Plugin::$instance->documents->get_current();

			if ( ! $current_document ) {
				return false;
			}

			$document_id = $current_document->get_main_id();

			return $document_id ? absint( $document_id ) : false;
		}

		/**
		 * Get fallback post ID from AJAX referer.
		 *
		 * @return int|false
		 */
		public function get_referer_post_id() {

			$referer = wp_get_referer();

			if ( ! $referer ) {
				return false;
			}

			$post_id = url_to_postid( $referer );

			return $post_id ? absint( $post_id ) : false;
		}

		/**
		 * Find filtered widget in document by widget ID.
		 *
		 * @param int    $post_id   Document post ID.
		 * @param string $widget_id Widget ID.
		 * @return object|false
		 */
		public function get_filtered_widget( $post_id, $widget_id ) {

			$elementor = \Elementor\Plugin::instance();
			$document  = $elementor->documents->get( $post_id );

			if ( ! $document ) {
				return false;
			}

			$widget_data = $this->find_widget_recursive( $document->get_elements_data(), $widget_id );

			if ( ! $widget_data ) {
				return false;
			}

			return $elementor->elements_manager->create_element_instance( $widget_data );
		}

		/**
		 * Find widget recursively in elements stack.
		 *
		 * @param array  $widgets   Elements stack.
		 * @param string $widget_id Widget ID.
		 * @return array|false
		 */
		protected function find_widget_recursive( $widgets, $widget_id ) {

			foreach ( $widgets as $widget ) {
				if ( $widget_id === $widget['id'] ) {
					return $widget;
				}

				if ( empty( $widget['elements'] ) ) {
					continue;
				}

				$found_widget = $this->find_widget_recursive( $widget['elements'], $widget_id );

				if ( $found_widget ) {
					return $found_widget;
				}
			}

			return false;
		}
	}
}
