<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic, non-authorizing policy proposal classifier.
 *
 * Confidence, names, HTTP methods, schema shape and annotations are evidence
 * only. They never create grants, mounts, certification or execution authority.
 */
final class MAD4B_SCP_Runtime_Policy_Classifier {
	const CONTRACT = 'mad4b.runtime-policy-classifier.v1';
	const PROPOSAL_CONTRACT = 'mad4b.runtime-policy-proposal.v1';
	const CLASSIFIER_VERSION = '1.1.0';
	const REVIEW_CONTRACT = 'mad4b.runtime-policy-review-overlay.v1';
	const REVIEW_OPTION = 'mad4b_runtime_policy_review_overlay_v1';
	const REVIEW_LOCK_OPTION = 'mad4b_runtime_policy_review_overlay_lock_v1';
	const MAX_REVIEWS = 256;

	public static function boot() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 40 );
		}
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/runtime-policy-proposals' ) ) {
			wp_register_ability(
				'mad4b/runtime-policy-proposals',
				array(
					'label' => 'Runtime Policy Proposals',
					'description' => 'Deterministic evidence-derived policy proposals. Proposals and confidence are non-authorizing and never alter grants or mounts.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'proposals' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'ability_name' => array( 'type' => 'string', 'maxLength' => 191 ),
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

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/runtime-policy-review-record' ) ) {
			wp_register_ability(
				'mad4b/runtime-policy-review-record',
				array(
					'label' => 'Record Runtime Policy Review',
					'description' => 'Record an owner review bound to the exact graph generation and proposal. This evidence-only overlay never creates grants, mounts, scopes or certification.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'record_review' ),
					'permission_callback' => array( __CLASS__, 'can_review' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
							'graph_generation_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
							'proposal_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
							'decision' => array( 'type' => 'string', 'enum' => array( 'accept_evidence', 'reject', 'defer' ) ),
							'requested_scope_change' => array( 'type' => 'boolean', 'default' => false ),
							'note' => array( 'type' => 'string', 'maxLength' => 500, 'default' => '' ),
							'expected_revision' => array( 'type' => 'integer', 'minimum' => 0 ),
						),
						'required' => array(
							'ability_name',
							'graph_generation_sha256',
							'proposal_sha256',
							'decision',
							'expected_revision',
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
						'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					),
				)
			);
		}
	}

	public static function proposals( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! class_exists( 'MAD4B_SCP_Runtime_Evidence_Graph' ) ) {
			return new WP_Error( 'mad4b_runtime_evidence_graph_unavailable', 'Runtime evidence graph is unavailable.' );
		}

		$graph = MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
		if ( is_wp_error( $graph ) ) return $graph;

		$filter = isset( $input['ability_name'] ) ? trim( (string) $input['ability_name'] ) : '';
		$overlay = self::stored_overlay();
		$filtered = apply_filters( 'mad4b_scp_reviewed_policy_overlay', $overlay );
		if ( is_array( $filtered ) ) $overlay = $filtered;

		$nodes = isset( $graph['nodes']['abilities'] ) && is_array( $graph['nodes']['abilities'] )
			? $graph['nodes']['abilities']
			: array();

		$rows = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) ) continue;

			$name = (string) $node['id'];
			if ( '' !== $filter && $filter !== $name ) continue;

			$conformance = apply_filters( 'mad4b_scp_runtime_policy_conformance', array(), $name, $node, $graph );
			if ( ! is_array( $conformance ) ) $conformance = array();

			$features = self::features_from_node( $node, $conformance );
			$proposal = self::classify_features( $features );
			$proposal['ability_name'] = $name;
			$proposal['graph_generation_sha256'] = isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '';
			$proposal['evidence_refs'] = array_values(
				array_filter(
					array(
						isset( $node['descriptor_generation_sha256'] ) ? (string) $node['descriptor_generation_sha256'] : '',
						isset( $node['classification_sha256'] ) ? (string) $node['classification_sha256'] : '',
						isset( $conformance['evidence_sha256'] ) ? (string) $conformance['evidence_sha256'] : '',
					)
				)
			);
			$proposal['reviewed_overlay'] = self::overlay_projection( $name, $overlay, $proposal, $proposal['graph_generation_sha256'] );
			$proposal['proposal_sha256'] = self::digest( self::PROPOSAL_CONTRACT, $proposal );
			$rows[] = $proposal;
		}

		return array(
			'contract' => self::CONTRACT,
			'proposal_contract' => self::PROPOSAL_CONTRACT,
			'classifier_version' => self::CLASSIFIER_VERSION,
			'graph_generation_sha256' => isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '',
			'proposals' => $rows,
			'confidence_is_safety_proof' => false,
			'operation_names_create_authority' => false,
			'schema_infers_privilege' => false,
			'annotations_create_authority' => false,
			'grants_changed' => false,
			'mounts_changed' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function can_review( $input = null ) {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public static function review_status() {
		$store = self::review_store();
		return array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => (int) $store['revision'],
			'review_count' => count( $store['reviews'] ),
			'authorizing' => false,
			'grants_changed' => false,
			'mounts_changed' => false,
			'scopes_changed' => false,
			'certifications_changed' => false,
		);
	}

	public static function record_review( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! self::can_review() ) {
			return new WP_Error( 'mad4b_runtime_policy_review_admin_required', 'Administrator capability is required to record a runtime policy review.' );
		}

		foreach ( array( 'graph_generation_sha256', 'proposal_sha256' ) as $field ) {
			$value = isset( $input[ $field ] ) ? (string) $input[ $field ] : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
				return new WP_Error( 'mad4b_runtime_policy_review_digest_invalid', 'Runtime policy review digest is invalid.' );
			}
		}

		$name = isset( $input['ability_name'] ) ? trim( (string) $input['ability_name'] ) : '';
		if ( '' === $name || strlen( $name ) > 191 ) {
			return new WP_Error( 'mad4b_runtime_policy_review_ability_invalid', 'Runtime policy review ability identity is invalid.' );
		}

		$decision = isset( $input['decision'] ) ? sanitize_key( (string) $input['decision'] ) : '';
		if ( ! in_array( $decision, array( 'accept_evidence', 'reject', 'defer' ), true ) ) {
			return new WP_Error( 'mad4b_runtime_policy_review_decision_invalid', 'Runtime policy review decision is invalid.' );
		}

		$token = self::acquire_review_lock();
		if ( is_wp_error( $token ) ) return $token;

		$before = self::review_store();
		$expected = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : -1;
		if ( $expected !== (int) $before['revision'] ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_stale', 'Runtime policy review ledger changed since it was loaded.' );
		}

		$proposal_set = self::proposals( array( 'ability_name' => $name ) );
		if ( is_wp_error( $proposal_set ) ) {
			self::release_review_lock( $token );
			return $proposal_set;
		}

		$proposal = isset( $proposal_set['proposals'][0] ) && is_array( $proposal_set['proposals'][0] )
			? $proposal_set['proposals'][0]
			: array();

		if ( empty( $proposal ) || $name !== ( isset( $proposal['ability_name'] ) ? (string) $proposal['ability_name'] : '' ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_proposal_missing', 'Runtime policy proposal is unavailable for this ability.' );
		}

		if ( ! hash_equals( (string) $proposal_set['graph_generation_sha256'], (string) $input['graph_generation_sha256'] ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_graph_stale', 'Runtime evidence graph changed before review could be recorded.' );
		}

		if ( ! hash_equals( (string) $proposal['proposal_sha256'], (string) $input['proposal_sha256'] ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_proposal_stale', 'Runtime policy proposal changed before review could be recorded.' );
		}

		if (
			'accept_evidence' === $decision
			&& ! empty( $proposal['owner_review_required'] )
			&& 'high' === ( isset( $proposal['risk'] ) ? (string) $proposal['risk'] : '' )
		) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_high_risk_cannot_auto_accept', 'High-risk evidence may be reviewed but cannot be accepted as automatic classification.' );
		}

		$reviews = $before['reviews'];
		if ( ! isset( $reviews[ $name ] ) && count( $reviews ) >= self::MAX_REVIEWS ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_limit', 'Runtime policy review ledger is full.' );
		}

		$record = array(
			'ability_name' => $name,
			'graph_generation_sha256' => (string) $proposal_set['graph_generation_sha256'],
			'proposal_sha256' => (string) $proposal['proposal_sha256'],
			'classification' => (string) $proposal['classification'],
			'risk' => (string) $proposal['risk'],
			'decision' => $decision,
			'requested_scope_change' => ! empty( $input['requested_scope_change'] ),
			'note' => isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '',
			'reviewed_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'reviewed_at' => function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
			'authority_effect' => 'none',
			'creates_grant' => false,
			'creates_mount' => false,
			'creates_scope' => false,
			'creates_certification' => false,
		);
		$record['review_sha256'] = self::digest( self::REVIEW_CONTRACT, $record );

		$reviews[ $name ] = $record;
		ksort( $reviews, SORT_STRING );

		$next = array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => (int) $before['revision'] + 1,
			'reviews' => $reviews,
			'authorizing' => false,
		);

		$persisted = function_exists( 'update_option' ) ? update_option( self::REVIEW_OPTION, $next, false ) : false;
		$readback = self::review_store();

		if ( false === $persisted && $readback !== $next ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_persist_failed', 'Runtime policy review could not be persisted.' );
		}

		if (
			(int) $readback['revision'] !== (int) $next['revision']
			|| ! isset( $readback['reviews'][ $name ] )
			|| ! hash_equals( $record['review_sha256'], (string) $readback['reviews'][ $name ]['review_sha256'] )
		) {
			if ( function_exists( 'update_option' ) ) update_option( self::REVIEW_OPTION, $before, false );
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_readback_failed', 'Runtime policy review readback did not match the committed review.' );
		}

		if (
			class_exists( 'MAD4B_SCP_Audit' )
			&& method_exists( 'MAD4B_SCP_Audit', 'storage_status' )
			&& method_exists( 'MAD4B_SCP_Audit', 'record' )
		) {
			$status = MAD4B_SCP_Audit::storage_status();
			if ( is_array( $status ) && ! empty( $status['ready'] ) ) {
				$audit = MAD4B_SCP_Audit::record(
					'runtime_policy_review_recorded',
					array(
						'ability_name' => $name,
						'revision' => (int) $next['revision'],
						'graph_generation_sha256' => $record['graph_generation_sha256'],
						'proposal_sha256' => $record['proposal_sha256'],
						'review_sha256' => $record['review_sha256'],
						'decision' => $decision,
						'requested_scope_change' => $record['requested_scope_change'],
						'authority_effect' => 'none',
					),
					'ok'
				);

				if ( is_wp_error( $audit ) ) {
					if ( function_exists( 'update_option' ) ) update_option( self::REVIEW_OPTION, $before, false );
					self::release_review_lock( $token );
					return new WP_Error( 'mad4b_runtime_policy_review_audit_failed', 'Runtime policy review was rolled back because audit evidence could not be recorded.' );
				}
			}
		}

		self::release_review_lock( $token );

		return array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => (int) $next['revision'],
			'review' => $record,
			'grants_changed' => false,
			'mounts_changed' => false,
			'scopes_changed' => false,
			'certifications_changed' => false,
			'authorizing' => false,
			'mutation_performed' => true,
		);
	}

	private static function stored_overlay() {
		$store = self::review_store();
		return $store['reviews'];
	}

	private static function review_store() {
		$value = function_exists( 'get_option' ) ? get_option( self::REVIEW_OPTION, array() ) : array();

		if (
			! is_array( $value )
			|| self::REVIEW_CONTRACT !== ( isset( $value['contract'] ) ? (string) $value['contract'] : '' )
			|| ! isset( $value['reviews'] )
			|| ! is_array( $value['reviews'] )
		) {
			return array(
				'contract' => self::REVIEW_CONTRACT,
				'revision' => 0,
				'reviews' => array(),
				'authorizing' => false,
			);
		}

		$out = array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => max( 0, (int) ( isset( $value['revision'] ) ? $value['revision'] : 0 ) ),
			'reviews' => array(),
			'authorizing' => false,
		);

		foreach ( array_slice( $value['reviews'], 0, self::MAX_REVIEWS, true ) as $name => $row ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $row ) ) continue;
			if ( empty( $row['review_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $row['review_sha256'] ) ) continue;
			$out['reviews'][ $name ] = $row;
		}

		ksort( $out['reviews'], SORT_STRING );
		return $out;
	}

	private static function acquire_review_lock() {
		$token = function_exists( 'wp_generate_uuid4' )
			? strtolower( wp_generate_uuid4() )
			: hash( 'sha256', microtime( true ) . ':' . mt_rand() );

		$record = array(
			'token' => $token,
			'expires' => time() + 30,
		);

		if ( function_exists( 'add_option' ) && add_option( self::REVIEW_LOCK_OPTION, $record, '', false ) ) {
			return $token;
		}

		$current = function_exists( 'get_option' ) ? get_option( self::REVIEW_LOCK_OPTION, array() ) : array();
		if (
			is_array( $current )
			&& isset( $current['expires'] )
			&& (int) $current['expires'] < time()
			&& function_exists( 'delete_option' )
		) {
			delete_option( self::REVIEW_LOCK_OPTION );
			if ( function_exists( 'add_option' ) && add_option( self::REVIEW_LOCK_OPTION, $record, '', false ) ) {
				return $token;
			}
		}

		return new WP_Error( 'mad4b_runtime_policy_review_busy', 'Another runtime policy review is currently being committed.' );
	}

	private static function release_review_lock( $token ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) return;

		$current = get_option( self::REVIEW_LOCK_OPTION, array() );
		if (
			is_array( $current )
			&& isset( $current['token'] )
			&& is_string( $current['token'] )
			&& hash_equals( (string) $current['token'], (string) $token )
		) {
			delete_option( self::REVIEW_LOCK_OPTION );
		}
	}

	private static function features_from_node( array $node, array $conformance ) {
		$annotations = isset( $node['annotations'] ) && is_array( $node['annotations'] )
			? $node['annotations']
			: array();

		$actual = isset( $conformance['result'] ) ? (string) $conformance['result'] : '';
		$actual_ok = (
			'zero_effect_read_verified' === $actual
			&& ! empty( $conformance['evidence_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) $conformance['evidence_sha256'] )
		);

		return array(
			'namespace' => isset( $node['namespace'] ) ? (string) $node['namespace'] : '',
			'action' => isset( $node['action'] ) ? (string) $node['action'] : '',
			'schema_sha256' => isset( $node['input_schema_sha256'] ) ? (string) $node['input_schema_sha256'] : '',
			'readonly_annotation' => array_key_exists( 'readonly', $annotations ) ? $annotations['readonly'] : null,
			'destructive_annotation' => array_key_exists( 'destructive', $annotations ) ? $annotations['destructive'] : null,
			'idempotent_annotation' => array_key_exists( 'idempotent', $annotations ) ? $annotations['idempotent'] : null,
			'execution_lane' => isset( $node['execution_lane'] ) ? (string) $node['execution_lane'] : '',
			'execution_provider' => isset( $node['execution_provider'] ) ? (string) $node['execution_provider'] : '',
			'execution_eligible' => ! empty( $node['execution_eligible'] ),
			'execution_boundary_verified' => ! empty( $node['execution_boundary_verified'] ),
			'breakglass' => ! empty( $node['breakglass'] ),
			'reversible_contract' => isset( $node['reversible_contract'] ) ? (string) $node['reversible_contract'] : '',
			'effect_class' => isset( $node['effect_class'] ) ? (string) $node['effect_class'] : 'unknown',
			'schema_secret_bearing' => ! empty( $node['schema_secret_bearing'] ),
			'actual_conformance_verified' => (bool) $actual_ok,
			'actual_conformance_result' => $actual,
			'http_method' => isset( $node['http_method'] ) ? (string) $node['http_method'] : '',
			'declared_capability' => isset( $node['required_capability'] ) ? (string) $node['required_capability'] : '',
			'requested_risk' => isset( $node['requested_risk'] ) ? (string) $node['requested_risk'] : '',
		);
	}

	public static function classify_features( array $features ) {
		$missing = array();
		$contradictions = array();

		$lane = isset( $features['execution_lane'] ) ? sanitize_key( (string) $features['execution_lane'] ) : '';
		$readonly = array_key_exists( 'readonly_annotation', $features ) && is_bool( $features['readonly_annotation'] )
			? $features['readonly_annotation']
			: null;
		$effect = isset( $features['effect_class'] ) ? sanitize_key( (string) $features['effect_class'] ) : 'unknown';
		$secret = ! empty( $features['schema_secret_bearing'] );
		$conformance = ! empty( $features['actual_conformance_verified'] );
		$breakglass = ! empty( $features['breakglass'] );
		$method = strtoupper( isset( $features['http_method'] ) ? (string) $features['http_method'] : '' );
		$action = strtolower( isset( $features['action'] ) ? (string) $features['action'] : '' );
		$requested_risk = sanitize_key( isset( $features['requested_risk'] ) ? (string) $features['requested_risk'] : '' );

		if ( null === $readonly ) $missing[] = 'readonly_annotation_missing';
		if ( '' === $lane || 'none' === $lane ) $missing[] = 'execution_lane_missing_or_blocked';
		if ( empty( $features['schema_sha256'] ) ) $missing[] = 'schema_digest_missing';
		if ( ! $conformance ) $missing[] = 'actual_conformance_missing';

		if ( true === $readonly && 'read' !== $lane ) $contradictions[] = 'readonly_annotation_conflicts_with_execution_lane';
		if ( true === $readonly && 'declared_read_only' !== $effect ) $contradictions[] = 'readonly_annotation_conflicts_with_effect';
		if ( preg_match( '/^(get|list|read|inspect|status|describe|discover)/', $action ) && true !== $readonly ) {
			$contradictions[] = 'operation_name_cannot_prove_read_safety';
		}
		if ( 'GET' === $method && ( 'read' !== $lane || 'declared_read_only' !== $effect ) ) {
			$contradictions[] = 'http_get_cannot_prove_read_safety';
		}
		if ( $secret && $conformance ) $contradictions[] = 'secret_schema_blocks_zero_effect_auto_classification';

		$high = (
			in_array( $lane, array( 'write', 'admin', 'content', 'developer', 'developer-breakglass', 'breakglass', 'enrollment' ), true )
			|| $breakglass
			|| 'mutation_or_unknown' === $effect
		);
		$risk = $high ? 'high' : ( ( 'read' === $lane && ! $secret ) ? 'low' : 'unknown' );

		if ( 'low' === $requested_risk && 'low' !== $risk ) $contradictions[] = 'risk_downgrade_rejected';

		$eligible = (
			true === $readonly
			&& 'read' === $lane
			&& 'declared_read_only' === $effect
			&& ! $secret
			&& $conformance
			&& ! empty( $features['execution_eligible'] )
			&& ! $breakglass
			&& empty( $contradictions )
		);

		$confidence = 0;
		foreach ( array( 'namespace', 'action', 'schema_sha256', 'execution_lane', 'effect_class' ) as $key ) {
			if ( ! empty( $features[ $key ] ) ) $confidence += 10;
		}
		if ( null !== $readonly ) $confidence += 10;
		if ( $conformance ) $confidence += 20;
		if ( ! empty( $features['execution_provider'] ) || 'read' === $lane ) $confidence += 10;
		if ( ! empty( $features['reversible_contract'] ) ) $confidence += 5;
		$confidence = min( 95, $confidence );

		$classification = $eligible
			? 'zero_effect_read_candidate'
			: ( $high ? 'high_risk_review' : 'unknown_review' );

		return array(
			'contract' => self::PROPOSAL_CONTRACT,
			'classifier_version' => self::CLASSIFIER_VERSION,
			'classification' => $classification,
			'risk' => $risk,
			'confidence' => $confidence,
			'confidence_authority_effect' => 'none',
			'auto_classification_eligible' => $eligible,
			'owner_review_required' => ! $eligible,
			'missing_evidence' => array_values( array_unique( $missing ) ),
			'contradictory_evidence' => array_values( array_unique( $contradictions ) ),
			'features' => self::public_features( $features ),
			'authority_delta' => array( 'grants' => 0, 'mounts' => 0, 'scopes' => 0, 'certifications' => 0 ),
			'authorizing' => false,
		);
	}

	private static function overlay_projection( $name, array $overlay, array $proposal, $generation ) {
		$row = isset( $overlay[ $name ] ) && is_array( $overlay[ $name ] )
			? $overlay[ $name ]
			: array();

		if ( empty( $row ) ) {
			return array(
				'state' => 'not_reviewed',
				'authorizing' => false,
			);
		}

		$bound = isset( $row['graph_generation_sha256'] ) ? (string) $row['graph_generation_sha256'] : '';
		$stale = '' === $bound || ! hash_equals( (string) $generation, $bound );
		$reviewed_class = isset( $row['classification'] ) ? sanitize_key( (string) $row['classification'] ) : '';
		$reviewed_risk = isset( $row['risk'] ) ? sanitize_key( (string) $row['risk'] ) : '';

		$diff = array();
		if ( $reviewed_class !== $proposal['classification'] ) $diff[] = 'classification_changed';
		if ( $reviewed_risk !== $proposal['risk'] ) $diff[] = 'risk_changed';
		if ( ! empty( $row['requested_scope_change'] ) ) $diff[] = 'scope_change_requires_external_consent';

		return array(
			'state' => $stale ? 'stale_review' : 'reviewed_evidence_only',
			'graph_generation_sha256' => $bound,
			'reviewed_classification' => $reviewed_class,
			'reviewed_risk' => $reviewed_risk,
			'decision' => isset( $row['decision'] ) ? sanitize_key( (string) $row['decision'] ) : '',
			'review_sha256' => isset( $row['review_sha256'] ) ? (string) $row['review_sha256'] : '',
			'requested_scope_change' => ! empty( $row['requested_scope_change'] ),
			'differences' => $diff,
			'creates_grant' => false,
			'creates_mount' => false,
			'creates_scope' => false,
			'creates_certification' => false,
			'authorizing' => false,
		);
	}

	private static function public_features( array $features ) {
		$allowed = array(
			'namespace',
			'action',
			'schema_sha256',
			'readonly_annotation',
			'destructive_annotation',
			'idempotent_annotation',
			'execution_lane',
			'execution_provider',
			'execution_eligible',
			'execution_boundary_verified',
			'breakglass',
			'reversible_contract',
			'effect_class',
			'schema_secret_bearing',
			'actual_conformance_verified',
			'actual_conformance_result',
			'http_method',
			'declared_capability',
			'requested_risk',
		);

		$out = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $features ) ) $out[ $key ] = $features[ $key ];
		}
		return $out;
	}

	private static function digest( $contract, $value ) {
		if ( class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' ) ) {
			$digest = MAD4B_SCP_Ability_Contract_Inspector::digest( $contract, $value );
			if ( ! is_wp_error( $digest ) ) return $digest;
		}

		return hash(
			'sha256',
			wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}
}

MAD4B_SCP_Runtime_Policy_Classifier::boot();
