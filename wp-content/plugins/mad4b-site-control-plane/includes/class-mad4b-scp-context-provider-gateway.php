<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Repository-owned Context provider gateway.
 *
 * This class is the only provider dispatch surface used by Brand Context
 * orchestration. Provider selection is derived from the governed Context
 * source registry; callers cannot supply an arbitrary runtime class.
 */
final class MAD4B_SCP_Context_Provider_Gateway {
	const CONTRACT = 'mad4b.context-provider-gateway.v1';
	const MATERIALIZATION_RECONCILIATION_CONTRACT = 'mad4b.brand-context-materialization-reconciliation.v1';
	const MATERIALIZATION_NO_EFFECT_CONTRACT = 'mad4b.brand-context-materialization-no-effect.v1';
	const MATERIALIZATION_ZERO_OBSERVATION_CONTRACT = 'mad4b.brand-context-materialization-zero-observation.v1';

	public static function boot() {
		add_filter( 'mad4b_scp_durable_reconciliation_verified', array( __CLASS__, 'verify_durable_reconciliation' ), 20, 3 );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}

	private static function valid_materialization_provider_identity( $identity ) {
		if ( ! is_array( $identity ) ) return false;
		$expected = array(
			'mad4b_kind' => 'brand_context',
			'mad4b_artifact' => isset( $identity['mad4b_artifact'] ) ? (string) $identity['mad4b_artifact'] : '',
			'mad4b_source' => isset( $identity['mad4b_source'] ) ? (string) $identity['mad4b_source'] : '',
			'mad4b_idempotency' => isset( $identity['mad4b_idempotency'] ) ? (string) $identity['mad4b_idempotency'] : '',
			'mad4b_request' => isset( $identity['mad4b_request'] ) ? (string) $identity['mad4b_request'] : '',
		);
		if ( ! isset( $identity['mad4b_kind'] ) || ! hash_equals( 'brand_context', (string) $identity['mad4b_kind'] ) ) return false;
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', strtolower( $expected['mad4b_artifact'] ) ) ) return false;
		foreach ( array( 'mad4b_source', 'mad4b_idempotency', 'mad4b_request' ) as $field ) if ( ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $expected[ $field ] ) ) ) return false;
		return true;
	}

	public static function materialization_reconciliation_ref( array $result ) {
		if ( self::MATERIALIZATION_RECONCILIATION_CONTRACT !== ( isset( $result['reconciliation_contract'] ) ? (string) $result['reconciliation_contract'] : '' ) ) return new WP_Error( 'mad4b_brand_reconciliation_contract_invalid', 'Brand materialization reconciliation contract is invalid.' );
		if ( empty( $result['provider_scan_complete'] ) || 1 !== (int) ( isset( $result['provider_candidate_count'] ) ? $result['provider_candidate_count'] : 0 ) ) return new WP_Error( 'mad4b_brand_reconciliation_evidence_invalid', 'Brand materialization reconciliation requires one exact candidate from a complete provider scan.' );
		if ( ! self::valid_materialization_provider_identity( isset( $result['provider_identity'] ) ? $result['provider_identity'] : array() ) ) return new WP_Error( 'mad4b_brand_reconciliation_provider_identity_invalid', 'Brand materialization reconciliation provider identity is invalid.' );
		$basis = array(
			'contract' => self::MATERIALIZATION_RECONCILIATION_CONTRACT,
			'artifact_id' => isset( $result['artifact_id'] ) ? (string) $result['artifact_id'] : '',
			'source_id' => isset( $result['source_id'] ) ? (string) $result['source_id'] : '',
			'asset_id' => isset( $result['asset_id'] ) ? (string) $result['asset_id'] : '',
			'file_id' => isset( $result['file_id'] ) ? (string) $result['file_id'] : '',
			'target_folder_id' => isset( $result['target_folder_id'] ) ? (string) $result['target_folder_id'] : '',
			'after_sha256' => isset( $result['after_sha256'] ) ? strtolower( (string) $result['after_sha256'] ) : '',
			'mime_type' => isset( $result['mime_type'] ) ? strtolower( (string) $result['mime_type'] ) : '',
			'format' => isset( $result['format'] ) ? sanitize_key( (string) $result['format'] ) : '',
			'provider_scan_generation' => isset( $result['provider_scan_generation'] ) ? (string) $result['provider_scan_generation'] : '',
			'provider_scan_complete' => ! empty( $result['provider_scan_complete'] ),
			'provider_candidate_count' => isset( $result['provider_candidate_count'] ) ? (int) $result['provider_candidate_count'] : 0,
			'provider_identity' => isset( $result['provider_identity'] ) && is_array( $result['provider_identity'] ) ? $result['provider_identity'] : array(),
		);
		foreach ( array( 'artifact_id', 'source_id', 'asset_id', 'file_id', 'target_folder_id', 'after_sha256', 'mime_type', 'format', 'provider_scan_generation' ) as $field ) if ( '' === (string) $basis[ $field ] ) return new WP_Error( 'mad4b_brand_reconciliation_binding_incomplete', 'Brand materialization reconciliation binding is incomplete.', array( 'field' => $field ) );
		$json = wp_json_encode( self::canonicalize( $basis ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_brand_reconciliation_encoding_failed', 'Brand materialization reconciliation evidence could not be encoded.' );
		return 'brand-materialization:' . hash( 'sha256', $json );
	}

	public static function materialization_zero_observation_ref( array $observation ) {
		if ( self::MATERIALIZATION_ZERO_OBSERVATION_CONTRACT !== ( isset( $observation['reconciliation_contract'] ) ? (string) $observation['reconciliation_contract'] : '' ) ) return new WP_Error( 'mad4b_brand_zero_observation_contract_invalid', 'Brand materialization zero-effect observation contract is invalid.' );
		if ( empty( $observation['provider_scan_complete'] ) || 0 !== (int) ( isset( $observation['provider_candidate_count'] ) ? $observation['provider_candidate_count'] : -1 ) ) return new WP_Error( 'mad4b_brand_zero_observation_invalid', 'Brand materialization zero-effect observation requires a complete provider scan with zero exact candidates.' );
		if ( ! self::valid_materialization_provider_identity( isset( $observation['provider_identity'] ) ? $observation['provider_identity'] : array() ) ) return new WP_Error( 'mad4b_brand_zero_observation_provider_identity_invalid', 'Brand materialization zero-effect observation provider identity is invalid.' );
		$basis = array(
			'contract' => self::MATERIALIZATION_ZERO_OBSERVATION_CONTRACT,
			'artifact_id' => isset( $observation['artifact_id'] ) ? (string) $observation['artifact_id'] : '',
			'source_id' => isset( $observation['source_id'] ) ? (string) $observation['source_id'] : '',
			'target_folder_id' => isset( $observation['target_folder_id'] ) ? (string) $observation['target_folder_id'] : '',
			'expected_name' => isset( $observation['expected_name'] ) ? (string) $observation['expected_name'] : '',
			'expected_content_sha256' => isset( $observation['expected_content_sha256'] ) ? strtolower( (string) $observation['expected_content_sha256'] ) : '',
			'expected_mime_type' => isset( $observation['expected_mime_type'] ) ? strtolower( (string) $observation['expected_mime_type'] ) : '',
			'format' => isset( $observation['format'] ) ? sanitize_key( (string) $observation['format'] ) : '',
			'provider_scan_generation' => isset( $observation['provider_scan_generation'] ) ? (string) $observation['provider_scan_generation'] : '',
			'provider_scan_complete' => true,
			'provider_candidate_count' => 0,
			'provider_identity' => isset( $observation['provider_identity'] ) && is_array( $observation['provider_identity'] ) ? $observation['provider_identity'] : array(),
		);
		foreach ( array( 'artifact_id', 'source_id', 'target_folder_id', 'expected_name', 'expected_content_sha256', 'expected_mime_type', 'format', 'provider_scan_generation' ) as $field ) if ( '' === (string) $basis[ $field ] ) return new WP_Error( 'mad4b_brand_zero_observation_binding_incomplete', 'Brand materialization zero-effect observation binding is incomplete.', array( 'field' => $field ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $basis['expected_content_sha256'] ) ) return new WP_Error( 'mad4b_brand_zero_observation_content_hash_invalid', 'Brand materialization zero-effect observation requires an exact SHA-256 content identity.' );
		$json = wp_json_encode( self::canonicalize( $basis ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_brand_zero_observation_encoding_failed', 'Brand materialization zero-effect observation could not be encoded.' );
		return 'brand-materialization-zero:' . hash( 'sha256', $json );
	}

	public static function materialization_no_effect_ref( array $proof ) {
		if ( self::MATERIALIZATION_NO_EFFECT_CONTRACT !== ( isset( $proof['reconciliation_contract'] ) ? (string) $proof['reconciliation_contract'] : '' ) ) return new WP_Error( 'mad4b_brand_no_effect_contract_invalid', 'Brand materialization no-effect reconciliation contract is invalid.' );
		if ( empty( $proof['provider_scan_complete'] ) || 0 !== (int) ( isset( $proof['provider_candidate_count'] ) ? $proof['provider_candidate_count'] : -1 ) ) return new WP_Error( 'mad4b_brand_no_effect_evidence_invalid', 'Brand materialization no-effect reconciliation requires a complete provider scan with zero exact candidates.' );
		if ( ! self::valid_materialization_provider_identity( isset( $proof['provider_identity'] ) ? $proof['provider_identity'] : array() ) ) return new WP_Error( 'mad4b_brand_no_effect_provider_identity_invalid', 'Brand materialization no-effect provider identity is invalid.' );
		$observation_count = isset( $proof['durable_observation_count'] ) ? (int) $proof['durable_observation_count'] : 0;
		$observation_elapsed = isset( $proof['durable_observation_elapsed_seconds'] ) ? (int) $proof['durable_observation_elapsed_seconds'] : 0;
		$scan_generations = isset( $proof['durable_scan_generations'] ) && is_array( $proof['durable_scan_generations'] ) ? array_values( array_unique( array_filter( array_map( 'strval', $proof['durable_scan_generations'] ) ) ) ) : array();
		if ( $observation_count < 2 || count( $scan_generations ) < 2 ) return new WP_Error( 'mad4b_brand_no_effect_observations_insufficient', 'Brand materialization no-effect proof requires at least two distinct durable provider observations.' );
		$minimum_interval = class_exists( 'MAD4B_SCP_Durable_Execution' ) ? MAD4B_SCP_Durable_Execution::NO_EFFECT_MIN_OBSERVATION_SECONDS : 60;
		if ( $observation_elapsed < $minimum_interval ) return new WP_Error( 'mad4b_brand_no_effect_observation_window_pending', 'Brand materialization no-effect observations have not reached the certified minimum interval.', array( 'elapsed_seconds' => $observation_elapsed, 'minimum_interval_seconds' => $minimum_interval ) );
		$basis = array(
			'contract' => self::MATERIALIZATION_NO_EFFECT_CONTRACT,
			'artifact_id' => isset( $proof['artifact_id'] ) ? (string) $proof['artifact_id'] : '',
			'source_id' => isset( $proof['source_id'] ) ? (string) $proof['source_id'] : '',
			'target_folder_id' => isset( $proof['target_folder_id'] ) ? (string) $proof['target_folder_id'] : '',
			'expected_name' => isset( $proof['expected_name'] ) ? (string) $proof['expected_name'] : '',
			'expected_content_sha256' => isset( $proof['expected_content_sha256'] ) ? strtolower( (string) $proof['expected_content_sha256'] ) : '',
			'expected_mime_type' => isset( $proof['expected_mime_type'] ) ? strtolower( (string) $proof['expected_mime_type'] ) : '',
			'format' => isset( $proof['format'] ) ? sanitize_key( (string) $proof['format'] ) : '',
			'provider_scan_generation' => isset( $proof['provider_scan_generation'] ) ? (string) $proof['provider_scan_generation'] : '',
			'provider_scan_complete' => ! empty( $proof['provider_scan_complete'] ),
			'provider_candidate_count' => isset( $proof['provider_candidate_count'] ) ? (int) $proof['provider_candidate_count'] : -1,
			'provider_identity' => isset( $proof['provider_identity'] ) && is_array( $proof['provider_identity'] ) ? $proof['provider_identity'] : array(),
			'durable_observation_count' => $observation_count,
			'durable_observation_elapsed_seconds' => $observation_elapsed,
			'durable_scan_generations' => $scan_generations,
		);
		foreach ( array( 'artifact_id', 'source_id', 'target_folder_id', 'expected_name', 'expected_content_sha256', 'expected_mime_type', 'format', 'provider_scan_generation' ) as $field ) if ( '' === (string) $basis[ $field ] ) return new WP_Error( 'mad4b_brand_no_effect_binding_incomplete', 'Brand materialization no-effect reconciliation binding is incomplete.', array( 'field' => $field ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $basis['expected_content_sha256'] ) ) return new WP_Error( 'mad4b_brand_no_effect_content_hash_invalid', 'Brand materialization no-effect reconciliation requires an exact SHA-256 content identity.' );
		$json = wp_json_encode( self::canonicalize( $basis ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) return new WP_Error( 'mad4b_brand_no_effect_encoding_failed', 'Brand materialization no-effect reconciliation evidence could not be encoded.' );
		return 'brand-materialization-no-effect:' . hash( 'sha256', $json );
	}

	public static function verify_durable_reconciliation( $verified, $kind, $context ) {
		if ( true === $verified ) return true;
		$kind = sanitize_key( (string) $kind );
		if ( ! is_array( $context ) ) return $verified;
		$result = isset( $context['result'] ) && is_array( $context['result'] ) ? $context['result'] : array();
		$actual = isset( $context['reconciliation_ref'] ) ? (string) $context['reconciliation_ref'] : '';
		if ( 'idempotency_observation' === $kind && self::MATERIALIZATION_ZERO_OBSERVATION_CONTRACT === ( isset( $result['reconciliation_contract'] ) ? (string) $result['reconciliation_contract'] : '' ) ) {
			$expected = self::materialization_zero_observation_ref( $result );
			return ! is_wp_error( $expected ) && '' !== $actual && hash_equals( (string) $expected, $actual );
		}
		if ( 'idempotency_completion' === $kind && self::MATERIALIZATION_RECONCILIATION_CONTRACT === ( isset( $result['reconciliation_contract'] ) ? (string) $result['reconciliation_contract'] : '' ) ) {
			$expected = self::materialization_reconciliation_ref( $result );
			return ! is_wp_error( $expected ) && '' !== $actual && hash_equals( (string) $expected, $actual );
		}
		if ( 'idempotency_no_effect' === $kind && self::MATERIALIZATION_NO_EFFECT_CONTRACT === ( isset( $result['reconciliation_contract'] ) ? (string) $result['reconciliation_contract'] : '' ) ) {
			$expected = self::materialization_no_effect_ref( $result );
			return ! is_wp_error( $expected ) && '' !== $actual && hash_equals( (string) $expected, $actual );
		}
		return $verified;
	}

	private static function source( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		if ( '' === $source_id || ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) {
			return new WP_Error( 'mad4b_context_provider_source_unavailable', 'Context source authority is unavailable.' );
		}
		$source = MAD4B_SCP_Context_Authority::source( $source_id );
		if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		return $source;
	}

	private static function provider_from_source( array $source ) {
		$provider = sanitize_key( isset( $source['provider'] ) ? (string) $source['provider'] : '' );
		if ( 'google_drive' === $provider && class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) return $provider;
		return new WP_Error(
			'mad4b_context_provider_unsupported',
			'Context source provider is not certified by the repository-owned provider gateway.',
			array( 'provider' => $provider )
		);
	}

	public static function capabilities() {
		return array(
			'contract' => self::CONTRACT,
			'providers' => array(
				'google_drive' => array(
					'read_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'read_context_asset' ),
					'scan_source' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'scan_folder' ),
					'create_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'create_asset' ),
					'create_brand_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'create_brand_asset' ),
					'find_brand_materialization_candidates' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'find_brand_materialization_candidates' ),
					'rollback_created_brand_asset' => class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'rollback_created_brand_asset' ),
				),
			),
			'dynamic_provider_class_selection' => false,
			'materialization_reconciliation' => array(
				'contract' => self::MATERIALIZATION_RECONCILIATION_CONTRACT,
				'no_effect_contract' => self::MATERIALIZATION_NO_EFFECT_CONTRACT,
				'zero_observation_contract' => self::MATERIALIZATION_ZERO_OBSERVATION_CONTRACT,
				'minimum_no_effect_observations' => 2,
				'minimum_observation_interval_seconds' => class_exists( 'MAD4B_SCP_Durable_Execution' ) ? MAD4B_SCP_Durable_Execution::NO_EFFECT_MIN_OBSERVATION_SECONDS : 60,
				'complete_scan_required' => true,
				'exact_candidate_count_required' => 1,
				'durable_idempotency_completion_supported' => true,
				'durable_no_effect_release_supported' => true,
			),
			'authority_widening' => false,
		);
	}

	public static function read_context_asset( $asset_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_context_authority_unavailable', 'Context Authority is unavailable.' );
		$asset = MAD4B_SCP_Context_Authority::asset( $asset_id );
		if ( empty( $asset ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		$source = self::source( isset( $asset['source_id'] ) ? $asset['source_id'] : '' );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::read_context_asset( $asset_id );
		return new WP_Error( 'mad4b_context_provider_read_unsupported', 'Context provider does not expose certified asset readback.' );
	}

	public static function scan_source( $source_id ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) {
			return MAD4B_SCP_Google_Drive_Context::scan_folder(
				isset( $source['external_root_id'] ) ? $source['external_root_id'] : '',
				! empty( $source['recursive'] )
			);
		}
		return new WP_Error( 'mad4b_context_provider_scan_unsupported', 'Context provider does not expose certified source scanning.' );
	}

	public static function create_asset( $source_id, $name, $content, $format ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::create_asset( $source_id, $name, $content, $format );
		return new WP_Error( 'mad4b_context_provider_create_unsupported', 'Context provider does not expose certified asset creation.' );
	}

	public static function find_brand_materialization_candidates( $source_id, array $identity ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::find_brand_materialization_candidates( $source_id, $identity );
		return new WP_Error( 'mad4b_context_provider_brand_lookup_unsupported', 'Context provider does not expose certified Brand materialization identity lookup.' );
	}

	public static function create_brand_asset( $source_id, $name, $content, $format, array $identity ) {
		$source = self::source( $source_id );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::create_brand_asset( $source_id, $name, $content, $format, $identity );
		return new WP_Error( 'mad4b_context_provider_brand_create_unsupported', 'Context provider does not expose certified Brand Context creation.' );
	}

	public static function rollback_created_brand_asset( array $receipt, $allow_unmarked = false ) {
		$source = self::source( isset( $receipt['source_id'] ) ? $receipt['source_id'] : '' );
		if ( is_wp_error( $source ) ) return $source;
		$provider = self::provider_from_source( $source );
		if ( is_wp_error( $provider ) ) return $provider;
		if ( 'google_drive' === $provider ) return MAD4B_SCP_Google_Drive_Context::rollback_created_brand_asset( $receipt, $allow_unmarked );
		return new WP_Error( 'mad4b_context_provider_rollback_unsupported', 'Context provider does not expose certified Brand Context rollback.' );
	}
}
