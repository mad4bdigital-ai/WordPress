<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only exact plan for Staging governed-write grant reconciliation.
 *
 * The plan never creates/revokes grants, agents, subjects, approvals or authority.
 * It snapshots the exact enrolled profile, package provenance, canonical agent,
 * governed write inventory and current exact-grant gap into a deterministic digest.
 * The mutation ability must bind to that digest immediately before any write.
 */
final class MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan {
	const CONTRACT = 'mad4b.staging-write-grant-reconciliation-plan.v1';
	const ABILITY = 'mad4b/staging-write-grant-reconciliation-plan';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability( self::ABILITY, array(
			'label' => 'Plan Exact Staging Write Grant Reconciliation',
			'description' => 'Read-only exact plan for reconciling the canonical Staging governed-write grant set. Produces a deterministic plan digest and performs no mutation.',
			'category' => 'mad4b-governance',
			'execute_callback' => array( __CLASS__, 'plan' ),
			'permission_callback' => array( __CLASS__, 'can_plan' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array(
					'public' => false,
					'type' => 'tool',
					'surface' => 'enrollment',
					'readonly_authority_plan' => true,
					'authorizes_mutation' => false,
				),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function can_plan( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_grant_reconcile_plan_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_grant_reconcile_plan_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_grant_reconcile_plan_staging_only', 'Grant reconciliation planning is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_grant_reconcile_plan_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_grant_reconcile_plan_write_disabled', 'Governed write must already be enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_canonical_agent_required', 'Planning is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
			return $value;
		}
		foreach ( $value as $index => $item ) $value[ $index ] = self::canonicalize( $item );
		return $value;
	}

	private static function current_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== (string) $agent['slug'] || 'enabled' !== (string) $agent['status'] || 'staging' !== (string) $agent['environment'] ) return new WP_Error( 'mad4b_grant_reconcile_plan_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging write agent.' );
		if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'mad4b_grant_reconcile_plan_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function inventory() {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) || ! class_exists( 'MAD4B_SCP_Servers' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_authority_unavailable', 'Governed write authority components are unavailable.' );
		$tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
		if ( ! is_array( $tools ) || empty( $tools ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_inventory_empty', 'Current governed write inventory is empty.' );
		$rows = array();
		foreach ( $tools as $ability ) {
			$ability = (string) $ability;
			if ( 'mad4b/database-raw-query' === $ability ) return new WP_Error( 'mad4b_grant_reconcile_plan_breakglass_leak', 'Breakglass/raw SQL must never enter governed grant reconciliation.' );
			$provider = MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $ability );
			if ( null === $provider ) return new WP_Error( 'mad4b_grant_reconcile_plan_unmounted_ability', 'A current write ability has no exact mad4b-write provider mount.', array( 'ability' => $ability ) );
			$rows[] = array( 'ability' => $ability, 'provider' => sanitize_key( (string) $provider ) );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		return array(
			'rows' => $rows,
			'count' => count( $rows ),
			'fingerprint' => hash( 'sha256', wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
		);
	}

	public static function plan( $input = null ) {
		$permission = self::can_plan( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return new WP_Error( 'mad4b_grant_reconcile_plan_schema_unavailable', 'Governance schema must be physically ready.' );
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array( 'ready' => false );
		if ( empty( $audit['ready'] ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_audit_required', 'Ready append-only audit storage is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_provenance_unavailable', 'Build provenance authority is unavailable.' );

		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_grant_reconcile_plan_provenance_not_ready', 'Exact current build provenance is not ready.' );

		$source_sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$build_fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		$package_manifest_digest = isset( $provenance['package_manifest_digest'] ) ? strtolower( (string) $provenance['package_manifest_digest'] ) : '';
		$artifact_identity = isset( $provenance['artifact_identity'] ) ? trim( (string) $provenance['artifact_identity'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $source_sha )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $build_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $package_manifest_digest )
			|| '' === $artifact_identity
			|| strlen( $artifact_identity ) > 191 ) {
			return new WP_Error( 'mad4b_grant_reconcile_plan_candidate_invalid', 'Exact four-part package provenance identity is invalid.' );
		}

		$agent = self::current_agent();
		if ( is_wp_error( $agent ) ) return $agent;
		$inventory = self::inventory();
		if ( is_wp_error( $inventory ) ) return $inventory;

		$counts = MAD4B_SCP_Agent_Registry::counts();
		$blockers = array();
		if ( ! empty( $counts['wildcard_grants'] ) ) $blockers[] = 'wildcard_grants_detected';

		$desired = array();
		foreach ( $inventory['rows'] as $row ) $desired[ $row['ability'] . "\0" . $row['provider'] ] = $row;
		$existing_rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
		$seen_allow = array();
		foreach ( $existing_rows as $grant ) {
			if ( 'allow' !== (string) $grant['effect'] ) continue;
			$key = (string) $grant['ability_name'] . "\0" . sanitize_key( (string) $grant['provider'] );
			if ( ! isset( $desired[ $key ] ) ) $blockers[] = 'stale_allow:' . (string) $grant['ability_name'];
			if ( 'staging' !== (string) $grant['environment'] ) $blockers[] = 'non_staging_allow:' . (string) $grant['ability_name'];
			if ( isset( $seen_allow[ $key ] ) ) $blockers[] = 'duplicate_allow:' . (string) $grant['ability_name'];
			$seen_allow[ $key ] = true;
		}

		$missing = array();
		$missing_providers = array();
		foreach ( $inventory['rows'] as $row ) {
			$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], 'mad4b-write', $row['ability'], $row['provider'] );
			if ( is_wp_error( $grant ) ) {
				if ( 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) {
					$blockers[] = 'existing_grant_blocked:' . $row['ability'] . ':' . $grant->get_error_code();
					continue;
				}
				$missing[] = $row['ability'];
				$missing_providers[ $row['ability'] ] = $row['provider'];
			}
		}
		sort( $missing, SORT_STRING );

		$allowed_providers = class_exists( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation' ) ? MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers() : array();
		foreach ( $missing as $ability ) {
			if ( ! isset( $allowed_providers[ $ability ] ) ) {
				$blockers[] = 'missing_outside_allowlist:' . $ability;
				continue;
			}
			if ( sanitize_key( (string) $allowed_providers[ $ability ] ) !== sanitize_key( (string) $missing_providers[ $ability ] ) ) $blockers[] = 'provider_mismatch:' . $ability;
		}

		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		$blockers = array_values( array_unique( $blockers ) );
		sort( $blockers, SORT_STRING );

		$payload = array(
			'contract' => self::CONTRACT,
			'non_authorizing' => true,
			'eligible' => empty( $blockers ),
			'blockers' => $blockers,
			'environment' => 'staging',
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'expected_revision' => (int) MAD4B_SCP_Site_Profile::revision(),
			'expected_profile_digest' => strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() ),
			'expected_source_commit_sha' => $source_sha,
			'expected_build_fingerprint' => $build_fingerprint,
			'expected_package_manifest_digest' => $package_manifest_digest,
			'expected_artifact_identity' => $artifact_identity,
			'expected_agent_public_id' => strtolower( (string) $agent['public_id'] ),
			'expected_write_tool_count' => (int) $inventory['count'],
			'expected_write_inventory_fingerprint' => (string) $inventory['fingerprint'],
			'expected_missing_abilities' => $missing,
			'candidate_binding_match' => ! empty( $binding['match'] ),
			'candidate_binding_required' => ! empty( $binding['required'] ),
			'apply_ability' => MAD4B_SCP_Staging_Write_Grant_Reconciliation::ABILITY,
			'required_confirmation' => MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONFIRMATION,
			'production_mutation' => false,
			'breakglass_included' => false,
		);
		$encoded = wp_json_encode( self::canonicalize( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) return new WP_Error( 'mad4b_grant_reconcile_plan_encoding_failed', 'Unable to encode exact reconciliation plan.' );
		$payload['plan_sha256'] = hash( 'sha256', $encoded );
		$payload['write_binding'] = array(
			'expected_plan_sha256' => $payload['plan_sha256'],
			'expected_revision' => $payload['expected_revision'],
			'expected_profile_digest' => $payload['expected_profile_digest'],
			'expected_source_commit_sha' => $payload['expected_source_commit_sha'],
			'expected_build_fingerprint' => $payload['expected_build_fingerprint'],
			'expected_package_manifest_digest' => $payload['expected_package_manifest_digest'],
			'expected_artifact_identity' => $payload['expected_artifact_identity'],
			'expected_agent_public_id' => $payload['expected_agent_public_id'],
			'expected_write_tool_count' => $payload['expected_write_tool_count'],
			'expected_write_inventory_fingerprint' => $payload['expected_write_inventory_fingerprint'],
			'expected_missing_abilities' => $payload['expected_missing_abilities'],
			'confirmation' => MAD4B_SCP_Staging_Write_Grant_Reconciliation::CONFIRMATION,
		);
		return $payload;
	}
}
