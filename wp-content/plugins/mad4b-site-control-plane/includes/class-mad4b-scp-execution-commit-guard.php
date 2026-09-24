<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007 commit-time material dependency guard.
 *
 * This class does not create authority. It snapshots already-authorized
 * execution dependencies after an approval is claimed, then re-reads the same
 * dependencies immediately before the mutation callback is entered.
 */
final class MAD4B_SCP_Execution_Commit_Guard {
	const CONTRACT = 'mad4b.execution-commit-guard.v1';
	const RECEIPT_CONTRACT = 'mad4b.execution-commit-guard-receipt.v1';

	public static function capture( array $claim, $input = null ) {
		$material = self::material_snapshot( $claim, $input );
		if ( is_wp_error( $material ) ) return $material;
		return array(
			'contract' => self::CONTRACT,
			'captured_at' => gmdate( 'c' ),
			'material' => $material,
			'material_sha256' => self::stable_digest( $material ),
		);
	}

	public static function revalidate( array $claim, $input = null ) {
		$expected = isset( $claim['commit_guard_snapshot'] ) && is_array( $claim['commit_guard_snapshot'] )
			? $claim['commit_guard_snapshot']
			: array();
		if ( self::CONTRACT !== ( isset( $expected['contract'] ) ? (string) $expected['contract'] : '' )
			|| empty( $expected['material'] )
			|| empty( $expected['material_sha256'] ) ) {
			return self::error( 'DENIED', 'Execution Commit Guard snapshot is missing or invalid.' );
		}
		if ( ! hash_equals( (string) $expected['material_sha256'], self::stable_digest( $expected['material'] ) ) ) {
			return self::error( 'DENIED', 'Execution Commit Guard snapshot integrity check failed.' );
		}

		$current = self::material_snapshot( $claim, $input );
		if ( is_wp_error( $current ) ) return $current;
		$expected_material = $expected['material'];

		if ( ! empty( $current['kill_switch']['active'] ) ) {
			return self::error( 'KILL_SWITCHED', 'Execution kill switch became active before commit.' );
		}
		if ( ! self::same( $expected_material, $current, 'plan' ) ) {
			return self::error( 'REPLAN_REQUIRED', 'Execution plan binding changed before commit.' );
		}
		if ( ! self::same( $expected_material, $current, 'target' ) ) {
			return self::error( 'TARGET_CHANGED', 'Authoritative target state changed before commit.' );
		}
		if ( ! self::same( $expected_material, $current, 'provider' ) ) {
			return self::error( 'RECERTIFICATION_REQUIRED', 'Provider capability or artifact evidence changed before commit.' );
		}
		foreach ( array( 'grant', 'approval', 'policy', 'authority', 'site_profile', 'candidate', 'kill_switch', 'rights', 'data_processing' ) as $dependency ) {
			if ( ! self::same( $expected_material, $current, $dependency ) ) {
				return self::error( 'REAPPROVAL_REQUIRED', 'A material authorization dependency changed before commit.', array( 'dependency' => $dependency ) );
			}
		}

		$current_sha = self::stable_digest( $current );
		if ( ! hash_equals( (string) $expected['material_sha256'], $current_sha ) ) {
			return self::error( 'DENIED', 'An unclassified material execution dependency changed before commit.' );
		}

		return array(
			'contract' => self::RECEIPT_CONTRACT,
			'verdict' => 'COMMIT_ALLOWED',
			'checked_at' => gmdate( 'c' ),
			'material_sha256' => $current_sha,
			'point_of_no_return' => 'governed_ability_execute_callback_entry',
			'authorizing' => false,
		);
	}

	private static function material_snapshot( array $claim, $input ) {
		$ability = isset( $claim['ability'] ) ? (string) $claim['ability'] : '';
		$provider = isset( $claim['provider'] ) ? sanitize_key( (string) $claim['provider'] ) : 'core';
		$server_id = isset( $claim['server_id'] ) ? sanitize_key( (string) $claim['server_id'] ) : '';
		$agent_public_id = isset( $claim['agent_public_id'] ) ? strtolower( trim( (string) $claim['agent_public_id'] ) ) : '';
		if ( '' === $ability || '' === $server_id || '' === $agent_public_id ) {
			return self::error( 'DENIED', 'Execution claim is missing stable ability/server/agent identity.' );
		}

		$authorization_input = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			? MAD4B_SCP_Staging_Write_Authority::authorization_input( $input )
			: $input;

		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return self::error( 'DENIED', 'Authenticated subject identity is unavailable at commit guard.' );

		$agent = class_exists( 'MAD4B_SCP_Agent_Registry' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent_public_id ) : null;
		if ( ! is_array( $agent ) || empty( $agent['id'] ) || 'enabled' !== (string) $agent['status'] ) {
			return self::error( 'REAPPROVAL_REQUIRED', 'Authorized agent is no longer enabled.' );
		}
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability, $provider );
		if ( is_wp_error( $grant ) ) return self::error( 'REAPPROVAL_REQUIRED', 'Exact grant is no longer valid.', array( 'cause' => $grant->get_error_code() ) );

		$approval = self::approval_material( $claim );
		if ( is_wp_error( $approval ) ) return $approval;
		$profile = self::site_profile_material();
		if ( is_wp_error( $profile ) ) return $profile;
		$candidate = self::candidate_material();
		if ( is_wp_error( $candidate ) ) return $candidate;
		$target = self::target_material( $ability, $provider, $authorization_input, $claim );
		if ( is_wp_error( $target ) ) return $target;
		$provider_material = self::provider_material( $ability, $provider );
		if ( is_wp_error( $provider_material ) ) return $provider_material;
		$kill_switch = self::kill_switch_material( $ability, $provider, $claim, $authorization_input );
		if ( is_wp_error( $kill_switch ) ) return $kill_switch;
		if ( ! empty( $kill_switch['active'] ) ) return self::error( 'KILL_SWITCHED', 'Execution kill switch is active.' );

		$policy = array(
			'mutation_global_enabled' => defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED,
			'can_mutate' => class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_mutate(),
			'developer_allowed' => 'mad4b-developer' === $server_id ? MAD4B_SCP_Policy::can_developer() : null,
			'developer_breakglass_allowed' => 'mad4b-developer-breakglass' === $server_id ? MAD4B_SCP_Policy::can_developer_breakglass() : null,
			'breakglass_allowed' => 'mad4b-breakglass' === $server_id ? MAD4B_SCP_Policy::can_breakglass() : null,
			'site_write_enabled' => class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured()
				? MAD4B_SCP_Site_Profile::write_enabled()
				: null,
		);
		if ( empty( $policy['mutation_global_enabled'] ) || empty( $policy['can_mutate'] ) ) {
			return self::error( 'REAPPROVAL_REQUIRED', 'Mutation policy is no longer effective at commit guard.' );
		}
		foreach ( array( 'developer_allowed', 'developer_breakglass_allowed', 'breakglass_allowed' ) as $special ) {
			if ( false === $policy[ $special ] ) return self::error( 'REAPPROVAL_REQUIRED', 'Special execution policy is no longer effective.', array( 'dependency' => $special ) );
		}

		$authority = array(
			'subject_type' => isset( $identity['subject_type'] ) ? (string) $identity['subject_type'] : '',
			'subject_fingerprint' => isset( $identity['subject_fingerprint'] ) ? (string) $identity['subject_fingerprint'] : '',
			'issuer_fingerprint' => isset( $identity['issuer_fingerprint'] ) ? (string) $identity['issuer_fingerprint'] : '',
			'client_fingerprint' => isset( $identity['client_fingerprint'] ) ? (string) $identity['client_fingerprint'] : '',
			'auth_method' => isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '',
			'wp_user_id' => isset( $identity['wp_user_id'] ) ? (int) $identity['wp_user_id'] : 0,
			'agent_public_id' => $agent_public_id,
			'agent_fingerprint' => self::stable_digest( self::selected_fields( $agent, array( 'id', 'public_id', 'status', 'environment', 'role' ) ) ),
		);
		if ( isset( $claim['subject_fingerprint'] ) && ! hash_equals( (string) $claim['subject_fingerprint'], (string) $authority['subject_fingerprint'] ) ) {
			return self::error( 'REAPPROVAL_REQUIRED', 'Authenticated subject changed before commit.' );
		}
		if ( class_exists( 'MAD4B_SCP_Multi_Authority_Registry' ) ) {
			$registry = MAD4B_SCP_Multi_Authority_Registry::snapshot();
			$authority['authority_snapshot_sha256'] = is_array( $registry ) && isset( $registry['authority_snapshot_sha256'] )
				? (string) $registry['authority_snapshot_sha256']
				: '';
		}

		$rights = apply_filters( 'mad4b_scp_execution_commit_guard_rights_dependencies', array(), $ability, $provider, $authorization_input, $claim );
		$data_processing = apply_filters( 'mad4b_scp_execution_commit_guard_data_processing_dependencies', array(), $ability, $provider, $authorization_input, $claim );
		if ( ! is_array( $rights ) || ! is_array( $data_processing ) ) return self::error( 'DENIED', 'Rights or data-processing dependency projection is invalid.' );

		$plan_sha = '';
		if ( isset( $approval['payload_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $approval['payload_sha256'] ) ) {
			$plan_sha = (string) $approval['payload_sha256'];
		} else {
			$plan_sha = self::stable_digest( array(
				'ability' => $ability,
				'provider' => $provider,
				'server_id' => $server_id,
				'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? (string) $claim['target_fingerprint'] : '',
			) );
		}
		$filtered_plan = apply_filters( 'mad4b_scp_execution_commit_guard_plan_sha256', $plan_sha, $ability, $provider, $authorization_input, $claim );
		if ( ! is_string( $filtered_plan ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( $filtered_plan ) ) ) {
			return self::error( 'REPLAN_REQUIRED', 'Execution plan SHA-256 is unavailable or malformed.' );
		}

		return array(
			'plan' => array( 'plan_sha256' => strtolower( $filtered_plan ) ),
			'target' => $target,
			'grant' => array(
				'grant_id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
				'grant_fingerprint' => self::stable_digest( $grant ),
			),
			'approval' => $approval,
			'policy' => $policy,
			'authority' => $authority,
			'site_profile' => $profile,
			'candidate' => $candidate,
			'provider' => $provider_material,
			'kill_switch' => $kill_switch,
			'rights' => empty( $rights ) ? array( 'state' => 'not_applicable' ) : self::canonicalize( $rights ),
			'data_processing' => empty( $data_processing ) ? array( 'state' => 'not_applicable' ) : self::canonicalize( $data_processing ),
		);
	}

	private static function approval_material( array $claim ) {
		if ( empty( $claim['approval_required'] ) ) return array( 'required' => false, 'state' => 'not_required' );
		$ticket_id = isset( $claim['approval_ticket_id'] ) ? strtolower( trim( (string) $claim['approval_ticket_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $ticket_id ) || ! class_exists( 'MAD4B_SCP_Approval_Tickets' ) ) {
			return self::error( 'REAPPROVAL_REQUIRED', 'Claimed approval ticket is unavailable.' );
		}
		$ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_id );
		if ( ! is_array( $ticket ) || 'executing' !== (string) $ticket['status'] ) {
			return self::error( 'REAPPROVAL_REQUIRED', 'Approval ticket is not in the expected executing state.' );
		}
		$expires = ! empty( $ticket['expires_at'] ) ? strtotime( (string) $ticket['expires_at'] . ' UTC' ) : false;
		if ( false === $expires || $expires < time() ) return self::error( 'REAPPROVAL_REQUIRED', 'Approval ticket expired before commit.' );
		return array(
			'required' => true,
			'ticket_id' => $ticket_id,
			'status' => 'executing',
			'payload_sha256' => isset( $ticket['payload_sha256'] ) ? (string) $ticket['payload_sha256'] : '',
			'ticket_class' => isset( $ticket['ticket_class'] ) ? (string) $ticket['ticket_class'] : '',
			'expires_at' => isset( $ticket['expires_at'] ) ? (string) $ticket['expires_at'] : '',
			'candidate_binding' => MAD4B_SCP_Approval_Tickets::candidate_binding_from_ticket( $ticket ),
			'decision_fingerprint' => self::stable_digest( self::selected_fields( $ticket, array(
				'ticket_id', 'ticket_class', 'agent_id', 'server_id', 'ability_name', 'provider',
				'target_fingerprint', 'payload_sha256', 'status', 'approved_by', 'approved_at',
				'expires_at', 'candidate_binding_contract', 'candidate_sha', 'build_fingerprint',
				'binding_environment', 'binding_host', 'site_uuid', 'site_profile_revision',
				'site_profile_digest', 'bound_at',
			) ) ),
		);
	}

	private static function site_profile_material() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array( 'configured' => false, 'state' => 'not_available' );
		$status = MAD4B_SCP_Site_Profile::status();
		return array(
			'configured' => ! empty( $status['configured'] ),
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'revision' => MAD4B_SCP_Site_Profile::revision(),
			'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'origin' => MAD4B_SCP_Site_Profile::current_origin(),
		);
	}

	private static function candidate_material() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' )
			|| ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) {
			return array( 'state' => 'not_available' );
		}
		$status = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $status ) ) return self::error( 'REAPPROVAL_REQUIRED', 'Runtime candidate provenance is unavailable.' );
		return array(
			'version' => isset( $status['version'] ) ? (string) $status['version'] : '',
			'source_commit_sha' => isset( $status['source_commit_sha'] ) ? (string) $status['source_commit_sha'] : '',
			'build_fingerprint' => isset( $status['build_fingerprint'] ) ? (string) $status['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $status['package_manifest_digest'] ) ? (string) $status['package_manifest_digest'] : '',
			'runtime_manifest_match' => ! empty( $status['runtime_manifest_match'] ),
			'stale' => ! empty( $status['stale'] ),
		);
	}

	private static function provider_material( $ability, $provider ) {
		if ( 'core' === $provider ) return array( 'provider_id' => 'core', 'certification_source' => 'release_candidate', 'state' => 'release_bound' );
		if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
			|| ! MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider ) ) {
			return array(
				'provider_id' => $provider,
				'state' => 'legacy_unmanaged',
				'fingerprint' => self::stable_digest( array( 'provider' => $provider, 'ability' => (string) $ability ) ),
			);
		}
		$cert = MAD4B_SCP_Provider_Compatibility_Certification::capability_certification( array(
			'provider_id' => $provider,
			'ability' => (string) $ability,
		) );
		if ( ! is_array( $cert ) ) return self::error( 'RECERTIFICATION_REQUIRED', 'Provider capability certification is unavailable.' );
		return array(
			'provider_id' => $provider,
			'state' => ( isset( $cert['match_count'] ) && (int) $cert['match_count'] > 0 ) ? 'cataloged' : 'no_capability_match',
			'compatibility_state' => isset( $cert['compatibility_state'] ) ? (string) $cert['compatibility_state'] : 'unknown',
			'artifact_fingerprint' => isset( $cert['artifact']['runtime_artifact_fingerprint'] ) ? (string) $cert['artifact']['runtime_artifact_fingerprint'] : '',
			'certification_fingerprint' => self::stable_digest( $cert ),
		);
	}

	private static function target_material( $ability, $provider, $input, array $claim ) {
		$target = array(
			'target_fingerprint' => isset( $claim['target_fingerprint'] ) ? (string) $claim['target_fingerprint'] : '',
			'state_source' => 'operation_contract',
		);
		if ( 'mad4b/content-update-post' === (string) $ability && is_array( $input ) ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$post = $post_id ? get_post( $post_id ) : null;
			if ( ! $post ) return self::error( 'TARGET_CHANGED', 'Content target no longer exists before commit.' );
			$expected = isset( $input['expected_modified_gmt'] ) ? trim( (string) $input['expected_modified_gmt'] ) : '';
			if ( '' === $expected || ! hash_equals( (string) $post->post_modified_gmt, $expected ) ) {
				return self::error( 'TARGET_CHANGED', 'Content target changed before commit.' );
			}
			$target['state_source'] = 'wordpress_post_revision';
			$target['post_id'] = $post_id;
			$target['post_modified_gmt'] = (string) $post->post_modified_gmt;
			$target['post_status'] = (string) $post->post_status;
			$target['post_type'] = (string) $post->post_type;
		}
		$filtered = apply_filters( 'mad4b_scp_execution_commit_guard_target_state', $target, $ability, $provider, $input, $claim );
		return is_array( $filtered ) ? self::canonicalize( $filtered ) : self::error( 'DENIED', 'Target-state dependency projection is invalid.' );
	}

	private static function kill_switch_material( $ability, $provider, array $claim, $input ) {
		$default = array(
			'active' => ! ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED ),
			'revision' => 'global-mutation-switch:v1:' . ( ( defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED ) ? 'enabled' : 'disabled' ),
			'source' => 'MAD4B_MCP_MUTATION_ENABLED',
		);
		$value = apply_filters( 'mad4b_scp_execution_kill_switch_state', $default, $ability, $provider, $claim, $input );
		if ( ! is_array( $value ) || ! array_key_exists( 'active', $value ) || empty( $value['revision'] ) ) {
			return self::error( 'DENIED', 'Execution kill-switch state is invalid.' );
		}
		return array(
			'active' => (bool) $value['active'],
			'revision' => substr( sanitize_text_field( (string) $value['revision'] ), 0, 191 ),
			'source' => isset( $value['source'] ) ? substr( sanitize_text_field( (string) $value['source'] ), 0, 191 ) : 'filter',
		);
	}

	private static function selected_fields( array $row, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) if ( array_key_exists( $key, $row ) ) $out[ $key ] = $row[ $key ];
		return $out;
	}

	private static function same( array $expected, array $current, $key ) {
		if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $current ) ) return false;
		return hash_equals( self::stable_digest( $expected[ $key ] ), self::stable_digest( $current[ $key ] ) );
	}

	private static function stable_digest( $value ) {
		$canonical = self::canonicalize( $value );
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			$keys = array_keys( $value );
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) return $value;
		return (string) $value;
	}

	private static function is_list( array $value ) {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) return false;
			$expected++;
		}
		return true;
	}

	private static function error( $verdict, $message, array $data = array() ) {
		$verdict = strtoupper( sanitize_key( str_replace( '-', '_', (string) $verdict ) ) );
		$code = 'mad4b_commit_guard_' . strtolower( $verdict );
		$data['contract'] = self::RECEIPT_CONTRACT;
		$data['verdict'] = $verdict;
		$data['authorizing'] = false;
		return new WP_Error( $code, $message, $data );
	}
}
