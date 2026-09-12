<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only MCP/Abilities projection for the file-backed Skill registry.
 * Authoring remains a local wp-admin operation behind explicit environment
 * gates and is deliberately not exposed as a ChatGPT mutation tool.
 */
final class MAD4B_SCP_Skill_Abilities {
	const SNAPSHOT_ANCHOR_PREFIX = 'MAD4B-SNAPSHOT-ANCHOR';
	const DYNAMIC_SNAPSHOT_CONTRACT = 'mad4b.external-snapshot-dynamic-attestation.v1';
	const DYNAMIC_SNAPSHOT_OPTION = 'mad4b_scp_dynamic_external_snapshot_v1';
	const DYNAMIC_SNAPSHOT_TTL = 900;
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const STAGING_HOST = 'staging.egypttourgates.com';

	private static $pending_external_snapshot = null;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 30 );
		// Observe the exact authenticated tools/list response, then let the existing
		// authoritative handshake observer run before committing evidence at shutdown.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_external_tools_list' ), PHP_INT_MAX - 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'finalize_external_snapshot_observation' ), PHP_INT_MAX - 5 );
		// The existing v3 portable finalizer remains intact. This later callback only
		// replaces snapshot_external when stronger zero-touch evidence is ready.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_zero_touch_live_acceptance' ), 260, 2 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		self::add(
			'mad4b/skills-list',
			'List Skills',
			array(
				'type' => 'object',
				'properties' => array(
					'level' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Skill_Registry::levels() ),
					'target' => array( 'type' => 'string', 'maxLength' => 120 ),
					'enabled' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'skills_list' )
		);

		self::add(
			'mad4b/skill-get',
			'Get Skill',
			array(
				'type' => 'object',
				'properties' => array(
					'level' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Skill_Registry::levels() ),
					'target' => array( 'type' => 'string', 'maxLength' => 120 ),
					'name' => array( 'type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' ),
				),
				'required' => array( 'level', 'name' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'skill_get' )
		);

		self::add(
			'mad4b/skills-export-status',
			'Get Skills Export Status',
			array( 'type' => 'object', 'additionalProperties' => false ),
			array( __CLASS__, 'skills_export_status' )
		);

		self::add(
			'mad4b/skills-runtime-certification',
			'Get Skills Runtime Certification',
			array( 'type' => 'object', 'additionalProperties' => false ),
			array( __CLASS__, 'skills_runtime_certification' )
		);
	}

	private static function add( $name, $label, array $input_schema, $callback ) {
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => self::ability_description( $name, $label ),
				'category' => 'mad4b-read',
				'execute_callback' => $callback,
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => $input_schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	/** @internal Deterministic tool metadata anchor consumed only from a real external tools/list response. */
	public static function snapshot_anchor() {
		$identity = class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array();
		$token = isset( $identity['identity_token'] ) ? strtolower( trim( (string) $identity['identity_token'] ) ) : '';
		return 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', $token ) ? $token : '';
	}

	private static function ability_description( $name, $label ) {
		$description = $label . ' through the governed MAD4B file-backed Skill registry.';
		if ( 'mad4b/skills-export-status' !== (string) $name ) return $description;
		$anchor = self::snapshot_anchor();
		return '' === $anchor ? $description : $description . ' ' . self::SNAPSHOT_ANCHOR_PREFIX . ' ' . $anchor . '.';
	}

	public static function skills_list( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$filters = array();
		if ( isset( $input['level'] ) ) $filters['level'] = $input['level'];
		if ( isset( $input['target'] ) ) $filters['target'] = $input['target'];
		if ( array_key_exists( 'enabled', $input ) ) $filters['enabled'] = (bool) $input['enabled'];
		$skills = MAD4B_SCP_Skill_Registry::list_skills( $filters );
		return array( 'contract' => 'mad4b.skills-list.v1', 'count' => count( $skills ), 'skills' => $skills );
	}

	public static function skill_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$level = isset( $input['level'] ) ? $input['level'] : '';
		$target = isset( $input['target'] ) ? $input['target'] : '';
		$name = isset( $input['name'] ) ? $input['name'] : '';
		$skill = MAD4B_SCP_Skill_Registry::get_skill( $level, $target, $name );
		if ( is_wp_error( $skill ) ) return $skill;
		return array( 'contract' => 'mad4b.skill-get.v1', 'skill' => $skill );
	}

	public static function skills_export_status() {
		return array(
			'contract' => 'mad4b.skills-export-status.v1',
			'registry' => MAD4B_SCP_Skill_Registry::status(),
			'portable_snapshot' => MAD4B_SCP_Skill_Registry::portable_snapshot(),
			'snapshot_identity' => class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) ? MAD4B_SCP_Skill_Snapshot_Identity::build() : array(),
			'zero_touch_external_snapshot' => self::dynamic_snapshot_status(),
			'note' => 'The authenticated MCP tools/list surface carries the exact current Skill snapshot anchor automatically. Portable Plugin export remains available only as a backwards-compatible fallback and is not required for live snapshot acceptance.',
		);
	}

	public static function skills_runtime_certification() {
		return class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' )
			? MAD4B_SCP_Skill_Runtime_Certification::observe()
			: array( 'contract' => 'mad4b.skill-runtime-certification.v1', 'ready' => false, 'state' => 'unavailable' );
	}

	public static function bind_zero_touch_live_acceptance( $args, $name ) {
		if ( 'mad4b/live-acceptance-status' !== (string) $name || ! is_array( $args ) ) return $args;
		$args['execute_callback'] = array( __CLASS__, 'zero_touch_live_acceptance_status' );
		return $args;
	}

	public static function zero_touch_live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base = class_exists( 'MAD4B_SCP_External_Snapshot_Finalizer' )
			? MAD4B_SCP_External_Snapshot_Finalizer::live_acceptance_status( $input )
			: ( class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' ) ? MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $input ) : array() );
		if ( ! is_array( $base ) ) $base = array();
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();

		$dynamic = self::dynamic_snapshot_status();
		$existing = isset( $base['gates']['snapshot_external'] ) && is_array( $base['gates']['snapshot_external'] ) ? $base['gates']['snapshot_external'] : array();
		if ( ! empty( $dynamic['ready'] ) || empty( $existing['ready'] ) ) $base['gates']['snapshot_external'] = $dynamic;

		$base['ready'] = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] )
			: self::all_ready( $base['gates'] );
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['zero_touch_external_snapshot_contract'] = self::DYNAMIC_SNAPSHOT_CONTRACT;
		$base['external_facts_self_certified'] = false;
		return $base;
	}

	public static function observe_external_tools_list( $response, $server, $request ) {
		if ( ! self::staging_allowed() || ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $response;
		$params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		if ( ! is_array( $params ) || 'tools/list' !== ( isset( $params['method'] ) ? (string) $params['method'] : '' ) ) return $response;
		$session_id = method_exists( $request, 'get_header' ) ? trim( (string) $request->get_header( 'mcp-session-id' ) ) : '';
		if ( '' === $session_id || strlen( $session_id ) > 512 ) return $response;
		$rest = function_exists( 'rest_ensure_response' ) ? rest_ensure_response( $response ) : $response;
		if ( ! is_object( $rest ) || ! method_exists( $rest, 'get_status' ) || ! method_exists( $rest, 'get_data' ) || 200 !== (int) $rest->get_status() ) return $response;
		$data = self::normalize_value( $rest->get_data() );
		$tools = isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ? $data['result']['tools'] : array();
		$snapshot = self::snapshot_attestation_from_tools( $tools );
		if ( empty( $snapshot['identity_match'] ) ) return $response;
		$names = array();
		foreach ( $tools as $tool ) if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) && '' !== trim( $tool['name'] ) ) $names[] = trim( $tool['name'] );
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		if ( empty( $names ) ) return $response;
		self::$pending_external_snapshot = array(
			'session_fingerprint' => hash( 'sha256', $session_id ),
			'snapshot_identity' => (string) $snapshot['observed_identity'],
			'tool_inventory_fingerprint' => hash( 'sha256', implode( "\n", $names ) ),
			'tool_count' => count( $names ),
			'observed_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		unset( $session_id );
		return $response;
	}

	public static function finalize_external_snapshot_observation() {
		$pending = self::$pending_external_snapshot;
		self::$pending_external_snapshot = null;
		if ( ! is_array( $pending ) || ! self::staging_allowed() ) return;
		if ( ! class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) || ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ) return;

		$handshake = MAD4B_SCP_External_Handshake_Evidence::status();
		$raw_handshake = get_option( MAD4B_SCP_External_Handshake_Evidence::OPTION, array() );
		$observer = MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status();
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $handshake ) || empty( $handshake['verified'] ) || empty( $handshake['tool_inventory_match'] ) || empty( $handshake['build_fingerprint_match'] ) ) return;
		if ( ! is_array( $raw_handshake ) || ! is_array( $observer ) || empty( $observer['verified'] ) || empty( $observer['real_external_session'] ) || empty( $observer['inventory_match'] ) || empty( $observer['write_inventory_fingerprint_match'] ) || empty( $observer['build_fingerprint_match'] ) ) return;
		if ( ! is_array( $provenance ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) ) return;

		$raw_session = isset( $raw_handshake['mcp_session_fingerprint'] ) ? strtolower( trim( (string) $raw_handshake['mcp_session_fingerprint'] ) ) : '';
		$raw_inventory = isset( $raw_handshake['tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $raw_handshake['tool_inventory_fingerprint'] ) ) : '';
		$observer_inventory = isset( $observer['external_tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $observer['external_tool_inventory_fingerprint'] ) ) : '';
		if ( ! self::valid_hash( $raw_session ) || ! hash_equals( $raw_session, (string) $pending['session_fingerprint'] ) ) return;
		if ( ! self::valid_hash( $raw_inventory ) || ! hash_equals( $raw_inventory, (string) $pending['tool_inventory_fingerprint'] ) ) return;
		if ( ! self::valid_hash( $observer_inventory ) || ! hash_equals( $observer_inventory, (string) $pending['tool_inventory_fingerprint'] ) ) return;
		if ( ! isset( $raw_handshake['tool_count'], $observer['external_tool_count'] ) || (int) $raw_handshake['tool_count'] !== (int) $pending['tool_count'] || (int) $observer['external_tool_count'] !== (int) $pending['tool_count'] ) return;
		if ( self::CHATGPT_CLIENT_ID !== ( isset( $handshake['client_id'] ) ? (string) $handshake['client_id'] : '' ) || self::SERVER_ID !== ( isset( $handshake['server_id'] ) ? (string) $handshake['server_id'] : '' ) ) return;

		$observer_time = self::parse_time( isset( $observer['observed_at'] ) ? $observer['observed_at'] : '' );
		$pending_time = self::parse_time( isset( $pending['observed_at'] ) ? $pending['observed_at'] : '' );
		if ( false === $observer_time || false === $pending_time || abs( $observer_time - $pending_time ) > 5 ) return;
		$current_snapshot = self::snapshot_anchor();
		if ( '' === $current_snapshot || ! hash_equals( $current_snapshot, (string) $pending['snapshot_identity'] ) ) return;

		$source_sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$build = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		$observer_build = isset( $observer['build_fingerprint'] ) ? strtolower( trim( (string) $observer['build_fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $source_sha ) || ! self::valid_hash( $build ) || ! self::valid_hash( $observer_build ) || ! hash_equals( $build, $observer_build ) ) return;

		update_option(
			self::DYNAMIC_SNAPSHOT_OPTION,
			array(
				'contract' => self::DYNAMIC_SNAPSHOT_CONTRACT,
				'candidate_sha' => $source_sha,
				'build_fingerprint' => $build,
				'snapshot_identity' => $current_snapshot,
				'mcp_session_fingerprint' => $raw_session,
				'tool_inventory_fingerprint' => $raw_inventory,
				'tool_count' => (int) $pending['tool_count'],
				'observer_observed_at' => isset( $observer['observed_at'] ) ? (string) $observer['observed_at'] : '',
				'handshake_verified_at' => isset( $handshake['verified_at'] ) ? (string) $handshake['verified_at'] : '',
				'observed_at' => gmdate( 'c' ),
			),
			false
		);
	}

	/** @internal Pure parser for the exact Skill snapshot anchor in tools/list metadata. */
	public static function snapshot_attestation_from_tools( array $tools ) {
		$expected_tool = self::ability_to_mcp_tool_name( 'mad4b/skills-export-status' );
		$observed = '';
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) || ! is_string( $tool['name'] ) || '' === $expected_tool || ! hash_equals( $expected_tool, trim( (string) $tool['name'] ) ) ) continue;
			$description = isset( $tool['description'] ) && is_string( $tool['description'] ) ? $tool['description'] : '';
			$pattern = '/(?:^|\s)' . preg_quote( self::SNAPSHOT_ANCHOR_PREFIX, '/' ) . '\s+(sha256:[a-f0-9]{64})(?:\.|\s|$)/i';
			if ( preg_match( $pattern, $description, $matches ) ) $observed = strtolower( (string) $matches[1] );
			break;
		}
		$expected = self::snapshot_anchor();
		$present = 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', $observed );
		return array(
			'anchor_present' => (bool) $present,
			'tool_name' => $expected_tool,
			'observed_identity' => $present ? $observed : '',
			'expected_identity' => $expected,
			'identity_match' => $present && '' !== $expected && hash_equals( $expected, $observed ),
		);
	}

	/** @internal Read-only evaluator used by the live-acceptance wrapper and tests. */
	public static function dynamic_snapshot_status() {
		$stored = self::staging_allowed() ? get_option( self::DYNAMIC_SNAPSHOT_OPTION, array() ) : array();
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$handshake = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) ? MAD4B_SCP_External_Handshake_Evidence::status() : array();
		$raw_handshake = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) ? get_option( MAD4B_SCP_External_Handshake_Evidence::OPTION, array() ) : array();
		$current_snapshot = self::snapshot_anchor();
		$blockers = array();

		if ( ! self::staging_allowed() ) $blockers[] = 'wrong_target';
		if ( ! is_array( $stored ) || self::DYNAMIC_SNAPSHOT_CONTRACT !== ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' ) ) $blockers[] = 'fresh_external_tools_list_required';
		if ( ! is_array( $provenance ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) ) $blockers[] = 'candidate_provenance_unavailable';
		if ( ! is_array( $handshake ) || empty( $handshake['verified'] ) || empty( $handshake['tool_inventory_match'] ) || empty( $handshake['build_fingerprint_match'] ) ) $blockers[] = 'fresh_external_tools_list_required';
		if ( ! is_array( $external ) || empty( $external['verified'] ) || empty( $external['real_external_session'] ) || empty( $external['finalizer_subject_binding_verified'] ) || empty( $external['inventory_match'] ) || empty( $external['write_inventory_fingerprint_match'] ) || empty( $external['build_fingerprint_match'] ) ) $blockers[] = 'external_subject_session_binding_required';
		if ( self::CHATGPT_CLIENT_ID !== ( isset( $external['client_id'] ) ? (string) $external['client_id'] : '' ) || self::SERVER_ID !== ( isset( $external['server_id'] ) ? (string) $external['server_id'] : '' ) ) $blockers[] = 'external_client_server_binding_mismatch';
		if ( ! self::valid_hash( isset( $external['finalizer_context_digest'] ) ? strtolower( trim( (string) $external['finalizer_context_digest'] ) ) : '' ) ) $blockers[] = 'external_subject_session_binding_required';

		$source_sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$build = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) ) : '';
		$stored_sha = isset( $stored['candidate_sha'] ) ? strtolower( trim( (string) $stored['candidate_sha'] ) ) : '';
		$stored_build = isset( $stored['build_fingerprint'] ) ? strtolower( trim( (string) $stored['build_fingerprint'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $source_sha ) || 1 !== preg_match( '/^[a-f0-9]{40}$/', $stored_sha ) || ! hash_equals( $source_sha, $stored_sha ) ) $blockers[] = 'candidate_mismatch';
		if ( ! self::valid_hash( $build ) || ! self::valid_hash( $stored_build ) || ! hash_equals( $build, $stored_build ) ) $blockers[] = 'build_fingerprint_mismatch';

		$stored_snapshot = isset( $stored['snapshot_identity'] ) ? strtolower( trim( (string) $stored['snapshot_identity'] ) ) : '';
		if ( '' === $current_snapshot || 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $stored_snapshot ) || ! hash_equals( $current_snapshot, $stored_snapshot ) ) $blockers[] = 'snapshot_binding_changed';

		$stored_session = isset( $stored['mcp_session_fingerprint'] ) ? strtolower( trim( (string) $stored['mcp_session_fingerprint'] ) ) : '';
		$current_session = is_array( $raw_handshake ) && isset( $raw_handshake['mcp_session_fingerprint'] ) ? strtolower( trim( (string) $raw_handshake['mcp_session_fingerprint'] ) ) : '';
		if ( ! self::valid_hash( $stored_session ) || ! self::valid_hash( $current_session ) || ! hash_equals( $stored_session, $current_session ) ) $blockers[] = 'external_session_binding_changed';

		$stored_inventory = isset( $stored['tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $stored['tool_inventory_fingerprint'] ) ) : '';
		$current_inventory = isset( $external['external_tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $external['external_tool_inventory_fingerprint'] ) ) : '';
		$handshake_inventory = is_array( $handshake ) && isset( $handshake['tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $handshake['tool_inventory_fingerprint'] ) ) : '';
		if ( ! self::valid_hash( $stored_inventory ) || ! self::valid_hash( $current_inventory ) || ! self::valid_hash( $handshake_inventory ) || ! hash_equals( $stored_inventory, $current_inventory ) || ! hash_equals( $stored_inventory, $handshake_inventory ) ) $blockers[] = 'external_inventory_changed';

		$stored_observer_time = isset( $stored['observer_observed_at'] ) ? (string) $stored['observer_observed_at'] : '';
		$current_observer_time = isset( $external['observed_at'] ) ? (string) $external['observed_at'] : '';
		if ( '' === $stored_observer_time || '' === $current_observer_time || ! hash_equals( $stored_observer_time, $current_observer_time ) ) $blockers[] = 'external_observation_changed';
		$observed_at = isset( $stored['observed_at'] ) ? (string) $stored['observed_at'] : '';
		$observed_ts = self::parse_time( $observed_at );
		$fresh = false !== $observed_ts && $observed_ts <= time() + 60 && ( time() - $observed_ts ) <= self::DYNAMIC_SNAPSHOT_TTL;
		if ( ! $fresh ) $blockers[] = 'stale_external_snapshot_attestation';

		$blockers = array_values( array_unique( $blockers ) );
		$ready = empty( $blockers );
		return array(
			'ready' => $ready,
			'state' => $ready ? 'ready' : 'pending_external_evidence',
			'fresh' => $ready && $fresh,
			'evidence_contract' => self::DYNAMIC_SNAPSHOT_CONTRACT,
			'evidence_mode' => 'dynamic_authenticated_mcp_tools_list',
			'observed_at' => $observed_at,
			'blockers' => $blockers,
			'candidate_sha' => $source_sha,
			'build_fingerprint' => $build,
			'snapshot_identity' => $current_snapshot,
			'external_subject_session_bound' => empty( $blockers ) || ! in_array( 'external_subject_session_binding_required', $blockers, true ),
			'self_certified' => false,
		);
	}

	private static function ability_to_mcp_tool_name( $ability_name ) {
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return '';
		$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( (string) $ability_name );
		return is_wp_error( $name ) || ! is_string( $name ) ? '' : trim( $name );
	}

	private static function normalize_value( $value ) {
		$encoded = wp_json_encode( $value );
		return false === $encoded ? null : json_decode( $encoded, true );
	}

	private static function staging_allowed() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$home_host = function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) : '';
		$site_host = function_exists( 'site_url' ) ? strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ) ) : '';
		return 'staging' === $environment && self::STAGING_HOST === $home_host && self::STAGING_HOST === $site_host;
	}

	private static function parse_time( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? false : strtotime( $value );
	}

	private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ); }
	private static function all_ready( array $gates ) { foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false; return ! empty( $gates ); }
}
