<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Context_Adapter extends MAD4B_SCP_Adapter_Base {
	public function id() { return 'context'; }
	public function label() { return 'Context Authority'; }
	public function is_available() { return class_exists( 'MAD4B_SCP_Context_Authority' ) && class_exists( 'MAD4B_SCP_Google_Drive_Context' ); }
	protected function certified_provider_key() { return 'google_drive_context'; }
	protected function mutation_requires_certification() { return false; }
	protected function detect_plugin_version() { return defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : ''; }

	public function reversible_contracts() {
		return array(
			'context/update-drive-asset' => 'mad4b.rollback.google-drive-context-update.v1',
			'context/recreate-drive-asset' => 'mad4b.rollback.google-drive-context-recreate.v1',
		);
	}

	public function ability_names() {
		return array(
			'read' => array(
				'context/status',
				'context/assets',
				'context/google-drive-status',
			),
			'content' => array(),
			'write' => array(
				'context/create-drive-asset',
				'context/update-drive-asset',
				'context/recreate-drive-asset',
			),
			'admin' => array(),
		);
	}

	public function register_abilities() {
		$this->add_ability(
			'context/status',
			'Context Authority Status',
			'context_status',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/assets',
			'List Context Assets',
			'list_assets',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'mode' => array( 'type' => 'string', 'enum' => array( '', 'governed', 'task_attachment' ), 'default' => '' ),
					'status' => array( 'type' => 'string', 'default' => '' ),
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ),
				)
			)
		);
		$this->add_ability(
			'context/google-drive-status',
			'Google Drive Context Connection Status',
			'google_drive_status',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);

		$write_permission = array( 'MAD4B_SCP_Policy', 'can_admin' );
		$this->add_ability(
			'context/create-drive-asset',
			'Create Google Drive Context Asset',
			'create_drive_asset',
			$write_permission,
			$this->schema(
				array(
					'source_id' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
					'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Google_Drive_Context::MAX_WRITE_BYTES ),
					'format' => array( 'type' => 'string', 'enum' => array( 'google_doc', 'markdown', 'text' ), 'default' => 'markdown' ),
				),
				array( 'source_id', 'name', 'content' )
			),
			'write',
			false,
			true,
			false
		);
		$this->add_ability(
			'context/update-drive-asset',
			'Update Google Drive Context Asset',
			'update_drive_asset',
			$write_permission,
			$this->schema(
				array(
					'asset_id' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'expected_content_hash' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Google_Drive_Context::MAX_REVERSIBLE_TEXT_BYTES ),
				),
				array( 'asset_id', 'expected_content_hash', 'content' )
			),
			'write',
			false,
			true,
			false
		);
		$this->add_ability(
			'context/recreate-drive-asset',
			'Recreate Unavailable Google Drive Context Asset',
			'recreate_drive_asset',
			$write_permission,
			$this->schema(
				array(
					'asset_id' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Google_Drive_Context::MAX_REVERSIBLE_TEXT_BYTES ),
					'format' => array( 'type' => 'string', 'enum' => array( 'google_doc', 'markdown', 'text' ), 'default' => 'google_doc' ),
				),
				array( 'asset_id', 'content' )
			),
			'write',
			false,
			true,
			false
		);
	}

	public function mutation_ability_runtime_eligibility( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! in_array( $ability_name, $this->ability_names()['write'], true ) ) return true;
		if ( ! $this->is_available() ) return new WP_Error( 'mad4b_context_provider_unavailable', 'Context Authority Google Drive provider is unavailable.' );
		$status = MAD4B_SCP_Google_Drive_Context::write_capability_status();
		if ( empty( $status['write_available'] ) ) return new WP_Error( 'mad4b_google_drive_write_scope_required', 'Google Drive read+write OAuth scope is required before Context write abilities can mount.' );
		if ( empty( $status['selected_source_count'] ) ) return new WP_Error( 'mad4b_context_source_required_for_write', 'Select a governed Google Drive source folder before Context write abilities can mount.' );
		$operation_map = array(
			'context/create-drive-asset' => array( 'operation' => 'create', 'count_key' => 'create_source_count' ),
			'context/update-drive-asset' => array( 'operation' => 'update', 'count_key' => 'update_source_count' ),
			'context/recreate-drive-asset' => array( 'operation' => 'recreate', 'count_key' => 'recreate_source_count' ),
		);
		if ( ! isset( $operation_map[ $ability_name ] ) ) return new WP_Error( 'mad4b_context_write_operation_unknown', 'Context write ability has no source policy mapping.' );
		$mapping = $operation_map[ $ability_name ];
		if ( empty( $status[ $mapping['count_key'] ] ) ) {
			return new WP_Error(
				'mad4b_context_source_policy_blocks_write',
				'No selected Context source policy currently permits this Drive mutation.',
				array( 'operation' => $mapping['operation'] )
			);
		}
		return true;
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'context/update-drive-asset' === $ability_name ) {
			$asset_id = isset( $input['asset_id'] ) ? (string) $input['asset_id'] : '';
			$expected = isset( $input['expected_content_hash'] ) ? (string) $input['expected_content_hash'] : '';
			$state = MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id, $expected );
			if ( is_wp_error( $state ) ) return $state;
			return array(
				'target_type' => 'google_drive_context_asset',
				'target_id' => (string) $state['asset_id'],
				'target' => array(
					'operation' => 'update',
					'asset_id' => (string) $state['asset_id'],
					'source_id' => (string) $state['source_id'],
					'file_id' => (string) $state['file_id'],
				),
				'state' => $state,
			);
		}
		if ( 'context/recreate-drive-asset' === $ability_name ) {
			$asset_id = isset( $input['asset_id'] ) ? (string) $input['asset_id'] : '';
			$state = MAD4B_SCP_Google_Drive_Context::reversible_recreate_state( $asset_id );
			if ( is_wp_error( $state ) ) return $state;
			if ( 'unavailable' !== ( isset( $state['status'] ) ? (string) $state['status'] : '' ) ) return new WP_Error( 'mad4b_google_drive_recreate_snapshot_not_unavailable', 'Recreate rollback capture requires an unavailable original asset.' );
			return array(
				'target_type' => 'google_drive_context_asset',
				'target_id' => (string) $state['asset_id'],
				'target' => array(
					'operation' => 'recreate',
					'asset_id' => (string) $state['asset_id'],
					'source_id' => (string) $state['source_id'],
					'file_id' => (string) $state['original_file_id'],
					'parent_folder_id' => isset( $state['parent_folder_id'] ) ? (string) $state['parent_folder_id'] : '',
				),
				'state' => $state,
			);
		}
		return parent::capture_reversible_state( $ability_name, $input );
	}

	public function read_reversible_state( $ability_name, array $target ) {
		$asset_id = isset( $target['asset_id'] ) ? (string) $target['asset_id'] : '';
		if ( 'context/update-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id );
		if ( 'context/recreate-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::reversible_recreate_state( $asset_id );
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( 'context/update-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_update_state( $target, $state );
		if ( 'context/recreate-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_recreate_state( $target, $state );
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}

	public function context_status() {
		return MAD4B_SCP_Context_Authority::status();
	}

	public function google_drive_status() {
		return array(
			'connection' => MAD4B_SCP_Google_Drive_Context::connection_status(),
			'write_capability' => MAD4B_SCP_Google_Drive_Context::write_capability_status(),
		);
	}

	public function list_assets( $input ) {
		$mode = sanitize_key( isset( $input['mode'] ) ? (string) $input['mode'] : '' );
		$status = sanitize_key( isset( $input['status'] ) ? (string) $input['status'] : '' );
		$category = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 100;
		$items = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( '' !== $mode && $mode !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) continue;
			if ( '' !== $status && $status !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( '' !== $category && $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
			$items[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'source_mode' => isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '',
				'source_write_policy' => class_exists( 'MAD4B_SCP_Context_Authority' ) && ! empty( $asset['source_id'] ) ? MAD4B_SCP_Context_Authority::source_write_policy( (string) $asset['source_id'] ) : 'read_only',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'required' => ! empty( $asset['required'] ),
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'availability_reason' => isset( $asset['availability_reason'] ) ? (string) $asset['availability_reason'] : '',
				'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				'file_id' => isset( $asset['file_id'] ) ? (string) $asset['file_id'] : '',
				'parent_folder_id' => isset( $asset['parent_folder_id'] ) ? (string) $asset['parent_folder_id'] : '',
				'mime_type' => isset( $asset['mime_type'] ) ? (string) $asset['mime_type'] : '',
				'last_synced_at' => isset( $asset['last_synced_at'] ) ? (string) $asset['last_synced_at'] : '',
				'write_capabilities' => MAD4B_SCP_Google_Drive_Context::asset_write_capabilities( isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '' ),
			);
			if ( count( $items ) >= $limit ) break;
		}
		return array( 'items' => $items, 'count' => count( $items ) );
	}

	public function create_drive_asset( $input ) {
		return MAD4B_SCP_Google_Drive_Context::create_asset(
			(string) $input['source_id'],
			(string) $input['name'],
			(string) $input['content'],
			isset( $input['format'] ) ? (string) $input['format'] : 'markdown'
		);
	}

	public function update_drive_asset( $input ) {
		return MAD4B_SCP_Google_Drive_Context::update_asset(
			(string) $input['asset_id'],
			(string) $input['expected_content_hash'],
			(string) $input['content']
		);
	}

	public function recreate_drive_asset( $input ) {
		return MAD4B_SCP_Google_Drive_Context::recreate_asset(
			(string) $input['asset_id'],
			(string) $input['content'],
			isset( $input['format'] ) ? (string) $input['format'] : 'google_doc'
		);
	}
}
