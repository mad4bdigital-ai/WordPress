<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;

final class InventoryReconciler {
	const CONTRACT = 'etg.dfsb.inventory-reconciliation.v2';
	const MAX_FINDINGS = 500;
	const MAX_CANDIDATES = 50;

	public function analyze( array $snapshot, array $profiles, array $previous = array() ): array {
		$validation = $this->validateSnapshot( $snapshot );
		if ( $validation ) { return $this->invalidResult( $validation ); }

		$inventory = (array) $snapshot['inventory'];
		$postTypes = (array) ( $inventory['post_types'] ?? array() );
		$taxonomies = (array) ( $inventory['taxonomies'] ?? array() );
		$queryBuilder = (array) ( $inventory['query_builder'] ?? array() );
		$queries = (array) ( $queryBuilder['queries'] ?? array() );
		$languages = (array) ( $inventory['languages'] ?? array() );
		$completeness = (array) ( $inventory['completeness'] ?? array() );
		$queryConflicts = $this->queryConflictIndex( (array) ( $queryBuilder['identity_conflicts'] ?? array() ) );
		$queryIndex = $this->queryIndex( $queries, $queryConflicts );
		$findings = $this->inventoryQualityFindings( $inventory );
		$profiledPostTypes = array();
		$profiledTaxonomies = array();

		foreach ( array_slice( $profiles, 0, 50, true ) as $profileId => $profile ) {
			if ( ! is_array( $profile ) ) { continue; }
			$id = sanitize_key( (string) ( $profile['id'] ?? $profileId ) );
			if ( '' === $id ) { continue; }
			$enabled = ! empty( $profile['enabled'] );
			$severity = $enabled ? 'blocking' : 'warning';
			$allowedPostTypes = $this->cleanKeys( (array) ( $profile['post_types'] ?? array() ) );
			$allowedPostTypeMap = array_fill_keys( $allowedPostTypes, true );

			foreach ( $allowedPostTypes as $postType ) {
				$profiledPostTypes[ $postType ] = true;
				if ( isset( $postTypes[ $postType ] ) ) { continue; }
				if ( $this->sectionTruncated( $inventory, 'post_types' ) ) {
					$this->finding( $findings, $severity, 'profile_post_type_unresolved_inventory_truncated', 'profile:' . $id, array( 'post_type' => $postType, 'profile_enabled' => $enabled ) );
				} else {
					$this->finding( $findings, $severity, 'profile_post_type_missing', 'profile:' . $id, array( 'post_type' => $postType, 'profile_enabled' => $enabled ) );
				}
			}

			$rules = (array) ( $profile['taxonomy_rules'] ?? array() );
			foreach ( array_slice( $rules, 0, 50, true ) as $taxonomy => $rule ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				if ( '' === $taxonomy ) { continue; }
				$profiledTaxonomies[ $taxonomy ] = true;
				if ( ! isset( $taxonomies[ $taxonomy ] ) ) {
					if ( $this->sectionTruncated( $inventory, 'taxonomies' ) ) {
						$this->finding( $findings, $severity, 'profile_taxonomy_unresolved_inventory_truncated', 'profile:' . $id, array( 'taxonomy' => $taxonomy, 'profile_enabled' => $enabled ) );
					} else {
						$this->finding( $findings, $severity, 'profile_taxonomy_missing', 'profile:' . $id, array( 'taxonomy' => $taxonomy, 'profile_enabled' => $enabled ) );
					}
					continue;
				}
				$attached = $this->cleanKeys( (array) ( $taxonomies[ $taxonomy ]['object_type'] ?? array() ) );
				if ( $allowedPostTypes && ! array_intersect( $allowedPostTypes, $attached ) ) {
					$this->finding( $findings, $severity, 'taxonomy_no_longer_attached_to_profile_post_type', 'profile:' . $id, array( 'taxonomy' => $taxonomy, 'runtime_object_types' => $attached, 'profile_post_types' => $allowedPostTypes ) );
				}
			}

			$runtimeArchivePaths = array();
			foreach ( $allowedPostTypes as $postType ) {
				foreach ( (array) ( $postTypes[ $postType ]['archive_paths'] ?? array() ) as $path ) {
					$normalized = $this->normalizePath( (string) $path );
					if ( '' !== $normalized ) { $runtimeArchivePaths[ $normalized ] = true; }
				}
			}
			foreach ( array_slice( (array) ( $profile['archive_paths'] ?? array() ), 0, 100 ) as $path ) {
				$path = $this->normalizePath( (string) $path );
				if ( '' === $path || ! $runtimeArchivePaths || isset( $runtimeArchivePaths[ $path ] ) ) { continue; }
				if ( $this->sectionTruncated( $inventory, 'archive_path_translations' ) ) {
					$this->finding( $findings, 'warning', 'profile_archive_path_unresolved_inventory_truncated', 'profile:' . $id, array( 'archive_path' => $path, 'profile_enabled' => $enabled, 'evidence_scope' => 'post_type_archive_only' ) );
				} else {
					$this->finding( $findings, 'warning', 'profile_archive_path_not_observed_as_post_type_archive', 'profile:' . $id, array( 'archive_path' => $path, 'profile_enabled' => $enabled, 'evidence_scope' => 'post_type_archive_only' ) );
				}
			}

			foreach ( array_slice( (array) ( $profile['routes'] ?? array() ), 0, 20 ) as $route ) {
				if ( ! is_array( $route ) ) { continue; }
				$provider = sanitize_key( (string) ( $route['provider'] ?? '' ) );
				$queryId = sanitize_key( (string) ( $route['query_id'] ?? '' ) );
				if ( 'jet-engine' !== $provider ) {
					$this->finding( $findings, 'info', 'route_provider_outside_inventory_authority', 'profile:' . $id, array( 'provider' => $provider, 'query_id' => $queryId ) );
					continue;
				}
				if ( empty( $queryBuilder['available'] ) ) {
					$this->finding( $findings, $severity, 'profile_query_inventory_unavailable', 'profile:' . $id, array( 'query_id' => $queryId, 'profile_enabled' => $enabled ) );
					continue;
				}
				if ( '' !== $queryId && isset( $queryConflicts[ $queryId ] ) ) {
					$this->finding( $findings, $severity, 'profile_query_identity_collision', 'profile:' . $id, array( 'query_id' => $queryId, 'profile_enabled' => $enabled, 'conflict' => $queryConflicts[ $queryId ] ) );
					continue;
				}
				if ( '' === $queryId || ! isset( $queryIndex[ $queryId ] ) ) {
					if ( $this->sectionTruncated( $inventory, 'query_builder' ) ) {
						$this->finding( $findings, $severity, 'profile_query_unresolved_inventory_truncated', 'profile:' . $id, array( 'query_id' => $queryId, 'profile_enabled' => $enabled ) );
					} else {
						$this->finding( $findings, $severity, 'profile_query_missing', 'profile:' . $id, array( 'query_id' => $queryId, 'profile_enabled' => $enabled ) );
					}
					continue;
				}
				$query = (array) $queryIndex[ $queryId ];
				if ( 'posts' !== (string) ( $query['type'] ?? '' ) ) {
					$this->finding( $findings, $severity, 'profile_query_not_posts', 'profile:' . $id, array( 'query_id' => $queryId, 'query_type' => (string) ( $query['type'] ?? '' ) ) );
					continue;
				}
				if ( empty( $query['post_type_bounded'] ) ) {
					$this->finding( $findings, $severity, 'profile_query_unbounded_post_type', 'profile:' . $id, array( 'query_id' => $queryId ) );
					continue;
				}
				$observed = $this->cleanKeys( (array) ( $query['post_types'] ?? array() ) );
				$foreign = array_keys( array_diff_key( array_fill_keys( $observed, true ), $allowedPostTypeMap ) );
				if ( ! $observed || $foreign ) {
					$this->finding( $findings, $severity, 'profile_query_post_type_mismatch', 'profile:' . $id, array( 'query_id' => $queryId, 'observed_post_types' => $observed, 'profile_post_types' => $allowedPostTypes ) );
				}
			}
		}

		foreach ( array_slice( $postTypes, 0, 100, true ) as $postType => $record ) {
			$postType = sanitize_key( (string) $postType );
			if ( '' === $postType || isset( $profiledPostTypes[ $postType ] ) ) { continue; }
			$this->finding( $findings, 'info', 'unprofiled_post_type_discovered', 'post_type:' . $postType, array( 'post_type' => $postType ) );
		}
		foreach ( array_slice( $taxonomies, 0, 150, true ) as $taxonomy => $record ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( '' === $taxonomy || isset( $profiledTaxonomies[ $taxonomy ] ) ) { continue; }
			$attached = $this->cleanKeys( (array) ( $record['object_type'] ?? array() ) );
			if ( array_intersect( array_keys( $profiledPostTypes ), $attached ) ) {
				$this->finding( $findings, 'warning', 'unprofiled_taxonomy_attached_to_profiled_post_type', 'taxonomy:' . $taxonomy, array( 'taxonomy' => $taxonomy, 'runtime_object_types' => $attached ) );
			}
		}

		$previousValidation = $previous ? $this->validateSnapshot( $previous ) : array();
		if ( $previousValidation ) {
			$this->finding( $findings, 'warning', 'previous_inventory_invalid', 'previous_snapshot', array( 'errors' => array_slice( $previousValidation, 0, 20 ) ) );
		}
		$drift = $previousValidation ? array( 'findings' => array() ) : $this->compareSnapshots( $previous, $snapshot );
		foreach ( $drift['findings'] as $finding ) { $this->findingArray( $findings, $finding ); }

		$blocking = $this->countSeverity( $findings, 'blocking' );
		$warnings = $this->countSeverity( $findings, 'warning' );
		return array(
			'contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'profile_mutation' => false,
			'requires_operator_review' => true,
			'snapshot_fingerprint' => (string) ( $snapshot['snapshot_fingerprint'] ?? '' ),
			'previous_snapshot_fingerprint' => (string) ( $previous['snapshot_fingerprint'] ?? '' ),
			'state' => $blocking ? 'blocked_drift' : ( $warnings ? 'review_required' : 'clean_or_informational' ),
			'summary' => array(
				'blocking' => $blocking,
				'warnings' => $warnings,
				'info' => $this->countSeverity( $findings, 'info' ),
				'profiles' => count( $profiles ),
				'post_types' => count( $postTypes ),
				'taxonomies' => count( $taxonomies ),
				'languages' => count( $languages ),
				'queries' => count( $queries ),
				'inventory_complete_for_candidates' => $this->inventorySafeForCandidates( $inventory ),
			),
			'findings' => array_slice( $findings, 0, self::MAX_FINDINGS ),
			'disabled_candidates' => $this->inventorySafeForCandidates( $inventory ) ? $this->candidateProfiles( $inventory, $profiledPostTypes, $queryConflicts ) : array(),
		);
	}

	private function inventoryQualityFindings( array $inventory ): array {
		$out = array();
		foreach ( array( 'post_types', 'taxonomies', 'languages', 'query_builder', 'archive_path_translations' ) as $section ) {
			$record = (array) ( (array) ( $inventory['completeness'] ?? array() )[ $section ] ?? array() );
			if ( empty( $record['truncated'] ) ) { continue; }
			$out[] = $this->findingValue( 'blocking', 'inventory_' . $section . '_truncated', 'inventory:' . $section, array(
				'observed_count' => (int) ( $record['observed_count'] ?? 0 ),
				'included_count' => (int) ( $record['included_count'] ?? 0 ),
				'limit' => (int) ( $record['limit'] ?? 0 ),
			) );
		}
		$queryBuilder = (array) ( $inventory['query_builder'] ?? array() );
		if ( empty( $queryBuilder['available'] ) ) {
			$out[] = $this->findingValue( 'blocking', 'inventory_query_builder_unavailable', 'inventory:query_builder', array( 'source' => (string) ( $queryBuilder['source'] ?? 'unavailable' ) ) );
		}
		$conflictCount = (int) ( $queryBuilder['identity_conflict_count'] ?? 0 );
		if ( $conflictCount > 0 ) {
			$out[] = $this->findingValue( 'blocking', 'query_builder_identity_collision', 'inventory:query_builder', array(
				'identity_conflict_count' => $conflictCount,
				'identity_conflicts_truncated' => ! empty( $queryBuilder['identity_conflicts_truncated'] ),
				'identity_conflicts' => array_slice( (array) ( $queryBuilder['identity_conflicts'] ?? array() ), 0, 20 ),
			) );
		}
		return $out;
	}

	private function compareSnapshots( array $previous, array $current ): array {
		if ( ! $previous || $this->validateSnapshot( $previous ) ) { return array( 'findings' => array() ); }
		$out = array();
		$previousInventory = (array) $previous['inventory'];
		$currentInventory = (array) $current['inventory'];
		foreach ( array( 'post_types', 'taxonomies' ) as $section ) {
			if ( ! $this->sectionComparable( $previousInventory, $currentInventory, $section ) ) {
				$out[] = $this->findingValue( 'warning', 'snapshot_' . $section . '_comparison_skipped_incomplete_inventory', 'snapshot:' . $section );
				continue;
			}
			$before = (array) ( $previousInventory[ $section ] ?? array() );
			$after = (array) ( $currentInventory[ $section ] ?? array() );
			foreach ( array_diff( array_keys( $after ), array_keys( $before ) ) as $key ) { $out[] = $this->findingValue( 'info', 'snapshot_' . $section . '_added', $section . ':' . sanitize_key( (string) $key ), array( 'key' => sanitize_key( (string) $key ) ) ); }
			foreach ( array_diff( array_keys( $before ), array_keys( $after ) ) as $key ) { $out[] = $this->findingValue( 'warning', 'snapshot_' . $section . '_removed', $section . ':' . sanitize_key( (string) $key ), array( 'key' => sanitize_key( (string) $key ) ) ); }
			foreach ( array_intersect( array_keys( $before ), array_keys( $after ) ) as $key ) {
				if ( $this->stableHash( (array) $before[ $key ] ) !== $this->stableHash( (array) $after[ $key ] ) ) { $out[] = $this->findingValue( 'warning', 'snapshot_' . $section . '_changed', $section . ':' . sanitize_key( (string) $key ), array( 'key' => sanitize_key( (string) $key ) ) ); }
			}
		}

		if ( $this->sectionComparable( $previousInventory, $currentInventory, 'languages' ) ) {
			$beforeLanguages = $this->languageIndex( (array) ( $previousInventory['languages'] ?? array() ) );
			$afterLanguages = $this->languageIndex( (array) ( $currentInventory['languages'] ?? array() ) );
			foreach ( array_diff( array_keys( $afterLanguages ), array_keys( $beforeLanguages ) ) as $code ) { $out[] = $this->findingValue( 'info', 'snapshot_language_added', 'language:' . $code, array( 'code' => $code ) ); }
			foreach ( array_diff( array_keys( $beforeLanguages ), array_keys( $afterLanguages ) ) as $code ) { $out[] = $this->findingValue( 'warning', 'snapshot_language_removed', 'language:' . $code, array( 'code' => $code ) ); }
			foreach ( array_intersect( array_keys( $beforeLanguages ), array_keys( $afterLanguages ) ) as $code ) {
				if ( $this->stableHash( $beforeLanguages[ $code ] ) !== $this->stableHash( $afterLanguages[ $code ] ) ) { $out[] = $this->findingValue( 'warning', 'snapshot_language_changed', 'language:' . $code, array( 'code' => $code ) ); }
			}
		} else {
			$out[] = $this->findingValue( 'warning', 'snapshot_languages_comparison_skipped_incomplete_inventory', 'snapshot:languages' );
		}

		$previousQueryBuilder = (array) ( $previousInventory['query_builder'] ?? array() );
		$currentQueryBuilder = (array) ( $currentInventory['query_builder'] ?? array() );
		$queryComparable = $this->sectionComparable( $previousInventory, $currentInventory, 'query_builder' )
			&& ! empty( $previousQueryBuilder['available'] ) && ! empty( $currentQueryBuilder['available'] )
			&& 0 === (int) ( $previousQueryBuilder['identity_conflict_count'] ?? 0 )
			&& 0 === (int) ( $currentQueryBuilder['identity_conflict_count'] ?? 0 );
		if ( $queryComparable ) {
			$beforeQueries = $this->queryIndex( (array) ( $previousQueryBuilder['queries'] ?? array() ), array() );
			$afterQueries = $this->queryIndex( (array) ( $currentQueryBuilder['queries'] ?? array() ), array() );
			foreach ( array_diff( array_keys( $afterQueries ), array_keys( $beforeQueries ) ) as $id ) { $out[] = $this->findingValue( 'info', 'snapshot_query_added', 'query:' . $id, array( 'query_id' => $id ) ); }
			foreach ( array_diff( array_keys( $beforeQueries ), array_keys( $afterQueries ) ) as $id ) { $out[] = $this->findingValue( 'warning', 'snapshot_query_removed', 'query:' . $id, array( 'query_id' => $id ) ); }
			foreach ( array_intersect( array_keys( $beforeQueries ), array_keys( $afterQueries ) ) as $id ) {
				$before = (array) $beforeQueries[ $id ]; $after = (array) $afterQueries[ $id ];
				if ( ! empty( $before['post_type_bounded'] ) && empty( $after['post_type_bounded'] ) ) { $out[] = $this->findingValue( 'blocking', 'snapshot_query_became_unbounded', 'query:' . $id, array( 'query_id' => $id ) ); continue; }
				if ( $this->stableHash( $before ) !== $this->stableHash( $after ) ) { $out[] = $this->findingValue( 'warning', 'snapshot_query_changed', 'query:' . $id, array( 'query_id' => $id ) ); }
			}
		} else {
			$out[] = $this->findingValue( 'warning', 'snapshot_query_comparison_skipped_incomplete_or_ambiguous_inventory', 'snapshot:query_builder' );
		}
		return array( 'findings' => array_slice( $out, 0, self::MAX_FINDINGS ) );
	}

	private function candidateProfiles( array $inventory, array $profiledPostTypes, array $queryConflicts ): array {
		$postTypes = (array) ( $inventory['post_types'] ?? array() );
		$taxonomies = (array) ( $inventory['taxonomies'] ?? array() );
		$queries = (array) ( (array) ( $inventory['query_builder'] ?? array() )['queries'] ?? array() );
		$out = array();
		foreach ( array_slice( $postTypes, 0, self::MAX_CANDIDATES, true ) as $postType => $record ) {
			$postType = sanitize_key( (string) $postType );
			if ( '' === $postType || isset( $profiledPostTypes[ $postType ] ) ) { continue; }
			$record = is_array( $record ) ? $record : array();
			$rules = array(); $priority = 10;
			foreach ( array_slice( $this->cleanKeys( (array) ( $record['taxonomies'] ?? array() ) ), 0, 20 ) as $taxonomy ) {
				$taxonomyRecord = (array) ( $taxonomies[ $taxonomy ] ?? array() );
				if ( ! in_array( $postType, $this->cleanKeys( (array) ( $taxonomyRecord['object_type'] ?? array() ) ), true ) ) { continue; }
				$rules[ $taxonomy ] = array( 'role' => $taxonomy, 'priority' => $priority, 'gallery_priority' => $priority, 'index_single' => false, 'min_results' => 3, 'required_meta_key' => '', 'required_meta_values' => array(), 'meta_constraint_scope' => 'single', 'field_map' => array() );
				$priority += 10;
			}
			$suggestedRoutes = array();
			foreach ( array_slice( $queries, 0, 100 ) as $query ) {
				if ( ! is_array( $query ) || 'posts' !== (string) ( $query['type'] ?? '' ) || empty( $query['post_type_bounded'] ) ) { continue; }
				$observed = $this->cleanKeys( (array) ( $query['post_types'] ?? array() ) );
				if ( array( $postType ) !== $observed ) { continue; }
				$queryId = $this->queryIdentityKey( $query );
				if ( '' !== $queryId && ! isset( $queryConflicts[ $queryId ] ) ) { $suggestedRoutes[] = array( 'provider' => 'jet-engine', 'query_id' => $queryId ); }
			}
			if ( empty( $record['has_archive'] ) && ! $suggestedRoutes ) { continue; }
			$archivePaths = array_values( array_unique( array_filter( array_map( array( $this, 'normalizePath' ), array_values( (array) ( $record['archive_paths'] ?? array() ) ) ) ) ) );
			$out[] = array(
				'contract' => 'etg.dfsb.reconciliation-candidate.v2',
				'authorizing' => false,
				'requires_operator_review' => true,
				'evidence' => array( 'observed_archive_paths' => $archivePaths, 'suggested_routes' => array_slice( $suggestedRoutes, 0, 20 ) ),
				'profile' => array(
					'id' => $postType, 'enabled' => false, 'inherit_global_defaults' => false, 'post_types' => array( $postType ), 'require_post_type_binding' => true, 'post_type_authority' => 'query_builder',
					'archive_slugs' => array(), 'archive_paths' => array(), 'providers' => array(), 'query_ids' => array(), 'routes' => array(), 'max_filters' => min( 10, max( 1, count( $rules ) ) ),
					'composition_mode' => 'generic', 'canonical_mode' => 'filtered', 'require_exact_combination_approval' => true, 'require_exact_for_single' => false,
					'allowed_taxonomy_sets' => array(), 'min_results_by_depth' => array( '1' => 3, '2' => 3, '3' => 3 ), 'taxonomy_rules' => $rules, 'indexable_combinations' => array(),
					'content' => array( 'required' => true, 'require_meta_description' => true, 'min_chars' => 80 ),
				),
			);
		}
		return array_slice( $out, 0, self::MAX_CANDIDATES );
	}

	private function validateSnapshot( array $snapshot ): array {
		$errors = array();
		if ( RuntimeInventory::CONTRACT !== (string) ( $snapshot['contract'] ?? '' ) ) { $errors[] = 'invalid_inventory_contract'; }
		if ( ! array_key_exists( 'authorizing', $snapshot ) || false !== $snapshot['authorizing'] ) { $errors[] = 'inventory_must_be_non_authorizing'; }
		if ( ! array_key_exists( 'read_only', $snapshot ) || true !== $snapshot['read_only'] ) { $errors[] = 'inventory_must_be_read_only'; }
		if ( ! array_key_exists( 'profile_mutation', $snapshot ) || false !== $snapshot['profile_mutation'] ) { $errors[] = 'inventory_profile_mutation_must_be_false'; }
		if ( ! isset( $snapshot['inventory'] ) || ! is_array( $snapshot['inventory'] ) ) { $errors[] = 'inventory_payload_missing'; return $errors; }
		$inventory = (array) $snapshot['inventory'];
		$postTypes = (array) ( $inventory['post_types'] ?? array() );
		$taxonomies = (array) ( $inventory['taxonomies'] ?? array() );
		$languages = (array) ( $inventory['languages'] ?? array() );
		$queryBuilder = (array) ( $inventory['query_builder'] ?? array() );
		$queries = (array) ( $queryBuilder['queries'] ?? array() );
		$completeness = (array) ( $inventory['completeness'] ?? array() );
		if ( count( $postTypes ) > RuntimeInventory::MAX_POST_TYPES ) { $errors[] = 'inventory_post_type_limit_exceeded'; }
		if ( count( $taxonomies ) > RuntimeInventory::MAX_TAXONOMIES ) { $errors[] = 'inventory_taxonomy_limit_exceeded'; }
		if ( count( $languages ) > RuntimeInventory::MAX_LANGUAGES ) { $errors[] = 'inventory_language_limit_exceeded'; }
		if ( count( $queries ) > RuntimeInventory::MAX_QUERIES ) { $errors[] = 'inventory_query_limit_exceeded'; }

		$archiveTranslationCount = 0;
		foreach ( $postTypes as $record ) {
			if ( ! is_array( $record ) ) { continue; }
			foreach ( array_keys( (array) ( $record['archive_paths'] ?? array() ) ) as $key ) { if ( 'current' !== (string) $key ) { $archiveTranslationCount++; } }
		}
		$expected = array(
			'post_types' => array( count( $postTypes ), RuntimeInventory::MAX_POST_TYPES ),
			'taxonomies' => array( count( $taxonomies ), RuntimeInventory::MAX_TAXONOMIES ),
			'languages' => array( count( $languages ), RuntimeInventory::MAX_LANGUAGES ),
			'query_builder' => array( count( $queries ), RuntimeInventory::MAX_QUERIES ),
			'archive_path_translations' => array( $archiveTranslationCount, RuntimeInventory::MAX_ARCHIVE_PATH_TRANSLATIONS ),
		);
		foreach ( $expected as $section => $values ) {
			$errors = array_merge( $errors, $this->validateCompletenessRecord( (array) ( $completeness[ $section ] ?? array() ), $section, $values[0], $values[1] ) );
		}

		$conflicts = (array) ( $queryBuilder['identity_conflicts'] ?? array() );
		$conflictCount = (int) ( $queryBuilder['identity_conflict_count'] ?? 0 );
		if ( $conflictCount < 0 || count( $conflicts ) > RuntimeInventory::MAX_QUERY_IDENTITY_CONFLICTS || $conflictCount < count( $conflicts ) ) { $errors[] = 'inventory_query_identity_conflict_metadata_invalid'; }
		if ( $conflictCount > 0 && ! $conflicts ) { $errors[] = 'inventory_query_identity_conflict_details_missing'; }
		if ( ( true === ( $queryBuilder['identity_conflicts_truncated'] ?? false ) ) !== ( $conflictCount > count( $conflicts ) ) ) { $errors[] = 'inventory_query_identity_conflict_truncation_mismatch'; }
		$seenConflictKeys = array();
		foreach ( $conflicts as $conflict ) {
			if ( ! is_array( $conflict ) ) { $errors[] = 'inventory_query_identity_conflict_record_invalid'; continue; }
			$key = sanitize_key( (string) ( $conflict['identity_key'] ?? '' ) );
			if ( '' === $key || isset( $seenConflictKeys[ $key ] ) || (int) ( $conflict['count'] ?? 0 ) < 2 ) { $errors[] = 'inventory_query_identity_conflict_record_invalid'; continue; }
			$seenConflictKeys[ $key ] = true;
		}
		$includedDuplicates = $this->duplicateQueryKeys( $queries );
		$conflictIndex = $this->queryConflictIndex( $conflicts );
		foreach ( $includedDuplicates as $key ) { if ( ! isset( $conflictIndex[ $key ] ) ) { $errors[] = 'inventory_query_identity_collision_metadata_mismatch'; break; } }

		$fingerprint = (string) ( $snapshot['snapshot_fingerprint'] ?? '' );
		if ( '' === $fingerprint || ! hash_equals( $this->inventoryFingerprint( $inventory ), $fingerprint ) ) { $errors[] = 'inventory_fingerprint_mismatch'; }
		return array_values( array_unique( $errors ) );
	}

	private function validateCompletenessRecord( array $record, string $section, $actualIncluded, int $expectedLimit ): array {
		$errors = array();
		$prefix = 'inventory_' . sanitize_key( $section ) . '_completeness_';
		foreach ( array( 'observed_count', 'included_count', 'limit', 'truncated' ) as $key ) { if ( ! array_key_exists( $key, $record ) ) { $errors[] = $prefix . 'missing'; return $errors; } }
		$observed = (int) $record['observed_count'];
		$included = (int) $record['included_count'];
		$limit = (int) $record['limit'];
		$truncated = true === $record['truncated'];
		if ( $observed < 0 || $included < 0 || $observed < $included ) { $errors[] = $prefix . 'counts_invalid'; }
		if ( $expectedLimit !== $limit ) { $errors[] = $prefix . 'limit_mismatch'; }
		if ( null !== $actualIncluded && $included !== (int) $actualIncluded ) { $errors[] = $prefix . 'included_count_mismatch'; }
		if ( $truncated !== ( $observed > $included ) ) { $errors[] = $prefix . 'truncation_mismatch'; }
		return $errors;
	}

	private function inventoryFingerprint( array $inventory ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '{}' );
	}

	private function invalidResult( array $errors ): array {
		return array( 'contract' => self::CONTRACT, 'authorizing' => false, 'read_only' => true, 'profile_mutation' => false, 'requires_operator_review' => true, 'state' => 'invalid_inventory', 'errors' => array_values( array_unique( $errors ) ), 'summary' => array( 'blocking' => count( $errors ), 'warnings' => 0, 'info' => 0 ), 'findings' => array(), 'disabled_candidates' => array() );
	}

	private function inventorySafeForCandidates( array $inventory ): bool {
		foreach ( array( 'post_types', 'taxonomies', 'languages', 'query_builder', 'archive_path_translations' ) as $section ) { if ( $this->sectionTruncated( $inventory, $section ) ) { return false; } }
		$queryBuilder = (array) ( $inventory['query_builder'] ?? array() );
		return ! empty( $queryBuilder['available'] ) && 0 === (int) ( $queryBuilder['identity_conflict_count'] ?? 0 );
	}

	private function sectionTruncated( array $inventory, string $section ): bool {
		$record = (array) ( (array) ( $inventory['completeness'] ?? array() )[ $section ] ?? array() );
		return ! empty( $record['truncated'] );
	}

	private function sectionComparable( array $before, array $after, string $section ): bool { return ! $this->sectionTruncated( $before, $section ) && ! $this->sectionTruncated( $after, $section ); }

	private function queryIndex( array $queries, array $conflicts ): array {
		$out = array();
		foreach ( array_slice( $queries, 0, RuntimeInventory::MAX_QUERIES ) as $query ) {
			if ( ! is_array( $query ) ) { continue; }
			$key = $this->queryIdentityKey( $query );
			if ( '' === $key || isset( $conflicts[ $key ] ) || isset( $out[ $key ] ) ) { continue; }
			$out[ $key ] = $query;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function queryConflictIndex( array $conflicts ): array {
		$out = array();
		foreach ( array_slice( $conflicts, 0, RuntimeInventory::MAX_QUERY_IDENTITY_CONFLICTS ) as $conflict ) {
			if ( ! is_array( $conflict ) ) { continue; }
			$key = sanitize_key( (string) ( $conflict['identity_key'] ?? '' ) );
			if ( '' !== $key ) { $out[ $key ] = $conflict; }
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function duplicateQueryKeys( array $queries ): array {
		$counts = array();
		foreach ( $queries as $query ) {
			if ( ! is_array( $query ) ) { continue; }
			$key = $this->queryIdentityKey( $query );
			if ( '' === $key ) { continue; }
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		$out = array(); foreach ( $counts as $key => $count ) { if ( $count > 1 ) { $out[] = $key; } } sort( $out, SORT_STRING ); return $out;
	}

	private function queryIdentityKey( array $query ): string { return sanitize_key( (string) ( ( $query['identity_key'] ?? '' ) ?: ( ( $query['custom_query_id'] ?? '' ) ?: ( $query['id'] ?? '' ) ) ) ); }

	private function languageIndex( array $languages ): array {
		$out = array();
		foreach ( array_slice( $languages, 0, RuntimeInventory::MAX_LANGUAGES ) as $language ) {
			if ( ! is_array( $language ) ) { continue; }
			$code = sanitize_key( (string) ( $language['code'] ?? '' ) );
			if ( '' !== $code ) { $out[ $code ] = $language; }
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function cleanKeys( array $values ): array { $out = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $values ) ) ) ) ); sort( $out, SORT_STRING ); return $out; }
	private function normalizePath( string $path ): string { $path = rawurldecode( trim( $path ) ); if ( '' === $path ) { return ''; } $parsed = parse_url( $path, PHP_URL_PATH ); if ( is_string( $parsed ) && '' !== $parsed ) { $path = $parsed; } $path = '/' . trim( $path, '/' ); return '/' === $path ? '/' : $path . '/'; }
	private function stableHash( array $value ): string { $this->sortRecursive( $value ); $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return hash( 'sha256', is_string( $json ) ? $json : '{}' ); }
	private function sortRecursive( array &$value ): void { foreach ( $value as &$child ) { if ( is_array( $child ) ) { $this->sortRecursive( $child ); } } unset( $child ); if ( array_keys( $value ) !== array_keys( array_values( $value ) ) ) { ksort( $value, SORT_STRING ); } }
	private function countSeverity( array $findings, string $severity ): int { $count = 0; foreach ( $findings as $finding ) { if ( $severity === (string) ( $finding['severity'] ?? '' ) ) { $count++; } } return $count; }
	private function finding( array &$findings, string $severity, string $code, string $subject, array $details = array() ): void { if ( count( $findings ) >= self::MAX_FINDINGS ) { return; } $findings[] = $this->findingValue( $severity, $code, $subject, $details ); }
	private function findingArray( array &$findings, array $finding ): void { if ( count( $findings ) < self::MAX_FINDINGS ) { $findings[] = $finding; } }
	private function findingValue( string $severity, string $code, string $subject, array $details = array() ): array { return array( 'severity' => in_array( $severity, array( 'info', 'warning', 'blocking' ), true ) ? $severity : 'warning', 'code' => sanitize_key( $code ), 'subject' => sanitize_text_field( $subject ), 'details' => $details ); }
}
