<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable rights and AI data-processing registries.
 *
 * Raw source/license text, credentials and provider secrets are never persisted.
 * All write surfaces are normal governed writes and never grant Production authority.
 */
final class MAD4B_SCP_Data_Governance_Registry {
	const RIGHTS_PLAN = 'mad4b.rights-record-plan.v1';
	const PROFILE_PLAN = 'mad4b.data-processing-profile-plan.v1';
	const TAKEDOWN_PLAN = 'mad4b.rights-takedown-plan.v1';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/rights-record-plan', 'Plan Rights Record', 'rights_plan', true );
		self::register( 'mad4b/rights-record-apply', 'Apply Rights Record', 'rights_apply', false );
		self::register( 'mad4b/data-processing-profile-plan', 'Plan Data Processing Profile', 'profile_plan', true );
		self::register( 'mad4b/data-processing-profile-apply', 'Apply Data Processing Profile', 'profile_apply', false );
		self::register( 'mad4b/data-processing-bound-decision-record', 'Record Registry-bound Data Governance Decision', 'bound_decision_record', false );
		self::register( 'mad4b/rights-takedown-plan', 'Plan Rights Takedown', 'takedown_plan', true );
		self::register( 'mad4b/rights-takedown-apply', 'Apply Rights Takedown', 'takedown_apply', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; registry evidence only, no provider call and no Production authority.',
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

	public static function rights_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = self::uuid( isset( $input['job_id'] ) ? $input['job_id'] : '' );
		$source_id = self::uuid( isset( $input['source_artifact_id'] ) ? $input['source_artifact_id'] : '' );
		if ( '' === $job_id || '' === $source_id ) return new WP_Error( 'mad4b_rights_registry_identity_invalid', 'Exact job and source artifact identities are required.' );

		$source = self::artifact( $source_id, $job_id );
		if ( is_wp_error( $source ) ) return $source;
		$rights_class = strtoupper( sanitize_key( isset( $input['rights_class'] ) ? $input['rights_class'] : '' ) );
		$review_status = sanitize_key( isset( $input['review_status'] ) ? $input['review_status'] : '' );
		if ( ! in_array( $rights_class, array( 'LICENSED', 'OWNED', 'PUBLIC_DOMAIN', 'PERMITTED_REFERENCE_ONLY', 'PROHIBITED' ), true ) ) {
			return new WP_Error( 'mad4b_rights_registry_class_invalid', 'Rights class is invalid.' );
		}
		if ( ! in_array( $review_status, array( 'approved', 'rejected', 'pending' ), true ) ) {
			return new WP_Error( 'mad4b_rights_registry_review_invalid', 'Rights review status is invalid.' );
		}

		$plan = array(
			'contract' => self::RIGHTS_PLAN,
			'job_id' => $job_id,
			'source_artifact_id' => $source_id,
			'source_content_sha256' => isset( $source['content_sha256'] ) ? (string) $source['content_sha256'] : '',
			'rights_class' => $rights_class,
			'review_status' => $review_status,
			'license_id' => self::text( isset( $input['license_id'] ) ? $input['license_id'] : '', 191 ),
			'attribution_required' => ! empty( $input['attribution_required'] ),
			'modification_allowed' => ! empty( $input['modification_allowed'] ),
			'commercial_use_allowed' => ! empty( $input['commercial_use_allowed'] ),
			'policy_revision' => self::text( isset( $input['policy_revision'] ) ? $input['policy_revision'] : '', 64 ),
			'raw_license_document_persisted' => false,
			'raw_source_text_persisted' => false,
			'provider_call_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['record_fingerprint'] = self::digest( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		$plan['plan_sha256'] = self::digest( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function rights_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$check = self::plan_valid( $plan, self::RIGHTS_PLAN );
		if ( is_wp_error( $check ) ) return $check;
		$source = self::artifact( (string) $plan['source_artifact_id'], (string) $plan['job_id'] );
		if ( is_wp_error( $source ) ) return $source;
		if ( isset( $source['content_sha256'] ) && ! hash_equals( (string) $plan['source_content_sha256'], (string) $source['content_sha256'] ) ) {
			return new WP_Error( 'mad4b_rights_registry_source_drift', 'Source artifact changed since RightsRecord planning.' );
		}

		$artifact = self::append(
			(string) $plan['job_id'],
			'rights_record',
			array(
				'source_artifact_id' => (string) $plan['source_artifact_id'],
				'source_content_sha256' => (string) $plan['source_content_sha256'],
				'rights_class' => (string) $plan['rights_class'],
				'review_status' => (string) $plan['review_status'],
				'license_id' => (string) $plan['license_id'],
				'attribution_required' => (bool) $plan['attribution_required'],
				'modification_allowed' => (bool) $plan['modification_allowed'],
				'commercial_use_allowed' => (bool) $plan['commercial_use_allowed'],
				'policy_revision' => (string) $plan['policy_revision'],
				'record_fingerprint' => (string) $plan['record_fingerprint'],
				'payload_minimized' => true,
				'raw_license_document_persisted' => false,
				'raw_source_text_persisted' => false,
			),
			'persist exact rights registry evidence'
		);
		if ( is_wp_error( $artifact ) ) return $artifact;
		$link = MAD4B_SCP_Artifacts::link_artifacts(
			array(
				'from_artifact_id' => (string) $plan['source_artifact_id'],
				'to_artifact_id' => (string) $artifact['artifact_id'],
				'relation' => 'uses',
			)
		);
		if ( is_wp_error( $link ) ) return $link;
		return array(
			'contract' => 'mad4b.rights-record.v1',
			'artifact' => $artifact,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'provider_call_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => true,
		);
	}

	public static function profile_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = self::uuid( isset( $input['job_id'] ) ? $input['job_id'] : '' );
		$provider_id = sanitize_key( isset( $input['provider_id'] ) ? $input['provider_id'] : '' );
		$capability_id = trim( (string) ( isset( $input['capability_id'] ) ? $input['capability_id'] : '' ) );
		if ( '' === $job_id || '' === $provider_id || '' === $capability_id ) {
			return new WP_Error( 'mad4b_processing_profile_identity_invalid', 'Exact job, provider and capability identities are required.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Provider_Execution_Binding' ) ) return new WP_Error( 'mad4b_processing_profile_binding_unavailable', 'Provider execution binding is unavailable.' );

		$binding = MAD4B_SCP_Provider_Execution_Binding::bind(
			array(
				'plan_family' => 'data_processing_profile',
				'provider_id' => $provider_id,
				'capability_id' => $capability_id,
				'required_traits' => isset( $input['required_traits'] ) && is_array( $input['required_traits'] ) ? $input['required_traits'] : array(),
				'release_ring' => isset( $input['release_ring'] ) ? $input['release_ring'] : 'shadow',
				'require_certified' => ! array_key_exists( 'require_certified', $input ) || ! empty( $input['require_certified'] ),
			)
		);
		if ( is_wp_error( $binding ) ) return $binding;

		$profile = array(
			'provider_id' => $provider_id,
			'processor_type' => sanitize_key( isset( $input['processor_type'] ) ? $input['processor_type'] : '' ),
			'allowed_data_classes' => self::keys( isset( $input['allowed_data_classes'] ) ? $input['allowed_data_classes'] : array() ),
			'prohibited_data_classes' => self::keys( isset( $input['prohibited_data_classes'] ) ? $input['prohibited_data_classes'] : array() ),
			'region' => sanitize_key( isset( $input['region'] ) ? $input['region'] : '' ),
			'training_use' => ! empty( $input['training_use'] ),
			'retention_days' => max( 0, (int) ( isset( $input['retention_days'] ) ? $input['retention_days'] : 0 ) ),
			'policy_revision' => self::text( isset( $input['policy_revision'] ) ? $input['policy_revision'] : '', 64 ),
		);
		$plan = array(
			'contract' => self::PROFILE_PLAN,
			'job_id' => $job_id,
			'profile' => $profile,
			'provider_binding' => $binding,
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['profile_fingerprint'] = self::digest( $profile );
		$plan['plan_sha256'] = self::digest( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function profile_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$check = self::plan_valid( $plan, self::PROFILE_PLAN );
		if ( is_wp_error( $check ) ) return $check;
		$binding = MAD4B_SCP_Provider_Execution_Binding::revalidate( array( 'binding' => isset( $plan['provider_binding'] ) ? $plan['provider_binding'] : array() ) );
		if ( is_wp_error( $binding ) ) return $binding;

		$artifact = self::append(
			(string) $plan['job_id'],
			'data_processing_profile',
			array(
				'profile' => isset( $plan['profile'] ) ? $plan['profile'] : array(),
				'profile_fingerprint' => (string) $plan['profile_fingerprint'],
				'provider_binding' => isset( $plan['provider_binding'] ) ? $plan['provider_binding'] : array(),
				'provider_binding_sha256' => isset( $plan['provider_binding']['binding_sha256'] ) ? (string) $plan['provider_binding']['binding_sha256'] : '',
				'payload_minimized' => true,
				'credentials_persisted' => false,
			),
			'persist exact AI data processing profile'
		);
		if ( is_wp_error( $artifact ) ) return $artifact;
		return array(
			'contract' => 'mad4b.data-processing-profile.v1',
			'artifact' => $artifact,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'provider_call_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => true,
		);
	}

	public static function bound_decision_record( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = self::uuid( isset( $input['job_id'] ) ? $input['job_id'] : '' );
		$profile_id = self::uuid( isset( $input['processing_profile_artifact_id'] ) ? $input['processing_profile_artifact_id'] : '' );
		$rights_ids = array();
		foreach ( (array) ( isset( $input['rights_record_artifact_ids'] ) ? $input['rights_record_artifact_ids'] : array() ) as $id ) {
			$id = self::uuid( $id );
			if ( '' !== $id ) $rights_ids[] = $id;
		}
		$rights_ids = array_values( array_unique( $rights_ids ) );
		if ( '' === $job_id || '' === $profile_id || empty( $rights_ids ) ) {
			return new WP_Error( 'mad4b_registry_bound_decision_identity_invalid', 'Registry-bound decision requires exact job, processing-profile and rights-record artifacts.' );
		}

		$profile_artifact = self::artifact( $profile_id, $job_id, 'data_processing_profile' );
		if ( is_wp_error( $profile_artifact ) ) return $profile_artifact;
		$profile_payload = isset( $profile_artifact['payload'] ) && is_array( $profile_artifact['payload'] ) ? $profile_artifact['payload'] : array();
		$destination_profile = isset( $profile_payload['profile'] ) && is_array( $profile_payload['profile'] ) ? $profile_payload['profile'] : array();
		$rights_records = array();
		$source_ids = array( $profile_id );

		foreach ( $rights_ids as $rights_id ) {
			$row = self::artifact( $rights_id, $job_id, 'rights_record' );
			if ( is_wp_error( $row ) ) return $row;
			$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
			$rights_records[] = array(
				'rights_class' => isset( $payload['rights_class'] ) ? (string) $payload['rights_class'] : '',
				'review_status' => isset( $payload['review_status'] ) ? (string) $payload['review_status'] : '',
				'attribution_required' => ! empty( $payload['attribution_required'] ),
				'modification_allowed' => ! empty( $payload['modification_allowed'] ),
				'commercial_use_allowed' => ! empty( $payload['commercial_use_allowed'] ),
				'record_fingerprint' => isset( $payload['record_fingerprint'] ) ? (string) $payload['record_fingerprint'] : '',
			);
			$source_ids[] = $rights_id;
		}
		if ( ! class_exists( 'MAD4B_SCP_Data_Governance' ) ) return new WP_Error( 'mad4b_data_governance_unavailable', 'Data Governance service is unavailable.' );

		$result = MAD4B_SCP_Data_Governance::record_decision(
			array(
				'job_id' => $job_id,
				'purpose' => isset( $input['purpose'] ) ? $input['purpose'] : 'research',
				'data_classes' => isset( $input['data_classes'] ) && is_array( $input['data_classes'] ) ? $input['data_classes'] : array(),
				'rights_records' => $rights_records,
				'destination_profile' => $destination_profile,
				'site_policy' => isset( $input['site_policy'] ) && is_array( $input['site_policy'] ) ? $input['site_policy'] : array(),
				'reason' => isset( $input['reason'] ) ? $input['reason'] : 'registry-bound data governance decision',
				'source_artifact_ids' => array_values( array_unique( $source_ids ) ),
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		$result['registry_bound'] = true;
		$result['rights_record_artifact_ids'] = $rights_ids;
		$result['processing_profile_artifact_id'] = $profile_id;
		$result['registry_binding_sha256'] = self::digest( array( 'rights_record_artifact_ids' => $rights_ids, 'processing_profile_artifact_id' => $profile_id ) );
		return $result;
	}

	public static function takedown_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = self::uuid( isset( $input['job_id'] ) ? $input['job_id'] : '' );
		$rights_id = self::uuid( isset( $input['rights_record_artifact_id'] ) ? $input['rights_record_artifact_id'] : '' );
		$targets = array();
		foreach ( (array) ( isset( $input['target_artifact_ids'] ) ? $input['target_artifact_ids'] : array() ) as $id ) {
			$id = self::uuid( $id );
			if ( '' !== $id ) $targets[] = $id;
		}
		$targets = array_values( array_unique( $targets ) );
		if ( '' === $job_id || '' === $rights_id || empty( $targets ) ) return new WP_Error( 'mad4b_rights_takedown_identity_invalid', 'Exact takedown identities are required.' );
		$rights = self::artifact( $rights_id, $job_id, 'rights_record' );
		if ( is_wp_error( $rights ) ) return $rights;
		foreach ( $targets as $target ) {
			$artifact = self::artifact( $target, $job_id );
			if ( is_wp_error( $artifact ) ) return $artifact;
		}
		$plan = array(
			'contract' => self::TAKEDOWN_PLAN,
			'job_id' => $job_id,
			'rights_record_artifact_id' => $rights_id,
			'target_artifact_ids' => $targets,
			'reason_code' => sanitize_key( isset( $input['reason_code'] ) ? $input['reason_code'] : 'rights_takedown' ),
			'public_content_delete_performed' => false,
			'provider_call_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest( $plan, array( 'plan_sha256', 'mutation_performed' ) );
		return $plan;
	}

	public static function takedown_apply( $input = array() ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$check = self::plan_valid( $plan, self::TAKEDOWN_PLAN );
		if ( is_wp_error( $check ) ) return $check;
		$affected = array();
		foreach ( (array) $plan['target_artifact_ids'] as $target ) {
			$result = MAD4B_SCP_Artifacts::invalidate_artifact( array( 'artifact_id' => $target, 'reason_code' => (string) $plan['reason_code'] ) );
			if ( is_wp_error( $result ) ) return $result;
			$affected[] = $result;
		}
		$notice = self::append(
			(string) $plan['job_id'],
			'rights_takedown',
			array(
				'rights_record_artifact_id' => (string) $plan['rights_record_artifact_id'],
				'target_artifact_ids' => (array) $plan['target_artifact_ids'],
				'reason_code' => (string) $plan['reason_code'],
				'invalidation_count' => count( $affected ),
				'public_content_delete_performed' => false,
			),
			'persist rights takedown invalidation evidence'
		);
		if ( is_wp_error( $notice ) ) return $notice;
		return array(
			'contract' => 'mad4b.rights-takedown.v1',
			'notice_artifact' => $notice,
			'invalidation_results' => $affected,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'provider_call_performed' => false,
			'production_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function append( $job_id, $type, array $payload, $reason ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$result = MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => $job_id,
				'artifact_type' => $type,
				'payload' => $payload,
				'metadata' => array( 'registry_contract' => 'mad4b.data-governance-registry.v1' ),
				'producer_stage' => '',
				'producer_ref' => 'data-governance-registry',
				'reason' => $reason,
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		return isset( $result['artifact'] ) && is_array( $result['artifact'] ) ? $result['artifact'] : new WP_Error( 'mad4b_registry_artifact_invalid', 'Registry artifact append did not return an artifact.' );
	}

	private static function artifact( $artifact_id, $job_id, $type = '' ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
		if ( is_wp_error( $result ) ) return $result;
		$row = isset( $result['artifact'] ) && is_array( $result['artifact'] ) ? $result['artifact'] : array();
		if ( (string) ( isset( $row['job_id'] ) ? $row['job_id'] : '' ) !== $job_id ) return new WP_Error( 'mad4b_registry_artifact_job_mismatch', 'Registry source artifact belongs to another ContentJob.' );
		if ( '' !== $type && (string) ( isset( $row['artifact_type'] ) ? $row['artifact_type'] : '' ) !== $type ) return new WP_Error( 'mad4b_registry_artifact_type_mismatch', 'Registry source artifact type mismatch.' );
		if ( isset( $row['status'] ) && 'active' !== (string) $row['status'] ) return new WP_Error( 'mad4b_registry_artifact_stale', 'Registry source artifact is not active/current.' );
		return $row;
	}

	private static function plan_valid( array $plan, $contract ) {
		if ( $contract !== (string) ( isset( $plan['contract'] ) ? $plan['contract'] : '' ) ) return new WP_Error( 'mad4b_registry_plan_contract_invalid', 'Registry plan contract is invalid.' );
		$sha = strtolower( trim( (string) ( isset( $plan['plan_sha256'] ) ? $plan['plan_sha256'] : '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest( $plan, array( 'plan_sha256', 'mutation_performed' ) ) ) ) {
			return new WP_Error( 'mad4b_registry_plan_digest_invalid', 'Registry plan digest mismatch.' );
		}
		return true;
	}

	public static function uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value ) ? $value : '';
	}

	private static function keys( $value ) {
		$items = is_array( $value ) ? array_map( 'sanitize_key', $value ) : array();
		$items = array_values( array_unique( array_filter( $items ) ) );
		sort( $items, SORT_STRING );
		return $items;
	}

	private static function text( $value, $max ) {
		$value = sanitize_text_field( (string) $value );
		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}

	private static function digest( $value, array $exclude = array() ) {
		if ( is_array( $value ) ) foreach ( $exclude as $key ) unset( $value[ $key ] );
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

MAD4B_SCP_Data_Governance_Registry::boot();
