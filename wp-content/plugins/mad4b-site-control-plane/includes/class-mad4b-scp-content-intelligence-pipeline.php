<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider-neutral semantic pipeline over the immutable Artifact Registry.
 *
 * This service does not call AI/research providers and does not publish. It
 * validates bounded evidence and appends typed artifacts with explicit lineage.
 */
final class MAD4B_SCP_Content_Intelligence_Pipeline {
	const CONTRACT = 'mad4b.content-intelligence-pipeline.v1';
	const MAX_CONTEXT_SOURCES = 64;
	const MAX_CONTEXT_BYTES = 262144;
	const MAX_RESEARCH_BYTES = 524288;
	const MAX_DRAFT_BYTES = 1048576;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 41 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/blueprint-build', 'Build Content Blueprint', 'build_blueprint' );
		self::register( 'mad4b/blueprint-qa', 'Evaluate Blueprint QA', 'blueprint_qa' );
		self::register( 'mad4b/draft-append', 'Append Article Draft', 'append_draft' );
		self::register( 'mad4b/qa-bundle-append', 'Append QA Bundle', 'append_qa_bundle' );
	}

	private static function register( $name, $label, $method ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the provider-neutral Feature 007 semantic pipeline.',
				'category' => 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ),
					'annotations' => array(
						'readonly' => false,
						'destructive' => false,
						'idempotent' => false,
					),
				),
			)
		);
	}

	public static function build_context_pack( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$sources = isset( $input['sources'] ) && is_array( $input['sources'] ) ? $input['sources'] : array();
		$provisional_allowed = ! empty( $input['provisional_allowed'] );
		if ( empty( $sources ) ) return new WP_Error( 'mad4b_context_sources_required', 'ContextPack requires at least one bounded source.' );
		if ( count( $sources ) > self::MAX_CONTEXT_SOURCES ) return new WP_Error( 'mad4b_context_source_limit', 'ContextPack source count exceeds the bounded limit.' );

		$eligible = array();
		$total_bytes = 0;
		foreach ( $sources as $row ) {
			if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_context_source_invalid', 'Context source must be an object.' );
			$source_id = self::bounded_string( $row, 'source_id', 191 );
			$asset_id = self::bounded_string( $row, 'asset_id', 191 );
			$version = self::bounded_string( $row, 'version', 64 );
			$fingerprint = strtolower( self::bounded_string( $row, 'fingerprint', 64 ) );
			$authority_state = sanitize_key( self::bounded_string( $row, 'authority_state', 64 ) );
			$review_state = sanitize_key( self::bounded_string( $row, 'review_state', 64 ) );
			$bytes = isset( $row['bytes'] ) ? max( 0, (int) $row['bytes'] ) : 0;
			if ( '' === $source_id || '' === $asset_id || '' === $version || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
				return new WP_Error( 'mad4b_context_source_identity_invalid', 'Context source identity/fingerprint is incomplete.' );
			}
			$approved = in_array( $authority_state, array( 'authoritative', 'approved' ), true )
				&& in_array( $review_state, array( 'reviewed', 'approved' ), true );
			if ( ! $approved && ! $provisional_allowed ) {
				return new WP_Error( 'mad4b_context_source_ineligible', 'Context source is not eligible under the current review/authority policy.' );
			}
			$total_bytes += $bytes;
			if ( $total_bytes > self::MAX_CONTEXT_BYTES ) return new WP_Error( 'mad4b_context_size_limit', 'ContextPack exceeds the bounded byte budget.' );
			$eligible[] = array(
				'source_id' => $source_id,
				'asset_id' => $asset_id,
				'version' => $version,
				'authority_state' => $authority_state,
				'review_state' => $review_state,
				'fingerprint' => $fingerprint,
				'bytes' => $bytes,
				'provisional' => ! $approved,
			);
		}
		usort( $eligible, static function ( $a, $b ) {
			return strcmp( $a['source_id'] . "\0" . $a['asset_id'] . "\0" . $a['version'], $b['source_id'] . "\0" . $b['asset_id'] . "\0" . $b['version'] );
		} );
		$payload = array(
			'contract' => 'mad4b.context-pack.v1',
			'sources' => $eligible,
			'source_count' => count( $eligible ),
			'total_declared_bytes' => $total_bytes,
			'provisional_allowed' => $provisional_allowed,
			'bounded' => true,
		);
		return self::append( $job_id, 'context_pack', 'KNOWLEDGE_DISPATCH', $payload, array(), 'build bounded ContextPack' );
	}

	public static function append_research( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$type = isset( $input['research_type'] ) ? sanitize_key( (string) $input['research_type'] ) : '';
		if ( ! in_array( $type, array( 'keyword_research', 'serp_research' ), true ) ) {
			return new WP_Error( 'mad4b_research_type_invalid', 'Research artifact type is not supported by this normalized contract.' );
		}
		$provider = self::bounded_string( $input, 'provider_id', 191 );
		$collected = self::bounded_string( $input, 'collected_at', 64 );
		$request_fingerprint = strtolower( self::bounded_string( $input, 'request_fingerprint', 64 ) );
		$source_refs = isset( $input['source_refs'] ) && is_array( $input['source_refs'] ) ? array_values( $input['source_refs'] ) : array();
		$data = isset( $input['normalized_data'] ) && is_array( $input['normalized_data'] ) ? $input['normalized_data'] : null;
		if ( '' === $provider || '' === $collected || 1 !== preg_match( '/^[a-f0-9]{64}$/', $request_fingerprint ) || null === $data ) {
			return new WP_Error( 'mad4b_research_identity_invalid', 'Research response identity/provenance is incomplete.' );
		}
		$payload = array(
			'contract' => 'mad4b.research-artifact.v1',
			'provider_id' => $provider,
			'collected_at' => $collected,
			'request_fingerprint' => $request_fingerprint,
			'source_refs' => $source_refs,
			'normalized_data' => $data,
			'usage' => isset( $input['usage'] ) && is_array( $input['usage'] ) ? $input['usage'] : array(),
			'cost' => isset( $input['cost'] ) && is_array( $input['cost'] ) ? $input['cost'] : array(),
			'freshness' => isset( $input['freshness'] ) ? sanitize_key( (string) $input['freshness'] ) : 'unknown',
		);
		$json = wp_json_encode( $payload );
		if ( false === $json || strlen( $json ) > self::MAX_RESEARCH_BYTES ) return new WP_Error( 'mad4b_research_size_limit', 'Research artifact exceeds bounded payload budget.' );
		$stage = 'keyword_research' === $type ? 'KEYWORD_RESEARCH' : 'SERP_RESEARCH';
		return self::append( $job_id, $type, $stage, $payload, array(), 'append normalized research evidence' );
	}

	public static function build_blueprint( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$writer = self::job_writer_profile( $job_id );
		if ( is_wp_error( $writer ) ) return $writer;
		$context_id = self::artifact_id( $input, 'context_artifact_id' );
		if ( is_wp_error( $context_id ) ) return $context_id;
		$context = self::active_artifact( $context_id, $job_id, 'context_pack' );
		if ( is_wp_error( $context ) ) return $context;
		if ( ! self::context_writer_matches( $context, $writer ) ) {
			return new WP_Error( 'mad4b_writer_profile_context_mismatch', 'ContextPack WriterProfile binding does not match the ContentJob.' );
		}
		$research_ids = isset( $input['research_artifact_ids'] ) && is_array( $input['research_artifact_ids'] ) ? array_values( $input['research_artifact_ids'] ) : array();
		if ( empty( $research_ids ) ) return new WP_Error( 'mad4b_can_plan_research_missing', 'CAN_PLAN requires at least one active research artifact.' );
		$research = array();
		foreach ( $research_ids as $id ) {
			$id = strtolower( trim( (string) $id ) );
			$row = self::active_artifact( $id, $job_id, array( 'keyword_research', 'serp_research', 'competitor_analysis', 'information_gain' ) );
			if ( is_wp_error( $row ) ) return $row;
			$research[] = $row;
		}
		$required = array( 'search_intent', 'audience', 'goals', 'outline', 'section_objectives', 'evidence_requirements' );
		foreach ( $required as $key ) {
			if ( ! isset( $input[ $key ] ) || ( is_string( $input[ $key ] ) && '' === trim( $input[ $key ] ) ) || ( is_array( $input[ $key ] ) && empty( $input[ $key ] ) ) ) {
				return new WP_Error( 'mad4b_blueprint_field_required', 'Blueprint field is required: ' . $key );
			}
		}
		$payload = array(
			'contract' => 'mad4b.content-blueprint.v1',
			'search_intent' => $input['search_intent'],
			'audience' => $input['audience'],
			'goals' => $input['goals'],
			'outline' => $input['outline'],
			'section_objectives' => $input['section_objectives'],
			'evidence_requirements' => $input['evidence_requirements'],
			'internal_linking_intent' => $input['internal_linking_intent'] ?? array(),
			'cta_intent' => $input['cta_intent'] ?? array(),
			'structured_data_intent' => $input['structured_data_intent'] ?? array(),
			'media_needs' => $input['media_needs'] ?? array(),
			'context_artifact_id' => $context_id,
			'research_artifact_ids' => $research_ids,
			'can_plan' => true,
		);
		$result = self::append( $job_id, 'blueprint', 'BLUEPRINT', $payload, array(), 'build ContentBlueprint from eligible evidence' );
		if ( is_wp_error( $result ) ) return $result;
		$blueprint_id = (string) $result['artifact']['artifact_id'];
		$link = self::link_many( array_merge( array( $context_id ), $research_ids ), $blueprint_id );
		return is_wp_error( $link ) ? $link : $result;
	}

	public static function blueprint_qa( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$blueprint_id = self::artifact_id( $input, 'blueprint_artifact_id' );
		if ( is_wp_error( $blueprint_id ) ) return $blueprint_id;
		$blueprint = self::active_artifact( $blueprint_id, $job_id, 'blueprint' );
		if ( is_wp_error( $blueprint ) ) return $blueprint;
		$hard = isset( $input['hard_blockers'] ) && is_array( $input['hard_blockers'] ) ? array_values( $input['hard_blockers'] ) : array();
		$warnings = isset( $input['warnings'] ) && is_array( $input['warnings'] ) ? array_values( $input['warnings'] ) : array();
		$payload = array(
			'contract' => 'mad4b.blueprint-qa.v1',
			'blueprint_artifact_id' => $blueprint_id,
			'hard_blockers' => $hard,
			'warnings' => $warnings,
			'pass' => empty( $hard ),
			'can_write' => empty( $hard ),
		);
		$result = self::append( $job_id, 'blueprint_qa', 'BLUEPRINT_QA', $payload, array(), 'evaluate Blueprint QA hard blockers' );
		if ( is_wp_error( $result ) ) return $result;
		$link = self::link_many( array( $blueprint_id ), (string) $result['artifact']['artifact_id'], 'qa_of' );
		return is_wp_error( $link ) ? $link : $result;
	}

	public static function append_draft( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$writer = self::job_writer_profile( $job_id );
		if ( is_wp_error( $writer ) ) return $writer;
		$blueprint_id = self::artifact_id( $input, 'blueprint_artifact_id' );
		if ( is_wp_error( $blueprint_id ) ) return $blueprint_id;
		$qa_id = self::artifact_id( $input, 'blueprint_qa_artifact_id' );
		if ( is_wp_error( $qa_id ) ) return $qa_id;
		$context_id = self::artifact_id( $input, 'context_artifact_id' );
		if ( is_wp_error( $context_id ) ) return $context_id;
		$blueprint = self::active_artifact( $blueprint_id, $job_id, 'blueprint' );
		if ( is_wp_error( $blueprint ) ) return $blueprint;
		$context = self::active_artifact( $context_id, $job_id, 'context_pack' );
		if ( is_wp_error( $context ) ) return $context;
		$qa = self::active_artifact( $qa_id, $job_id, 'blueprint_qa' );
		if ( is_wp_error( $qa ) ) return $qa;
		if ( empty( $qa['payload']['can_write'] ) || empty( $qa['payload']['pass'] ) ) return new WP_Error( 'mad4b_can_write_blocked', 'CAN_WRITE is blocked by Blueprint QA.' );
		if ( ! isset( $qa['payload']['blueprint_artifact_id'] ) || ! hash_equals( $blueprint_id, (string) $qa['payload']['blueprint_artifact_id'] ) ) {
			return new WP_Error( 'mad4b_blueprint_qa_lineage_mismatch', 'Blueprint QA does not certify the selected Blueprint.' );
		}
		if ( ! isset( $blueprint['payload']['context_artifact_id'] ) || ! hash_equals( $context_id, (string) $blueprint['payload']['context_artifact_id'] ) ) {
			return new WP_Error( 'mad4b_blueprint_context_lineage_mismatch', 'Selected ContextPack does not match the Blueprint lineage.' );
		}
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		if ( '' === trim( $content ) ) return new WP_Error( 'mad4b_draft_content_required', 'ArticleDraft content is required.' );
		if ( strlen( $content ) > self::MAX_DRAFT_BYTES ) return new WP_Error( 'mad4b_draft_size_limit', 'ArticleDraft exceeds bounded payload budget.' );
		$payload = array(
			'contract' => 'mad4b.article-draft.v1',
			'blueprint_artifact_id' => $blueprint_id,
			'blueprint_qa_artifact_id' => $qa_id,
			'context_artifact_id' => $context_id,
			'writer_profile_id' => $writer['writer_profile_id'],
			'writer_profile_version' => $writer['writer_profile_version'],
			'writer_profile_fingerprint' => $writer['writer_profile_fingerprint'],
			'content' => $content,
			'section_count' => isset( $input['section_count'] ) ? max( 0, (int) $input['section_count'] ) : 0,
			'can_write' => true,
		);
		$result = self::append( $job_id, 'draft', 'WRITING', $payload, array(), 'append ArticleDraft from approved blueprint' );
		if ( is_wp_error( $result ) ) return $result;
		$link = self::link_many( array( $blueprint_id, $qa_id, $context_id ), (string) $result['artifact']['artifact_id'] );
		return is_wp_error( $link ) ? $link : $result;
	}

	public static function append_qa_bundle( $input ) {
		$job_id = self::job_id( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$draft_id = self::artifact_id( $input, 'draft_artifact_id' );
		if ( is_wp_error( $draft_id ) ) return $draft_id;
		$draft = self::active_artifact( $draft_id, $job_id, 'draft' );
		if ( is_wp_error( $draft ) ) return $draft;
		$writer = self::job_writer_profile( $job_id );
		if ( is_wp_error( $writer ) ) return $writer;
		if ( ! self::draft_writer_matches( $draft, $writer ) ) {
			return new WP_Error( 'mad4b_writer_profile_draft_mismatch', 'ArticleDraft WriterProfile binding does not match the ContentJob.' );
		}
		$definitions = array(
			'fact_ledger' => array( 'stage' => 'FACT_QA', 'contract' => 'mad4b.fact-ledger.v1', 'input' => 'fact_ledger' ),
			'editorial_qa' => array( 'stage' => 'EDITORIAL_QA', 'contract' => 'mad4b.editorial-qa.v1', 'input' => 'editorial_qa' ),
			'seo_qa' => array( 'stage' => 'SEO', 'contract' => 'mad4b.seo-qa.v1', 'input' => 'seo_qa' ),
		);
		$created = array();
		$hard_blockers = array();
		foreach ( $definitions as $type => $def ) {
			$row = isset( $input[ $def['input'] ] ) && is_array( $input[ $def['input'] ] ) ? $input[ $def['input'] ] : null;
			if ( null === $row ) return new WP_Error( 'mad4b_qa_component_required', 'QA component is required: ' . $def['input'] );
			$component_hard = isset( $row['hard_blockers'] ) && is_array( $row['hard_blockers'] ) ? array_values( $row['hard_blockers'] ) : array();
			foreach ( $component_hard as $blocker ) $hard_blockers[] = $type . ':' . (string) $blocker;
			$payload = array_merge( $row, array(
				'contract' => $def['contract'],
				'draft_artifact_id' => $draft_id,
				'writer_profile_id' => $writer['writer_profile_id'],
				'writer_profile_version' => $writer['writer_profile_version'],
				'writer_profile_fingerprint' => $writer['writer_profile_fingerprint'],
				'hard_blockers' => $component_hard,
				'pass' => empty( $component_hard ),
			) );
			$result = self::append( $job_id, $type, $def['stage'], $payload, array(), 'append typed QA evidence' );
			if ( is_wp_error( $result ) ) return $result;
			$id = (string) $result['artifact']['artifact_id'];
			$link = self::link_many( array( $draft_id ), $id, 'qa_of' );
			if ( is_wp_error( $link ) ) return $link;
			$created[ $type ] = $id;
		}
		$final = array(
			'contract' => 'mad4b.final-qa.v1',
			'draft_artifact_id' => $draft_id,
			'writer_profile_id' => $writer['writer_profile_id'],
			'writer_profile_version' => $writer['writer_profile_version'],
			'writer_profile_fingerprint' => $writer['writer_profile_fingerprint'],
			'component_artifact_ids' => $created,
			'hard_blockers' => $hard_blockers,
			'pass' => empty( $hard_blockers ),
			'can_publish' => false,
			'publication_authorized' => false,
		);
		$result = self::append( $job_id, 'final_qa', 'FINAL_QA', $final, array(), 'combine QA hard blockers without averaging' );
		if ( is_wp_error( $result ) ) return $result;
		$final_id = (string) $result['artifact']['artifact_id'];
		$link = self::link_many( array_values( $created ), $final_id, 'uses' );
		if ( is_wp_error( $link ) ) return $link;
		return array(
			'contract' => self::CONTRACT,
			'qa_artifact_ids' => $created,
			'final_qa_artifact_id' => $final_id,
			'pass' => empty( $hard_blockers ),
			'can_publish' => false,
			'publication_authorized' => false,
			'mutation_performed' => true,
		);
	}

	private static function job_writer_profile( $job_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Context_Pack' ) || ! method_exists( 'MAD4B_SCP_Context_Pack', 'writer_profile_binding_for_job_id' ) ) {
			return new WP_Error( 'mad4b_writer_profile_resolver_unavailable', 'WriterProfile requires the authoritative ContextPack resolver.' );
		}
		$writer = MAD4B_SCP_Context_Pack::writer_profile_binding_for_job_id( $job_id );
		if ( is_wp_error( $writer ) ) return $writer;
		if ( empty( $writer['writer_profile_id'] ) || empty( $writer['writer_profile_version'] ) || empty( $writer['writer_profile_fingerprint'] ) ) {
			return new WP_Error( 'mad4b_writer_profile_required', 'ContentJob must bind an immutable WriterProfile identity, approved content hash version and fingerprint.' );
		}
		return $writer;
	}

	private static function context_writer_matches( array $context, array $writer ) {
		$payload = isset( $context['payload'] ) && is_array( $context['payload'] ) ? $context['payload'] : array();
		return isset( $payload['writer_profile_id'], $payload['writer_profile_version'], $payload['writer_profile_fingerprint'] )
			&& hash_equals( $writer['writer_profile_id'], (string) $payload['writer_profile_id'] )
			&& hash_equals( $writer['writer_profile_version'], (string) $payload['writer_profile_version'] )
			&& hash_equals( $writer['writer_profile_fingerprint'], (string) $payload['writer_profile_fingerprint'] );
	}

	private static function draft_writer_matches( array $draft, array $writer ) {
		$payload = isset( $draft['payload'] ) && is_array( $draft['payload'] ) ? $draft['payload'] : array();
		return isset( $payload['writer_profile_id'], $payload['writer_profile_version'], $payload['writer_profile_fingerprint'] )
			&& hash_equals( $writer['writer_profile_id'], (string) $payload['writer_profile_id'] )
			&& hash_equals( $writer['writer_profile_version'], (string) $payload['writer_profile_version'] )
			&& hash_equals( $writer['writer_profile_fingerprint'], (string) $payload['writer_profile_fingerprint'] );
	}

	private static function digest_value( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function append( $job_id, $type, $stage, array $payload, array $metadata, $reason ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		return MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => $job_id,
				'artifact_type' => $type,
				'payload' => $payload,
				'metadata' => $metadata,
				'producer_stage' => $stage,
				'producer_ref' => 'mad4b-content-intelligence-pipeline',
				'reason' => $reason,
			)
		);
	}

	private static function active_artifact( $artifact_id, $job_id, $types ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
		if ( is_wp_error( $result ) ) return $result;
		$row = isset( $result['artifact'] ) && is_array( $result['artifact'] ) ? $result['artifact'] : array();
		$allowed = is_array( $types ) ? $types : array( $types );
		if ( (string) ( $row['job_id'] ?? '' ) !== $job_id ) return new WP_Error( 'mad4b_artifact_cross_job', 'Artifact belongs to a different ContentJob.' );
		if ( ! in_array( (string) ( $row['artifact_type'] ?? '' ), $allowed, true ) ) return new WP_Error( 'mad4b_artifact_type_mismatch', 'Artifact type does not satisfy this gate.' );
		if ( 'active' !== (string) ( $row['status'] ?? '' ) ) return new WP_Error( 'mad4b_artifact_stale', 'Artifact is not active/current.' );
		return $row;
	}

	private static function link_many( array $from_ids, $to_id, $relation = 'uses' ) {
		foreach ( array_values( array_unique( $from_ids ) ) as $from ) {
			$result = MAD4B_SCP_Artifacts::link_artifacts(
				array(
					'from_artifact_id' => $from,
					'to_artifact_id' => $to_id,
					'relation' => $relation,
				)
			);
			if ( is_wp_error( $result ) ) return $result;
		}
		return true;
	}

	private static function job_id( $input ) {
		$id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $id )
			? $id
			: new WP_Error( 'mad4b_pipeline_job_id_invalid', 'ContentJob ID is invalid.' );
	}

	private static function artifact_id( $input, $key ) {
		$id = isset( $input[ $key ] ) ? strtolower( trim( (string) $input[ $key ] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $id )
			? $id
			: new WP_Error( 'mad4b_pipeline_artifact_id_invalid', 'Artifact ID is invalid: ' . $key );
	}

	private static function bounded_string( array $input, $key, $max ) {
		$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		return strlen( $value ) > $max ? '' : $value;
	}
}

MAD4B_SCP_Content_Intelligence_Pipeline::boot();
