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
	const QUALITY_CONTRACT = 'mad4b.context-quality-score.v2';

	const PROFILE_OPTION = 'mad4b_scp_brand_context_profile_v1';
	const SOURCES_OPTION = 'mad4b_scp_context_sources_v1';
	const ASSETS_OPTION = 'mad4b_scp_context_assets_v1';
	const REGISTRY_REVISION_OPTION = 'mad4b_scp_context_registry_revision_v1';
	const REGISTRY_LOCK_OPTION = 'mad4b_scp_context_registry_lock_v1';
	const REGISTRY_LOCK_TTL = 45;

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
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		$record = get_option( self::PROFILE_OPTION, array() );
		if ( ! self::valid_profile( $record ) ) return array();
		$record_site_uuid = strtolower( trim( (string) $record['site_uuid'] ) );
		return hash_equals( (string) $site['site_uuid'], $record_site_uuid ) ? $record : array();
	}

	public static function sources() {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		$records = get_option( self::SOURCES_OPTION, array() );
		if ( ! is_array( $records ) ) return array();
		$out = array();
		foreach ( array_slice( $records, -self::MAX_SOURCES, self::MAX_SOURCES, true ) as $key => $record ) {
			if ( ! self::valid_source( $record ) ) continue;
			$record_site_uuid = strtolower( trim( (string) $record['site_uuid'] ) );
			if ( ! hash_equals( (string) $site['site_uuid'], $record_site_uuid ) ) continue;
			$policy = isset( $record['write_policy'] ) ? sanitize_key( (string) $record['write_policy'] ) : 'read_only';
			if ( ! in_array( $policy, array( 'read_only', 'repair_only', 'managed' ), true ) ) $policy = 'read_only';
			if ( 'task_attachment' === ( isset( $record['mode'] ) ? (string) $record['mode'] : '' ) ) $policy = 'read_only';
			$record['write_policy'] = $policy;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	public static function assets() {
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return array();
		$records = get_option( self::ASSETS_OPTION, array() );
		if ( ! is_array( $records ) ) return array();
		$out = array();
		foreach ( array_slice( $records, -self::MAX_ASSETS, self::MAX_ASSETS, true ) as $key => $record ) {
			if ( ! self::valid_asset( $record ) ) continue;
			$record_site_uuid = isset( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
			if ( '' === $record_site_uuid || ! hash_equals( (string) $site['site_uuid'], $record_site_uuid ) ) continue;
			$out[ (string) $key ] = $record;
		}
		return $out;
	}

	public static function categories() {
		return array(
			'brand_strategy' => 'Brand Strategy',
			'brand_positioning' => 'Brand Positioning',
			'audience_persona' => 'Audience / Persona',
			'tone_of_voice' => 'Tone of Voice',
			'messaging' => 'Messaging',
			'editorial_guidelines' => 'Editorial Guidelines',
			'terminology' => 'Terminology',
			'claim_policy' => 'Claim Policy',
			'seo_strategy' => 'SEO Strategy',
			'content_strategy' => 'Content Strategy',
			'campaign_strategy' => 'Campaign Strategy',
			'product_knowledge' => 'Product Knowledge',
			'service_knowledge' => 'Service Knowledge',
			'destination_knowledge' => 'Destination Knowledge',
			'market_research' => 'Market Research',
			'writer_reference' => 'Writer Reference',
			'content_example' => 'Content Example',
			'historical_content' => 'Historical Content',
			'legal_policy' => 'Legal Policy',
			'operational_policy' => 'Operational Policy',
			'uncategorized' => 'Uncategorized',
		);
	}

	public static function authority_classes() {
		return array(
			'brand_authority' => 'Brand Authority',
			'policy_authority' => 'Policy Authority',
			'task_knowledge' => 'Task Knowledge',
			'reference' => 'Reference',
		);
	}

	public static function write_policies() {
		return array(
			'read_only' => array(
				'label' => 'Read-only',
				'operations' => array(),
				'description' => 'Browse, scan, classify and score only.',
			),
			'repair_only' => array(
				'label' => 'Repair existing assets',
				'operations' => array( 'update', 'recreate' ),
				'description' => 'Update existing text assets and recreate assets confirmed unavailable; cannot create unrelated new assets.',
			),
			'managed' => array(
				'label' => 'Managed library',
				'operations' => array( 'create', 'update', 'recreate' ),
				'description' => 'Create, update and recreate governed assets inside this selected source folder.',
			),
		);
	}

	public static function source_write_policy( $source_id ) {
		$source = self::source( $source_id );
		return empty( $source ) ? 'read_only' : ( isset( $source['write_policy'] ) ? (string) $source['write_policy'] : 'read_only' );
	}

	public static function source_allows_write( $source_id, $operation ) {
		$source = self::source( $source_id );
		if ( empty( $source ) ) return false;
		if ( 'task_attachment' === ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) ) return false;
		$operation = sanitize_key( (string) $operation );
		$policy = isset( $source['write_policy'] ) ? sanitize_key( (string) $source['write_policy'] ) : 'read_only';
		$policies = self::write_policies();
		return isset( $policies[ $policy ] ) && in_array( $operation, $policies[ $policy ]['operations'], true );
	}

	public static function writable_source_count( $operation ) {
		$count = 0;
		foreach ( self::sources() as $source_id => $source ) if ( self::source_allows_write( $source_id, $operation ) ) ++$count;
		return $count;
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
		return self::with_registry_lock(
			'upsert_source',
			static function () use ( $input ) {
				$site = self::site_binding();
				if ( is_wp_error( $site ) ) return $site;
				$profile = self::profile();
				if ( empty( $profile ) ) return new WP_Error( 'mad4b_brand_context_profile_required', 'Configure the Brand Context Profile before adding sources.' );

				$provider = sanitize_key( isset( $input['provider'] ) ? $input['provider'] : '' );
				$mode = sanitize_key( isset( $input['mode'] ) ? $input['mode'] : 'governed' );
				if ( 'google_drive' !== $provider ) return new WP_Error( 'mad4b_context_source_provider_invalid', 'Only the governed Google Drive context provider is supported in this foundation.' );
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
				$write_policy = isset( $input['write_policy'] )
					? sanitize_key( (string) $input['write_policy'] )
					: ( isset( $current['write_policy'] ) ? sanitize_key( (string) $current['write_policy'] ) : ( 'governed' === $mode ? 'repair_only' : 'read_only' ) );
				if ( ! isset( self::write_policies()[ $write_policy ] ) ) return new WP_Error( 'mad4b_context_source_write_policy_invalid', 'Context source write policy is invalid.' );
				if ( 'task_attachment' === $mode && 'read_only' !== $write_policy ) return new WP_Error( 'mad4b_context_task_source_write_forbidden', 'Task-only Context sources are read-only in this release.' );

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
					'write_policy' => $write_policy,
					'recursive' => ! isset( $input['recursive'] ) || ! empty( $input['recursive'] ),
					'status' => isset( $current['status'] ) ? (string) $current['status'] : 'selected',
					'last_synced_at' => isset( $current['last_synced_at'] ) ? (string) $current['last_synced_at'] : '',
					'last_scan_complete' => ! empty( $current['last_scan_complete'] ),
					'last_scan_generation' => isset( $current['last_scan_generation'] ) ? (string) $current['last_scan_generation'] : '',
					'last_complete_scan_generation' => isset( $current['last_complete_scan_generation'] ) ? (string) $current['last_complete_scan_generation'] : '',
					'last_complete_scan_at' => isset( $current['last_complete_scan_at'] ) ? (string) $current['last_complete_scan_at'] : '',
					'last_scan_truncation_reasons' => isset( $current['last_scan_truncation_reasons'] ) && is_array( $current['last_scan_truncation_reasons'] ) ? $current['last_scan_truncation_reasons'] : array(),
					'asset_count' => isset( $current['asset_count'] ) ? absint( $current['asset_count'] ) : 0,
					'created_at' => isset( $current['created_at'] ) ? (string) $current['created_at'] : gmdate( 'c' ),
					'updated_at' => gmdate( 'c' ),
				);
				$sources[ $source_id ] = $record;
				if ( count( $sources ) > self::MAX_SOURCES ) $sources = array_slice( $sources, -self::MAX_SOURCES, self::MAX_SOURCES, true );
				if ( ! self::write_option( self::SOURCES_OPTION, $sources ) ) return new WP_Error( 'mad4b_context_source_registry_write_failed', 'Context source registry could not be persisted.' );
				return $record;
			}
		);
	}

	public static function replace_source_assets( $source_id, array $assets, array $scan = array() ) {
		return self::with_registry_lock(
			'replace_source_assets',
			static function () use ( $source_id, $assets, $scan ) {
				$source_id = strtolower( trim( (string) $source_id ) );
				$sources = self::sources();
				if ( ! isset( $sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
				$source = $sources[ $source_id ];
				$records = self::assets();
				$previous = array();
				$scan_started_at = isset( $scan['started_at'] ) ? sanitize_text_field( (string) $scan['started_at'] ) : gmdate( 'c' );
				$scan_completed_at = isset( $scan['completed_at'] ) ? sanitize_text_field( (string) $scan['completed_at'] ) : gmdate( 'c' );
				$scan_complete = ! array_key_exists( 'complete', $scan ) || ! empty( $scan['complete'] );
				$scan_generation = isset( $scan['scan_generation'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $scan['scan_generation'] )
					? strtolower( (string) $scan['scan_generation'] )
					: hash( 'sha256', $source_id . '|' . $scan_started_at . '|' . count( $assets ) );
				$truncation_reasons = isset( $scan['truncation_reasons'] ) && is_array( $scan['truncation_reasons'] )
					? array_values( array_unique( array_filter( array_map( 'sanitize_key', $scan['truncation_reasons'] ) ) ) )
					: array();

				foreach ( $records as $asset_id => $record ) {
					if ( ! isset( $record['source_id'] ) || ! hash_equals( $source_id, (string) $record['source_id'] ) ) continue;
					$previous[ (string) $asset_id ] = $record;
					if ( $scan_complete ) {
						$records[ $asset_id ]['status'] = 'unavailable';
						$records[ $asset_id ]['availability_reason'] = 'not_seen_in_complete_scan';
						$records[ $asset_id ]['last_missing_at'] = $scan_completed_at;
						$records[ $asset_id ]['absence_scan_generation'] = $scan_generation;
					}
				}

				$count = 0;
				foreach ( array_slice( $assets, 0, self::MAX_ASSETS ) as $asset ) {
					if ( ! is_array( $asset ) ) continue;
					$normalized = self::normalize_asset( $source, $asset );
					if ( is_wp_error( $normalized ) ) continue;
					$prior = isset( $previous[ $normalized['asset_id'] ] ) ? $previous[ $normalized['asset_id'] ] : array();
					if ( ! empty( $prior['reviewed_at'] ) && 'human' === ( isset( $prior['classification_source'] ) ? $prior['classification_source'] : '' ) ) {
						$normalized['category'] = isset( $prior['category'] ) ? (string) $prior['category'] : $normalized['category'];
						$normalized['classification_confidence'] = 1.0;
						$normalized['classification_source'] = 'human';
						$normalized['authority_class'] = isset( $prior['authority_class'] ) ? (string) $prior['authority_class'] : $normalized['authority_class'];
						$normalized['required'] = ! empty( $prior['required'] );
						$normalized['priority'] = isset( $prior['priority'] ) ? (int) $prior['priority'] : $normalized['priority'];
						$normalized['reviewed_by'] = isset( $prior['reviewed_by'] ) ? absint( $prior['reviewed_by'] ) : 0;
						$normalized['reviewed_at'] = (string) $prior['reviewed_at'];
						$normalized['review_status'] = 'approved';
						$same_content = ! empty( $prior['content_hash'] ) && hash_equals( (string) $prior['content_hash'], (string) $normalized['content_hash'] );
						if ( $same_content && ! empty( $prior['quality']['human_override'] ) ) {
							$normalized['quality_score'] = isset( $prior['quality_score'] ) ? (int) $prior['quality_score'] : $normalized['quality_score'];
							$normalized['quality'] = $prior['quality'];
						} elseif ( ! $same_content ) {
							$normalized['review_status'] = 'needs_review_content_changed';
						}
					}
					$normalized['availability_reason'] = '';
					$normalized['last_seen_at'] = $scan_completed_at;
					$normalized['last_missing_at'] = isset( $prior['last_missing_at'] ) ? (string) $prior['last_missing_at'] : '';
					unset( $normalized['absence_scan_generation'] );
					$records[ $normalized['asset_id'] ] = $normalized;
					++$count;
					if ( count( $records ) >= self::MAX_ASSETS ) break;
				}

				if ( ! self::write_option( self::ASSETS_OPTION, $records ) ) return new WP_Error( 'mad4b_context_asset_registry_write_failed', 'Context asset registry could not persist the source scan.' );
				$sources[ $source_id ]['status'] = $scan_complete ? 'ready' : 'partial_scan';
				$sources[ $source_id ]['last_synced_at'] = $scan_completed_at;
				$sources[ $source_id ]['last_scan_complete'] = (bool) $scan_complete;
				$sources[ $source_id ]['last_scan_generation'] = $scan_generation;
				$sources[ $source_id ]['last_scan_truncation_reasons'] = $truncation_reasons;
				if ( $scan_complete ) {
					$sources[ $source_id ]['last_complete_scan_generation'] = $scan_generation;
					$sources[ $source_id ]['last_complete_scan_at'] = $scan_completed_at;
				}
				$sources[ $source_id ]['asset_count'] = $count;
				$sources[ $source_id ]['updated_at'] = gmdate( 'c' );
				if ( ! self::write_option( self::SOURCES_OPTION, $sources ) ) return new WP_Error( 'mad4b_context_source_registry_write_failed', 'Context source scan state could not be persisted.' );

				$profile = self::profile();
				if ( ! empty( $profile ) ) {
					$profile['context_fingerprint'] = self::context_fingerprint( $records, $sources );
					$profile['authority_manifest_fingerprint'] = self::authority_manifest_fingerprint( $records );
					if ( $scan_complete ) $profile['last_verified_at'] = $scan_completed_at;
					$profile['status'] = $scan_complete ? 'indexed' : 'partial_index';
					$profile['updated_at'] = gmdate( 'c' );
					if ( ! self::write_option( self::PROFILE_OPTION, $profile ) ) return new WP_Error( 'mad4b_context_profile_write_failed', 'Brand Context Profile scan state could not be persisted.' );
				}
				return array(
					'source' => $sources[ $source_id ],
					'asset_count' => $count,
					'scan_complete' => (bool) $scan_complete,
					'scan_generation' => $scan_generation,
					'context_fingerprint' => self::context_fingerprint( $records, $sources ),
					'authority_manifest_fingerprint' => self::authority_manifest_fingerprint( $records ),
				);
			}
		);
	}

	public static function source( $source_id ) {
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$sources = self::sources();
		return isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : array();
	}

	public static function asset( $asset_id ) {
		$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
		$assets = self::assets();
		return isset( $assets[ $asset_id ] ) ? $assets[ $asset_id ] : array();
	}

	public static function upsert_asset_from_provider( $source_id, array $provider_asset, array $preserve = array() ) {
		return self::with_registry_lock(
			'upsert_asset_from_provider',
			static function () use ( $source_id, $provider_asset, $preserve ) {
				$source = self::source( $source_id );
				if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
				$normalized = self::normalize_asset( $source, $provider_asset );
				if ( is_wp_error( $normalized ) ) return $normalized;
				$records = self::assets();
				$existing = isset( $records[ $normalized['asset_id'] ] ) && is_array( $records[ $normalized['asset_id'] ] ) ? $records[ $normalized['asset_id'] ] : array();
				foreach ( array( 'category', 'classification_confidence', 'classification_source', 'authority_class', 'required', 'priority', 'reviewed_by', 'reviewed_at', 'review_status' ) as $field ) {
					if ( array_key_exists( $field, $preserve ) ) $normalized[ $field ] = $preserve[ $field ];
					elseif ( ! empty( $existing['reviewed_at'] ) && 'human' === ( isset( $existing['classification_source'] ) ? $existing['classification_source'] : '' ) && array_key_exists( $field, $existing ) ) $normalized[ $field ] = $existing[ $field ];
				}
				if ( ! empty( $existing['quality']['human_override'] ) && isset( $existing['content_hash'] ) && hash_equals( (string) $existing['content_hash'], (string) $normalized['content_hash'] ) ) {
					$normalized['quality_score'] = isset( $existing['quality_score'] ) ? (int) $existing['quality_score'] : $normalized['quality_score'];
					$normalized['quality'] = $existing['quality'];
				}
				$normalized['availability_reason'] = '';
				$normalized['last_seen_at'] = gmdate( 'c' );
				$records[ $normalized['asset_id'] ] = $normalized;
				if ( ! self::write_option( self::ASSETS_OPTION, $records ) ) return new WP_Error( 'mad4b_context_asset_registry_write_failed', 'Context asset registry could not persist the provider readback.' );
				self::refresh_profile_fingerprint( $records, self::sources() );
				return $normalized;
			}
		);
	}

	public static function register_recreated_asset( $old_asset_id, $source_id, array $provider_asset, array $preserve = array() ) {
		return self::with_registry_lock(
			'register_recreated_asset',
			static function () use ( $old_asset_id, $source_id, $provider_asset, $preserve ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
				$source = self::source( $source_id );
				if ( empty( $source ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found for recreation.' );
				$records = self::assets();
				if ( ! isset( $records[ $old_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_original_missing', 'Original Context asset is missing from the registry.' );
				$original = $records[ $old_asset_id ];
				if ( ! hash_equals( (string) $original['source_id'], $source_id ) ) return new WP_Error( 'mad4b_context_recreate_source_mismatch', 'Original Context asset is not bound to the requested source.' );
				if ( 'unavailable' !== ( isset( $original['status'] ) ? (string) $original['status'] : '' ) ) return new WP_Error( 'mad4b_context_recreate_original_not_unavailable', 'Only an unavailable Context asset can be atomically replaced.' );

				$normalized = self::normalize_asset( $source, $provider_asset );
				if ( is_wp_error( $normalized ) ) return $normalized;
				if ( $old_asset_id === (string) $normalized['asset_id'] ) return new WP_Error( 'mad4b_context_recreate_identity_collision', 'Recreated provider asset unexpectedly reused the unavailable asset identity.' );
				foreach ( array( 'category', 'classification_confidence', 'classification_source', 'authority_class', 'required', 'priority', 'reviewed_by', 'reviewed_at', 'review_status' ) as $field ) {
					if ( array_key_exists( $field, $preserve ) ) $normalized[ $field ] = $preserve[ $field ];
					elseif ( array_key_exists( $field, $original ) ) $normalized[ $field ] = $original[ $field ];
				}
				$normalized['availability_reason'] = '';
				$normalized['last_seen_at'] = gmdate( 'c' );

				$records[ $old_asset_id ]['status'] = 'recreated';
				$records[ $old_asset_id ]['availability_reason'] = 'replacement_created';
				$records[ $old_asset_id ]['replacement_asset_id'] = (string) $normalized['asset_id'];
				$records[ $old_asset_id ]['recreated_at'] = gmdate( 'c' );
				$records[ $normalized['asset_id'] ] = $normalized;
				if ( ! self::write_option( self::ASSETS_OPTION, $records ) ) return new WP_Error( 'mad4b_context_recreate_registry_commit_failed', 'Context registry could not atomically bind the recreated replacement.' );
				self::refresh_profile_fingerprint( $records, self::sources() );
				if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/context-recreated-asset-registered', array( 'asset_id' => $old_asset_id, 'replacement_asset_id' => (string) $normalized['asset_id'], 'source_id' => $source_id, 'file_id' => (string) $normalized['file_id'] ), 'ok' );
				return array( 'original' => $records[ $old_asset_id ], 'replacement' => $normalized );
			}
		);
	}

	public static function mark_asset_recreated( $old_asset_id, array $new_asset ) {
		$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
		$records = self::assets();
		if ( isset( $records[ $old_asset_id ] ) ) {
			$records[ $old_asset_id ]['status'] = 'recreated';
			$records[ $old_asset_id ]['availability_reason'] = 'replacement_created';
			$records[ $old_asset_id ]['replacement_asset_id'] = isset( $new_asset['asset_id'] ) ? (string) $new_asset['asset_id'] : '';
			$records[ $old_asset_id ]['recreated_at'] = gmdate( 'c' );
		}
		if ( ! empty( $new_asset['asset_id'] ) ) $records[ (string) $new_asset['asset_id'] ] = $new_asset;
		$written = self::write_option( self::ASSETS_OPTION, $records );
		if ( false === $written ) return new WP_Error( 'mad4b_context_recreate_registry_write_failed', 'Context registry could not persist recreated-asset lineage.' );
		self::refresh_profile_fingerprint( $records, self::sources() );
		return isset( $records[ $old_asset_id ] ) ? $records[ $old_asset_id ] : array();
	}

	public static function rollback_recreated_asset( $old_asset_id, $replacement_asset_id, array $before_state ) {
		return self::with_registry_lock(
			'rollback_recreated_asset',
			static function () use ( $old_asset_id, $replacement_asset_id, $before_state ) {
				$old_asset_id = strtolower( trim( sanitize_text_field( (string) $old_asset_id ) ) );
				$replacement_asset_id = strtolower( trim( sanitize_text_field( (string) $replacement_asset_id ) ) );
				$records = self::assets();
				if ( ! isset( $records[ $old_asset_id ] ) || ! isset( $records[ $replacement_asset_id ] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_missing', 'Recreate rollback requires both original and replacement registry records.' );
				$current = $records[ $old_asset_id ];
				if ( ! in_array( isset( $current['status'] ) ? (string) $current['status'] : '', array( 'recreated', 'rollback_pending' ), true ) ) return new WP_Error( 'mad4b_context_recreate_rollback_status_drift', 'Original Context asset is no longer in a rollback-compatible recreated state.' );
				if ( empty( $current['replacement_asset_id'] ) || ! hash_equals( (string) $current['replacement_asset_id'], $replacement_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_binding_drift', 'Original Context asset no longer points to the recorded replacement.' );
				if ( empty( $before_state['asset_id'] ) || ! hash_equals( (string) $before_state['asset_id'], $old_asset_id ) ) return new WP_Error( 'mad4b_context_recreate_rollback_before_mismatch', 'Rollback state is not bound to the original Context asset.' );
				if ( empty( $before_state['source_id'] ) || ! hash_equals( (string) $before_state['source_id'], (string) $current['source_id'] ) ) return new WP_Error( 'mad4b_context_recreate_rollback_source_mismatch', 'Rollback state is not bound to the original Context source.' );

				unset( $records[ $replacement_asset_id ] );
				$records[ $old_asset_id ]['status'] = isset( $before_state['status'] ) ? sanitize_key( (string) $before_state['status'] ) : 'unavailable';
				$records[ $old_asset_id ]['availability_reason'] = isset( $before_state['availability_reason'] ) ? sanitize_key( (string) $before_state['availability_reason'] ) : 'not_seen_in_complete_scan';
				if ( isset( $before_state['absence_scan_generation'] ) ) $records[ $old_asset_id ]['absence_scan_generation'] = (string) $before_state['absence_scan_generation'];
				unset( $records[ $old_asset_id ]['replacement_asset_id'], $records[ $old_asset_id ]['recreated_at'], $records[ $old_asset_id ]['rollback_started_at'] );
				if ( ! self::write_option( self::ASSETS_OPTION, $records ) ) return new WP_Error( 'mad4b_context_recreate_rollback_registry_write_failed', 'Replacement file was removed but Context registry rollback could not be persisted.' );
				self::refresh_profile_fingerprint( $records, self::sources() );
				if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/context-recreate-rollback', array( 'asset_id' => $old_asset_id, 'replacement_asset_id' => $replacement_asset_id, 'source_id' => (string) $before_state['source_id'], 'restored_status' => (string) $records[ $old_asset_id ]['status'] ), 'ok' );
				return $records[ $old_asset_id ];
			}
		);
	}

	private static function refresh_profile_fingerprint( array $assets, array $sources ) {
		$profile = self::profile();
		if ( empty( $profile ) ) return;
		$profile['context_fingerprint'] = self::context_fingerprint( $assets, $sources );
		$profile['authority_manifest_fingerprint'] = self::authority_manifest_fingerprint( $assets );
		$profile['last_verified_at'] = gmdate( 'c' );
		$profile['updated_at'] = gmdate( 'c' );
		self::write_option( self::PROFILE_OPTION, $profile );
	}

	public static function update_source_write_policy( $source_id, $write_policy ) {
		$audit_ready = self::audit_preflight();
		if ( is_wp_error( $audit_ready ) ) return $audit_ready;
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$write_policy = sanitize_key( (string) $write_policy );
		$sources = self::sources();
		if ( ! isset( $sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		$source = $sources[ $source_id ];
		if ( ! hash_equals( (string) $site['site_uuid'], (string) $source['site_uuid'] ) ) return new WP_Error( 'mad4b_context_source_site_mismatch', 'Context source is not bound to this Site Profile.' );
		if ( ! isset( self::write_policies()[ $write_policy ] ) ) return new WP_Error( 'mad4b_context_source_write_policy_invalid', 'Context source write policy is invalid.' );
		if ( 'task_attachment' === ( isset( $source['mode'] ) ? (string) $source['mode'] : '' ) && 'read_only' !== $write_policy ) return new WP_Error( 'mad4b_context_task_source_write_forbidden', 'Task-only Context sources are read-only in this release.' );
		$previous = isset( $source['write_policy'] ) ? (string) $source['write_policy'] : 'read_only';
		$sources[ $source_id ]['write_policy'] = $write_policy;
		$sources[ $source_id ]['updated_at'] = gmdate( 'c' );
		self::write_option( self::SOURCES_OPTION, $sources );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'mad4b/context-source-write-policy',
				array(
					'source_id' => $source_id,
					'provider' => isset( $source['provider'] ) ? (string) $source['provider'] : '',
					'mode' => isset( $source['mode'] ) ? (string) $source['mode'] : '',
					'previous_write_policy' => $previous,
					'write_policy' => $write_policy,
				),
				'ok'
			);
		}
		return $sources[ $source_id ];
	}

	public static function remove_source( $source_id ) {
		$audit_ready = self::audit_preflight();
		if ( is_wp_error( $audit_ready ) ) return $audit_ready;
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$source_id = strtolower( trim( sanitize_text_field( (string) $source_id ) ) );
		$sources = self::sources();
		if ( ! isset( $sources[ $source_id ] ) ) return new WP_Error( 'mad4b_context_source_not_found', 'Context source was not found.' );
		$source = $sources[ $source_id ];
		if ( ! hash_equals( (string) $site['site_uuid'], (string) $source['site_uuid'] ) ) return new WP_Error( 'mad4b_context_source_site_mismatch', 'Context source is not bound to this Site Profile.' );
		$assets = self::assets();
		$removed_assets = 0;
		foreach ( $assets as $asset_id => $asset ) {
			if ( isset( $asset['source_id'] ) && hash_equals( $source_id, (string) $asset['source_id'] ) ) {
				unset( $assets[ $asset_id ] );
				++$removed_assets;
			}
		}
		unset( $sources[ $source_id ] );
		self::write_option( self::ASSETS_OPTION, $assets );
		self::write_option( self::SOURCES_OPTION, $sources );
		$profile = self::profile();
		if ( ! empty( $profile ) ) {
			$profile['context_fingerprint'] = self::context_fingerprint( $assets, $sources );
			$profile['last_verified_at'] = gmdate( 'c' );
			$profile['updated_at'] = gmdate( 'c' );
			self::write_option( self::PROFILE_OPTION, $profile );
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'mad4b/context-source-remove',
				array(
					'source_id' => $source_id,
					'provider' => isset( $source['provider'] ) ? (string) $source['provider'] : '',
					'mode' => isset( $source['mode'] ) ? (string) $source['mode'] : '',
					'removed_asset_count' => $removed_assets,
				)
			);
		}
		return array( 'source_id' => $source_id, 'removed_asset_count' => $removed_assets, 'context_fingerprint' => self::context_fingerprint( $assets, $sources ) );
	}

	public static function review_asset( $asset_id, array $input ) {
		$audit_ready = self::audit_preflight();
		if ( is_wp_error( $audit_ready ) ) return $audit_ready;
		$site = self::site_binding();
		if ( is_wp_error( $site ) ) return $site;
		$asset_id = strtolower( trim( sanitize_text_field( (string) $asset_id ) ) );
		$records = self::assets();
		if ( ! isset( $records[ $asset_id ] ) ) return new WP_Error( 'mad4b_context_asset_not_found', 'Context asset was not found.' );
		$asset = $records[ $asset_id ];
		if ( ! hash_equals( (string) $site['site_uuid'], (string) $asset['site_uuid'] ) ) return new WP_Error( 'mad4b_context_asset_site_mismatch', 'Context asset is not bound to this Site Profile.' );

		$categories = self::categories();
		$authorities = self::authority_classes();
		$category = sanitize_key( isset( $input['category'] ) ? $input['category'] : '' );
		$authority = sanitize_key( isset( $input['authority_class'] ) ? $input['authority_class'] : '' );
		if ( ! isset( $categories[ $category ] ) ) return new WP_Error( 'mad4b_context_category_invalid', 'Context category is invalid.' );
		if ( ! isset( $authorities[ $authority ] ) ) return new WP_Error( 'mad4b_context_authority_class_invalid', 'Context authority class is invalid.' );

		$asset['category'] = $category;
		$asset['classification_confidence'] = 1.0;
		$asset['classification_source'] = 'human';
		$asset['authority_class'] = $authority;
		$asset['required'] = ! empty( $input['required'] );
		$asset['priority'] = 'brand_authority' === $authority || 'policy_authority' === $authority ? 100 : ( 'task_knowledge' === $authority ? 70 : 40 );
		$quality_input = isset( $input['quality_score'] ) ? trim( (string) $input['quality_score'] ) : '';
		if ( '' !== $quality_input ) {
			if ( ! preg_match( '/^\d{1,3}$/', $quality_input ) || (int) $quality_input < 0 || (int) $quality_input > 100 ) return new WP_Error( 'mad4b_context_quality_score_invalid', 'Quality score must be between 0 and 100.' );
			$automatic = isset( $asset['quality_auto_score'] ) ? (int) $asset['quality_auto_score'] : ( isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null );
			$asset['quality_score'] = (int) $quality_input;
			if ( ! isset( $asset['quality'] ) || ! is_array( $asset['quality'] ) ) $asset['quality'] = array( 'contract' => self::QUALITY_CONTRACT );
			$asset['quality']['automatic_score'] = $automatic;
			$asset['quality']['overall_score'] = (int) $quality_input;
			$asset['quality']['mode'] = 'human_override';
			$asset['quality']['human_override'] = true;
			$asset['quality']['provisional'] = false;
		}
		$asset['reviewed_by'] = get_current_user_id();
		$asset['reviewed_at'] = gmdate( 'c' );
		$asset['review_status'] = 'approved';
		$records[ $asset_id ] = $asset;
		self::write_option( self::ASSETS_OPTION, $records );

		$profile = self::profile();
		if ( ! empty( $profile ) ) {
			$profile['context_fingerprint'] = self::context_fingerprint( $records, self::sources() );
			$profile['last_verified_at'] = gmdate( 'c' );
			$profile['updated_at'] = gmdate( 'c' );
			self::write_option( self::PROFILE_OPTION, $profile );
		}
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record(
				'mad4b/context-asset-review',
				array(
					'asset_id' => $asset_id,
					'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
					'category' => $category,
					'authority_class' => $authority,
					'required' => ! empty( $asset['required'] ),
					'quality_score' => isset( $asset['quality_score'] ) ? (int) $asset['quality_score'] : null,
					'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				)
			);
		}
		return $asset;
	}

	public static function status() {
		$site_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$profile = self::profile();
		$sources = self::sources();
		$assets = self::assets();
		$governed_sources = array();
		$task_sources = array();
		$partial_sources = 0;
		foreach ( $sources as $source ) {
			if ( 'governed' === $source['mode'] ) {
				$governed_sources[] = $source;
				if ( 'partial_scan' === ( isset( $source['status'] ) ? (string) $source['status'] : '' ) || empty( $source['last_scan_complete'] ) ) ++$partial_sources;
			} else $task_sources[] = $source;
		}
		$governed_assets = array();
		$required = array();
		$quality_values = array();
		$stale = 0;
		$conflicting = 0;
		$unavailable = 0;
		$incomplete = 0;
		foreach ( $assets as $asset ) {
			if ( 'governed' !== $asset['source_mode'] ) continue;
			$governed_assets[] = $asset;
			if ( ! empty( $asset['required'] ) ) $required[] = $asset;
			if ( null !== $asset['quality_score'] ) $quality_values[] = (int) $asset['quality_score'];
			if ( 'stale' === $asset['status'] ) ++$stale;
			if ( 'conflicting' === $asset['status'] ) ++$conflicting;
			if ( 'unavailable' === $asset['status'] ) ++$unavailable;
			if ( 'incomplete' === $asset['status'] || ( array_key_exists( 'content_complete', $asset ) && empty( $asset['content_complete'] ) ) ) ++$incomplete;
		}
		$ready_required = 0;
		$approved_required = 0;
		foreach ( $required as $asset ) {
			$content_complete = ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] );
			if ( 'ready' === $asset['status'] && $content_complete ) ++$ready_required;
			if ( 'ready' === $asset['status'] && $content_complete && 'approved' === ( isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '' ) ) ++$approved_required;
		}
		$blockers = array();
		if ( empty( $site_status['configured'] ) || empty( $site_status['origin_match'] ) || empty( $site_status['environment_match'] ) ) $blockers[] = 'site_profile_not_enrolled';
		if ( empty( $profile ) ) $blockers[] = 'brand_context_profile_unconfigured';
		if ( empty( $governed_sources ) ) $blockers[] = 'governed_context_source_missing';
		if ( $partial_sources > 0 ) $blockers[] = 'governed_context_source_scan_incomplete';
		if ( empty( $governed_assets ) ) $blockers[] = 'governed_context_assets_missing';
		if ( ! empty( $governed_assets ) && empty( $required ) ) $blockers[] = 'mandatory_context_unclassified';
		if ( count( $required ) !== $ready_required ) $blockers[] = 'mandatory_context_not_ready';
		if ( count( $required ) !== $approved_required ) $blockers[] = 'mandatory_context_review_required';
		if ( $stale > 0 ) $blockers[] = 'brand_context_contains_stale_assets';
		if ( $unavailable > 0 ) $blockers[] = 'brand_context_contains_unavailable_assets';
		if ( $conflicting > 0 ) $blockers[] = 'mandatory_context_conflict';
		if ( $incomplete > 0 ) $blockers[] = 'brand_context_contains_incomplete_assets';

		return array(
			'contract' => 'mad4b.context-authority.v2',
			'ready' => empty( $blockers ),
			'state' => empty( $blockers ) ? 'ready' : ( empty( $profile ) ? 'unconfigured' : 'blocked' ),
			'site_uuid' => isset( $site_status['site_uuid'] ) ? (string) $site_status['site_uuid'] : '',
			'brand_id' => isset( $profile['brand_id'] ) ? (string) $profile['brand_id'] : '',
			'brand_name' => isset( $profile['brand_name'] ) ? (string) $profile['brand_name'] : '',
			'profile_revision' => isset( $profile['revision'] ) ? absint( $profile['revision'] ) : 0,
			'registry_revision' => self::registry_revision(),
			'context_fingerprint' => self::context_fingerprint( $assets, $sources ),
			'authority_manifest_fingerprint' => self::authority_manifest_fingerprint( $assets ),
			'governed_source_count' => count( $governed_sources ),
			'task_source_count' => count( $task_sources ),
			'partial_source_count' => $partial_sources,
			'asset_count' => count( $assets ),
			'governed_asset_count' => count( $governed_assets ),
			'required_asset_count' => count( $required ),
			'ready_required_asset_count' => $ready_required,
			'approved_required_asset_count' => $approved_required,
			'stale_asset_count' => $stale,
			'unavailable_asset_count' => $unavailable,
			'incomplete_asset_count' => $incomplete,
			'conflicting_asset_count' => $conflicting,
			'average_quality_score' => $quality_values ? (int) round( array_sum( $quality_values ) / count( $quality_values ) ) : null,
			'quality_scored_asset_count' => count( $quality_values ),
			'blockers' => array_values( array_unique( $blockers ) ),
		);
	}

	public static function classify_asset( $name, $path = '', $content = '' ) {
		$haystack = strtolower( trim( (string) $name . ' ' . (string) $path . ' ' . substr( (string) $content, 0, 6000 ) ) );
		$rules = array(
			 'brand_strategy' => array( 'brand strategy', 'brand core', 'brand plan', 'استراتيجية العلامة', 'استراتيجية البراند', 'جوهر العلامة' ),
			 'brand_positioning' => array( 'positioning', 'brand position', 'تموضع العلامة', 'التموضع' ),
			 'audience_persona' => array( 'persona', 'audience', 'customer profile', 'buyer profile', 'الجمهور', 'شخصية العميل', 'العميل المثالي' ),
			 'tone_of_voice' => array( 'tone of voice', 'tone-of-voice', 'brand voice', 'tov', 'نبرة الصوت', 'نبرة العلامة', 'أسلوب الكتابة' ),
			'messaging' => array( 'messaging', 'message framework', 'key messages' ),
			 'editorial_guidelines' => array( 'editorial', 'writing guideline', 'style guide', 'content guideline', 'دليل التحرير', 'إرشادات الكتابة', 'قواعد المحتوى' ),
			 'terminology' => array( 'terminology', 'naming rule', 'glossary', 'vocabulary', 'المصطلحات', 'قاموس', 'التسمية' ),
			'claim_policy' => array( 'prohibited claim', 'claim policy', 'restriction', 'legal claim' ),
			 'seo_strategy' => array( 'seo', 'search strategy', 'keyword strategy', 'استراتيجية السيو', 'الكلمات المفتاحية', 'تحسين محركات البحث' ),
			'content_strategy' => array( 'content strategy', 'blog strategy', 'content pillar' ),
			'campaign_strategy' => array( 'campaign plan', 'campaign strategy' ),
			'product_knowledge' => array( 'product knowledge', 'product guide', 'product catalog' ),
			'service_knowledge' => array( 'service knowledge', 'service guide', 'services' ),
			'destination_knowledge' => array( 'destination guide', 'destination knowledge', 'travel guide' ),
			 'market_research' => array( 'market research', 'market report', 'research report', 'market insight', 'بحث السوق', 'دراسة السوق', 'تقرير السوق' ),
			 'writer_reference' => array( 'writer reference', 'author reference', 'journalist', 'writing sample', 'style profile', 'مرجع كاتب', 'نموذج كتابة', 'أسلوب الكاتب' ),
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
		$paragraphs = '' === $content ? array() : array_values( array_filter( preg_split( '/\R{2,}/u', $content ) ) );
		$paragraph_count = count( $paragraphs );
		$category = isset( $metadata['category'] ) ? sanitize_key( (string) $metadata['category'] ) : 'uncategorized';
		$profile = self::quality_profile_for_category( $category );
		$target_words = self::quality_target_words( $profile );

		$modified = isset( $metadata['modifiedTime'] ) ? strtotime( (string) $metadata['modifiedTime'] ) : false;
		$age_days = false === $modified ? null : max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) );
		$freshness = null === $age_days ? 50 : ( $age_days <= 90 ? 100 : ( $age_days <= 365 ? 85 : ( $age_days <= 730 ? 65 : 45 ) ) );
		$extractability = '' !== $content ? 100 : 45;

		$metadata_signals = 0;
		foreach ( array( 'file_id', 'mimeType', 'modifiedTime', 'webViewLink' ) as $field ) if ( ! empty( $metadata[ $field ] ) ) ++$metadata_signals;
		$source_quality = min( 100, 55 + ( $metadata_signals * 10 ) + ( ! empty( $metadata['classification_confidence'] ) ? 5 : 0 ) );

		$reasons = array();
		if ( null === $age_days ) $reasons[] = 'freshness_unknown';
		elseif ( $age_days <= 90 ) $reasons[] = 'recent_source';
		elseif ( $age_days > 730 ) $reasons[] = 'source_older_than_two_years';

		if ( '' === $content ) {
			$overall = (int) round( $freshness * 0.35 + $source_quality * 0.35 + $extractability * 0.30 );
			$reasons[] = 'metadata_only_no_normalized_text';
			return array(
				'contract' => self::QUALITY_CONTRACT,
				'profile' => $profile,
				'overall_score' => max( 0, min( 100, $overall ) ),
				'confidence' => 0.45,
				'mode' => 'metadata_provisional',
				'provisional' => true,
				'word_count' => 0,
				'paragraph_count' => 0,
				'weights' => array( 'freshness' => 0.35, 'source_quality' => 0.35, 'extractability' => 0.30 ),
				'dimensions' => array(
					'freshness' => $freshness,
					'completeness' => null,
					'structure' => null,
					'specificity' => null,
					'source_quality' => $source_quality,
					'language_quality' => null,
					'retrieval_quality' => null,
					'extractability' => $extractability,
				),
				'reasons' => $reasons,
			);
		}

		$ratio = $target_words > 0 ? min( 1, $word_count / $target_words ) : 1;
		$completeness = (int) round( 35 + ( $ratio * 65 ) );
		if ( $ratio < 0.35 ) $reasons[] = 'content_short_for_quality_profile';
		elseif ( $ratio >= 0.85 ) $reasons[] = 'content_depth_matches_quality_profile';

		$heading_count = preg_match_all( '/(^|\R)\s*#{1,6}\s+|(^|\R)\s*[^\r\n]{2,80}:\s*(?=\R|$)/um', $content, $unused );
		$bullet_count = preg_match_all( '/(^|\R)\s*(?:[-*•]|\d+[.)])\s+/u', $content, $unused );
		$table_signal = preg_match( '/\|[^\r\n]+\|/u', $content ) ? 1 : 0;
		$structure = min( 100, 42 + min( 28, $paragraph_count * 4 ) + min( 15, $heading_count * 5 ) + min( 10, $bullet_count * 2 ) + ( $table_signal ? 5 : 0 ) );
		if ( $structure >= 80 ) $reasons[] = 'well_structured_for_retrieval';
		elseif ( $structure < 55 ) $reasons[] = 'weak_document_structure';

		$number_signals = preg_match_all( '/\p{N}+(?:[.,]\p{N}+)?%?/u', $content, $unused );
		$reference_signals = preg_match_all( '/https?:\/\/|\[[0-9]+\]|\([^\)]{2,80},\s*20[0-9]{2}\)/u', $content, $unused );
		$list_signals = min( 5, $bullet_count );
		$specificity = min( 100, 45 + min( 30, $number_signals * 3 ) + min( 15, $reference_signals * 5 ) + ( $list_signals * 2 ) );
		if ( $specificity >= 75 ) $reasons[] = 'strong_specificity_signals';
		elseif ( $specificity < 55 ) $reasons[] = 'limited_specificity_signals';

		$sentence_count = max( 1, preg_match_all( '/[.!?؟]+(?:\s|$)/u', $content, $unused ) );
		$long_paragraphs = 0;
		foreach ( $paragraphs as $paragraph ) {
			$words = count( preg_split( '/\s+/u', trim( $paragraph ), -1, PREG_SPLIT_NO_EMPTY ) );
			if ( $words > 160 ) ++$long_paragraphs;
		}
		$language_quality = 82;
		if ( $word_count < 80 ) $language_quality -= 12;
		if ( $sentence_count < 3 && $word_count > 150 ) $language_quality -= 10;
		$language_quality -= min( 20, $long_paragraphs * 5 );
		if ( preg_match( '/([!?؟.,])\1{2,}/u', $content ) ) $language_quality -= 8;
		$language_quality = max( 35, min( 100, $language_quality ) );
		if ( $long_paragraphs > 0 ) $reasons[] = 'very_long_paragraphs_reduce_readability';

		$retrieval_quality = min( 100, 45 + min( 25, $paragraph_count * 4 ) + min( 15, $heading_count * 5 ) + ( $word_count >= 200 ? 10 : 0 ) + ( $extractability >= 100 ? 5 : 0 ) );
		if ( $retrieval_quality >= 80 ) $reasons[] = 'high_retrieval_readiness';

		$dimensions = array(
			'freshness' => $freshness,
			'completeness' => $completeness,
			'structure' => $structure,
			'specificity' => $specificity,
			'source_quality' => $source_quality,
			'language_quality' => $language_quality,
			'retrieval_quality' => $retrieval_quality,
			'extractability' => $extractability,
		);
		$weights = self::quality_weights( $profile );
		$overall = 0.0;
		foreach ( $weights as $dimension => $weight ) $overall += ( isset( $dimensions[ $dimension ] ) ? (float) $dimensions[ $dimension ] : 0.0 ) * (float) $weight;
		$confidence = min( 0.96, 0.62 + ( min( 1, $ratio ) * 0.22 ) + ( min( 1, $paragraph_count / 5 ) * 0.08 ) + ( $metadata_signals >= 3 ? 0.04 : 0 ) );

		return array(
			'contract' => self::QUALITY_CONTRACT,
			'profile' => $profile,
			'overall_score' => max( 0, min( 100, (int) round( $overall ) ) ),
			'confidence' => round( $confidence, 2 ),
			'mode' => 'content_heuristic_v2',
			'provisional' => false,
			'word_count' => $word_count,
			'paragraph_count' => $paragraph_count,
			'target_word_count' => $target_words,
			'weights' => $weights,
			'dimensions' => $dimensions,
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	private static function quality_profile_for_category( $category ) {
		$category = sanitize_key( (string) $category );
		if ( in_array( $category, array( 'brand_strategy', 'brand_positioning', 'audience_persona', 'tone_of_voice', 'messaging', 'editorial_guidelines', 'terminology', 'claim_policy', 'legal_policy', 'operational_policy' ), true ) ) return 'brand_policy';
		if ( 'market_research' === $category ) return 'market_research';
		if ( in_array( $category, array( 'writer_reference', 'content_example', 'historical_content' ), true ) ) return 'writer_reference';
		if ( in_array( $category, array( 'seo_strategy', 'content_strategy', 'campaign_strategy', 'product_knowledge', 'service_knowledge', 'destination_knowledge' ), true ) ) return 'knowledge';
		return 'generic';
	}

	private static function quality_target_words( $profile ) {
		$targets = array(
			'brand_policy' => 300,
			'knowledge' => 600,
			'market_research' => 900,
			'writer_reference' => 700,
			'generic' => 400,
		);
		return isset( $targets[ $profile ] ) ? (int) $targets[ $profile ] : 400;
	}

	private static function quality_weights( $profile ) {
		$profiles = array(
			'brand_policy' => array( 'freshness' => 0.12, 'completeness' => 0.18, 'structure' => 0.10, 'specificity' => 0.18, 'source_quality' => 0.14, 'language_quality' => 0.10, 'retrieval_quality' => 0.10, 'extractability' => 0.08 ),
			'knowledge' => array( 'freshness' => 0.15, 'completeness' => 0.20, 'structure' => 0.10, 'specificity' => 0.18, 'source_quality' => 0.14, 'language_quality' => 0.08, 'retrieval_quality' => 0.10, 'extractability' => 0.05 ),
			'market_research' => array( 'freshness' => 0.22, 'completeness' => 0.17, 'structure' => 0.08, 'specificity' => 0.22, 'source_quality' => 0.17, 'language_quality' => 0.04, 'retrieval_quality' => 0.06, 'extractability' => 0.04 ),
			'writer_reference' => array( 'freshness' => 0.05, 'completeness' => 0.15, 'structure' => 0.18, 'specificity' => 0.12, 'source_quality' => 0.10, 'language_quality' => 0.25, 'retrieval_quality' => 0.10, 'extractability' => 0.05 ),
			'generic' => array( 'freshness' => 0.15, 'completeness' => 0.20, 'structure' => 0.15, 'specificity' => 0.15, 'source_quality' => 0.15, 'language_quality' => 0.08, 'retrieval_quality' => 0.08, 'extractability' => 0.04 ),
		);
		return isset( $profiles[ $profile ] ) ? $profiles[ $profile ] : $profiles['generic'];
	}

	public static function context_fingerprint( $assets = null, $sources = null ) {
		if ( null === $assets ) $assets = self::assets();
		if ( null === $sources ) $sources = self::sources();
		$rows = array();
		foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? $asset['source_mode'] : '' ) ) continue;
			$rows[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'source_id' => isset( $asset['source_id'] ) ? (string) $asset['source_id'] : '',
				'version' => isset( $asset['version'] ) ? (string) $asset['version'] : '',
				'content_hash' => isset( $asset['content_hash'] ) ? (string) $asset['content_hash'] : '',
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
				'normalization_status' => isset( $asset['normalization_status'] ) ? (string) $asset['normalization_status'] : '',
				'parent_folder_id' => isset( $asset['parent_folder_id'] ) ? (string) $asset['parent_folder_id'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '',
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
		$parent_folder_id = self::bounded_external_id( isset( $asset['parent_folder_id'] ) ? $asset['parent_folder_id'] : '' );
		if ( '' === $parent_folder_id && ! empty( $asset['parents'] ) && is_array( $asset['parents'] ) ) $parent_folder_id = self::bounded_external_id( (string) reset( $asset['parents'] ) );
		$title = trim( sanitize_text_field( isset( $asset['title'] ) ? $asset['title'] : '' ) );
		if ( '' === $title ) $title = 'Untitled';
		$content_complete = ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] );
		$content = $content_complete && isset( $asset['normalized_text'] ) ? (string) $asset['normalized_text'] : '';
		$classification = self::classify_asset( $title, isset( $asset['path'] ) ? $asset['path'] : '', $content );
		$metadata = $asset;
		$metadata['category'] = $classification['category'];
		$metadata['classification_confidence'] = $classification['classification_confidence'];
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
			'parent_folder_id' => $parent_folder_id,
			'title' => $title,
			'path' => isset( $asset['path'] ) ? substr( sanitize_text_field( (string) $asset['path'] ), 0, 500 ) : '',
			'mime_type' => isset( $asset['mimeType'] ) ? substr( sanitize_text_field( (string) $asset['mimeType'] ), 0, 191 ) : '',
			'version' => isset( $asset['modifiedTime'] ) ? sanitize_text_field( (string) $asset['modifiedTime'] ) : '',
			'content_hash' => $content_hash,
			'content_complete' => (bool) $content_complete,
			'content_bytes' => isset( $asset['content_bytes'] ) ? max( 0, (int) $asset['content_bytes'] ) : strlen( $content ),
			'normalization_status' => isset( $asset['normalization_status'] ) ? sanitize_key( (string) $asset['normalization_status'] ) : ( $content_complete ? 'ready' : 'incomplete' ),
			'normalization_reason' => isset( $asset['normalization_reason'] ) ? sanitize_key( (string) $asset['normalization_reason'] ) : '',
			'content_available' => $content_complete && '' !== $content,
			'content_excerpt' => $content_complete && '' !== $content ? wp_trim_words( wp_strip_all_tags( $content ), 45, '…' ) : '',
			'category' => $classification['category'],
			'classification_confidence' => $classification['classification_confidence'],
			'classification_source' => $classification['classification_source'],
			'authority_class' => $classification['authority_class'],
			'required' => $classification['required'],
			'priority' => $classification['priority'],
			'language' => isset( $asset['language'] ) ? sanitize_key( (string) $asset['language'] ) : '',
			'scope' => 'all',
			'quality_score' => isset( $quality['overall_score'] ) ? (int) $quality['overall_score'] : null,
			'quality_auto_score' => isset( $quality['overall_score'] ) ? (int) $quality['overall_score'] : null,
			'quality' => $quality,
			'reviewed_by' => 0,
			'reviewed_at' => '',
			'review_status' => 'unreviewed',
			'status' => $content_complete ? 'ready' : 'incomplete',
			'last_synced_at' => gmdate( 'c' ),
		);
	}

	private static function audit_preflight() {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( 'mad4b_context_audit_unavailable', 'Append-only audit service is unavailable.' );
		$status = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) return new WP_Error( 'mad4b_context_audit_not_ready', 'Append-only audit storage is not ready; Context governance mutation remains fail-closed.' );
		return true;
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

	public static function registry_revision() {
		return max( 0, (int) get_option( self::REGISTRY_REVISION_OPTION, 0 ) );
	}

	public static function authority_manifest_fingerprint( $assets = null ) {
		if ( null === $assets ) $assets = self::assets();
		$rows = array();
		foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
			if ( ! is_array( $asset ) || 'governed' !== ( isset( $asset['source_mode'] ) ? (string) $asset['source_mode'] : '' ) ) continue;
			$rows[] = array(
				'asset_id' => isset( $asset['asset_id'] ) ? (string) $asset['asset_id'] : '',
				'category' => isset( $asset['category'] ) ? (string) $asset['category'] : '',
				'authority_class' => isset( $asset['authority_class'] ) ? (string) $asset['authority_class'] : '',
				'classification_source' => isset( $asset['classification_source'] ) ? (string) $asset['classification_source'] : '',
				'review_status' => isset( $asset['review_status'] ) ? (string) $asset['review_status'] : '',
				'required' => ! empty( $asset['required'] ),
				'priority' => isset( $asset['priority'] ) ? (int) $asset['priority'] : 0,
				'status' => isset( $asset['status'] ) ? (string) $asset['status'] : '',
				'content_complete' => ! array_key_exists( 'content_complete', $asset ) || ! empty( $asset['content_complete'] ),
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['asset_id'], $b['asset_id'] ); } );
		$json = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '[]' );
	}

	private static function with_registry_lock( $operation, $callback ) {
		$lock = self::acquire_registry_lock( $operation );
		if ( is_wp_error( $lock ) ) return $lock;
		try {
			$result = call_user_func( $callback );
			if ( ! is_wp_error( $result ) ) self::bump_registry_revision();
			return $result;
		} finally {
			self::release_registry_lock( $lock );
		}
	}

	private static function acquire_registry_lock( $operation ) {
		$operation = sanitize_key( (string) $operation );
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : hash( 'sha256', uniqid( 'mad4b-context-lock-', true ) );
		$record = array( 'owner' => $owner, 'operation' => $operation, 'expires_at' => time() + self::REGISTRY_LOCK_TTL );
		if ( add_option( self::REGISTRY_LOCK_OPTION, $record, '', false ) ) return $owner;
		$current = get_option( self::REGISTRY_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['expires_at'] ) && (int) $current['expires_at'] < time() ) {
			delete_option( self::REGISTRY_LOCK_OPTION );
			if ( add_option( self::REGISTRY_LOCK_OPTION, $record, '', false ) ) return $owner;
		}
		return new WP_Error( 'mad4b_context_registry_busy', 'Context registry is being changed by another governed operation. Retry against the new registry revision.', array( 'operation' => $operation, 'registry_revision' => self::registry_revision() ) );
	}

	private static function release_registry_lock( $owner ) {
		$current = get_option( self::REGISTRY_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $owner ) ) delete_option( self::REGISTRY_LOCK_OPTION );
	}

	private static function bump_registry_revision() {
		$next = self::registry_revision() + 1;
		self::write_option( self::REGISTRY_REVISION_OPTION, $next );
		return $next;
	}

	private static function write_option( $name, $value ) {
		$current = get_option( $name, false );
		if ( false !== $current && $current === $value ) return true;
		$result = false === $current ? add_option( $name, $value, '', false ) : update_option( $name, $value, false );
		if ( true === $result ) return true;
		return get_option( $name, false ) === $value;
	}
}
