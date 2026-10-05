<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only live evidence producer for Production readiness certification.
 *
 * This service never grants Production authority and never performs mutation.
 * It binds current runtime/identity evidence to the exact deployed candidate so
 * Staging certification can be composed without caller-supplied booleans.
 */
final class MAD4B_SCP_Production_Certification {
	const CONTRACT = 'mad4b.production-certification-runtime.v1';
	const ABILITY = 'mad4b/production-certification-readonly-evidence';
	const STATUS_ABILITY = 'mad4b/production-certification-status';
	const PLAN_ABILITY = 'mad4b/production-certification-plan';
	const MULTI_AUTH_CONTRACT = 'mad4b.multi-authority-live-canary.v1';
	const POLICY_PROBE_CONTRACT = 'mad4b.production-policy-probe.v1';
	const SECURITY_CANARY_CONTRACT = 'mad4b.production-security-live-canary.v1';

	private static $stages = array(
		'provider_side_channel_inventory' => 'provider_side_channel_safe',
		'multi_authority_canary' => 'multi_authority_live',
		'policy_resolution_canary' => 'policy_engine_ready',
		'security_fault_canary' => 'kernel_quality_verified',
		'operator_doctor' => 'operator_recovery_review_ready',
	);

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 38 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::ABILITY ) ) {
			wp_register_ability(
				self::ABILITY,
				array(
					'label' => 'Production Certification Read-only Evidence',
					'description' => 'Produce exact-candidate Staging evidence for one read-only Production-readiness stage without granting authority or mutating state.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'execute' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'stage_id' => array(
								'type' => 'string',
								'enum' => array_keys( self::$stages ),
							),
						),
						'required' => array( 'stage_id' ),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::STATUS_ABILITY ) ) {
			wp_register_ability(
				self::STATUS_ABILITY,
				array(
					'label' => 'Production Certification Status',
					'description' => 'Return the exact-candidate Production-certification stage matrix, local read-only canaries and external evidence requirements without mutation or authority.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		}
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::PLAN_ABILITY ) ) {
			wp_register_ability(
				self::PLAN_ABILITY,
				array(
					'label' => 'Production Certification Plan',
					'description' => 'Build one deterministic exact-candidate Staging certification session plan with local read-only evidence and bounded external evidence requirements.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
						'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					),
				)
			);
		}
	}

	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$stage = isset( $input['stage_id'] ) ? sanitize_key( (string) $input['stage_id'] ) : '';
		if ( ! isset( self::$stages[ $stage ] ) ) {
			return new WP_Error( 'mad4b_production_certification_stage_invalid', 'Requested Production certification stage is not a registered read-only stage.' );
		}
		$identity = self::candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		if ( 'provider_side_channel_inventory' === $stage ) return self::provider_side_channel( $identity );
		if ( 'multi_authority_canary' === $stage ) return self::multi_authority_canary( $identity );
		if ( 'policy_resolution_canary' === $stage ) return self::policy_probe( $identity );
		if ( 'security_fault_canary' === $stage ) return self::security_fault_canary( $identity );
		if ( 'operator_doctor' === $stage ) return self::operator_doctor( $identity );
		return new WP_Error( 'mad4b_production_certification_stage_unimplemented', 'Requested Production certification stage is not implemented.' );
	}

	public static function plan( $input = array() ) {
		$identity = self::candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		$environment = self::current_environment();
		if ( 'staging' !== $environment ) {
			return new WP_Error( 'mad4b_production_certification_plan_environment_not_staging', 'Production certification session planning is Staging-only.' );
		}
		$plan = self::load_config( 'production-certification-plan.json', 'mad4b.production-certification-plan.v1' );
		if ( is_wp_error( $plan ) ) return $plan;
		$policy = self::load_config( 'production-readiness-policy.json', 'mad4b.production-readiness-policy.v2' );
		if ( is_wp_error( $policy ) ) return $policy;

		$stage_rows = array();
		$local_evidence = array();
		$external_requirements = array();
		$ordinal = 0;
		foreach ( isset( $plan['stages'] ) && is_array( $plan['stages'] ) ? $plan['stages'] : array() as $stage ) {
			if ( ! is_array( $stage ) ) continue;
			$ordinal++;
			$id = sanitize_key( isset( $stage['id'] ) ? (string) $stage['id'] : '' );
			$gate = sanitize_key( isset( $stage['gate'] ) ? (string) $stage['gate'] : '' );
			$mutation_class = sanitize_key( isset( $stage['mutation_class'] ) ? (string) $stage['mutation_class'] : '' );
			$trust_mode = sanitize_key( isset( $stage['trust_mode'] ) ? (string) $stage['trust_mode'] : '' );
			$local = isset( self::$stages[ $id ] )
				&& 'runtime_recompute' === $trust_mode
				&& self::ABILITY === ( isset( $stage['producer_ability'] ) ? (string) $stage['producer_ability'] : '' );
			$row = array(
				'ordinal' => $ordinal,
				'stage_id' => $id,
				'gate' => $gate,
				'mutation_class' => $mutation_class,
				'producer' => isset( $stage['producer'] ) ? (string) $stage['producer'] : '',
				'producer_contract' => isset( $stage['producer_contract'] ) ? (string) $stage['producer_contract'] : '',
				'trust_mode' => $trust_mode,
				'attestation_method' => isset( $stage['attestation_method'] ) ? sanitize_key( (string) $stage['attestation_method'] ) : '',
				'rollback_required' => true === ( isset( $stage['rollback_required'] ) ? $stage['rollback_required'] : false ),
				'collection_mode' => $local ? 'runtime_recompute' : ( 'reversible_staging_mutation' === $mutation_class ? 'external_reversible_staging_evidence' : 'external_signed_evidence' ),
			);
			$stage_rows[] = $row;
			if ( $local ) {
				$evidence = self::execute( array( 'stage_id' => $id ) );
				if ( is_wp_error( $evidence ) ) {
					return new WP_Error(
						'mad4b_production_certification_plan_local_evidence_blocked',
						'Production certification session cannot be planned while a local read-only certification stage is blocked.',
						array( 'stage_id' => $id, 'cause' => $evidence->get_error_code(), 'mutation_performed' => false, 'production_authorized' => false )
					);
				}
				$local_evidence[] = $evidence;
			} else {
				$external_requirements[] = $row;
			}
		}

		$core = isset( $policy['profiles']['control_plane_core'] ) && is_array( $policy['profiles']['control_plane_core'] ) ? $policy['profiles']['control_plane_core'] : array();
		$optional = isset( $core['optional_workstream_ids'] ) && is_array( $core['optional_workstream_ids'] ) ? array_values( array_map( 'strval', $core['optional_workstream_ids'] ) ) : array();
		sort( $optional, SORT_STRING );
		$material = array(
			'contract' => 'mad4b.production-certification-session-material.v1',
			'profile' => 'control_plane_core',
			'environment' => $environment,
			'candidate_identity' => $identity,
			'stages' => $stage_rows,
			'optional_capabilities_disabled' => $optional,
			'terminal_evaluator' => 'mad4b/production-readiness-evaluate',
		);
		$plan_sha256 = self::canonical_digest( $material );
		if ( '' === $plan_sha256 ) return new WP_Error( 'mad4b_production_certification_plan_digest_failed', 'Production certification session plan could not be canonicalized.' );

		return array(
			'contract' => 'mad4b.production-certification-session-plan.v1',
			'profile' => 'control_plane_core',
			'environment' => $environment,
			'candidate_identity' => $identity,
			'session_id' => 'pc-' . substr( $plan_sha256, 0, 24 ),
			'plan_sha256' => $plan_sha256,
			'stage_count' => count( $stage_rows ),
			'local_evidence_count' => count( $local_evidence ),
			'external_evidence_required_count' => count( $external_requirements ),
			'stages' => $stage_rows,
			'local_evidence_seed' => $local_evidence,
			'external_evidence_requirements' => $external_requirements,
			'bundle_template' => array(
				'contract' => 'mad4b.production-live-evidence-bundle.v1',
				'profile' => 'control_plane_core',
				'candidate_identity' => $identity,
				'evidence' => $local_evidence,
				'authorizing' => false,
				'production_authorized' => false,
			),
			'terminal_evaluator' => 'mad4b/production-readiness-evaluate',
			'optional_capabilities_disabled' => $optional,
			'production_ready' => false,
			'production_authorized' => false,
			'promotion_required' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}

	public static function status( $input = array() ) {
		$identity = self::candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;
		$plan = self::load_config( 'production-certification-plan.json', 'mad4b.production-certification-plan.v1' );
		if ( is_wp_error( $plan ) ) return $plan;
		$policy = self::load_config( 'production-readiness-policy.json', 'mad4b.production-readiness-policy.v2' );
		if ( is_wp_error( $policy ) ) return $policy;

		$rows = array();
		$local_ready = 0;
		$local_blocked = 0;
		$external_required = 0;
		foreach ( isset( $plan['stages'] ) && is_array( $plan['stages'] ) ? $plan['stages'] : array() as $stage ) {
			if ( ! is_array( $stage ) ) continue;
			$id = sanitize_key( isset( $stage['id'] ) ? (string) $stage['id'] : '' );
			$gate = sanitize_key( isset( $stage['gate'] ) ? (string) $stage['gate'] : '' );
			$trust_mode = sanitize_key( isset( $stage['trust_mode'] ) ? (string) $stage['trust_mode'] : '' );
			$mutation_class = sanitize_key( isset( $stage['mutation_class'] ) ? (string) $stage['mutation_class'] : '' );
			$local = isset( self::$stages[ $id ] )
				&& 'runtime_recompute' === $trust_mode
				&& self::ABILITY === ( isset( $stage['producer_ability'] ) ? (string) $stage['producer_ability'] : '' );
			$row = array(
				'stage_id' => $id,
				'gate' => $gate,
				'mutation_class' => $mutation_class,
				'producer' => isset( $stage['producer'] ) ? (string) $stage['producer'] : '',
				'producer_contract' => isset( $stage['producer_contract'] ) ? (string) $stage['producer_contract'] : '',
				'trust_mode' => $trust_mode,
				'attestation_method' => isset( $stage['attestation_method'] ) ? sanitize_key( (string) $stage['attestation_method'] ) : '',
				'local_runtime_recompute' => $local,
				'ready' => false,
				'blockers' => array(),
			);
			if ( $local ) {
				$evidence = self::execute( array( 'stage_id' => $id ) );
				if ( is_wp_error( $evidence ) ) {
					$row['blockers'][] = $evidence->get_error_code();
					$local_blocked++;
				} else {
					$row['ready'] = ! empty( $evidence['ready'] );
					$row['blockers'] = isset( $evidence['blockers'] ) && is_array( $evidence['blockers'] ) ? array_values( $evidence['blockers'] ) : array();
					$row['producer_evidence_sha256'] = isset( $evidence['producer_evidence_sha256'] ) ? (string) $evidence['producer_evidence_sha256'] : '';
					if ( $row['ready'] ) $local_ready++; else $local_blocked++;
				}
			} else {
				$row['collection_mode'] = 'reversible_staging_mutation' === $mutation_class
					? 'external_reversible_staging_evidence_required'
					: 'external_signed_evidence_required';
				$row['blockers'][] = 'external_evidence_required';
				$external_required++;
			}
			$rows[] = $row;
		}

		$core = isset( $policy['profiles']['control_plane_core'] ) && is_array( $policy['profiles']['control_plane_core'] ) ? $policy['profiles']['control_plane_core'] : array();
		$optional = isset( $core['optional_workstream_ids'] ) && is_array( $core['optional_workstream_ids'] ) ? array_values( array_map( 'strval', $core['optional_workstream_ids'] ) ) : array();
		sort( $optional, SORT_STRING );

		return array(
			'contract' => 'mad4b.production-certification-status.v1',
			'profile' => 'control_plane_core',
			'environment' => self::current_environment(),
			'candidate_identity' => $identity,
			'stage_count' => count( $rows ),
			'local_runtime_stage_count' => $local_ready + $local_blocked,
			'local_ready_count' => $local_ready,
			'local_blocked_count' => $local_blocked,
			'external_evidence_required_count' => $external_required,
			'stages' => $rows,
			'optional_capabilities_disabled' => $optional,
			'canonical_runtime_evaluation_required' => true,
			'production_ready' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
			'production_mutation' => false,
		);
	}

	private static function load_config( $file, $contract ) {
		$path = dirname( __DIR__ ) . '/config/' . basename( (string) $file );
		if ( ! is_file( $path ) || is_link( $path ) ) return new WP_Error( 'mad4b_production_certification_config_unavailable', 'Production certification configuration is unavailable.' );
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || (string) $contract !== ( isset( $decoded['contract'] ) ? (string) $decoded['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_production_certification_config_invalid', 'Production certification configuration contract is invalid.' );
		}
		return $decoded;
	}

	private static function candidate_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) {
			return new WP_Error( 'mad4b_production_certification_candidate_identity_unavailable', 'Exact runtime candidate identity is unavailable.' );
		}
		$source = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
		if ( ! is_array( $source ) || empty( $source['identity_ready'] ) ) {
			return new WP_Error( 'mad4b_production_certification_candidate_identity_not_ready', 'Exact runtime candidate identity is not ready.' );
		}
		$identity = array(
			'source_commit_sha' => strtolower( trim( (string) ( isset( $source['source_commit_sha'] ) ? $source['source_commit_sha'] : '' ) ) ),
			'build_fingerprint' => strtolower( trim( (string) ( isset( $source['build_fingerprint'] ) ? $source['build_fingerprint'] : '' ) ) ),
			'package_manifest_digest' => strtolower( trim( (string) ( isset( $source['package_manifest_digest'] ) ? $source['package_manifest_digest'] : '' ) ) ),
		);
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $identity['source_commit_sha'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['build_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity['package_manifest_digest'] ) ) {
			return new WP_Error( 'mad4b_production_certification_candidate_identity_invalid', 'Exact runtime candidate identity is malformed.' );
		}
		return $identity;
	}

	private static function current_environment() {
		if ( class_exists( 'MAD4B_SCP_Environment' ) && method_exists( 'MAD4B_SCP_Environment', 'effective' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Environment::effective() );
		}
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'current_environment' ) ) {
			return sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		}
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function envelope( $stage, $producer, array $source, $ready, array $blockers, array $identity ) {
		$environment = self::current_environment();
		if ( 'staging' !== $environment ) {
			$ready = false;
			$blockers[] = 'certification_environment_not_staging';
		}
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
		$producer_contract = isset( $source['contract'] ) ? (string) $source['contract'] : '';
		if ( '' === $producer_contract ) {
			$ready = false;
			$blockers[] = 'producer_contract_missing';
		}
		return array(
			'contract' => 'mad4b.production-live-gate-evidence.v1',
			'stage_id' => (string) $stage,
			'gate' => self::$stages[ $stage ],
			'environment' => $environment,
			'candidate_identity' => $identity,
			'producer' => (string) $producer,
			'producer_contract' => $producer_contract,
			'producer_evidence' => $source,
			'producer_evidence_sha256' => self::canonical_digest( $source ),
			'ready' => (bool) $ready && empty( $blockers ),
			'blockers' => $blockers,
			'mutation_performed' => false,
			'production_mutation' => false,
			'authorizing' => false,
		);
	}

	private static function provider_side_channel( array $identity ) {
		$status = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' )
			? MAD4B_SCP_MCP_Peer_Governance::status()
			: array( 'contract' => 'mad4b.mcp-peer-governance.v2', 'inventory_ready' => false, 'blockers' => array( 'mcp_peer_governance_unavailable' ) );
		$blockers = isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? array_values( $status['blockers'] ) : array();
		if ( empty( $status['inventory_ready'] ) ) $blockers[] = 'mcp_peer_inventory_unavailable';
		if ( empty( $status['foreign_transport_inventory_ready'] ) ) $blockers[] = 'mcp_foreign_transport_inventory_unavailable';
		if ( ! empty( $status['write_side_channel_detected'] ) ) $blockers[] = 'mcp_write_side_channel_detected';
		if ( ! empty( $status['foreign_transport_unreviewed'] ) ) $blockers[] = 'mcp_foreign_transport_unreviewed';
		$status['candidate_identity'] = $identity;
		$status['production_mutation'] = false;
		$status['authorizing'] = false;
		return self::envelope(
			'provider_side_channel_inventory',
			'mad4b/production-certification-readonly-evidence#mcp-peer-governance',
			$status,
			empty( $blockers ),
			$blockers,
			$identity
		);
	}

	private static function multi_authority_canary( array $identity ) {
		$registry = class_exists( 'MAD4B_SCP_Multi_Authority_Registry' ) ? MAD4B_SCP_Multi_Authority_Registry::snapshot() : array();
		$context = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$server_id = class_exists( 'MAD4B_SCP_Transport_Context' ) && method_exists( 'MAD4B_SCP_Transport_Context', 'current_server_id' )
			? sanitize_key( (string) MAD4B_SCP_Transport_Context::current_server_id() )
			: '';
		$blockers = array();
		if ( is_wp_error( $context ) || ! is_array( $context ) || empty( $context['authenticated'] ) ) $blockers[] = 'authenticated_subject_required';
		if ( ! is_array( $context ) || 'oauth2_bearer' !== ( isset( $context['auth_method'] ) ? (string) $context['auth_method'] : '' ) ) $blockers[] = 'oauth2_bearer_required';
		$subject_fp = is_array( $context ) && isset( $context['subject_fingerprint'] ) ? strtolower( (string) $context['subject_fingerprint'] ) : '';
		$issuer_fp = is_array( $context ) && isset( $context['issuer_fingerprint'] ) ? strtolower( (string) $context['issuer_fingerprint'] ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $subject_fp ) ) $blockers[] = 'subject_fingerprint_missing';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $issuer_fp ) ) $blockers[] = 'issuer_fingerprint_missing';
		if ( '' === $server_id ) $blockers[] = 'transport_server_identity_missing';
		if ( empty( $bridge['effective'] ) ) $blockers[] = 'oauth_resource_bridge_not_effective';

		$matched = array();
		foreach ( isset( $registry['authorities'] ) && is_array( $registry['authorities'] ) ? $registry['authorities'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['issuer'] ) ) continue;
			if ( '' !== $issuer_fp && hash_equals( hash( 'sha256', rtrim( (string) $row['issuer'], '/' ) ), $issuer_fp ) ) {
				$matched = $row;
				break;
			}
		}
		if ( empty( $matched ) ) {
			$blockers[] = 'issuer_not_bound_to_registry';
		} else {
			if ( empty( $matched['trusted'] ) ) $blockers[] = 'issuer_not_trusted';
			if ( empty( $matched['advertised'] ) ) $blockers[] = 'issuer_not_advertised';
			if ( empty( $matched['allowed_subject_count'] ) ) $blockers[] = 'subject_policy_missing';
			$resources = isset( $matched['resource_server_ids'] ) && is_array( $matched['resource_server_ids'] ) ? $matched['resource_server_ids'] : array();
			if ( '' === $server_id || ! in_array( $server_id, $resources, true ) ) $blockers[] = 'resource_policy_mismatch';
		}
		$source = array(
			'contract' => self::MULTI_AUTH_CONTRACT,
			'candidate_identity' => $identity,
			'authority_snapshot_sha256' => isset( $registry['authority_snapshot_sha256'] ) ? (string) $registry['authority_snapshot_sha256'] : '',
			'authority_id' => isset( $matched['authority_id'] ) ? (string) $matched['authority_id'] : '',
			'authority_type' => isset( $matched['authority_type'] ) ? (string) $matched['authority_type'] : '',
			'transport_server_id' => $server_id,
			'subject_fingerprint' => $subject_fp,
			'issuer_fingerprint' => $issuer_fp,
			'oauth_bridge_contract' => isset( $bridge['contract'] ) ? (string) $bridge['contract'] : '',
			'ready' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'mutation_performed' => false,
			'production_mutation' => false,
			'authorizing' => false,
		);
		return self::envelope(
			'multi_authority_canary',
			'mad4b/production-certification-readonly-evidence#multi-authority',
			$source,
			empty( $blockers ),
			$blockers,
			$identity
		);
	}

	private static function policy_probe( array $identity ) {
		$blockers = array();
		if ( ! class_exists( 'MAD4B_SCP_Policy_Resolution' ) ) {
			$blockers[] = 'policy_resolution_unavailable';
			return self::envelope( 'policy_resolution_canary', 'mad4b/production-certification-readonly-evidence#policy-probe', array(
				'contract' => self::POLICY_PROBE_CONTRACT,
				'candidate_identity' => $identity,
				'ready' => false,
				'blockers' => $blockers,
				'mutation_performed' => false,
				'production_mutation' => false,
				'authorizing' => false,
			), false, $blockers, $identity );
		}
		$environment = self::current_environment();
		$mode = MAD4B_SCP_Policy_Resolution::current_operating_mode();
		$config_sha = MAD4B_SCP_Policy_Resolution::config_digest();
		$authority = class_exists( 'MAD4B_SCP_Authorization' ) ? MAD4B_SCP_Authorization::authority_status() : array( 'blockers' => array( 'authorization_status_unavailable' ) );
		if ( is_wp_error( $mode ) ) $blockers[] = $mode->get_error_code();
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $config_sha ) ) $blockers[] = 'policy_config_digest_invalid';
		$authority_blockers = isset( $authority['blockers'] ) && is_array( $authority['blockers'] ) ? array_values( $authority['blockers'] ) : array();
		foreach ( $authority_blockers as $item ) $blockers[] = sanitize_key( (string) $item );
		$base_facts = array(
			'hard_deny' => false,
			'kill_switch_active' => false,
			'environment_allowed' => MAD4B_SCP_Policy_Resolution::environment_allowed( $environment ),
			'authority_allowed' => empty( $authority_blockers ),
			'certification_allowed' => true,
			'quality_release_allowed' => true,
			'approval_required' => false,
			'approval_satisfied' => true,
			'grant_allowed' => true,
			'feature_enabled' => true,
			'environment' => $environment,
			'capability' => self::ABILITY,
			'target_fingerprint' => '',
			'operating_mode' => is_wp_error( $mode ) ? '' : (string) $mode,
			'policy_versions' => array( 'policy_resolution_config_sha256' => (string) $config_sha ),
			'required_approvals' => array(),
		);
		$allow = empty( $blockers ) ? MAD4B_SCP_Policy_Resolution::resolve( $base_facts ) : new WP_Error( 'mad4b_production_policy_probe_prerequisite_failed', 'Policy probe prerequisites are not ready.' );
		$kill_facts = $base_facts;
		$kill_facts['kill_switch_active'] = true;
		$kill = empty( $blockers ) ? MAD4B_SCP_Policy_Resolution::resolve( $kill_facts ) : new WP_Error( 'mad4b_production_policy_probe_prerequisite_failed', 'Policy probe prerequisites are not ready.' );
		if ( is_wp_error( $allow ) || 'ALLOW' !== ( isset( $allow['decision'] ) ? (string) $allow['decision'] : '' ) ) $blockers[] = 'policy_allow_probe_failed';
		if ( is_wp_error( $kill ) || 'DENY' !== ( isset( $kill['decision'] ) ? (string) $kill['decision'] : '' ) || 'kill_switch_active' !== ( isset( $kill['reason_code'] ) ? (string) $kill['reason_code'] : '' ) ) {
			$blockers[] = 'policy_kill_switch_probe_failed';
		}
		$source = array(
			'contract' => self::POLICY_PROBE_CONTRACT,
			'candidate_identity' => $identity,
			'environment' => $environment,
			'operating_mode' => is_wp_error( $mode ) ? '' : (string) $mode,
			'policy_config_sha256' => (string) $config_sha,
			'allow_probe' => is_wp_error( $allow ) ? array( 'error_code' => $allow->get_error_code() ) : $allow,
			'kill_switch_probe' => is_wp_error( $kill ) ? array( 'error_code' => $kill->get_error_code() ) : $kill,
			'authority_status' => $authority,
			'ready' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'mutation_performed' => false,
			'production_mutation' => false,
			'authorizing' => false,
		);
		return self::envelope(
			'policy_resolution_canary',
			'mad4b/production-certification-readonly-evidence#policy-probe',
			$source,
			empty( $blockers ),
			$blockers,
			$identity
		);
	}

	private static function security_fault_canary( array $identity ) {
		$blockers = array();
		$write_tools = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'write_tools' )
			? array_values( (array) MAD4B_SCP_Servers::write_tools() )
			: array();
		$chatgpt_tools = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'chatgpt_base_tools' )
			? array_values( (array) MAD4B_SCP_Servers::chatgpt_base_tools() )
			: array();
		$raw_query = 'mad4b/database-raw-query';
		$raw_on_write = in_array( $raw_query, $write_tools, true );
		$raw_on_chatgpt = in_array( $raw_query, $chatgpt_tools, true );
		if ( $raw_on_write ) $blockers[] = 'raw_sql_on_write_surface';
		if ( $raw_on_chatgpt ) $blockers[] = 'raw_sql_on_chatgpt_surface';

		$host = class_exists( 'MAD4B_SCP_Host_Bridge' ) && method_exists( 'MAD4B_SCP_Host_Bridge', 'capabilities' )
			? MAD4B_SCP_Host_Bridge::capabilities()
			: array();
		if ( is_wp_error( $host ) || ! is_array( $host ) || empty( $host['contract'] ) ) {
			$blockers[] = 'host_bridge_capabilities_unavailable';
			$host = array();
		}
		foreach ( array(
			'generic_shell_available' => 'generic_shell_available',
			'raw_sql_available' => 'host_raw_sql_available',
			'caller_executable_paths_allowed' => 'caller_executable_paths_allowed',
			'caller_command_strings_allowed' => 'caller_command_strings_allowed',
		) as $key => $reason ) {
			if ( ! array_key_exists( $key, $host ) || false !== $host[ $key ] ) $blockers[] = $reason;
		}
		if ( isset( $host['production_authorized'] ) && false !== $host['production_authorized'] ) $blockers[] = 'host_bridge_production_authorized';

		$raw_write = class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) && method_exists( 'MAD4B_SCP_Governed_Runtime_Gates', 'raw_sql_write_enabled' )
			? (bool) MAD4B_SCP_Governed_Runtime_Gates::raw_sql_write_enabled()
			: false;
		$raw_ddl = class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) && method_exists( 'MAD4B_SCP_Governed_Runtime_Gates', 'raw_sql_ddl_enabled' )
			? (bool) MAD4B_SCP_Governed_Runtime_Gates::raw_sql_ddl_enabled()
			: false;
		$raw_breakglass = class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) && method_exists( 'MAD4B_SCP_Governed_Runtime_Gates', 'raw_sql_breakglass_enabled' )
			? (bool) MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()
			: false;
		if ( $raw_write ) $blockers[] = 'raw_sql_write_gate_enabled';
		if ( $raw_ddl ) $blockers[] = 'raw_sql_ddl_gate_enabled';
		if ( $raw_breakglass ) $blockers[] = 'raw_sql_breakglass_gate_enabled';

		$source = array(
			'contract' => self::SECURITY_CANARY_CONTRACT,
			'candidate_identity' => $identity,
			'write_tool_count' => count( $write_tools ),
			'chatgpt_tool_count' => count( $chatgpt_tools ),
			'raw_sql_on_write_surface' => $raw_on_write,
			'raw_sql_on_chatgpt_surface' => $raw_on_chatgpt,
			'host_bridge_contract' => isset( $host['contract'] ) ? (string) $host['contract'] : '',
			'generic_shell_available' => isset( $host['generic_shell_available'] ) ? (bool) $host['generic_shell_available'] : null,
			'host_raw_sql_available' => isset( $host['raw_sql_available'] ) ? (bool) $host['raw_sql_available'] : null,
			'caller_executable_paths_allowed' => isset( $host['caller_executable_paths_allowed'] ) ? (bool) $host['caller_executable_paths_allowed'] : null,
			'caller_command_strings_allowed' => isset( $host['caller_command_strings_allowed'] ) ? (bool) $host['caller_command_strings_allowed'] : null,
			'raw_sql_write_enabled' => $raw_write,
			'raw_sql_ddl_enabled' => $raw_ddl,
			'raw_sql_breakglass_enabled' => $raw_breakglass,
			'ready' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'mutation_performed' => false,
			'production_mutation' => false,
			'authorizing' => false,
		);
		return self::envelope(
			'security_fault_canary',
			'mad4b/production-certification-readonly-evidence#security-fault-canary',
			$source,
			empty( $blockers ),
			$blockers,
			$identity
		);
	}

	private static function operator_doctor( array $identity ) {
		$doctor = class_exists( 'MAD4B_SCP_Operator_Doctor' ) ? MAD4B_SCP_Operator_Doctor::doctor( array( 'stale_seconds' => 900, 'limit' => 25 ) ) : new WP_Error( 'mad4b_operator_doctor_unavailable', 'Operator Doctor runtime is unavailable.' );
		$blockers = array();
		if ( is_wp_error( $doctor ) ) {
			$blockers[] = $doctor->get_error_code();
			$source = array(
				'contract' => 'mad4b.operator-doctor.v1',
				'candidate_identity' => $identity,
				'healthy' => false,
				'finding_count' => 0,
				'mutation_performed' => false,
				'authorizing' => false,
			);
		} else {
			$source = $doctor;
			$source['candidate_identity'] = $identity;
			$source['production_mutation'] = false;
			if ( empty( $doctor['healthy'] ) ) $blockers[] = 'operator_doctor_findings_present';
		}
		return self::envelope(
			'operator_doctor',
			'mad4b/production-certification-readonly-evidence#operator-doctor',
			$source,
			empty( $blockers ),
			$blockers,
			$identity
		);
	}

	private static function canonical_digest( $value ) {
		$sorted = self::sort_value( $value );
		$json = wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function sort_value( $value ) {
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$out = array();
			foreach ( $value as $item ) $out[] = self::sort_value( $item );
			return $out;
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Production_Certification::boot();
