<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Version policy for persisted security/governance records.
 *
 * This is compatibility metadata only. It can constrain governed writes but
 * never grants authority. Unknown/newer contracts are never interpreted as an
 * older contract merely because their fields happen to look compatible.
 */
final class MAD4B_SCP_Persisted_Contract_Compatibility {
	const CONTRACT = 'mad4b.persisted-contract-compatibility.v1';

	public static function registry() {
		$schema_version = class_exists( 'MAD4B_SCP_Schema' ) ? (int) MAD4B_SCP_Schema::VERSION : 0;
		return array(
			'contract' => self::CONTRACT,
			'governance_schema' => array(
				'current_version' => $schema_version,
				'write_policy' => 'exact_version_only',
				'future_version_policy' => 'fail_closed_no_downgrade',
			),
			'site_profile' => array(
				'current' => array( 'contract' => 'mad4b.site-profile.v2', 'version' => 2 ),
				'legacy_migration_only' => array( array( 'contract' => 'mad4b.site-profile.v1', 'version' => 1 ) ),
			),
			'preparation_receipt' => array( 'current_contract' => 'mad4b.preparation-receipt.v1' ),
			'approval' => array(
				'ticket_contract' => 'mad4b.approval.v1',
				'candidate_binding_current' => 'mad4b.approval-candidate-binding.v2',
				'candidate_binding_legacy_migration_only' => array( 'mad4b.approval-candidate-binding.v1' ),
			),
			'operation_journal' => array(
				'journal_contract' => 'mad4b.dynamic-operation-journal.v1',
				'event_contract' => 'dynamic-operation-event:v1',
			),
			'durable_execution' => array(
				'root_contract' => 'mad4b.durable-execution.v1',
				'lease_contract' => 'mad4b.execution-plane.v1',
				'idempotency_contract' => 'mad4b.idempotency-record.v1',
				'outbox_contract' => 'mad4b.execution-outbox.v1',
				'inbox_contract' => 'mad4b.execution-inbox.v1',
			),
			'catalog' => array(
				'transport_contract' => 'mad4b.ability-catalog-transport.v2',
				'object_envelope_contract' => 'mad4b.catalog-object-envelope.v1',
				'object_envelope_version' => 1,
			),
		);
	}

	public static function registry_sha256() {
		$json = self::stable_json( self::registry() );
		return '' === $json ? '' : hash( 'sha256', $json );
	}

	public static function evaluate_schema_generation( $installed_version, $supported_version ) {
		$installed_version = (int) $installed_version;
		$supported_version = (int) $supported_version;
		if ( $supported_version < 1 || $installed_version < 0 ) return 'invalid';
		if ( $installed_version === $supported_version ) return 'current';
		if ( $installed_version > $supported_version ) return 'future_schema_downgrade_forbidden';
		return 'schema_upgrade_required';
	}

	public static function evaluate_versioned_record( $contract, $version, $current_contract, $current_version, array $legacy = array() ) {
		$contract = trim( (string) $contract );
		$version = (int) $version;
		if ( hash_equals( (string) $current_contract, $contract ) && (int) $current_version === $version ) return 'current';
		foreach ( $legacy as $row ) {
			if ( ! is_array( $row ) ) continue;
			if ( hash_equals( (string) ( isset( $row['contract'] ) ? $row['contract'] : '' ), $contract )
				&& (int) ( isset( $row['version'] ) ? $row['version'] : 0 ) === $version ) return 'legacy_migration_required';
		}
		return 'unsupported';
	}

	public static function status() {
		$registry = self::registry();
		$blockers = array();
		$installed_schema = class_exists( 'MAD4B_SCP_Schema' ) ? (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) : -1;
		$supported_schema = class_exists( 'MAD4B_SCP_Schema' ) ? (int) MAD4B_SCP_Schema::VERSION : 0;
		$schema_state = self::evaluate_schema_generation( $installed_schema, $supported_schema );
		if ( 'current' !== $schema_state ) $blockers[] = $schema_state;

		$profile_state = 'absent';
		$profile_contract = '';
		$profile_version = 0;
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) ) {
			$raw = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
			if ( is_array( $raw ) && ! empty( $raw ) ) {
				$profile_contract = isset( $raw['contract'] ) ? (string) $raw['contract'] : '';
				$profile_version = isset( $raw['version'] ) ? (int) $raw['version'] : 0;
				$profile_state = self::evaluate_versioned_record(
					$profile_contract,
					$profile_version,
					$registry['site_profile']['current']['contract'],
					$registry['site_profile']['current']['version'],
					$registry['site_profile']['legacy_migration_only']
				);
				if ( 'current' !== $profile_state ) $blockers[] = 'site_profile_' . $profile_state;
			}
		}

		$registry_sha = self::registry_sha256();
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $registry_sha ) ) $blockers[] = 'persisted_contract_registry_digest_unavailable';

		return array(
			'contract' => self::CONTRACT,
			'registry_sha256' => $registry_sha,
			'schema' => array(
				'installed_version' => $installed_schema,
				'supported_version' => $supported_schema,
				'state' => $schema_state,
				'exact_write_compatible' => 'current' === $schema_state,
			),
			'site_profile' => array(
				'contract' => $profile_contract,
				'version' => $profile_version,
				'state' => $profile_state,
				'exact_write_compatible' => in_array( $profile_state, array( 'absent', 'current' ), true ),
			),
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready_for_write' => empty( $blockers ),
			'unknown_or_future_records_fail_closed' => true,
			'downgrade_reinterpretation_allowed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function assert_write_compatible() {
		$status = self::status();
		if ( empty( $status['ready_for_write'] ) ) {
			return new WP_Error(
				'mad4b_persisted_contract_incompatible',
				'Persisted security/governance contracts are not exactly compatible with this runtime.',
				array(
					'blockers' => isset( $status['blockers'] ) ? $status['blockers'] : array( 'persisted_contract_status_unavailable' ),
					'reapproval_required' => true,
					'worker_recycle_required' => true,
					'authorizing' => false,
				)
			);
		}
		return $status;
	}

	private static function stable_json( $value ) {
		$normalized = self::canonicalize( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$keys = array_keys( $value );
			$is_list = array() === $value || $keys === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$out = array();
				foreach ( $value as $item ) $out[] = self::canonicalize( $item );
				return $out;
			}
			sort( $keys, SORT_STRING );
			$out = array();
			foreach ( $keys as $key ) $out[ (string) $key ] = self::canonicalize( $value[ $key ] );
			return $out;
		}
		if ( is_object( $value ) ) return self::canonicalize( get_object_vars( $value ) );
		return $value;
	}
}
