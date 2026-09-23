<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Context_Adapter extends MAD4B_SCP_Adapter_Base {
	const PROVIDER_CONTRACT = 'mad4b.google-drive-context-provider.v1';
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
				'context/review-queue',
				'context/review-audit',
				'context/brand-core-coverage',
				'context/google-drive-status',
				'context/runtime-readiness',
				'context/conflicts',
				'context/reference-profile',
				'context/retrieve',
				'context/compliance-check',
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
					'task_scope' => array( 'type' => 'string', 'maxLength' => 160, 'default' => '' ),
					'status' => array( 'type' => 'string', 'default' => '' ),
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ),
				)
			)
		);
		$this->add_ability(
			'context/review-queue',
			'Context Human Review Queue',
			'context_review_queue',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/review-audit',
			'Context Human Review Audit',
			'context_review_audit',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'asset_id' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}
		$this->add_ability(
			'context/brand-core-coverage',
			'Brand Core Context Coverage',
			'brand_core_coverage',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/google-drive-status',
			'Google Drive Context Connection Status',
			'google_drive_status',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/runtime-readiness',
			'Context Runtime Readiness',
			'context_runtime_readiness',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/conflicts',
			'Context Conflict Report',
			'context_conflicts',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25 ),
				)
			)
		);
		$this->add_ability(
			'context/reference-profile',
			'Writer Reference Profile',
			'context_reference_profile',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'asset_id' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'task_scope' => array( 'type' => 'string', 'maxLength' => 160, 'default' => '' ),
				),
				array( 'asset_id' )
			)
		);
		$this->add_ability(
			'context/retrieve',
			'Rank Context Assets',
			'context_retrieve',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Context_Intelligence::MAX_QUERY_BYTES ),
					'task_scope' => array( 'type' => 'string', 'default' => '' ),
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ),
				),
				array( 'query' )
			)
		);
		$this->add_ability(
			'context/compliance-check',
			'Brand Compliance Check',
			'context_compliance_check',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Context_Intelligence::MAX_DRAFT_BYTES ),
					'receipt' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				array( 'text', 'receipt' )
			)
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
		if ( 'context/create-drive-asset' === $ability_name ) {
			return new WP_Error(
				'mad4b_google_drive_create_rollback_not_certified',
				'Arbitrary Drive asset creation is not mounted until an exact post-create identity and rollback contract are certified. Use update for ready assets or recreate for assets proven unavailable.'
			);
		}
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
		$provider_contract = $this->context_provider_contract_status();
		if ( empty( $provider_contract['ready'] ) ) {
			return new WP_Error(
				'mad4b_context_provider_contract_not_ready',
				'Google Drive Context write is denied because the first-party provider contract is incomplete or drifted.',
				array( 'provider_contract' => $provider_contract )
			);
		}
		return true;
	}

	public function context_provider_contract_status() {
		$blockers = array();
		$required_methods = array(
			'MAD4B_SCP_Google_Drive_Context' => array(
				'write_capability_status',
				'create_asset',
				'update_asset',
				'recreate_asset',
				'reversible_update_state',
				'restore_update_state',
				'reversible_recreate_state',
				'restore_recreate_state',
			),
			'MAD4B_SCP_Context_Authority' => array(
				'source',
				'asset',
				'source_allows_write',
				'upsert_asset_from_provider',
				'register_recreated_asset',
				'registry_revision',
				'context_fingerprint',
				'authority_manifest_fingerprint',
				'begin_recreated_asset_rollback',
				'cancel_recreated_asset_rollback',
				'rollback_recreated_asset',
			),
			'MAD4B_SCP_External_Handshake_Evidence' => array(
				'build_fingerprint',
			),
		);
		foreach ( $required_methods as $class => $methods ) {
			if ( ! class_exists( $class ) ) {
				$blockers[] = 'class_missing:' . $class;
				continue;
			}
			foreach ( $methods as $method ) if ( ! method_exists( $class, $method ) ) $blockers[] = 'method_missing:' . $class . '::' . $method;
		}

		$contracts = $this->reversible_contracts();
		if ( 'mad4b.rollback.google-drive-context-update.v1' !== ( isset( $contracts['context/update-drive-asset'] ) ? (string) $contracts['context/update-drive-asset'] : '' ) ) $blockers[] = 'update_rollback_contract_drift';
		if ( 'mad4b.rollback.google-drive-context-recreate.v1' !== ( isset( $contracts['context/recreate-drive-asset'] ) ? (string) $contracts['context/recreate-drive-asset'] : '' ) ) $blockers[] = 'recreate_rollback_contract_drift';

		$critical_hashes = array();
		$critical_files = array(
			'includes/adapters/class-mad4b-scp-context-adapter.php',
			'includes/class-mad4b-scp-context-authority.php',
			'includes/class-mad4b-scp-google-drive-context.php',
			'includes/class-mad4b-scp-context-preflight.php',
		);
		if ( ! defined( 'MAD4B_SCP_DIR' ) ) {
			$blockers[] = 'control_plane_runtime_root_unavailable';
		} else {
			foreach ( $critical_files as $relative ) {
				$file = MAD4B_SCP_DIR . $relative;
				if ( ! is_file( $file ) || ! is_readable( $file ) ) {
					$blockers[] = 'critical_file_unreadable:' . $relative;
					continue;
				}
				$hash = hash_file( 'sha256', $file );
				if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
					$blockers[] = 'critical_file_hash_failed:' . $relative;
					continue;
				}
				$critical_hashes[ $relative ] = $hash;
			}
		}
		ksort( $critical_hashes, SORT_STRING );
		$control_plane_build_fingerprint = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) && method_exists( 'MAD4B_SCP_External_Handshake_Evidence', 'build_fingerprint' )
			? strtolower( trim( (string) MAD4B_SCP_External_Handshake_Evidence::build_fingerprint() ) )
			: '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $control_plane_build_fingerprint ) ) $blockers[] = 'control_plane_build_fingerprint_unavailable';

		$payload = array(
			'contract' => self::PROVIDER_CONTRACT,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'control_plane_build_fingerprint' => $control_plane_build_fingerprint,
			'critical_files' => $critical_hashes,
			'rollback_contracts' => $contracts,
		);
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'contract' => self::PROVIDER_CONTRACT,
			'provider' => 'google_drive_context',
			'ready' => empty( $blockers ),
			'first_party' => true,
			'certification_mode' => 'runtime_structural_plus_build_bound_artifact_fingerprint',
			'control_plane_build_fingerprint' => $control_plane_build_fingerprint,
			'artifact_fingerprint' => is_string( $json ) ? hash( 'sha256', $json ) : '',
			'critical_files' => $critical_hashes,
			'rollback_contracts' => $contracts,
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'context/update-drive-asset' === $ability_name ) {
			$asset_id = isset( $input['asset_id'] ) ? (string) $input['asset_id'] : '';
			$expected = isset( $input['expected_content_hash'] ) ? (string) $input['expected_content_hash'] : '';
			$state = MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id, $expected );
			if ( is_wp_error( $state ) ) return $state;
			$state = $this->bind_reversible_state_to_provider_contract( $state );
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
			$state = $this->bind_reversible_state_to_provider_contract( $state );
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
		if ( 'context/update-drive-asset' === $ability_name ) {
			$state = MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id );
			return is_wp_error( $state ) ? $state : $this->bind_reversible_state_to_provider_contract( $state );
		}
		if ( 'context/recreate-drive-asset' === $ability_name ) {
			$state = MAD4B_SCP_Google_Drive_Context::reversible_recreate_state( $asset_id );
			return is_wp_error( $state ) ? $state : $this->bind_reversible_state_to_provider_contract( $state );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( in_array( $ability_name, array( 'context/update-drive-asset', 'context/recreate-drive-asset' ), true ) ) {
			$guard = $this->validate_reversible_provider_binding( $state );
			if ( is_wp_error( $guard ) ) return $guard;
		}
		if ( 'context/update-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_update_state( $target, $state );
		if ( 'context/recreate-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_recreate_state( $target, $state );
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}

	private function bind_reversible_state_to_provider_contract( array $state ) {
		$provider = $this->context_provider_contract_status();
		if ( empty( $provider['ready'] ) ) {
			return new WP_Error(
				'mad4b_context_reversible_provider_contract_not_ready',
				'Context reversible state cannot be captured because the exact first-party provider contract is not ready.',
				array( 'blockers' => isset( $provider['blockers'] ) ? $provider['blockers'] : array() )
			);
		}
		$artifact = isset( $provider['artifact_fingerprint'] ) ? strtolower( trim( (string) $provider['artifact_fingerprint'] ) ) : '';
		$build = isset( $provider['control_plane_build_fingerprint'] ) ? strtolower( trim( (string) $provider['control_plane_build_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $artifact ) || ! preg_match( '/^[a-f0-9]{64}$/', $build ) ) {
			return new WP_Error( 'mad4b_context_reversible_provider_binding_invalid', 'Context reversible provider binding is incomplete.' );
		}
		$state['_mad4b_provider_binding'] = array(
			'contract' => self::PROVIDER_CONTRACT,
			'artifact_fingerprint' => $artifact,
			'control_plane_build_fingerprint' => $build,
		);
		return $state;
	}

	private function validate_reversible_provider_binding( array $state ) {
		$recorded = isset( $state['_mad4b_provider_binding'] ) && is_array( $state['_mad4b_provider_binding'] ) ? $state['_mad4b_provider_binding'] : array();
		$current = $this->context_provider_contract_status();
		if ( empty( $current['ready'] ) ) return new WP_Error( 'mad4b_context_undo_provider_contract_not_ready', 'Context undo is denied because the current provider contract is not ready.' );
		foreach ( array( 'artifact_fingerprint', 'control_plane_build_fingerprint' ) as $field ) {
			$before = isset( $recorded[ $field ] ) ? strtolower( trim( (string) $recorded[ $field ] ) ) : '';
			$now = isset( $current[ $field ] ) ? strtolower( trim( (string) $current[ $field ] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $before ) || ! preg_match( '/^[a-f0-9]{64}$/', $now ) || ! hash_equals( $before, $now ) ) {
				return new WP_Error(
					'mad4b_context_undo_provider_contract_drift',
					'Context undo is denied because the first-party provider artifact/build no longer matches the mutation snapshot.',
					array( 'field' => $field )
				);
			}
		}
		return true;
	}

	public function context_status() {
		return MAD4B_SCP_Context_Authority::status();
	}

	public function google_drive_status() {
		return array(
			'connection' => MAD4B_SCP_Google_Drive_Context::public_connection_status(),
			'write_capability' => MAD4B_SCP_Google_Drive_Context::write_capability_status(),
			'provider_contract' => $this->context_provider_contract_status(),
		);
	}

	public function context_runtime_readiness() {
		return MAD4B_SCP_Google_Drive_Context::runtime_readiness();
	}

	public function list_assets( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mode = sanitize_key( isset( $input['mode'] ) ? (string) $input['mode'] : '' );
		$task_scope = isset( $input['task_scope'] ) ? substr( sanitize_text_field( (string) $input['task_scope'] ), 0, 160 ) : '';
		$status = sanitize_key( isset( $input['status'] ) ? (string) $input['status'] : '' );
		$category = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 100;
		if ( 'task_attachment' === $mode && '' === $task_scope ) {
			return new WP_Error(
				'mad4b_context_task_scope_required',
				'Task-scoped Context asset metadata requires the exact task_scope.'
			);
		}
		$items = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$asset_mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			if ( 'task_attachment' === $asset_mode ) {
				$asset_scope = isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '';
				if ( '' === $task_scope || '' === $asset_scope || ! hash_equals( $asset_scope, $task_scope ) ) continue;
			}
			if ( '' !== $mode && $mode !== $asset_mode ) continue;
			if ( '' !== $status && $status !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( '' !== $category && $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
			$current_content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_content_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			$review_binding_exact = 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) && preg_match( '/^[a-f0-9]{64}$/', $current_content_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_content_hash ) && hash_equals( $current_content_hash, $reviewed_content_hash );
			$items[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'source_mode' => $asset_mode,
				'source_write_policy' => class_exists( 'MAD4B_SCP_Context_Authority' ) && ! empty( $asset['source_id'] ) ? MAD4B_SCP_Context_Authority::source_write_policy( (string) $asset['source_id'] ) : 'read_only',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'required' => ! empty( $asset['required'] ),
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'quality_mode' => isset( $asset['quality']['mode'] ) ? (string) $asset['quality']['mode'] : '',
				'quality_confidence' => isset( $asset['quality']['confidence'] ) ? (float) $asset['quality']['confidence'] : null,
				'quality_provisional' => ! empty( $asset['quality']['provisional'] ),
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed',
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'review_note' => isset( $asset['review_note'] ) ? (string) $asset['review_note'] : '',
				'reviewed_at' => isset( $asset['reviewed_at'] ) ? (string) $asset['reviewed_at'] : '',
				'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
				'review_binding_exact' => $review_binding_exact,
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'content_available' => ! empty( $asset['content_available'] ),
				'content_excerpt' => isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'classification_confidence' => isset( $asset['classification_confidence'] ) ? (float) $asset['classification_confidence'] : 0.0,
				'automatic_classification' => isset( $asset['automatic_classification'] ) && is_array( $asset['automatic_classification'] ) ? $asset['automatic_classification'] : array(),
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
		$status = method_exists( 'MAD4B_SCP_Context_Authority', 'status' ) ? MAD4B_SCP_Context_Authority::status() : array();
		return array(
			'contract' => 'mad4b.context-asset-list.v3',
			'items' => $items,
			'count' => count( $items ),
			'task_scope_bound' => '' !== $task_scope,
			'registry_revision' => isset( $status['registry_revision'] ) ? (int) $status['registry_revision'] : MAD4B_SCP_Context_Authority::registry_revision(),
			'context_fingerprint' => isset( $status['context_fingerprint'] ) ? (string) $status['context_fingerprint'] : MAD4B_SCP_Context_Authority::context_fingerprint(),
			'authority_manifest_fingerprint' => isset( $status['authority_manifest_fingerprint'] ) ? (string) $status['authority_manifest_fingerprint'] : MAD4B_SCP_Context_Authority::authority_manifest_fingerprint(),
		);
	}

	public function context_review_queue() {
		return MAD4B_SCP_Context_Authority::review_queue();
	}

	public function context_review_audit( $input ) {
		$selectors = is_array( $input ) ? $input : array();
		return MAD4B_SCP_Audit::context_review_events( $selectors );
	}

	public function brand_core_coverage() {
		return MAD4B_SCP_Context_Authority::brand_core_coverage();
	}

	public function context_conflicts( $input ) {
		return MAD4B_SCP_Context_Intelligence::conflict_report( is_array( $input ) ? $input : array() );
	}

	public function context_reference_profile( $input ) {
		return MAD4B_SCP_Context_Intelligence::reference_profile( is_array( $input ) ? $input : array() );
	}

	public function context_retrieve( $input ) {
		return MAD4B_SCP_Context_Intelligence::retrieve( is_array( $input ) ? $input : array() );
	}

	public function context_compliance_check( $input ) {
		return MAD4B_SCP_Context_Intelligence::compliance_check( is_array( $input ) ? $input : array() );
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
, 'default' => '' ),
					'request_id' => array( 'type' => 'string', 'maxLength' => 100, 'default' => '' ),
					'event_id' => array( 'type' => 'string', 'maxLength' => 36, 'default' => '' ),
					'decision' => array( 'type' => 'string', 'enum' => array( '', 'approve', 'needs_changes', 'reject' ), 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
				)
			)
		);
		$this->add_ability(
			'context/brand-core-coverage',
			'Brand Core Context Coverage',
			'brand_core_coverage',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/google-drive-status',
			'Google Drive Context Connection Status',
			'google_drive_status',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/runtime-readiness',
			'Context Runtime Readiness',
			'context_runtime_readiness',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema( array() )
		);
		$this->add_ability(
			'context/conflicts',
			'Context Conflict Report',
			'context_conflicts',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25 ),
				)
			)
		);
		$this->add_ability(
			'context/reference-profile',
			'Writer Reference Profile',
			'context_reference_profile',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'asset_id' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'task_scope' => array( 'type' => 'string', 'maxLength' => 160, 'default' => '' ),
				),
				array( 'asset_id' )
			)
		);
		$this->add_ability(
			'context/retrieve',
			'Rank Context Assets',
			'context_retrieve',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Context_Intelligence::MAX_QUERY_BYTES ),
					'task_scope' => array( 'type' => 'string', 'default' => '' ),
					'category' => array( 'type' => 'string', 'default' => '' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ),
				),
				array( 'query' )
			)
		);
		$this->add_ability(
			'context/compliance-check',
			'Brand Compliance Check',
			'context_compliance_check',
			array( 'MAD4B_SCP_Policy', 'can_read' ),
			$this->schema(
				array(
					'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => MAD4B_SCP_Context_Intelligence::MAX_DRAFT_BYTES ),
					'receipt' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				array( 'text', 'receipt' )
			)
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
		if ( 'context/create-drive-asset' === $ability_name ) {
			return new WP_Error(
				'mad4b_google_drive_create_rollback_not_certified',
				'Arbitrary Drive asset creation is not mounted until an exact post-create identity and rollback contract are certified. Use update for ready assets or recreate for assets proven unavailable.'
			);
		}
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
		$provider_contract = $this->context_provider_contract_status();
		if ( empty( $provider_contract['ready'] ) ) {
			return new WP_Error(
				'mad4b_context_provider_contract_not_ready',
				'Google Drive Context write is denied because the first-party provider contract is incomplete or drifted.',
				array( 'provider_contract' => $provider_contract )
			);
		}
		return true;
	}

	public function context_provider_contract_status() {
		$blockers = array();
		$required_methods = array(
			'MAD4B_SCP_Google_Drive_Context' => array(
				'write_capability_status',
				'create_asset',
				'update_asset',
				'recreate_asset',
				'reversible_update_state',
				'restore_update_state',
				'reversible_recreate_state',
				'restore_recreate_state',
			),
			'MAD4B_SCP_Context_Authority' => array(
				'source',
				'asset',
				'source_allows_write',
				'upsert_asset_from_provider',
				'register_recreated_asset',
				'registry_revision',
				'context_fingerprint',
				'authority_manifest_fingerprint',
				'begin_recreated_asset_rollback',
				'cancel_recreated_asset_rollback',
				'rollback_recreated_asset',
			),
			'MAD4B_SCP_External_Handshake_Evidence' => array(
				'build_fingerprint',
			),
		);
		foreach ( $required_methods as $class => $methods ) {
			if ( ! class_exists( $class ) ) {
				$blockers[] = 'class_missing:' . $class;
				continue;
			}
			foreach ( $methods as $method ) if ( ! method_exists( $class, $method ) ) $blockers[] = 'method_missing:' . $class . '::' . $method;
		}

		$contracts = $this->reversible_contracts();
		if ( 'mad4b.rollback.google-drive-context-update.v1' !== ( isset( $contracts['context/update-drive-asset'] ) ? (string) $contracts['context/update-drive-asset'] : '' ) ) $blockers[] = 'update_rollback_contract_drift';
		if ( 'mad4b.rollback.google-drive-context-recreate.v1' !== ( isset( $contracts['context/recreate-drive-asset'] ) ? (string) $contracts['context/recreate-drive-asset'] : '' ) ) $blockers[] = 'recreate_rollback_contract_drift';

		$critical_hashes = array();
		$critical_files = array(
			'includes/adapters/class-mad4b-scp-context-adapter.php',
			'includes/class-mad4b-scp-context-authority.php',
			'includes/class-mad4b-scp-google-drive-context.php',
			'includes/class-mad4b-scp-context-preflight.php',
		);
		if ( ! defined( 'MAD4B_SCP_DIR' ) ) {
			$blockers[] = 'control_plane_runtime_root_unavailable';
		} else {
			foreach ( $critical_files as $relative ) {
				$file = MAD4B_SCP_DIR . $relative;
				if ( ! is_file( $file ) || ! is_readable( $file ) ) {
					$blockers[] = 'critical_file_unreadable:' . $relative;
					continue;
				}
				$hash = hash_file( 'sha256', $file );
				if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
					$blockers[] = 'critical_file_hash_failed:' . $relative;
					continue;
				}
				$critical_hashes[ $relative ] = $hash;
			}
		}
		ksort( $critical_hashes, SORT_STRING );
		$control_plane_build_fingerprint = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) && method_exists( 'MAD4B_SCP_External_Handshake_Evidence', 'build_fingerprint' )
			? strtolower( trim( (string) MAD4B_SCP_External_Handshake_Evidence::build_fingerprint() ) )
			: '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $control_plane_build_fingerprint ) ) $blockers[] = 'control_plane_build_fingerprint_unavailable';

		$payload = array(
			'contract' => self::PROVIDER_CONTRACT,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'control_plane_build_fingerprint' => $control_plane_build_fingerprint,
			'critical_files' => $critical_hashes,
			'rollback_contracts' => $contracts,
		);
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array(
			'contract' => self::PROVIDER_CONTRACT,
			'provider' => 'google_drive_context',
			'ready' => empty( $blockers ),
			'first_party' => true,
			'certification_mode' => 'runtime_structural_plus_build_bound_artifact_fingerprint',
			'control_plane_build_fingerprint' => $control_plane_build_fingerprint,
			'artifact_fingerprint' => is_string( $json ) ? hash( 'sha256', $json ) : '',
			'critical_files' => $critical_hashes,
			'rollback_contracts' => $contracts,
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public function capture_reversible_state( $ability_name, array $input ) {
		if ( 'context/update-drive-asset' === $ability_name ) {
			$asset_id = isset( $input['asset_id'] ) ? (string) $input['asset_id'] : '';
			$expected = isset( $input['expected_content_hash'] ) ? (string) $input['expected_content_hash'] : '';
			$state = MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id, $expected );
			if ( is_wp_error( $state ) ) return $state;
			$state = $this->bind_reversible_state_to_provider_contract( $state );
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
			$state = $this->bind_reversible_state_to_provider_contract( $state );
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
		if ( 'context/update-drive-asset' === $ability_name ) {
			$state = MAD4B_SCP_Google_Drive_Context::reversible_update_state( $asset_id );
			return is_wp_error( $state ) ? $state : $this->bind_reversible_state_to_provider_contract( $state );
		}
		if ( 'context/recreate-drive-asset' === $ability_name ) {
			$state = MAD4B_SCP_Google_Drive_Context::reversible_recreate_state( $asset_id );
			return is_wp_error( $state ) ? $state : $this->bind_reversible_state_to_provider_contract( $state );
		}
		return parent::read_reversible_state( $ability_name, $target );
	}

	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		if ( in_array( $ability_name, array( 'context/update-drive-asset', 'context/recreate-drive-asset' ), true ) ) {
			$guard = $this->validate_reversible_provider_binding( $state );
			if ( is_wp_error( $guard ) ) return $guard;
		}
		if ( 'context/update-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_update_state( $target, $state );
		if ( 'context/recreate-drive-asset' === $ability_name ) return MAD4B_SCP_Google_Drive_Context::restore_recreate_state( $target, $state );
		return parent::restore_reversible_state( $ability_name, $target, $state, $record );
	}

	private function bind_reversible_state_to_provider_contract( array $state ) {
		$provider = $this->context_provider_contract_status();
		if ( empty( $provider['ready'] ) ) {
			return new WP_Error(
				'mad4b_context_reversible_provider_contract_not_ready',
				'Context reversible state cannot be captured because the exact first-party provider contract is not ready.',
				array( 'blockers' => isset( $provider['blockers'] ) ? $provider['blockers'] : array() )
			);
		}
		$artifact = isset( $provider['artifact_fingerprint'] ) ? strtolower( trim( (string) $provider['artifact_fingerprint'] ) ) : '';
		$build = isset( $provider['control_plane_build_fingerprint'] ) ? strtolower( trim( (string) $provider['control_plane_build_fingerprint'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $artifact ) || ! preg_match( '/^[a-f0-9]{64}$/', $build ) ) {
			return new WP_Error( 'mad4b_context_reversible_provider_binding_invalid', 'Context reversible provider binding is incomplete.' );
		}
		$state['_mad4b_provider_binding'] = array(
			'contract' => self::PROVIDER_CONTRACT,
			'artifact_fingerprint' => $artifact,
			'control_plane_build_fingerprint' => $build,
		);
		return $state;
	}

	private function validate_reversible_provider_binding( array $state ) {
		$recorded = isset( $state['_mad4b_provider_binding'] ) && is_array( $state['_mad4b_provider_binding'] ) ? $state['_mad4b_provider_binding'] : array();
		$current = $this->context_provider_contract_status();
		if ( empty( $current['ready'] ) ) return new WP_Error( 'mad4b_context_undo_provider_contract_not_ready', 'Context undo is denied because the current provider contract is not ready.' );
		foreach ( array( 'artifact_fingerprint', 'control_plane_build_fingerprint' ) as $field ) {
			$before = isset( $recorded[ $field ] ) ? strtolower( trim( (string) $recorded[ $field ] ) ) : '';
			$now = isset( $current[ $field ] ) ? strtolower( trim( (string) $current[ $field ] ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $before ) || ! preg_match( '/^[a-f0-9]{64}$/', $now ) || ! hash_equals( $before, $now ) ) {
				return new WP_Error(
					'mad4b_context_undo_provider_contract_drift',
					'Context undo is denied because the first-party provider artifact/build no longer matches the mutation snapshot.',
					array( 'field' => $field )
				);
			}
		}
		return true;
	}

	public function context_status() {
		return MAD4B_SCP_Context_Authority::status();
	}

	public function google_drive_status() {
		return array(
			'connection' => MAD4B_SCP_Google_Drive_Context::public_connection_status(),
			'write_capability' => MAD4B_SCP_Google_Drive_Context::write_capability_status(),
			'provider_contract' => $this->context_provider_contract_status(),
		);
	}

	public function context_runtime_readiness() {
		return MAD4B_SCP_Google_Drive_Context::runtime_readiness();
	}

	public function list_assets( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mode = sanitize_key( isset( $input['mode'] ) ? (string) $input['mode'] : '' );
		$task_scope = isset( $input['task_scope'] ) ? substr( sanitize_text_field( (string) $input['task_scope'] ), 0, 160 ) : '';
		$status = sanitize_key( isset( $input['status'] ) ? (string) $input['status'] : '' );
		$category = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 100;
		if ( 'task_attachment' === $mode && '' === $task_scope ) {
			return new WP_Error(
				'mad4b_context_task_scope_required',
				'Task-scoped Context asset metadata requires the exact task_scope.'
			);
		}
		$items = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$asset_mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			if ( 'task_attachment' === $asset_mode ) {
				$asset_scope = isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '';
				if ( '' === $task_scope || '' === $asset_scope || ! hash_equals( $asset_scope, $task_scope ) ) continue;
			}
			if ( '' !== $mode && $mode !== $asset_mode ) continue;
			if ( '' !== $status && $status !== ( isset( $asset['status'] ) ? (string) $asset['status'] : '' ) ) continue;
			if ( '' !== $category && $category !== ( isset( $asset['category'] ) ? (string) $asset['category'] : '' ) ) continue;
			$current_content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_content_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			$review_binding_exact = 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) && preg_match( '/^[a-f0-9]{64}$/', $current_content_hash ) && preg_match( '/^[a-f0-9]{64}$/', $reviewed_content_hash ) && hash_equals( $current_content_hash, $reviewed_content_hash );
			$items[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'source_mode' => $asset_mode,
				'source_write_policy' => class_exists( 'MAD4B_SCP_Context_Authority' ) && ! empty( $asset['source_id'] ) ? MAD4B_SCP_Context_Authority::source_write_policy( (string) $asset['source_id'] ) : 'read_only',
				'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'required' => ! empty( $asset['required'] ),
				'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
				'quality_mode' => isset( $asset['quality']['mode'] ) ? (string) $asset['quality']['mode'] : '',
				'quality_confidence' => isset( $asset['quality']['confidence'] ) ? (float) $asset['quality']['confidence'] : null,
				'quality_provisional' => ! empty( $asset['quality']['provisional'] ),
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : 'unreviewed',
				'review_decision' => isset( $asset['review_decision'] ) ? (string) $asset['review_decision'] : '',
				'review_note' => isset( $asset['review_note'] ) ? (string) $asset['review_note'] : '',
				'reviewed_at' => isset( $asset['reviewed_at'] ) ? (string) $asset['reviewed_at'] : '',
				'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
				'review_binding_exact' => $review_binding_exact,
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'content_available' => ! empty( $asset['content_available'] ),
				'content_excerpt' => isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'classification_confidence' => isset( $asset['classification_confidence'] ) ? (float) $asset['classification_confidence'] : 0.0,
				'automatic_classification' => isset( $asset['automatic_classification'] ) && is_array( $asset['automatic_classification'] ) ? $asset['automatic_classification'] : array(),
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
		$status = method_exists( 'MAD4B_SCP_Context_Authority', 'status' ) ? MAD4B_SCP_Context_Authority::status() : array();
		return array(
			'contract' => 'mad4b.context-asset-list.v3',
			'items' => $items,
			'count' => count( $items ),
			'task_scope_bound' => '' !== $task_scope,
			'registry_revision' => isset( $status['registry_revision'] ) ? (int) $status['registry_revision'] : MAD4B_SCP_Context_Authority::registry_revision(),
			'context_fingerprint' => isset( $status['context_fingerprint'] ) ? (string) $status['context_fingerprint'] : MAD4B_SCP_Context_Authority::context_fingerprint(),
			'authority_manifest_fingerprint' => isset( $status['authority_manifest_fingerprint'] ) ? (string) $status['authority_manifest_fingerprint'] : MAD4B_SCP_Context_Authority::authority_manifest_fingerprint(),
		);
	}

	public function context_review_queue() {
		return MAD4B_SCP_Context_Authority::review_queue();
	}

	public function brand_core_coverage() {
		return MAD4B_SCP_Context_Authority::brand_core_coverage();
	}

	public function context_conflicts( $input ) {
		return MAD4B_SCP_Context_Intelligence::conflict_report( is_array( $input ) ? $input : array() );
	}

	public function context_reference_profile( $input ) {
		return MAD4B_SCP_Context_Intelligence::reference_profile( is_array( $input ) ? $input : array() );
	}

	public function context_retrieve( $input ) {
		return MAD4B_SCP_Context_Intelligence::retrieve( is_array( $input ) ? $input : array() );
	}

	public function context_compliance_check( $input ) {
		return MAD4B_SCP_Context_Intelligence::compliance_check( is_array( $input ) ? $input : array() );
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
