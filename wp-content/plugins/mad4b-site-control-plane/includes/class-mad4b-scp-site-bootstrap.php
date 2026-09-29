<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only existing-site Content Intelligence bootstrap snapshot.
 *
 * No IntentClaim, Artifact, redirect, SEO or content mutation is performed here.
 */
final class MAD4B_SCP_Site_Bootstrap {
	const CONTRACT = 'mad4b.site-content-bootstrap.v1';
	const ITEM_CONTRACT = 'mad4b.content-inventory-item.v1';
	const MAX_ITEMS = 2000;
	const MAX_LINKS_PER_ITEM = 100;
	const MAX_TERMS_PER_ITEM = 100;
	const MAX_MEDIA_REFS_PER_ITEM = 100;
	const MAX_PROVIDER_OBSERVATION_BYTES = 32768;
	const MAX_ITEM_ERRORS = 100;
	const MAX_TRANSPORT_PAGE_ITEMS = 5;
	const MAX_TRANSPORT_RESPONSE_BYTES = 12288;
	const MAX_INLINE_LINKS_PER_ITEM = 6;
	const MAX_INLINE_TERMS_PER_ITEM = 16;
	const MAX_INLINE_OBSERVATION_BYTES = 1024;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/site-bootstrap-snapshot' ) ) return;
		wp_register_ability(
			'mad4b/site-bootstrap-snapshot',
			array(
				'label' => 'Existing Site Bootstrap Snapshot',
				'description' => 'Read-only bounded Content Intelligence inventory for an already-existing WordPress site.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'snapshot' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'max_items' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ITEMS, 'default' => 1000 ),
						'after_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					),
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

	public static function snapshot( $input = array() ) {
		global $wpdb;
		$input = is_array( $input ) ? $input : array();
		$max_items = isset( $input['max_items'] ) ? max( 1, min( self::MAX_ITEMS, absint( $input['max_items'] ) ) ) : 1000;
		$effective_page_items = min( self::MAX_TRANSPORT_PAGE_ITEMS, $max_items );
		$after_id = isset( $input['after_id'] ) ? max( 0, absint( $input['after_id'] ) ) : 0;
		$site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		$site_identity_source = 'enrolled_site_profile';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) {
			// A fresh generic installation may intentionally have no governed Site
			// Profile yet. Reuse the portable read-only connection identity so site
			// discovery can start automatically without creating hidden authority.
			$portable = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) ? MAD4B_SCP_Portable_Readonly_Connection::bootstrap() : array();
			$site_uuid = ! empty( $portable['effective'] ) && method_exists( 'MAD4B_SCP_Portable_Readonly_Connection', 'connection_uuid' )
				? strtolower( trim( (string) MAD4B_SCP_Portable_Readonly_Connection::connection_uuid() ) )
				: '';
			$site_identity_source = 'portable_readonly_connection';
		}
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) {
			return new WP_Error( 'mad4b_bootstrap_site_identity_unavailable', 'Existing-site bootstrap requires either an enrolled Site Profile or an effective portable read-only connection identity.' );
		}

		$post_types = self::post_types();
		if ( empty( $post_types ) ) return new WP_Error( 'mad4b_bootstrap_post_types_unavailable', 'No governed content post types are available.' );
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );
		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args = array_merge( $post_types, $statuses );
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})";
		self::reset_db_error( $wpdb );
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
		$count_error = self::db_error( $wpdb );
		if ( '' !== $count_error ) {
			return new WP_Error( 'mad4b_bootstrap_count_query_failed', 'Existing-site bootstrap count query failed.', array( 'db_error' => $count_error ) );
		}

		$row_args = $args;
		$cursor_sql = '';
		if ( $after_id > 0 ) {
			$cursor_sql = ' AND ID > %d';
			$row_args[] = $after_id;
		}
		$row_args[] = $effective_page_items + 1;
		$row_sql = "SELECT ID,post_type,post_status,post_title,post_name,post_author,post_date_gmt,post_modified_gmt,post_content,post_excerpt FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}){$cursor_sql} ORDER BY ID ASC LIMIT %d";
		self::reset_db_error( $wpdb );
		$rows = $wpdb->get_results( $wpdb->prepare( $row_sql, $row_args ), ARRAY_A );
		$inventory_error = self::db_error( $wpdb );
		if ( '' !== $inventory_error ) {
			return new WP_Error( 'mad4b_bootstrap_inventory_query_failed', 'Existing-site bootstrap inventory query failed.', array( 'db_error' => $inventory_error ) );
		}
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$query_has_more = count( $rows ) > $effective_page_items;
		if ( $query_has_more ) $rows = array_slice( $rows, 0, $effective_page_items );
		$items = array();
		$item_errors = array();
		$last_scanned_id = $after_id;
		foreach ( $rows as $row ) {
			$object_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			if ( $object_id > $last_scanned_id ) $last_scanned_id = $object_id;
			try {
				$item = self::item_from_row( $site_uuid, $row );
			} catch ( Throwable $e ) {
				$item = new WP_Error( 'mad4b_bootstrap_item_exception', 'One inventory item raised an exception during read-only discovery.' );
			}
			if ( is_wp_error( $item ) ) {
				if ( count( $item_errors ) < self::MAX_ITEM_ERRORS ) {
					$item_errors[] = array(
						'object_id' => $object_id,
						'code' => sanitize_key( (string) $item->get_error_code() ),
					);
				}
				continue;
			}
			$items[] = $item;
		}

		$snapshot_errors = array();
		$identity = self::safe_snapshot_call(
			static function () use ( $site_uuid ) { return self::identity( $site_uuid ); },
			array( 'site_uuid' => $site_uuid ),
			'identity_observation_exception',
			$snapshot_errors
		);
		$active_languages = self::safe_snapshot_call(
			static function () { return self::active_languages(); },
			function_exists( 'get_locale' ) ? array( strtolower( (string) get_locale() ) ) : array(),
			'language_observation_exception',
			$snapshot_errors
		);
		$content_types = self::safe_snapshot_call(
			static function () use ( $post_types ) { return self::content_type_summary( $post_types ); },
			array(),
			'content_type_observation_exception',
			$snapshot_errors
		);
		$taxonomies = self::safe_snapshot_call(
			static function () { return self::taxonomy_summary(); },
			array(),
			'taxonomy_observation_exception',
			$snapshot_errors
		);
		$redirect_observations = self::safe_snapshot_call(
			static function () use ( $site_uuid ) {
				return self::bounded_observation(
					apply_filters( 'mad4b_scp_site_bootstrap_redirect_observations', array(), $site_uuid ),
					'redirect_observations'
				);
			},
			array( 'state' => 'observation_unavailable', 'kind' => 'redirect_observations' ),
			'redirect_observation_exception',
			$snapshot_errors
		);

		$base = array(
			'contract' => self::CONTRACT,
			'site_uuid' => $site_uuid,
			'site_identity_source' => $site_identity_source,
			'identity' => $identity,
			'wordpress_version' => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'active_languages' => $active_languages,
			'content_types' => $content_types,
			'taxonomies' => $taxonomies,
			'redirect_observations' => $redirect_observations,
			'inventory_scope' => array(
				'post_types' => $post_types,
				'post_statuses' => $statuses,
				'max_items' => $max_items,
				'effective_page_items' => $effective_page_items,
			),
			'pagination' => array(
				'after_id' => $after_id,
				'requested_max_items' => $max_items,
				'effective_page_items' => $effective_page_items,
				'last_scanned_id' => $last_scanned_id,
				'query_has_more' => (bool) $query_has_more,
			),
			'transport' => array(
				'contract' => 'mad4b.site-bootstrap-transport.v1',
				'response_byte_budget' => self::MAX_TRANSPORT_RESPONSE_BYTES,
				'page_item_cap' => self::MAX_TRANSPORT_PAGE_ITEMS,
				'compact_projection' => true,
			),
			'observed_at' => gmdate( 'c' ),
			'historical_source_attribution' => 'observed_existing_content',
			'backfill_performed' => false,
			'intent_claims_created' => false,
			'artifacts_created' => false,
			'mutation_performed' => false,
			'authorizing' => false,
			'item_errors' => $item_errors,
			'item_error_count' => count( $item_errors ),
			'item_errors_truncated' => count( $item_errors ) >= self::MAX_ITEM_ERRORS && count( $items ) + count( $item_errors ) < min( $total, $effective_page_items ),
			'snapshot_errors' => $snapshot_errors,
			'snapshot_error_count' => count( $snapshot_errors ),
		);
		return self::finalize_snapshot( $base, $items, $total, $max_items );
	}

	public static function finalize_snapshot( array $base, array $items, $total_count, $max_items ) {
		// Pagination is keyed by object ID, so transport projection must preserve
		// the same total order as the SQL cursor before any byte-budget truncation.
		usort( $items, static function ( $a, $b ) {
			$left_id = (int) ( $a['object_id'] ?? 0 );
			$right_id = (int) ( $b['object_id'] ?? 0 );
			if ( $left_id === $right_id ) {
				return strcmp( (string) ( $a['object_type'] ?? '' ), (string) ( $b['object_type'] ?? '' ) );
			}
			return $left_id <=> $right_id;
		} );

		$transport_safe = ! empty( $base['transport']['compact_projection'] );
		if ( $transport_safe ) {
			$items = array_map( array( __CLASS__, 'compact_item_for_transport' ), $items );
		}

		$total_count = max( 0, (int) $total_count );
		$max_items = max( 1, (int) $max_items );
		$item_error_count = isset( $base['item_error_count'] ) ? max( 0, (int) $base['item_error_count'] ) : 0;
		$snapshot_error_count = isset( $base['snapshot_error_count'] ) ? max( 0, (int) $base['snapshot_error_count'] ) : 0;
		$after_id = isset( $base['pagination']['after_id'] ) ? max( 0, (int) $base['pagination']['after_id'] ) : 0;
		$query_has_more = ! empty( $base['pagination']['query_has_more'] );
		$transport_truncated = false;
		$budget = isset( $base['transport']['response_byte_budget'] ) ? max( 4096, (int) $base['transport']['response_byte_budget'] ) : 0;

		$build = static function ( array $page_items ) use ( $base, $total_count, $max_items, $item_error_count, $snapshot_error_count, $after_id, $query_has_more, &$transport_truncated ) {
			$result = $base;
			$page_has_more = $query_has_more || $transport_truncated;
			$complete = 0 === $after_id && ! $page_has_more && 0 === $item_error_count && 0 === $snapshot_error_count && count( $page_items ) === $total_count;
			$result['inventory_item_contract'] = self::ITEM_CONTRACT;
			$result['total_item_count'] = $total_count;
			$result['returned_item_count'] = count( $page_items );
			$result['complete'] = $complete;
			$result['bounded'] = true;
			$blocking_reasons = array();
			if ( $page_has_more || $after_id > 0 || ( $total_count > $max_items && 0 === $after_id ) ) $blocking_reasons[] = 'inventory_pagination_required_or_active';
			if ( $total_count > $max_items || count( $page_items ) + $item_error_count < min( $total_count, $max_items ) ) $blocking_reasons[] = 'inventory_bound_exceeded_or_incomplete';
			if ( $item_error_count > 0 ) $blocking_reasons[] = 'inventory_items_partially_unreadable';
			if ( $snapshot_error_count > 0 ) $blocking_reasons[] = 'inventory_observations_partially_unreadable';
			if ( $transport_truncated ) $blocking_reasons[] = 'transport_response_budget_applied';
			$result['blocking_reasons'] = $complete ? array() : array_values( array_unique( $blocking_reasons ) );
			$result['items'] = $page_items;
			$result['collision_analysis'] = self::collision_analysis( $page_items );
			$result['collision_analysis']['scope'] = $complete ? 'complete_inventory' : 'returned_page_only';
			$result['pagination']['page_complete'] = ! $page_has_more;
			$result['pagination']['inventory_complete'] = $complete;
			$result['pagination']['next_after_id'] = 0;
			if ( $page_has_more ) {
				if ( ! empty( $page_items ) ) {
					$represented_ids = array_values( array_filter( array_map(
						static function ( $item ) { return isset( $item['object_id'] ) ? (int) $item['object_id'] : 0; },
						$page_items
					), static function ( $id ) use ( $after_id ) { return $id > $after_id; } ) );
					$result['pagination']['next_after_id'] = ! empty( $represented_ids ) ? max( $represented_ids ) : $after_id;
				} else {
					$result['pagination']['next_after_id'] = isset( $base['pagination']['last_scanned_id'] ) ? (int) $base['pagination']['last_scanned_id'] : $after_id;
				}
			}
			$result['transport']['truncated'] = (bool) $transport_truncated;
			$result['transport']['response_bytes'] = 0;
			$material = $result;
			unset( $material['observed_at'], $material['snapshot_sha256'] );
			$result['snapshot_sha256'] = hash( 'sha256', self::stable_json( $material ) );
			$result['transport']['response_bytes'] = strlen( self::stable_json( $result ) );
			$material = $result;
			unset( $material['observed_at'], $material['snapshot_sha256'] );
			$result['snapshot_sha256'] = hash( 'sha256', self::stable_json( $material ) );
			return $result;
		};

		$page_items = $items;
		$result = $build( $page_items );
		if ( $transport_safe && $budget > 0 ) {
			while ( count( $page_items ) > 1 && strlen( self::stable_json( $result ) ) > $budget ) {
				array_pop( $page_items );
				$transport_truncated = true;
				$result = $build( $page_items );
			}
			if ( count( $page_items ) === 1 && strlen( self::stable_json( $result ) ) > $budget ) {
				$page_items[0] = self::minimal_item_for_transport( $page_items[0] );
				$transport_truncated = true;
				$result = $build( $page_items );
			}
			if ( strlen( self::stable_json( $result ) ) > $budget ) {
				$page_items = array();
				$transport_truncated = true;
				$result = $build( $page_items );
				$result['blocking_reasons'][] = 'transport_response_budget_unrepresentable_item';
				$result['blocking_reasons'] = array_values( array_unique( $result['blocking_reasons'] ) );
				$result['transport']['response_bytes'] = strlen( self::stable_json( $result ) );
				$material = $result;
				unset( $material['observed_at'], $material['snapshot_sha256'] );
				$result['snapshot_sha256'] = hash( 'sha256', self::stable_json( $material ) );
			}
		}
		return $result;
	}

	private static function item_from_row( $site_uuid, array $row ) {
		$id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
		if ( $id < 1 ) return new WP_Error( 'mad4b_bootstrap_object_id_invalid', 'Inventory row object ID is invalid.' );
		$post_type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
		$status = sanitize_key( (string) ( $row['post_status'] ?? '' ) );
		$locale = self::post_locale( $id );
		$seo = self::seo_observation( $id );
		$public_url = 'publish' === $status && function_exists( 'get_permalink' ) ? (string) get_permalink( $id ) : '';
		$canonical = isset( $seo['canonical_url'] ) && '' !== (string) $seo['canonical_url'] ? (string) $seo['canonical_url'] : $public_url;
		$links = self::link_observation( (string) ( $row['post_content'] ?? '' ) );
		$media = self::media_observation( $id, (string) ( $row['post_content'] ?? '' ), $links['all_urls'] );
		unset( $links['all_urls'] );
		$taxonomies = self::term_observation( $id, $post_type );
		$provider = self::bounded_observation(
			apply_filters( 'mad4b_scp_site_bootstrap_provider_observation', array(), $id, $post_type, $locale ),
			'provider_observation'
		);
		$structured = self::bounded_observation(
			apply_filters( 'mad4b_scp_site_bootstrap_structured_data_observation', array(), $id, $post_type ),
			'structured_data_observation'
		);

		$fingerprint_material = array(
			'object_id' => $id,
			'post_type' => $post_type,
			'status' => $status,
			'title' => (string) ( $row['post_title'] ?? '' ),
			'slug' => (string) ( $row['post_name'] ?? '' ),
			'content' => (string) ( $row['post_content'] ?? '' ),
			'excerpt' => (string) ( $row['post_excerpt'] ?? '' ),
			'modified' => (string) ( $row['post_modified_gmt'] ?? '' ),
			'seo' => $seo,
			'taxonomies' => $taxonomies,
			'links' => $links,
			'media' => $media,
		);

		return array(
			'contract' => self::ITEM_CONTRACT,
			'content_id' => $site_uuid . ':post:' . $id,
			'site_uuid' => $site_uuid,
			'locale' => $locale,
			'language' => $locale,
			'object_type' => $post_type,
			'object_id' => $id,
			'public_url' => self::normalize_url( $public_url ),
			'canonical_url' => self::normalize_url( $canonical ),
			'title' => (string) ( $row['post_title'] ?? '' ),
			'status' => $status,
			'content_fingerprint' => hash( 'sha256', self::stable_json( $fingerprint_material ) ),
			'topic_entity_signals' => array(),
			'target_search_intent' => array( 'state' => 'unknown', 'source' => 'bootstrap_observation_only' ),
			'primary_keywords' => isset( $seo['focus_keywords'] ) ? $seo['focus_keywords'] : array(),
			'secondary_keywords' => array(),
			'taxonomy_assignments' => $taxonomies,
			'internal_link_count' => count( $links['internal'] ),
			'outbound_link_count' => count( $links['outbound'] ),
			'internal_links' => $links['internal'],
			'outbound_links' => $links['outbound'],
			'seo' => $seo,
			'indexability' => isset( $seo['indexability'] ) ? $seo['indexability'] : 'unknown',
			'structured_data' => $structured,
			'media' => $media,
			'author_user_id' => isset( $row['post_author'] ) ? (int) $row['post_author'] : 0,
			'published_gmt' => (string) ( $row['post_date_gmt'] ?? '' ),
			'modified_gmt' => (string) ( $row['post_modified_gmt'] ?? '' ),
			'provider_observations' => $provider,
			'field_sources' => array(
				'object' => 'wordpress_posts',
				'locale' => self::locale_source( $id ),
				'seo' => isset( $seo['source'] ) ? $seo['source'] : 'wordpress',
				'links' => 'post_content_observation',
				'media' => 'post_content_and_featured_media_observation',
				'intent' => 'unknown',
			),
		);
	}

	private static function identity( $site_uuid ) {
		$runtime = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status()
			: array();
		$profile_configured = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured();
		$canonical_origin = class_exists( 'MAD4B_SCP_Site_Profile' )
			? ( $profile_configured ? (string) MAD4B_SCP_Site_Profile::canonical_origin() : (string) MAD4B_SCP_Site_Profile::current_origin() )
			: '';
		return array(
			'site_uuid' => $site_uuid,
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown',
			'canonical_origin' => $canonical_origin,
			'site_profile_configured' => (bool) $profile_configured,
			'site_profile_revision' => $profile_configured ? (int) MAD4B_SCP_Site_Profile::revision() : 0,
			'site_profile_digest' => $profile_configured ? (string) MAD4B_SCP_Site_Profile::profile_digest() : '',
			'source_commit_sha' => isset( $runtime['source_commit_sha'] ) ? (string) $runtime['source_commit_sha'] : '',
			'build_fingerprint' => isset( $runtime['build_fingerprint'] ) ? (string) $runtime['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $runtime['package_manifest_digest'] ) ? (string) $runtime['package_manifest_digest'] : '',
		);
	}

	private static function post_types() {
		$types = function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'names' ) : array( 'post', 'page' );
		$types = is_array( $types ) ? array_values( $types ) : array();
		foreach ( array( 'post', 'page' ) as $required ) if ( post_type_exists( $required ) && ! in_array( $required, $types, true ) ) $types[] = $required;
		$types = apply_filters( 'mad4b_scp_site_bootstrap_post_types', $types );
		$types = is_array( $types ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) ) : array();
		$excluded = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' );
		$types = array_values( array_diff( $types, $excluded ) );
		sort( $types, SORT_STRING );
		return $types;
	}

	private static function content_type_summary( array $post_types ) {
		$result = array();
		foreach ( $post_types as $type ) {
			$obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
			$result[] = array(
				'post_type' => $type,
				'public' => is_object( $obj ) ? ! empty( $obj->public ) : true,
				'hierarchical' => is_object( $obj ) ? ! empty( $obj->hierarchical ) : false,
			);
		}
		return $result;
	}

	private static function taxonomy_summary() {
		if ( ! function_exists( 'get_taxonomies' ) ) return array();
		$objects = get_taxonomies( array( 'public' => true ), 'objects' );
		$result = array();
		foreach ( is_array( $objects ) ? $objects : array() as $name => $obj ) {
			$result[] = array(
				'taxonomy' => sanitize_key( (string) $name ),
				'hierarchical' => is_object( $obj ) ? ! empty( $obj->hierarchical ) : false,
			);
		}
		usort( $result, static function ( $a, $b ) { return strcmp( $a['taxonomy'], $b['taxonomy'] ); } );
		return $result;
	}

	private static function active_languages() {
		$languages = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$value = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( is_array( $value ) ) $languages = array_merge( $languages, $value );
		}
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $wpml ) ) $languages = array_merge( $languages, array_keys( $wpml ) );
		if ( empty( $languages ) && function_exists( 'get_locale' ) ) $languages[] = get_locale();
		$languages = array_values( array_unique( array_filter( array_map( static function ( $v ) { return strtolower( trim( (string) $v ) ); }, $languages ) ) ) );
		sort( $languages, SORT_STRING );
		return $languages;
	}

	private static function post_locale( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$value = pll_get_post_language( $post_id, 'slug' );
			if ( is_string( $value ) && '' !== trim( $value ) ) return strtolower( trim( $value ) );
		}
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) return strtolower( trim( (string) $details['language_code'] ) );
		return function_exists( 'get_locale' ) ? strtolower( (string) get_locale() ) : 'und';
	}

	private static function locale_source( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) return 'polylang';
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) return 'wpml';
		return 'wordpress_locale';
	}

	private static function seo_observation( $post_id ) {
		$providers = array();
		if ( function_exists( 'get_post_meta' ) ) {
			$yoast = array(
				'title' => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
				'description' => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
				'canonical_url' => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
				'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
				'noindex' => (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			);
			if ( array_filter( $yoast, static function ( $v ) { return '' !== $v; } ) ) $providers['yoast'] = $yoast;
			$rankmath = array(
				'title' => (string) get_post_meta( $post_id, 'rank_math_title', true ),
				'description' => (string) get_post_meta( $post_id, 'rank_math_description', true ),
				'canonical_url' => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
				'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
				'robots' => get_post_meta( $post_id, 'rank_math_robots', true ),
			);
			if ( array_filter( $rankmath, static function ( $v ) { return ! empty( $v ); } ) ) $providers['rank_math'] = $rankmath;
		}
		$normalized = array(
			'source' => empty( $providers ) ? 'wordpress' : implode( '+', array_keys( $providers ) ),
			'canonical_url' => '',
			'focus_keywords' => array(),
			'indexability' => 'unknown',
			'provider_observations' => $providers,
		);
		foreach ( $providers as $provider => $row ) {
			if ( '' === $normalized['canonical_url'] && ! empty( $row['canonical_url'] ) ) $normalized['canonical_url'] = self::normalize_url( (string) $row['canonical_url'] );
			if ( ! empty( $row['focus_keyword'] ) ) {
				foreach ( preg_split( '/\s*,\s*/', (string) $row['focus_keyword'] ) as $kw ) if ( '' !== trim( $kw ) ) $normalized['focus_keywords'][] = trim( $kw );
			}
			if ( 'yoast' === $provider && isset( $row['noindex'] ) && '1' === (string) $row['noindex'] ) $normalized['indexability'] = 'noindex';
			if ( 'rank_math' === $provider && is_array( $row['robots'] ) && in_array( 'noindex', $row['robots'], true ) ) $normalized['indexability'] = 'noindex';
		}
		$normalized['focus_keywords'] = array_values( array_unique( $normalized['focus_keywords'] ) );
		if ( 'unknown' === $normalized['indexability'] && ! empty( $providers ) ) $normalized['indexability'] = 'provider_default_or_unspecified';
		return $normalized;
	}

	private static function term_observation( $post_id, $post_type ) {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'wp_get_object_terms' ) ) return array();
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		if ( empty( $taxonomies ) ) return array();
		$terms = wp_get_object_terms( $post_id, $taxonomies );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) return array();
		$result = array();
		foreach ( array_slice( $terms, 0, self::MAX_TERMS_PER_ITEM ) as $term ) {
			if ( ! is_object( $term ) ) continue;
			$result[] = array(
				'taxonomy' => isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '',
				'term_id' => isset( $term->term_id ) ? (int) $term->term_id : 0,
				'slug' => isset( $term->slug ) ? (string) $term->slug : '',
				'name' => isset( $term->name ) ? (string) $term->name : '',
			);
		}
		usort( $result, static function ( $a, $b ) { return strcmp( $a['taxonomy'] . ':' . $a['slug'], $b['taxonomy'] . ':' . $b['slug'] ); } );
		return $result;
	}

	private static function link_observation( $content ) {
		$urls = function_exists( 'wp_extract_urls' ) ? wp_extract_urls( (string) $content ) : array();
		if ( ! is_array( $urls ) ) $urls = array();
		if ( empty( $urls ) && preg_match_all( '~https?://[^\s"\'<>]+~i', (string) $content, $matches ) ) $urls = $matches[0];
		$urls = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_url' ), $urls ) ) ) );
		sort( $urls, SORT_STRING );
		$urls = array_slice( $urls, 0, self::MAX_LINKS_PER_ITEM * 2 );
		$home_host = function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) : '';
		$internal = array();
		$outbound = array();
		foreach ( $urls as $url ) {
			$host = function_exists( 'wp_parse_url' ) ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $home_host && hash_equals( $home_host, $host ) ) $internal[] = $url; else $outbound[] = $url;
		}
		return array(
			'internal' => array_slice( $internal, 0, self::MAX_LINKS_PER_ITEM ),
			'outbound' => array_slice( $outbound, 0, self::MAX_LINKS_PER_ITEM ),
			'all_urls' => $urls,
			'truncated' => count( $internal ) > self::MAX_LINKS_PER_ITEM || count( $outbound ) > self::MAX_LINKS_PER_ITEM,
		);
	}

	private static function media_observation( $post_id, $content, array $urls ) {
		$ids = array();
		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$featured = (int) get_post_thumbnail_id( $post_id );
			if ( $featured > 0 ) $ids[] = $featured;
		}
		if ( preg_match_all( '/\bwp-image-([0-9]+)\b/', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $id ) if ( (int) $id > 0 ) $ids[] = (int) $id;
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		$media_urls = array_values( array_filter( $urls, static function ( $url ) {
			return false !== strpos( $url, '/wp-content/uploads/' ) || 1 === preg_match( '/\.(?:jpe?g|png|gif|webp|svg|avif|pdf|mp4|webm)(?:\?.*)?$/i', $url );
		} ) );
		sort( $media_urls, SORT_STRING );
		return array(
			'attachment_ids' => array_slice( $ids, 0, self::MAX_MEDIA_REFS_PER_ITEM ),
			'urls' => array_slice( $media_urls, 0, self::MAX_MEDIA_REFS_PER_ITEM ),
			'truncated' => count( $ids ) > self::MAX_MEDIA_REFS_PER_ITEM || count( $media_urls ) > self::MAX_MEDIA_REFS_PER_ITEM,
		);
	}

	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) return '';
		if ( function_exists( 'esc_url_raw' ) ) $url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url || 1 !== preg_match( '~^https?://~i', $url ) ) return '';
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) return '';
		$scheme = strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' );
		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . $host . $port . $path . $query;
	}

	private static function reset_db_error( $wpdb ) {
		if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) $wpdb->last_error = '';
	}

	private static function db_error( $wpdb ) {
		return is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ? trim( (string) $wpdb->last_error ) : '';
	}

	private static function safe_snapshot_call( $callback, $fallback, $error_code, array &$errors ) {
		try {
			return is_callable( $callback ) ? call_user_func( $callback ) : $fallback;
		} catch ( Throwable $e ) {
			if ( count( $errors ) < self::MAX_ITEM_ERRORS ) {
				$errors[] = array( 'code' => sanitize_key( (string) $error_code ) );
			}
			return $fallback;
		}
	}

	private static function collision_analysis( array $items ) {
		$canonical_by_locale = array();
		$canonical_locales = array();
		$public_urls = array();
		foreach ( $items as $item ) {
			$locale = (string) ( $item['locale'] ?? '' );
			$canonical = self::normalize_url( (string) ( $item['canonical_url'] ?? '' ) );
			$public = self::normalize_url( (string) ( $item['public_url'] ?? '' ) );
			$content_id = (string) ( $item['content_id'] ?? '' );
			if ( '' !== $canonical ) {
				$key = $locale . "\0" . $canonical;
				if ( ! isset( $canonical_by_locale[ $key ] ) ) $canonical_by_locale[ $key ] = array();
				$canonical_by_locale[ $key ][] = $content_id;
				if ( ! isset( $canonical_locales[ $canonical ] ) ) $canonical_locales[ $canonical ] = array();
				$canonical_locales[ $canonical ][ $locale ] = true;
			}
			if ( '' !== $public ) {
				if ( ! isset( $public_urls[ $public ] ) ) $public_urls[ $public ] = array();
				$public_urls[ $public ][] = $content_id;
			}
		}

		$duplicate_canonicals = array();
		foreach ( $canonical_by_locale as $key => $owners ) {
			if ( count( $owners ) < 2 ) continue;
			list( $locale, $url ) = explode( "\0", $key, 2 );
			sort( $owners, SORT_STRING );
			$duplicate_canonicals[] = array( 'locale' => $locale, 'canonical_url' => $url, 'content_ids' => $owners );
		}
		usort( $duplicate_canonicals, static function ( $a, $b ) {
			return strcmp( $a['locale'] . "\0" . $a['canonical_url'], $b['locale'] . "\0" . $b['canonical_url'] );
		} );

		$cross_locale_shared = array();
		foreach ( $canonical_locales as $url => $locale_map ) {
			$locales = array_keys( $locale_map );
			sort( $locales, SORT_STRING );
			if ( count( $locales ) > 1 ) $cross_locale_shared[] = array( 'canonical_url' => $url, 'locales' => $locales );
		}
		usort( $cross_locale_shared, static function ( $a, $b ) { return strcmp( $a['canonical_url'], $b['canonical_url'] ); } );

		$duplicate_public = array();
		foreach ( $public_urls as $url => $owners ) {
			if ( count( $owners ) < 2 ) continue;
			sort( $owners, SORT_STRING );
			$duplicate_public[] = array( 'public_url' => $url, 'content_ids' => $owners );
		}
		usort( $duplicate_public, static function ( $a, $b ) { return strcmp( $a['public_url'], $b['public_url'] ); } );

		return array(
			'duplicate_canonicals_same_locale' => $duplicate_canonicals,
			'duplicate_public_urls' => $duplicate_public,
			'cross_locale_shared_canonicals' => $cross_locale_shared,
			'cross_locale_shared_canonical_is_automatic_conflict' => false,
			'cannibalization_automatic_from_topic_overlap' => false,
		);
	}

	private static function compact_item_for_transport( $item ) {
		if ( ! is_array( $item ) ) return array();
		$item['title'] = self::bounded_text( (string) ( $item['title'] ?? '' ), 320 );
		$item['public_url'] = self::bounded_text( (string) ( $item['public_url'] ?? '' ), 1024 );
		$item['canonical_url'] = self::bounded_text( (string) ( $item['canonical_url'] ?? '' ), 1024 );

		foreach ( array( 'internal_links', 'outbound_links' ) as $key ) {
			$values = isset( $item[ $key ] ) && is_array( $item[ $key ] ) ? array_values( $item[ $key ] ) : array();
			$item[ $key . '_returned_count' ] = min( count( $values ), self::MAX_INLINE_LINKS_PER_ITEM );
			$item[ $key . '_truncated' ] = count( $values ) > self::MAX_INLINE_LINKS_PER_ITEM;
			$item[ $key ] = array_map(
				static function ( $url ) { return self::bounded_text( (string) $url, 768 ); },
				array_slice( $values, 0, self::MAX_INLINE_LINKS_PER_ITEM )
			);
		}

		$terms = isset( $item['taxonomy_assignments'] ) && is_array( $item['taxonomy_assignments'] ) ? array_values( $item['taxonomy_assignments'] ) : array();
		$item['taxonomy_assignment_count'] = count( $terms );
		$item['taxonomy_assignments_truncated'] = count( $terms ) > self::MAX_INLINE_TERMS_PER_ITEM;
		$item['taxonomy_assignments'] = array_slice( $terms, 0, self::MAX_INLINE_TERMS_PER_ITEM );

		foreach ( array( 'provider_observations', 'structured_data' ) as $key ) {
			if ( isset( $item[ $key ] ) ) $item[ $key ] = self::compact_observation_for_transport( $item[ $key ], $key );
		}
		if ( isset( $item['seo']['provider_observations'] ) ) {
			$item['seo']['provider_observations'] = self::compact_observation_for_transport( $item['seo']['provider_observations'], 'seo_provider_observations' );
		}
		return $item;
	}

	private static function minimal_item_for_transport( array $item ) {
		return array(
			'contract' => isset( $item['contract'] ) ? (string) $item['contract'] : self::ITEM_CONTRACT,
			'content_id' => isset( $item['content_id'] ) ? (string) $item['content_id'] : '',
			'site_uuid' => isset( $item['site_uuid'] ) ? (string) $item['site_uuid'] : '',
			'locale' => isset( $item['locale'] ) ? (string) $item['locale'] : '',
			'language' => isset( $item['language'] ) ? (string) $item['language'] : '',
			'object_type' => isset( $item['object_type'] ) ? (string) $item['object_type'] : '',
			'object_id' => isset( $item['object_id'] ) ? (int) $item['object_id'] : 0,
			'title' => self::bounded_text( (string) ( $item['title'] ?? '' ), 256 ),
			'status' => isset( $item['status'] ) ? (string) $item['status'] : '',
			'public_url' => self::bounded_text( (string) ( $item['public_url'] ?? '' ), 768 ),
			'canonical_url' => self::bounded_text( (string) ( $item['canonical_url'] ?? '' ), 768 ),
			'content_fingerprint' => isset( $item['content_fingerprint'] ) ? (string) $item['content_fingerprint'] : '',
			'internal_link_count' => isset( $item['internal_link_count'] ) ? (int) $item['internal_link_count'] : 0,
			'outbound_link_count' => isset( $item['outbound_link_count'] ) ? (int) $item['outbound_link_count'] : 0,
			'taxonomy_assignment_count' => isset( $item['taxonomy_assignment_count'] ) ? (int) $item['taxonomy_assignment_count'] : 0,
			'indexability' => isset( $item['indexability'] ) ? (string) $item['indexability'] : 'unknown',
			'published_gmt' => isset( $item['published_gmt'] ) ? (string) $item['published_gmt'] : '',
			'modified_gmt' => isset( $item['modified_gmt'] ) ? (string) $item['modified_gmt'] : '',
			'transport_projection' => 'minimal',
		);
	}

	private static function compact_observation_for_transport( $value, $kind ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) && strlen( $json ) <= self::MAX_INLINE_OBSERVATION_BYTES ) return $value;
		$keys = is_array( $value ) ? array_slice( array_map( 'strval', array_keys( $value ) ), 0, 20 ) : array();
		return array(
			'state' => 'compacted_for_transport',
			'kind' => sanitize_key( (string) $kind ),
			'byte_count' => is_string( $json ) ? strlen( $json ) : 0,
			'sha256' => is_string( $json ) ? hash( 'sha256', $json ) : '',
			'top_level_keys' => $keys,
		);
	}

	private static function bounded_text( $value, $max_bytes ) {
		$value = (string) $value;
		$max_bytes = max( 16, (int) $max_bytes );
		if ( strlen( $value ) <= $max_bytes ) return $value;
		return substr( $value, 0, $max_bytes );
	}

	private static function bounded_observation( $value, $kind ) {
		if ( ! is_array( $value ) ) return array( 'state' => 'invalid_provider_observation', 'kind' => sanitize_key( (string) $kind ) );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PROVIDER_OBSERVATION_BYTES ) {
			return array( 'state' => 'provider_observation_exceeds_bound', 'kind' => sanitize_key( (string) $kind ) );
		}
		return $value;
	}

	private static function stable_json( $value ) {
		$value = self::sort_value( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : $json;
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::sort_value( $item );
		return $value;
	}
}

MAD4B_SCP_Site_Bootstrap::boot();
