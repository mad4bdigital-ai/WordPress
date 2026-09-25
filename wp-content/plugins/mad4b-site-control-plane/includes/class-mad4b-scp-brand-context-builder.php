<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Brand Context Builder
 *
 * Evidence collection and persistence are deterministic WordPress concerns.
 * Synthesis remains the responsibility of the managed Skill/Agent.
 */
final class MAD4B_SCP_Brand_Context_Builder {
	const CONTRACT = 'mad4b.brand-context-builder.v1';
	const PLAN_CONTRACT = 'mad4b.brand-gap-plan.v1';
	const DRAFT_CONTRACT = 'mad4b.brand-context-draft.v1';
	const SCAN_PLAN_CONTRACT = 'mad4b.context-source-scan-plan.v1';
	const MATERIALIZE_CONTRACT = 'mad4b.brand-context-materialization.v1';
	const ROLLBACK_CONTRACT = 'mad4b.rollback.google-drive-brand-context-create.v1';
	const BUILDER_SPEC_VERSION = '1';
	const MAX_LIVE_SAMPLES = 24;
	const MAX_SAMPLE_BYTES = 1800;
	const MAX_AUTHORITY_EVIDENCE_BYTES = 40000;
	const MAX_DRAFT_BYTES = 120000;

	public static function expected_categories() {
		return array(
			'brand_strategy' => 'Brand Strategy',
			'tone_of_voice' => 'Tone of Voice',
			'editorial_guidelines' => 'Editorial Guidelines',
		);
	}

	public static function generatable_categories() {
		return array( 'tone_of_voice', 'editorial_guidelines' );
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}

	private static function approved_brand_asset( array $asset ) {
		$current = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
		$reviewed = isset( $asset['reviewed_content_hash'] ) ? strtolower( trim( (string) $asset['reviewed_content_hash'] ) ) : '';
		return 'ready' === ( isset( $asset['status'] ) ? (string) $asset['status'] : '' )
			&& 'brand_authority' === ( isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '' )
			&& 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $current )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $reviewed )
			&& hash_equals( $current, $reviewed );
	}

	private static function approved_assets_by_category() {
		$by_category = array();
		foreach ( class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::assets() : array() as $asset ) {
			if ( ! is_array( $asset ) || ! self::approved_brand_asset( $asset ) ) continue;
			$category = sanitize_key( isset( $asset['category'] ) ? (string) $asset['category'] : '' );
			if ( '' === $category ) continue;
			if ( ! isset( $by_category[ $category ] ) ) $by_category[ $category ] = array();
			$by_category[ $category ][] = $asset;
		}
		return $by_category;
	}

	private static function bounded_text( $value, $limit = self::MAX_SAMPLE_BYTES ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		if ( strlen( $text ) <= $limit ) return $text;
		return substr( $text, 0, $limit );
	}

	private static function live_content_evidence() {
		$items = array();
		if ( ! function_exists( 'get_post_types' ) || ! function_exists( 'get_posts' ) ) return $items;
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_values( array_diff( is_array( $post_types ) ? $post_types : array(), array( 'attachment' ) ) );
		if ( empty( $post_types ) ) return $items;
		$posts = get_posts(
			array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => self::MAX_LIVE_SAMPLES,
				'orderby' => 'modified',
				'order' => 'DESC',
				'suppress_filters' => false,
			)
		);
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			if ( ! is_object( $post ) || empty( $post->ID ) ) continue;
			$title = get_the_title( $post );
			$body = self::bounded_text( (string) $post->post_excerpt . "\n" . (string) $post->post_content );
			$seo = array();
			foreach ( array( 'rank_math_title', 'rank_math_description', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ) as $meta_key ) {
				$value = get_post_meta( (int) $post->ID, $meta_key, true );
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) $seo[ $meta_key ] = self::bounded_text( (string) $value, 500 );
			}
			$language = '';
			if ( function_exists( 'pll_get_post_language' ) ) $language = (string) pll_get_post_language( (int) $post->ID, 'slug' );
			$content_identity = array(
				'content_id' => 'post:' . (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'title' => self::bounded_text( $title, 300 ),
				'text' => $body,
				'seo' => $seo,
				'language' => sanitize_key( $language ),
				'modified_gmt' => isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '',
			);
			$record = array_merge(
				array( 'source' => 'wordpress_live_content' ),
				$content_identity,
				array(
					'observed_at' => gmdate( 'c' ),
					'reason' => 'published_live_brand_expression',
				)
			);
			$record['content_hash'] = hash( 'sha256', self::stable_json( $content_identity ) );
			$items[] = $record;
		}
		return $items;
	}

	private static function structure_evidence() {
		$menus = array();
		if ( function_exists( 'wp_get_nav_menus' ) ) {
			foreach ( (array) wp_get_nav_menus() as $menu ) {
				if ( ! is_object( $menu ) ) continue;
				$menus[] = array( 'name' => (string) $menu->name, 'slug' => (string) $menu->slug );
				if ( count( $menus ) >= 12 ) break;
			}
		}
		$taxonomies = array();
		if ( function_exists( 'get_taxonomies' ) && function_exists( 'get_terms' ) ) {
			foreach ( (array) get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				if ( ! is_object( $tax ) || empty( $tax->name ) ) continue;
				$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true, 'number' => 12 ) );
				if ( is_wp_error( $terms ) ) continue;
				$taxonomies[] = array(
					'taxonomy' => (string) $tax->name,
					'label' => isset( $tax->label ) ? (string) $tax->label : '',
					'terms' => array_values( array_map( static function ( $term ) { return is_object( $term ) ? (string) $term->name : ''; }, (array) $terms ) ),
				);
				if ( count( $taxonomies ) >= 12 ) break;
			}
		}
		return array(
			'source' => 'wordpress_live_structure',
			'menus' => $menus,
			'taxonomies' => $taxonomies,
			'locale' => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'observed_at' => gmdate( 'c' ),
			'reason' => 'navigation_taxonomy_and_localization_conventions',
		);
	}

	public static function gap_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$include_authoritative_content = ! array_key_exists( 'include_authoritative_content', $input ) || ! empty( $input['include_authoritative_content'] );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_brand_builder_site_identity_unavailable', 'Site Profile identity is unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_brand_builder_context_unavailable', 'Context Authority is unavailable.' );

		$approved = self::approved_assets_by_category();
		$missing = array();
		$authoritative_assets = array();
		$authority_read_blockers = array();
		$conflicts = array();
		foreach ( self::expected_categories() as $category => $label ) {
			$rows = isset( $approved[ $category ] ) ? $approved[ $category ] : array();
			if ( empty( $rows ) ) $missing[] = $category;
			$hashes = array();
			foreach ( $rows as $asset ) {
				$hash = isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '';
				if ( '' !== $hash ) $hashes[ $hash ] = true;
				$entry = array(
					'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
					'category' => $category,
					'title' => isset( $asset['title'] ) ? (string) $asset['title'] : '',
					'content_hash' => $hash,
					'content_excerpt' => isset( $asset['content_excerpt'] ) ? (string) $asset['content_excerpt'] : '',
					'reviewed_content_hash' => isset( $asset['reviewed_content_hash'] ) ? (string) $asset['reviewed_content_hash'] : '',
					'source' => 'context_authority',
					'observed_at' => gmdate( 'c' ),
					'reason' => 'approved_brand_authority',
				);
				if ( $include_authoritative_content && class_exists( 'MAD4B_SCP_Google_Drive_Context' ) ) {
					$readback = MAD4B_SCP_Google_Drive_Context::read_context_asset( (string) $entry['asset_id'] );
					if ( is_wp_error( $readback ) ) {
						$authority_read_blockers[] = 'authority_read_failed:' . (string) $entry['asset_id'] . ':' . $readback->get_error_code();
					} else {
						$content = isset( $readback['content'] ) ? (string) $readback['content'] : '';
						$entry['content'] = strlen( $content ) > self::MAX_AUTHORITY_EVIDENCE_BYTES ? substr( $content, 0, self::MAX_AUTHORITY_EVIDENCE_BYTES ) : $content;
						$entry['content_bytes'] = strlen( $content );
						$entry['content_truncated'] = strlen( $content ) > self::MAX_AUTHORITY_EVIDENCE_BYTES;
						$entry['provider_observed_at'] = isset( $readback['observed_at'] ) ? (string) $readback['observed_at'] : '';
					}
				}
				$authoritative_assets[] = $entry;
			}
			if ( count( $hashes ) > 1 ) $conflicts[] = array( 'category' => $category, 'code' => 'multiple_approved_brand_authorities' );
		}

		$live_content = self::live_content_evidence();
		$structure = self::structure_evidence();
		$registry_revision = (int) MAD4B_SCP_Context_Authority::registry_revision();
		$context_fingerprint = (string) MAD4B_SCP_Context_Authority::context_fingerprint();
		$authority_manifest_fingerprint = (string) MAD4B_SCP_Context_Authority::authority_manifest_fingerprint();
		$evidence_identity = array(
			'authoritative_assets' => array_map(
				static function ( $row ) {
					return array(
						'asset_id' => isset( $row['asset_id'] ) ? (string) $row['asset_id'] : '',
						'category' => isset( $row['category'] ) ? (string) $row['category'] : '',
						'content_hash' => isset( $row['content_hash'] ) ? (string) $row['content_hash'] : '',
						'reviewed_content_hash' => isset( $row['reviewed_content_hash'] ) ? (string) $row['reviewed_content_hash'] : '',
					);
				},
				$authoritative_assets
			),
			'live_content' => array_map(
				static function ( $row ) {
					return array(
						'content_id' => isset( $row['content_id'] ) ? (string) $row['content_id'] : '',
						'post_type' => isset( $row['post_type'] ) ? (string) $row['post_type'] : '',
						'content_hash' => isset( $row['content_hash'] ) ? (string) $row['content_hash'] : '',
						'language' => isset( $row['language'] ) ? (string) $row['language'] : '',
					);
				},
				$live_content
			),
			'live_structure' => array(
				'menus' => isset( $structure['menus'] ) ? $structure['menus'] : array(),
				'taxonomies' => isset( $structure['taxonomies'] ) ? $structure['taxonomies'] : array(),
				'locale' => isset( $structure['locale'] ) ? (string) $structure['locale'] : '',
			),
		);
		$evidence_digest = hash( 'sha256', self::stable_json( $evidence_identity ) );
		$drafts = array();
		$names = array(
			'tone_of_voice' => 'Egypt Tour Gates - Tone of Voice.md',
			'editorial_guidelines' => 'Egypt Tour Gates - Editorial Guidelines.md',
		);
		foreach ( self::generatable_categories() as $category ) {
			if ( ! in_array( $category, $missing, true ) ) continue;
			$drafts[] = array(
				'category' => $category,
				'suggested_name' => $names[ $category ],
				'ready_to_generate' => empty( $conflicts ) && ! empty( $authoritative_assets ) && ! empty( $live_content ),
			);
		}
		$hard_blockers = array();
		if ( empty( $approved['brand_strategy'] ) ) $hard_blockers[] = 'approved_brand_strategy_required';
		if ( empty( $live_content ) ) $hard_blockers[] = 'live_content_evidence_required';
		if ( ! empty( $conflicts ) ) $hard_blockers[] = 'brand_authority_conflict_requires_review';
		foreach ( $authority_read_blockers as $blocker ) $hard_blockers[] = $blocker;

		$plan_basis = array(
			'contract' => self::PLAN_CONTRACT,
			'builder_spec_version' => self::BUILDER_SPEC_VERSION,
			'site_uuid' => $site_uuid,
			'registry_revision' => $registry_revision,
			'context_fingerprint' => $context_fingerprint,
			'authority_manifest_fingerprint' => $authority_manifest_fingerprint,
			'missing_categories' => $missing,
			'evidence_digest' => $evidence_digest,
			'conflicts' => $conflicts,
			'hard_blockers' => $hard_blockers,
		);
		$plan_sha256 = hash( 'sha256', self::stable_json( $plan_basis ) );
		return array_merge(
			$plan_basis,
			array(
				'plan_sha256' => $plan_sha256,
				'authoritative_assets' => $authoritative_assets,
				'live_evidence' => array(
					'pages_posts_products' => $live_content,
					'structure' => $structure,
					'sample_count' => count( $live_content ),
				),
				'evidence_digest' => $evidence_digest,
				'drafts' => $drafts,
				'generation_is_authority' => false,
				'approval_required_before_brand_core_ready' => true,
				'authoritative_content_included' => $include_authoritative_content,
			)
		);
	}

	private static function find_existing_draft( $idempotency_key ) {
		global $wpdb;
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return array();
		$t = MAD4B_SCP_Schema::tables();
		$needle = '%"idempotency_key":"' . $wpdb->esc_like( (string) $idempotency_key ) . '"%';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT artifact_id FROM {$t['artifacts']} WHERE site_uuid=%s AND artifact_type=%s AND status=%s AND metadata_json LIKE %s ORDER BY id DESC LIMIT 1",
				self::site_uuid(),
				'brand_context_draft',
				'active',
				$needle
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $row ) || empty( $row['artifact_id'] ) ) return array();
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => (string) $row['artifact_id'] ) );
		return is_wp_error( $result ) ? array() : $result;
	}

	public static function append_draft( $input ) {
		$input = is_array( $input ) ? $input : array();
		$category = sanitize_key( isset( $input['category'] ) ? (string) $input['category'] : '' );
		if ( ! in_array( $category, self::generatable_categories(), true ) ) return new WP_Error( 'mad4b_brand_draft_category_invalid', 'Brand draft category is not generatable.' );
		$content = isset( $input['content'] ) ? trim( (string) $input['content'] ) : '';
		if ( '' === $content || strlen( $content ) > self::MAX_DRAFT_BYTES ) return new WP_Error( 'mad4b_brand_draft_content_invalid', 'Brand draft content is missing or exceeds the certified limit.' );
		$plan = self::gap_plan();
		if ( is_wp_error( $plan ) ) return $plan;
		$expected_plan = strtolower( trim( (string) ( isset( $input['expected_plan_sha256'] ) ? $input['expected_plan_sha256'] : '' ) ) );
		$expected_evidence = strtolower( trim( (string) ( isset( $input['evidence_digest'] ) ? $input['evidence_digest'] : '' ) ) );
		if ( ! hash_equals( (string) $plan['plan_sha256'], $expected_plan ) || ! hash_equals( (string) $plan['evidence_digest'], $expected_evidence ) ) {
			return new WP_Error( 'mad4b_brand_draft_plan_stale', 'Brand evidence changed after generation planning; regenerate against a fresh plan.' );
		}
		if ( ! empty( $plan['hard_blockers'] ) ) return new WP_Error( 'mad4b_brand_draft_blocked', 'Brand draft generation is blocked by current evidence.', array( 'blockers' => $plan['hard_blockers'] ) );
		if ( ! in_array( $category, $plan['missing_categories'], true ) ) return new WP_Error( 'mad4b_brand_draft_category_not_missing', 'Requested Brand Core category is no longer missing.' );

		$draft_content_sha256 = hash( 'sha256', $content );
		$idempotency_key = hash( 'sha256', self::site_uuid() . '|' . $category . '|' . $plan['evidence_digest'] . '|' . self::BUILDER_SPEC_VERSION );
		$existing = self::find_existing_draft( $idempotency_key );
		if ( ! empty( $existing ) ) {
			$existing['idempotent'] = true;
			$existing['idempotency_key'] = $idempotency_key;
			return $existing;
		}
		if ( ! class_exists( 'MAD4B_SCP_Content_Jobs' ) || ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return new WP_Error( 'mad4b_brand_draft_artifact_runtime_unavailable', 'ContentJob/Artifact runtime is unavailable.' );
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$language = sanitize_key( substr( $locale, 0, 2 ) );
		$country = false !== strpos( $locale, '_' ) ? sanitize_key( substr( $locale, strrpos( $locale, '_' ) + 1 ) ) : 'global';
		$job = MAD4B_SCP_Content_Jobs::create_job(
			array(
				'brand_id' => self::site_uuid(),
				'subject' => 'Generate missing Brand Context: ' . $category,
				'language' => '' !== $language ? $language : 'en',
				'country' => '' !== $country ? $country : 'global',
				'content_type' => 'brand_context_generation',
				'research_depth' => 'evidence_bound',
				'automation_level' => 'review_gated',
				'reason' => 'Generate a missing Brand Core draft from exact live evidence.',
			)
		);
		if ( is_wp_error( $job ) || empty( $job['job']['job_id'] ) ) return is_wp_error( $job ) ? $job : new WP_Error( 'mad4b_brand_draft_job_failed', 'Brand Context ContentJob could not be created.' );
		$job_id = (string) $job['job']['job_id'];
		$source_asset_ids = array_values( array_filter( array_map( static function ( $row ) { return isset( $row['asset_id'] ) ? (string) $row['asset_id'] : ''; }, $plan['authoritative_assets'] ) ) );
		$source_content_ids = array_values( array_filter( array_map( static function ( $row ) { return isset( $row['content_id'] ) ? (string) $row['content_id'] : ''; }, $plan['live_evidence']['pages_posts_products'] ) ) );
		$payload = array(
			'contract' => self::DRAFT_CONTRACT,
			'category' => $category,
			'content' => $content,
			'content_sha256' => $draft_content_sha256,
			'evidence_digest' => (string) $plan['evidence_digest'],
			'source_asset_ids' => $source_asset_ids,
			'source_content_ids' => $source_content_ids,
			'generator_contract' => self::CONTRACT,
			'status' => 'draft',
			'review_status' => 'unreviewed',
			'quality_provisional' => true,
		);
		$artifact = MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => $job_id,
				'artifact_type' => 'brand_context_draft',
				'payload' => $payload,
				'metadata' => array(
					'idempotency_key' => $idempotency_key,
					'plan_sha256' => (string) $plan['plan_sha256'],
					'evidence_digest' => (string) $plan['evidence_digest'],
					'builder_spec_version' => self::BUILDER_SPEC_VERSION,
					'draft_content_sha256' => $draft_content_sha256,
					'suggested_name' => 'tone_of_voice' === $category ? 'Egypt Tour Gates - Tone of Voice.md' : 'Egypt Tour Gates - Editorial Guidelines.md',
				),
				'producer_stage' => 'DRAFT',
				'producer_ref' => 'wordpress-brand-context-builder',
				'reason' => 'Persist exact-evidence Brand Context draft before materialization or review.',
			)
		);
		if ( is_wp_error( $artifact ) ) return $artifact;
		$artifact['idempotent'] = false;
		$artifact['idempotency_key'] = $idempotency_key;
		$artifact['draft_content_sha256'] = $draft_content_sha256;
		$artifact['brand_core_ready'] = false;
		return $artifact;
	}

	private static function source_scan_snapshot( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$source = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::source( $source_id ) : array();
		if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		$scan = MAD4B_SCP_Google_Drive_Context::scan_folder( $source['external_root_id'], ! empty( $source['recursive'] ) );
		if ( is_wp_error( $scan ) ) return $scan;
		$current = array();
		foreach ( MAD4B_SCP_Context_Authority::assets() as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['source_id'] ) || ! hash_equals( $source_id, (string) $asset['source_id'] ) ) continue;
			$current[ (string) $asset['file_id'] ] = $asset;
		}
		$observed = array();
		foreach ( isset( $scan['assets'] ) && is_array( $scan['assets'] ) ? $scan['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['file_id'] ) ) continue;
			$observed[ (string) $asset['file_id'] ] = $asset;
		}
		ksort( $observed, SORT_STRING );
		ksort( $current, SORT_STRING );
		$new_files = array_values( array_diff( array_keys( $observed ), array_keys( $current ) ) );
		$missing_files = array_values( array_diff( array_keys( $current ), array_keys( $observed ) ) );
		sort( $new_files, SORT_STRING );
		sort( $missing_files, SORT_STRING );
		$changed_files = array();
		$unchanged_files = array();
		foreach ( array_intersect( array_keys( $observed ), array_keys( $current ) ) as $file_id ) {
			$before = isset( $current[ $file_id ]['content_hash'] ) ? (string) $current[ $file_id ]['content_hash'] : '';
			$after = isset( $observed[ $file_id ]['content_hash'] ) ? (string) $observed[ $file_id ]['content_hash'] : '';
			( '' !== $before && '' !== $after && hash_equals( $before, $after ) ? $unchanged_files : $changed_files )[] = $file_id;
		}
		sort( $changed_files, SORT_STRING );
		sort( $unchanged_files, SORT_STRING );
		$inventory_digest = hash( 'sha256', self::stable_json( $observed ) );
		$basis = array(
			'contract' => self::SCAN_PLAN_CONTRACT,
			'source_id' => $source_id,
			'registry_revision' => (int) MAD4B_SCP_Context_Authority::registry_revision(),
			'provider_inventory_digest' => $inventory_digest,
			'scan_complete' => ! empty( $scan['complete'] ),
			'truncation_reasons' => isset( $scan['truncation_reasons'] ) ? array_values( $scan['truncation_reasons'] ) : array(),
			'new_files' => $new_files,
			'changed_files' => $changed_files,
			'unchanged_files' => $unchanged_files,
			'missing_files' => $missing_files,
		);
		$basis['plan_sha256'] = hash( 'sha256', self::stable_json( $basis ) );
		return array( 'plan' => $basis, 'scan' => $scan );
	}

	public static function source_scan_plan( $input ) {
		$result = self::source_scan_snapshot( isset( $input['source_id'] ) ? $input['source_id'] : '' );
		return is_wp_error( $result ) ? $result : $result['plan'];
	}

	public static function source_scan_apply( $input ) {
		$result = self::source_scan_snapshot( isset( $input['source_id'] ) ? $input['source_id'] : '' );
		if ( is_wp_error( $result ) ) return $result;
		$plan = $result['plan'];
		if ( empty( $plan['scan_complete'] ) ) return new WP_Error( 'mad4b_context_source_scan_incomplete', 'Truncated/incomplete scans cannot mutate Context Authority.', array( 'truncation_reasons' => $plan['truncation_reasons'] ) );
		foreach ( array( 'expected_plan_sha256' => 'plan_sha256', 'expected_provider_inventory_digest' => 'provider_inventory_digest' ) as $input_key => $plan_key ) {
			$expected = strtolower( trim( (string) ( isset( $input[ $input_key ] ) ? $input[ $input_key ] : '' ) ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( (string) $plan[ $plan_key ], $expected ) ) return new WP_Error( 'mad4b_context_source_scan_plan_stale', 'Context source inventory changed after scan planning.', array( 'field' => $plan_key ) );
		}
		$expected_revision = isset( $input['expected_registry_revision'] ) ? (int) $input['expected_registry_revision'] : -1;
		if ( $expected_revision !== (int) $plan['registry_revision'] ) return new WP_Error( 'mad4b_context_source_scan_registry_stale', 'Context registry changed after scan planning.' );
		return MAD4B_SCP_Context_Authority::replace_source_assets(
			(string) $plan['source_id'],
			isset( $result['scan']['assets'] ) && is_array( $result['scan']['assets'] ) ? $result['scan']['assets'] : array(),
			$result['scan']
		);
	}

	public static function materialize_draft( $input ) {
		$artifact_id = strtolower( trim( (string) ( isset( $input['artifact_id'] ) ? $input['artifact_id'] : '' ) ) );
		$source_id = strtolower( trim( (string) ( isset( $input['source_id'] ) ? $input['source_id'] : '' ) ) );
		$format = sanitize_key( isset( $input['format'] ) ? (string) $input['format'] : 'markdown' );
		if ( ! in_array( $format, array( 'markdown', 'text' ), true ) ) return new WP_Error( 'mad4b_brand_materialize_format_invalid', 'Brand drafts may initially materialize only as Markdown or plain text.' );
		$record = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $artifact_id ) );
		if ( is_wp_error( $record ) || empty( $record['artifact'] ) ) return is_wp_error( $record ) ? $record : new WP_Error( 'mad4b_brand_materialize_artifact_missing', 'Brand draft Artifact was not found.' );
		$artifact = $record['artifact'];
		if ( 'brand_context_draft' !== (string) $artifact['artifact_type'] || 'active' !== (string) $artifact['status'] ) return new WP_Error( 'mad4b_brand_materialize_artifact_invalid', 'Only an active Brand Context draft Artifact can be materialized.' );
		$payload = isset( $artifact['payload'] ) && is_array( $artifact['payload'] ) ? $artifact['payload'] : array();
		$category = sanitize_key( isset( $payload['category'] ) ? (string) $payload['category'] : '' );
		if ( ! in_array( $category, self::generatable_categories(), true ) ) return new WP_Error( 'mad4b_brand_materialize_category_invalid', 'Artifact category is not materializable.' );
		$content = isset( $payload['content'] ) ? (string) $payload['content'] : '';
		$draft_content_sha256 = hash( 'sha256', $content );
		$expected_sha = strtolower( trim( (string) ( isset( $input['expected_draft_content_sha256'] ) ? $input['expected_draft_content_sha256'] : '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha ) || ! hash_equals( $draft_content_sha256, $expected_sha ) ) return new WP_Error( 'mad4b_brand_materialize_artifact_stale', 'Brand draft text changed before materialization.' );
		$name = 'tone_of_voice' === $category ? 'Egypt Tour Gates - Tone of Voice.md' : 'Egypt Tour Gates - Editorial Guidelines.md';
		if ( 'text' === $format ) $name = preg_replace( '/\.md$/', '.txt', $name );
		$created = MAD4B_SCP_Google_Drive_Context::create_asset( $source_id, $name, $content, $format );
		if ( is_wp_error( $created ) ) return $created;
		$receipt = array(
			'artifact_id' => $artifact_id,
			'category' => $category,
			'source_id' => (string) $created['source_id'],
			'asset_id' => (string) $created['asset_id'],
			'file_id' => (string) $created['file_id'],
			'target_folder_id' => (string) $created['target_folder_id'],
			'after_sha256' => (string) $created['content_sha256'],
			'mime_type' => (string) $created['mime_type'],
			'format' => $format,
		);
		$receipt_sha256 = hash( 'sha256', self::stable_json( $receipt ) );
		$marked = MAD4B_SCP_Context_Authority::mark_generated_brand_draft(
			(string) $created['asset_id'],
			$category,
			$artifact_id,
			isset( $payload['evidence_digest'] ) ? (string) $payload['evidence_digest'] : '',
			$receipt_sha256
		);
		if ( is_wp_error( $marked ) ) {
			$compensation = MAD4B_SCP_Google_Drive_Context::rollback_created_brand_asset( array_merge( $receipt, array( 'receipt_sha256' => $receipt_sha256 ) ), true );
			if ( is_wp_error( $compensation ) ) return new WP_Error( 'mad4b_brand_materialize_compensation_failed', 'Brand draft was created but registry marking and exact provider compensation both failed; recovery is required.', array( 'mark_error_code' => $marked->get_error_code(), 'rollback_error_code' => $compensation->get_error_code(), 'receipt_sha256' => $receipt_sha256 ) );
			$registry_cleanup = MAD4B_SCP_Context_Authority::mark_generated_brand_draft_rolled_back( (string) $created['asset_id'], (string) $created['file_id'], (string) $created['content_sha256'], $artifact_id, $receipt_sha256, true );
			if ( is_wp_error( $registry_cleanup ) ) return new WP_Error( 'mad4b_brand_materialize_compensation_registry_failed', 'Brand draft provider create was rolled back but registry cleanup failed; recovery is required.', array( 'mark_error_code' => $marked->get_error_code(), 'cleanup_error_code' => $registry_cleanup->get_error_code(), 'receipt_sha256' => $receipt_sha256 ) );
			return $marked;
		}
		return array_merge(
			array(
				'contract' => self::MATERIALIZE_CONTRACT,
				'rollback_contract' => self::ROLLBACK_CONTRACT,
				'receipt_sha256' => $receipt_sha256,
				'brand_core_ready' => false,
				'review_status' => 'unreviewed',
			),
			$receipt
		);
	}

	public static function rollback_materialized_draft( $input ) {
		$receipt = array(
			'artifact_id' => isset( $input['artifact_id'] ) ? (string) $input['artifact_id'] : '',
			'category' => isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '',
			'source_id' => isset( $input['source_id'] ) ? (string) $input['source_id'] : '',
			'asset_id' => isset( $input['asset_id'] ) ? (string) $input['asset_id'] : '',
			'file_id' => isset( $input['file_id'] ) ? (string) $input['file_id'] : '',
			'target_folder_id' => isset( $input['target_folder_id'] ) ? (string) $input['target_folder_id'] : '',
			'after_sha256' => isset( $input['after_sha256'] ) ? (string) $input['after_sha256'] : '',
			'mime_type' => isset( $input['mime_type'] ) ? (string) $input['mime_type'] : '',
			'format' => isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : '',
		);
		$expected_receipt_sha256 = strtolower( trim( (string) ( isset( $input['receipt_sha256'] ) ? $input['receipt_sha256'] : '' ) ) );
		$current_receipt_sha256 = hash( 'sha256', self::stable_json( $receipt ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_receipt_sha256 ) || ! hash_equals( $current_receipt_sha256, $expected_receipt_sha256 ) ) return new WP_Error( 'mad4b_brand_materialize_receipt_mismatch', 'Brand materialization rollback receipt does not match the exact creation result.' );
		if ( ! in_array( $receipt['category'], self::generatable_categories(), true ) || ! in_array( $receipt['format'], array( 'markdown', 'text' ), true ) ) return new WP_Error( 'mad4b_brand_materialize_receipt_scope_invalid', 'Brand materialization rollback receipt is outside the certified Brand Core scope.' );
		$receipt['receipt_sha256'] = $expected_receipt_sha256;
		$rolled = MAD4B_SCP_Google_Drive_Context::rollback_created_brand_asset( $receipt );
		if ( is_wp_error( $rolled ) ) return $rolled;
		$registry = MAD4B_SCP_Context_Authority::mark_generated_brand_draft_rolled_back( $receipt['asset_id'], $receipt['file_id'], $receipt['after_sha256'], $receipt['artifact_id'], $expected_receipt_sha256, false );
		if ( is_wp_error( $registry ) ) return new WP_Error( 'mad4b_brand_materialize_rollback_recovery_required', 'Provider rollback succeeded but Context registry finalization failed.', array( 'registry_error_code' => $registry->get_error_code(), 'provider_deleted' => true, 'receipt_sha256' => $expected_receipt_sha256 ) );
		return array( 'contract' => self::ROLLBACK_CONTRACT, 'status' => 'rolled_back', 'artifact_id' => $receipt['artifact_id'], 'asset_id' => $receipt['asset_id'], 'file_id' => $receipt['file_id'], 'receipt_sha256' => $expected_receipt_sha256, 'verified' => true );
	}
}
