<?php

namespace Jet_Engine\Modules\Custom_Content_Types\Forms;

use Jet_Engine\Modules\Custom_Content_Types\Module;
use Jet_Form_Builder\Actions\Action_Handler;
use Jet_Form_Builder\Actions\Types\Base;
use Jet_Form_Builder\Exceptions\Action_Exception;
use Jet_Form_Builder\Exceptions\Silence_Exception;

class Delete_Action extends Base {

	/**
	 * @return mixed
	 */
	public function get_id() {
		return 'delete_custom_content_type';
	}

	/**
	 * @return mixed
	 */
	public function get_name() {
		return __( 'Delete Custom Content Type Item', 'jet-engine' );
	}

	public function action_data() {
		require_once Module::instance()->module_path( 'forms/query-cct-data.php' );

		$types      = Query_Cct_Data::cct_list();
		$statuses   = Query_Cct_Data::cct_statuses_list();
		$fetch_path = Module::instance()->query_dialog()->api_path();

		$statuses = array_map( function ( $name, $label ) {
			return array( 'value' => $name, 'label' => $label );

		}, array_keys( $statuses ), $statuses );

		return array(
			'types'      => $types,
			'statuses'   => $statuses,
			'fetch_path' => $fetch_path,
			'user_roles' => \Jet_Engine_Tools::get_user_roles_for_js(),
		);
	}

	/**
	 * @return mixed
	 */
	public function visible_attributes_for_gateway_editor() {
		return array( 'type' );
	}

	/**
	 * @return mixed
	 */
	public function self_script_name() {
		return 'JetEngineCCT';
	}

	/**
	 * @return mixed
	 */
	public function editor_labels() {
		return array(
			'type'           => __( 'Content Type:', 'jet-engine' ),
			'status'         => __( 'Item Status:', 'jet-engine' ),
			'fields_map'     => __( 'Fields Map:', 'jet-engine' ),
			'default_fields' => __( 'Default Fields:', 'jet-engine' )
		);
	}

	public function editor_labels_help() {
		return array(
			'fields_map'     => __( 'Select content type fields to save appropriate form fields into', 'jet-engine' ),
			'default_fields' => __( 'Define default fields values which should be set on the CCT item creation', 'jet-engine' ),
		);
	}

	/**
	 * @param array $request
	 * @param Action_Handler $handler
	 *
	 * @return void
	 * @throws Action_Exception
	 */
	public function do_action( array $request, Action_Handler $handler ) {

		$type        = ! empty( $this->settings['type'] ) ? $this->settings['type'] : false;
		$permission  = ! empty( $this->settings['permission'] ) ? $this->settings['permission'] : 'permitted_users';
		$id_field    = ! empty( $this->settings['item_id'] ) ? $this->settings['item_id'] : '';
		$type_object = false;

		if ( empty( $id_field ) ) {
			throw ( new Action_Exception(
				'Internal error! Please contact website administrator. Error code: item_id_field_empty',
				$this->settings
			) )->dynamic_error();
		}

		if ( $type ) {
			$type_object = Module::instance()->manager->get_content_types( $type );
		}

		if ( ! $type_object ) {
			throw ( new Action_Exception(
				'Internal error! Please contact website administrator. Error code: content_type_not_found',
				$this->settings
			) )->dynamic_error();
		}

		$item_id       = absint( $request[ $id_field ] ?? 0 );
		$existing_item = $type_object->db->get_item( $item_id );

		if ( ! $existing_item ) {
			throw ( new Action_Exception(
				'The item you are trying to delete does not exist.'
			) )->dynamic_error();
		}

		switch ( $permission ) {
			case 'anybody':
				break;
			case 'permitted_users':
				if ( ! $type_object->user_has_access() ) {
					throw ( new Action_Exception(
						'You are not allowed to delete this item.'
					) )->dynamic_error();
				}
				break;
			case 'logged_in':
				if ( ! is_user_logged_in() ) {
					throw ( new Action_Exception(
						'You are not allowed to delete this item.'
					) )->dynamic_error();
				}
				break;
			default:
				throw ( new Action_Exception(
					'Internal error! Please contact website administrator. Error code: unknown_permission_type'
				) )->dynamic_error();
		}

		$handler = $type_object->get_item_handler();
		$handler->raw_delete_item( $item_id );
	}

	public function recursive_parse_values( $source ) {
		if ( ! is_array( $source ) ) {
			return wp_specialchars_decode(
				\Jet_Form_Builder\Classes\Tools::sanitize_text_field( $source ),
				ENT_COMPAT
			);
		}

		$response = array();
		foreach ( $source as $item_name => $item_value ) {
			$response[ $item_name ] = $this->recursive_parse_values( $item_value );
		}

		return $response;
	}

}