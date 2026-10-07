<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007 G2 read-only governance experience.
 *
 * This layer composes existing recovery, identity, OAuth and audit primitives.
 * It never creates grants, mounts tools, approves tickets, revokes identities,
 * executes undo, or changes provider resources.
 */
final class MAD4B_SCP_G2_Governance_Experience {
	const CONTRACT = 'mad4b.feature007-g2-governance-experience.v1';
	const MAX_HISTORY = 100;
	const MAX_SUBJECTS = 32;
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 31 );
	}

	public static function register_abilities() {
		self::register(
			'mad4b/recovery-preview',
			'Preview Governed Recovery',
			'Preview exact mutation reversal evidence and blockers without executing undo.',
			'recovery_preview',
			self::schema(
				array(
					'mutation_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'reason' => array( 'type' => 'string', 'maxLength' => 500, 'default' => '' ),
				),
				array( 'mutation_id' )
			)
		);
		self::register(
			'mad4b/agent-access-workspace',
			'Inspect Agent Access Workspace',
			'Inspect one enrolled agent, redacted subject bindings, effective access and recent activity without changing authority.',
			'agent_access_workspace',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'server_id' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
				),
				array( 'agent_public_id' )
			)
		);
		self::register(
			'mad4b/consent-profile-status',
			'Inspect Consent Profiles',
			'Inspect bounded OAuth/MCP consent presets and client compatibility evidence. New scopes always require external consent.',
			'consent_profile_status',
			self::schema(
				array(
					'client_hint' => array( 'type' => 'string', 'maxLength' => 128, 'default' => '' ),
				)
			)
		);
		self::register(
			'mad4b/change-history-search',
			'Search Governed Change History',
			'Search bounded redacted mutation history with evidence links and integrity digests. History never creates rollback authority.',
			'change_history_search',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'ability' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
					'target_type' => array( 'type' => 'string', 'maxLength' => 64, 'default' => '' ),
					'target_id' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
					'status' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
					'date_from' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
					'date_to' => array( 'type' => 'string', 'maxLength' => 32, 'default' => '' ),
					'before_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_HISTORY, 'default' => 25 ),
				)
			)
		);
	}

	private static function register( $name, $label, $description, $method, array $input_schema ) {
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
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function can_manage( $input = null ) {
		return current_user_can( 'manage_options' );
	}

	public static function recovery_preview( $input ) {
		$mutation_id = isset( $input['mutation_id'] ) ? trim( (string) $input['mutation_id'] ) : '';
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9-]{36,64}$/', $mutation_id ) ) {
			return new WP_Error( 'mad4b_g2_mutation_id_invalid', 'A valid mutation id is required.' );
		}
		$record = class_exists( 'MAD4B_SCP_Mutation_Manager' ) ? MAD4B_SCP_Mutation_Manager::get( $mutation_id ) : null;
		if ( ! is_array( $record ) ) return new WP_Error( 'mad4b_mutation_missing', 'Mutation record was not found.' );

		$blockers = array();
		$expired = empty( $record['undo_expires_at'] ) || strtotime( $record['undo_expires_at'] . ' UTC' ) < time();
		if ( empty( $record['reversible'] ) ) $blockers[] = 'mutation_not_reversible';
		if ( $expired ) $blockers[] = 'undo_window_expired';
		if ( 'undone' === (string) $record['status'] ) $blockers[] = 'mutation_already_undone';
		if ( ! in_array( (string) $record['status'], array( 'verified', 'verification_failed' ), true ) ) $blockers[] = 'mutation_status_not_recoverable';
		if ( empty( $record['before_sha256'] ) || empty( $record['after_sha256'] ) ) $blockers[] = 'before_after_readback_missing';

		$identity_state = 'unavailable';
		$same_agent = false;
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) {
			$blockers[] = 'identity_runtime_unavailable';
		} else {
			$identity = MAD4B_SCP_Identity_Context::current();
			if ( ! is_wp_error( $identity ) ) {
				$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
				if ( ! is_wp_error( $agent ) ) {
					$identity_state = 'resolved';
					$same_agent = (int) $agent['id'] === (int) $record['agent_id'];
					if ( ! $same_agent ) $blockers[] = 'different_agent_requires_explicit_recovery_policy';
				} else {
					$identity_state = 'unbound';
					$blockers[] = 'current_subject_not_bound_to_agent';
				}
			} else {
				$blockers[] = 'authenticated_subject_context_unavailable';
			}
		}

		$readback_state = 'provider_readback_required_at_execution';
		if ( 'post' === (string) $record['target_type'] && absint( $record['target_id'] ) > 0 ) {
			$post = get_post( absint( $record['target_id'] ) );
			if ( ! $post ) {
				$readback_state = 'target_missing';
				$blockers[] = 'current_target_missing';
			} else {
				$current = array(
					'post_title' => (string) $post->post_title,
					'post_content' => (string) $post->post_content,
					'post_excerpt' => (string) $post->post_excerpt,
					'post_status' => (string) $post->post_status,
				);
				$current_hash = hash( 'sha256', wp_json_encode( $current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				$readback_state = hash_equals( (string) $record['after_sha256'], $current_hash ) ? 'after_state_match' : 'state_drift';
				if ( 'state_drift' === $readback_state ) $blockers[] = 'current_state_drift';
			}
		}

		$descriptor = array(
			'contract' => 'mad4b.recovery-preview.v1',
			'mutation_id' => (string) $record['mutation_id'],
			'parent_mutation_id' => (string) $record['parent_mutation_id'],
			'ability' => (string) $record['ability_name'],
			'provider' => (string) $record['provider'],
			'target' => array( 'type' => (string) $record['target_type'], 'id' => (string) $record['target_id'] ),
			'status' => (string) $record['status'],
			'reversible' => ! empty( $record['reversible'] ),
			'before_sha256' => (string) $record['before_sha256'],
			'after_sha256' => (string) $record['after_sha256'],
			'undo_expires_at' => (string) $record['undo_expires_at'],
			'verification_code' => (string) $record['verification_code'],
			'error_code' => (string) $record['error_code'],
			'approval_ticket_sha256' => '' === (string) $record['approval_ticket_id'] ? '' : hash( 'sha256', (string) $record['approval_ticket_id'] ),
			'identity_state' => $identity_state,
			'same_agent' => $same_agent,
			'readback_state' => $readback_state,
			'blockers' => array_values( array_unique( $blockers ) ),
			'eligible_by_repository_evidence' => empty( $blockers ),
			'execution_available_here' => false,
			'execution_requires_existing_mutation_undo_authority' => true,
			'reason_sha256' => '' === $reason ? '' : hash( 'sha256', $reason ),
			'rollback_payload_exposed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$descriptor['plan_sha256'] = hash( 'sha256', wp_json_encode( $descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $descriptor;
	}

	public static function agent_access_workspace( $input ) {
		global $wpdb;
		$public_id = isset( $input['agent_public_id'] ) ? trim( (string) $input['agent_public_id'] ) : '';
		$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
		$agent = class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $public_id ) : null;
		if ( ! is_array( $agent ) ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );

		$effective = MAD4B_SCP_Governance_Abilities::agent_effective_access(
			array( 'agent_public_id' => $public_id, 'token_scopes' => array(), 'server_id' => $server_id )
		);
		if ( is_wp_error( $effective ) ) return $effective;

		$subjects = MAD4B_SCP_Agent_Registry::subjects_for_agent( (int) $agent['id'] );
		$redacted_subjects = array();
		foreach ( array_slice( is_array( $subjects ) ? $subjects : array(), 0, self::MAX_SUBJECTS ) as $subject ) {
			$fingerprint = isset( $subject['subject_fingerprint'] ) ? strtolower( (string) $subject['subject_fingerprint'] ) : '';
			$redacted_subjects[] = array(
				'type' => isset( $subject['subject_type'] ) ? sanitize_key( (string) $subject['subject_type'] ) : '',
				'label' => isset( $subject['label'] ) ? sanitize_text_field( (string) $subject['label'] ) : '',
				'status' => isset( $subject['status'] ) ? sanitize_key( (string) $subject['status'] ) : '',
				'fingerprint_hint' => preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? substr( $fingerprint, 0, 12 ) . '…' : '',
				'raw_identifier_exposed' => false,
			);
		}

		$t = MAD4B_SCP_Schema::tables();
		$activity = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS mutation_count, MAX(created_at) AS last_mutation_at FROM {$t['mutations']} WHERE agent_id = %d",
				(int) $agent['id']
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'contract' => 'mad4b.agent-access-workspace.v1',
			'agent' => isset( $effective['agent'] ) ? $effective['agent'] : array(),
			'subjects' => $redacted_subjects,
			'subject_count_returned' => count( $redacted_subjects ),
			'effective_access' => isset( $effective['effective'] ) ? $effective['effective'] : array(),
			'allowed_count' => isset( $effective['allowed_count'] ) ? (int) $effective['allowed_count'] : 0,
			'conditional_count' => isset( $effective['conditional_count'] ) ? (int) $effective['conditional_count'] : 0,
			'denied_count' => isset( $effective['denied_count'] ) ? (int) $effective['denied_count'] : 0,
			'recent_activity' => array(
				'mutation_count' => is_array( $activity ) ? (int) $activity['mutation_count'] : 0,
				'last_mutation_at' => is_array( $activity ) ? (string) $activity['last_mutation_at'] : '',
			),
			'permission_apply_available_here' => false,
			'wildcard_grants_allowed' => false,
			'automatic_tool_mounts_allowed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function consent_profile_status( $input = array() ) {
		$metadata = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::metadata() : array();
		$profiles = class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::profiles() : array();
		$client_hint = isset( $input['client_hint'] ) ? sanitize_text_field( (string) $input['client_hint'] ) : '';
		$detected = class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' )
			? MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile( null, $client_hint )
			: array();

		$presets = array(
			array(
				'id' => 'read_only',
				'label' => 'Read-only',
				'scopes' => array( 'mad4b:read', 'offline_access' ),
				'default' => true,
				'requires_external_reconsent' => false,
				'mutation_authority' => false,
			),
			array(
				'id' => 'reviewed_step_up',
				'label' => 'Reviewed step-up',
				'scopes' => array( 'mad4b:read', 'mad4b:authority:step-up', 'offline_access' ),
				'default' => false,
				'requires_external_reconsent' => true,
				'mutation_authority' => false,
			),
		);
		$basis = array(
			'supported_scopes' => isset( $metadata['scopes_supported'] ) ? $metadata['scopes_supported'] : array(),
			'profiles' => $profiles,
			'presets' => $presets,
		);
		$generation = hash( 'sha256', wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'contract' => 'mad4b.consent-profile-status.v1',
			'generation_sha256' => $generation,
			'presets' => $presets,
			'detected_client' => is_array( $detected ) ? $detected : array(),
			'client_profiles' => is_array( $profiles ) ? $profiles : array(),
			'protocol' => array(
				'pkce_methods' => isset( $metadata['code_challenge_methods_supported'] ) ? $metadata['code_challenge_methods_supported'] : array(),
				'grant_types' => isset( $metadata['grant_types_supported'] ) ? $metadata['grant_types_supported'] : array(),
				'revocation_endpoint_present' => ! empty( $metadata['revocation_endpoint'] ),
				'client_metadata_document_supported' => ! empty( $metadata['client_id_metadata_document_supported'] ),
				'dynamic_client_registration_exposed' => false,
				'refresh_token_rotation_expected' => true,
			),
			'exceptional_scopes' => array( 'server:mad4b-breakglass', 'server:mad4b-developer', 'server:mad4b-developer-breakglass' ),
			'exceptional_scopes_in_presets' => false,
			'new_scopes_require_external_consent' => true,
			'generic_full_access_supported' => false,
			'request_time_scope_revalidation_required' => true,
			'request_time_subject_revalidation_required' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function change_history_search( $input = array() ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$limit = isset( $input['limit'] ) ? max( 1, min( self::MAX_HISTORY, absint( $input['limit'] ) ) ) : 25;
		$where = array( '1=1' );
		$args = array();

		$agent_public_id = isset( $input['agent_public_id'] ) ? trim( (string) $input['agent_public_id'] ) : '';
		if ( '' !== $agent_public_id ) {
			$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id );
			if ( ! is_array( $agent ) ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
			$where[] = 'm.agent_id = %d';
			$args[] = (int) $agent['id'];
		}
		foreach ( array( 'ability' => 'ability_name', 'target_type' => 'target_type', 'target_id' => 'target_id', 'status' => 'status' ) as $input_key => $column ) {
			$value = isset( $input[ $input_key ] ) ? trim( (string) $input[ $input_key ] ) : '';
			if ( '' !== $value ) {
				$where[] = "m.{$column} = %s";
				$args[] = $value;
			}
		}
		$date_from = isset( $input['date_from'] ) ? trim( (string) $input['date_from'] ) : '';
		$date_to = isset( $input['date_to'] ) ? trim( (string) $input['date_to'] ) : '';
		if ( '' !== $date_from ) { $where[] = 'm.created_at >= %s'; $args[] = $date_from; }
		if ( '' !== $date_to ) { $where[] = 'm.created_at <= %s'; $args[] = $date_to; }
		$before_id = isset( $input['before_id'] ) ? absint( $input['before_id'] ) : 0;
		if ( $before_id > 0 ) { $where[] = 'm.id < %d'; $args[] = $before_id; }

		$sql = "SELECT m.id,m.mutation_id,m.parent_mutation_id,m.request_id,m.agent_id,m.subject_type,m.subject_fingerprint,m.server_id,m.ability_name,m.provider,m.target_type,m.target_id,m.approval_ticket_id,m.impact,m.status,m.reversible,m.before_sha256,m.after_sha256,m.undo_expires_at,m.verification_code,m.error_code,m.created_at,m.updated_at,a.public_id AS agent_public_id,a.slug AS agent_slug,a.label AS agent_label
			FROM {$t['mutations']} m
			LEFT JOIN {$t['agents']} a ON a.id=m.agent_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY m.id DESC LIMIT %d';
		$args[] = $limit + 1;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) $rows = array_slice( $rows, 0, $limit );

		$items = array();
		$next_before_id = 0;
		foreach ( $rows as $row ) {
			$next_before_id = (int) $row['id'];
			$fingerprint = strtolower( (string) $row['subject_fingerprint'] );
			$items[] = array(
				'mutation_id' => (string) $row['mutation_id'],
				'parent_mutation_id' => (string) $row['parent_mutation_id'],
				'request_id' => (string) $row['request_id'],
				'agent' => array(
					'public_id' => (string) $row['agent_public_id'],
					'slug' => (string) $row['agent_slug'],
					'label' => (string) $row['agent_label'],
				),
				'subject' => array(
					'type' => (string) $row['subject_type'],
					'fingerprint_hint' => preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? substr( $fingerprint, 0, 12 ) . '…' : '',
				),
				'operation' => array(
					'server_id' => (string) $row['server_id'],
					'ability' => (string) $row['ability_name'],
					'provider' => (string) $row['provider'],
					'target_type' => (string) $row['target_type'],
					'target_id' => (string) $row['target_id'],
					'impact' => (string) $row['impact'],
					'status' => (string) $row['status'],
				),
				'diff' => array(
					'before_sha256' => (string) $row['before_sha256'],
					'after_sha256' => (string) $row['after_sha256'],
					'changed' => '' !== (string) $row['before_sha256'] && ! hash_equals( (string) $row['before_sha256'], (string) $row['after_sha256'] ),
					'raw_private_content_exposed' => false,
				),
				'evidence' => array(
					'approval_ticket_sha256' => '' === (string) $row['approval_ticket_id'] ? '' : hash( 'sha256', (string) $row['approval_ticket_id'] ),
					'verification_code' => (string) $row['verification_code'],
					'error_code' => (string) $row['error_code'],
					'reversible' => ! empty( $row['reversible'] ),
					'undo_expires_at' => (string) $row['undo_expires_at'],
				),
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
			);
		}
		$export_basis = array(
			'contract' => 'mad4b.change-history-export-evidence.v1',
			'site_scope_sha256' => hash( 'sha256', untrailingslashit( site_url() ) ),
			'items' => $items,
		);
		return array(
			'contract' => 'mad4b.change-history-search.v1',
			'items' => $items,
			'count' => count( $items ),
			'has_more' => $has_more,
			'next_before_id' => $has_more ? $next_before_id : 0,
			'site_scope_sha256' => $export_basis['site_scope_sha256'],
			'export_sha256' => hash( 'sha256', wp_json_encode( $export_basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'audit_chain_ready' => class_exists( 'MAD4B_SCP_Audit' ) ? (bool) MAD4B_SCP_Audit::verify_chain() : false,
			'history_is_rollback_authority' => false,
			'rollback_payload_exposed' => false,
			'secret_or_token_values_exposed' => false,
			'legal_hold_supported' => false,
			'retention_policy_evidence' => class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array(),
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}
}
