<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-global, versioned Intent Registry.
 *
 * The v11 intent_relations table is the authoritative registry. Artifact
 * snapshots may reference this state later, but they never replace authority.
 * Intent ownership is many-to-many and cannibalization is derived evidence.
 */
final class MAD4B_SCP_Intent_Registry {
	const CONTRACT = 'mad4b.intent-ownership.v1';
	const REGISTRY_CONTRACT = 'mad4b.intent-registry.v1';
	const MAX_RELATIONS_PER_SCOPE = 500;
	const MAX_EVIDENCE_REFS = 32;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 41 );
	}

	public static function roles() {
		return array(
			'PRIMARY_OWNER',
			'SUPPORTING',
			'INFORMATIONAL_OWNER',
			'TRANSACTIONAL_OWNER',
			'LOCALIZED_OWNER',
			'COMPARISON',
			'FAQ_SUPPORT',
			'HISTORICAL_RETIRED',
		);
	}

	public static function sources() {
		return array( 'observed', 'operator', 'model', 'imported' );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/intent-registry-current', 'Get Intent Registry State', 'current', true );
		self::register( 'mad4b/intent-conflicts-analyze', 'Analyze Intent Ownership Conflicts', 'analyze', true );
		self::register( 'mad4b/intent-registry-reconcile', 'Reconcile Intent Registry Scope', 'reconcile', false );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the Feature 007 many-to-many Intent Registry.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-write',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $readonly ? array( 'MAD4B_SCP_Policy', 'can_read' ) : array( 'MAD4B_SCP_Policy', 'can_mutate' ),
				'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
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

	private static function schema_ready() {
		return class_exists( 'MAD4B_SCP_Schema' )
			&& MAD4B_SCP_Schema::VERSION >= 11
			&& MAD4B_SCP_Schema::critical_ready();
	}

	private static function site_uuid() {
		$uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ? $uuid : '';
	}

	public static function current( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_intent_schema_unavailable', 'Intent Registry schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_intent_site_identity_unavailable', 'Site Profile identity is unavailable.' );

		$intent_id = self::bounded_key( $input['intent_id'] ?? '', 191 );
		$locale = self::bounded_key( $input['locale'] ?? '', 32 );
		$market = self::bounded_key( $input['market'] ?? '', 64 );
		$content_id = self::bounded_key( $input['content_id'] ?? '', 191 );
		$limit = isset( $input['limit'] ) ? max( 1, min( 1000, absint( $input['limit'] ) ) ) : 200;
		$t = MAD4B_SCP_Schema::tables();

		$where = array( "site_uuid=%s", "valid_to=''", 'current_relation_key IS NOT NULL' );
		$args = array( $site_uuid );
		if ( '' !== $intent_id ) { $where[] = 'intent_id=%s'; $args[] = $intent_id; }
		if ( '' !== $locale ) { $where[] = 'locale=%s'; $args[] = $locale; }
		if ( '' !== $market ) { $where[] = 'market=%s'; $args[] = $market; }
		if ( '' !== $content_id ) { $where[] = 'content_id=%s'; $args[] = $content_id; }
		$args[] = $limit;

		$sql = "SELECT * FROM {$t['intent_relations']} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY intent_id ASC,locale ASC,market ASC,content_id ASC,revision ASC,id ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$relations = array_map( array( __CLASS__, 'normalize_db_row' ), is_array( $rows ) ? $rows : array() );
		$scope_sha = self::scope_sha256( $relations );

		return array(
			'contract' => self::REGISTRY_CONTRACT,
			'site_uuid' => $site_uuid,
			'relations' => $relations,
			'relation_count' => count( $relations ),
			'scope_sha256' => $scope_sha,
			'complete_for_query' => count( $relations ) < $limit,
			'many_to_many' => true,
			'cannibalization_is_derived' => true,
			'overlap_alone_is_conflict' => false,
			'mutation_performed' => false,
		);
	}

	public static function analyze( $input ) {
		$relations = isset( $input['relations'] ) && is_array( $input['relations'] ) ? $input['relations'] : array();
		$normalized = self::normalize_analysis_relations( $relations );
		if ( is_wp_error( $normalized ) ) return $normalized;
		return array(
			'contract' => 'mad4b.intent-conflict-analysis.v1',
			'outcomes' => self::analyze_relations( $normalized ),
			'relation_count' => count( $normalized ),
			'cannibalization_is_derived' => true,
			'overlap_alone_is_conflict' => false,
			'mutation_performed' => false,
		);
	}

	/**
	 * Reconcile one complete intent+locale+market scope.
	 *
	 * Caller supplies the exact current scope hash (or ABSENT). The relation
	 * set is the desired active state for that scope. Historical rows are kept.
	 */
	public static function reconcile( $input ) {
		global $wpdb;
		if ( ! self::schema_ready() ) return new WP_Error( 'mad4b_intent_schema_unavailable', 'Intent Registry schema is not ready.' );
		$site_uuid = self::site_uuid();
		if ( '' === $site_uuid ) return new WP_Error( 'mad4b_intent_site_identity_unavailable', 'Site Profile identity is unavailable.' );

		$intent_id = self::bounded_key( $input['intent_id'] ?? '', 191 );
		$locale = self::bounded_key( $input['locale'] ?? '', 32 );
		$market = self::bounded_key( $input['market'] ?? '', 64 );
		$expected_scope_sha = strtolower( trim( (string) ( $input['expected_scope_sha256'] ?? '' ) ) );
		$desired_raw = isset( $input['relations'] ) && is_array( $input['relations'] ) ? $input['relations'] : null;
		$reason = trim( sanitize_text_field( (string) ( $input['reason'] ?? '' ) ) );
		if ( '' === $intent_id || '' === $locale || '' === $market ) return new WP_Error( 'mad4b_intent_scope_invalid', 'Intent/locale/market scope is incomplete.' );
		if ( null === $desired_raw ) return new WP_Error( 'mad4b_intent_relations_required', 'Complete desired relation set is required.' );
		if ( count( $desired_raw ) > self::MAX_RELATIONS_PER_SCOPE ) return new WP_Error( 'mad4b_intent_relation_limit', 'Intent scope exceeds bounded relation limit.' );
		if ( 'ABSENT' !== $expected_scope_sha && 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_scope_sha ) ) {
			return new WP_Error( 'mad4b_intent_scope_sha_invalid', 'Expected intent scope SHA is invalid.' );
		}
		if ( strlen( $reason ) < 3 || strlen( $reason ) > 500 ) return new WP_Error( 'mad4b_intent_reason_required', 'Reconcile reason is required.' );

		$desired = self::normalize_desired_scope( $desired_raw, $intent_id, $locale, $market );
		if ( is_wp_error( $desired ) ) return $desired;
		$t = MAD4B_SCP_Schema::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$now_iso = gmdate( 'c' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t['intent_relations']} WHERE site_uuid=%s AND locale=%s AND market=%s AND intent_id=%s AND valid_to='' AND current_relation_key IS NOT NULL ORDER BY content_id ASC,revision ASC,id ASC FOR UPDATE",
					$site_uuid,
					$locale,
					$market,
					$intent_id
				),
				ARRAY_A
			);
			$current = array_map( array( __CLASS__, 'normalize_db_row' ), is_array( $rows ) ? $rows : array() );
			$current_sha = self::scope_sha256( $current );
			$expected = empty( $current ) ? 'ABSENT' : $current_sha;
			if ( ! hash_equals( $expected, $expected_scope_sha ) ) throw new RuntimeException( 'intent_scope_stale' );

			$current_by_content = array();
			foreach ( $current as $row ) {
				if ( isset( $current_by_content[ $row['content_id'] ] ) ) throw new RuntimeException( 'intent_scope_multiple_active_relations' );
				$current_by_content[ $row['content_id'] ] = $row;
			}
			$desired_by_content = array();
			foreach ( $desired as $row ) $desired_by_content[ $row['content_id'] ] = $row;

			$closed = array();
			$inserted = array();
			$unchanged = array();

			foreach ( $current_by_content as $content_id => $row ) {
				if ( ! isset( $desired_by_content[ $content_id ] ) ) {
					$changed = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$t['intent_relations']} SET valid_to=%s,current_relation_key=NULL,owner_scope_key=NULL WHERE relation_id=%s AND revision=%d AND site_uuid=%s AND valid_to='' AND current_relation_key IS NOT NULL",
							$now_iso,
							$row['relation_id'],
							(int) $row['revision'],
							$site_uuid
						)
					);
					if ( 1 !== (int) $changed ) throw new RuntimeException( 'intent_relation_close_failed:' . (string) $wpdb->last_error );
					$closed[] = $row['relation_id'];
				}
			}

			foreach ( $desired_by_content as $content_id => $row ) {
				$prior = $current_by_content[ $content_id ] ?? null;
				if ( is_array( $prior ) && hash_equals( self::semantic_sha256( $prior ), self::semantic_sha256( $row ) ) ) {
					$unchanged[] = $prior['relation_id'];
					continue;
				}
				if ( is_array( $prior ) ) {
					$changed = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$t['intent_relations']} SET valid_to=%s,current_relation_key=NULL,owner_scope_key=NULL WHERE relation_id=%s AND revision=%d AND site_uuid=%s AND valid_to='' AND current_relation_key IS NOT NULL",
							$now_iso,
							$prior['relation_id'],
							(int) $prior['revision'],
							$site_uuid
						)
					);
					if ( 1 !== (int) $changed ) throw new RuntimeException( 'intent_relation_close_failed:' . (string) $wpdb->last_error );
					$closed[] = $prior['relation_id'];
				}

				$relation_id = is_array( $prior ) ? (string) $prior['relation_id'] : strtolower( wp_generate_uuid4() );
				$max_revision = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT MAX(revision) FROM {$t['intent_relations']} WHERE site_uuid=%s AND relation_id=%s",
						$site_uuid,
						$relation_id
					)
				);
				$revision = max( 1, $max_revision + 1 );
				$evidence_json = self::stable_json( $row['evidence_refs'] );
				$analysis_signals_json = self::stable_json( $row['analysis_signals'] );
				$relation_sha = self::semantic_sha256( $row );
				$current_relation_key = hash( 'sha256', $site_uuid . "\0" . $locale . "\0" . $market . "\0" . $intent_id . "\0" . $content_id );
				$owner_scope_key = hash( 'sha256', $site_uuid . "\0" . $locale . "\0" . $market . "\0" . $intent_id . "\0" . $row['role'] );

				$ok = $wpdb->insert(
					$t['intent_relations'],
					array(
						'relation_id' => $relation_id,
						'site_uuid' => $site_uuid,
						'locale' => $locale,
						'market' => $market,
						'intent_id' => $intent_id,
						'content_id' => $content_id,
						'role' => $row['role'],
						'confidence' => $row['confidence'],
						'evidence_json' => $evidence_json,
						'analysis_signals_json' => $analysis_signals_json,
						'source' => $row['source'],
						'revision' => $revision,
						'valid_from' => '' !== $row['valid_from'] ? $row['valid_from'] : $now_iso,
						'valid_to' => '',
						'current_relation_key' => $current_relation_key,
						'owner_scope_key' => $owner_scope_key,
						'relation_sha256' => $relation_sha,
						'created_at' => $now,
					),
					array( '%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s' )
				);
				if ( false === $ok ) throw new RuntimeException( 'intent_relation_insert_failed:' . (string) $wpdb->last_error );
				$inserted[] = $relation_id;
			}

			$after_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t['intent_relations']} WHERE site_uuid=%s AND locale=%s AND market=%s AND intent_id=%s AND valid_to='' AND current_relation_key IS NOT NULL ORDER BY content_id ASC,revision ASC,id ASC",
					$site_uuid,
					$locale,
					$market,
					$intent_id
				),
				ARRAY_A
			);
			$after = array_map( array( __CLASS__, 'normalize_db_row' ), is_array( $after_rows ) ? $after_rows : array() );
			if ( count( $after ) !== count( $desired ) ) throw new RuntimeException( 'intent_scope_postcondition_count_mismatch' );
			$after_semantic = array();
			foreach ( $after as $row ) $after_semantic[ $row['content_id'] ] = self::semantic_sha256( $row );
			foreach ( $desired as $row ) {
				if ( ! isset( $after_semantic[ $row['content_id'] ] ) || ! hash_equals( $after_semantic[ $row['content_id'] ], self::semantic_sha256( $row ) ) ) {
					throw new RuntimeException( 'intent_scope_postcondition_mismatch' );
				}
			}

			if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
				$audit = MAD4B_SCP_Audit::record(
					'mad4b/intent-registry-reconcile',
					array(
						'site_uuid' => $site_uuid,
						'intent_id' => $intent_id,
						'locale' => $locale,
						'market' => $market,
						'expected_scope_sha256' => $expected_scope_sha,
						'result_scope_sha256' => self::scope_sha256( $after ),
						'inserted_count' => count( $inserted ),
						'closed_count' => count( $closed ),
						'unchanged_count' => count( $unchanged ),
						'reason' => $reason,
					),
					'ok',
					true
				);
				if ( is_wp_error( $audit ) ) throw new RuntimeException( $audit->get_error_code() );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'intent_registry_commit_failed' );
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::transaction_committed();
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::transaction_rolled_back();
			if ( 'intent_scope_stale' === $e->getMessage() ) return new WP_Error( 'mad4b_intent_scope_stale', 'Intent scope changed since expected state was captured.' );
			if ( 'intent_scope_multiple_active_relations' === $e->getMessage() ) return new WP_Error( 'mad4b_intent_scope_inconsistent', 'Intent scope contains multiple active relations for one content item.' );
			return new WP_Error( 'mad4b_intent_reconcile_failed', 'Unable to reconcile Intent Registry scope.', array( 'cause' => $e->getMessage() ) );
		}

		$analysis = self::analyze_relations( $after );
		return array(
			'contract' => self::CONTRACT,
			'site_uuid' => $site_uuid,
			'intent_id' => $intent_id,
			'locale' => $locale,
			'market' => $market,
			'relations' => $after,
			'relation_count' => count( $after ),
			'scope_sha256' => self::scope_sha256( $after ),
			'inserted_relation_ids' => $inserted,
			'closed_relation_ids' => array_values( array_unique( $closed ) ),
			'unchanged_relation_ids' => $unchanged,
			'conflict_analysis' => $analysis,
			'many_to_many' => true,
			'cannibalization_is_derived' => true,
			'overlap_alone_is_conflict' => false,
			'mutation_performed' => ! empty( $inserted ) || ! empty( $closed ),
		);
	}

	public static function normalize_analysis_relations( array $relations ) {
		if ( count( $relations ) > self::MAX_RELATIONS_PER_SCOPE ) return new WP_Error( 'mad4b_intent_relation_limit', 'Intent relation count exceeds bounded limit.' );
		$out = array();
		$seen = array();
		foreach ( $relations as $row ) {
			if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_intent_relation_invalid', 'Intent relation must be an object.' );
			$normalized = self::normalize_semantic_row( $row );
			if ( is_wp_error( $normalized ) ) return $normalized;
			$key = $normalized['intent_id'] . " " . $normalized['locale'] . " " . $normalized['market'] . " " . $normalized['content_id'];
			if ( isset( $seen[ $key ] ) ) return new WP_Error( 'mad4b_intent_relation_duplicate', 'Intent/content scope is duplicated.' );
			$seen[ $key ] = true;
			$out[] = $normalized;
		}
		usort( $out, array( __CLASS__, 'compare_relations' ) );
		return $out;
	}

	private static function normalize_desired_scope( array $relations, $intent_id, $locale, $market ) {
		$prepared = array();
		foreach ( $relations as $row ) {
			if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_intent_relation_invalid', 'Intent relation must be an object.' );
			$row['intent_id'] = $intent_id;
			$row['locale'] = $locale;
			$row['market'] = $market;
			$prepared[] = $row;
		}
		return self::normalize_analysis_relations( $prepared );
	}

	private static function normalize_semantic_row( array $row ) {
		$intent_id = self::bounded_key( $row['intent_id'] ?? '', 191 );
		$content_id = self::bounded_key( $row['content_id'] ?? '', 191 );
		$locale = self::bounded_key( $row['locale'] ?? '', 32 );
		$market = self::bounded_key( $row['market'] ?? '', 64 );
		$role = strtoupper( self::bounded_key( $row['role'] ?? '', 64 ) );
		$source = strtolower( self::bounded_key( $row['source'] ?? '', 32 ) );
		$confidence = isset( $row['confidence'] ) ? (float) $row['confidence'] : -1.0;
		$valid_from = self::bounded_string( $row['valid_from'] ?? '', 64 );
		$valid_to = self::bounded_string( $row['valid_to'] ?? '', 64 );
		if ( '' === $intent_id || '' === $content_id || '' === $locale || '' === $market ) return new WP_Error( 'mad4b_intent_relation_identity_invalid', 'Intent relation identity is incomplete.' );
		if ( ! in_array( $role, self::roles(), true ) ) return new WP_Error( 'mad4b_intent_role_invalid', 'Intent relation role is invalid.' );
		if ( ! in_array( $source, self::sources(), true ) ) return new WP_Error( 'mad4b_intent_source_invalid', 'Intent relation source is invalid.' );
		if ( $confidence < 0.0 || $confidence > 1.0 ) return new WP_Error( 'mad4b_intent_confidence_invalid', 'Intent relation confidence must be between 0 and 1.' );
		$evidence = isset( $row['evidence_refs'] ) && is_array( $row['evidence_refs'] ) ? array_values( $row['evidence_refs'] ) : array();
		if ( count( $evidence ) > self::MAX_EVIDENCE_REFS ) return new WP_Error( 'mad4b_intent_evidence_limit', 'Intent evidence refs exceed bounded limit.' );
		$evidence = array_values( array_unique( array_filter( array_map( static function ( $value ) {
			$value = trim( (string) $value );
			return strlen( $value ) <= 191 ? $value : '';
		}, $evidence ) ) ) );
		sort( $evidence, SORT_STRING );
		return array(
			'intent_id' => $intent_id,
			'content_id' => $content_id,
			'locale' => $locale,
			'market' => $market,
			'role' => $role,
			'confidence' => round( $confidence, 5 ),
			'evidence_refs' => $evidence,
			'valid_from' => $valid_from,
			'valid_to' => $valid_to,
			'source' => $source,
			'analysis_signals' => self::normalize_signals( isset( $row['analysis_signals'] ) && is_array( $row['analysis_signals'] ) ? $row['analysis_signals'] : array() ),
		);
	}

	public static function analyze_relations( array $relations ) {
		$groups = array();
		foreach ( $relations as $row ) {
			if ( ! is_array( $row ) ) continue;
			if ( 'HISTORICAL_RETIRED' === (string) ( $row['role'] ?? '' ) || '' !== (string) ( $row['valid_to'] ?? '' ) ) continue;
			$key = (string) ( $row['intent_id'] ?? '' ) . " " . (string) ( $row['locale'] ?? '' ) . " " . (string) ( $row['market'] ?? '' );
			if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = array();
			$groups[ $key ][] = $row;
		}
		$out = array();
		foreach ( $groups as $key => $rows ) {
			list( $intent_id, $locale, $market ) = explode( " ", $key, 3 );
			$owners = array_values( array_filter( $rows, static function ( $row ) {
				return in_array( (string) $row['role'], array( 'PRIMARY_OWNER','INFORMATIONAL_OWNER','TRANSACTIONAL_OWNER','LOCALIZED_OWNER','COMPARISON' ), true );
			} ) );
			$support = array_values( array_filter( $rows, static function ( $row ) {
				return in_array( (string) $row['role'], array( 'SUPPORTING','FAQ_SUPPORT' ), true );
			} ) );
			$outcome = 'NO_CONFLICT';
			$reason_codes = array();
			if ( count( $rows ) > 1 && 1 === count( $owners ) && ! empty( $support ) ) {
				$outcome = 'HEALTHY_SUPPORT';
				$reason_codes[] = 'single_owner_with_supporting_relations';
			} elseif ( count( $owners ) > 1 ) {
				$strong = 0;
				foreach ( $owners as $row ) {
					$score = 0;
					$signals = isset( $row['analysis_signals'] ) && is_array( $row['analysis_signals'] ) ? $row['analysis_signals'] : array();
					foreach ( array( 'serp_overlap','same_page_purpose','indexable','canonical_competes','performance_overlap' ) as $signal ) if ( ! empty( $signals[ $signal ] ) ) $score++;
					if ( (float) $row['confidence'] >= 0.75 && $score >= 3 ) $strong++;
				}
				if ( $strong >= 2 ) {
					$outcome = 'CANNIBALIZATION_RISK';
					$reason_codes[] = 'multiple_high_confidence_owner_relations_with_explicit_competition_signals';
				} else {
					$outcome = 'POSSIBLE_OVERLAP';
					$reason_codes[] = 'multiple_owner_relations_without_sufficient_conflict_evidence';
				}
			} elseif ( count( $rows ) > 1 ) {
				$outcome = 'HUMAN_REVIEW';
				$reason_codes[] = 'multiple_non_owner_relations_require_contextual_review';
			}
			$content_ids = array_values( array_unique( array_map( static function ( $row ) { return (string) $row['content_id']; }, $rows ) ) );
			sort( $content_ids, SORT_STRING );
			$out[] = array(
				'intent_id' => $intent_id,
				'locale' => $locale,
				'market' => $market,
				'content_ids' => $content_ids,
				'relation_count' => count( $rows ),
				'owner_relation_count' => count( $owners ),
				'outcome' => $outcome,
				'reason_codes' => $reason_codes,
			);
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['intent_id'] . " " . $a['locale'] . " " . $a['market'], $b['intent_id'] . " " . $b['locale'] . " " . $b['market'] );
		} );
		return $out;
	}

	private static function normalize_db_row( $row ) {
		if ( ! is_array( $row ) ) return array();
		$evidence = json_decode( (string) ( $row['evidence_json'] ?? '' ), true );
		$evidence = is_array( $evidence ) ? array_values( $evidence ) : array();
		$signals = json_decode( (string) ( $row['analysis_signals_json'] ?? '' ), true );
		$signals = is_array( $signals ) ? $signals : array();
		return array(
			'relation_id' => (string) ( $row['relation_id'] ?? '' ),
			'site_uuid' => (string) ( $row['site_uuid'] ?? '' ),
			'intent_id' => (string) ( $row['intent_id'] ?? '' ),
			'content_id' => (string) ( $row['content_id'] ?? '' ),
			'locale' => (string) ( $row['locale'] ?? '' ),
			'market' => (string) ( $row['market'] ?? '' ),
			'role' => (string) ( $row['role'] ?? '' ),
			'confidence' => (float) ( $row['confidence'] ?? 0 ),
			'evidence_refs' => $evidence,
			'analysis_signals' => self::normalize_signals( $signals ),
			'source' => (string) ( $row['source'] ?? '' ),
			'revision' => (int) ( $row['revision'] ?? 0 ),
			'valid_from' => (string) ( $row['valid_from'] ?? '' ),
			'valid_to' => (string) ( $row['valid_to'] ?? '' ),
			'relation_sha256' => (string) ( $row['relation_sha256'] ?? '' ),
		);
	}

	private static function semantic_sha256( array $row ) {
		$semantic = array(
			'intent_id' => (string) ( $row['intent_id'] ?? '' ),
			'content_id' => (string) ( $row['content_id'] ?? '' ),
			'locale' => (string) ( $row['locale'] ?? '' ),
			'market' => (string) ( $row['market'] ?? '' ),
			'role' => (string) ( $row['role'] ?? '' ),
			'confidence' => round( (float) ( $row['confidence'] ?? 0 ), 5 ),
			'evidence_refs' => isset( $row['evidence_refs'] ) && is_array( $row['evidence_refs'] ) ? array_values( $row['evidence_refs'] ) : array(),
			'source' => (string) ( $row['source'] ?? '' ),
			'analysis_signals' => self::normalize_signals( isset( $row['analysis_signals'] ) && is_array( $row['analysis_signals'] ) ? $row['analysis_signals'] : array() ),
		);
		return hash( 'sha256', self::stable_json( $semantic ) );
	}

	private static function scope_sha256( array $relations ) {
		if ( empty( $relations ) ) return 'ABSENT';
		$rows = array();
		foreach ( $relations as $row ) {
			$rows[] = array(
				'relation_id' => (string) ( $row['relation_id'] ?? '' ),
				'content_id' => (string) ( $row['content_id'] ?? '' ),
				'revision' => (int) ( $row['revision'] ?? 0 ),
				'relation_sha256' => (string) ( $row['relation_sha256'] ?? self::semantic_sha256( $row ) ),
			);
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['content_id'] . " " . $a['relation_id'], $b['content_id'] . " " . $b['relation_id'] ); } );
		return hash( 'sha256', self::stable_json( $rows ) );
	}

	private static function compare_relations( $a, $b ) {
		return strcmp(
			(string) $a['intent_id'] . " " . (string) $a['locale'] . " " . (string) $a['market'] . " " . (string) $a['content_id'],
			(string) $b['intent_id'] . " " . (string) $b['locale'] . " " . (string) $b['market'] . " " . (string) $b['content_id']
		);
	}

	private static function normalize_signals( array $signals ) {
		$out = array();
		foreach ( array( 'serp_overlap','same_page_purpose','indexable','canonical_competes','performance_overlap' ) as $key ) $out[ $key ] = ! empty( $signals[ $key ] );
		return $out;
	}

	private static function mysql_datetime( $value ) {
		$ts = strtotime( (string) $value );
		if ( false === $ts ) throw new RuntimeException( 'intent_valid_from_invalid' );
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function bounded_key( $value, $max ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > $max ) return '';
		return preg_replace( '/[^A-Za-z0-9:._\-\/]/', '', $value );
	}

	private static function bounded_string( $value, $max ) {
		$value = trim( (string) $value );
		return strlen( $value ) <= $max ? $value : '';
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

MAD4B_SCP_Intent_Registry::boot();
