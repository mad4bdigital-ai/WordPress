<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * A to Z Brand Core recovery routing over the EXISTING Context Authority.
 *
 * This does not write, migrate ownership, duplicate Drive folders, approve
 * evidence or confer write grants. A caller can resume the exact workflow
 * after EACH independently governed mutation and readback.
 */
final class MAD4B_SCP_Brand_Core_Control_Loop {
	const CONTRACT = 'mad4b.brand-core-control-loop.v1';
	const REQUIRED = array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines' );

	private static function safe_bool( $value ) { return true === $value; }

	/**
	 * Pure reducer for native tests and recovery without fabricated evidence.
	 * Quarantined records are prioritised before any source creation.
	 */
	public static function decide( array $coverage, array $context, array $convergence, array $census, array $provider ) {
		// These snapshots are collected in separate reads. A stale/malformed
		// census must not silently erase quarantined records reported by Context.
		$counts_valid = isset( $census['source_quarantine_count'], $census['asset_quarantine_count'],
			$context['quarantined_source_record_count'], $context['quarantined_asset_record_count'] )
			&& is_int( $census['source_quarantine_count'] ) && is_int( $census['asset_quarantine_count'] )
			&& is_int( $context['quarantined_source_record_count'] ) && is_int( $context['quarantined_asset_record_count'] )
			&& $census['source_quarantine_count'] >= 0 && $census['asset_quarantine_count'] >= 0
			&& $context['quarantined_source_record_count'] >= 0 && $context['quarantined_asset_record_count'] >= 0;
		$quarantined_sources = max( 0, (int) ( $census['source_quarantine_count'] ?? 0 ),
			(int) ( $context['quarantined_source_record_count'] ?? 0 ) );
		$quarantined_assets = max( 0, (int) ( $census['asset_quarantine_count'] ?? 0 ),
			(int) ( $context['quarantined_asset_record_count'] ?? 0 ) );
		$counts_drift = ! $counts_valid
			|| (int) ( $census['source_quarantine_count'] ?? -1 ) !== (int) ( $context['quarantined_source_record_count'] ?? -2 )
			|| (int) ( $census['asset_quarantine_count'] ?? -1 ) !== (int) ( $context['quarantined_asset_record_count'] ?? -2 );
		$quarantine = $quarantined_sources + $quarantined_assets > 0;
		$invalid_snapshot = $counts_drift
			|| ! isset( $context['registry_revision'], $context['authority_manifest_fingerprint'] )
			|| (int) ( $context['registry_revision'] ?? -1 ) !== (int) ( $coverage['registry_revision'] ?? -2 )
			|| empty( $context['authority_manifest_fingerprint'] )
			|| ! hash_equals( (string) ( $context['authority_manifest_fingerprint'] ?? '' ),
				(string) ( $coverage['authority_manifest_fingerprint'] ?? '' ) )
			|| ! isset( $coverage['coverage'], $coverage['registry_revision'], $convergence['registry_revision'] )
			|| ! is_array( $coverage['coverage'] )
			|| (int) $coverage['registry_revision'] !== (int) $convergence['registry_revision']
			|| empty( $convergence['authority_manifest_fingerprint'] )
			|| empty( $coverage['authority_manifest_fingerprint'] )
			|| ! hash_equals( (string) $coverage['authority_manifest_fingerprint'], (string) $convergence['authority_manifest_fingerprint'] );
		$provider_connected = ! empty( $provider['connection']['connected'] );
		$provider_write = ! empty( $provider['connection']['write_available'] );
		$review = $context['review_policy'] ?? array();
		$ai_delegated = is_array( $review ) && ! empty( $review['ready'] );
		$by_category = array();
		foreach ( $convergence['actions'] ?? array() as $candidate ) {
			if ( is_array( $candidate ) && isset( $candidate['category'] ) )
				$by_category[ (string) $candidate['category'] ] = $candidate;
		}
		$categories = array();
		$first_step = array();
		foreach ( self::REQUIRED as $category ) {
			$info = $coverage['coverage'][ $category ] ?? array();
			$approved = ! empty( $info['ready'] ) && empty( $info['conflict'] );
			$conflict = ! empty( $info['conflict'] );
			$action = $by_category[ $category ] ?? array();
			// A recovered original Context asset may be stale or unreviewed.
			// Presence must route to its original source/review, not to new creation.
			$observed = isset( $info['observed_assets'] ) && is_array( $info['observed_assets'] )
				? $info['observed_assets'] : array();
			$needs_source_scan = false;
			$needs_source_review = false;
			foreach ( $observed as $item ) {
				if ( ! is_array( $item ) ) { $needs_source_review = true; continue; }
				$reasons = isset( $item['reasons'] ) && is_array( $item['reasons'] ) ? $item['reasons'] : array();
				if ( array_intersect( $reasons, array( 'status_not_ready', 'content_incomplete',
					'generation_evidence_stale' ) ) ) $needs_source_scan = true;
				if ( in_array( 'source_not_governed', $reasons, true )
					|| in_array( 'wrong_authority_class', $reasons, true ) ) $needs_source_review = true;
			}
			$deps = array();
			if ( 'tone_of_voice' === $category && empty( $coverage['coverage']['brand_strategy']['ready'] ) ) $deps[] = 'brand_strategy';
			if ( 'editorial_guidelines' === $category ) {
				if ( empty( $coverage['coverage']['brand_strategy']['ready'] ) ) $deps[] = 'brand_strategy';
				if ( empty( $coverage['coverage']['tone_of_voice']['ready'] ) ) $deps[] = 'tone_of_voice';
			}
			if ( $invalid_snapshot ) {
				$state = 'SNAPSHOT_DRIFT';
				$ability = 'context/brand-core-coverage';
			} elseif ( $quarantine ) {
				$state = 'OWNERSHIP_REVIEW_REQUIRED';
				$ability = 'context/legacy-reconciliation-census';
			} elseif ( $conflict ) {
				$state = 'AUTHORITY_CONFLICT';
				$ability = 'context/conflicts';
			} elseif ( $approved ) {
				$state = 'READY';
				$ability = 'context/brand-core-coverage';
			} elseif ( ! $provider_connected ) {
				$state = 'PROVIDER_RECOVERY';
				$ability = 'context/google-drive-status';
			} elseif ( $deps ) {
				$state = 'WAIT_UPSTREAM_AUTHORITY';
				$ability = 'context/brand-core-control-loop';
			} elseif ( $observed ) {
				if ( $needs_source_review ) {
					$state = 'REVIEW_EXISTING_SOURCE_FIRST';
					$ability = 'context/brand-core-convergence-plan';
				} elseif ( $needs_source_scan ) {
					$state = 'RESCAN_EXISTING_ASSET';
					$ability = 'context/source-scan-plan';
				} else {
					$state = 'REVIEW_EXISTING_ASSET';
					$ability = 'context/review-queue';
				}
			} elseif ( 'brand_strategy' === $category ) {
				$state = 'OWNER_STRATEGY_REQUIRED';
				$ability = 'context/review-queue';
			} elseif ( empty( $provider_write ) ) {
				$state = 'PROVIDER_WRITE_RECOVERY';
				$ability = 'context/provider-capabilities';
			} elseif ( empty( $convergence['writable_sources'] ) ) {
				$state = 'REVIEW_EXISTING_SOURCE_FIRST';
				$ability = 'context/brand-core-convergence-plan';
			} elseif ( 'ready_to_create' !== ( $action['state'] ?? '' ) ) {
				$state = 'EVIDENCE_COLLECTION';
				$ability = 'context/brand-gap-plan';
			} else {
				$state = 'DRAFT_PREFLIGHT';
				$ability = 'context/brand-draft-preflight';
			}
			$row = array(
				'category' => $category,
				'state' => $state,
				'next_ability' => $ability,
				'dependencies' => $deps,
				'approved_current_content' => $approved,
				'candidate_recreation_authorized' => false,
				'existing_authority_priority' => true,
				'operator_decision_required' => in_array( $state, array( 'OWNERSHIP_REVIEW_REQUIRED', 'AUTHORITY_CONFLICT', 'OWNER_STRATEGY_REQUIRED',
					'REVIEW_EXISTING_SOURCE_FIRST', 'REVIEW_EXISTING_ASSET' ), true ),
				'blockers' => isset( $action['blockers'] ) && is_array( $action['blockers'] ) ? array_values( $action['blockers'] ) : array(),
			);
			$categories[] = $row;
			if ( ! $first_step && 'READY' !== $state ) $first_step = $row;
		}
		$complete = ! $quarantine && ! $invalid_snapshot && ! $first_step
			&& ! empty( $coverage['ready'] );
		$next = $complete ? 'context/brand-core-coverage'
			: ( $first_step['next_ability'] ?? 'context/brand-core-coverage' );
		$state = $invalid_snapshot ? 'BLOCKED_SNAPSHOT_DRIFT'
			: ( $quarantine ? 'BLOCKED_OWNERSHIP_QUARANTINE'
			: ( $complete ? 'READY' : 'RECONCILIATION_REQUIRED' ) );
		$basis = array(
			'contract' => self::CONTRACT,
			'state' => $state,
			'ready' => $complete,
			'brand_id' => (string) ( $context['brand_id'] ?? '' ),
			'brand_revision' => (int) ( $context['profile_revision'] ?? 0 ),
			'registry_revision' => (int) ( $coverage['registry_revision'] ?? 0 ),
			'authority_manifest_fingerprint' => (string) ( $coverage['authority_manifest_fingerprint'] ?? '' ),
			'quarantine' => array( 'source_count' => $quarantined_sources, 'asset_count' => $quarantined_assets,
				'prevents_recreation' => $quarantine, 'migration_automatically_authorized' => false ),
			'provider' => array( 'connected' => $provider_connected, 'write_available' => $provider_write,
				'credential_refresh_required' => ! empty( $provider['connection']['refresh_required'] ) ),
			'categories' => $categories,
			'next_ability' => $next,
			'next_operator_action' => $quarantine ? 'review_exact_legacy_ownership_with_independent_evidence'
				: ( $complete ? 'continue_original_content_request_with_fresh_context_receipt'
				: ( $first_step['state'] ?? 'inspect_context' ) ),
			'governed_write_abilities' => array(
				'context/brand-draft-create', 'context/materialize-brand-draft',
				'context/reconcile-brand-materialization', 'context/source-scan-apply',
				'context/update-drive-asset', 'context/recreate-drive-asset',
			),
			'delegated_ai_review_available' => $ai_delegated,
			'delegated_ai_review_ability' => $ai_delegated ? 'mad4b/context-ai-review' : '',
			'approval_required_per_mutation' => true,
			'status_readback_required_after_every_step' => true,
			'preserve_existing_source_of_truth' => true,
			'create_parallel_context_registry' => false,
			'brand_strategy_auto_generated_as_authority' => false,
			'deletion_automatic' => false,
			'production_mutation_allowed' => false,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$basis['snapshot_sha256'] = is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		return $basis;
	}

	public static function status( $input = array() ) {
		if ( ! is_array( $input ) || $input )
			return new WP_Error( 'mad4b_brand_loop_input_invalid', 'Read-only Brand recovery accepts only an empty request.' );
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ||
			! class_exists( 'MAD4B_SCP_Brand_Context_Builder' ) )
			return new WP_Error( 'mad4b_brand_loop_runtime_missing', 'Original Context Authority and Brand Builder must be available.' );
		$context = MAD4B_SCP_Context_Authority::status();
		$coverage = MAD4B_SCP_Context_Authority::brand_core_coverage();
		$convergence = MAD4B_SCP_Brand_Context_Builder::convergence_plan( array( 'include_authoritative_content' => false, 'include_rendered_frontend' => false ) );
		if ( is_wp_error( $convergence ) ) return $convergence;
		if ( ! is_array( $context ) || ! is_array( $coverage ) )
			return new WP_Error( 'mad4b_brand_loop_snapshot_unavailable', 'Verified Context snapshot unavailable.' );
		$census = MAD4B_SCP_Context_Authority::legacy_reconciliation_census();
		// Permission denial is not proof that there are zero quarantined assets.
		if ( is_wp_error( $census ) ) {
			return new WP_Error( 'mad4b_brand_loop_census_permission_required', 'Exact privileged quarantined-record census required before deciding to recreate.' );
		}
		$provider = class_exists( 'MAD4B_SCP_Google_Drive_Context' ) && method_exists( 'MAD4B_SCP_Google_Drive_Context', 'connection_status' )
			? array( 'connection' => MAD4B_SCP_Google_Drive_Context::connection_status() ) : array();
		return self::decide( $coverage, $context, $convergence, $census, $provider );
	}
}
