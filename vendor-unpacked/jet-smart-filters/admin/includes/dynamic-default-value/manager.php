<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

if ( ! class_exists( 'Jet_Smart_Filters_Admin_Dynamic_Default_Value_Manager' ) ) {
	/**
	 * Jet Smart Filters Admin Dynamic Default Value Manager class
	 */
	class Jet_Smart_Filters_Admin_Dynamic_Default_Value_Manager {

		public $list = [];
		private $separator = '::';
		
		public function __construct() {

			$dynamic_default_value_list = array();

			// Request
			$dynamic_default_value_list[] = array(
				'value'  => '__request',
				'label'  => __( 'Request', 'jet-smart-filters' ),
				'fields' => array(
					'relation' => array(
						'type'  => 'text',
						'title' => __( 'Value Key', 'jet-smart-filters' )
					)
				)
			);

			// Cookie
			$dynamic_default_value_list[] = array(
				'value'  => '__cookie',
				'label'  => __( 'Cookie', 'jet-smart-filters' ),
				'fields' => array(
					'relation' => array(
						'type'  => 'text',
						'title' => __( 'Value Key', 'jet-smart-filters' )
					)
				)
			);

			// Shortcode
			$dynamic_default_value_list[] = array(
				'value'  => '__shortcode',
				'label'  => __( 'Shortcode', 'jet-smart-filters' ),
				'fields' => array(
					'relation' => array(
						'type' => 'text'
					)
				)
			);

			$this->register_items( $dynamic_default_value_list );
		}
		
		public function register_item( $item ) {

			if ( empty( $item['value'] ) || empty( $item['label'] ) ) {
				return;
			}

			if ( isset( $item['fields'] ) ) {
				$item['separator'] = $this->separator;
			}

			$this->list[] = $item;
		}

		public function register_items( $items_list ) {

			foreach ( $items_list as $item ) {
				$this->register_item( $item );
			}
		}
	}
}
