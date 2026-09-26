<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only, non-authorizing data/rights policy evaluator.
 *
 * Operationalizes mad4b.content-rights.v1, mad4b.ai-data-processing.v1 and
 * mad4b.data-flow-policy.v1 without making legal determinations or provider calls.
 */
final class MAD4B_SCP_Data_Governance {
	const CONTRACT = 'mad4b.data-governance-decision.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/data-processing-evaluate' ) ) ) {
		wp_register_ability(
			'mad4b/data-processing-evaluate',
			array(
				'label' => 'Data Processing and Rights Evaluation',
				'description' => 'Evaluate content rights, data-flow, AI processing, retention, training and residency constraints. Read-only and non-authorizing.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'evaluate' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/data-processing-record-decision' ) ) ) {
			wp_register_ability(
				'mad4b/data-processing-record-decision',
				array(
					'label' => 'Record Data Processing Decision',
					'description' => 'Persist one payload-minimized rights/data-processing decision as immutable ContentJob evidence. This does not call a provider or grant authority.',
					'category' => 'mad4b-write',
					'execute_callback' => array( __CLASS__, 'record_decision' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_mutate' ),
					'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ),
						'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					),
				)
			);
		}
	}

	private static function norm_list( $value ) {
		if ( ! is_array( $value ) ) return array();
		$out = array();
		foreach ( $value as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) $out[] = $item;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	private static function stable( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $k => $v ) $value[ $k ] = self::stable( $v );
		}
		return $value;
	}

	private static function digest( $value ) {
		return hash( 'sha256', wp_json_encode( self::stable( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public static function evaluate( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$purpose = sanitize_key( (string) ( $input['purpose'] ?? '' ) );
		$data_classes = self::norm_list( $input['data_classes'] ?? array() );
		$rights = isset( $input['rights_records'] ) && is_array( $input['rights_records'] ) ? $input['rights_records'] : array();
		$dest = isset( $input['destination_profile'] ) && is_array( $input['destination_profile'] ) ? $input['destination_profile'] : array();
		$policy = isset( $input['site_policy'] ) && is_array( $input['site_policy'] ) ? $input['site_policy'] : array();

		$hard = array();
		$approval = array();
		$redact = array();
		$requirements = array();
		$rights_summary = array();

		if ( '' === $purpose ) $hard[] = 'purpose_required';
		if ( empty( $data_classes ) ) $hard[] = 'data_classification_required';

		$publication_purposes = array( 'publish', 'publication', 'reproduce', 'reuse', 'media_publish', 'model_training' );
		foreach ( $rights as $record ) {
			if ( ! is_array( $record ) ) { $hard[] = 'rights_record_invalid'; continue; }
			$class = strtoupper( trim( (string) ( $record['rights_class'] ?? 'UNKNOWN' ) ) );
			$review = sanitize_key( (string) ( $record['review_status'] ?? 'unknown' ) );
			$rights_summary[] = array(
				'rights_class' => $class,
				'review_status' => $review,
				'attribution_required' => ! empty( $record['attribution_required'] ),
				'modification_allowed' => ! empty( $record['modification_allowed'] ),
				'commercial_use_allowed' => ! empty( $record['commercial_use_allowed'] ),
				'source_artifact_id' => isset( $record['source_artifact_id'] ) ? strtolower( trim( (string) $record['source_artifact_id'] ) ) : '',
				'license_id' => isset( $record['license_id'] ) ? substr( sanitize_text_field( (string) $record['license_id'] ), 0, 191 ) : '',
			);
			if ( 'PROHIBITED' === $class ) $hard[] = 'rights_prohibited';
			if ( 'UNKNOWN' === $class && in_array( $purpose, $publication_purposes, true ) ) $hard[] = 'rights_unknown_for_reuse';
			if ( 'PERMITTED_REFERENCE_ONLY' === $class && in_array( $purpose, $publication_purposes, true ) ) $hard[] = 'reference_only_reuse_denied';
			if ( ! empty( $record['attribution_required'] ) ) $requirements[] = 'attribution_required';
			if ( in_array( $review, array( 'pending', 'requires_review', 'unknown' ), true ) ) $approval[] = 'rights_review_required';
		}

		$prohibited = self::norm_list( $dest['prohibited_data_classes'] ?? array() );
		foreach ( array_intersect( $data_classes, $prohibited ) as $class ) $hard[] = 'destination_prohibits:' . $class;

		$allowed = self::norm_list( $dest['allowed_data_classes'] ?? array() );
		if ( ! empty( $allowed ) ) {
			foreach ( array_diff( $data_classes, $allowed ) as $class ) $hard[] = 'destination_not_allowed:' . $class;
		}

		$allowed_processors = self::norm_list( $policy['allowed_processors'] ?? array() );
		$provider_id = sanitize_key( (string) ( $dest['provider_id'] ?? '' ) );
		if ( ! empty( $allowed_processors ) && ( '' === $provider_id || ! in_array( $provider_id, $allowed_processors, true ) ) ) {
			$hard[] = 'processor_not_allowed';
		}

		$processor_type = sanitize_key( (string) ( $dest['processor_type'] ?? '' ) );
		$no_external_ai = ! empty( $policy['no_external_ai'] );
		$local_only = $no_external_ai && 'ai_external' === $processor_type;

		$allowed_regions = self::norm_list( $policy['allowed_processing_regions'] ?? array() );
		$region = sanitize_key( (string) ( $dest['region'] ?? '' ) );
		if ( ! empty( $allowed_regions ) && ( '' === $region || ! in_array( $region, $allowed_regions, true ) ) ) {
			$hard[] = 'processing_region_not_allowed';
		}

		$training_allowed = array_key_exists( 'training_use_allowed', $policy ) ? ! empty( $policy['training_use_allowed'] ) : false;
		$provider_training = ! empty( $dest['training_use'] );
		if ( $provider_training && ! $training_allowed ) $hard[] = 'provider_training_use_denied';

		$max_retention = isset( $policy['max_retention_days'] ) ? (int) $policy['max_retention_days'] : 0;
		$retention = isset( $dest['retention_days'] ) ? (int) $dest['retention_days'] : -1;
		if ( $max_retention > 0 && ( $retention < 0 || $retention > $max_retention ) ) $hard[] = 'retention_policy_exceeded';

		$redact_classes = self::norm_list( $policy['redact_data_classes'] ?? array() );
		foreach ( array_intersect( $data_classes, $redact_classes ) as $class ) $redact[] = $class;
		$approval_classes = self::norm_list( $policy['approval_required_data_classes'] ?? array() );
		foreach ( array_intersect( $data_classes, $approval_classes ) as $class ) $approval[] = 'approval_required:' . $class;

		$hard = array_values( array_unique( $hard ) );
		$approval = array_values( array_unique( $approval ) );
		$redact = array_values( array_unique( $redact ) );
		$requirements = array_values( array_unique( $requirements ) );

		if ( ! empty( $hard ) ) $decision = 'DENY';
		elseif ( $local_only ) $decision = 'LOCAL_ONLY';
		elseif ( ! empty( $approval ) ) $decision = 'REQUIRE_APPROVAL';
		elseif ( ! empty( $redact ) ) $decision = 'REDACT_THEN_ALLOW';
		else $decision = 'ALLOW';

		usort( $rights_summary, static function( $a, $b ) {
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		} );
		$processor_profile = array(
			'provider_id' => $provider_id,
			'processor_type' => $processor_type,
			'region' => $region,
			'training_use' => $provider_training,
			'retention_days' => $retention,
			'allowed_data_classes' => $allowed,
			'prohibited_data_classes' => $prohibited,
		);

		$evidence = array(
			'purpose' => $purpose,
			'data_classes' => $data_classes,
			'provider_id' => $provider_id,
			'processor_type' => $processor_type,
			'region' => $region,
			'policy_revision' => sanitize_text_field( (string) ( $policy['policy_revision'] ?? '' ) ),
			'rights_record_count' => count( $rights ),
			'rights_summary_fingerprint' => self::digest( $rights_summary ),
			'processor_profile_fingerprint' => self::digest( $processor_profile ),
		);

		return array(
			'contract' => self::CONTRACT,
			'decision' => $decision,
			'hard_denials' => $hard,
			'approval_requirements' => $approval,
			'redact_data_classes' => $redact,
			'requirements' => $requirements,
			'evidence' => $evidence,
			'decision_fingerprint' => self::digest( array( $evidence, $hard, $approval, $redact, $requirements, $decision ) ),
			'authorizing' => false,
			'mutation_performed' => false,
			'legal_determination' => false,
			'raw_secrets_persisted' => false,
		);
	}

	public static function validate_decision_artifact( $job_id, $artifact_id, $provider_id = '', $allowed_decisions = array( 'ALLOW', 'REDACT_THEN_ALLOW' ) ) {
		$job_id = strtolower( trim( (string) $job_id ) );
		$artifact_id = strtolower( trim( (string) $artifact_id ) );
		$provider_id = sanitize_key( (string) $provider_id );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $job_id ) || 1 !== preg_match( '/^[a-f0-9-]{36}$/', $artifact_id ) ) {
			return new WP_Error( 'mad4b_data_governance_evidence_identity_invalid', 'Data-governance evidence identity is invalid.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_data_governance_artifacts_unavailable', 'Artifact Registry is unavailable.' );
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
		if ( is_wp_error( $result ) ) return $result;
		$row = isset( $result['artifact'] ) && is_array( $result['artifact'] ) ? $result['artifact'] : array();
		if ( ! isset( $row['job_id'] ) || ! hash_equals( $job_id, (string) $row['job_id'] ) ) return new WP_Error( 'mad4b_data_governance_cross_job', 'Data-governance evidence belongs to another ContentJob.' );
		if ( 'data_governance_decision' !== (string) ( $row['artifact_type'] ?? '' ) ) return new WP_Error( 'mad4b_data_governance_artifact_type_invalid', 'Artifact is not a data-governance decision.' );
		if ( 'active' !== (string) ( $row['status'] ?? '' ) ) return new WP_Error( 'mad4b_data_governance_artifact_stale', 'Data-governance decision is not active/current.' );
		$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
		$decision = strtoupper( (string) ( $payload['decision'] ?? '' ) );
		if ( ! in_array( $decision, $allowed_decisions, true ) ) return new WP_Error( 'mad4b_data_governance_decision_denied', 'Data-governance decision does not permit this processing plan.' );
		$evidence = isset( $payload['evidence'] ) && is_array( $payload['evidence'] ) ? $payload['evidence'] : array();
		$observed_provider = sanitize_key( (string) ( $evidence['provider_id'] ?? '' ) );
		if ( '' !== $provider_id && ( '' === $observed_provider || ! hash_equals( $provider_id, $observed_provider ) ) ) {
			return new WP_Error( 'mad4b_data_governance_provider_mismatch', 'Data-governance decision is bound to a different provider.' );
		}
		$fingerprint = strtolower( trim( (string) ( $payload['decision_fingerprint'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_data_governance_fingerprint_invalid', 'Data-governance decision fingerprint is invalid.' );
		return array(
			'artifact_id' => $artifact_id,
			'decision' => $decision,
			'decision_fingerprint' => $fingerprint,
			'provider_id' => $observed_provider,
			'policy_revision' => (string) ( $evidence['policy_revision'] ?? '' ),
			'rights_summary_fingerprint' => (string) ( $evidence['rights_summary_fingerprint'] ?? '' ),
			'processor_profile_fingerprint' => (string) ( $evidence['processor_profile_fingerprint'] ?? '' ),
			'authorizing' => false,
		);
	}

	public static function record_decision( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = strtolower( trim( (string) ( $input['job_id'] ?? '' ) ) );
		$reason = sanitize_text_field( (string) ( $input['reason'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $job_id ) ) return new WP_Error( 'mad4b_data_governance_job_invalid', 'ContentJob ID is invalid.' );
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_data_governance_reason_required', 'A bounded reason is required to persist data-governance evidence.' );
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_data_governance_artifacts_unavailable', 'Artifact Registry is unavailable.' );

		$source_artifact_ids = isset( $input['source_artifact_ids'] ) && is_array( $input['source_artifact_ids'] ) ? array_values( array_unique( $input['source_artifact_ids'] ) ) : array();
		if ( count( $source_artifact_ids ) > 64 ) return new WP_Error( 'mad4b_data_governance_source_limit', 'Too many source artifacts for one data-governance decision.' );
		foreach ( $source_artifact_ids as $source_artifact_id ) {
			if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', strtolower( trim( (string) $source_artifact_id ) ) ) ) {
				return new WP_Error( 'mad4b_data_governance_source_invalid', 'Source Artifact identity is invalid.' );
			}
		}
		$decision_input = $input;
		unset( $decision_input['job_id'], $decision_input['reason'], $decision_input['source_artifact_ids'] );
		$decision = self::evaluate( $decision_input );

		$payload = array(
			'contract' => 'mad4b.data-governance-evidence.v1',
			'decision' => (string) $decision['decision'],
			'hard_denials' => (array) $decision['hard_denials'],
			'approval_requirements' => (array) $decision['approval_requirements'],
			'redact_data_classes' => (array) $decision['redact_data_classes'],
			'requirements' => (array) $decision['requirements'],
			'evidence' => (array) $decision['evidence'],
			'decision_fingerprint' => (string) $decision['decision_fingerprint'],
			'payload_minimized' => true,
			'raw_rights_records_persisted' => false,
			'raw_source_content_persisted' => false,
			'raw_secrets_persisted' => false,
			'source_artifact_ids' => array_map( 'strval', $source_artifact_ids ),
			'authorizing' => false,
		);
		$result = MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => $job_id,
				'artifact_type' => 'data_governance_decision',
				'payload' => $payload,
				'metadata' => array(
					'decision_fingerprint' => (string) $decision['decision_fingerprint'],
					'policy_revision' => (string) ( $decision['evidence']['policy_revision'] ?? '' ),
					'provider_id' => (string) ( $decision['evidence']['provider_id'] ?? '' ),
				),
				'producer_stage' => 'DATA_GOVERNANCE',
				'producer_ref' => self::CONTRACT,
				'reason' => $reason,
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		$artifact_id = isset( $result['artifact']['artifact_id'] ) ? (string) $result['artifact']['artifact_id'] : '';
		if ( '' !== $artifact_id && method_exists( 'MAD4B_SCP_Artifacts', 'link_artifacts' ) ) {
			foreach ( $source_artifact_ids as $source_artifact_id ) {
				$link = MAD4B_SCP_Artifacts::link_artifacts( array(
					'from_artifact_id' => $artifact_id,
					'to_artifact_id' => strtolower( trim( (string) $source_artifact_id ) ),
					'relation' => 'uses',
				) );
				if ( is_wp_error( $link ) ) return $link;
			}
		}
		return array(
			'contract' => 'mad4b.data-governance-record.v1',
			'decision' => (string) $decision['decision'],
			'decision_fingerprint' => (string) $decision['decision_fingerprint'],
			'artifact' => isset( $result['artifact'] ) ? $result['artifact'] : array(),
			'provider_call_performed' => false,
			'authority_created' => false,
			'mutation_performed' => true,
		);
	}
}

MAD4B_SCP_Data_Governance::boot();
