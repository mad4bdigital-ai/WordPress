<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact-candidate external snapshot finalization.
 *
 * This layer deliberately does not expose the WordPress-local snapshot identity
 * or the expected package token through MCP. A client proves possession of the
 * exported portable package by presenting the candidate-bound external token
 * embedded in that package, from the same verified ChatGPT OAuth/MCP context
 * that produced the exact tool inventory attestation.
 */
final class MAD4B_SCP_External_Snapshot_Finalizer {
	const CONTRACT = 'mad4b.external-snapshot-finalizer.v2';
	const ATTESTATION_CONTRACT = 'mad4b.external-snapshot-attestation.v2';
	const VERIFY_CONTRACT = 'mad4b.external-snapshot-verification.v2';
	const OPTION = 'mad4b_scp_external_snapshot_attestation_v2';
	const TTL = 1800;
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const STAGING_HOST = 'staging.egypttourgates.com';

	private static $booted = false;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		// Later than the legacy observer/finalizer/reconciler bindings. This is a
		// strict replacement callback, not an additional ability or write surface.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'bind_callbacks' ), 240, 2 );
	}

	public static function bind_callbacks( $args, $name ) {
		if ( ! is_array( $args ) ) return $args;
		if ( 'mad4b/snapshot-verify' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'snapshot_verify' );
		}
		if ( 'mad4b/live-acceptance-status' === (string) $name ) {
			$args['execute_callback'] = array( __CLASS__, 'live_acceptance_status' );
		}
		return $args;
	}

	public static function snapshot_verify( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$client = isset( $input['client_snapshot_token'] ) ? strtolower( trim( (string) $input['client_snapshot_token'] ) ) : '';
		$candidate = self::current_candidate();
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$trusted = self::trusted_external_context( $external );
		$expected = self::expected_external_token( $candidate );
		$exact = '' !== $expected && 1 === preg_match( '/^sha256:[a-f0-9]{64}$/', $client ) && hash_equals( $expected, $client );
		$recorded = false;

		if ( self::staging_allowed() && $trusted && ! empty( $candidate['ready'] ) && $exact ) {
			$attestation = array(
				'contract' => self::ATTESTATION_CONTRACT,
				'candidate_sha' => $candidate['source_commit_sha'],
				'build_fingerprint' => $candidate['build_fingerprint'],
				'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
				'external_snapshot_token_digest' => hash( 'sha256', $client ),
				'client_id' => self::CHATGPT_CLIENT_ID,
				'server_id' => self::SERVER_ID,
				'finalizer_context_digest' => (string) $external['finalizer_context_digest'],
				'external_tool_inventory_fingerprint' => (string) $external['external_tool_inventory_fingerprint'],
				'external_write_inventory_fingerprint' => (string) $external['external_write_inventory_fingerprint'],
				'session_fingerprint_present' => true,
				'external_observed_at' => isset( $external['observed_at'] ) ? (string) $external['observed_at'] : '',
				'observed_at' => gmdate( 'c' ),
			);
			update_option( self::OPTION, $attestation, false );
			$recorded = true;
		}

		return array(
			'contract' => self::VERIFY_CONTRACT,
			'exact_match' => (bool) $exact,
			'trusted_external_context' => (bool) $trusted,
			'attestation_recorded' => (bool) $recorded,
			'candidate_ready' => ! empty( $candidate['ready'] ),
			'candidate_sha' => ! empty( $candidate['ready'] ) ? (string) $candidate['source_commit_sha'] : '',
			'build_fingerprint' => ! empty( $candidate['ready'] ) ? (string) $candidate['build_fingerprint'] : '',
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? MAD4B_SCP_VERSION : '',
			'expected_token_disclosed' => false,
			'local_snapshot_identity_disclosed' => false,
		);
	}

	public static function external_snapshot_status() {
		$candidate = self::current_candidate();
		$stored = get_option( self::OPTION, array() );
		$external = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::external_handshake_attestation_status() : array();
		$trusted = self::trusted_external_context( $external );
		$expected = self::expected_external_token( $candidate );
		$blockers = array();

		if ( ! self::staging_allowed() ) $blockers[] = 'wrong_target';
		if ( empty( $candidate['ready'] ) ) $blockers[] = 'candidate_provenance_unavailable';
		if ( ! is_array( $stored ) || self::ATTESTATION_CONTRACT !== ( isset( $stored['contract'] ) ? (string) $stored['contract'] : '' ) ) $blockers[] = 'trusted_external_snapshot_attestation_required';
		if ( empty( $stored['candidate_sha'] ) || empty( $candidate['source_commit_sha'] ) || ! hash_equals( (string) $candidate['source_commit_sha'], (string) $stored['candidate_sha'] ) ) $blockers[] = 'candidate_mismatch';
		if ( empty( $stored['build_fingerprint'] ) || empty( $candidate['build_fingerprint'] ) || ! hash_equals( (string) $candidate['build_fingerprint'], (string) $stored['build_fingerprint'] ) ) $blockers[] = 'build_fingerprint_mismatch';
		if ( self::CHATGPT_CLIENT_ID !== ( isset( $stored['client_id'] ) ? (string) $stored['client_id'] : '' ) ) $blockers[] = 'client_binding_mismatch';
		if ( self::SERVER_ID !== ( isset( $stored['server_id'] ) ? (string) $stored['server_id'] : '' ) ) $blockers[] = 'server_binding_mismatch';
		if ( ! $trusted ) $blockers[] = 'external_session_not_verified';

		$stored_context = isset( $stored['finalizer_context_digest'] ) ? strtolower( trim( (string) $stored['finalizer_context_digest'] ) ) : '';
		$current_context = isset( $external['finalizer_context_digest'] ) ? strtolower( trim( (string) $external['finalizer_context_digest'] ) ) : '';
		if ( ! self::valid_hash( $stored_context ) || ! self::valid_hash( $current_context ) || ! hash_equals( $stored_context, $current_context ) ) $blockers[] = 'external_session_binding_changed';

		$stored_tools = isset( $stored['external_tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $stored['external_tool_inventory_fingerprint'] ) ) : '';
		$current_tools = isset( $external['external_tool_inventory_fingerprint'] ) ? strtolower( trim( (string) $external['external_tool_inventory_fingerprint'] ) ) : '';
		if ( ! self::valid_hash( $stored_tools ) || ! self::valid_hash( $current_tools ) || ! hash_equals( $stored_tools, $current_tools ) ) $blockers[] = 'external_inventory_changed';
		$stored_write = isset( $stored['external_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $stored['external_write_inventory_fingerprint'] ) ) : '';
		$current_write = isset( $external['external_write_inventory_fingerprint'] ) ? strtolower( trim( (string) $external['external_write_inventory_fingerprint'] ) : '';
		if ( ! self::valid_hash( $stored_write ) || ! self::valid_hash( $current_write ) || ! hash_equals( $stored_write, $current_write ) ) $blockers[] = 'external_write_inventory_changed';

		$stored_token_digest = isset( $stored['external_snapshot_token_digest'] ) ? strtolower( trim( (string) $stored['external_snapshot_token_digest'] ) ) : '';
		$expected_digest = '' !== $expected ? hash( 'sha256', $expected ) : '';
		if ( ! self::valid_hash( $stored_token_digest ) || ! self::valid_hash( $expected_digest ) || ! hash_equals( $expected_digest, $stored_token_digest ) ) $blockers[] = 'external_package_token_mismatch';

		$observed_at = isset( $stored['observed_at'] ) ? (string) $stored['observed_at'] : '';
		$ts = '' !== $observed_at ? strtotime( $observed_at ) : false;
		$fresh = false !== $ts && $ts <= time() + 60 && ( time() - $ts ) <= self::TTL;
		if ( ! $fresh ) $blockers[] = 'stale_external_snapshot_attestation';

		$blockers = array_values( array_unique( $blockers ) );
		$ready = empty( $blockers );
		return self::gate( $ready, $ready ? 'ready' : 'pending_external_evidence', $fresh && $ready, self::ATTESTATION_CONTRACT, $blockers, $observed_at );
	}

	public static function live_acceptance_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$base_input = $input;
		unset( $base_input['client_snapshot_token'] );
		$base = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::live_acceptance_status( $base_input )
			: array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! is_array( $base ) ) $base = array( 'contract' => 'mad4b.live-acceptance-status.v1', 'gates' => array() );
		if ( ! isset( $base['gates'] ) || ! is_array( $base['gates'] ) ) $base['gates'] = array();

		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Reconciler' ) ) {
			$base['gates']['mutation_acceptance'] = MAD4B_SCP_Live_Acceptance_Reconciler::mutation_acceptance_status();
		}
		if ( ! empty( $input['client_snapshot_token'] ) ) {
			self::snapshot_verify( array( 'client_snapshot_token' => (string) $input['client_snapshot_token'] ) );
		}
		$base['gates']['snapshot_external'] = self::external_snapshot_status();
		$base['ready'] = class_exists( 'MAD4B_SCP_Live_Acceptance_Finalizer' )
			? MAD4B_SCP_Live_Acceptance_Finalizer::aggregate_ready( $base['gates'] )
			: self::all_ready( $base['gates'] );
		$base['state'] = $base['ready'] ? 'ready' : 'pending_or_blocked';
		$base['external_snapshot_finalizer_contract'] = self::CONTRACT;
		$base['external_facts_self_certified'] = false;
		return $base;
	}

	private static function expected_external_token( array $candidate ) {
		if ( empty( $candidate['ready'] ) || ! class_exists( 'MAD4B_SCP_Skill_Snapshot_Identity' ) || ! class_exists( 'MAD4B_SCP_Portable_Snapshot_Attestation' ) ) return '';
		$live = MAD4B_SCP_Skill_Snapshot_Identity::build();
		$snapshot = isset( $live['identity_token'] ) ? strtolower( trim( (string) $live['identity_token'] ) ) : '';
		if ( 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $snapshot ) ) return '';
		return MAD4B_SCP_Portable_Snapshot_Attestation::external_token( $snapshot, $candidate['source_commit_sha'], $candidate['build_fingerprint'] );
	}

	private static function trusted_external_context( array $external ) {
		if ( ! self::staging_allowed() ) return false;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $identity ) || ! is_array( $identity ) || empty( $identity['authenticated'] ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return false;
		$scopes = isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? $identity['token_scopes'] : array();
		if ( ! in_array( 'mad4b:read', $scopes, true ) ) return false;
		if ( empty( $external['verified'] ) || empty( $external['real_external_session'] ) || empty( $external['finalizer_subject_binding_verified'] ) ) return false;
		if ( self::CHATGPT_CLIENT_ID !== ( isset( $external['client_id'] ) ? (string) $external['client_id'] : '' ) ) return false;
		if ( self::SERVER_ID !== ( isset( $external['server_id'] ) ? (string) $external['server_id'] : '' ) ) return false;
		if ( empty( $external['session_fingerprint_present'] ) || empty( $external['inventory_match'] ) || empty( $external['write_inventory_fingerprint_match'] ) ) return false;
		if ( ! self::valid_hash( isset( $external['finalizer_context_digest'] ) ? strtolower( (string) $external['finalizer_context_digest'] ) : '' ) ) return false;
		if ( ! self::valid_hash( isset( $external['external_tool_inventory_fingerprint'] ) ? strtolower( (string) $external['external_tool_inventory_fingerprint'] ) : '' ) ) return false;
		if ( ! self::valid_hash( isset( $external['external_write_inventory_fingerprint'] ) ? strtolower( (string) $external['external_write_inventory_fingerprint'] ) : '' ) ) return false;
		return true;
	}

	private static function current_candidate() {
		$provenance = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( trim( (string) $provenance['source_commit_sha'] ) ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( trim( (string) $provenance['build_fingerprint'] ) : '';
		$ready = ! empty( $provenance['runtime_manifest_match'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/', $sha ) && self::valid_hash( $fingerprint );
		return array( 'ready' => (bool) $ready, 'source_commit_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}

	private static function staging_allowed() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : '';
		if ( 'staging' !== $environment ) return false;
		$home_host = function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) : '';
		$site_host = function_exists( 'site_url' ) ? strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ) ) : '';
		return self::STAGING_HOST === $home_host && self::STAGING_HOST === $site_host;
	}

	private static function valid_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ); }
	private static function all_ready( array $gates ) { foreach ( $gates as $gate ) if ( ! is_array( $gate ) || empty( $gate['ready'] ) ) return false; return ! empty( $gates ); }
	private static function gate( $ready, $state, $fresh, $contract, array $blockers, $observed_at = '' ) {
		return array(
			'ready' => (bool) $ready,
			'state' => (string) $state,
			'fresh' => (bool) $fresh,
			'evidence_contract' => (string) $contract,
			'observed_at' => (string) $observed_at,
			'blockers' => array_values( array_unique( array_filter( array_map( 'strval', $blockers ) ) ) ),
		);
	}
}

MAD4B_SCP_External_Snapshot_Finalizer::boot_early();
