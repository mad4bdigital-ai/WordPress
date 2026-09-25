<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic Knowledge Dispatcher + bounded ContextPack builder.
 *
 * Reads only the governed Context Authority registry. It never browses Drive or
 * another provider directly. Output is persisted as an immutable context_pack
 * artifact through MAD4B_SCP_Artifacts.
 */
final class MAD4B_SCP_Context_Pack {
	const CONTRACT = 'mad4b.context-pack.v1';
	const REQUIREMENTS_CONTRACT = 'mad4b.job-knowledge-requirements.v1';
	const DISPATCHER_VERSION = '1';
	const MAX_SOURCE_ASSETS = 64;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function knowledge_classes() {
		return array(
			'brand.core',
			'brand.positioning',
			'audience.primary',
			'voice.language',
			'seo.strategy',
			'product.knowledge',
			'service.knowledge',
			'destination.knowledge',
			'destination.blueprint',
			'pricing',
			'evidence',
			'legal.compliance',
			'writer.profile',
		);
	}

	private static function category_map() {
		return array(
			'brand_strategy' => array( 'brand.core' ),
			'messaging' => array( 'brand.core' ),
			'brand_positioning' => array( 'brand.positioning' ),
			'audience_persona' => array( 'audience.primary' ),
			'tone_of_voice' => array( 'voice.language' ),
			'editorial_guidelines' => array( 'voice.language' ),
			'terminology' => array( 'voice.language' ),
			'seo_strategy' => array( 'seo.strategy' ),
			'content_strategy' => array( 'seo.strategy', 'destination.blueprint' ),
			'product_knowledge' => array( 'product.knowledge' ),
			'service_knowledge' => array( 'service.knowledge' ),
			'destination_knowledge' => array( 'destination.knowledge' ),
			'market_research' => array( 'evidence' ),
			'content_example' => array( 'evidence', 'writer.profile' ),
			'historical_content' => array( 'evidence', 'writer.profile' ),
			'writer_reference' => array( 'writer.profile' ),
			'claim_policy' => array( 'legal.compliance' ),
			'legal_policy' => array( 'legal.compliance' ),
			'operational_policy' => array( 'legal.compliance' ),
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/context-requirements-resolve', 'Resolve Job Knowledge Requirements', 'resolve_requirements', true );
		self::register( 'mad4b/context-pack-preview', 'Preview ContextPack', 'preview', true );
		self::register( 'mad4b/context-pack-build', 'Build ContextPack', 'build', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' using the governed Context Authority registry.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'job_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
						'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
					),
					'required' => $readonly ? array( 'job_id' ) : array( 'job_id', 'reason' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $readonly ? 'read' : 'write' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => false,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	public static function resolve_requirements( $input ) {
		$job = self::job( isset( $input['job_id'] ) ? $input['job_id'] : '' );
		if ( is_wp_error( $job ) ) return $job;
		$writer = self::writer_profile_binding( $job );
		if ( is_wp_error( $writer ) ) return $writer;
		$required = array( 'brand.core', 'audience.primary', 'voice.language' );
		$conditional = array( 'brand.positioning', 'evidence', 'legal.compliance', 'pricing' );

		$content_type = isset( $job['content_type'] ) ? sanitize_key( (string) $job['content_type'] ) : '';
		switch ( $content_type ) {
			case 'product':
				$required[] = 'product.knowledge';
				$required[] = 'seo.strategy';
				break;
			case 'destination':
			case 'guide':
				$required[] = 'destination.knowledge';
				$required[] = 'seo.strategy';
				$conditional[] = 'destination.blueprint';
				break;
			case 'landing_page':
			case 'category':
				$required[] = 'seo.strategy';
				break;
			case 'article':
			case 'update':
				$conditional[] = 'seo.strategy';
				break;
			default:
				$conditional[] = 'seo.strategy';
				$conditional[] = 'product.knowledge';
				$conditional[] = 'service.knowledge';
				$conditional[] = 'destination.knowledge';
				break;
		}
		if ( ! empty( $job['writer_profile_id'] ) ) $required[] = 'writer.profile';
		else $conditional[] = 'writer.profile';

		$required = array_values( array_unique( array_intersect( self::knowledge_classes(), $required ) ) );
		$conditional = array_values( array_diff( array_unique( array_intersect( self::knowledge_classes(), $conditional ) ), $required ) );
		sort( $required, SORT_STRING );
		sort( $conditional, SORT_STRING );

		$material = array(
			'contract' => self::REQUIREMENTS_CONTRACT,
			'job_id' => (string) $job['job_id'],
			'brand_id' => (string) $job['brand_id'],
			'language' => (string) $job['language'],
			'country' => (string) $job['country'],
			'content_type' => (string) $job['content_type'],
			'writer_profile_id' => $writer['writer_profile_id'],
			'writer_profile_version' => $writer['writer_profile_version'],
			'writer_profile_fingerprint' => $writer['writer_profile_fingerprint'],
			'required_classes' => $required,
			'conditional_classes' => $conditional,
			'resolver_version' => '1',
		);
		$material['job_requirements_sha256'] = self::digest( $material );
		$material['mutation_performed'] = false;
		return $material;
	}

	public static function preview( $input ) {
		$requirements = self::resolve_requirements( $input );
		if ( is_wp_error( $requirements ) ) return $requirements;
		$job = self::job( $requirements['job_id'] );
		if ( is_wp_error( $job ) ) return $job;
		return self::assemble( $job, $requirements );
	}

	public static function build( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_context_pack_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		$preview = self::preview( $input );
		if ( is_wp_error( $preview ) ) return $preview;
		if ( ! empty( $preview['missing_required_classes'] ) ) {
			return new WP_Error(
				'mad4b_context_pack_required_knowledge_missing',
				'ContextPack cannot be built because required knowledge classes are missing.',
				array(
					'missing_required_classes' => $preview['missing_required_classes'],
					'job_requirements_sha256' => $preview['job_requirements_sha256'],
				)
			);
		}
		$reason = isset( $input['reason'] ) ? trim( sanitize_text_field( (string) $input['reason'] ) ) : '';
		if ( strlen( $reason ) < 3 ) return new WP_Error( 'mad4b_context_pack_reason_required', 'ContextPack build reason is required.' );

		$payload = $preview;
		unset( $payload['contract'], $payload['ready'], $payload['mutation_performed'], $payload['blockers'] );
		$result = MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => (string) $preview['job_id'],
				'artifact_type' => 'context_pack',
				'payload' => $payload,
				'metadata' => array(
					'context_pack_sha256' => (string) $preview['context_pack_sha256'],
					'authority_manifest_fingerprint' => (string) $preview['authority_manifest_fingerprint'],
					'registry_revision' => (int) $preview['registry_revision'],
				),
				'producer_stage' => 'KNOWLEDGE_DISPATCH',
				'producer_ref' => self::CONTRACT,
				'reason' => $reason,
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		return array(
			'contract' => self::CONTRACT,
			'ready' => true,
			'job_id' => (string) $preview['job_id'],
			'context_pack_sha256' => (string) $preview['context_pack_sha256'],
			'artifact' => $result['artifact'],
			'mutation_performed' => true,
		);
	}

	private static function assemble( array $job, array $requirements ) {
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_context_authority_unavailable', 'Context Authority is unavailable.' );
		$profile = MAD4B_SCP_Context_Authority::profile();
		if ( ! is_array( $profile ) || empty( $profile ) ) return new WP_Error( 'mad4b_context_profile_required', 'Brand Context Profile is required.' );
		if ( ! isset( $profile['brand_id'] ) || ! hash_equals( (string) $job['brand_id'], (string) $profile['brand_id'] ) ) {
			return new WP_Error( 'mad4b_context_pack_brand_mismatch', 'ContentJob brand does not match the site-bound Context profile.' );
		}
		$assets = MAD4B_SCP_Context_Authority::assets();
		$assets = is_array( $assets ) ? $assets : array();
		$category_map = self::category_map();
		$source_assets = array();
		$covered = array();
		$blockers = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$category = isset( $asset['category'] ) ? sanitize_key( (string) $asset['category'] ) : '';
			if ( ! isset( $category_map[ $category ] ) ) continue;
			$status = isset( $asset['status'] ) ? (string) $asset['status'] : '';
			$review_status = isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '';
			$content_complete = ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] );
			$content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
			$reviewed_hash = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
			if ( 'ready' !== $status || ! $content_complete || 'approved' !== $review_status ) continue;
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $content_hash ) || ! hash_equals( $content_hash, $reviewed_hash ) ) continue;

			$asset_language = isset( $asset['language'] ) ? strtolower( trim( (string) $asset['language'] ) ) : '';
			$job_language = strtolower( trim( (string) $job['language'] ) );
			if ( '' !== $asset_language && ! hash_equals( $asset_language, $job_language ) ) continue;

			$source_mode = isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '';
			$task_scope = isset( $asset['task_scope'] ) ? (string) $asset['task_scope'] : '';
			if ( 'task_attachment' === $source_mode && '' !== $task_scope && ! hash_equals( $task_scope, (string) $job['job_id'] ) ) continue;

			foreach ( $category_map[ $category ] as $knowledge_class ) {
				if ( ! in_array( $knowledge_class, $requirements['required_classes'], true )
					&& ! in_array( $knowledge_class, $requirements['conditional_classes'], true ) ) continue;
				$row = array(
					'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
					'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
					'asset_version' => 'sha256:' . $content_hash,
					'knowledge_class' => $knowledge_class,
					'review_state' => $review_status,
					'authority' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
					'fingerprint' => $content_hash,
					'content_location' => isset( $asset['path'] ) ? (string) $asset['path'] : '',
					'excerpt' => isset( $asset['content_excerpt'] ) ? substr( (string) $asset['content_excerpt'], 0, 1000 ) : '',
					'language_match' => '' === $asset_language ? 'language_unspecified' : 'exact',
				);
				$key = $knowledge_class . '|' . $row['asset_id'];
				$source_assets[ $key ] = $row;
				$covered[ $knowledge_class ] = true;
			}
			if ( count( $source_assets ) >= self::MAX_SOURCE_ASSETS ) {
				$blockers[] = 'context_pack_source_asset_limit_reached';
				break;
			}
		}

		ksort( $source_assets, SORT_STRING );
		$source_assets = array_values( array_slice( $source_assets, 0, self::MAX_SOURCE_ASSETS, true ) );
		$missing = array();
		foreach ( $requirements['required_classes'] as $class ) if ( empty( $covered[ $class ] ) ) $missing[] = $class;
		sort( $missing, SORT_STRING );

		$payload = array(
			'contract' => self::CONTRACT,
			'job_id' => (string) $job['job_id'],
			'job_requirements_sha256' => (string) $requirements['job_requirements_sha256'],
			'source_assets' => $source_assets,
			'required_classes' => $requirements['required_classes'],
			'missing_required_classes' => $missing,
			'conditional_classes' => $requirements['conditional_classes'],
			'dispatcher_version' => self::DISPATCHER_VERSION,
			'generated_at' => gmdate( 'c' ),
			'authority_manifest_fingerprint' => method_exists( 'MAD4B_SCP_Context_Authority', 'authority_manifest_fingerprint' )
				? MAD4B_SCP_Context_Authority::authority_manifest_fingerprint()
				: '',
			'registry_revision' => method_exists( 'MAD4B_SCP_Context_Authority', 'registry_revision' )
				? (int) MAD4B_SCP_Context_Authority::registry_revision()
				: 0,
			'brand_id' => (string) $job['brand_id'],
			'language' => (string) $job['language'],
			'country' => (string) $job['country'],
			'writer_profile_id' => (string) $requirements['writer_profile_id'],
			'writer_profile_version' => (string) $requirements['writer_profile_version'],
			'writer_profile_fingerprint' => (string) $requirements['writer_profile_fingerprint'],
			'site_brand_match' => true,
			'provisional_context_allowed' => false,
		);
		$hash_material = $payload;
		unset( $hash_material['generated_at'] );
		$payload['context_pack_sha256'] = self::digest( $hash_material );
		$payload['ready'] = empty( $missing ) && empty( $blockers );
		$payload['blockers'] = array_values( array_unique( array_merge(
			$blockers,
			empty( $missing ) ? array() : array( 'required_knowledge_missing' )
		) ) );
		$payload['mutation_performed'] = false;
		return $payload;
	}

	private static function writer_profile_binding( array $job ) {
		$id = isset( $job['writer_profile_id'] ) ? trim( (string) $job['writer_profile_id'] ) : '';
		$version = isset( $job['writer_profile_version'] ) ? trim( (string) $job['writer_profile_version'] ) : '';
		if ( ( '' === $id ) xor ( '' === $version ) ) {
			return new WP_Error( 'mad4b_writer_profile_binding_incomplete', 'WriterProfile identity and version must be bound together.' );
		}
		$fingerprint = '';
		if ( '' !== $id ) {
			$fingerprint = self::digest( array(
				'contract' => 'mad4b.writer-profile-binding.v1',
				'writer_profile_id' => $id,
				'writer_profile_version' => $version,
			) );
		}
		return array(
			'writer_profile_id' => $id,
			'writer_profile_version' => $version,
			'writer_profile_fingerprint' => $fingerprint,
		);
	}

	private static function job( $job_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Content_Jobs' ) ) return new WP_Error( 'mad4b_content_job_service_unavailable', 'ContentJob service is unavailable.' );
		$result = MAD4B_SCP_Content_Jobs::get_job( array( 'job_id' => strtolower( trim( (string) $job_id ) ) ) );
		if ( is_wp_error( $result ) ) return $result;
		return isset( $result['job'] ) && is_array( $result['job'] ) ? $result['job'] : new WP_Error( 'mad4b_content_job_missing', 'ContentJob was not found.' );
	}

	private static function digest( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Context_Pack::boot();
