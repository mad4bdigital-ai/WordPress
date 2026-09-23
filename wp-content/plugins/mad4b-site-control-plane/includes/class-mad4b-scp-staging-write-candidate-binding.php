<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact Staging-only candidate binding bootstrap.
 *
 * This surface is intentionally narrower than grant reconciliation:
 * - it never creates/revokes grants;
 * - it never creates/rebinds subjects or agents;
 * - it never invokes full authority reconciliation;
 * - it binds only the already-reconciled persisted authority to the exact
 *   currently-installed package candidate after proving the grant snapshot is
 *   clean and unchanged from the operator-reviewed read-only plan.
 */
final class MAD4B_SCP_Staging_Write_Candidate_Binding {
	const CONTRACT = 'mad4b.staging-write-candidate-binding.v2';
	const ABILITY = 'mad4b/staging-write-candidate-bind';
	const AUDIT_ABILITY = 'mad4b/staging-write-candidate-binding-audit';
	const SERVER_ID = 'mad4b-enrollment';
	const CONFIRMATION = 'BIND EXACT CURRENT STAGING WRITE CANDIDATE';

	private static $booted = false;
	private static $running = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 12 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_audit_ability' ), 13 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;

		// Enrollment bootstrap is intentionally separate from the normal
		// mad4b-write inventory so exposing this tool never creates grant #41.
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );

		try {
			wp_register_ability( self::ABILITY, array(
				'label' => 'Bind Exact Staging Write Candidate',
				'description' => 'Bind an already-clean governed-write authority snapshot to the exact current Staging package candidate without reconciling grants, subjects or agents.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'bind' ),
				'permission_callback' => array( __CLASS__, 'can_execute' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'expected_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
						'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
						'expected_build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_artifact_identity' => array(
							'type' => 'string',
							'minLength' => 40,
							'maxLength' => 191,
							'pattern' => '^mad4b-site-control-plane-general-distribution-kit-[A-Fa-f0-9]{40}$',
						),
						'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
						'expected_write_tool_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
						'expected_write_inventory_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'expected_grant_rows_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
						'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
					),
					'required' => array(
						'expected_revision',
						'expected_profile_digest',
						'expected_source_commit_sha',
						'expected_build_fingerprint',
						'expected_package_manifest_digest',
						'expected_artifact_identity',
						'expected_agent_public_id',
						'expected_write_tool_count',
						'expected_write_inventory_fingerprint',
						'expected_grant_rows_fingerprint',
						'confirmation',
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'type' => 'tool',
						'surface' => 'enrollment',
						'mad4b_candidate_binding_authority' => self::CONTRACT,
						'binding_only' => true,
						'grant_mutation_allowed' => false,
						'subject_mutation_allowed' => false,
						'agent_mutation_allowed' => false,
						'auto_approves' => false,
					),
					'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			) );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	public static function register_audit_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::AUDIT_ABILITY ) ) return;
		wp_register_ability( self::AUDIT_ABILITY, array(
			'label' => 'Inspect Staging Write Candidate Binding Audit',
			'description' => 'Read bounded append-only authorization, completion, no-op and rollback evidence for the exact Staging write candidate binding operation.',
			'category' => 'mad4b-governance',
			'execute_callback' => array( __CLASS__, 'binding_audit' ),
			'permission_callback' => array( __CLASS__, 'can_read_audit' ),
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'request_id' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 100 ),
					'operation_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
					'event_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36, 'pattern' => '^[A-Fa-f0-9-]{36}$' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'enrollment', 'binding_audit_only' => true ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function can_read_audit( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_candidate_binding_audit_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_candidate_binding_audit_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() || 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_candidate_binding_audit_staging_required', 'Binding audit lookup is limited to the enrolled Staging Site Profile.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_candidate_binding_audit_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		return true;
	}

	public static function binding_audit( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || ! method_exists( 'MAD4B_SCP_Audit', 'candidate_binding_events' ) ) return new WP_Error( 'mad4b_candidate_binding_audit_lookup_unavailable', 'Candidate-binding audit lookup is unavailable.' );
		return MAD4B_SCP_Audit::candidate_binding_events( is_array( $input ) ? $input : array() );
	}

	public static function can_execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_candidate_bind_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_candidate_bind_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_candidate_bind_profile_missing', 'An enrolled Site Profile is required.' );
		if ( 'staging' !== MAD4B_SCP_Site_Profile::current_environment() ) return new WP_Error( 'mad4b_candidate_bind_staging_only', 'Candidate binding is Staging-only.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_candidate_bind_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) return new WP_Error( 'mad4b_candidate_bind_write_disabled', 'Governed write must already be enabled.' );
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) || true !== constant( 'MAD4B_MCP_MUTATION_ENABLED' ) ) return new WP_Error( 'mad4b_candidate_bind_mutation_gate_disabled', 'The governed mutation master gate must already be enabled.' );
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) return new WP_Error( 'mad4b_candidate_bind_breakglass_enabled', 'Candidate binding is denied while Breakglass authority is enabled.' );
		if ( 'chatgpt-governed-write' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::agent_slug() ) ) return new WP_Error( 'mad4b_candidate_bind_canonical_agent_required', 'Candidate binding is limited to the canonical profile-owned governed-write agent.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_candidate_bind_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		if ( 'https' !== strtolower( (string) wp_parse_url( MAD4B_SCP_Site_Profile::current_origin(), PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_candidate_bind_https_required', 'Remote candidate binding requires HTTPS.' );
		return true;
	}

	private static function current_agent_or_error() {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return new WP_Error( 'mad4b_candidate_bind_identity_unavailable', 'Governance identity components are unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_candidate_bind_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$agent = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
		if ( is_wp_error( $agent ) ) return $agent;
		if ( 'chatgpt-governed-write' !== ( isset( $agent['slug'] ) ? (string) $agent['slug'] : '' )
			|| 'enabled' !== ( isset( $agent['status'] ) ? (string) $agent['status'] : '' )
			|| 'staging' !== ( isset( $agent['environment'] ) ? (string) $agent['environment'] : '' ) ) return new WP_Error( 'mad4b_candidate_bind_agent_invalid', 'Resolved governance identity is not the canonical enabled Staging governed-write agent.' );
		if ( (int) ( isset( $agent['wp_user_id'] ) ? $agent['wp_user_id'] : 0 ) !== get_current_user_id() ) return new WP_Error( 'mad4b_candidate_bind_agent_user_mismatch', 'Resolved governed-write agent belongs to another WordPress user.' );
		return $agent;
	}

	private static function clean_plan_or_error( array $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ) return new WP_Error( 'mad4b_candidate_bind_authority_unavailable', 'Governed write authority is unavailable.' );
		$plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
		if ( ! is_array( $plan ) || empty( $plan['read_only'] ) || ! empty( $plan['mutation_performed'] ) ) return new WP_Error( 'mad4b_candidate_bind_plan_invalid', 'Read-only reconciliation evidence is unavailable.' );
		if ( empty( $plan['eligible'] ) || empty( $plan['current_ready'] ) ) return new WP_Error( 'mad4b_candidate_bind_authority_not_persisted_ready', 'Persisted governed-write authority must already be ready.' );
		if ( empty( $plan['agent_present'] ) ) return new WP_Error( 'mad4b_candidate_bind_agent_missing', 'Canonical governed-write agent is unavailable.' );

		$write_tool_count = isset( $plan['write_tool_count'] ) ? (int) $plan['write_tool_count'] : 0;
		$existing = isset( $plan['exact_grants_existing'] ) ? (int) $plan['exact_grants_existing'] : 0;
		if ( $write_tool_count < 1 || $existing !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_exact_grants_incomplete', 'All runtime-eligible write tools must already have exact current-environment grants.' );
		foreach ( array(
			'exact_grants_missing_count',
			'stale_allow_grants_count',
			'broad_environment_grants_count',
			'duplicate_exact_allow_grants_count',
			'current_agent_wildcard_grants',
			'global_registry_wildcard_grants',
		) as $key ) {
			if ( ! empty( $plan[ $key ] ) ) return new WP_Error( 'mad4b_candidate_bind_grant_snapshot_not_clean', 'Candidate binding requires a clean grant snapshot.', array( 'field' => $key, 'value' => $plan[ $key ] ) );
		}
		if ( ! empty( $plan['breakglass_included'] ) ) return new WP_Error( 'mad4b_candidate_bind_breakglass_leak', 'Breakglass/raw SQL must remain outside governed write authority.' );

		$rows = isset( $plan['rows'] ) && is_array( $plan['rows'] ) ? $plan['rows'] : array();
		if ( count( $rows ) !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_row_count_mismatch', 'Reconciliation rows do not match the current write inventory.' );
		$inventory_rows = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['ability'], $row['provider'] ) ) $inventory_rows[] = array( 'ability' => (string) $row['ability'], 'provider' => sanitize_key( (string) $row['provider'] ) );
			if ( ! is_array( $row )
				|| empty( $row['mounted'] )
				|| empty( $row['exact_grant_present'] )
				|| 'exact_current_environment' !== ( isset( $row['grant_state'] ) ? (string) $row['grant_state'] : '' ) ) {
				return new WP_Error( 'mad4b_candidate_bind_row_not_exact', 'Every governed write row must already be exact/current before candidate binding.' );
			}
		}

		$expected_agent = strtolower( trim( (string) $input['expected_agent_public_id'] ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $expected_agent ) || ! hash_equals( $expected_agent, strtolower( (string) $plan['agent_public_id'] ) ) ) return new WP_Error( 'mad4b_candidate_bind_agent_mismatch', 'Canonical governed-write agent changed after operator review.' );
		$resolved_agent = self::current_agent_or_error();
		if ( is_wp_error( $resolved_agent ) ) return $resolved_agent;
		if ( ! isset( $resolved_agent['public_id'] ) || ! hash_equals( $expected_agent, strtolower( (string) $resolved_agent['public_id'] ) ) ) return new WP_Error( 'mad4b_candidate_bind_resolved_agent_mismatch', 'Authenticated governance identity no longer resolves to the reviewed canonical agent.' );
		if ( (int) $input['expected_write_tool_count'] !== $write_tool_count ) return new WP_Error( 'mad4b_candidate_bind_write_count_mismatch', 'Governed write tool count changed after operator review.' );

		usort( $inventory_rows, static function ( $a, $b ) { return strcmp( $a['ability'] . "\0" . $a['provider'], $b['ability'] . "\0" . $b['provider'] ); } );
		$current_inventory = hash( 'sha256', wp_json_encode( $inventory_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$expected_inventory = strtolower( trim( (string) $input['expected_write_inventory_fingerprint'] ) );
		$persisted_inventory = isset( $plan['write_inventory_fingerprint'] ) ? strtolower( (string) $plan['write_inventory_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_inventory )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $persisted_inventory )
			|| ! hash_equals( $expected_inventory, $current_inventory )
			|| ! hash_equals( $persisted_inventory, $current_inventory ) ) return new WP_Error( 'mad4b_candidate_bind_inventory_fingerprint_mismatch', 'Governed write inventory changed after operator review or no longer matches persisted authority.' );

		$expected_rows = strtolower( trim( (string) $input['expected_grant_rows_fingerprint'] ) );
		$live_rows = isset( $plan['grant_rows_fingerprint'] ) ? strtolower( (string) $plan['grant_rows_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_rows ) || ! preg_match( '/^[a-f0-9]{64}$/', $live_rows ) || ! hash_equals( $expected_rows, $live_rows ) ) return new WP_Error( 'mad4b_candidate_bind_grant_rows_fingerprint_mismatch', 'Exact grant snapshot changed after operator review.' );

		return $plan;
	}

	private static function operation_context( array $plan, array $binding, $current_revision, $current_digest ) {
		if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) ) return new WP_Error( 'mad4b_candidate_bind_identity_unavailable', 'Governance identity context is unavailable.' );
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_candidate_bind_oauth_identity_required', 'Verified OAuth bearer governance identity is required.' );
		$subject_fingerprint = isset( $identity['subject_fingerprint'] ) ? strtolower( trim( (string) $identity['subject_fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $subject_fingerprint ) ) return new WP_Error( 'mad4b_candidate_bind_subject_fingerprint_missing', 'A stable hashed OAuth subject fingerprint is required for binding audit attribution.' );
		$issuer_fingerprint = isset( $identity['issuer_fingerprint'] ) ? strtolower( trim( (string) $identity['issuer_fingerprint'] ) ) : '';
		$client_fingerprint = isset( $identity['client_fingerprint'] ) ? strtolower( trim( (string) $identity['client_fingerprint'] ) ) : '';
		$session_fingerprint = isset( $identity['session_fingerprint'] ) ? strtolower( trim( (string) $identity['session_fingerprint'] ) ) : '';
		foreach ( array(
			'issuer_fingerprint' => $issuer_fingerprint,
			'client_fingerprint' => $client_fingerprint,
			'session_fingerprint' => $session_fingerprint,
		) as $field => $value ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) return new WP_Error( 'mad4b_candidate_bind_actor_attribution_incomplete', 'Hashed OAuth issuer/client/token attribution is required for candidate binding.', array( 'field' => $field ) );
		}
		$correlation_id = isset( $identity['request_id'] ) ? substr( sanitize_text_field( (string) $identity['request_id'] ), 0, 100 ) : '';
		if ( '' === $correlation_id ) return new WP_Error( 'mad4b_candidate_bind_correlation_missing', 'A request correlation identifier is required for binding audit attribution.' );
		$transport = class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '';
		if ( ! in_array( $transport, array( 'mad4b-chatgpt', 'mad4b-enrollment' ), true ) ) return new WP_Error( 'mad4b_candidate_bind_transport_context_invalid', 'Candidate binding requires the bounded ChatGPT or enrollment MCP transport.' );
		$operation_id = wp_generate_uuid4();
		return array(
			'contract' => self::CONTRACT,
			'operation_id' => $operation_id,
			'correlation_id' => $correlation_id,
			'actor' => array(
				'actor_type' => isset( $identity['subject_type'] ) ? sanitize_key( (string) $identity['subject_type'] ) : 'oauth',
				'wp_user_id' => get_current_user_id(),
				'identity_method' => 'oauth2_bearer',
				'subject_fingerprint' => $subject_fingerprint,
				'issuer_fingerprint' => $issuer_fingerprint,
				'client_fingerprint' => $client_fingerprint,
				'session_fingerprint' => $session_fingerprint,
				'mcp_request_context_fingerprint' => hash( 'sha256', $subject_fingerprint . "\0" . $correlation_id . "\0" . $transport ),
				'agent_public_id' => (string) $plan['agent_public_id'],
				'transport_server_id' => $transport,
			),
			'site' => array(
				'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
				'profile_revision' => (int) $current_revision,
				'profile_digest' => (string) $current_digest,
			),
			'previous_binding' => array(
				'source_commit_sha' => isset( $binding['stored_source_commit_sha'] ) ? (string) $binding['stored_source_commit_sha'] : '',
				'build_fingerprint' => isset( $binding['stored_build_fingerprint'] ) ? (string) $binding['stored_build_fingerprint'] : '',
				'package_manifest_digest' => isset( $binding['stored_package_manifest_digest'] ) ? (string) $binding['stored_package_manifest_digest'] : '',
				'artifact_identity' => isset( $binding['stored_artifact_identity'] ) ? (string) $binding['stored_artifact_identity'] : '',
				'identity_completeness' => isset( $binding['identity_completeness'] ) ? (string) $binding['identity_completeness'] : 'legacy_partial',
			),
			'target_binding' => array(
				'source_commit_sha' => (string) $binding['current_source_commit_sha'],
				'build_fingerprint' => (string) $binding['current_build_fingerprint'],
				'package_manifest_digest' => (string) $binding['current_package_manifest_digest'],
				'artifact_identity' => (string) $binding['current_artifact_identity'],
			),
			'write_snapshot' => array(
				'write_tool_count' => (int) $plan['write_tool_count'],
				'exact_grants_existing' => (int) $plan['exact_grants_existing'],
				'write_inventory_fingerprint' => (string) $plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $plan['grant_rows_fingerprint'],
			),
			'confirmation' => self::CONFIRMATION,
			'mutation_class' => 'candidate_binding_only',
		);
	}

	public static function bind( $input ) {
		if ( self::$running ) return new WP_Error( 'mad4b_candidate_bind_reentry_denied', 'Candidate binding is already running in this request.' );
		$permission = self::can_execute( $input );
		if ( is_wp_error( $permission ) || ! $permission ) return $permission;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_candidate_bind_input_invalid', 'Input must be an object.' );
		if ( self::CONFIRMATION !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_candidate_bind_confirmation_required', 'Exact candidate binding confirmation is required.' );

		self::$running = true;
		try {
			$current_revision = MAD4B_SCP_Site_Profile::revision();
			$expected_revision = absint( $input['expected_revision'] );
			if ( $current_revision !== $expected_revision ) return new WP_Error( 'mad4b_candidate_bind_revision_stale', 'Site Profile revision changed before candidate binding.' );
			$current_digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
			$expected_digest = strtolower( trim( (string) $input['expected_profile_digest'] ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_digest ) || ! hash_equals( $current_digest, $expected_digest ) ) return new WP_Error( 'mad4b_candidate_bind_profile_stale', 'Site Profile digest changed before candidate binding.' );

			$plan = self::clean_plan_or_error( $input );
			if ( is_wp_error( $plan ) ) return $plan;
			$binding = isset( $plan['candidate_binding'] ) && is_array( $plan['candidate_binding'] ) ? $plan['candidate_binding'] : array();
			if ( empty( $binding['required'] ) ) return new WP_Error( 'mad4b_candidate_bind_not_required', 'Current package does not require candidate binding.' );

			$expected_sha = strtolower( trim( (string) $input['expected_source_commit_sha'] ) );
			$expected_build = strtolower( trim( (string) $input['expected_build_fingerprint'] ) );
			$expected_manifest = strtolower( trim( (string) $input['expected_package_manifest_digest'] ) );
			$expected_artifact = trim( (string) $input['expected_artifact_identity'] );
			$current_sha = isset( $binding['current_source_commit_sha'] ) ? strtolower( (string) $binding['current_source_commit_sha'] ) : '';
			$current_build = isset( $binding['current_build_fingerprint'] ) ? strtolower( (string) $binding['current_build_fingerprint'] ) : '';
			$current_manifest = isset( $binding['current_package_manifest_digest'] ) ? strtolower( (string) $binding['current_package_manifest_digest'] ) : '';
			$current_artifact = isset( $binding['current_artifact_identity'] ) ? (string) $binding['current_artifact_identity'] : '';
			if ( ! hash_equals( $expected_sha, $current_sha ) ) return new WP_Error( 'mad4b_candidate_bind_source_mismatch', 'Source commit does not match the exact current package.' );
			if ( ! hash_equals( $expected_build, $current_build ) ) return new WP_Error( 'mad4b_candidate_bind_build_mismatch', 'Build fingerprint does not match the exact current package.' );
			if ( ! hash_equals( $expected_manifest, $current_manifest ) ) return new WP_Error( 'mad4b_candidate_bind_manifest_mismatch', 'Package manifest digest does not match the exact current package.' );
			if ( ! hash_equals( $expected_artifact, $current_artifact ) ) return new WP_Error( 'mad4b_candidate_bind_artifact_mismatch', 'Artifact identity does not match the exact current package.' );

			$operation_context = self::operation_context( $plan, $binding, $current_revision, $current_digest );
			if ( is_wp_error( $operation_context ) ) return $operation_context;
			$result = MAD4B_SCP_Staging_Write_Authority::bind_candidate_identity( $current_sha, $current_build, $operation_context );
			if ( is_wp_error( $result ) ) return $result;

			$after_binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			$after_plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			$post_ok = is_array( $after_binding )
				&& ! empty( $after_binding['match'] )
				&& 'complete' === ( isset( $after_binding['identity_completeness'] ) ? (string) $after_binding['identity_completeness'] : '' )
				&& is_array( $after_plan )
				&& (int) $after_plan['write_tool_count'] === (int) $plan['write_tool_count']
				&& (int) $after_plan['exact_grants_existing'] === (int) $plan['exact_grants_existing']
				&& hash_equals( (string) $plan['write_inventory_fingerprint'], (string) $after_plan['write_inventory_fingerprint'] )
				&& hash_equals( (string) $plan['grant_rows_fingerprint'], (string) $after_plan['grant_rows_fingerprint'] )
				&& MAD4B_SCP_Staging_Write_Authority::effective();
			if ( ! $post_ok ) return new WP_Error( 'mad4b_candidate_bind_wrapper_postcondition_failed', 'Binding primitive returned without a fully effective exact four-part candidate identity.' );

			return array_merge( $result, array(
				'candidate_binding' => $after_binding,
				'write_tool_count' => (int) $after_plan['write_tool_count'],
				'exact_grants_existing' => (int) $after_plan['exact_grants_existing'],
				'write_inventory_fingerprint' => (string) $after_plan['write_inventory_fingerprint'],
				'grant_rows_fingerprint' => (string) $after_plan['grant_rows_fingerprint'],
				'effective' => true,
			) );
		} finally {
			self::$running = false;
		}
	}

}
