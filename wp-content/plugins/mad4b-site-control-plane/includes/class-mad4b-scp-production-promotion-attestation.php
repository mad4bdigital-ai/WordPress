<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Non-authorizing bridge from an exact Staging Production-readiness verdict to
 * the one-time Production Site Profile write-enable transition.
 */
final class MAD4B_SCP_Production_Promotion_Attestation {
	const CONTRACT = 'mad4b.production-promotion-attestation.v1';
	const STAGING_SOURCE_CONTRACT = 'mad4b.external-staging-readiness-observer.v1';
	const VERDICT_CONTRACT = 'mad4b.production-live-evidence-verdict.v1';
	const EVIDENCE_TRUST_CONTRACT = 'mad4b.production-evidence-trust.v1';
	const PROFILE = 'control_plane_core';
	const FINALIZER_ISSUER = 'chatgpt_external_read_only_finalizer';
	const FINALIZER_PROVENANCE = 'verified_oauth_readonly_session';
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const SERVER_ID = 'mad4b-chatgpt';
	const TTL = 1800;
	const MAX_BYTES = 262144;

	public static function schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'contract' => array( 'type' => 'string', 'enum' => array( self::CONTRACT ) ),
				'profile' => array( 'type' => 'string', 'enum' => array( self::PROFILE ) ),
				'target' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'environment' => array( 'type' => 'string', 'enum' => array( 'production' ) ),
				'staging_origin' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 240 ),
				'production_origin' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 240 ),
				'site_uuid' => array( 'type' => 'string', 'pattern' => '^[a-f0-9-]{36}$' ),
				'site_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'site_profile_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'candidate_identity' => array(
					'type' => 'object',
					'properties' => array(
						'source_commit_sha' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{40}$' ),
						'build_fingerprint' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'package_manifest_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'artifact_identity' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
					),
					'required' => array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ),
					'additionalProperties' => false,
				),
				'staging_source' => array(
					'type' => 'object',
					'properties' => array(
						'contract' => array( 'type' => 'string', 'enum' => array( self::STAGING_SOURCE_CONTRACT ) ),
						'environment' => array( 'type' => 'string', 'enum' => array( 'staging' ) ),
						'origin' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 240 ),
						'ability' => array( 'type' => 'string', 'enum' => array( 'mad4b/production-readiness-evaluate' ) ),
						'verdict_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					),
					'required' => array( 'contract', 'environment', 'origin', 'ability', 'verdict_sha256' ),
					'additionalProperties' => false,
				),
				'staging_readiness_verdict' => array( 'type' => 'object', 'additionalProperties' => true ),
				'staging_readiness_verdict_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'production_unchanged_receipt' => array( 'type' => 'object', 'additionalProperties' => true ),
				'issued_at' => array( 'type' => 'string', 'minLength' => 19, 'maxLength' => 40 ),
				'issuer' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_ISSUER ) ),
				'provenance' => array( 'type' => 'string', 'enum' => array( self::FINALIZER_PROVENANCE ) ),
				'evidence_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			),
			'required' => array(
				'contract', 'profile', 'target', 'environment', 'staging_origin', 'production_origin',
				'site_uuid', 'site_profile_revision', 'site_profile_digest', 'candidate_identity',
				'staging_source', 'staging_readiness_verdict', 'staging_readiness_verdict_sha256',
				'production_unchanged_receipt', 'issued_at', 'issuer', 'provenance', 'evidence_digest',
			),
			'additionalProperties' => false,
		);
	}

	public static function validate_for_activation( array $attestation, $expected_revision, $expected_profile_digest, $expected_source_commit_sha, $expected_build_fingerprint ) {
		$identity = self::current_candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		$trusted = self::trusted_production_finalizer_context( $identity );
		return self::evaluate(
			$attestation,
			absint( $expected_revision ),
			strtolower( trim( (string) $expected_profile_digest ) ),
			strtolower( trim( (string) $expected_source_commit_sha ) ),
			strtolower( trim( (string) $expected_build_fingerprint ) ),
			$identity,
			$trusted,
			null
		);
	}

	public static function evaluate( array $attestation, $expected_revision, $expected_profile_digest, $expected_source_commit_sha, $expected_build_fingerprint, array $identity, $trusted_context, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$encoded = wp_json_encode( $attestation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) return self::error( 'mad4b_production_promotion_attestation_oversized', 'Production Promotion Attestation exceeds its bounded transport budget.' );
		if ( self::CONTRACT !== ( isset( $attestation['contract'] ) ? (string) $attestation['contract'] : '' ) ) return self::error( 'mad4b_production_promotion_attestation_contract_invalid', 'Production Promotion Attestation contract is invalid.' );
		if ( self::PROFILE !== ( isset( $attestation['profile'] ) ? (string) $attestation['profile'] : '' ) ) return self::error( 'mad4b_production_promotion_profile_invalid', 'Production Promotion Attestation must target the control_plane_core readiness profile.' );
		if ( 'production' !== ( isset( $attestation['target'] ) ? sanitize_key( (string) $attestation['target'] ) : '' )
			|| 'production' !== ( isset( $attestation['environment'] ) ? sanitize_key( (string) $attestation['environment'] ) : '' ) ) return self::error( 'mad4b_production_promotion_target_invalid', 'Production Promotion Attestation is not bound to Production.' );
		if ( true !== $trusted_context ) return self::error( 'mad4b_production_promotion_finalizer_untrusted', 'Production promotion requires a verified external ChatGPT finalizer session on the exact Production runtime.' );
		if ( self::FINALIZER_ISSUER !== ( isset( $attestation['issuer'] ) ? (string) $attestation['issuer'] : '' )
			|| self::FINALIZER_PROVENANCE !== ( isset( $attestation['provenance'] ) ? (string) $attestation['provenance'] : '' ) ) return self::error( 'mad4b_production_promotion_provenance_invalid', 'Production Promotion Attestation provenance is not trusted.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || 'production' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return self::error( 'mad4b_production_promotion_environment_invalid', 'Production Promotion Attestation may be consumed only on the enrolled Production Site Profile.' );

		$site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
		$current_revision = (int) MAD4B_SCP_Site_Profile::revision();
		$current_digest = strtolower( trim( (string) MAD4B_SCP_Site_Profile::profile_digest() ) );
		$staging_origin = rtrim( (string) MAD4B_SCP_Site_Profile::related_origin( 'staging' ), '/' );
		$production_origin = rtrim( (string) MAD4B_SCP_Site_Profile::related_origin( 'production' ), '/' );
		if ( $expected_revision < 1 || $current_revision !== (int) $expected_revision || ! self::valid_hash( $expected_profile_digest ) || ! hash_equals( $current_digest, $expected_profile_digest ) ) return self::error( 'mad4b_production_promotion_profile_binding_stale', 'Production Site Profile changed after promotion review.' );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid )
			|| ! hash_equals( $site_uuid, strtolower( trim( (string) ( isset( $attestation['site_uuid'] ) ? $attestation['site_uuid'] : '' ) ) ) )
			|| $current_revision !== (int) ( isset( $attestation['site_profile_revision'] ) ? $attestation['site_profile_revision'] : 0 )
			|| ! hash_equals( $current_digest, strtolower( trim( (string) ( isset( $attestation['site_profile_digest'] ) ? $attestation['site_profile_digest'] : '' ) ) ) ) ) return self::error( 'mad4b_production_promotion_site_binding_mismatch', 'Production Promotion Attestation does not match the exact Site Profile revision and digest.' );
		if ( '' === $staging_origin || '' === $production_origin
			|| ! hash_equals( $staging_origin, rtrim( (string) ( isset( $attestation['staging_origin'] ) ? $attestation['staging_origin'] : '' ), '/' ) )
			|| ! hash_equals( $production_origin, rtrim( (string) ( isset( $attestation['production_origin'] ) ? $attestation['production_origin'] : '' ), '/' ) ) ) return self::error( 'mad4b_production_promotion_origin_binding_mismatch', 'Production Promotion Attestation origin binding is stale or invalid.' );

		$current_identity = self::normalize_identity( $identity );
		if ( is_wp_error( $current_identity ) ) return $current_identity;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $expected_source_commit_sha ) || ! self::valid_hash( $expected_build_fingerprint )
			|| ! hash_equals( $current_identity['source_commit_sha'], $expected_source_commit_sha )
			|| ! hash_equals( $current_identity['build_fingerprint'], $expected_build_fingerprint ) ) return self::error( 'mad4b_production_promotion_candidate_stale', 'Production runtime candidate changed after promotion review.' );
		$attested_identity = self::normalize_identity( isset( $attestation['candidate_identity'] ) && is_array( $attestation['candidate_identity'] ) ? $attestation['candidate_identity'] : array() );
		if ( is_wp_error( $attested_identity ) || ! self::identity_matches( $current_identity, $attested_identity ) ) return self::error( 'mad4b_production_promotion_candidate_mismatch', 'Production Promotion Attestation is not bound to the exact current runtime candidate.' );

		$source = isset( $attestation['staging_source'] ) && is_array( $attestation['staging_source'] ) ? $attestation['staging_source'] : array();
		$declared_verdict_sha = strtolower( trim( (string) ( isset( $attestation['staging_readiness_verdict_sha256'] ) ? $attestation['staging_readiness_verdict_sha256'] : '' ) ) );
		if ( self::STAGING_SOURCE_CONTRACT !== ( isset( $source['contract'] ) ? (string) $source['contract'] : '' )
			|| 'staging' !== ( isset( $source['environment'] ) ? sanitize_key( (string) $source['environment'] ) : '' )
			|| 'mad4b/production-readiness-evaluate' !== ( isset( $source['ability'] ) ? (string) $source['ability'] : '' )
			|| ! hash_equals( $staging_origin, rtrim( (string) ( isset( $source['origin'] ) ? $source['origin'] : '' ), '/' ) )
			|| ! self::valid_hash( $declared_verdict_sha )
			|| ! isset( $source['verdict_sha256'] ) || ! hash_equals( $declared_verdict_sha, strtolower( (string) $source['verdict_sha256'] ) ) ) return self::error( 'mad4b_production_promotion_staging_source_invalid', 'Production Promotion Attestation does not identify the exact Staging readiness source.' );

		$verdict = isset( $attestation['staging_readiness_verdict'] ) && is_array( $attestation['staging_readiness_verdict'] ) ? $attestation['staging_readiness_verdict'] : array();
		$verdict_check = self::validate_readiness_verdict( $verdict, $current_identity );
		if ( is_wp_error( $verdict_check ) ) return $verdict_check;
		$actual_verdict_sha = self::verdict_digest( $verdict );
		if ( ! hash_equals( $declared_verdict_sha, $actual_verdict_sha ) ) return self::error( 'mad4b_production_promotion_verdict_digest_mismatch', 'Staging Production-readiness verdict digest does not match its reviewed payload.' );

		$issued = self::parse_time( isset( $attestation['issued_at'] ) ? $attestation['issued_at'] : '' );
		if ( false === $issued || $issued > $now + 60 || $issued < $now - self::TTL ) return self::error( 'mad4b_production_promotion_attestation_stale', 'Production Promotion Attestation is stale or has an invalid issue time.' );
		$provided_digest = strtolower( trim( (string) ( isset( $attestation['evidence_digest'] ) ? $attestation['evidence_digest'] : '' ) ) );
		$computed_digest = self::attestation_digest( $attestation );
		if ( ! self::valid_hash( $provided_digest ) || ! hash_equals( $computed_digest, $provided_digest ) ) return self::error( 'mad4b_production_promotion_attestation_digest_mismatch', 'Production Promotion Attestation digest does not match its exact payload.' );

		if ( ! class_exists( 'MAD4B_SCP_Production_Unchanged_Attestation' ) || ! method_exists( 'MAD4B_SCP_Production_Unchanged_Attestation', 'evaluate_receipt' ) ) return self::error( 'mad4b_production_promotion_unchanged_verifier_unavailable', 'Production unchanged-state verifier is unavailable.' );
		$production_receipt = isset( $attestation['production_unchanged_receipt'] ) && is_array( $attestation['production_unchanged_receipt'] ) ? $attestation['production_unchanged_receipt'] : array();
		$unchanged = MAD4B_SCP_Production_Unchanged_Attestation::evaluate_receipt( $production_receipt, $current_identity, true, $now );
		if ( is_wp_error( $unchanged ) || ! is_array( $unchanged ) || empty( $unchanged['ready'] ) || empty( $unchanged['fresh'] ) || ! empty( $unchanged['blockers'] ) ) return self::error( 'mad4b_production_promotion_production_unchanged_required', 'Production promotion requires fresh trusted evidence that the Production runtime and plugin inventory remain unchanged.', array( 'unchanged_state' => is_array( $unchanged ) && isset( $unchanged['state'] ) ? (string) $unchanged['state'] : 'unavailable' ) );

		return array(
			'contract' => self::CONTRACT,
			'ready' => true,
			'profile' => self::PROFILE,
			'production_authorized' => false,
			'authorizing' => false,
			'promotion_required' => true,
			'one_time_binding' => 'site_profile_revision_digest_transition',
			'promotion_attestation_sha256' => $provided_digest,
			'staging_readiness_verdict_sha256' => $declared_verdict_sha,
			'production_runtime_identity' => isset( $unchanged['production_runtime_identity'] ) ? (string) $unchanged['production_runtime_identity'] : '',
			'candidate_identity' => $current_identity,
			'site_profile_revision' => $current_revision,
			'site_profile_digest' => $current_digest,
			'fresh' => true,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}

	public static function attestation_digest( array $attestation ) { unset( $attestation['evidence_digest'] ); return hash( 'sha256', self::canonical_json( $attestation ) ); }
	public static function verdict_digest( array $verdict ) { return hash( 'sha256', self::canonical_json( $verdict ) ); }

	private static function validate_readiness_verdict( array $verdict, array $identity ) {
		if ( self::VERDICT_CONTRACT !== ( isset( $verdict['contract'] ) ? (string) $verdict['contract'] : '' ) || self::PROFILE !== ( isset( $verdict['profile'] ) ? (string) $verdict['profile'] : '' ) ) return self::error( 'mad4b_production_promotion_verdict_contract_invalid', 'Staging Production-readiness verdict contract/profile is invalid.' );
		if ( empty( $verdict['production_ready'] ) || ! empty( $verdict['production_authorized'] ) || empty( $verdict['promotion_required'] ) || ! empty( $verdict['authorizing'] ) || ! empty( $verdict['mutation_performed'] ) || ! empty( $verdict['production_mutation'] ) ) return self::error( 'mad4b_production_promotion_verdict_not_ready', 'Staging verdict is not a non-authorizing Production-ready verdict.' );
		if ( self::EVIDENCE_TRUST_CONTRACT !== ( isset( $verdict['evidence_trust_contract'] ) ? (string) $verdict['evidence_trust_contract'] : '' ) ) return self::error( 'mad4b_production_promotion_verdict_trust_invalid', 'Staging verdict does not use the certified Production evidence trust contract.' );
		$gate_count = isset( $verdict['evidence_gate_count'] ) ? (int) $verdict['evidence_gate_count'] : 0;
		$trusted_count = isset( $verdict['trusted_evidence_gate_count'] ) ? (int) $verdict['trusted_evidence_gate_count'] : -1;
		if ( $gate_count < 1 || $trusted_count !== $gate_count ) return self::error( 'mad4b_production_promotion_verdict_evidence_incomplete', 'Staging verdict is missing trusted evidence for one or more required gates.' );
		$verdict_identity = self::normalize_identity( isset( $verdict['candidate_identity'] ) && is_array( $verdict['candidate_identity'] ) ? $verdict['candidate_identity'] : array() );
		if ( is_wp_error( $verdict_identity ) || ! self::identity_matches( $identity, $verdict_identity ) ) return self::error( 'mad4b_production_promotion_verdict_candidate_mismatch', 'Staging readiness verdict belongs to a different runtime candidate.' );
		return true;
	}

	private static function current_candidate_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) return self::error( 'mad4b_production_promotion_candidate_unavailable', 'Exact Production runtime candidate identity is unavailable.' );
		$identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
		if ( ! is_array( $identity ) || empty( $identity['identity_ready'] ) ) return self::error( 'mad4b_production_promotion_candidate_not_ready', 'Exact Production runtime candidate identity is not ready.' );
		return self::normalize_identity( $identity );
	}

	private static function trusted_production_finalizer_context( array $identity ) {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return false;
		$current = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : null;
		if ( is_wp_error( $current ) || ! is_array( $current ) || empty( $current['authenticated'] ) || 'oauth2_bearer' !== ( isset( $current['auth_method'] ) ? (string) $current['auth_method'] : '' ) ) return false;
		$scopes = isset( $current['token_scopes'] ) && is_array( $current['token_scopes'] ) ? array_values( array_unique( array_map( 'strval', $current['token_scopes'] ) ) ) : array();
		$step_up = defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' ) ? MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE : 'mad4b:authority:step-up';
		if ( ! in_array( 'mad4b:read', $scopes, true ) || ! in_array( $step_up, $scopes, true ) ) return false;
		$external = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) ? MAD4B_SCP_External_Handshake_Evidence::status() : array();
		if ( ! is_array( $external ) || empty( $external['verified'] ) || 'production' !== ( isset( $external['environment'] ) ? sanitize_key( (string) $external['environment'] ) : '' ) || self::SERVER_ID !== ( isset( $external['server_id'] ) ? (string) $external['server_id'] : '' ) || self::CHATGPT_CLIENT_ID !== ( isset( $external['client_id'] ) ? (string) $external['client_id'] : '' ) || 'oauth2_bearer' !== ( isset( $external['auth_method'] ) ? (string) $external['auth_method'] : '' ) || empty( $external['package_identity_match'] ) ) return false;
		$external_identity = array(
			'source_commit_sha' => isset( $external['source_commit_sha'] ) ? $external['source_commit_sha'] : '',
			'build_fingerprint' => isset( $external['package_build_fingerprint'] ) ? $external['package_build_fingerprint'] : '',
			'package_manifest_digest' => isset( $external['package_manifest_digest'] ) ? $external['package_manifest_digest'] : '',
			'artifact_identity' => isset( $external['artifact_identity'] ) ? $external['artifact_identity'] : '',
		);
		$normalized = self::normalize_identity( $external_identity );
		return ! is_wp_error( $normalized ) && self::identity_matches( $identity, $normalized );
	}

	private static function normalize_identity( array $identity ) {
		$out = array(
			'source_commit_sha' => strtolower( trim( (string) ( isset( $identity['source_commit_sha'] ) ? $identity['source_commit_sha'] : '' ) ) ),
			'build_fingerprint' => strtolower( trim( (string) ( isset( $identity['build_fingerprint'] ) ? $identity['build_fingerprint'] : '' ) ) ),
			'package_manifest_digest' => strtolower( trim( (string) ( isset( $identity['package_manifest_digest'] ) ? $identity['package_manifest_digest'] : '' ) ) ),
			'artifact_identity' => trim( (string) ( isset( $identity['artifact_identity'] ) ? $identity['artifact_identity'] : '' ) ),
		);
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $out['source_commit_sha'] ) || ! self::valid_hash( $out['build_fingerprint'] ) || ! self::valid_hash( $out['package_manifest_digest'] ) || '' === $out['artifact_identity'] || strlen( $out['artifact_identity'] ) > 191 ) return self::error( 'mad4b_production_promotion_candidate_identity_invalid', 'Production promotion candidate identity is incomplete or malformed.' );
		return $out;
	}

	private static function identity_matches( array $left, array $right ) {
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $key ) if ( ! isset( $left[ $key ], $right[ $key ] ) || ! hash_equals( (string) $left[ $key ], (string) $right[ $key ] ) ) return false;
		return true;
	}
	private static function canonical_json( $value ) { $normalized = self::canonicalize( $value ); $json = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return is_string( $json ) ? $json : ''; }
	private static function canonicalize( $value ) { if ( ! is_array( $value ) ) return $value; $keys = array_keys( $value ); $is_list = empty( $value ) || $keys === range( 0, count( $value ) - 1 ); if ( $is_list ) return array_map( array( __CLASS__, 'canonicalize' ), $value ); ksort( $value, SORT_STRING ); foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item ); return $value; }
	private static function parse_time( $value ) { $value = trim( (string) $value ); return '' === $value ? false : strtotime( $value . ( preg_match( '/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $value ) ? '' : ' UTC' ) ); }
	private static function valid_hash( $value ) { return 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( (string) $value ) ) ); }
	private static function error( $code, $message, array $extra = array() ) { return new WP_Error( sanitize_key( (string) $code ), (string) $message, array_merge( array( 'mutation_state'=>'not_started','target_execution_entered'=>false,'production_authorized'=>false,'blind_retry_allowed'=>false,'fresh_promotion_attestation_required'=>true ), $extra ) ); }
}
