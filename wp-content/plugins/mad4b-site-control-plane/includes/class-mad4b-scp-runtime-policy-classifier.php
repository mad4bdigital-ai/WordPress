<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic, non-authorizing policy proposal classifier.
 *
 * Confidence, names, HTTP methods, schema shape and annotations are evidence
 * only. They never create grants, mounts, certification or execution authority.
 */
final class MAD4B_SCP_Runtime_Policy_Classifier {
	const CONTRACT = 'mad4b.runtime-policy-classifier.v2';
	const PROPOSAL_CONTRACT = 'mad4b.runtime-policy-proposal.v2';
	const CONFORMANCE_CONTRACT = 'mad4b.runtime-policy-conformance-receipt.v1';
	const CLASSIFIER_VERSION = '2.0.0';
	const REVIEW_CONTRACT = 'mad4b.runtime-policy-review-overlay.v2';
	const REVIEW_OPTION = 'mad4b_runtime_policy_review_overlay_v2';
	const REVIEW_LOCK_OPTION = 'mad4b_runtime_policy_review_overlay_lock_v2';
	const MAX_REVIEWS = 256;
	const MAX_REVIEW_HISTORY = 1024;
	const REVIEW_LOCK_TTL = 120;
	const REVIEW_MAX_ELAPSED_MS = 10000;
	const MAX_CONFORMANCE_VERIFIERS = 16;

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
		$store = self::review_store();
		$overlay = ! empty( $store['integrity_valid'] ) ? $store['reviews'] : array();
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

			$raw_conformance = apply_filters( 'mad4b_scp_runtime_policy_conformance', array(), $name, $node, $graph );
			$conformance = self::verify_conformance_receipt( $raw_conformance, $name, $node, $graph );
			$features = self::features_from_node( $node, $conformance );
			$proposal = self::classify_features( $features );
			$proposal['ability_name'] = $name;
			$proposal['graph_generation_sha256'] = isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '';
			$proposal['conformance'] = $conformance;
			$proposal['evidence_refs'] = array_values(
				array_filter(
					array(
						isset( $node['descriptor_generation_sha256'] ) ? (string) $node['descriptor_generation_sha256'] : '',
						isset( $node['classification_sha256'] ) ? (string) $node['classification_sha256'] : '',
						! empty( $conformance['verified'] ) ? (string) $conformance['evidence_sha256'] : '',
						! empty( $conformance['verified'] ) ? (string) $conformance['receipt_sha256'] : '',
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
			'conformance_contract' => self::CONFORMANCE_CONTRACT,
			'classifier_version' => self::CLASSIFIER_VERSION,
			'graph_generation_sha256' => isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '',
			'review_integrity_valid' => ! empty( $store['integrity_valid'] ),
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

	private static function trusted_conformance_verifiers( $ability_name, array $node, array $graph ) {
		$raw = function_exists( 'apply_filters' )
			? apply_filters( 'mad4b_scp_runtime_policy_conformance_verifiers', array(), $ability_name, $node, $graph )
			: array();
		$raw = is_array( $raw ) ? array_slice( $raw, 0, self::MAX_CONFORMANCE_VERIFIERS, true ) : array();
		$out = array();
		foreach ( $raw as $key => $candidate ) {
			if ( ! is_array( $candidate ) ) continue;
			$id = isset( $candidate['verifier_id'] ) ? sanitize_key( (string) $candidate['verifier_id'] ) : sanitize_key( (string) $key );
			$issuer_contract = isset( $candidate['issuer_contract'] ) ? (string) $candidate['issuer_contract'] : '';
			$issuer_id = isset( $candidate['issuer_id'] ) ? sanitize_key( (string) $candidate['issuer_id'] ) : '';
			$scheme = isset( $candidate['signature_scheme'] ) ? substr( trim( (string) $candidate['signature_scheme'] ), 0, 80 ) : '';
			$callback = isset( $candidate['verify_callback'] ) ? $candidate['verify_callback'] : null;
			if ( '' === $id || '' === $issuer_contract || '' === $issuer_id || '' === $scheme || ! is_callable( $callback ) ) continue;
			if ( true !== ( isset( $candidate['trusted'] ) ? $candidate['trusted'] : false ) ) continue;
			if ( true !== ( isset( $candidate['read_only_verifier'] ) ? $candidate['read_only_verifier'] : false ) ) continue;
			if ( false !== ( isset( $candidate['authorizing'] ) ? $candidate['authorizing'] : null ) ) continue;
			if ( ! self::callback_owned_by_control_plane( $callback ) ) continue;
			$out[ $id ] = array(
				'verifier_id' => $id,
				'issuer_contract' => $issuer_contract,
				'issuer_id' => $issuer_id,
				'signature_scheme' => $scheme,
				'verify_callback' => $callback,
				'verifier_provenance' => 'mad4b_control_plane_source',
			);
		}
		return $out;
	}

	private static function callback_owned_by_control_plane( $callback ) {
		try {
			if ( $callback instanceof Closure ) $reflection = new ReflectionFunction( $callback );
			elseif ( is_array( $callback ) && 2 === count( $callback ) ) $reflection = new ReflectionMethod( $callback[0], $callback[1] );
			elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$reflection = new ReflectionMethod( $class, $method );
			} elseif ( is_string( $callback ) ) $reflection = new ReflectionFunction( $callback );
			elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) $reflection = new ReflectionMethod( $callback, '__invoke' );
			else return false;
			$file = $reflection->getFileName();
		} catch ( Throwable $error ) {
			return false;
		}
		$root = defined( 'MAD4B_SCP_DIR' ) ? realpath( MAD4B_SCP_DIR ) : false;
		$file = is_string( $file ) ? realpath( $file ) : false;
		if ( false === $root || false === $file ) return false;
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$file = str_replace( '\\', '/', $file );
		return $file === $root || 0 === strpos( $file, $root . '/' );
	}

	private static function verify_conformance_receipt( $receipt, $ability_name, array $node, array $graph ) {
		$base = array(
			'verified' => false,
			'reason' => 'conformance_receipt_missing_or_invalid',
			'contract' => self::CONFORMANCE_CONTRACT,
			'evidence_sha256' => '',
			'receipt_sha256' => '',
			'output_classification' => 'unknown',
			'issuer_contract' => '',
		);
		if ( ! is_array( $receipt ) || self::CONFORMANCE_CONTRACT !== ( isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '' ) ) return $base;

		$trusted_issuers = array(
			'mad4b.provider-compatibility-certification.v1',
			'mad4b.runtime-compatibility-profile.v1',
		);
		$issuer = isset( $receipt['issuer_contract'] ) ? (string) $receipt['issuer_contract'] : '';
		if ( ! in_array( $issuer, $trusted_issuers, true ) ) {
			$base['reason'] = 'conformance_issuer_untrusted';
			return $base;
		}
		$verifier_id = isset( $receipt['verifier_id'] ) ? sanitize_key( (string) $receipt['verifier_id'] ) : '';
		$issuer_id = isset( $receipt['issuer_id'] ) ? sanitize_key( (string) $receipt['issuer_id'] ) : '';
		$signature = isset( $receipt['signature'] ) && is_scalar( $receipt['signature'] ) ? trim( (string) $receipt['signature'] ) : '';
		$verifiers = self::trusted_conformance_verifiers( $ability_name, $node, $graph );
		if ( '' === $verifier_id || ! isset( $verifiers[ $verifier_id ] ) ) {
			$base['reason'] = 'conformance_verifier_untrusted';
			return $base;
		}
		$verifier = $verifiers[ $verifier_id ];
		if ( ! hash_equals( (string) $verifier['issuer_contract'], $issuer ) || '' === $issuer_id || ! hash_equals( (string) $verifier['issuer_id'], $issuer_id ) ) {
			$base['reason'] = 'conformance_verifier_issuer_binding_mismatch';
			return $base;
		}
		if ( '' === $signature || strlen( $signature ) > 1024 ) {
			$base['reason'] = 'conformance_signature_invalid';
			return $base;
		}

		$expected_provider_sha = '';
		$provider = isset( $node['execution_provider'] ) ? (string) $node['execution_provider'] : '';
		foreach ( isset( $graph['nodes']['providers'] ) && is_array( $graph['nodes']['providers'] ) ? $graph['nodes']['providers'] : array() as $provider_row ) {
			if ( is_array( $provider_row ) && $provider === ( isset( $provider_row['id'] ) ? (string) $provider_row['id'] : '' ) ) {
				$expected_provider_sha = isset( $provider_row['contract_sha256'] ) ? (string) $provider_row['contract_sha256'] : '';
				break;
			}
		}

		$checks = array(
			'ability_name' => (string) $ability_name,
			'graph_generation_sha256' => isset( $graph['generation_sha256'] ) ? (string) $graph['generation_sha256'] : '',
			'descriptor_generation_sha256' => isset( $node['descriptor_generation_sha256'] ) ? (string) $node['descriptor_generation_sha256'] : '',
			'input_schema_sha256' => isset( $node['input_schema_sha256'] ) ? (string) $node['input_schema_sha256'] : '',
			'output_schema_sha256' => isset( $node['output_schema_sha256'] ) ? (string) $node['output_schema_sha256'] : '',
		);
		foreach ( $checks as $field => $expected ) {
			$actual = isset( $receipt[ $field ] ) ? (string) $receipt[ $field ] : '';
			if ( '' === $expected || ! hash_equals( $expected, $actual ) ) {
				$base['reason'] = 'conformance_binding_mismatch:' . $field;
				return $base;
			}
		}
		if ( '' !== $provider ) {
			$actual_provider_sha = isset( $receipt['provider_contract_sha256'] ) ? (string) $receipt['provider_contract_sha256'] : '';
			if ( '' === $expected_provider_sha || ! hash_equals( $expected_provider_sha, $actual_provider_sha ) ) {
				$base['reason'] = 'conformance_provider_binding_mismatch';
				return $base;
			}
		}
		if ( 'zero_effect_read_verified' !== ( isset( $receipt['result'] ) ? (string) $receipt['result'] : '' ) ) {
			$base['reason'] = 'conformance_result_not_zero_effect_read';
			return $base;
		}
		if ( 0 !== (int) ( isset( $receipt['observed_writes'] ) ? $receipt['observed_writes'] : -1 )
			|| 0 !== (int) ( isset( $receipt['observed_external_effects'] ) ? $receipt['observed_external_effects'] : -1 ) ) {
			$base['reason'] = 'conformance_effects_observed';
			return $base;
		}
		$output_classification = isset( $receipt['output_classification'] ) ? sanitize_key( (string) $receipt['output_classification'] ) : '';
		if ( 'public_bounded' !== $output_classification ) {
			$base['reason'] = 'conformance_output_not_public_bounded';
			return $base;
		}
		$evidence_sha = isset( $receipt['evidence_sha256'] ) ? (string) $receipt['evidence_sha256'] : '';
		$claimed = isset( $receipt['receipt_sha256'] ) ? (string) $receipt['receipt_sha256'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $evidence_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $claimed ) ) {
			$base['reason'] = 'conformance_digest_invalid';
			return $base;
		}
		$basis = $receipt;
		unset( $basis['receipt_sha256'] );
		$expected_receipt = self::digest( self::CONFORMANCE_CONTRACT, $basis );
		if ( ! hash_equals( $expected_receipt, $claimed ) ) {
			$base['reason'] = 'conformance_receipt_digest_mismatch';
			return $base;
		}
		try {
			$verification = call_user_func(
				$verifier['verify_callback'],
				$receipt,
				array(
					'ability_name' => (string) $ability_name,
					'graph_generation_sha256' => (string) $graph['generation_sha256'],
					'provider_contract_sha256' => $expected_provider_sha,
				)
			);
		} catch ( Throwable $error ) {
			$base['reason'] = 'conformance_verifier_exception';
			return $base;
		}
		if ( ! is_array( $verification ) || empty( $verification['verified'] ) ) {
			$base['reason'] = 'conformance_signature_verification_failed';
			return $base;
		}
		$verified_evidence = isset( $verification['evidence_sha256'] ) ? (string) $verification['evidence_sha256'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $verified_evidence ) || ! hash_equals( $evidence_sha, $verified_evidence ) ) {
			$base['reason'] = 'conformance_verifier_evidence_mismatch';
			return $base;
		}

		return array(
			'verified' => true,
			'reason' => 'verified',
			'contract' => self::CONFORMANCE_CONTRACT,
			'evidence_sha256' => $evidence_sha,
			'receipt_sha256' => $claimed,
			'output_classification' => $output_classification,
			'issuer_contract' => $issuer,
			'issuer_id' => $issuer_id,
			'verifier_id' => $verifier_id,
			'signature_scheme' => $verifier['signature_scheme'],
			'verifier_provenance' => $verifier['verifier_provenance'],
			'provider_contract_sha256' => $expected_provider_sha,
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
			'history_count' => count( $store['history'] ),
			'integrity_valid' => ! empty( $store['integrity_valid'] ),
			'integrity_errors' => isset( $store['integrity_errors'] ) ? $store['integrity_errors'] : array(),
			'audit_required' => true,
			'authorizing' => false,
			'grants_changed' => false,
			'mounts_changed' => false,
			'scopes_changed' => false,
			'certifications_changed' => false,
		);
	}

	public static function record_review( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$started = microtime( true );

		if ( ! self::can_review() ) {
			return new WP_Error( 'mad4b_runtime_policy_review_admin_required', 'Administrator capability is required to record a runtime policy review.' );
		}
		$audit_ready = self::assert_audit_ready();
		if ( is_wp_error( $audit_ready ) ) return $audit_ready;

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
		if ( empty( $before['integrity_valid'] ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_store_tampered', 'Runtime policy review ledger integrity validation failed.' );
		}
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
		$proposal = isset( $proposal_set['proposals'][0] ) && is_array( $proposal_set['proposals'][0] ) ? $proposal_set['proposals'][0] : array();
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
		if ( 'accept_evidence' === $decision && ! empty( $proposal['owner_review_required'] ) && 'high' === ( isset( $proposal['risk'] ) ? (string) $proposal['risk'] : '' ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_high_risk_cannot_auto_accept', 'High-risk evidence may be reviewed but cannot be accepted as automatic classification.' );
		}

		$reviews = $before['reviews'];
		$history = $before['history'];
		if ( ! isset( $reviews[ $name ] ) && count( $reviews ) >= self::MAX_REVIEWS ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_limit', 'Runtime policy review ledger is full.' );
		}
		if ( count( $history ) >= self::MAX_REVIEW_HISTORY ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_history_limit', 'Runtime policy review history is full and cannot be silently truncated.' );
		}

		$next_revision = (int) $before['revision'] + 1;
		$previous_sha = isset( $reviews[ $name ]['review_sha256'] ) ? (string) $reviews[ $name ]['review_sha256'] : '';
		$record = array(
			'ability_name' => $name,
			'revision' => $next_revision,
			'previous_review_sha256' => $previous_sha,
			'graph_generation_sha256' => (string) $proposal_set['graph_generation_sha256'],
			'proposal_sha256' => (string) $proposal['proposal_sha256'],
			'classification' => (string) $proposal['classification'],
			'risk' => (string) $proposal['risk'],
			'decision' => $decision,
			'requested_scope_change' => ! empty( $input['requested_scope_change'] ),
			'note' => isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '',
			'reviewed_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'reviewed_at' => function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
			'lock_fence_sha256' => hash( 'sha256', (string) $token ),
			'audit_required' => true,
			'authority_effect' => 'none',
			'creates_grant' => false,
			'creates_mount' => false,
			'creates_scope' => false,
			'creates_certification' => false,
		);
		$record['review_sha256'] = self::review_record_digest( $record );

		$reviews[ $name ] = $record;
		ksort( $reviews, SORT_STRING );
		$history[] = $record;
		$next = array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => $next_revision,
			'reviews' => $reviews,
			'history' => $history,
			'authorizing' => false,
		);

		if ( ! self::lock_owned( $token ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_lock_lost', 'Runtime policy review lock ownership was lost before commit.' );
		}
		if ( ( microtime( true ) - $started ) * 1000 > self::REVIEW_MAX_ELAPSED_MS ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_budget_exceeded', 'Runtime policy review exceeded its bounded commit budget.' );
		}

		$persisted = function_exists( 'update_option' ) ? update_option( self::REVIEW_OPTION, $next, false ) : false;
		$readback = self::review_store();
		if ( false === $persisted && $readback !== array_merge( $next, array( 'integrity_valid'=>true, 'integrity_errors'=>array() ) ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_persist_failed', 'Runtime policy review could not be persisted.' );
		}
		if ( empty( $readback['integrity_valid'] )
			|| (int) $readback['revision'] !== $next_revision
			|| ! isset( $readback['reviews'][ $name ] )
			|| ! hash_equals( $record['review_sha256'], (string) $readback['reviews'][ $name ]['review_sha256'] ) ) {
			if ( function_exists( 'update_option' ) ) update_option( self::REVIEW_OPTION, self::persistable_store( $before ), false );
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_readback_failed', 'Runtime policy review readback did not match the committed review.' );
		}
		if ( ! self::lock_owned( $token ) ) {
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_lock_lost', 'Runtime policy review lock ownership was lost after commit.' );
		}

		$audit = MAD4B_SCP_Audit::record(
			'runtime_policy_review_recorded',
			array(
				'ability_name' => $name,
				'revision' => $next_revision,
				'graph_generation_sha256' => $record['graph_generation_sha256'],
				'proposal_sha256' => $record['proposal_sha256'],
				'review_sha256' => $record['review_sha256'],
				'previous_review_sha256' => $previous_sha,
				'decision' => $decision,
				'requested_scope_change' => $record['requested_scope_change'],
				'authority_effect' => 'none',
			),
			'ok'
		);
		if ( is_wp_error( $audit ) ) {
			if ( function_exists( 'update_option' ) ) update_option( self::REVIEW_OPTION, self::persistable_store( $before ), false );
			self::release_review_lock( $token );
			return new WP_Error( 'mad4b_runtime_policy_review_audit_failed', 'Runtime policy review was rolled back because mandatory audit evidence could not be recorded.' );
		}

		self::release_review_lock( $token );
		return array(
			'contract' => self::REVIEW_CONTRACT,
			'revision' => $next_revision,
			'history_count' => count( $history ),
			'review' => $record,
			'grants_changed' => false,
			'mounts_changed' => false,
			'scopes_changed' => false,
			'certifications_changed' => false,
			'authorizing' => false,
			'mutation_performed' => true,
		);
	}

	private static function assert_audit_ready() {
		if ( ! class_exists( 'MAD4B_SCP_Audit' )
			|| ! method_exists( 'MAD4B_SCP_Audit', 'storage_status' )
			|| ! method_exists( 'MAD4B_SCP_Audit', 'record' ) ) {
			return new WP_Error( 'mad4b_runtime_policy_review_audit_unavailable', 'Mandatory runtime policy review audit storage is unavailable.' );
		}
		$status = MAD4B_SCP_Audit::storage_status();
		if ( ! is_array( $status ) || empty( $status['ready'] ) ) {
			return new WP_Error( 'mad4b_runtime_policy_review_audit_not_ready', 'Mandatory runtime policy review audit storage is not ready.' );
		}
		return true;
	}

	private static function stored_overlay() {
		$store = self::review_store();
		return ! empty( $store['integrity_valid'] ) ? $store['reviews'] : array();
	}

	private static function review_store() {
		$value = function_exists( 'get_option' ) ? get_option( self::REVIEW_OPTION, array() ) : array();
		if ( empty( $value ) ) {
			return array(
				'contract'=>self::REVIEW_CONTRACT,
				'revision'=>0,
				'reviews'=>array(),
				'history'=>array(),
				'authorizing'=>false,
				'integrity_valid'=>true,
				'integrity_errors'=>array(),
			);
		}
		if ( ! is_array( $value ) || self::REVIEW_CONTRACT !== ( isset( $value['contract'] ) ? (string) $value['contract'] : '' ) ) {
			return array(
				'contract'=>self::REVIEW_CONTRACT,'revision'=>0,'reviews'=>array(),'history'=>array(),'authorizing'=>false,
				'integrity_valid'=>false,'integrity_errors'=>array('review_store_contract_invalid'),
			);
		}
		$reviews = isset( $value['reviews'] ) && is_array( $value['reviews'] ) ? $value['reviews'] : array();
		$history = isset( $value['history'] ) && is_array( $value['history'] ) ? $value['history'] : array();
		$errors = array();
		if ( count( $reviews ) > self::MAX_REVIEWS ) $errors[] = 'review_store_current_unbounded';
		if ( count( $history ) > self::MAX_REVIEW_HISTORY ) $errors[] = 'review_store_history_unbounded';

		$clean_reviews = array();
		foreach ( array_slice( $reviews, 0, self::MAX_REVIEWS, true ) as $name => $row ) {
			if ( ! is_string( $name ) || '' === $name || ! is_array( $row ) || ! self::review_record_valid( $row ) ) {
				$errors[] = 'review_record_invalid:' . ( is_string( $name ) ? $name : 'unknown' );
				continue;
			}
			$clean_reviews[ $name ] = $row;
		}
		$clean_history = array();
		foreach ( array_slice( $history, 0, self::MAX_REVIEW_HISTORY ) as $index => $row ) {
			if ( ! is_array( $row ) || ! self::review_record_valid( $row ) ) {
				$errors[] = 'review_history_record_invalid:' . (string) $index;
				continue;
			}
			$clean_history[] = $row;
		}
		ksort( $clean_reviews, SORT_STRING );

		return array(
			'contract'=>self::REVIEW_CONTRACT,
			'revision'=>max(0,(int)(isset($value['revision'])?$value['revision']:0)),
			'reviews'=>$clean_reviews,
			'history'=>$clean_history,
			'authorizing'=>false,
			'integrity_valid'=>empty($errors),
			'integrity_errors'=>array_values(array_unique($errors)),
		);
	}

	private static function persistable_store( array $store ) {
		return array(
			'contract'=>self::REVIEW_CONTRACT,
			'revision'=>(int)(isset($store['revision'])?$store['revision']:0),
			'reviews'=>isset($store['reviews'])&&is_array($store['reviews'])?$store['reviews']:array(),
			'history'=>isset($store['history'])&&is_array($store['history'])?$store['history']:array(),
			'authorizing'=>false,
		);
	}

	private static function review_record_valid( array $row ) {
		$claimed=isset($row['review_sha256'])?(string)$row['review_sha256']:'';
		return 1===preg_match('/^[a-f0-9]{64}$/D',$claimed) && hash_equals(self::review_record_digest($row),$claimed);
	}

	private static function review_record_digest( array $row ) {
		unset($row['review_sha256']);
		return self::digest(self::REVIEW_CONTRACT,$row);
	}

	private static function acquire_review_lock() {
		$token = function_exists( 'wp_generate_uuid4' )
			? strtolower( wp_generate_uuid4() )
			: hash( 'sha256', microtime( true ) . ':' . mt_rand() );
		$record = array( 'token'=>$token, 'expires'=>time()+self::REVIEW_LOCK_TTL );
		if ( function_exists('add_option') && add_option(self::REVIEW_LOCK_OPTION,$record,'',false) ) return $token;
		$current = function_exists('get_option') ? get_option(self::REVIEW_LOCK_OPTION,array()) : array();
		if ( is_array($current) && isset($current['expires']) && (int)$current['expires'] < time() && function_exists('delete_option') ) {
			delete_option(self::REVIEW_LOCK_OPTION);
			if ( function_exists('add_option') && add_option(self::REVIEW_LOCK_OPTION,$record,'',false) ) return $token;
		}
		return new WP_Error('mad4b_runtime_policy_review_busy','Another runtime policy review is currently being committed.');
	}

	private static function lock_owned( $token ) {
		if ( ! function_exists('get_option') ) return false;
		$current=get_option(self::REVIEW_LOCK_OPTION,array());
		return is_array($current)
			&& isset($current['token'],$current['expires'])
			&& is_string($current['token'])
			&& (int)$current['expires'] >= time()
			&& hash_equals((string)$current['token'],(string)$token);
	}

	private static function release_review_lock( $token ) {
		if ( ! function_exists('get_option') || ! function_exists('delete_option') ) return;
		$current=get_option(self::REVIEW_LOCK_OPTION,array());
		if(is_array($current)&&isset($current['token'])&&is_string($current['token'])&&hash_equals((string)$current['token'],(string)$token)) {
			delete_option(self::REVIEW_LOCK_OPTION);
		}
	}

	private static function features_from_node( array $node, array $conformance ) {
		$annotations = isset( $node['annotations'] ) && is_array( $node['annotations'] ) ? $node['annotations'] : array();
		return array(
			'namespace'=>isset($node['namespace'])?(string)$node['namespace']:'',
			'action'=>isset($node['action'])?(string)$node['action']:'',
			'schema_sha256'=>isset($node['input_schema_sha256'])?(string)$node['input_schema_sha256']:'',
			'output_schema_sha256'=>isset($node['output_schema_sha256'])?(string)$node['output_schema_sha256']:'',
			'readonly_annotation'=>array_key_exists('readonly',$annotations)?$annotations['readonly']:null,
			'destructive_annotation'=>array_key_exists('destructive',$annotations)?$annotations['destructive']:null,
			'idempotent_annotation'=>array_key_exists('idempotent',$annotations)?$annotations['idempotent']:null,
			'execution_lane'=>isset($node['execution_lane'])?(string)$node['execution_lane']:'',
			'execution_provider'=>isset($node['execution_provider'])?(string)$node['execution_provider']:'',
			'execution_eligible'=>!empty($node['execution_eligible']),
			'execution_boundary_verified'=>!empty($node['execution_boundary_verified']),
			'breakglass'=>!empty($node['breakglass']),
			'reversible_contract'=>isset($node['reversible_contract'])?(string)$node['reversible_contract']:'',
			'effect_class'=>isset($node['effect_class'])?(string)$node['effect_class']:'unknown',
			'schema_secret_bearing'=>!empty($node['schema_secret_bearing']),
			'output_schema_secret_bearing'=>!empty($node['output_schema_secret_bearing']),
			'output_schema_evidence_classification'=>isset($node['output_schema_evidence_classification'])?(string)$node['output_schema_evidence_classification']:'unknown',
			'actual_conformance_verified'=>!empty($conformance['verified']),
			'actual_conformance_result'=>!empty($conformance['verified'])?'zero_effect_read_verified':'',
			'actual_output_classification'=>isset($conformance['output_classification'])?(string)$conformance['output_classification']:'unknown',
			'conformance_reason'=>isset($conformance['reason'])?(string)$conformance['reason']:'unknown',
			'http_method'=>isset($node['http_method'])?(string)$node['http_method']:'',
			'declared_capability'=>isset($node['required_capability'])?(string)$node['required_capability']:'',
			'data_classification'=>isset($node['data_classification'])?(string)$node['data_classification']:'',
			'resource_schema_version'=>isset($node['resource_schema_version'])?(string)$node['resource_schema_version']:'',
			'resource_constraints_sha256'=>isset($node['resource_constraints_sha256'])?(string)$node['resource_constraints_sha256']:'',
			'requested_risk'=>isset($node['requested_risk'])?(string)$node['requested_risk']:'',
		);
	}

	public static function classify_features( array $features ) {
		$missing=array(); $contradictions=array();
		$lane=isset($features['execution_lane'])?sanitize_key((string)$features['execution_lane']):'';
		$readonly=array_key_exists('readonly_annotation',$features)&&is_bool($features['readonly_annotation'])?$features['readonly_annotation']:null;
		$effect=isset($features['effect_class'])?sanitize_key((string)$features['effect_class']):'unknown';
		$input_secret=!empty($features['schema_secret_bearing']);
		$output_secret=!empty($features['output_schema_secret_bearing']);
		$conformance=!empty($features['actual_conformance_verified']);
		$breakglass=!empty($features['breakglass']);
		$method=strtoupper(isset($features['http_method'])?(string)$features['http_method']:'');
		$action=strtolower(isset($features['action'])?(string)$features['action']:'');
		$requested_risk=sanitize_key(isset($features['requested_risk'])?(string)$features['requested_risk']:'');
		$capability=sanitize_key(isset($features['declared_capability'])?(string)$features['declared_capability']:'');
		$data_class=sanitize_key(isset($features['data_classification'])?(string)$features['data_classification']:'');
		$output_class=sanitize_key(isset($features['actual_output_classification'])?(string)$features['actual_output_classification']:'');
		$resource_sha=isset($features['resource_constraints_sha256'])?(string)$features['resource_constraints_sha256']:'';
		$resource_version=isset($features['resource_schema_version'])?(string)$features['resource_schema_version']:'';

		if(null===$readonly) $missing[]='readonly_annotation_missing';
		if(''===$lane||'none'===$lane) $missing[]='execution_lane_missing_or_blocked';
		if(empty($features['schema_sha256'])) $missing[]='schema_digest_missing';
		if(empty($features['output_schema_sha256'])) $missing[]='output_schema_digest_missing';
		if(!$conformance) $missing[]='trusted_conformance_receipt_missing';
		if(empty($features['execution_boundary_verified'])) $missing[]='execution_boundary_unverified';
		if(''!==$resource_sha && ''===$resource_version) $missing[]='resource_schema_version_missing';

		if(true===$readonly && 'read'!==$lane) $contradictions[]='readonly_annotation_conflicts_with_execution_lane';
		if(true===$readonly && 'declared_read_only'!==$effect) $contradictions[]='readonly_annotation_conflicts_with_effect';
		if(preg_match('/^(get|list|read|inspect|status|describe|discover)/',$action) && true!==$readonly) $contradictions[]='operation_name_cannot_prove_read_safety';
		if('GET'===$method && ('read'!==$lane||'declared_read_only'!==$effect)) $contradictions[]='http_get_cannot_prove_read_safety';
		if($input_secret) $contradictions[]='secret_input_schema_blocks_auto_classification';
		if($output_secret) $contradictions[]='secret_output_schema_blocks_auto_classification';
		if($conformance && 'public_bounded'!==$output_class) $contradictions[]='conformance_output_classification_not_public';
		if(''!==$capability && 'read'!==$capability) $contradictions[]='privileged_capability_blocks_auto_classification';
		if(''!==$data_class && !in_array($data_class,array('public','public_bounded','non_sensitive'),true)) $contradictions[]='sensitive_data_classification_blocks_auto_classification';

		$high=in_array($lane,array('write','admin','content','developer','developer-breakglass','breakglass','enrollment'),true)
			|| $breakglass || 'mutation_or_unknown'===$effect || $output_secret || (''!==$capability && 'read'!==$capability)
			|| (''!==$data_class && !in_array($data_class,array('public','public_bounded','non_sensitive'),true));
		$risk=$high?'high':(('read'===$lane&&!$input_secret&&!$output_secret)?'low':'unknown');
		if('low'===$requested_risk && 'low'!==$risk) $contradictions[]='risk_downgrade_rejected';

		$eligible=true===$readonly
			&& 'read'===$lane
			&& 'declared_read_only'===$effect
			&& !$input_secret
			&& !$output_secret
			&& $conformance
			&& 'public_bounded'===$output_class
			&& !empty($features['execution_eligible'])
			&& !$breakglass
			&& empty($missing)
			&& empty($contradictions);

		$confidence=0;
		foreach(array('namespace','action','schema_sha256','output_schema_sha256','execution_lane','effect_class') as $key) if(!empty($features[$key])) $confidence+=8;
		if(null!==$readonly) $confidence+=8;
		if($conformance) $confidence+=22;
		if(!empty($features['execution_provider'])||'read'===$lane) $confidence+=8;
		if(!empty($features['reversible_contract'])) $confidence+=5;
		if('public_bounded'===$output_class) $confidence+=7;
		$confidence=min(95,$confidence);

		$classification=$eligible?'zero_effect_public_read_candidate':($high?'high_risk_review':('read'===$lane?'read_review_required':'unknown_review'));
		return array(
			'contract'=>self::PROPOSAL_CONTRACT,
			'classifier_version'=>self::CLASSIFIER_VERSION,
			'classification'=>$classification,
			'risk'=>$risk,
			'confidence'=>$confidence,
			'confidence_authority_effect'=>'none',
			'auto_classification_eligible'=>$eligible,
			'owner_review_required'=>!$eligible,
			'missing_evidence'=>array_values(array_unique($missing)),
			'contradictory_evidence'=>array_values(array_unique($contradictions)),
			'features'=>self::public_features($features),
			'authority_delta'=>array('grants'=>0,'mounts'=>0,'scopes'=>0,'certifications'=>0),
			'authorizing'=>false,
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
			'output_schema_sha256',
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
			'output_schema_secret_bearing',
			'output_schema_evidence_classification',
			'actual_conformance_verified',
			'actual_conformance_result',
			'actual_output_classification',
			'conformance_reason',
			'http_method',
			'declared_capability',
			'data_classification',
			'resource_schema_version',
			'resource_constraints_sha256',
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
