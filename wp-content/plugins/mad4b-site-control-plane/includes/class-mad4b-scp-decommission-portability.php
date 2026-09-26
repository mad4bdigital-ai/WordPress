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
		self::register_read( 'mad4b/decommission-preflight', 'Decommission and Portability Preflight', 'preflight',
			'Inventory blockers and portable export requirements before provider/site/module retirement.' );
		self::register_read( 'mad4b/export-bundle-build', 'Build Portable Export Bundle', 'build_export_bundle',
			'Build a deterministic payload-minimized portability bundle from a supplied governed snapshot. No filesystem/provider mutation.' );
		self::register_read( 'mad4b/import-bundle-validate', 'Validate Portable Import Bundle', 'validate_import_bundle',
			'Validate bundle integrity, compatibility, remapping and forbidden authority material without importing anything.' );
	}

	private static function register_read( $name, $label, $method, $description ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $description . ' Read-only and non-authorizing.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, $method ),
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

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function clean_items( $value, $max = 1000 ) {
		if ( ! is_array( $value ) ) return array();
		$out = array();
		foreach ( array_slice( $value, 0, $max ) as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$out[] = $item;
			} elseif ( is_array( $item ) ) {
				$out[] = self::stable( $item );
			}
		}
		return $out;
	}

	public static function build_export_bundle( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$scope = sanitize_key( (string) ( $input['scope'] ?? '' ) );
		$source_identity = isset( $input['source_identity'] ) && is_array( $input['source_identity'] ) ? $input['source_identity'] : array();
		$snapshot = isset( $input['snapshot'] ) && is_array( $input['snapshot'] ) ? $input['snapshot'] : array();
		$allowed_scopes = array( 'provider_adapter', 'workflow_provider', 'ai_provider', 'site', 'tenant', 'module', 'host_connector', 'authority', 'installation' );
		if ( ! in_array( $scope, $allowed_scopes, true ) ) return self::blocked_bundle( 'scope_invalid' );

		$source_sha = strtolower( trim( (string) ( $source_identity['source_commit_sha'] ?? '' ) ) );
		$site_uuid = strtolower( trim( (string) ( $source_identity['site_uuid'] ?? '' ) ) );
		if ( '' !== $source_sha && 1 !== preg_match( '/^[a-f0-9]{40}$/', $source_sha ) ) return self::blocked_bundle( 'source_commit_invalid' );
		if ( '' !== $site_uuid && 1 !== preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return self::blocked_bundle( 'site_uuid_invalid' );

		$forbidden = array( 'secrets', 'private_keys', 'credentials', 'access_tokens', 'refresh_tokens', 'approval_tickets', 'grants', 'production_authority' );
		foreach ( $forbidden as $key ) {
			if ( ! empty( $snapshot[ $key ] ) ) return self::blocked_bundle( 'forbidden_authority_material:' . $key );
		}

		$sections = array(
			'jobs_events', 'artifact_metadata', 'blob_refs', 'lineage', 'writer_profiles',
			'context_source_refs', 'certification_evidence', 'content_inventory', 'intent_registry',
			'provider_execution_refs', 'audit_tombstones',
		);
		$payload = array();
		foreach ( $sections as $section ) $payload[ $section ] = self::clean_items( $snapshot[ $section ] ?? array() );

		$section_digests = array();
		foreach ( $payload as $section => $items ) $section_digests[ $section ] = self::digest( $items );
		$manifest = array(
			'contract' => 'mad4b.export-bundle.v1',
			'scope' => $scope,
			'schema_contracts' => self::clean_items( $snapshot['schema_contracts'] ?? array(), 100 ),
			'source_identity' => array(
				'source_commit_sha' => $source_sha,
				'build_fingerprint' => strtolower( trim( (string) ( $source_identity['build_fingerprint'] ?? '' ) ) ),
				'site_uuid' => $site_uuid,
			),
			'section_digests' => $section_digests,
			'payload' => $payload,
			'secrets_included' => false,
			'private_keys_included' => false,
			'credentials_included' => false,
			'grants_included' => false,
			'production_authority_included' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$manifest['bundle_sha256'] = self::digest( $manifest );
		return $manifest;
	}

	public static function validate_import_bundle( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] ) ? $input['bundle'] : array();
		$target = isset( $input['target'] ) && is_array( $input['target'] ) ? $input['target'] : array();
		$blockers = array();
		if ( 'mad4b.export-bundle.v1' !== (string) ( $bundle['contract'] ?? '' ) ) $blockers[] = 'bundle_contract_invalid';

		$expected = strtolower( trim( (string) ( $bundle['bundle_sha256'] ?? '' ) ) );
		$copy = $bundle;
		unset( $copy['bundle_sha256'] );
		$actual = self::digest( $copy );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, $actual ) ) $blockers[] = 'bundle_checksum_mismatch';

		foreach ( array( 'secrets_included', 'private_keys_included', 'credentials_included', 'grants_included', 'production_authority_included' ) as $flag ) {
			if ( ! empty( $bundle[ $flag ] ) ) $blockers[] = 'forbidden_import_material:' . $flag;
		}

		$payload = isset( $bundle['payload'] ) && is_array( $bundle['payload'] ) ? $bundle['payload'] : array();
		$digests = isset( $bundle['section_digests'] ) && is_array( $bundle['section_digests'] ) ? $bundle['section_digests'] : array();
		foreach ( $payload as $section => $items ) {
			if ( ! isset( $digests[ $section ] ) || ! hash_equals( (string) $digests[ $section ], self::digest( $items ) ) ) {
				$blockers[] = 'section_checksum_mismatch:' . sanitize_key( (string) $section );
			}
		}

		$source_site = strtolower( trim( (string) ( $bundle['source_identity']['site_uuid'] ?? '' ) ) );
		$target_site = strtolower( trim( (string) ( $target['site_uuid'] ?? '' ) ) );
		$remap = isset( $target['site_uuid_remap'] ) ? strtolower( trim( (string) $target['site_uuid_remap'] ) ) : '';
		if ( '' !== $source_site && '' !== $target_site && ! hash_equals( $source_site, $target_site ) && ! hash_equals( $source_site, $remap ) ) {
			$blockers[] = 'site_remap_required';
		}

		$collisions = isset( $target['collisions'] ) && is_array( $target['collisions'] ) ? array_values( $target['collisions'] ) : array();
		if ( ! empty( $collisions ) ) $blockers[] = 'target_collisions_present';
		$missing_refs = isset( $target['missing_external_refs'] ) && is_array( $target['missing_external_refs'] ) ? array_values( $target['missing_external_refs'] ) : array();
		if ( ! empty( $missing_refs ) ) $blockers[] = 'missing_external_refs';

		return array(
			'contract' => 'mad4b.import-bundle-validation.v1',
			'valid' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'bundle_sha256' => $expected,
			'grants_recreated' => false,
			'production_authorized' => false,
			'import_performed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	private static function blocked_bundle( $reason ) {
		return array(
			'contract' => 'mad4b.export-bundle.v1',
			'blocked' => true,
			'blockers' => array( (string) $reason ),
			'secrets_included' => false,
			'private_keys_included' => false,
			'grants_included' => false,
			'production_authority_included' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

}

MAD4B_SCP_Decommission_Portability::boot();
