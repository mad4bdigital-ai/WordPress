<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed portability import protocol.
 *
 * Import is quarantine-first: a validated portability bundle is admitted as a
 * durable, payload-minimized reconciliation record. It never recreates grants,
 * credentials, Production authority, or public content. Domain rehydration must
 * be performed later by separately certified adapters under fresh authority.
 */
final class MAD4B_SCP_Portability_Import {
	const CONTRACT = 'mad4b.portability-import-quarantine.v1';
	const PLAN_CONTRACT = 'mad4b.portability-import-quarantine-plan.v1';
	const OPTION = 'mad4b_scp_portability_import_ledger_v1';
	const LOCK_OPTION = 'mad4b_scp_portability_import_lock_v1';
	const MAX_RECORDS = 20;
	const LOCK_TTL = 20;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/portability-import-quarantine-plan', 'Plan Portability Import Quarantine', 'plan', true );
		self::register( 'mad4b/portability-import-quarantine-apply', 'Apply Portability Import Quarantine', 'apply', false );
		self::register( 'mad4b/portability-import-quarantine-status', 'Portability Import Quarantine Status', 'status', true );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; payload-minimized quarantine only, never grants authority or publishes content.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => ! $readonly, 'idempotent' => (bool) $readonly ),
				),
			)
		);
	}

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'production' === $environment ) return new WP_Error( 'mad4b_portability_import_production_denied', 'Portability import quarantine is denied in Production.' );
		if ( ! class_exists( 'MAD4B_SCP_Decommission_Portability' ) ) return new WP_Error( 'mad4b_portability_validator_unavailable', 'Portability bundle validator is unavailable.' );

		$bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] ) ? $input['bundle'] : array();
		$target = isset( $input['target'] ) && is_array( $input['target'] ) ? $input['target'] : array();
		$validation = MAD4B_SCP_Decommission_Portability::validate_import_bundle( array( 'bundle' => $bundle, 'target' => $target ) );
		if ( is_wp_error( $validation ) ) return $validation;
		if ( empty( $validation['valid'] ) ) return new WP_Error( 'mad4b_portability_import_bundle_invalid', 'Portability bundle validation failed.', array( 'blockers' => isset( $validation['blockers'] ) ? $validation['blockers'] : array() ) );

		$bundle_sha = self::sha( isset( $bundle['bundle_sha256'] ) ? $bundle['bundle_sha256'] : '' );
		$rollback_sha = self::sha( isset( $input['rollback_snapshot_sha256'] ) ? $input['rollback_snapshot_sha256'] : '' );
		$target_site = self::uuid( isset( $target['site_uuid'] ) ? $target['site_uuid'] : '' );
		$source_site = self::uuid( isset( $bundle['source_identity']['site_uuid'] ) ? $bundle['source_identity']['site_uuid'] : '' );
		if ( '' === $bundle_sha || '' === $rollback_sha || '' === $target_site ) return new WP_Error( 'mad4b_portability_import_identity_incomplete', 'Exact bundle, target site and rollback snapshot identities are required.' );
		$current_site = self::current_site_uuid();
		if ( '' !== $current_site && ! hash_equals( $current_site, $target_site ) ) return new WP_Error( 'mad4b_portability_import_target_site_mismatch', 'Import target does not match the current enrolled site.' );

		$sections = self::section_summary( isset( $bundle['payload'] ) && is_array( $bundle['payload'] ) ? $bundle['payload'] : array(), isset( $bundle['section_digests'] ) && is_array( $bundle['section_digests'] ) ? $bundle['section_digests'] : array() );
		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'environment' => $environment,
			'bundle_sha256' => $bundle_sha,
			'source_site_uuid' => $source_site,
			'target_site_uuid' => $target_site,
			'rollback_snapshot_sha256' => $rollback_sha,
			'section_summary' => $sections,
			'import_mode' => 'quarantine_reconciliation_only',
			'domain_release_requires_fresh_governed_plan' => true,
			'direct_publication_allowed' => false,
			'authority_material_allowed' => false,
			'credentials_allowed' => false,
			'grants_allowed' => false,
			'production_authority_allowed' => false,
			'caller_payload_persisted' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function apply( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$valid = self::validate_plan( $plan );
		if ( is_wp_error( $valid ) ) return $valid;
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		if ( 'production' === $environment || ! hash_equals( (string) $plan['environment'], $environment ) ) return new WP_Error( 'mad4b_portability_import_environment_drift', 'Portability import environment changed after planning.' );

		$bundle = isset( $input['bundle'] ) && is_array( $input['bundle'] ) ? $input['bundle'] : array();
		$target = isset( $input['target'] ) && is_array( $input['target'] ) ? $input['target'] : array();
		$replan = self::plan(
			array(
				'bundle' => $bundle,
				'target' => $target,
				'rollback_snapshot_sha256' => isset( $plan['rollback_snapshot_sha256'] ) ? $plan['rollback_snapshot_sha256'] : '',
			)
		);
		if ( is_wp_error( $replan ) ) return $replan;
		foreach ( array( 'bundle_sha256', 'source_site_uuid', 'target_site_uuid', 'rollback_snapshot_sha256', 'plan_sha256' ) as $field ) {
			if ( ! isset( $replan[ $field ], $plan[ $field ] ) || ! hash_equals( (string) $plan[ $field ], (string) $replan[ $field ] ) ) return new WP_Error( 'mad4b_portability_import_plan_drift', 'Portability import plan changed before apply.', array( 'field' => $field ) );
		}

		return self::with_lock( 'apply', static function () use ( $plan ) {
			$ledger = self::ledger();
			foreach ( $ledger as $record ) {
				if ( is_array( $record ) && isset( $record['bundle_sha256'], $record['target_site_uuid'] )
					&& hash_equals( (string) $record['bundle_sha256'], (string) $plan['bundle_sha256'] )
					&& hash_equals( (string) $record['target_site_uuid'], (string) $plan['target_site_uuid'] ) ) {
					return array( 'contract' => self::CONTRACT, 'state' => 'already_quarantined', 'record' => $record, 'mutation_performed' => false, 'production_authorized' => false );
				}
			}
			if ( count( $ledger ) >= self::MAX_RECORDS ) return new WP_Error( 'mad4b_portability_import_ledger_full', 'Portability import quarantine ledger is full; reconcile existing imports first.' );
			$id = strtolower( wp_generate_uuid4() );
			$record = array(
				'contract' => self::CONTRACT,
				'import_id' => $id,
				'status' => 'QUARANTINED_RECONCILIATION_REQUIRED',
				'bundle_sha256' => (string) $plan['bundle_sha256'],
				'source_site_uuid' => (string) $plan['source_site_uuid'],
				'target_site_uuid' => (string) $plan['target_site_uuid'],
				'rollback_snapshot_sha256' => (string) $plan['rollback_snapshot_sha256'],
				'section_summary' => $plan['section_summary'],
				'quarantined_at' => gmdate( 'c' ),
				'domain_release_requires_fresh_governed_plan' => true,
				'raw_bundle_payload_persisted' => false,
				'credentials_imported' => false,
				'grants_imported' => false,
				'production_authority_imported' => false,
				'public_content_mutated' => false,
				'provider_side_effect_performed' => false,
			);
			$record['record_sha256'] = self::digest( $record );
			$ledger[ $id ] = $record;
			update_option( self::OPTION, $ledger, false );
			$stored = self::ledger();
			if ( ! isset( $stored[ $id ] ) || ! hash_equals( (string) $record['record_sha256'], (string) $stored[ $id ]['record_sha256'] ) ) return new WP_Error( 'mad4b_portability_import_persist_failed', 'Portability import quarantine record could not be durably read back.' );
			return array(
				'contract' => self::CONTRACT,
				'state' => 'quarantined',
				'record' => $record,
				'next_action' => 'reconcile_each_domain_through_certified_adapter_with_fresh_authority',
				'production_authorized' => false,
				'authorizing' => false,
				'mutation_performed' => true,
			);
		} );
	}

	public static function status( $input = array() ) {
		$items = array_values( self::ledger() );
		usort( $items, static function ( $a, $b ) { return strcmp( (string) ( isset( $b['quarantined_at'] ) ? $b['quarantined_at'] : '' ), (string) ( isset( $a['quarantined_at'] ) ? $a['quarantined_at'] : '' ) ); } );
		return array(
			'contract' => self::CONTRACT,
			'count' => count( $items ),
			'items' => $items,
			'raw_bundle_payload_persisted' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function validate_plan( array $plan ) {
		if ( self::PLAN_CONTRACT !== (string) ( isset( $plan['contract'] ) ? $plan['contract'] : '' ) ) return new WP_Error( 'mad4b_portability_import_plan_contract_invalid', 'Portability import plan contract is invalid.' );
		$sha = self::sha( isset( $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '' );
		if ( '' === $sha || ! hash_equals( $sha, self::digest_without( $plan, array( 'plan_sha256', 'mutation_performed' ) ) ) ) return new WP_Error( 'mad4b_portability_import_plan_digest_invalid', 'Portability import plan digest mismatch.' );
		if ( ! empty( $plan['direct_publication_allowed'] ) || ! empty( $plan['authority_material_allowed'] ) || ! empty( $plan['credentials_allowed'] ) || ! empty( $plan['grants_allowed'] ) || ! empty( $plan['production_authority_allowed'] ) ) return new WP_Error( 'mad4b_portability_import_plan_authority_widening', 'Portability import plan attempts to widen authority or publication.' );
		return true;
	}

	private static function section_summary( array $payload, array $digests ) {
		$out = array();
		foreach ( $payload as $section => $items ) {
			$key = sanitize_key( (string) $section );
			$sha = self::sha( isset( $digests[ $section ] ) ? $digests[ $section ] : '' );
			$out[ $key ] = array(
				'item_count' => is_array( $items ) ? count( $items ) : 0,
				'section_sha256' => $sha,
				'disposition' => 'quarantine_pending_certified_domain_reconciliation',
			);
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function current_site_uuid() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ) return self::uuid( MAD4B_SCP_Site_Profile::site_uuid() );
		return '';
	}

	private static function ledger() {
		$ledger = get_option( self::OPTION, array() );
		return is_array( $ledger ) ? $ledger : array();
	}

	private static function delete_option_if_unchanged( $name, $expected ) {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'delete' ) ) {
			$serialized = function_exists( 'maybe_serialize' ) ? maybe_serialize( $expected ) : serialize( $expected );
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => (string) $name, 'option_value' => $serialized ), array( '%s', '%s' ) );
			if ( 1 === (int) $deleted ) {
				if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( (string) $name, 'options' );
				return true;
			}
			return false;
		}
		$current = get_option( $name, null );
		if ( self::digest( $current ) !== self::digest( $expected ) ) return false;
		return delete_option( $name );
	}

	private static function with_lock( $operation, $callback ) {
		$owner = strtolower( wp_generate_uuid4() );
		$record = array( 'owner' => $owner, 'operation' => sanitize_key( (string) $operation ), 'expires_at_epoch' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $record, '', false ) ) {
			$current = get_option( self::LOCK_OPTION, array() );
			if ( ! is_array( $current ) || time() <= (int) ( isset( $current['expires_at_epoch'] ) ? $current['expires_at_epoch'] : 0 ) ) return new WP_Error( 'mad4b_portability_import_busy', 'Portability import quarantine is locked by another operation.' );
			if ( ! self::delete_option_if_unchanged( self::LOCK_OPTION, $current ) ) return new WP_Error( 'mad4b_portability_import_lock_reclaim_raced', 'Portability import lock changed while reclaiming an expired lock.' );
			if ( ! add_option( self::LOCK_OPTION, $record, '', false ) ) return new WP_Error( 'mad4b_portability_import_busy', 'Portability import quarantine lock could not be reclaimed.' );
		}
		try {
			return call_user_func( $callback );
		} finally {
			$current = get_option( self::LOCK_OPTION, array() );
			if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( $owner, (string) $current['owner'] ) ) self::delete_option_if_unchanged( self::LOCK_OPTION, $current );
		}
	}

	private static function uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value ) ? $value : '';
	}

	private static function sha( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function digest_without( $value, array $keys ) {
		if ( is_array( $value ) ) foreach ( $keys as $key ) unset( $value[ $key ] );
		return self::digest( $value );
	}

	private static function digest( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}

MAD4B_SCP_Portability_Import::boot();
