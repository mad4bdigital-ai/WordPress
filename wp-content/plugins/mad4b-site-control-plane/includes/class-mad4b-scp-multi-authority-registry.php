<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feature 007 projection of the existing OAuth bridge into explicit
 * multi-authority policy dimensions.
 *
 * This class creates no issuer, key, subject or grant. It is a read-only
 * projection over the OAuth Resource Bridge. Live verification remains false
 * until a separate explicit live-certification path records evidence.
 */
final class MAD4B_SCP_Multi_Authority_Registry {
	const CONTRACT = 'mad4b.multi-authority-registry.v1';
	const LIVE_CONTRACT = 'mad4b.multi-authority-live-certification.v1';
	const STATUS_ABILITY = 'mad4b/multi-authority-registry-status';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::STATUS_ABILITY ) ) return;

		wp_register_ability(
			self::STATUS_ABILITY,
			array(
				'label' => 'Multi-Authority Registry Status',
				'description' => 'Read the explicit trusted, advertised, resource-policy and live-verification dimensions of configured OAuth authorities.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'snapshot' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool' ),
					'annotations' => array(
						'readonly' => true,
						'destructive' => false,
						'idempotent' => true,
					),
				),
			)
		);
	}

	public static function snapshot() {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) {
			return array(
				'contract' => self::CONTRACT,
				'configured' => false,
				'authority_count' => 0,
				'authorities' => array(),
				'trusted_authorities' => array(),
				'advertised_authorities' => array(),
				'live_certification_contract' => self::LIVE_CONTRACT,
				'live_certification_verdict' => 'PENDING',
				'status_reason_codes' => array( 'OAUTH_RESOURCE_BRIDGE_UNAVAILABLE' ),
			);
		}

		$bridge = MAD4B_SCP_OAuth_Resource_Bridge::status();
		$trusted = MAD4B_SCP_OAuth_Resource_Bridge::trusted_issuers();
		$advertised = MAD4B_SCP_OAuth_Resource_Bridge::advertised_issuers();
		$primary = MAD4B_SCP_OAuth_Resource_Bridge::primary_issuer();
		$environment = isset( $bridge['environment'] ) ? sanitize_key( (string) $bridge['environment'] ) : 'unknown';

		$authorities = array();
		foreach ( $trusted as $issuer ) {
			$type = MAD4B_SCP_OAuth_Resource_Bridge::authority_type_for_issuer( $issuer );
			$user_id = MAD4B_SCP_OAuth_Resource_Bridge::configured_user_id_for_issuer( $issuer );
			$resources = MAD4B_SCP_OAuth_Resource_Bridge::resource_policy_for_issuer( $issuer );
			$subjects = MAD4B_SCP_OAuth_Resource_Bridge::allowed_subjects_for_issuer( $issuer );
			$is_advertised = in_array( $issuer, $advertised, true );
			$is_primary = '' !== $primary && hash_equals( $primary, $issuer );
			$reasons = array( 'LIVE_CERTIFICATION_PENDING' );
			if ( ! $is_advertised ) $reasons[] = 'NOT_ADVERTISED';
			if ( empty( $subjects ) ) $reasons[] = 'SUBJECT_POLICY_MISSING';
			if ( empty( $resources ) ) $reasons[] = 'RESOURCE_POLICY_EMPTY';

			$authorities[] = array(
				'authority_id' => 'oauth-authority:v1:' . substr( hash( 'sha256', $issuer ), 0, 24 ),
				'authority_type' => in_array( $type, array( 'local', 'external', 'managed', 'custom' ), true ) ? $type : 'custom',
				'issuer' => $issuer,
				'configured' => true,
				'trusted' => true,
				'advertised' => $is_advertised,
				'primary' => $is_primary,
				'standby' => ! $is_primary,
				'resource_policy_id' => MAD4B_SCP_OAuth_Resource_Bridge::resource_policy_id_for_issuer( $issuer ),
				'resource_server_ids' => array_values( $resources ),
				'subject_mapper_id' => 'issuer-bound-wp-user:v1:' . substr( hash( 'sha256', $issuer . "\0" . (string) $user_id ), 0, 24 ),
				'allowed_subject_count' => count( $subjects ),
				'runtime_verified' => false,
				'last_live_verified_at' => '',
				'last_live_verification_ref' => '',
				'metadata_source' => 'local' === $type ? 'local_in_process_metadata' : 'issuer_discovery',
				'jwks_source' => 'local' === $type ? 'local_in_process_jwks' : 'discovery_document_jwks_uri',
				'environment_scope' => array( $environment ),
				'status_reason_codes' => array_values( array_unique( $reasons ) ),
			);
		}
		usort(
			$authorities,
			static function ( $a, $b ) {
				return strcmp( isset( $a['authority_id'] ) ? $a['authority_id'] : '', isset( $b['authority_id'] ) ? $b['authority_id'] : '' );
			}
		);

		$candidate = array();
		if ( class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) {
			$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
			if ( is_array( $provenance ) ) {
				foreach ( array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity', 'runtime_manifest_match', 'stale' ) as $key ) {
					if ( array_key_exists( $key, $provenance ) ) $candidate[ $key ] = $provenance[ $key ];
				}
			}
		}

		$snapshot_material = array(
			'authority_mode' => isset( $bridge['authority_mode'] ) ? $bridge['authority_mode'] : '',
			'environment' => $environment,
			'trusted_authorities' => array_values( $trusted ),
			'advertised_authorities' => array_values( $advertised ),
			'authorities' => $authorities,
			'candidate' => $candidate,
		);
		$snapshot_sha = hash( 'sha256', wp_json_encode( $snapshot_material, JSON_UNESCAPED_SLASHES ) );

		return array(
			'contract' => self::CONTRACT,
			'configured' => ! empty( $bridge['configured'] ),
			'effective' => ! empty( $bridge['effective'] ),
			'environment' => $environment,
			'authority_mode' => isset( $bridge['authority_mode'] ) ? $bridge['authority_mode'] : '',
			'authority_count' => count( $authorities ),
			'trusted_authorities' => array_values( $trusted ),
			'advertised_authorities' => array_values( $advertised ),
			'trust_advertisement_separated' => true,
			'resource_policy_enforced_on_token_verification' => true,
			'authorities' => $authorities,
			'candidate' => $candidate,
			'authority_snapshot_sha256' => $snapshot_sha,
			'live_certification_contract' => self::LIVE_CONTRACT,
			'live_certification_verdict' => 'PENDING',
			'live_certification_required' => true,
			'live_certification_is_inferred_from_configuration' => false,
			'status_reason_codes' => empty( $authorities ) ? array( 'NO_CONFIGURED_TRUSTED_AUTHORITY' ) : array( 'LIVE_CERTIFICATION_PENDING' ),
		);
	}
}
