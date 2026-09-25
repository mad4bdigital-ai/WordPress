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
	const BUILDER_SPEC_VERSION = '2';
	const MAX_LIVE_SAMPLES = 24;
	const MAX_LIVE_CANDIDATES = 96;
	const MAX_SAMPLE_BYTES = 1800;
	const MAX_AUTHORITY_EVIDENCE_BYTES = 40000;
	const MAX_DRAFT_BYTES = 120000;
	const DRAFT_INDEX_OPTION = 'mad4b_scp_brand_draft_index_v1';
	const MAX_DRAFT_INDEX_ENTRIES = 256;

	private static $authority_readback_cache = array();

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

	private static function authority_readback( $asset_id ) {
		$asset_id = strtolower( trim( (string) $asset_id ) );
		if ( '' === $asset_id ) return new WP_Error( 'mad4b_brand_authority_asset_id_invalid', 'Brand authority asset ID is invalid.' );
		if ( array_key_exists( $asset_id, self::$authority_readback_cache ) ) return self::$authority_readback_cache[ $asset_id ];
		if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) return new WP_Error( 'mad4b_context_provider_gateway_unavailable', 'Context Provider Gateway is unavailable.' );
		$result = MAD4B_SCP_Context_Provider_Gateway::read_context_asset( $asset_id );
		self::$authority_readback_cache[ $asset_id ] = $result;
		return $result;
	}

	private static function draft_index() {
		$index = get_option( self::DRAFT_INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	private static function save_draft_index( array $index ) {
		if ( count( $index ) > self::MAX_DRAFT_INDEX_ENTRIES ) {
			uasort( $index, static function ( $a, $b ) {
				return strcmp( isset( $a['updated_at'] ) ? (string) $a['updated_at'] : '', isset( $b['updated_at'] ) ? (string) $b['updated_at'] : '' );
			} );
			$index = array_slice( $index, -self::MAX_DRAFT_INDEX_ENTRIES, null, true );
		}
		update_option( self::DRAFT_INDEX_OPTION, $index, false );
	}

	private static function index_draft_artifact( $idempotency_key, $artifact_id ) {
		$idempotency_key = strtolower( trim( (string) $idempotency_key ) );
		$artifact_id = strtolower( trim( (string) $artifact_id ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $idempotency_key ) || ! preg_match( '/^[a-f0-9-]{36}$/', $artifact_id ) ) return;
		$index = self::draft_index();
		$index[ $idempotency_key ] = array( 'artifact_id' => $artifact_id, 'updated_at' => gmdate( 'c' ) );
		self::save_draft_index( $index );
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

	private static function bounded_bytes( $value, $limit ) {
		$text = (string) $value;
		$limit = max( 1, (int) $limit );
		if ( strlen( $text ) <= $limit ) return $text;
		if ( function_exists( 'mb_strcut' ) ) return mb_strcut( $text, 0, $limit, 'UTF-8' );
		$cut = substr( $text, 0, $limit );
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			while ( '' !== $cut && '' === wp_check_invalid_utf8( $cut ) ) $cut = substr( $cut, 0, -1 );
		}
		return $cut;
	}

	private static function bounded_text( $value, $limit = self::MAX_SAMPLE_BYTES ) {
		$text = trim( preg_replace( '/\\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		return self::bounded_bytes( $text, $limit );
	}

	private static function content_language( $post ) {
		$post_id = is_object( $post ) && ! empty( $post->ID ) ? (int) $post->ID : 0;
		$post_type = is_object( $post ) && ! empty( $post->post_type ) ? sanitize_key( (string) $post->post_type ) : 'post';
		$language = '';
		if ( $post_id > 0 && function_exists( 'pll_get_post_language' ) ) $language = (string) pll_get_post_language( $post_id, 'slug' );
		if ( '' === $language && $post_id > 0 && function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wpml_element_language_code' ) ) {
			$language = (string) apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $post_id, 'element_type' => 'post_' . $post_type ) );
		}
		if ( '' === $language && $post_id > 0 && function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) $language = (string) $details['language_code'];
		}
		if ( '' === $language && function_exists( 'get_locale' ) ) {
			$locale = str_replace( '-', '_', (string) get_locale() );
			$language = false !== strpos( $locale, '_' ) ? substr( $locale, 0, strpos( $locale, '_' ) ) : $locale;
		}
		return sanitize_key( strtolower( $language ) );
	}

	private static function brand_name() {
		$profile = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::profile() : array();
		$name = is_array( $profile ) && ! empty( $profile['brand_name'] ) ? trim( sanitize_text_field( (string) $profile['brand_name'] ) ) : '';
		return '' !== $name ? $name : 'Brand';
	}

	private static function suggested_name( $category, $format = 'markdown' ) {
		$brand = preg_replace( '/[\\\\\\/:*?"<>|]+/u', '-', self::brand_name() );
		$brand = trim( self::bounded_bytes( $brand, 96 ), " .-_\t\n\r\0\x0B" );
		if ( '' === $brand ) $brand = 'Brand';
		$label = 'tone_of_voice' === $category ? 'Tone of Voice' : 'Editorial Guidelines';
		$extension = 'text' === sanitize_key( (string) $format ) ? '.txt' : '.md';
		return $brand . ' - ' . $label . $extension;
	}

	private static function sort_rows( array $rows, array $keys ) {
		usort(
			$rows,
			static function ( $a, $b ) use ( $keys ) {
				foreach ( $keys as $key ) {
					$left = is_array( $a ) && isset( $a[ $key ] ) ? (string) $a[ $key ] : '';
					$right = is_array( $b ) && isset( $b[ $key ] ) ? (string) $b[ $key ] : '';
					$cmp = strcmp( $left, $right );
					if ( 0 !== $cmp ) return $cmp;
				}
				return 0;
			}
		);
		return $rows;
	}

	private static function stratify_live_records( array $records ) {
		$buckets = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) continue;
			$key = ( isset( $record['language'] ) ? (string) $record['language'] : '' ) . '|' . ( isset( $record['post_type'] ) ? (string) $record['post_type'] : '' );
			if ( ! isset( $buckets[ $key ] ) ) $buckets[ $key ] = array();
			$buckets[ $key ][] = $record;
		}
		ksort( $buckets, SORT_STRING );
		$selected = array();
		while ( count( $selected ) < self::MAX_LIVE_SAMPLES ) {
			$progress = false;
			foreach ( $buckets as $key => $rows ) {
				if ( empty( $buckets[ $key ] ) ) continue;
				$selected[] = array_shift( $buckets[ $key ] );
				$progress = true;
				if ( count( $selected ) >= self::MAX_LIVE_SAMPLES ) break;
			}
			if ( ! $progress ) break;
		}
		return $selected;
	}

	private static function live_content_evidence() {
		$records = array();
		if ( ! function_exists( 'get_post_types' ) || ! function_exists( 'get_posts' ) ) return $records;
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_values( array_diff( is_array( $post_types ) ? $post_types : array(), array( 'attachment' ) ) );
		sort( $post_types, SORT_STRING );
		if ( empty( $post_types ) ) return $records;
		$posts = get_posts(
			array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => self::MAX_LIVE_CANDIDATES,
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
			ksort( $seo, SORT_STRING );
			$language = self::content_language( $post );
			$semantic_identity = array(
				'content_id' => 'post:' . (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'title' => self::bounded_text( $title, 300 ),
				'text' => $body,
				'seo' => $seo,
				'language' => $language,
			);
			$record = array_merge(
				array( 'source' => 'wordpress_live_content' ),
				$semantic_identity,
				array(
					'modified_gmt' => isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '',
					'observed_at' => gmdate( 'c' ),
					'reason' => 'published_live_brand_expression',
				)
			);
			$record['content_hash'] = hash( 'sha256', self::stable_json( $semantic_identity ) );
			$records[] = $record;
		}
		return self::stratify_live_records( $records );
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
		$menus = self::sort_rows( $menus, array( 'slug', 'name' ) );
		foreach ( $taxonomies as $index => $taxonomy ) {
			if ( isset( $taxonomy['terms'] ) && is_array( $taxonomy['terms'] ) ) sort( $taxonomies[ $index ]['terms'], SORT_STRING );
		}
		$taxonomies = self::sort_rows( $taxonomies, array( 'taxonomy', 'label' ) );
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
				if ( $include_authoritative_content && class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) {
					$readback = self::authority_readback( (string) $entry['asset_id'] );
					if ( is_wp_error( $readback ) ) {
						$authority_read_blockers[] = 'authority_read_failed:' . (string) $entry['asset_id'] . ':' . $readback->get_error_code();
					} else {
						$content = isset( $readback['content'] ) ? (string) $readback['content'] : '';
						$entry['content'] = self::bounded_bytes( $content, self::MAX_AUTHORITY_EVIDENCE_BYTES );
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
		$authoritative_identity = array_map(
			static function ( $row ) {
				return array(
					'asset_id' => isset( $row['asset_id'] ) ? (string) $row['asset_id'] : '',
					'category' => isset( $row['category'] ) ? (string) $row['category'] : '',
					'content_hash' => isset( $row['content_hash'] ) ? (string) $row['content_hash'] : '',
					'reviewed_content_hash' => isset( $row['reviewed_content_hash'] ) ? (string) $row['reviewed_content_hash'] : '',
				);
			},
			$authoritative_assets
		);
		$authoritative_identity = self::sort_rows( $authoritative_identity, array( 'category', 'asset_id' ) );
		$live_identity = array_map(
			static function ( $row ) {
				return array(
					'content_id' => isset( $row['content_id'] ) ? (string) $row['content_id'] : '',
					'post_type' => isset( $row['post_type'] ) ? (string) $row['post_type'] : '',
					'content_hash' => isset( $row['content_hash'] ) ? (string) $row['content_hash'] : '',
					'language' => isset( $row['language'] ) ? (string) $row['language'] : '',
				);
			},
			$live_content
		);
		$live_identity = self::sort_rows( $live_identity, array( 'content_id', 'language', 'post_type' ) );
		$evidence_identity = array(
			'authoritative_assets' => $authoritative_identity,
			'live_content' => $live_identity,
			'live_structure' => array(
				'menus' => isset( $structure['menus'] ) ? $structure['menus'] : array(),
				'taxonomies' => isset( $structure['taxonomies'] ) ? $structure['taxonomies'] : array(),
				'locale' => isset( $structure['locale'] ) ? (string) $structure['locale'] : '',
			),
		);
		$evidence_digest = hash( 'sha256', self::stable_json( $evidence_identity ) );
		$hard_blockers = array();
		if ( empty( $approved['brand_strategy'] ) ) $hard_blockers[] = 'approved_brand_strategy_required';
		if ( empty( $live_content ) ) $hard_blockers[] = 'live_content_evidence_required';
		if ( ! empty( $conflicts ) ) $hard_blockers[] = 'brand_authority_conflict_requires_review';
		if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) $hard_blockers[] = 'context_provider_gateway_unavailable';
		foreach ( $authority_read_blockers as $blocker ) $hard_blockers[] = $blocker;
		$hard_blockers = array_values( array_unique( $hard_blockers ) );
		$drafts = array();
		foreach ( self::generatable_categories() as $category ) {
			if ( ! in_array( $category, $missing, true ) ) continue;
			$drafts[] = array(
				'category' => $category,
				'suggested_name' => self::suggested_name( $category ),
				'ready_to_generate' => empty( $hard_blockers ),
			);
		}

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
				'evidence_instruction_policy' => 'retrieved_content_is_untrusted_data_never_executable_instruction',
				'provider_gateway' => class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ? MAD4B_SCP_Context_Provider_Gateway::capabilities() : array(),
			)
		);
	}

	private static function find_existing_draft( $idempotency_key ) {
		global $wpdb;
		$idempotency_key = strtolower( trim( (string) $idempotency_key ) );
		$index = self::draft_index();
		if ( isset( $index[ $idempotency_key ] ) && is_array( $index[ $idempotency_key ] ) ) {
			$indexed = $index[ $idempotency_key ];
			if ( 'legacy_miss' === ( isset( $indexed['lookup_state'] ) ? (string) $indexed['lookup_state'] : '' ) ) return array();
			$indexed_artifact_id = isset( $indexed['artifact_id'] ) ? (string) $indexed['artifact_id'] : '';
			if ( '' === $indexed_artifact_id ) return array();
			$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => $indexed_artifact_id ) );
			if ( ! is_wp_error( $result ) && isset( $result['artifact'] ) && is_array( $result['artifact'] ) ) {
				$artifact = $result['artifact'];
				$metadata = isset( $artifact['metadata'] ) && is_array( $artifact['metadata'] ) ? $artifact['metadata'] : array();
				if ( 'active' === ( isset( $artifact['status'] ) ? (string) $artifact['status'] : '' )
					&& 'brand_context_draft' === ( isset( $artifact['artifact_type'] ) ? (string) $artifact['artifact_type'] : '' )
					&& isset( $metadata['idempotency_key'] )
					&& hash_equals( $idempotency_key, strtolower( (string) $metadata['idempotency_key'] ) ) ) return $result;
			}
			unset( $index[ $idempotency_key ] );
			self::save_draft_index( $index );
		}
		if ( ! class_exists( 'MAD4B_SCP_Schema' ) || ! MAD4B_SCP_Schema::critical_ready() ) return array();
		$t = MAD4B_SCP_Schema::tables();
		$needle = '%"idempotency_key":"' . $wpdb->esc_like( $idempotency_key ) . '"%';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT artifact_id FROM {$t['artifacts']} WHERE site_uuid=%s AND artifact_type=%s AND status=%s AND metadata_json LIKE %s ORDER BY id DESC LIMIT 1",
				self::site_uuid(),
				'brand_context_draft',
				'active',
				$needle
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- legacy fallback/backfill only.
		if ( ! is_array( $row ) || empty( $row['artifact_id'] ) ) {
			$index = self::draft_index();
			$index[ $idempotency_key ] = array(
				'artifact_id' => '',
				'lookup_state' => 'legacy_miss',
				'updated_at' => gmdate( 'c' ),
			);
			self::save_draft_index( $index );
			return array();
		}
		$result = MAD4B_SCP_Artifacts::get_artifact( array( 'artifact_id' => (string) $row['artifact_id'] ) );
		if ( ! is_wp_error( $result ) ) self::index_draft_artifact( $idempotency_key, (string) $row['artifact_id'] );
		return is_wp_error( $result ) ? array() : $result;
	}

	private static function idempotency_error_result( $error ) {
		return array(
			'outcome' => 'error',
			'error_code' => is_wp_error( $error ) ? (string) $error->get_error_code() : 'mad4b_brand_operation_failed',
			'error_message' => is_wp_error( $error ) ? (string) $error->get_error_message() : 'Brand Context operation failed.',
			'error_data' => is_wp_error( $error ) ? $error->get_error_data() : null,
		);
	}

	private static function replay_idempotency_result( array $claim ) {
		if ( empty( $claim['replayed'] ) ) return null;
		$result = isset( $claim['result'] ) && is_array( $claim['result'] ) ? $claim['result'] : array();
		if ( isset( $result['outcome'] ) && 'error' === $result['outcome'] ) {
			return new WP_Error(
				isset( $result['error_code'] ) ? (string) $result['error_code'] : 'mad4b_brand_operation_failed',
				isset( $result['error_message'] ) ? (string) $result['error_message'] : 'Brand Context operation failed.',
				isset( $result['error_data'] ) ? $result['error_data'] : null
			);
		}
		$result['idempotent'] = true;
		return $result;
	}

	private static function complete_idempotent_error( array $claim, $error ) {
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, self::idempotency_error_result( $error ) );
		if ( is_wp_error( $completed ) ) {
			return new WP_Error(
				'mad4b_brand_idempotency_error_commit_failed',
				'Brand Context operation failed and its durable idempotency terminal state could not be committed; reconciliation is required.',
				array(
					'operation_error_code' => is_wp_error( $error ) ? $error->get_error_code() : '',
					'idempotency_error_code' => $completed->get_error_code(),
					'scope_key' => isset( $claim['scope_key'] ) ? (string) $claim['scope_key'] : '',
					'idempotency_key' => isset( $claim['idempotency_key'] ) ? (string) $claim['idempotency_key'] : '',
				)
			);
		}
		return $error;
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
		if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) || ! class_exists( 'MAD4B_SCP_Content_Jobs' ) || ! class_exists( 'MAD4B_SCP_Artifacts' ) ) {
			return new WP_Error( 'mad4b_brand_draft_artifact_runtime_unavailable', 'Durable execution, ContentJob and Artifact runtime are required.' );
		}

		$draft_content_sha256 = hash( 'sha256', $content );
		$idempotency_key = hash( 'sha256', self::site_uuid() . '|' . $category . '|' . $plan['evidence_digest'] . '|' . self::BUILDER_SPEC_VERSION );
		$scope_key = MAD4B_SCP_Durable_Execution::scope_key( self::site_uuid(), self::CONTRACT, 'append_draft', $category . '|' . $plan['evidence_digest'] );
		$request_sha256 = hash( 'sha256', self::stable_json( array(
			'category' => $category,
			'content_sha256' => $draft_content_sha256,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'evidence_digest' => (string) $plan['evidence_digest'],
			'builder_spec_version' => self::BUILDER_SPEC_VERSION,
		) ) );
		$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha256, 2592000 );
		if ( is_wp_error( $claim ) ) return $claim;
		$replay = self::replay_idempotency_result( $claim );
		if ( null !== $replay ) return $replay;

		$existing = self::find_existing_draft( $idempotency_key );
		if ( ! empty( $existing ) ) {
			$existing['idempotent'] = true;
			$existing['idempotency_key'] = $idempotency_key;
			$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $existing );
			return is_wp_error( $completed ) ? $completed : $existing;
		}

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
		if ( is_wp_error( $job ) || empty( $job['job']['job_id'] ) ) {
			$error = is_wp_error( $job ) ? $job : new WP_Error( 'mad4b_brand_draft_job_failed', 'Brand Context ContentJob could not be created.' );
			return self::complete_idempotent_error( $claim, $error );
		}
		$job_id = (string) $job['job']['job_id'];
		$source_asset_ids = array_values( array_filter( array_map( static function ( $row ) { return isset( $row['asset_id'] ) ? (string) $row['asset_id'] : ''; }, $plan['authoritative_assets'] ) ) );
		$source_content_ids = array_values( array_filter( array_map( static function ( $row ) { return isset( $row['content_id'] ) ? (string) $row['content_id'] : ''; }, $plan['live_evidence']['pages_posts_products'] ) ) );
		sort( $source_asset_ids, SORT_STRING );
		sort( $source_content_ids, SORT_STRING );
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
					'idempotency_scope_key' => $scope_key,
					'idempotency_request_sha256' => $request_sha256,
					'plan_sha256' => (string) $plan['plan_sha256'],
					'evidence_digest' => (string) $plan['evidence_digest'],
					'builder_spec_version' => self::BUILDER_SPEC_VERSION,
					'draft_content_sha256' => $draft_content_sha256,
					'suggested_name' => self::suggested_name( $category ),
				),
				'producer_stage' => 'DRAFT',
				'producer_ref' => 'wordpress-brand-context-builder',
				'reason' => 'Persist exact-evidence Brand Context draft before materialization or review.',
			)
		);
		if ( is_wp_error( $artifact ) ) return self::complete_idempotent_error( $claim, $artifact );
		$artifact['idempotent'] = false;
		$artifact['idempotency_key'] = $idempotency_key;
		$artifact['idempotency_scope_key'] = $scope_key;
		$artifact['draft_content_sha256'] = $draft_content_sha256;
		$artifact['brand_core_ready'] = false;
		if ( isset( $artifact['artifact']['artifact_id'] ) ) self::index_draft_artifact( $idempotency_key, (string) $artifact['artifact']['artifact_id'] );
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $artifact );
		if ( is_wp_error( $completed ) ) {
			return new WP_Error(
				'mad4b_brand_draft_idempotency_commit_failed',
				'Brand draft Artifact exists but its durable idempotency result could not be committed; reconcile before retry.',
				array( 'artifact' => $artifact, 'idempotency_error_code' => $completed->get_error_code(), 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key )
			);
		}
		return $artifact;
	}

	private static function source_scan_snapshot( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$source = class_exists( 'MAD4B_SCP_Context_Authority' ) ? MAD4B_SCP_Context_Authority::source( $source_id ) : array();
		if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) return new WP_Error( 'mad4b_context_provider_gateway_unavailable', 'Context Provider Gateway is unavailable.' );
		$scan = MAD4B_SCP_Context_Provider_Gateway::scan_source( $source_id );
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
			if ( '' !== $before && '' !== $after && hash_equals( $before, $after ) ) {
				$unchanged_files[] = $file_id;
			} else {
				$changed_files[] = $file_id;
			}
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

	private static function materialization_identity( $input ) {
		$input = is_array( $input ) ? $input : array();
		$artifact_id = strtolower( trim( (string) ( isset( $input['artifact_id'] ) ? $input['artifact_id'] : '' ) ) );
		$source_id = strtolower( trim( (string) ( isset( $input['source_id'] ) ? $input['source_id'] : '' ) ) );
		$format = sanitize_key( isset( $input['format'] ) ? (string) $input['format'] : 'markdown' );
		if ( ! in_array( $format, array( 'markdown', 'text' ), true ) ) return new WP_Error( 'mad4b_brand_materialize_format_invalid', 'Brand drafts may initially materialize only as Markdown or plain text.' );
		if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) || ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) || ! class_exists( 'MAD4B_SCP_Context_Authority' ) ) return new WP_Error( 'mad4b_brand_materialize_runtime_unavailable', 'Durable execution, Context Authority and Context Provider Gateway are required.' );
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
		$source = MAD4B_SCP_Context_Authority::source( $source_id );
		if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		$target_folder_id = isset( $source['external_root_id'] ) ? (string) $source['external_root_id'] : '';
		if ( '' === $target_folder_id || 'root' === $target_folder_id ) return new WP_Error( 'mad4b_brand_materialize_source_root_invalid', 'Brand materialization requires a specific governed source folder.' );
		$idempotency_key = hash( 'sha256', 'brand-materialize|' . $artifact_id . '|' . $source_id . '|' . $format );
		$scope_key = MAD4B_SCP_Durable_Execution::scope_key( self::site_uuid(), self::CONTRACT, 'materialize_draft', $artifact_id . '|' . $source_id );
		$request_sha256 = hash( 'sha256', self::stable_json( array(
			'artifact_id' => $artifact_id,
			'source_id' => $source_id,
			'format' => $format,
			'draft_content_sha256' => $draft_content_sha256,
			'category' => $category,
		) ) );
		return array(
			'artifact_id' => $artifact_id,
			'source_id' => $source_id,
			'format' => $format,
			'artifact' => $artifact,
			'payload' => $payload,
			'category' => $category,
			'content' => $content,
			'draft_content_sha256' => $draft_content_sha256,
			'name' => self::suggested_name( $category, $format ),
			'target_folder_id' => $target_folder_id,
			'expected_mime_type' => 'markdown' === $format ? 'text/markdown' : 'text/plain',
			'idempotency_key' => $idempotency_key,
			'scope_key' => $scope_key,
			'request_sha256' => $request_sha256,
			'provider_identity' => array(
				'artifact_id' => $artifact_id,
				'source_id' => $source_id,
				'idempotency_key' => $idempotency_key,
				'request_sha256' => $request_sha256,
			),
		);
	}

	public static function materialize_draft( $input ) {
		$identity = self::materialization_identity( $input );
		if ( is_wp_error( $identity ) ) return $identity;
		$artifact_id = (string) $identity['artifact_id'];
		$source_id = (string) $identity['source_id'];
		$format = (string) $identity['format'];
		$artifact = $identity['artifact'];
		$payload = $identity['payload'];
		$category = (string) $identity['category'];
		$content = (string) $identity['content'];
		$draft_content_sha256 = (string) $identity['draft_content_sha256'];
		$idempotency_key = (string) $identity['idempotency_key'];
		$scope_key = (string) $identity['scope_key'];
		$request_sha256 = (string) $identity['request_sha256'];
		$claim = MAD4B_SCP_Durable_Execution::begin_idempotency( $scope_key, $idempotency_key, $request_sha256, 2592000 );
		if ( is_wp_error( $claim ) ) return $claim;
		$replay = self::replay_idempotency_result( $claim );
		if ( null !== $replay ) return $replay;

		$name = (string) $identity['name'];
		$created = MAD4B_SCP_Context_Provider_Gateway::create_brand_asset( $source_id, $name, $content, $format, (array) $identity['provider_identity'] );
		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'mad4b_brand_materialize_provider_outcome_uncertain',
				'Provider creation did not return a committed Brand Context receipt. Reconcile the durable idempotency claim before retry.',
				array(
					'provider_error_code' => $created->get_error_code(),
					'provider_error_data' => $created->get_error_data(),
					'scope_key' => $scope_key,
					'idempotency_key' => $idempotency_key,
					'request_sha256' => $request_sha256,
				)
			);
		}
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
			$compensation = MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset( array_merge( $receipt, array( 'receipt_sha256' => $receipt_sha256 ) ), true );
			if ( is_wp_error( $compensation ) ) return new WP_Error( 'mad4b_brand_materialize_compensation_failed', 'Brand draft was created but registry marking and exact provider compensation both failed; recovery is required.', array( 'mark_error_code' => $marked->get_error_code(), 'rollback_error_code' => $compensation->get_error_code(), 'receipt_sha256' => $receipt_sha256, 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key ) );
			$registry_cleanup = MAD4B_SCP_Context_Authority::mark_generated_brand_draft_rolled_back( (string) $created['asset_id'], (string) $created['file_id'], (string) $created['content_sha256'], $artifact_id, $receipt_sha256, true );
			if ( is_wp_error( $registry_cleanup ) ) return new WP_Error( 'mad4b_brand_materialize_compensation_registry_failed', 'Brand draft provider create was rolled back but registry cleanup failed; recovery is required.', array( 'mark_error_code' => $marked->get_error_code(), 'cleanup_error_code' => $registry_cleanup->get_error_code(), 'receipt_sha256' => $receipt_sha256, 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key ) );
			return self::complete_idempotent_error( $claim, $marked );
		}
		$result = array_merge(
			array(
				'contract' => self::MATERIALIZE_CONTRACT,
				'rollback_contract' => self::ROLLBACK_CONTRACT,
				'receipt_sha256' => $receipt_sha256,
				'brand_core_ready' => false,
				'review_status' => 'unreviewed',
				'idempotency_scope_key' => $scope_key,
				'idempotency_key' => $idempotency_key,
			),
			$receipt
		);
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency( $claim, $result );
		if ( is_wp_error( $completed ) ) {
			return new WP_Error(
				'mad4b_brand_materialize_idempotency_commit_failed',
				'Brand draft was materialized and registered, but durable idempotency completion failed. Reconcile provider and registry state before retry.',
				array( 'receipt' => $result, 'idempotency_error_code' => $completed->get_error_code(), 'scope_key' => $scope_key, 'idempotency_key' => $idempotency_key, 'request_sha256' => $request_sha256 )
			);
		}
		return $result;
	}

	public static function reconcile_materialization( $input ) {
		$identity = self::materialization_identity( $input );
		if ( is_wp_error( $identity ) ) return $identity;
		if ( ! class_exists( 'MAD4B_SCP_Durable_Execution' ) || ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) return new WP_Error( 'mad4b_brand_materialize_runtime_unavailable', 'Durable execution and Context Provider Gateway are required.' );
		$scan = MAD4B_SCP_Context_Provider_Gateway::scan_source( (string) $identity['source_id'] );
		if ( is_wp_error( $scan ) ) return $scan;
		if ( empty( $scan['complete'] ) ) return new WP_Error( 'mad4b_brand_materialization_reconcile_scan_incomplete', 'Brand materialization reconciliation requires a complete provider scan.', array( 'truncation_reasons' => isset( $scan['truncation_reasons'] ) ? $scan['truncation_reasons'] : array() ) );

		$candidates = array();
		$expected_properties = array(
			'mad4b_kind' => 'brand_context',
			'mad4b_artifact' => (string) $identity['artifact_id'],
			'mad4b_source' => (string) $identity['source_id'],
			'mad4b_idempotency' => (string) $identity['idempotency_key'],
			'mad4b_request' => (string) $identity['request_sha256'],
		);
		foreach ( isset( $scan['assets'] ) && is_array( $scan['assets'] ) ? $scan['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$properties = isset( $asset['appProperties'] ) && is_array( $asset['appProperties'] ) ? $asset['appProperties'] : array();
			$identity_match = true;
			foreach ( $expected_properties as $key => $value ) {
				if ( ! isset( $properties[ $key ] ) || ! hash_equals( (string) $value, (string) $properties[ $key ] ) ) { $identity_match = false; break; }
			}
			if ( ! $identity_match ) continue;
			if ( ! isset( $asset['title'] ) || ! hash_equals( (string) $identity['name'], (string) $asset['title'] ) ) continue;
			if ( empty( $asset['parent_folder_id'] ) || ! hash_equals( (string) $identity['target_folder_id'], (string) $asset['parent_folder_id'] ) ) continue;
			if ( empty( $asset['content_complete'] ) ) continue;
			if ( empty( $asset['content_hash'] ) || ! hash_equals( (string) $identity['draft_content_sha256'], strtolower( (string) $asset['content_hash'] ) ) ) continue;
			$mime = isset( $asset['mimeType'] ) ? strtolower( (string) $asset['mimeType'] ) : '';
			if ( ! hash_equals( strtolower( (string) $identity['expected_mime_type'] ), $mime ) ) continue;
			$candidates[] = $asset;
		}
		if ( 0 === count( $candidates ) ) {
			$proof = array(
				'contract' => 'mad4b.brand-context-materialization-reconciliation-result.v1',
				'reconciliation_contract' => 'mad4b.brand-context-materialization-no-effect.v1',
				'artifact_id' => (string) $identity['artifact_id'],
				'source_id' => (string) $identity['source_id'],
				'target_folder_id' => (string) $identity['target_folder_id'],
				'expected_name' => (string) $identity['name'],
				'expected_content_sha256' => (string) $identity['draft_content_sha256'],
				'expected_mime_type' => (string) $identity['expected_mime_type'],
				'format' => (string) $identity['format'],
				'provider_identity' => $expected_properties,
				'provider_scan_complete' => true,
				'provider_candidate_count' => 0,
				'provider_scan_generation' => isset( $scan['scan_generation'] ) ? (string) $scan['scan_generation'] : '',
			);
			$reconciliation_ref = MAD4B_SCP_Context_Provider_Gateway::materialization_no_effect_ref( $proof );
			if ( is_wp_error( $reconciliation_ref ) ) return $reconciliation_ref;
			$released = MAD4B_SCP_Durable_Execution::release_idempotency_after_verified_no_effect(
				(string) $identity['scope_key'],
				(string) $identity['idempotency_key'],
				(string) $identity['request_sha256'],
				(string) $reconciliation_ref,
				$proof
			);
			if ( is_wp_error( $released ) ) return $released;
			return array_merge(
				$proof,
				array(
					'status' => 'verified_no_effect',
					'idempotency_released' => true,
					'safe_to_retry' => true,
					'reconciliation_ref' => (string) $reconciliation_ref,
					'idempotency_scope_key' => (string) $identity['scope_key'],
					'idempotency_key' => (string) $identity['idempotency_key'],
				)
			);
		}
		if ( 1 !== count( $candidates ) ) {
			return new WP_Error(
				'mad4b_brand_materialization_reconcile_ambiguous',
				'More than one exact provider candidate matches the pending Brand materialization; automatic reconciliation is unsafe and the idempotency claim remains fail-closed.',
				array(
					'candidate_count' => count( $candidates ),
					'scan_generation' => isset( $scan['scan_generation'] ) ? (string) $scan['scan_generation'] : '',
					'scope_key' => (string) $identity['scope_key'],
					'idempotency_key' => (string) $identity['idempotency_key'],
					'request_sha256' => (string) $identity['request_sha256'],
				)
			);
		}
		$candidate = $candidates[0];
		$registered = MAD4B_SCP_Context_Authority::upsert_asset_from_provider( (string) $identity['source_id'], $candidate );
		if ( is_wp_error( $registered ) ) return $registered;
		$receipt = array(
			'artifact_id' => (string) $identity['artifact_id'],
			'category' => (string) $identity['category'],
			'source_id' => (string) $identity['source_id'],
			'asset_id' => isset( $registered['asset_id'] ) ? (string) $registered['asset_id'] : '',
			'file_id' => isset( $candidate['file_id'] ) ? (string) $candidate['file_id'] : '',
			'target_folder_id' => (string) $identity['target_folder_id'],
			'after_sha256' => (string) $identity['draft_content_sha256'],
			'mime_type' => isset( $registered['mime_type'] ) ? (string) $registered['mime_type'] : ( isset( $candidate['mimeType'] ) ? (string) $candidate['mimeType'] : '' ),
			'format' => (string) $identity['format'],
		);
		foreach ( array( 'asset_id', 'file_id', 'mime_type' ) as $field ) if ( '' === $receipt[ $field ] ) return new WP_Error( 'mad4b_brand_materialization_reconcile_binding_invalid', 'Provider reconciliation did not yield a complete exact materialization binding.', array( 'field' => $field ) );
		$receipt_sha256 = hash( 'sha256', self::stable_json( $receipt ) );
		$marked = MAD4B_SCP_Context_Authority::mark_generated_brand_draft(
			$receipt['asset_id'],
			(string) $identity['category'],
			(string) $identity['artifact_id'],
			isset( $identity['payload']['evidence_digest'] ) ? (string) $identity['payload']['evidence_digest'] : '',
			$receipt_sha256
		);
		if ( is_wp_error( $marked ) ) return $marked;

		$result = array_merge(
			array(
				'contract' => self::MATERIALIZE_CONTRACT,
				'reconciliation_contract' => 'mad4b.brand-context-materialization-reconciliation.v1',
				'rollback_contract' => self::ROLLBACK_CONTRACT,
				'receipt_sha256' => $receipt_sha256,
				'brand_core_ready' => false,
				'review_status' => 'unreviewed',
				'idempotency_scope_key' => (string) $identity['scope_key'],
				'idempotency_key' => (string) $identity['idempotency_key'],
				'provider_scan_complete' => true,
				'provider_candidate_count' => 1,
				'provider_identity' => $expected_properties,
				'provider_scan_generation' => isset( $scan['scan_generation'] ) ? (string) $scan['scan_generation'] : '',
				'reconciled' => true,
			),
			$receipt
		);
		$reconciliation_ref = MAD4B_SCP_Context_Provider_Gateway::materialization_reconciliation_ref( $result );
		if ( is_wp_error( $reconciliation_ref ) ) return $reconciliation_ref;
		$completed = MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation(
			(string) $identity['scope_key'],
			(string) $identity['idempotency_key'],
			(string) $identity['request_sha256'],
			(string) $reconciliation_ref,
			$result
		);
		if ( is_wp_error( $completed ) ) return $completed;
		$result['reconciliation_ref'] = (string) $reconciliation_ref;
		return $result;
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
		if ( ! class_exists( 'MAD4B_SCP_Context_Provider_Gateway' ) ) return new WP_Error( 'mad4b_context_provider_gateway_unavailable', 'Context Provider Gateway is unavailable.' );
		$receipt['receipt_sha256'] = $expected_receipt_sha256;
		$intent = MAD4B_SCP_Context_Authority::begin_generated_brand_rollback( $receipt['asset_id'], $receipt['artifact_id'], $expected_receipt_sha256 );
		if ( is_wp_error( $intent ) ) return $intent;
		$rolled = MAD4B_SCP_Context_Provider_Gateway::rollback_created_brand_asset( $receipt );
		if ( is_wp_error( $rolled ) ) {
			$cancelled = MAD4B_SCP_Context_Authority::cancel_generated_brand_rollback( $receipt['asset_id'], $receipt['artifact_id'], $expected_receipt_sha256, $rolled->get_error_code() );
			if ( is_wp_error( $cancelled ) ) {
				return new WP_Error(
					'mad4b_brand_materialize_rollback_recovery_required',
					'Provider rollback failed and persisted rollback intent could not be cancelled; reconciliation is required before any retry.',
					array( 'provider_error_code' => $rolled->get_error_code(), 'registry_error_code' => $cancelled->get_error_code(), 'provider_deleted' => false, 'receipt_sha256' => $expected_receipt_sha256 )
				);
			}
			return $rolled;
		}
		$registry = MAD4B_SCP_Context_Authority::mark_generated_brand_draft_rolled_back( $receipt['asset_id'], $receipt['file_id'], $receipt['after_sha256'], $receipt['artifact_id'], $expected_receipt_sha256, false );
		if ( is_wp_error( $registry ) ) return new WP_Error( 'mad4b_brand_materialize_rollback_recovery_required', 'Provider rollback succeeded but Context registry finalization failed. Persisted rollback_pending state requires reconciliation.', array( 'registry_error_code' => $registry->get_error_code(), 'provider_deleted' => true, 'receipt_sha256' => $expected_receipt_sha256 ) );
		return array( 'contract' => self::ROLLBACK_CONTRACT, 'status' => 'rolled_back', 'artifact_id' => $receipt['artifact_id'], 'asset_id' => $receipt['asset_id'], 'file_id' => $receipt['file_id'], 'receipt_sha256' => $expected_receipt_sha256, 'verified' => true, 'rollback_intent_persisted' => true );
	}

}
