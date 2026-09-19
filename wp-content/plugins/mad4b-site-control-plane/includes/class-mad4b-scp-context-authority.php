<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-bound brand/task context authority.
 *
 * MVP storage is deliberately versioned, non-autoloaded WordPress options.
 * This keeps Context Authority independent from the governance schema while the
 * product contract and UX stabilize. A future lifecycle migration may move the
 * same records to dedicated tables without changing the public contracts.
 */
final class MAD4B_SCP_Context_Authority {
	const CONTRACT = 'mad4b.context-authority.v1';
	const PROFILE_CONTRACT = 'mad4b.brand-context-profile.v1';
	const SOURCE_CONTRACT = 'mad4b.context-source.v1';
	const ASSET_CONTRACT = 'mad4b.context-asset.v1';
	const QUALITY_CONTRACT = 'mad4b.context-quality-score.v1';

	const PROFILE_OPTION = 'mad4b_scp_brand_context_profile_v1';
	const SOURCES_OPTION = 'mad4b_scp_context_sources_v1';
	const ASSETS_OPTION = 'mad4b_scp_context_assets_v1';

	const ABILITY = 'mad4b/context-authority-status';
	const MAX_SOURCES = 50;
	const MAX_ASSETS = 1000;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 38 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Context Authority Status',
				'description' => 'Read-only site-bound Brand Context, source, asset classification and quality readiness.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
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

	public static function profile() {
		$record = get_option( self::PROFILE_OPTION, array() );
		return self::valid_profile( $record ) ? $record : array();
	}

	public static function sources() {
		$records = get_option( self::SOURCES_OPTION, array() );
		if ( ! is_array( $records ) ) return array();
		$out = array();
		foreach ( array_slice( $records, -self::MAX_SOURCES, self::MAX_SOURCES, true ) as $key => $record ) {
			if ( ! self::valid_source( $record ) ) continue;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	public static function assets() {
		$records = get_option( self::ASSETS_OPTION, array() );
		if ( ! is_array( $records ) ) return array();
		$out = array();
		foreach ( array_slice( $records, -self::MAX_ASSETS, self::MAX_ASSETS, true ) as $key => $record ) {
			if ( ! self::valid_asset( $record ) ) continue;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	public static function save_profile( $brand_name ) {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$brand_name = trim( sanitize_text_field( (string) $brand_name ) );
		if ( '' === $brand_name ) return new WP_Error( 'mad4b_brand_context_name_required', 'Brand name is required.' );
		$current = self::profile();
		$revision = isset( $current['revision'] ) ? max( 1, absint( $current['revision'] ) + 1 ) : 1;
		$record = array(
			'contract' => self::PROFILE_CONTRACT,
			'site_uuid' => $site['site_uuid'],
			'brand_id' => self::brand_id( $site['site_uuid'], $brand_name ),
			'brand_name' => $brand_name,
			'revision' => $revision,
			'status' => 'configured',
			'context_policy' => 'site_bound_governed_plus_task_sources',
			'context_fingerprint' => self::context_fingerprint(),
			'last_verified_at' => '',
			'created_at' => isset( $current['created_at'] ) ? (string) $current['created_at'] : gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		self::write_option( self::PROFILE_OPTION, $record );
		return $record;
	}

	public static function upsert_source( array $input ) {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$profile = self::profile();
		if ( empty( $profile ) ) return new WP_Error( 'mad4b_brand_context_profile_required', 'Configure the Brand Context Profile before adding sources.' );

		$provider = sanitize_key( isset( $input['provider'] ) ? $input['provider'] : '' );
		$mode = sanitize_key( isset( $input['mode'] ) ? $input['mode'] : 'governed' );
		if ( 'google_drive' !== $provider ) return new WP_Error( 'mad4b_context_source_provider_invalid', 'Only the Google Drive read provider is supported in this foundation.' );
		if ( ! in_array( $mode, array( 'governed', 'task_attachment' ), true ) ) return new WP_Error( 'mad4b_context_source_mode_invalid', 'Context source mode must be governed or task_attachment.' );

		$external_root_id = self::bounded_external_id( isset( $input['external_root_id'] ) ? $input['external_root_id'] : '' );
		if ( '' === $external_root_id ) return new WP_Error( 'mad4b_context_source_root_required', 'A canonical Google Drive folder ID is required.' );
		$label = trim( sanitize_text_field( isset( $input['label'] ) ? $input['label'] : '' ) );
		if ( '' === $label ) $label = 'Google Drive Folder';
		$task_scope = trim( sanitize_text_field( isset( $input['task_scope'] ) ? $input['task_scope'] : '' ) );
		if ( 'task_attachment' === $mode && '' === $task_scope ) return new WP_Error( 'mad4b_context_task_scope_required', 'Task-only sources require a task scope label.' );

		$sources = self::sources();
		$source_id = hash( 'sha256', $site['site_uuid'] . '|' . $provider . '|' . $mode . '|' . $external_root_id . '|' . $task_scope );
		$current = isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : array();
		$record = array(
			'contract' => self::SOURCE_CONTRACT,
			'source_id' => $source_id,
			'site_uuid' => $site['site_uuid'],
			'brand_id' => (string) $profile['brand_id'],
			'provider' => $provider,
			'mode' => $mode,
			'external_root_id' => $external_root_id,
			'label' => $label,
			'task_scope' => 'task_attachment' === $mode ? $task_scope : '',
			'recursive' => ! isset( $input['recursive'] ) || ! empty( $input['recursive'] ),
			'status' => 'selected',
			'last_synced_at' => isset( $current['last_synced_at'] ) ? (string) $current['last_synced_at'] : '',
			'asset_count' => isset( $current['asset_count'] ) ? absint( $current['asset_count'] ) : 0,
			'created_at' => isset( $current['created_at'] ) ? (string) $current['created_at'] : gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		$sources[ $source_id ] = $record;
		if ( count( $sources ) > self::MAX_SOURCES ) $sources = array_slice( $sources, -self::MAX_SOURCES, self::MAX_SOURCES, true );
		self::write_option( self::SOURCES_OPTION, $sources );
		return $record;
	}

	public static function replace_source_assets( $source_id, array $assets ) {
		$source_id = strtolower( trim( (string) $source_id ) );
		$sources = self::sources();
		if ( ! isset( $sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		$source = $sources[ $source_id ];
		$records = self::assets();
		foreach ( $records as $asset_id => $record ) {
			if ( isset( $record['source_id'] ) && hash_equals( $source_id, (string) $record['source_id'] ) ) unset( $records[ $asset_id ] );
		}
		$count = 0;
		foreach ( array_slice( $assets, 0, self::MAX_ASSETS ) as $asset ) {
			if ( ! is_array( $asset ) ) continue;
			$normalized = self::normalize_asset( $source, $asset );
			if ( is_wp_error( $normalized ) ) continue;
			$records[ $normalized['asset_id'] ] = $normalized;
			++$count;
			if ( count( $records ) >= self::MAX_ASSETS ) break;
		}
		self::write_option( self::ASSETS_OPTION, $records );
		$sources[ $source_id ]['status'] = 'ready';
		$sources[ $source_id ]['last_synced_at'] = gmdate( 'c' );
		$sources[ $source_id ]['asset_count'] = $count;
		$sources[ $source_id ]['updated_at'] = gmdate( 'c' );
		self::write_option( self::SOURCES_OPTION, $sources );

		$profile = self::profile();
		if ( ! empty( $profile ) ) {
			$profile['context_fingerprint'] = self::context_fingerprint( $records, $sources );
			$profile['last_verified_at'] = gmdate( 'c' );
			$profile['status'] = 'indexed';
			$profile['updated_at'] = gmdate( 'c' );
			self::write_option( self::PROFILE_OPTION, $profile );
		}
		return array( 'source' => $sources[ $source_id ], 'asset_count' => $count, 'context_fingerprint' => self::context_fingerprint( $records, $sources ) );
	}

	public static function status() {
		$site_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$profile = self::profile();
		$sources = self::sources();
		$assets = self::assets();
		$governed_sources = array();
		$task_sources = array();
		foreach ( $sources as $source ) {
			if ( 'governed' === $source['mode'] ) $governed_sources[] = $source;
			else $task_sources[] = $source;
		}
		$governed_assets = array();
		$required = array();
		$quality_values = array();
		$stale = 0;
		$conflicting = 0;
		foreach ( $assets as $asset ) {
			if ( 'governed' !== $asset['source_mode'] ) continue;
			$governed_assets[] = $asset;
			if ( ! empty( $asset['required'] ) ) $required[] = $asset;
			if ( null !== $asset['quality_score'] ) $quality_values[] = (int) $asset['quality_score'];
			if ( 'stale' === $asset['status'] ) ++$stale;
			if ( 'conflicting' === $asset['status'] ) ++$conflicting;
		}
		$ready_required = 0;
		foreach ( $required as $asset ) if ( 'ready' === $asset['status'] ) ++$ready_required;
		$blockers = array();
		if ( empty( $site_status['configured'] ) || empty( $site_status['origin_match'] ) || empty( $site_status['environment_match'] ) ) $blockers[] = 'site_profile_not_enrolled';
		if ( empty( $profile ) ) $blockers[] = 'brand_context_profile_unconfigured';
		if ( empty( $governed_sources ) ) $blockers[] = 'governed_context_source_missing';
		if ( empty( $governed_assets ) ) $blockers[] = 'governed_context_assets_missing';
		if ( ! empty( $governed_assets ) && empty( $required ) ) $blockers[] = 'mandatory_context_unclassified';
		if ( count( $required ) !== $ready_required ) $blockers[] = 'mandatory_context_not_ready';
		if ( $stale > 0 ) $blockers[] = 'brand_context_contains_stale_assets';
		if ( $conflicting > 0 ) $blockers[] = 'mandatory_context_conflict';

		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : ( empty( $profile ) ? 'unconfigured' : 'blocked' ),
			'site_uuid' => isset( $site_status['site_uuid'] ) ? (string) $site_status['site_uuid'] : '',
			'brand_id' => isset( $profile['brand_id'] ) ? (string) $profile['brand_id'] : '',
			'brand_name' => isset( $profile['brand_name'] ) ? (string) $profile['brand_name'] : '',
			'profile_revision' => isset( $profile['revision'] ) ? absint( $profile['revision'] ) : 0,
			'context_fingerprint' => self::context_fingerprint( $assets, $sources ),
			'governed_source_count' => count( $governed_sources ),
			'task_source_count' => count( $task_sources ),
			'asset_count' => count( $assets ),
			'governed_asset_count' => count( $governed_assets ),
			'required_asset_count' => count( $required ),
			'ready_required_asset_count' => $ready_required,
			'stale_asset_count' => $stale,
			'conflicting_asset_count' => $conflicting,
			'average_quality_score' => $quality_values ? (int) round( array_sum( $quality_values ) / count( $quality_values ) ) : null,
			'quality_scored_asset_count' => count( $quality_values ),
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public static function classify_asset( $name, $path = '', $content = '' ) {
		$haystack = strtolower( trim( (string) $name . ' ' . (string) $path . ' ' . substr( (string) $content, 0, 6000 ) ) );
		$rules = array(
			'brand_strategy' => array( 'brand strategy', 'brand core', 'brand plan' ),
			'brand_positioning' => array( 'positioning', 'brand position' ),
			'audience_persona' => array( 'persona', 'audience', 'customer profile', 'buyer profile' ),
			'tone_of_voice' => array( 'tone of voice', 'tone-of-voice', 'brand voice', 'tov' ),
			'messaging' => array( 'messaging', 'message framework', 'key messages' ),
			'editorial_guidelines' => array( 'editorial', 'writing guideline', 'style guide', 'content guideline' ),
			'terminology' => array( 'terminology', 'naming rule', 'glossary', 'vocabulary' ),
			'claim_policy' => array( 'prohibited claim', 'claim policy', 'restriction', 'legal claim' ),
			'seo_strategy' => array( 'seo', 'search strategy', 'keyword strategy' ),
			'content_strategy' => array( 'content strategy', 'blog strategy', 'content pillar' ),
			'campaign_strategy' => array( 'campaign plan', 'campaign strategy' ),
			'product_knowledge' => array( 'product knowledge', 'product guide', 'product catalog' ),
			'service_knowledge' => array( 'service knowledge', 'service guide', 'services' ),
			'destination_knowledge' => array( 'destination guide', 'destination knowledge', 'travel guide' ),
			'market_research' => array( 'market research', 'market report', 'research report', 'market insight' ),
			'writer_reference' => array( 'writer reference', 'author reference', 'journalist', 'writing sample', 'style profile' ),
			'content_example' => array( 'content example', 'sample article', 'sample blog', 'example copy' ),
		);
		$best = 'uncategorized';
		$best_hits = 0;
		foreach ( $rules as $category => $needles ) {
			$hits = 0;
			foreach ( $needles as $needle ) if ( false !== strpos( $haystack, $needle ) ) ++$hits;
			if ( $hits > $best_hits ) { $best = $category; $best_hits = $hits; }
		}
		$confidence = 0.35;
		if ( 1 === $best_hits ) $confidence = 0.72;
		if ( 2 === $best_hits ) $confidence = 0.88;
		if ( $best_hits >= 3 ) $confidence = 0.96;
		$authority = 'reference';
		if ( in_array( $best, array( 'brand_strategy', 'brand_positioning', 'audience_persona', 'tone_of_voice', 'messaging', 'editorial_guidelines', 'terminology', 'claim_policy' ), true ) ) $authority = 'brand_authority';
		elseif ( in_array( $best, array( 'seo_strategy', 'content_strategy', 'campaign_strategy', 'product_knowledge', 'service_knowledge', 'destination_knowledge', 'market_research' ), true ) ) $authority = 'task_knowledge';
		$required = in_array( $best, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines', 'terminology', 'claim_policy' ), true );
		$priority = 'brand_authority' === $authority ? 100 : ( 'task_knowledge' === $authority ? 70 : 40 );
		return array(
			'category' => $best,
			'classification_confidence' => $confidence,
			'classification_source' => 'automatic_heuristic',
			'authority_class' => $authority,
			'required' => $required,
			'priority' => $priority,
		);
	}

	public static function score_asset( array $metadata, $content = '' ) {
		$content = trim( (string) $content );
		$word_count = '' === $content ? 0 : count( preg_split( '/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY ) );
		$paragraph_count = '' === $content ? 0 : count( array_filter( preg_split( '/\R{2,}/u', $content ) ) );
		$modified = isset( $metadata['modifiedTime'] ) ? strtotime( (string) $metadata['modifiedTime'] ) : false;
		$age_days = false === $modified ? null : max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) );
		$freshness = null === $age_days ? 50 : ( $age_days <= 90 ? 100 : ( $age_days <= 365 ? 85 : ( $age_days <= 730 ? 65 : 45 ) ) );
		$extractability = '' !== $content ? 100 : 45;
		$completeness = 40;
		if ( $word_count >= 150 ) $completeness = 65;
		if ( $word_count >= 500 ) $completeness = 82;
		if ( $word_count >= 1200 ) $completeness = 95;
		$structure = '' === $content ? 45 : min( 100, 55 + min( 30, $paragraph_count * 4 ) + ( preg_match( '/(^|\R)#{1,6}\s+/u', $content ) ? 15 : 0 ) );
		$category = isset( $metadata['category'] ) ? sanitize_key( (string) $metadata['category'] ) : 'uncategorized';
		$source_quality = in_array( $category, array( 'brand_strategy', 'tone_of_voice', 'editorial_guidelines', 'terminology', 'claim_policy' ), true ) ? 95 : 80;
		if ( '' === $content ) {
			$overall = (int) round( $freshness * 0.45 + $extractability * 0.30 + $source_quality * 0.25 );
			$mode = 'metadata_provisional';
		} else {
			$overall = (int) round( $freshness * 0.15 + $completeness * 0.30 + $structure * 0.20 + $source_quality * 0.20 + $extractability * 0.15 );
			$mode = 'content_heuristic';
		}
		return array(
			'contract' => self::QUALITY_CONTRACT,
			'overall_score' => max( 0, min( 100, $overall ) ),
			'mode' => $mode,
			'provisional' => 'content_heuristic' !== $mode,
			'word_count' => $word_count,
			'paragraph_count' => $paragraph_count,
			'dimensions' => array(
				'freshness' => $freshness,
				'completeness' => $completeness,
				'structure' => $structure,
				'source_quality' => $source_quality,
				'extractability' => $extractability,
			),
		);
	}

	public static function context_fingerprint( $assets = null, $sources = null ) {
		if ( null === $assets ) $assets = self::assets();
		if ( null === $sources ) $sources = self::sources();
		$rows = array();
		foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? $asset['source_mode'] : '' ) ) continue;
			$rows[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'version' => isset( $asset['version'] ) ? (string) $asset['version'] : '',
				'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'priority' => isset( $asset['priority'] ) ? (int) $asset['priority'] : 0,
				'required' => ! empty( $asset['required'] ),
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['asset_id'], $b['asset_id'] ); } );
		$json = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '[]' );
	}

	private static function normalize_asset( array $source, array $asset ) {
		$file_id = self::bounded_external_id( isset( $asset['file_id'] ) ? $asset['file_id'] : '' );
		if ( '' === $file_id ) return new WP_Error( 'mad4b_context_asset_file_id_required', 'Asset requires a canonical external file ID.' );
		$title = trim( sanitize_text_field( isset( $asset['title'] ) ? $asset['title'] : '' ) );
		if ( '' === $title ) $title = 'Untitled';
		$content = isset( $asset['normalized_text'] ) ? (string) $asset['normalized_text'] : '';
		$classification = self::classify_asset( $title, isset( $asset['path'] ) ? $asset['path'] : '', $content );
		$metadata = $asset;
		$metadata['category'] = $classification['category'];
		$quality = self::score_asset( $metadata, $content );
		$content_hash = isset( $asset['content_hash'] ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $content_hash ) ) {
			$basis = '' !== $content ? $content : $file_id . '|' . ( isset( $asset['modifiedTime'] ) ? $asset['modifiedTime'] : '' );
			$content_hash = hash( 'sha256', $basis );
		}
		$asset_id = hash( 'sha256', (string) $source['source_id'] . '|' . $file_id );
		return array(
			'contract' => self::ASSET_CONTRACT,
			'asset_id' => $asset_id,
			'site_uuid' => (string) $source['site_uuid'],
			'brand_id' => (string) $source['brand_id'],
			'source_id' => (string) $source['source_id'],
			'source_mode' => (string) $source['mode'],
			'task_scope' => isset( $source['task_scope'] ) ? (string) $source['task_scope'] : '',
			'provider' => (string) $source['provider'],
			'file_id' => $file_id,
			'title' => $title,
			'path' => isset( $asset['path'] ) ? substr( sanitize_text_field( (string) $asset['path'] ), 0, 500 ) : '',
			'mime_type' => isset( $asset['mimeType'] ) ? substr( sanitize_text_field( (string) $asset['mimeType'] ), 0, 191 ) : '',
			'version' => isset( $asset['modifiedTime'] ) ? sanitize_text_field( (string) $asset['modifiedTime'] ) : '',
			'content_hash' => $content_hash,
			'content_available' => '' !== $content,
			'content_excerpt' => '' !== $content ? wp_trim_words( wp_strip_all_tags( $content ), 45, '…' ) : '',
			'category' => $classification['category'],
			'classification_confidence' => $classification['classification_confidence'],
			'classification_source' => $classification['classification_source'],
			'authority_class' => $classification['authority_class'],
			'required' => $classification['required'],
			'priority' => $classification['priority'],
			'language' => isset( $asset['language'] ) ? sanitize_key( (string) $asset['language'] ) : '',
			'scope' => 'all',
			'quality_score' => isset( $quality['overall_score'] ) ? (int) $quality['overall_score'] : null,
			'quality' => $quality,
			'status' => 'ready',
			'last_synced_at' => gmdate( 'c' ),
		);
	}

	private static function site_binding() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return new WP_Error( 'mad4b_context_site_profile_unavailable', 'Site Profile service is unavailable.' );
		$status = MAD4B_SCP_Site_Profile::status();
		if ( empty( $status['configured'] ) || empty( $status['origin_match'] ) || empty( $status['environment_match'] ) ) return new WP_Error( 'mad4b_context_site_profile_not_enrolled', 'Context Authority requires an enrolled Site Profile with matching origin and environment.' );
		$site_uuid = isset( $status['site_uuid'] ) ? strtolower( trim( (string) $status['site_uuid'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return new WP_Error( 'mad4b_context_site_uuid_invalid', 'Site Profile does not expose a valid site UUID.' );
		return array( 'site_uuid' => $site_uuid, 'environment' => isset( $status['environment'] ) ? (string) $status['environment'] : '' );
	}

	private static function valid_profile( $record ) {
		if ( ! is_array( $record ) || self::PROFILE_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( empty( $record['site_uuid'] ) || ! preg_match( '/^[a-f0-9-]{36}$/', (string) $record['site_uuid'] ) ) return false;
		return ! empty( $record['brand_id'] ) && ! empty( $record['brand_name'] ) && ! empty( $record['revision'] );
	}

	private static function valid_source( $record ) {
		if ( ! is_array( $record ) || self::SOURCE_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		return ! empty( $record['source_id'] ) && ! empty( $record['site_uuid'] ) && ! empty( $record['external_root_id'] ) && in_array( isset( $record['mode'] ) ? $record['mode'] : '', array( 'governed', 'task_attachment' ), true );
	}

	private static function valid_asset( $record ) {
		if ( ! is_array( $record ) || self::ASSET_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		return ! empty( $record['asset_id'] ) && ! empty( $record['source_id'] ) && ! empty( $record['file_id'] );
	}

	private static function brand_id( $site_uuid, $brand_name ) {
		return substr( hash( 'sha256', strtolower( trim( (string) $site_uuid ) ) . '|' . strtolower( trim( (string) $brand_name ) ) ), 0, 32 );
	}

	private static function bounded_external_id( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $value ) ) return '';
		return $value;
	}

	private static function write_option( $name, $value ) {
		if ( false === get_option( $name, false ) ) return add_option( $name, $value, '', false );
		return update_option( $name, $value, false );
	}
}
