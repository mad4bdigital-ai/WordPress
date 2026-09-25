<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only decommission/export portability preflight.
 *
 * It inventories blockers and export requirements. It never revokes credentials,
 * deletes data, recreates grants, or mutates WordPress/provider state.
 */
final class MAD4B_SCP_Decommission_Portability {
	const CONTRACT = 'mad4b.decommission-portability-preflight.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/decommission-preflight' ) ) return;
		wp_register_ability( 'mad4b/decommission-preflight', array(
			'label' => 'Decommission and Portability Preflight',
			'description' => 'Inventory blockers and portable export requirements before provider/site/module retirement. Read-only.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'preflight' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false, 'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $k => $v ) $value[ $k ] = self::stable( $v );
		}
		return $value;
	}

	public static function preflight( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$scope = sanitize_key( (string) ( $input['scope'] ?? '' ) );
		$inventory = isset( $input['inventory'] ) && is_array( $input['inventory'] ) ? $input['inventory'] : array();
		$policy = isset( $input['policy'] ) && is_array( $input['policy'] ) ? $input['policy'] : array();

		$allowed_scopes = array( 'provider_adapter', 'workflow_provider', 'ai_provider', 'site', 'tenant', 'module', 'host_connector', 'authority', 'installation' );
		$blockers = array();
		$actions = array();
		if ( ! in_array( $scope, $allowed_scopes, true ) ) $blockers[] = 'scope_invalid';

		$checks = array(
			'active_jobs' => 'drain_or_cancel_active_jobs',
			'unresolved_writes' => 'reconcile_unresolved_writes',
			'active_credentials' => 'revoke_or_rotate_credentials',
			'active_webhooks' => 'disable_external_callbacks',
			'scheduled_work' => 'remove_scheduled_work',
			'runner_leases' => 'drain_runner_leases',
			'pending_dlq' => 'export_or_quarantine_dead_letter_work',
		);
		foreach ( $checks as $key => $action ) {
			$count = isset( $inventory[ $key ] ) ? max( 0, (int) $inventory[ $key ] ) : 0;
			if ( $count > 0 ) {
				$blockers[] = $key . '_present';
				$actions[] = $action;
			}
		}

		$evidence_required = ! array_key_exists( 'evidence_retained_or_exportable', $inventory ) || empty( $inventory['evidence_retained_or_exportable'] );
		if ( $evidence_required ) {
			$blockers[] = 'required_evidence_not_retained';
			$actions[] = 'retain_or_export_required_evidence';
		}

		if ( ! empty( $inventory['external_refs_missing'] ) ) {
			$blockers[] = 'external_refs_unresolved';
			$actions[] = 'reconcile_external_refs';
		}

		$manifest = array(
			'contract' => 'mad4b.export-bundle-manifest.v1',
			'scope' => $scope,
			'schema_versions_required' => true,
			'jobs_events' => true,
			'artifact_metadata' => true,
			'blob_or_external_refs' => true,
			'lineage' => true,
			'writer_profiles' => true,
			'context_source_refs' => true,
			'certification_evidence_if_exportable' => true,
			'content_inventory_intent_registry' => true,
			'checksums_required' => true,
			'secrets_included' => false,
			'private_keys_included' => false,
			'grants_recreated_on_import' => false,
			'production_authority_recreated_on_import' => false,
		);

		$decision = empty( $blockers ) ? 'READY_FOR_GOVERNED_QUIESCE' : 'BLOCKED';
		$summary = array(
			'scope' => $scope,
			'blockers' => array_values( array_unique( $blockers ) ),
			'required_actions' => array_values( array_unique( $actions ) ),
			'export_manifest' => $manifest,
			'published_content_treatment' => sanitize_key( (string) ( $policy['published_content_treatment'] ?? 'explicit_decision_required' ) ),
			'audit_tombstone_required' => ! array_key_exists( 'audit_tombstone_required', $policy ) || ! empty( $policy['audit_tombstone_required'] ),
		);

		return array(
			'contract' => self::CONTRACT,
			'decision' => $decision,
			'blockers' => $summary['blockers'],
			'required_actions' => $summary['required_actions'],
			'export_manifest' => $manifest,
			'preflight_fingerprint' => hash( 'sha256', wp_json_encode( self::stable( $summary ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'mutation_performed' => false,
			'credentials_revoked' => false,
			'content_deleted' => false,
			'grants_recreated' => false,
			'production_authorized' => false,
			'authorizing' => false,
		);
	}
}

MAD4B_SCP_Decommission_Portability::boot();
