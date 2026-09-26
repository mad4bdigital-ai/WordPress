<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Normalized research/competitive-intelligence artifact service.
 *
 * This service records already-observed provider evidence. It performs no network
 * access, provider authentication, scraping, or publication mutation.
 */
final class MAD4B_SCP_Research_Intelligence {
	const CONTRACT = 'mad4b.research-intelligence.v1';
	const MAX_RECORDS = 1000;
	const MAX_SOURCE_REFS = 100;
	const MAX_MATRIX_ITEMS = 2000;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/research-keyword-record', 'Record Keyword Research', 'record_keyword' );
		self::register( 'mad4b/research-serp-record', 'Record SERP Snapshot', 'record_serp' );
		self::register( 'mad4b/research-competitor-select', 'Build Competitor Selection', 'select_competitors' );
		self::register( 'mad4b/research-scrape-record', 'Record Scraped Page', 'record_scraped_page' );
		self::register( 'mad4b/research-coverage-build', 'Build Coverage Matrix', 'build_coverage_matrix' );
		self::register( 'mad4b/research-information-gain-build', 'Build Information Gain Plan', 'build_information_gain' );
		self::register_read( 'mad4b/research-provider-plan', 'Plan Governed Research Provider Processing', 'provider_plan' );
	}

	private static function register_read( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . '; requires exact data-governance evidence and never calls the provider.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, $method ),
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

	private static function register( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' as immutable normalized research evidence.',
				'category' => 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
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

	public static function provider_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$provider_id = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		$request_sha = isset( $input['request_sha256'] ) ? strtolower( trim( (string) $input['request_sha256'] ) ) : '';
		$governance_id = isset( $input['data_governance_artifact_id'] ) ? strtolower( trim( (string) $input['data_governance_artifact_id'] ) ) : '';
		$purpose = isset( $input['purpose'] ) ? sanitize_key( (string) $input['purpose'] ) : 'research';
		if ( '' === $provider_id || strlen( $provider_id ) > 64 ) return new WP_Error( 'mad4b_research_provider_invalid', 'Research provider identity is required.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $request_sha ) ) return new WP_Error( 'mad4b_research_request_digest_invalid', 'Research request fingerprint is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Data_Governance' ) || ! method_exists( 'MAD4B_SCP_Data_Governance', 'validate_decision_artifact' ) ) {
			return new WP_Error( 'mad4b_research_data_governance_unavailable', 'Data-governance evidence validator is unavailable.' );
		}
		$governance = MAD4B_SCP_Data_Governance::validate_decision_artifact( $job_id, $governance_id, $provider_id );
		if ( is_wp_error( $governance ) ) return $governance;
		$plan = array(
			'contract' => 'mad4b.research-provider-plan.v1',
			'job_id' => $job_id,
			'provider_id' => $provider_id,
			'purpose' => $purpose,
			'request_sha256' => $request_sha,
			'data_governance_artifact_id' => (string) $governance['artifact_id'],
			'data_governance_decision' => (string) $governance['decision'],
			'data_governance_fingerprint' => (string) $governance['decision_fingerprint'],
			'rights_summary_fingerprint' => (string) $governance['rights_summary_fingerprint'],
			'processor_profile_fingerprint' => (string) $governance['processor_profile_fingerprint'],
			'policy_revision' => (string) $governance['policy_revision'],
			'execution_ready' => true,
			'provider_execution_performed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
		$plan['plan_sha256'] = self::digest( $plan );
		return $plan;
	}

	public static function record_keyword( $input ) {
		$base = self::provider_observation( $input, 'keyword' );
		if ( is_wp_error( $base ) ) return $base;
		$records = isset( $input['records'] ) && is_array( $input['records'] ) ? $input['records'] : array();
		if ( count( $records ) > self::MAX_RECORDS ) return new WP_Error( 'mad4b_research_record_limit', 'Keyword record limit exceeded.' );
		$seed_terms = isset( $input['seed_terms'] ) && is_array( $input['seed_terms'] ) ? array_values( array_map( 'strval', $input['seed_terms'] ) ) : array();
		$payload = array_merge(
			$base,
			array(
				'market' => self::bounded_text( isset( $input['market'] ) ? $input['market'] : '', 64 ),
				'language' => self::bounded_text( isset( $input['language'] ) ? $input['language'] : '', 32 ),
				'seed_terms' => array_slice( $seed_terms, 0, 100 ),
				'normalized_records' => self::canonicalize( $records ),
				'freshness_policy' => self::bounded_text( isset( $input['freshness_policy'] ) ? $input['freshness_policy'] : '', 64 ),
			)
		);
		return self::persist_observation( (string) $input['job_id'], 'keyword_research', 'KEYWORD_RESEARCH', $payload, 'keyword research observation' );
	}

	public static function record_serp( $input ) {
		$base = self::provider_observation( $input, 'serp' );
		if ( is_wp_error( $base ) ) return $base;
		$records = isset( $input['results'] ) && is_array( $input['results'] ) ? $input['results'] : array();
		if ( count( $records ) > 100 ) return new WP_Error( 'mad4b_serp_result_limit', 'SERP result limit exceeded.' );
		$payload = array_merge(
			$base,
			array(
				'query' => self::bounded_text( isset( $input['query'] ) ? $input['query'] : '', 500 ),
				'market' => self::bounded_text( isset( $input['market'] ) ? $input['market'] : '', 64 ),
				'language' => self::bounded_text( isset( $input['language'] ) ? $input['language'] : '', 32 ),
				'device_location_assumptions' => self::canonicalize( isset( $input['device_location_assumptions'] ) && is_array( $input['device_location_assumptions'] ) ? $input['device_location_assumptions'] : array() ),
				'result_records' => self::canonicalize( $records ),
				'features' => self::canonicalize( isset( $input['features'] ) && is_array( $input['features'] ) ? $input['features'] : array() ),
			)
		);
		$payload['snapshot_sha256'] = self::digest( $payload );
		return self::persist_observation( (string) $input['job_id'], 'serp_research', 'SERP_RESEARCH', $payload, 'SERP snapshot observation' );
	}

	public static function select_competitors( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$serp_id = isset( $input['serp_artifact_id'] ) ? strtolower( trim( (string) $input['serp_artifact_id'] ) ) : '';
		$serp = self::artifact_for_job( $serp_id, $job_id, array( 'serp_research' ) );
		if ( is_wp_error( $serp ) ) return $serp;
		if ( 'active' !== (string) $serp['status'] ) return new WP_Error( 'mad4b_research_source_stale', 'SERP source artifact is not active.' );

		$selected = self::bounded_list( isset( $input['selected'] ) ? $input['selected'] : array(), 100 );
		$excluded = self::bounded_list( isset( $input['excluded'] ) ? $input['excluded'] : array(), 100 );
		$rationale = self::bounded_list( isset( $input['rationale_codes'] ) ? $input['rationale_codes'] : array(), 100 );
		if ( is_wp_error( $selected ) || is_wp_error( $excluded ) || is_wp_error( $rationale ) ) return new WP_Error( 'mad4b_competitor_selection_invalid', 'Competitor selection exceeds bounded limits.' );
		$payload = array(
			'serp_snapshot_id' => $serp_id,
			'selected' => self::canonicalize( $selected ),
			'excluded' => self::canonicalize( $excluded ),
			'rationale_codes' => self::canonicalize( $rationale ),
			'operator_overrides' => self::canonicalize( isset( $input['operator_overrides'] ) && is_array( $input['operator_overrides'] ) ? $input['operator_overrides'] : array() ),
		);
		$payload['selection_sha256'] = self::digest( $payload );
		$artifact = self::append( $job_id, 'competitor_set', 'COMPETITOR_SELECTION', $payload, 'competitor selection' );
		if ( is_wp_error( $artifact ) ) return $artifact;
		$link = self::link( $serp_id, $artifact['artifact_id'], 'selected_from' );
		if ( is_wp_error( $link ) ) return $link;
		return self::result( $artifact, array( $serp_id ) );
	}

	public static function record_scraped_page( $input ) {
		$base = self::provider_observation( $input, 'scrape' );
		if ( is_wp_error( $base ) ) return $base;
		$source_url = isset( $input['source_url'] ) ? esc_url_raw( (string) $input['source_url'] ) : '';
		$canonical_url = isset( $input['canonical_url'] ) ? esc_url_raw( (string) $input['canonical_url'] ) : '';
		if ( '' === $source_url || '' === $canonical_url ) return new WP_Error( 'mad4b_scrape_url_invalid', 'Source and canonical URLs are required.' );
		$extracted_sha = isset( $input['extracted_sha256'] ) ? strtolower( trim( (string) $input['extracted_sha256'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $extracted_sha ) ) return new WP_Error( 'mad4b_scrape_digest_invalid', 'Extracted content digest is required.' );
		$payload = array_merge(
			$base,
			array(
				'source_url' => $source_url,
				'canonical_url' => $canonical_url,
				'http_result' => self::canonicalize( isset( $input['http_result'] ) && is_array( $input['http_result'] ) ? $input['http_result'] : array() ),
				'extraction_policy' => self::bounded_text( isset( $input['extraction_policy'] ) ? $input['extraction_policy'] : '', 128 ),
				'extracted_content_location' => self::bounded_text( isset( $input['extracted_content_location'] ) ? $input['extracted_content_location'] : '', 1000 ),
				'extracted_sha256' => $extracted_sha,
				'source_authority_class' => self::bounded_text( isset( $input['source_authority_class'] ) ? $input['source_authority_class'] : 'external_source', 64 ),
				'robots_policy_verdict' => self::bounded_text( isset( $input['robots_policy_verdict'] ) ? $input['robots_policy_verdict'] : '', 64 ),
			)
		);
		if ( ! in_array( $payload['robots_policy_verdict'], array( 'allowed', 'not_applicable', 'operator_approved' ), true ) ) {
			return new WP_Error( 'mad4b_scrape_policy_denied', 'Scrape observation lacks an allowed legal/robots policy verdict.' );
		}
		$artifact = self::persist_observation( (string) $input['job_id'], 'scraped_page', 'SCRAPING', $payload, 'scraped page observation' );
		if ( is_wp_error( $artifact ) ) return $artifact;
		$parent = isset( $input['competitor_selection_artifact_id'] ) ? strtolower( trim( (string) $input['competitor_selection_artifact_id'] ) ) : '';
		if ( '' !== $parent ) {
			$valid = self::artifact_for_job( $parent, (string) $input['job_id'], array( 'competitor_set' ) );
			if ( is_wp_error( $valid ) ) return $valid;
			$child = isset( $artifact['artifact']['artifact_id'] ) ? $artifact['artifact']['artifact_id'] : '';
			$link = self::link( $parent, $child, 'derived_from' );
			if ( is_wp_error( $link ) ) return $link;
		}
		return $artifact;
	}

	public static function build_coverage_matrix( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$types = array(
			'topic' => 'topic_coverage_matrix',
			'question' => 'question_coverage_matrix',
			'entity' => 'entity_coverage_matrix',
			'evidence' => 'evidence_coverage_matrix',
			'ux' => 'ux_coverage_matrix',
		);
		$matrix_type = isset( $input['matrix_type'] ) ? sanitize_key( (string) $input['matrix_type'] ) : '';
		if ( ! isset( $types[ $matrix_type ] ) ) return new WP_Error( 'mad4b_coverage_matrix_type_invalid', 'Coverage matrix type is invalid.' );
		$source_ids = self::artifact_ids( isset( $input['source_artifact_ids'] ) ? $input['source_artifact_ids'] : array(), 100 );
		if ( is_wp_error( $source_ids ) || empty( $source_ids ) ) return new WP_Error( 'mad4b_coverage_sources_required', 'Coverage matrix requires source artifacts.' );
		foreach ( $source_ids as $id ) {
			$source = self::artifact_for_job( $id, $job_id, array( 'serp_research', 'scraped_page', 'competitor_set', 'keyword_research' ) );
			if ( is_wp_error( $source ) ) return $source;
			if ( 'active' !== (string) $source['status'] ) return new WP_Error( 'mad4b_research_source_stale', 'Coverage source artifact is not active.' );
		}
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : array();
		if ( count( $items ) > self::MAX_MATRIX_ITEMS ) return new WP_Error( 'mad4b_coverage_item_limit', 'Coverage matrix item limit exceeded.' );
		$payload = array(
			'matrix_type' => $matrix_type,
			'dimensions' => self::canonicalize( isset( $input['dimensions'] ) && is_array( $input['dimensions'] ) ? $input['dimensions'] : array() ),
			'items' => self::canonicalize( $items ),
			'source_artifact_ids' => $source_ids,
			'analysis_process_version' => self::bounded_text( isset( $input['analysis_process_version'] ) ? $input['analysis_process_version'] : '', 64 ),
		);
		$payload['matrix_sha256'] = self::digest( $payload );
		$artifact = self::append( $job_id, $types[ $matrix_type ], 'COMPETITOR_ANALYSIS', $payload, $matrix_type . ' coverage matrix' );
		if ( is_wp_error( $artifact ) ) return $artifact;
		foreach ( $source_ids as $source_id ) {
			$link = self::link( $source_id, $artifact['artifact_id'], 'summarizes' );
			if ( is_wp_error( $link ) ) return $link;
		}
		return self::result( $artifact, $source_ids );
	}

	public static function build_information_gain( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$source_ids = self::artifact_ids( isset( $input['coverage_artifact_ids'] ) ? $input['coverage_artifact_ids'] : array(), 50 );
		if ( is_wp_error( $source_ids ) || empty( $source_ids ) ) return new WP_Error( 'mad4b_information_gain_sources_required', 'Information Gain Plan requires coverage artifacts.' );
		$allowed = array( 'topic_coverage_matrix', 'question_coverage_matrix', 'entity_coverage_matrix', 'evidence_coverage_matrix', 'ux_coverage_matrix' );
		foreach ( $source_ids as $id ) {
			$source = self::artifact_for_job( $id, $job_id, $allowed );
			if ( is_wp_error( $source ) ) return $source;
			if ( 'active' !== (string) $source['status'] ) return new WP_Error( 'mad4b_research_source_stale', 'Coverage artifact is not active.' );
		}
		$payload = array(
			'baseline_coverage_refs' => $source_ids,
			'differentiation_opportunities' => self::bounded_list( isset( $input['differentiation_opportunities'] ) ? $input['differentiation_opportunities'] : array(), 500 ),
			'missing_questions_entities_topics' => self::bounded_list( isset( $input['missing_questions_entities_topics'] ) ? $input['missing_questions_entities_topics'] : array(), 500 ),
			'first_party_evidence_opportunities' => self::bounded_list( isset( $input['first_party_evidence_opportunities'] ) ? $input['first_party_evidence_opportunities'] : array(), 500 ),
			'unsupported_areas_to_avoid' => self::bounded_list( isset( $input['unsupported_areas_to_avoid'] ) ? $input['unsupported_areas_to_avoid'] : array(), 500 ),
			'recommended_priorities' => self::bounded_list( isset( $input['recommended_priorities'] ) ? $input['recommended_priorities'] : array(), 500 ),
		);
		foreach ( $payload as $row ) if ( is_wp_error( $row ) ) return new WP_Error( 'mad4b_information_gain_limit', 'Information Gain Plan exceeds bounded limits.' );
		$payload['plan_sha256'] = self::digest( $payload );
		$artifact = self::append( $job_id, 'information_gain', 'INFORMATION_GAIN', $payload, 'information gain plan' );
		if ( is_wp_error( $artifact ) ) return $artifact;
		foreach ( $source_ids as $source_id ) {
			$link = self::link( $source_id, $artifact['artifact_id'], 'derived_from' );
			if ( is_wp_error( $link ) ) return $link;
		}
		return self::result( $artifact, $source_ids );
	}

	private static function provider_observation( $input, $kind ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		$request_sha = isset( $input['request_sha256'] ) ? strtolower( trim( (string) $input['request_sha256'] ) ) : '';
		$collected = isset( $input['collected_at'] ) ? trim( (string) $input['collected_at'] ) : '';
		$state = isset( $input['provider_state'] ) ? sanitize_key( (string) $input['provider_state'] ) : 'succeeded';
		if ( '' === $provider || strlen( $provider ) > 64 ) return new WP_Error( 'mad4b_research_provider_invalid', 'Research provider identity is required.' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $request_sha ) ) return new WP_Error( 'mad4b_research_request_digest_invalid', 'Research request fingerprint is required.' );
		if ( '' === $collected || strlen( $collected ) > 64 ) return new WP_Error( 'mad4b_research_collected_at_invalid', 'Research collected_at is required.' );
		if ( ! in_array( $state, array( 'succeeded', 'timeout', 'error', 'rate_limited' ), true ) ) return new WP_Error( 'mad4b_research_provider_state_invalid', 'Research provider state is invalid.' );
		$source_refs = isset( $input['source_refs'] ) && is_array( $input['source_refs'] ) ? $input['source_refs'] : array();
		if ( count( $source_refs ) > self::MAX_SOURCE_REFS ) return new WP_Error( 'mad4b_research_source_ref_limit', 'Research source reference limit exceeded.' );
		return array(
			'job_id' => $job_id,
			'research_kind' => $kind,
			'provider_id' => $provider,
			'provider_state' => $state,
			'collected_at' => $collected,
			'request_sha256' => $request_sha,
			'source_refs' => self::canonicalize( $source_refs ),
			'usage' => self::canonicalize( isset( $input['usage'] ) && is_array( $input['usage'] ) ? $input['usage'] : array() ),
			'error_class' => self::bounded_text( isset( $input['error_class'] ) ? $input['error_class'] : '', 64 ),
			'publishing_authority_created' => false,
		);
	}

	private static function persist_observation( $job_id, $type, $stage, array $payload, $reason ) {
		$existing = MAD4B_SCP_Artifacts::list_artifacts( array( 'job_id' => $job_id, 'artifact_type' => $type ) );
		if ( is_wp_error( $existing ) ) return $existing;
		foreach ( $existing['items'] as $row ) {
			$prior = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
			if ( ! empty( $payload['request_sha256'] ) && isset( $prior['request_sha256'] ) && hash_equals( (string) $prior['request_sha256'], (string) $payload['request_sha256'] ) ) {
				if ( hash_equals( self::digest( $prior ), self::digest( $payload ) ) ) {
					return array( 'contract' => self::CONTRACT, 'artifact' => $row, 'replayed' => true, 'mutation_performed' => false, 'publishing_authority_created' => false );
				}
				return new WP_Error( 'mad4b_research_idempotency_conflict', 'Research request fingerprint was replayed with different normalized evidence.' );
			}
		}
		$artifact = self::append( $job_id, $type, $stage, $payload, $reason );
		if ( is_wp_error( $artifact ) ) return $artifact;
		return self::result( $artifact, array() );
	}

	private static function append( $job_id, $type, $stage, array $payload, $reason ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_research_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$r = MAD4B_SCP_Artifacts::append_artifact( array(
			'job_id' => $job_id,
			'artifact_type' => $type,
			'payload' => $payload,
			'metadata' => array( 'research_contract' => self::CONTRACT ),
			'producer_stage' => $stage,
			'producer_ref' => self::CONTRACT,
			'reason' => $reason,
		) );
		return is_wp_error( $r ) ? $r : $r['artifact'];
	}

	private static function link( $from, $to, $relation ) {
		return MAD4B_SCP_Artifacts::link_artifacts( array(
			'from_artifact_id' => $from,
			'to_artifact_id' => $to,
			'relation' => $relation,
		) );
	}

	private static function artifact_for_job( $id, $job_id, array $types ) {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) return new WP_Error( 'mad4b_research_artifact_id_invalid', 'Artifact ID is invalid.' );
		$r = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $id ) );
		if ( is_wp_error( $r ) ) return $r;
		$row = $r['artifact'];
		if ( ! hash_equals( $job_id, (string) $row['job_id'] ) ) return new WP_Error( 'mad4b_research_cross_job_artifact', 'Research artifact belongs to another ContentJob.' );
		if ( ! in_array( (string) $row['artifact_type'], $types, true ) ) return new WP_Error( 'mad4b_research_artifact_type_invalid', 'Research artifact type is not valid for this operation.' );
		return $row;
	}

	private static function job_id( $input ) {
		$id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		return preg_match( '/^[a-f0-9-]{36}$/', $id ) ? $id : new WP_Error( 'mad4b_research_job_id_invalid', 'ContentJob ID is invalid.' );
	}

	private static function artifact_ids( $value, $max ) {
		if ( ! is_array( $value ) || count( $value ) > $max ) return new WP_Error( 'mad4b_research_artifact_list_invalid', 'Artifact reference list is invalid.' );
		$out = array();
		foreach ( $value as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) return new WP_Error( 'mad4b_research_artifact_id_invalid', 'Artifact ID is invalid.' );
			$out[] = $id;
		}
		return array_values( array_unique( $out ) );
	}

	private static function bounded_list( $value, $max ) {
		if ( ! is_array( $value ) || count( $value ) > $max ) return new WP_Error( 'mad4b_research_list_limit', 'Research list exceeds bounded limit.' );
		return array_values( $value );
	}

	private static function bounded_text( $value, $max ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return strlen( $value ) <= $max ? $value : substr( $value, 0, $max );
	}

	private static function result( array $artifact, array $source_ids ) {
		return array(
			'contract' => self::CONTRACT,
			'artifact' => $artifact,
			'source_artifact_ids' => $source_ids,
			'publishing_authority_created' => false,
			'provider_execution_performed' => false,
			'mutation_performed' => true,
		);
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

MAD4B_SCP_Research_Intelligence::boot();
