<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider evidence-rights and retention constraints.
 */
final class MAD4B_SCP_Search_Evidence_Policy {
	const CONTRACT = 'mad4b.search-evidence-policy.v1';

	public static function normalize_provider_policy( array $input ) {
		$regions = array_values( array_unique( array_filter( array_map( static function ( $value ) {
			return strtoupper( trim( (string) $value ) );
		}, is_array( $input['allowed_storage_regions'] ?? null ) ? $input['allowed_storage_regions'] : array() ) ) ) );
		sort( $regions, SORT_STRING );
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => sanitize_key( (string) ( $input['provider_id'] ?? '' ) ),
			'policy_version' => sanitize_text_field( (string) ( $input['policy_version'] ?? 'unknown' ) ),
			'raw_retention_permitted' => ! empty( $input['raw_retention_permitted'] ),
			'max_raw_retention_seconds' => max( 0, (int) ( $input['max_raw_retention_seconds'] ?? 0 ) ),
			'normalized_retention_permitted' => array_key_exists( 'normalized_retention_permitted', $input ) ? (bool) $input['normalized_retention_permitted'] : true,
			'max_normalized_retention_seconds' => max( 0, (int) ( $input['max_normalized_retention_seconds'] ?? 0 ) ),
			'redistribution_class' => sanitize_key( (string) ( $input['redistribution_class'] ?? 'internal_only' ) ),
			'allowed_storage_regions' => $regions,
			'deletion_required' => ! empty( $input['deletion_required'] ),
			'authorizing' => false,
		);
	}

	public static function resolve_retention( array $provider_policy, array $requested ) {
		$policy = self::normalize_provider_policy( $provider_policy );
		if ( '' === $policy['provider_id'] ) return new WP_Error( 'mad4b_search_evidence_provider_required', 'Provider evidence policy requires provider_id.' );
		$region = strtoupper( trim( (string) ( $requested['storage_region'] ?? '' ) ) );
		if ( ! empty( $policy['allowed_storage_regions'] ) && ( '' === $region || ! in_array( $region, $policy['allowed_storage_regions'], true ) ) ) {
			return new WP_Error( 'mad4b_search_evidence_region_denied', 'Requested evidence storage region is not allowed by provider policy.', array( 'storage_region' => $region ) );
		}

		$requested_raw = max( 0, (int) ( $requested['raw_retention_seconds'] ?? 0 ) );
		$requested_normalized = max( 0, (int) ( $requested['normalized_retention_seconds'] ?? 0 ) );
		$raw = 0;
		if ( $policy['raw_retention_permitted'] ) {
			$raw = $requested_raw;
			if ( $policy['max_raw_retention_seconds'] > 0 ) $raw = min( $raw, $policy['max_raw_retention_seconds'] );
		}
		$normalized = 0;
		if ( $policy['normalized_retention_permitted'] ) {
			$normalized = $requested_normalized;
			if ( $policy['max_normalized_retention_seconds'] > 0 ) $normalized = min( $normalized, $policy['max_normalized_retention_seconds'] );
		}
		return array(
			'contract' => self::CONTRACT,
			'provider_id' => $policy['provider_id'],
			'provider_policy_version' => $policy['policy_version'],
			'storage_region' => $region,
			'raw_retention_seconds' => $raw,
			'normalized_retention_seconds' => $normalized,
			'redistribution_class' => $policy['redistribution_class'],
			'deletion_required' => $policy['deletion_required'],
			'raw_storage_allowed' => $raw > 0,
			'normalized_storage_allowed' => $normalized > 0,
			'authorizing' => false,
		);
	}
}
