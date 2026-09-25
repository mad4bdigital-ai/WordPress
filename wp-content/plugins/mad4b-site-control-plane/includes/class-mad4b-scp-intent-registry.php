<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Job-scoped, versioned Intent Registry over the immutable Artifact Registry.
 *
 * The registry deliberately models many-to-many ownership and treats
 * cannibalization as derived evidence, never as a consequence of overlap alone.
 */
final class MAD4B_SCP_Intent_Registry {
	const CONTRACT = 'mad4b.intent-ownership.v1';
	const SNAPSHOT_CONTRACT = 'mad4b.intent-registry-snapshot.v1';
	const MAX_RELATIONS = 500;
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
		self::register( 'mad4b/intent-registry-current', 'Get Intent Registry Snapshot', 'current', true );
		self::register( 'mad4b/intent-conflicts-analyze', 'Analyze Intent Ownership Conflicts', 'analyze', true );
		self::register( 'mad4b/intent-registry-reconcile', 'Reconcile Intent Registry Snapshot', 'reconcile', false );
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

	public static function current( $input ) {
		$job_id = self::uuid( $input['job_id'] ?? '' );
		if ( '' === $job_id ) return new WP_Error( 'mad4b_intent_job_invalid', 'ContentJob ID is invalid.' );
		$current = self::current_snapshot( $job_id );
		if ( is_wp_error( $current ) ) return $current;
		return array(
			'contract' => self::SNAPSHOT_CONTRACT,
			'job_id' => $job_id,
			'registry' => $current,
			'mutation_performed' => false,
		);
	}

	public static function analyze( $input ) {
		$relations = isset( $input['relations'] ) && is_array( $input['relations'] ) ? $input['relations'] : array();
		$normalized = self::normalize_relations( $relations, array() );
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

	public static function reconcile( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) {
			return new WP_Error( 'mad4b_intent_artifact_registry_unavailable', 'Artifact Registry is unavailable.' );
		}
		$job_id = self::uuid( $input['job_id'] ?? '' );
		if ( '' === $job_id ) return new WP_Error( 'mad4b_intent_job_invalid', 'ContentJob ID is invalid.' );
		$relations = isset( $input['relations'] ) && is_array( $input['relations'] ) ? $input['relations'] : null;
		if ( null === $relations ) return new WP_Error( 'mad4b_intent_relations_required', 'Intent relations snapshot is required.' );

		$current = self::current_snapshot( $job_id );
		if ( is_wp_error( $current ) ) return $current;
		$expected_id = strtolower( trim( (string) ( $input['expected_registry_artifact_id'] ?? '' ) ) );
		$expected_sha = strtolower( trim( (string) ( $input['expected_registry_sha256'] ?? '' ) ) );
		$current_id = is_array( $current ) ? (string) ( $current['artifact_id'] ?? '' ) : '';
		$current_sha = is_array( $current ) ? (string) ( $current['content_sha256'] ?? '' ) : '';
		if ( '' !== $current_id ) {
			if ( ! hash_equals( $current_id, $expected_id ) || ! hash_equals( $current_sha, $expected_sha ) ) {
				return new WP_Error( 'mad4b_intent_registry_stale', 'Intent Registry changed since the requested reconcile plan.' );
			}
		} elseif ( '' !== $expected_id || '' !== $expected_sha ) {
			return new WP_Error( 'mad4b_intent_registry_expected_absent', 'Intent Registry is absent but caller supplied prior identity.' );
		}

		$previous_relations = array();
		if ( is_array( $current ) && isset( $current['payload']['relations'] ) && is_array( $current['payload']['relations'] ) ) {
			$previous_relations = $current['payload']['relations'];
		}
		$normalized = self::normalize_relations( $relations, $previous_relations );
		if ( is_wp_error( $normalized ) ) return $normalized;
		$analysis = self::analyze_relations( $normalized );
		$payload = array(
			'contract' => self::SNAPSHOT_CONTRACT,
			'relations' => $normalized,
			'conflict_analysis' => $analysis,
			'relation_count' => count( $normalized ),
			'many_to_many' => true,
			'cannibalization_is_derived' => true,
			'overlap_alone_is_conflict' => false,
		);
		$result = MAD4B_SCP_Artifacts::append_artifact(
			array(
				'job_id' => $job_id,
				'artifact_type' => 'intent_registry',
				'payload' => $payload,
				'metadata' => array(
					'previous_registry_artifact_id' => $current_id,
					'previous_registry_sha256' => $current_sha,
				),
				'producer_stage' => 'SITE_DISCOVERY',
				'producer_ref' => 'mad4b-intent-registry',
				'reason' => 'reconcile versioned many-to-many intent ownership registry',
			)
		);
		if ( is_wp_error( $result ) ) return $result;
		return array(
			'contract' => self::CONTRACT,
			'registry_artifact_id' => (string) $result['artifact']['artifact_id'],
			'registry_sha256' => (string) $result['artifact']['content_sha256'],
			'relation_count' => count( $normalized ),
			'conflict_analysis' => $analysis,
			'mutation_performed' => true,
		);
	}

	public static function normalize_relations( array $relations, array $previous_relations = array() ) {
		if ( count( $relations ) > self::MAX_RELATIONS ) {
			return new WP_Error( 'mad4b_intent_relation_limit', 'Intent Registry relation count exceeds bounded limit.' );
		}
		$previous = array();
		foreach ( $previous_relations as $row ) {
			if ( ! is_array( $row ) || empty( $row['relation_id'] ) ) continue;
			$previous[ (string) $row['relation_id'] ] = $row;
		}
		$out = array();
		$seen = array();
		foreach ( $relations as $row ) {
			if ( ! is_array( $row ) ) return new WP_Error( 'mad4b_intent_relation_invalid', 'Intent relation must be an object.' );
			$relation_id = self::bounded_key( $row['relation_id'] ?? '', 191 );
			$intent_id = self::bounded_key( $row['intent_id'] ?? '', 191 );
			$content_id = self::bounded_key( $row['content_id'] ?? '', 191 );
			$site = self::bounded_string( $row['site'] ?? '', 191 );
			$locale = self::bounded_key( $row['locale'] ?? '', 32 );
			$market = self::bounded_key( $row['market'] ?? '', 64 );
			$role = strtoupper( self::bounded_key( $row['role'] ?? '', 64 ) );
			$source = strtolower( self::bounded_key( $row['source'] ?? '', 32 ) );
			$confidence = isset( $row['confidence'] ) ? (float) $row['confidence'] : -1.0;
			$valid_from = self::bounded_string( $row['valid_from'] ?? '', 64 );
			$valid_to = self::bounded_string( $row['valid_to'] ?? '', 64 );
			if ( '' === $relation_id || '' === $intent_id || '' === $content_id || '' === $site || '' === $locale || '' === $market ) {
				return new WP_Error( 'mad4b_intent_relation_identity_invalid', 'Intent relation identity is incomplete.' );
			}
			if ( isset( $seen[ $relation_id ] ) ) return new WP_Error( 'mad4b_intent_relation_duplicate', 'Intent relation ID is duplicated.' );
			$seen[ $relation_id ] = true;
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
			$signals = self::normalize_signals( isset( $row['analysis_signals'] ) && is_array( $row['analysis_signals'] ) ? $row['analysis_signals'] : array() );
			$normalized = array(
				'relation_id' => $relation_id,
				'intent_id' => $intent_id,
				'content_id' => $content_id,
				'site' => $site,
				'locale' => $locale,
				'market' => $market,
				'role' => $role,
				'confidence' => round( $confidence, 6 ),
				'evidence_refs' => $evidence,
				'valid_from' => $valid_from,
				'valid_to' => $valid_to,
				'source' => $source,
				'analysis_signals' => $signals,
			);
			$prior = isset( $previous[ $relation_id ] ) && is_array( $previous[ $relation_id ] ) ? $previous[ $relation_id ] : null;
			$revision = 1;
			if ( $prior ) {
				$prior_compare = $prior;
				unset( $prior_compare['revision'] );
				$revision = (int) ( $prior['revision'] ?? 1 );
				if ( self::stable_json( $prior_compare ) !== self::stable_json( $normalized ) ) $revision++;
			}
			$normalized['revision'] = max( 1, $revision );
			$out[] = $normalized;
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['intent_id'] . " " . $a['locale'] . " " . $a['market'] . " " . $a['relation_id'], $b['intent_id'] . " " . $b['locale'] . " " . $b['market'] . " " . $b['relation_id'] );
		} );
		return $out;
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
					$signals = isset( $row['analysis_signals'] ) ? $row['analysis_signals'] : array();
					$score = 0;
					foreach ( array( 'serp_overlap','same_page_purpose','indexable','canonical_competes','performance_overlap' ) as $signal ) {
						if ( ! empty( $signals[ $signal ] ) ) $score++;
					}
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

	private static function current_snapshot( $job_id ) {
		if ( ! class_exists( 'MAD4B_SCP_Artifacts' ) ) return null;
		$list = MAD4B_SCP_Artifacts::list_artifacts( array( 'job_id' => $job_id, 'artifact_type' => 'intent_registry' ) );
		if ( is_wp_error( $list ) ) return $list;
		$items = isset( $list['items'] ) && is_array( $list['items'] ) ? $list['items'] : array();
		for ( $i = count( $items ) - 1; $i >= 0; $i-- ) {
			if ( 'active' === (string) ( $items[ $i ]['status'] ?? '' ) ) return $items[ $i ];
		}
		return null;
	}

	private static function normalize_signals( array $signals ) {
		$out = array();
		foreach ( array( 'serp_overlap','same_page_purpose','indexable','canonical_competes','performance_overlap' ) as $key ) {
			$out[ $key ] = ! empty( $signals[ $key ] );
		}
		return $out;
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

	private static function uuid( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
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
