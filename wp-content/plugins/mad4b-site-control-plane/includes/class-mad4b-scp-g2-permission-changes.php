<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007 G2 reviewed authority-reduction workflow.
 *
 * This component can only reduce existing NHI authority. It cannot create,
 * widen, infer, restore, or auto-mount authority.
 */
final class MAD4B_SCP_G2_Permission_Changes {
	const CONTRACT = 'mad4b.feature007-g2-permission-changes.v1';
	const CONFIRMATION = 'APPLY_AUTHORITY_REDUCTION';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 32 );
	}

	public static function register_abilities() {
		self::register(
			'mad4b/agent-permission-plan',
			'Plan Agent Permission Reduction',
			'Plan one exact authority-reducing agent change without mutating authority.',
			'plan',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'operation' => array( 'type' => 'string', 'enum' => array( 'disable_agent', 'disable_subject', 'revoke_allow_grant' ) ),
					'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
					'server_id' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'grant_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'subject_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'subject_fingerprint' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				),
				array( 'agent_public_id', 'operation', 'expected_revision', 'reason' )
			),
			true,
			false,
			true
		);
		self::register(
			'mad4b/agent-permission-apply',
			'Apply Agent Permission Reduction',
			'Apply one exact previously reviewed authority-reducing change and verify readback.',
			'apply',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'operation' => array( 'type' => 'string', 'enum' => array( 'disable_agent', 'disable_subject', 'revoke_allow_grant' ) ),
					'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
					'server_id' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'grant_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'subject_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'subject_fingerprint' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
					'plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
					'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
				),
				array( 'agent_public_id', 'operation', 'expected_revision', 'reason', 'plan_sha256', 'confirmation' )
			),
			false,
			true,
			false
		);
	}

	private static function register( $name, $label, $description, $method, array $input_schema, $readonly, $destructive, $idempotent ) {
		if ( wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $description,
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'input_schema' => $input_schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => (bool) $destructive,
						'idempotent' => (bool) $idempotent,
					),
				),
			)
		);
	}

	public static function can_manage( $input = null ) {
		return current_user_can( 'manage_options' );
	}

	public static function plan( $input ) {
		$normalized = self::normalize_input( $input );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $normalized['agent_public_id'] );
		if ( ! is_array( $agent ) ) return new WP_Error( 'mad4b_g2_agent_missing', 'Agent not found.' );
		if ( (int) $agent['revision'] !== (int) $normalized['expected_revision'] ) {
			return new WP_Error( 'mad4b_g2_permission_plan_stale', 'Agent configuration changed since the requested revision was read.' );
		}

		$blockers = array();
		$target = array();
		$readback_contract = '';
		if ( 'disable_agent' === $normalized['operation'] ) {
			if ( 'enabled' !== (string) $agent['status'] ) $blockers[] = 'agent_not_enabled';
			$target = array(
				'agent_public_id' => (string) $agent['public_id'],
				'current_status' => (string) $agent['status'],
				'expected_status' => 'disabled',
			);
			$readback_contract = 'agent_status_disabled_revision_incremented';
		} elseif ( 'disable_subject' === $normalized['operation'] ) {
			if ( '' === $normalized['subject_type'] || ! preg_match( '/^[a-f0-9]{64}$/', $normalized['subject_fingerprint'] ) ) {
				return new WP_Error( 'mad4b_g2_subject_target_invalid', 'Exact subject type and SHA-256 fingerprint are required.' );
			}
			$binding = MAD4B_SCP_Agent_Registry::subject_binding( $normalized['subject_type'], $normalized['subject_fingerprint'] );
			if ( is_wp_error( $binding ) ) return $binding;
			if ( ! is_array( $binding ) ) $blockers[] = 'subject_binding_missing';
			elseif ( (int) $binding['agent_id'] !== (int) $agent['id'] ) $blockers[] = 'subject_bound_to_different_agent';
			elseif ( 'enabled' !== (string) $binding['status'] ) $blockers[] = 'subject_not_enabled';
			$target = array(
				'subject_type' => $normalized['subject_type'],
				'subject_fingerprint_hint' => substr( $normalized['subject_fingerprint'], 0, 12 ) . '…',
				'subject_binding_sha256' => hash( 'sha256', $normalized['subject_type'] . ':' . $normalized['subject_fingerprint'] ),
				'current_status' => is_array( $binding ) ? (string) $binding['status'] : 'missing',
				'expected_status' => 'disabled',
			);
			$readback_contract = 'subject_binding_disabled_for_same_agent';
		} elseif ( 'revoke_allow_grant' === $normalized['operation'] ) {
			if ( $normalized['grant_id'] < 1 ) return new WP_Error( 'mad4b_g2_grant_target_invalid', 'Exact allow grant id is required.' );
			$grant = null;
			foreach ( MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $agent['id'], $normalized['server_id'] ) as $row ) {
				if ( (int) $row['id'] === $normalized['grant_id'] ) { $grant = $row; break; }
			}
			if ( ! is_array( $grant ) ) $blockers[] = 'grant_missing';
			else {
				if ( 'allow' !== (string) $grant['effect'] ) $blockers[] = 'grant_is_not_allow';
				if ( '' !== $normalized['server_id'] && $normalized['server_id'] !== (string) $grant['server_id'] ) $blockers[] = 'grant_server_mismatch';
				$constraints = isset( $grant['resource_constraints'] ) ? (string) $grant['resource_constraints'] : '';
				$target = array(
					'grant_id' => (int) $grant['id'],
					'server_id' => (string) $grant['server_id'],
					'ability' => (string) $grant['ability_name'],
					'provider' => (string) $grant['provider'],
					'environment' => (string) $grant['environment'],
					'effect' => (string) $grant['effect'],
					'resource_schema_version' => isset( $grant['resource_schema_version'] ) ? (string) $grant['resource_schema_version'] : '',
					'resource_constraints_sha256' => hash( 'sha256', $constraints ),
				);
			}
			$readback_contract = 'exact_allow_grant_absent';
		}

		$basis = array(
			'contract' => 'mad4b.agent-permission-reduction-plan.v1',
			'agent_public_id' => (string) $agent['public_id'],
			'agent_revision' => (int) $agent['revision'],
			'operation' => $normalized['operation'],
			'target' => $target,
			'reason_sha256' => hash( 'sha256', $normalized['reason'] ),
			'readback_contract' => $readback_contract,
			'blockers' => array_values( array_unique( $blockers ) ),
			'authority_expansion' => false,
			'grant_creation_allowed' => false,
			'enable_or_restore_allowed' => false,
			'automatic_tool_mounts_allowed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$basis['eligible'] = empty( $basis['blockers'] );
		$basis['plan_sha256'] = hash( 'sha256', wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $basis;
	}

	public static function apply( $input ) {
		$normalized = self::normalize_input( $input );
		if ( is_wp_error( $normalized ) ) return $normalized;
		if ( ! isset( $input['confirmation'] ) || self::CONFIRMATION !== (string) $input['confirmation'] ) {
			return new WP_Error( 'mad4b_g2_permission_confirmation_required', 'Explicit authority-reduction confirmation is required.' );
		}
		$expected_plan = isset( $input['plan_sha256'] ) ? strtolower( trim( (string) $input['plan_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_plan ) ) return new WP_Error( 'mad4b_g2_permission_plan_invalid', 'Exact plan SHA-256 is required.' );

		$plan = self::plan( $normalized );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_g2_permission_plan_blocked', 'Permission reduction plan has blockers.', array( 'blockers' => $plan['blockers'] ) );
		if ( ! hash_equals( (string) $plan['plan_sha256'], $expected_plan ) ) {
			return new WP_Error( 'mad4b_g2_permission_plan_drift', 'Permission reduction plan changed since review.' );
		}
		$audit_status = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array();
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( 'mad4b_g2_permission_audit_unavailable', 'Append-only audit storage must be ready before authority reduction.' );

		$before_revision = (int) $plan['agent_revision'];
		if ( 'disable_agent' === $normalized['operation'] ) {
			$result = MAD4B_SCP_Agent_Registry::disable_agent( $normalized['agent_public_id'], $before_revision );
		} elseif ( 'disable_subject' === $normalized['operation'] ) {
			$result = MAD4B_SCP_Agent_Registry::set_subject_status(
				$normalized['agent_public_id'],
				$normalized['subject_type'],
				$normalized['subject_fingerprint'],
				'disabled'
			);
		} else {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(
				$normalized['agent_public_id'],
				$normalized['grant_id'],
				$normalized['server_id']
			);
		}
		if ( is_wp_error( $result ) ) return $result;

		$readback = self::readback( $normalized, $before_revision );
		if ( is_wp_error( $readback ) ) return $readback;
		if ( empty( $readback['verified'] ) ) {
			return new WP_Error(
				'mad4b_g2_permission_readback_failed',
				'Authority reduction completed but exact readback did not prove the expected state.',
				array( 'readback' => $readback, 'mutation_performed' => true )
			);
		}

		$audit = MAD4B_SCP_Audit::record(
			'mad4b/agent-permission-apply',
			array(
				'contract' => self::CONTRACT,
				'agent_public_id' => $normalized['agent_public_id'],
				'operation' => $normalized['operation'],
				'plan_sha256' => $expected_plan,
				'authority_expansion' => false,
				'readback_sha256' => $readback['readback_sha256'],
			),
			'ok'
		);
		if ( is_wp_error( $audit ) ) {
			return new WP_Error(
				'mad4b_g2_permission_audit_failed_after_mutation',
				'Authority reduction verified but append-only audit persistence failed.',
				array( 'mutation_performed' => true, 'readback' => $readback )
			);
		}

		return array(
			'contract' => 'mad4b.agent-permission-reduction-apply.v1',
			'operation' => $normalized['operation'],
			'agent_public_id' => $normalized['agent_public_id'],
			'plan_sha256' => $expected_plan,
			'readback' => $readback,
			'audit_recorded' => true,
			'authority_reducing' => true,
			'authority_expansion' => false,
			'grant_creation_performed' => false,
			'enable_or_restore_performed' => false,
			'automatic_tool_mounts_performed' => false,
			'authorizing' => false,
			'mutation_performed' => true,
		);
	}

	private static function readback( array $normalized, $before_revision ) {
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $normalized['agent_public_id'] );
		if ( ! is_array( $agent ) ) return new WP_Error( 'mad4b_g2_agent_missing_after_apply', 'Agent disappeared during readback.' );
		$verified = false;
		$state = array();
		if ( 'disable_agent' === $normalized['operation'] ) {
			$verified = 'disabled' === (string) $agent['status'] && (int) $agent['revision'] === (int) $before_revision + 1;
			$state = array( 'status' => (string) $agent['status'], 'revision' => (int) $agent['revision'] );
		} elseif ( 'disable_subject' === $normalized['operation'] ) {
			$binding = MAD4B_SCP_Agent_Registry::subject_binding( $normalized['subject_type'], $normalized['subject_fingerprint'] );
			$verified = is_array( $binding ) && (int) $binding['agent_id'] === (int) $agent['id'] && 'disabled' === (string) $binding['status'];
			$state = array(
				'subject_type' => $normalized['subject_type'],
				'subject_fingerprint_hint' => substr( $normalized['subject_fingerprint'], 0, 12 ) . '…',
				'status' => is_array( $binding ) ? (string) $binding['status'] : 'missing',
			);
		} else {
			$found = false;
			foreach ( MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $agent['id'], $normalized['server_id'] ) as $row ) {
				if ( (int) $row['id'] === $normalized['grant_id'] && 'allow' === (string) $row['effect'] ) { $found = true; break; }
			}
			$verified = ! $found;
			$state = array( 'grant_id' => $normalized['grant_id'], 'allow_grant_present' => $found );
		}
		$readback = array(
			'operation' => $normalized['operation'],
			'verified' => (bool) $verified,
			'state' => $state,
		);
		$readback['readback_sha256'] = hash( 'sha256', wp_json_encode( $readback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $readback;
	}

	private static function normalize_input( $input ) {
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_g2_permission_input_invalid', 'Permission change input must be an object.' );
		$public_id = isset( $input['agent_public_id'] ) ? trim( (string) $input['agent_public_id'] ) : '';
		$operation = isset( $input['operation'] ) ? sanitize_key( (string) $input['operation'] ) : '';
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9-]{36,64}$/', $public_id ) ) return new WP_Error( 'mad4b_g2_agent_id_invalid', 'Valid agent public id is required.' );
		if ( ! in_array( $operation, array( 'disable_agent', 'disable_subject', 'revoke_allow_grant' ), true ) ) return new WP_Error( 'mad4b_g2_permission_operation_invalid', 'Unknown authority-reduction operation.' );
		if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) return new WP_Error( 'mad4b_g2_permission_reason_invalid', 'A bounded review reason is required.' );
		$fingerprint = isset( $input['subject_fingerprint'] ) ? strtolower( trim( (string) $input['subject_fingerprint'] ) ) : '';
		return array(
			'agent_public_id' => $public_id,
			'operation' => $operation,
			'expected_revision' => isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : 0,
			'server_id' => isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '',
			'grant_id' => isset( $input['grant_id'] ) ? absint( $input['grant_id'] ) : 0,
			'subject_type' => isset( $input['subject_type'] ) ? sanitize_key( (string) $input['subject_type'] ) : '',
			'subject_fingerprint' => $fingerprint,
			'reason' => $reason,
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}
}
