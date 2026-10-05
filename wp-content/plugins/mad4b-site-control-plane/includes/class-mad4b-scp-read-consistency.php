<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Snapshot-aware read consistency for MCP/connector clients.
 *
 * This service is deliberately read-only and stateless. It cannot repair an
 * external MCP session after that session has already terminated; instead it
 * gives clients the machine-readable identity needed to reconnect safely,
 * resume only missing reads on the same runtime generation, and discard partial
 * evidence when the runtime changed.
 */
final class MAD4B_SCP_Read_Consistency {
	const CONTRACT = 'mad4b.read-consistency.v1';
	const SNAPSHOT_ABILITY = 'mad4b/read-snapshot-header';
	const BUNDLE_ABILITY = 'mad4b/read-diagnostic-bundle';
	const METADATA_ABILITY = 'mad4b/read-metadata-envelope';
	const SESSION_SAFE_REPORT_ABILITY = 'mad4b/session-safe-diagnostics';
	const SESSION_SAFE_OPERATOR_SUMMARY_CONTRACT = 'mad4b.session-safe-operator-summary.v1';
	const MAX_SESSION_SAFE_REPORT_BYTES = 16384;
	const DEFAULT_SESSION_SAFE_BUDGET_MS = 12000;
	const MAX_SESSION_SAFE_BUDGET_MS = 20000;
	const DEFAULT_SNAPSHOT_TTL_SECONDS = 120;
	const DEFAULT_BUNDLE_BUDGET_MS = 8000;
	const MAX_BUNDLE_BUDGET_MS = 12000;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 26 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_session_safe_report' ), 27 );
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::SNAPSHOT_ABILITY ) ) ) {
			wp_register_ability(
				self::SNAPSHOT_ABILITY,
				array(
					'label' => 'Read Snapshot Header',
					'description' => 'Return a compact live runtime generation header used to bind resumable read-only diagnostics to one exact runtime.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'snapshot_header' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'properties' => array(
							'read_transaction_id' => array(
								'type' => 'string',
								'pattern' => '^rtx_[A-Za-z0-9._-]{8,80}$',
								'maxLength' => 84,
							),
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::read_meta(),
				)
			);
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::METADATA_ABILITY ) ) ) {
			wp_register_ability(
				self::METADATA_ABILITY,
				array(
					'label' => 'Compact Metadata Envelope',
					'description' => 'Return one compact generation-bound metadata/schema digest envelope for a governed Ability or registered operation.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'metadata_envelope' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'target_type', 'target' ),
						'properties' => array(
							'target_type' => array( 'type' => 'string', 'enum' => array( 'ability', 'operation' ) ),
							'target' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9._\\/-]{3,180}$', 'minLength' => 3, 'maxLength' => 180 ),
							'read_transaction_id' => array( 'type' => 'string', 'pattern' => '^rtx_[A-Za-z0-9._-]{8,80}$', 'maxLength' => 84 ),
							'expected_runtime_generation' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'minLength' => 64, 'maxLength' => 64 ),
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::read_meta(),
				)
			);
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::BUNDLE_ABILITY ) ) ) {
			wp_register_ability(
				self::BUNDLE_ABILITY,
				array(
					'label' => 'Bounded Diagnostic Bundle',
					'description' => 'Execute one fixed read-only diagnostic bundle only while the caller runtime generation remains current.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'diagnostic_bundle' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array(
						'type' => 'object',
						'required' => array( 'bundle', 'read_transaction_id', 'expected_runtime_generation', 'sequence' ),
						'properties' => array(
							'bundle' => array(
								'type' => 'string',
								'enum' => self::bundle_names(),
							),
							'read_transaction_id' => array(
								'type' => 'string',
								'pattern' => '^rtx_[A-Za-z0-9._-]{8,80}$',
								'maxLength' => 84,
							),
							'expected_runtime_generation' => array(
								'type' => 'string',
								'pattern' => '^[a-f0-9]{64}$',
								'minLength' => 64,
								'maxLength' => 64,
							),
							'sequence' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 16 ),
							'retry_transient_reads' => array( 'type' => 'boolean', 'default' => true ),
							'budget_ms' => array( 'type' => 'integer', 'minimum' => 1000, 'maximum' => self::MAX_BUNDLE_BUDGET_MS, 'default' => self::DEFAULT_BUNDLE_BUDGET_MS ),
						),
						'additionalProperties' => false,
					),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => self::read_meta(),
				)
			);
		}
	}

	public static function register_session_safe_report() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::SESSION_SAFE_REPORT_ABILITY ) ) return;

		wp_register_ability(
			self::SESSION_SAFE_REPORT_ABILITY,
			array(
				'label' => 'Session-Safe Diagnostics',
				'description' => 'Return one bounded generation-fenced diagnostic report that replaces parallel status fan-out for MCP clients.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'session_safe_diagnostics' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'read_transaction_id' => array(
							'type' => 'string',
							'pattern' => '^rtx_[A-Za-z0-9._-]{8,80}$',
							'maxLength' => 84,
						),
						'expected_runtime_generation' => array(
							'type' => 'string',
							'pattern' => '^[a-f0-9]{64}$',
							'minLength' => 64,
							'maxLength' => 64,
						),
						'retry_transient_reads' => array( 'type' => 'boolean', 'default' => true ),
						'budget_ms' => array(
							'type' => 'integer',
							'minimum' => 4000,
							'maximum' => self::MAX_SESSION_SAFE_BUDGET_MS,
							'default' => self::DEFAULT_SESSION_SAFE_BUDGET_MS,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => self::read_meta(),
			)
		);
	}

	public static function bundle_names() {
		return array( 'identity', 'runtime', 'certification', 'providers' );
	}

	private static function read_meta() {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		);
	}

	public static function snapshot_header( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$transaction_id = self::transaction_id( isset( $input['read_transaction_id'] ) ? $input['read_transaction_id'] : '' );
		$build = self::build_projection();
		$profile = self::profile_projection();
		$candidate = self::candidate_projection();
		$catalog = self::catalog_projection();
		$plugin_generation = self::active_plugin_generation();
		$session_fingerprint = self::session_fingerprint();

		$candidate_digest = self::digest( $candidate );
		$runtime_generation = self::digest( array(
			'source_commit_sha' => isset( $build['source_commit_sha'] ) ? $build['source_commit_sha'] : '',
			'build_fingerprint' => isset( $build['build_fingerprint'] ) ? $build['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $build['package_manifest_digest'] ) ? $build['package_manifest_digest'] : '',
			'candidate_binding_digest' => $candidate_digest,
			'provider_inventory_digest' => isset( $catalog['provider_inventory_digest'] ) ? $catalog['provider_inventory_digest'] : '',
			'active_plugin_generation' => $plugin_generation,
			'site_profile_digest' => isset( $profile['profile_digest'] ) ? $profile['profile_digest'] : '',
			'site_profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
		) );
		$identity_complete = self::is_sha( isset( $build['source_commit_sha'] ) ? $build['source_commit_sha'] : '', 40 )
			&& self::is_sha( isset( $build['build_fingerprint'] ) ? $build['build_fingerprint'] : '', 64 )
			&& self::is_sha( isset( $build['package_manifest_digest'] ) ? $build['package_manifest_digest'] : '', 64 );
		$observed_at = gmdate( 'c' );

		return array(
			'contract' => self::CONTRACT,
			'state' => $identity_complete ? 'ready' : 'partial_identity',
			'identity_complete' => $identity_complete,
			'snapshot_id' => hash( 'sha256', $runtime_generation . "\n" . $session_fingerprint ),
			'read_transaction_id' => $transaction_id,
			'environment' => isset( $profile['environment'] ) ? (string) $profile['environment'] : '',
			'source_commit_sha' => isset( $build['source_commit_sha'] ) ? (string) $build['source_commit_sha'] : '',
			'build_fingerprint' => isset( $build['build_fingerprint'] ) ? (string) $build['build_fingerprint'] : '',
			'package_manifest_digest' => isset( $build['package_manifest_digest'] ) ? (string) $build['package_manifest_digest'] : '',
			'candidate_binding_digest' => $candidate_digest,
			'provider_inventory_digest' => isset( $catalog['provider_inventory_digest'] ) ? (string) $catalog['provider_inventory_digest'] : '',
			'active_plugin_generation' => $plugin_generation,
			'site_profile_digest' => isset( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
			'site_profile_revision' => isset( $profile['revision'] ) ? (int) $profile['revision'] : 0,
			'runtime_generation' => $runtime_generation,
			'session_fingerprint' => $session_fingerprint,
			'transport_server_id' => class_exists( 'MAD4B_SCP_Transport_Context' ) ? MAD4B_SCP_Transport_Context::current_server_id() : '',
			'projection_freshness' => 'live',
			'observed_at' => $observed_at,
			'expires_at' => gmdate( 'c', time() + self::DEFAULT_SNAPSHOT_TTL_SECONDS ),
			'resume_permitted_if_generation_matches' => true,
			'discard_partial_on_generation_change' => true,
			'server_state_persisted' => false,
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
		);
	}

	public static function metadata_envelope( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$target_type = isset( $input['target_type'] ) ? sanitize_key( (string) $input['target_type'] ) : '';
		$target = isset( $input['target'] ) ? trim( sanitize_text_field( (string) $input['target'] ) ) : '';
		if ( ! in_array( $target_type, array( 'ability', 'operation' ), true ) || '' === $target ) {
			return new WP_Error( 'mad4b_metadata_envelope_target_invalid', 'A supported metadata target type and target are required.' );
		}
		$transaction_id = self::transaction_id( isset( $input['read_transaction_id'] ) ? $input['read_transaction_id'] : '' );
		$expected_generation = isset( $input['expected_runtime_generation'] ) ? strtolower( trim( (string) $input['expected_runtime_generation'] ) ) : '';
		$before = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		if ( '' !== $expected_generation && ! self::generation_matches( $expected_generation, $before['runtime_generation'] ) ) {
			return self::generation_changed_envelope( 'metadata', $transaction_id, 1, $expected_generation, $before, 'metadata_preflight_generation_mismatch' );
		}

		if ( 'ability' === $target_type
			&& ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::is_chatgpt_full_catalog_candidate( $target ) ) ) {
			return new WP_Error( 'mad4b_metadata_ability_not_cataloged', 'Requested Ability is outside the governed ChatGPT capability universe.' );
		}

		$payload = array(
			'target_type' => $target_type,
			'target' => $target,
			'found' => false,
			'remote_ability' => '',
			'authority_surface' => '',
			'registration_digest' => '',
			'dispatch_policy_digest' => '',
			'operation_policy_digest' => '',
			'dispatch_identity_source' => '',
			'input_schema_available' => false,
			'input_schema_sha256' => '',
			'output_schema_sha256' => '',
			'metadata_sha256' => '',
		);
		$ability_name = $target;

		if ( 'operation' === $target_type ) {
			$operation_id = sanitize_key( $target );
			if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) || ! method_exists( 'MAD4B_SCP_Enrollment_Dispatch', 'info' ) ) {
				return new WP_Error( 'mad4b_metadata_dispatch_info_unavailable', 'Canonical enrollment dispatch metadata service is unavailable.' );
			}
			$dispatch_info = MAD4B_SCP_Enrollment_Dispatch::info( array( 'operation_id' => $operation_id ) );
			if ( is_wp_error( $dispatch_info ) ) return $dispatch_info;
			if ( ! is_array( $dispatch_info )
				|| empty( $dispatch_info['remote_ability'] )
				|| ! self::is_sha( isset( $dispatch_info['registration_digest'] ) ? $dispatch_info['registration_digest'] : '', 64 )
				|| ! self::is_sha( isset( $dispatch_info['dispatch_policy_digest'] ) ? $dispatch_info['dispatch_policy_digest'] : '', 64 )
				|| ! self::is_sha( isset( $dispatch_info['input_schema_sha256'] ) ? $dispatch_info['input_schema_sha256'] : '', 64 ) ) {
				return new WP_Error( 'mad4b_metadata_dispatch_identity_incomplete', 'Canonical enrollment dispatch metadata is incomplete.' );
			}
			$ability_name = (string) $dispatch_info['remote_ability'];
			$operation = isset( $dispatch_info['operation'] ) && is_array( $dispatch_info['operation'] ) ? $dispatch_info['operation'] : array();
			$payload['target'] = $operation_id;
			$payload['found'] = true;
			$payload['remote_ability'] = $ability_name;
			$payload['authority_surface'] = isset( $operation['authority_surface'] ) ? (string) $operation['authority_surface'] : 'mad4b-enrollment';
			$payload['registration_digest'] = strtolower( (string) $dispatch_info['registration_digest'] );
			$payload['dispatch_policy_digest'] = strtolower( (string) $dispatch_info['dispatch_policy_digest'] );
			$payload['operation_policy_digest'] = $payload['dispatch_policy_digest'];
			$payload['dispatch_identity_source'] = 'MAD4B_SCP_Enrollment_Dispatch::info';
			$payload['input_schema_available'] = isset( $dispatch_info['input_schema'] ) && is_array( $dispatch_info['input_schema'] ) && ! empty( $dispatch_info['input_schema'] );
			$payload['input_schema_sha256'] = strtolower( (string) $dispatch_info['input_schema_sha256'] );
		}

		if ( '' !== $ability_name && function_exists( 'wp_has_ability' ) && function_exists( 'wp_get_ability' ) && wp_has_ability( $ability_name ) ) {
			$ability = wp_get_ability( $ability_name );
			$input_schema = self::ability_schema( $ability, 'input' );
			$output_schema = self::ability_schema( $ability, 'output' );
			$meta = self::ability_meta( $ability );
			if ( 'operation' === $target_type ) {
				if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) || ! method_exists( 'MAD4B_SCP_Enrollment_Dispatch', 'input_schema_sha256' ) ) {
					return new WP_Error( 'mad4b_metadata_dispatch_schema_digest_unavailable', 'Canonical enrollment schema digest helper is unavailable.' );
				}
				$computed_input_schema_sha256 = MAD4B_SCP_Enrollment_Dispatch::input_schema_sha256( $ability );
			} else {
				$computed_input_schema_sha256 = self::digest( $input_schema );
			}
			if ( 'operation' === $target_type && '' !== $payload['input_schema_sha256'] && ! hash_equals( $payload['input_schema_sha256'], $computed_input_schema_sha256 ) ) {
				return new WP_Error( 'mad4b_metadata_dispatch_schema_projection_drift', 'Canonical enrollment dispatch schema digest does not match the live Ability schema.' );
			}
			$payload['found'] = true;
			$payload['remote_ability'] = $ability_name;
			$payload['input_schema_available'] = ! empty( $input_schema );
			$payload['input_schema_sha256'] = $computed_input_schema_sha256;
			$payload['output_schema_sha256'] = self::digest( $output_schema );
			$payload['metadata_sha256'] = self::digest( $meta );
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
			$payload['readonly'] = array_key_exists( 'readonly', $annotations ) ? (bool) $annotations['readonly'] : null;
			$payload['destructive'] = array_key_exists( 'destructive', $annotations ) ? (bool) $annotations['destructive'] : null;
			$payload['idempotent'] = array_key_exists( 'idempotent', $annotations ) ? (bool) $annotations['idempotent'] : null;
			$payload['surface'] = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		}

		$after = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		if ( ! self::generation_matches( $before['runtime_generation'], $after['runtime_generation'] ) ) {
			return self::generation_changed_envelope( 'metadata', $transaction_id, 1, $before['runtime_generation'], $after, 'runtime_changed_during_metadata_read' );
		}
		$payload['contract'] = self::CONTRACT;
		$payload['state'] = ! empty( $payload['found'] ) ? 'ready' : 'not_found';
		$payload['read_transaction_id'] = $transaction_id;
		$payload['snapshot_id'] = $after['snapshot_id'];
		$payload['runtime_generation'] = $after['runtime_generation'];
		$payload['generation_match'] = true;
		$payload['valid_for_resume'] = true;
		$payload['execution_binding_digest'] = self::digest( array(
			'runtime_generation' => $after['runtime_generation'],
			'target_type' => $payload['target_type'],
			'target' => $payload['target'],
			'remote_ability' => $payload['remote_ability'],
			'registration_digest' => $payload['registration_digest'],
			'dispatch_policy_digest' => $payload['dispatch_policy_digest'],
			'input_schema_sha256' => $payload['input_schema_sha256'],
		) );
		$payload['observed_at'] = gmdate( 'c' );
		$payload['read_only'] = true;
		$payload['mutation_performed'] = false;
		$payload['production_mutation_performed'] = false;
		return $payload;
	}

	public static function diagnostic_bundle( $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) {
			return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		}

		$bundle = isset( $input['bundle'] ) ? sanitize_key( (string) $input['bundle'] ) : '';
		if ( ! in_array( $bundle, self::bundle_names(), true ) ) {
			return new WP_Error( 'mad4b_read_bundle_invalid', 'Requested diagnostic bundle is not in the fixed read-only bundle catalog.' );
		}
		$transaction_id = self::transaction_id( isset( $input['read_transaction_id'] ) ? $input['read_transaction_id'] : '' );
		$expected_generation = isset( $input['expected_runtime_generation'] ) ? strtolower( trim( (string) $input['expected_runtime_generation'] ) ) : '';
		$sequence = isset( $input['sequence'] ) ? max( 1, min( 16, absint( $input['sequence'] ) ) ) : 1;
		$retry = ! array_key_exists( 'retry_transient_reads', $input ) || ! empty( $input['retry_transient_reads'] );
		$budget_ms = isset( $input['budget_ms'] )
			? max( 1000, min( self::MAX_BUNDLE_BUDGET_MS, absint( $input['budget_ms'] ) ) )
			: self::DEFAULT_BUNDLE_BUDGET_MS;

		$before = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		if ( ! self::generation_matches( $expected_generation, $before['runtime_generation'] ) ) {
			return self::generation_changed_envelope( $bundle, $transaction_id, $sequence, $expected_generation, $before, 'preflight_generation_mismatch' );
		}

		$result = MAD4B_SCP_Connector_Resilience::run_checks(
			self::bundle_checks( $bundle ),
			array(
				'budget_ms' => $budget_ms,
				'retry_transient' => $retry,
			)
		);

		$after = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		if ( ! self::generation_matches( $before['runtime_generation'], $after['runtime_generation'] ) ) {
			$changed = self::generation_changed_envelope( $bundle, $transaction_id, $sequence, $expected_generation, $after, 'runtime_changed_during_bundle' );
			$changed['discarded_check_count'] = isset( $result['checks'] ) && is_array( $result['checks'] ) ? count( $result['checks'] ) : 0;
			$changed['elapsed_ms'] = isset( $result['elapsed_ms'] ) ? (int) $result['elapsed_ms'] : 0;
			return $changed;
		}

		$observed_at = gmdate( 'c' );
		if ( isset( $result['checks'] ) && is_array( $result['checks'] ) ) {
			foreach ( $result['checks'] as $name => $check ) {
				if ( ! is_array( $check ) ) continue;
				$result['checks'][ $name ]['read_transaction_id'] = $transaction_id;
				$result['checks'][ $name ]['sequence'] = $sequence;
				$result['checks'][ $name ]['bundle'] = $bundle;
				$result['checks'][ $name ]['source_generation'] = $after['runtime_generation'];
				$result['checks'][ $name ]['projection_freshness'] = ! empty( $check['ok'] ) ? 'live' : 'unavailable';
				$result['checks'][ $name ]['observed_at'] = $observed_at;
			}
		}

		$result['contract'] = self::CONTRACT;
		$result['bundle'] = $bundle;
		$result['read_transaction_id'] = $transaction_id;
		$result['sequence'] = $sequence;
		$result['snapshot_id'] = $after['snapshot_id'];
		$result['expected_runtime_generation'] = $expected_generation;
		$result['runtime_generation'] = $after['runtime_generation'];
		$result['generation_match'] = true;
		// Backward-compatible alias: bundle merge means only that this evidence can
		// join the same generation-bound read transaction. It is never a release or
		// deployment acceptance verdict.
		$result['valid_for_merge'] = true;
		$result['valid_for_bundle_evidence_merge'] = true;
		$result['valid_for_release_merge'] = false;
		$result['merge_scope'] = 'generation_bound_bundle_evidence_only';
		$result['deep_acceptance_required'] = true;
		$result['resume_permitted'] = true;
		$result['discard_partial'] = false;
		$result['projection_freshness'] = 'live';
		$result['observed_at'] = $observed_at;
		$result['client_action'] = ! empty( $result['partial'] ) ? 'resume_missing_checks_same_generation' : 'continue_next_bundle';
		$result['resilience_contract'] = MAD4B_SCP_Connector_Resilience::CONTRACT;
		$result['client_guidance'] = MAD4B_SCP_Connector_Resilience::client_guidance();
		return $result;
	}

	public static function session_safe_diagnostics( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) {
			return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		}

		$transaction_id = self::transaction_id( isset( $input['read_transaction_id'] ) ? $input['read_transaction_id'] : '' );
		$expected_generation = isset( $input['expected_runtime_generation'] ) ? strtolower( trim( (string) $input['expected_runtime_generation'] ) ) : '';
		$retry = ! array_key_exists( 'retry_transient_reads', $input ) || ! empty( $input['retry_transient_reads'] );
		$budget_ms = isset( $input['budget_ms'] )
			? max( 4000, min( self::MAX_SESSION_SAFE_BUDGET_MS, absint( $input['budget_ms'] ) ) )
			: self::DEFAULT_SESSION_SAFE_BUDGET_MS;
		$started = microtime( true );

		$before = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		$runtime_generation = isset( $before['runtime_generation'] ) ? (string) $before['runtime_generation'] : '';
		if ( '' !== $expected_generation && ! self::generation_matches( $expected_generation, $runtime_generation ) ) {
			return self::generation_changed_envelope( 'session_safe_diagnostics', $transaction_id, 1, $expected_generation, $before, 'session_safe_preflight_generation_mismatch' );
		}

		$sections = array();
		$partial = false;
		$session_termination_count = 0;
		$stop_reason = '';

		foreach ( self::bundle_names() as $index => $bundle ) {
			$elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
			$remaining_ms = $budget_ms - $elapsed_ms;
			if ( $remaining_ms < 1000 ) {
				$partial = true;
				$stop_reason = 'request_budget';
				$sections[ $bundle ] = array(
					'state' => 'skipped_budget',
					'partial' => true,
					'failed_checks' => array(),
					'retryable_checks' => array(),
					'skipped_budget_checks' => array( '*' ),
					'session_termination_count' => $session_termination_count,
					'check_count' => count( self::session_safe_bundle_checks( $bundle ) ),
					'evidence_digest' => self::digest( array( 'bundle' => $bundle, 'state' => 'skipped_budget', 'generation' => $runtime_generation ) ),
				);
				continue;
			}

			$result = MAD4B_SCP_Connector_Resilience::run_checks(
				self::session_safe_bundle_checks( $bundle ),
				array(
					'budget_ms' => min( self::MAX_BUNDLE_BUDGET_MS, max( 1000, $remaining_ms ) ),
					'retry_transient' => $retry,
				)
			);
			$sections[ $bundle ] = self::compact_bundle_result( $bundle, $result );
			$session_termination_count += isset( $result['session_termination_count'] ) ? max( 0, (int) $result['session_termination_count'] ) : 0;
			if ( ! empty( $result['partial'] ) ) $partial = true;

			if ( ! empty( $result['session_breaker_open'] ) ) {
				$partial = true;
				$stop_reason = 'session_breaker';
				foreach ( array_slice( self::bundle_names(), $index + 1 ) as $remaining_bundle ) {
					$sections[ $remaining_bundle ] = array(
						'state' => 'skipped_session_breaker',
						'partial' => true,
						'failed_checks' => array(),
						'retryable_checks' => array( '*' ),
						'skipped_budget_checks' => array(),
						'session_termination_count' => $session_termination_count,
						'check_count' => count( self::session_safe_bundle_checks( $remaining_bundle ) ),
						'evidence_digest' => self::digest( array( 'bundle' => $remaining_bundle, 'state' => 'skipped_session_breaker', 'generation' => $runtime_generation ) ),
					);
				}
				break;
			}
		}

		$after = self::snapshot_header( array( 'read_transaction_id' => $transaction_id ) );
		if ( ! self::generation_matches( $runtime_generation, isset( $after['runtime_generation'] ) ? $after['runtime_generation'] : '' ) ) {
			$changed = self::generation_changed_envelope( 'session_safe_diagnostics', $transaction_id, 1, $runtime_generation, $after, 'runtime_changed_during_session_safe_report' );
			$changed['discarded_section_count'] = count( $sections );
			$changed['report_payload_discarded'] = true;
			return $changed;
		}

		$subject_blockers = array();
		$runtime_checks = isset( $sections['runtime']['checks'] ) && is_array( $sections['runtime']['checks'] ) ? $sections['runtime']['checks'] : array();
		foreach ( array(
			'write_authority' => 'effective_authority_ready',
			'skills_runtime' => 'effective_skill_ready',
		) as $check_name => $effective_key ) {
			$summary = isset( $runtime_checks[ $check_name ]['summary'] ) && is_array( $runtime_checks[ $check_name ]['summary'] ) ? $runtime_checks[ $check_name ]['summary'] : array();
			if ( empty( $summary[ $effective_key ] ) ) $subject_blockers[] = $check_name . '_not_effective';
		}
		$subject_blockers = array_values( array_unique( $subject_blockers ) );
		$operator_summary = self::session_safe_operator_summary( $sections, $partial, $subject_blockers, $after );
		$valid_for_session_evidence_merge = ! $partial && empty( $subject_blockers );
		$deep_checks_deferred = array(
			'full_runtime_provenance_hash',
			'deep_write_authority_scan',
			'live_skill_filesystem_reconciliation',
			'live_update_manifest_network_fetch',
			'staging_certification_sweep',
			'workflow_provider_runtime_inventory',
			'write_catalog_runtime_rebuild',
		);

		$diagnostic_elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		$request_metrics = self::request_metrics();
		$performance_observation = self::performance_observation( $request_metrics, $budget_ms, $runtime_generation, $diagnostic_elapsed_ms );
		$report = array(
			'contract' => 'mad4b.session-safe-diagnostics.v1',
			'state' => $partial ? 'partial' : ( $valid_for_session_evidence_merge ? 'ready' : 'subject_not_ready' ),
			'partial' => $partial,
			'read_transaction_id' => $transaction_id,
			'snapshot_id' => isset( $after['snapshot_id'] ) ? (string) $after['snapshot_id'] : '',
			'runtime_generation' => $runtime_generation,
			'generation_match' => true,
			// Backward-compatible alias: this proves only that the bounded session
			// evidence can be merged into one coherent report. It is not a release,
			// PR or deployment acceptance verdict.
			'valid_for_merge' => $valid_for_session_evidence_merge,
			'valid_for_session_evidence_merge' => $valid_for_session_evidence_merge,
			'valid_for_release_merge' => false,
			'merge_scope' => 'session_safe_subject_evidence_only',
			'deep_acceptance_required' => true,
			'subject_blockers' => $subject_blockers,
			'operator_summary' => $operator_summary,
			'subject_live_validation_deferred' => array( 'skills_runtime' ),
			'projection_freshness' => 'live',
			'observed_at' => gmdate( 'c' ),
			'elapsed_ms' => $diagnostic_elapsed_ms,
			'budget_ms' => $budget_ms,
			'request_metrics' => $request_metrics,
			'performance_observation' => $performance_observation,
			'recommended_next_step' => self::session_safe_next_step( $partial, $subject_blockers, $operator_summary ),
			'deep_checks_deferred' => $deep_checks_deferred,
			'release_acceptance_deferred_checks' => $deep_checks_deferred,
			'fixed_bundle_order' => self::bundle_names(),
			'section_count' => count( $sections ),
			'sections' => $sections,
			'session_termination_count' => $session_termination_count,
			'stop_reason' => $stop_reason,
			'transport_policy' => array(
				'external_mcp_calls_required' => 1,
				'server_sequential_execution' => true,
				'direct_parallel_fanout_required' => false,
				'direct_composite_fanout_allowed' => false,
				'max_response_bytes' => self::MAX_SESSION_SAFE_REPORT_BYTES,
			),
			'client_action' => $partial ? 'inspect_partial_report_then_retry_missing_scope' : 'use_report_without_parallel_status_fanout',
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
		);
		return self::bound_session_safe_report( $report );
	}

	private static function session_safe_operator_summary( array $sections, $partial, array $subject_blockers, array $snapshot ) {
		$runtime_checks = isset( $sections['runtime']['checks'] ) && is_array( $sections['runtime']['checks'] ) ? $sections['runtime']['checks'] : array();
		$write = isset( $runtime_checks['write_authority']['summary'] ) && is_array( $runtime_checks['write_authority']['summary'] ) ? $runtime_checks['write_authority']['summary'] : array();
		$adapter_lifecycle = isset( $runtime_checks['adapter_lifecycle']['summary'] ) && is_array( $runtime_checks['adapter_lifecycle']['summary'] ) ? $runtime_checks['adapter_lifecycle']['summary'] : array();
		$skills = isset( $runtime_checks['skills_runtime']['summary'] ) && is_array( $runtime_checks['skills_runtime']['summary'] ) ? $runtime_checks['skills_runtime']['summary'] : array();
		$topology = isset( $runtime_checks['database_topology']['summary'] ) && is_array( $runtime_checks['database_topology']['summary'] ) ? $runtime_checks['database_topology']['summary'] : array();
		$protocol = isset( $runtime_checks['mcp_protocol_profile']['summary'] ) && is_array( $runtime_checks['mcp_protocol_profile']['summary'] ) ? $runtime_checks['mcp_protocol_profile']['summary'] : array();

		$adapter_lifecycle_ready = array_key_exists( 'ready', $adapter_lifecycle ) && null !== $adapter_lifecycle['ready'] ? (bool) $adapter_lifecycle['ready'] : null;
		$write_ready = array_key_exists( 'effective_authority_ready', $write ) && null !== $write['effective_authority_ready'] ? (bool) $write['effective_authority_ready'] : null;
		$candidate_match = array_key_exists( 'candidate_binding_match', $write ) && null !== $write['candidate_binding_match'] ? (bool) $write['candidate_binding_match'] : null;
		$grant_snapshot_ready = array_key_exists( 'current_grant_snapshot_ready', $write ) && null !== $write['current_grant_snapshot_ready'] ? (bool) $write['current_grant_snapshot_ready'] : null;
		$skills_ready = array_key_exists( 'effective_skill_ready', $skills ) && null !== $skills['effective_skill_ready'] ? (bool) $skills['effective_skill_ready'] : null;
		$topology_ready = array_key_exists( 'ready', $topology ) && null !== $topology['ready'] ? (bool) $topology['ready'] : null;
		$read_your_writes = array_key_exists( 'read_your_writes', $topology ) && null !== $topology['read_your_writes'] ? (bool) $topology['read_your_writes'] : null;
		$protocol_ready = array_key_exists( 'ready', $protocol ) && null !== $protocol['ready'] ? (bool) $protocol['ready'] : null;

		$reasons = self::bounded_scalar_list( $subject_blockers, 12 );
		$actions = array();
		$blocking = false;
		if ( false === $adapter_lifecycle_ready ) {
			$reasons[] = 'adapter_ability_lifecycle_incomplete';
			$actions[] = 'repair_adapter_ability_lifecycle_registration';
			$blocking = true;
		}
		if ( false === $write_ready ) {
			$reasons[] = 'write_authority_not_current';
			$actions[] = 'reconcile_exact_staging_write_authority';
			$blocking = true;
		}
		if ( false === $candidate_match ) {
			$reasons[] = 'runtime_authority_candidate_not_reconciled';
			$actions[] = 'reconcile_exact_staging_write_authority';
			$blocking = true;
		}
		if ( false === $grant_snapshot_ready ) {
			$reasons[] = 'exact_write_grants_not_current';
			$actions[] = 'reconcile_exact_staging_write_authority';
			$blocking = true;
		}
		foreach ( isset( $write['current_readiness_blockers'] ) && is_array( $write['current_readiness_blockers'] ) ? $write['current_readiness_blockers'] : array() as $reason ) {
			$reason = sanitize_key( (string) $reason );
			if ( '' !== $reason ) $reasons[] = $reason;
		}
		if ( false === $topology_ready || false === $read_your_writes ) {
			$reasons[] = 'database_topology_not_write_safe';
			$actions[] = 'inspect_database_writer_topology';
			$blocking = true;
		}
		foreach ( isset( $topology['blockers'] ) && is_array( $topology['blockers'] ) ? $topology['blockers'] : array() as $reason ) {
			$reason = sanitize_key( (string) $reason );
			if ( '' !== $reason ) $reasons[] = $reason;
		}
		if ( false === $protocol_ready ) {
			$reasons[] = 'mcp_protocol_profile_not_ready';
			if ( ! empty( $protocol['blocker'] ) ) $reasons[] = sanitize_key( (string) $protocol['blocker'] );
			$actions[] = 'deploy_exact_certified_runtime_release';
			$blocking = true;
		}
		if ( false === $skills_ready ) {
			$reasons[] = 'skills_runtime_not_ready';
			$actions[] = 'reconcile_managed_skills';
		}
		foreach ( array(
			'adapter_lifecycle_ready' => $adapter_lifecycle_ready,
			'write_authority_ready' => $write_ready,
			'candidate_binding_match' => $candidate_match,
			'current_grant_snapshot_ready' => $grant_snapshot_ready,
			'database_topology_ready' => $topology_ready,
			'read_your_writes' => $read_your_writes,
			'mcp_protocol_profile_ready' => $protocol_ready,
		) as $signal => $ready ) {
			if ( null !== $ready ) continue;
			$reasons[] = $signal . '_unknown';
			$actions[] = 'inspect_operational_readiness';
		}
		if ( $partial ) {
			$reasons[] = 'diagnostics_partial';
			$actions[] = 'query_one_generation_bound_bundle';
		}
		$reasons = array_values( array_unique( self::bounded_scalar_list( $reasons, 16 ) ) );
		$actions = array_values( array_unique( self::bounded_scalar_list( $actions, 8 ) ) );
		$state = $blocking ? 'BLOCKED' : ( ! empty( $reasons ) ? 'DEGRADED' : 'HEALTHY' );
		return array(
			'contract' => self::SESSION_SAFE_OPERATOR_SUMMARY_CONTRACT,
			'state' => $state,
			'effective_environment' => isset( $snapshot['environment'] ) ? sanitize_key( (string) $snapshot['environment'] ) : '',
			'reasons' => $reasons,
			'next_actions' => $actions,
			'signals' => array(
				'adapter_lifecycle_ready' => $adapter_lifecycle_ready,
				'adapter_lifecycle_missing_abilities' => self::bounded_scalar_list(
					isset( $adapter_lifecycle['missing_abilities'] ) && is_array( $adapter_lifecycle['missing_abilities'] ) ? $adapter_lifecycle['missing_abilities'] : array(),
					8
				),
				'write_authority_ready' => $write_ready,
				'candidate_binding_match' => $candidate_match,
				'current_grant_snapshot_ready' => $grant_snapshot_ready,
				'database_topology_ready' => $topology_ready,
				'read_your_writes' => $read_your_writes,
				'mcp_protocol_profile_ready' => $protocol_ready,
				'skills_runtime_ready' => $skills_ready,
			),
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function compact_bundle_result( $bundle, array $result ) {
		$checks = array();
		foreach ( isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array() as $name => $check ) {
			$check = is_array( $check ) ? $check : array();
			$data = isset( $check['data'] ) && is_array( $check['data'] ) ? $check['data'] : array();
			$summary = self::compact_status_data( $name, $data );
			$checks[ sanitize_key( (string) $name ) ] = array(
				'ok' => ! empty( $check['ok'] ),
				'state' => isset( $check['state'] ) ? sanitize_key( (string) $check['state'] ) : '',
				'check_execution_state' => isset( $check['state'] ) ? sanitize_key( (string) $check['state'] ) : '',
				'subject_state' => isset( $summary['state'] ) ? sanitize_key( (string) $summary['state'] ) : '',
				'subject_ready' => class_exists( 'MAD4B_SCP_Truth_Projection' ) ? MAD4B_SCP_Truth_Projection::tri_state( $summary, 'ready' ) : ( array_key_exists( 'ready', $summary ) && null !== $summary['ready'] ? (bool) $summary['ready'] : null ),
				'category' => isset( $check['category'] ) ? sanitize_key( (string) $check['category'] ) : '',
				'elapsed_ms' => isset( $check['elapsed_ms'] ) ? max( 0, (int) $check['elapsed_ms'] ) : 0,
				'summary' => $summary,
				'evidence_digest' => self::digest( $data ),
			);
		}
		return array(
			'state' => isset( $result['state'] ) ? sanitize_key( (string) $result['state'] ) : ( ! empty( $result['partial'] ) ? 'partial' : 'ready' ),
			'partial' => ! empty( $result['partial'] ),
			'elapsed_ms' => isset( $result['elapsed_ms'] ) ? max( 0, (int) $result['elapsed_ms'] ) : 0,
			'failed_checks' => self::bounded_scalar_list( isset( $result['failed_checks'] ) ? $result['failed_checks'] : array(), 16 ),
			'retryable_checks' => self::bounded_scalar_list( isset( $result['retryable_checks'] ) ? $result['retryable_checks'] : array(), 16 ),
			'skipped_budget_checks' => self::bounded_scalar_list( isset( $result['skipped_budget_checks'] ) ? $result['skipped_budget_checks'] : array(), 16 ),
			'session_termination_count' => isset( $result['session_termination_count'] ) ? max( 0, (int) $result['session_termination_count'] ) : 0,
			'check_count' => count( $checks ),
			'checks' => $checks,
			'evidence_digest' => self::digest( array( 'bundle' => $bundle, 'result' => $result ) ),
		);
	}

	private static function compact_status_data( $name, $data ) {
		if ( ! is_array( $data ) ) return is_scalar( $data ) || null === $data ? $data : null;
		$name = sanitize_key( (string) $name );
		$scalar_keys = array(
			'contract', 'ready', 'state', 'supported', 'configured', 'environment', 'revision',
			'expected_count', 'registered_count', 'registration_only', 'authority_evaluated',
			'profile_digest', 'exact_profile_bound', 'write_enabled', 'skills_enabled',
			'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest',
			'artifact_identity', 'mcp_adapter_version', 'runtime_manifest_match', 'stale',
			'local_transport_ready', 'local_transport_validation_state', 'local_transport_deep_validation_ready',
			'remote_endpoint_preflight_ready', 'remote_endpoint_preflight_state', 'remote_endpoint_deep_preflight_ready',
			'connection_certified', 'connection_certification_state',
			'resource', 'local_oauth_effective', 'oauth_resource_bridge_effective', 'chatgpt_registered',
			'missed_rest_recovery_state', 'write_auto_enabled', 'production_authority_auto_enabled',
			'breakglass_auto_enabled', 'eligible', 'runtime_reconciled', 'candidate_binding_required',
			'candidate_binding_match', 'current_source_commit_sha', 'candidate_source_commit_sha',
			'write_tool_count', 'write_inventory_fingerprint', 'provider_blocked_fingerprint',
			'wildcard_grants', 'breakglass_included', 'production_auto_enable', 'breakglass_auto_enable',
			'persistence', 'provider_count', 'expected_provider_count', 'managed_skill_count',
			'expected_managed_skill_count', 'manifest_state', 'manifest_error', 'native_update_ready',
			'production_remote_upload_allowed', 'default_provider', 'provider_summary_digest',
			'chatgpt_tool_count', 'raw_sql_breakglass_in_write_inventory', 'reconciliation_required',
			'blind_retry_allowed', 'next_action', 'candidate_match', 'build_fingerprint_match',
			'full_runtime_hash_validation_deferred', 'deep_authority_scan_deferred',
			'live_skill_evaluation_deferred', 'runtime_catalog_rebuild_deferred',
			'recorded_ready', 'current_candidate_match', 'effective_skill_ready', 'effective_skill_ready_scope', 'candidate_identity_bound_ready', 'live_skill_ready', 'recorded_source_commit_sha', 'recorded_build_fingerprint',
			'persisted_authority_ready', 'effective_authority_ready',
			'read_your_writes', 'database_dropin_present', 'database_dropin_observer_certified', 'database_dropin_ownership', 'observer_dropin_certified',
			'blocker', 'certified_adapter_version', 'runtime_adapter_version', 'adapter_version_match', 'successor_certification_state',
			'current_grant_snapshot_performed', 'current_grant_snapshot_ready',
			'deep_route_validation_deferred', 'deep_peer_inventory_deferred',
			'deep_oauth_validation_deferred', 'provider_runtime_hash_validation_deferred',
			'deep_local_oauth_status_deferred', 'deep_oauth_bridge_status_deferred',
			'seo_publication_authorized', 'production_activation_authorized', 'observed_at'
		);
		$out = array();
		foreach ( $scalar_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) continue;
			$value = $data[ $key ];
			if ( is_scalar( $value ) || null === $value ) $out[ $key ] = $value;
		}
		foreach ( array( 'missing_abilities', 'blockers', 'current_readiness_blockers', 'local_blockers', 'remote_preflight_blockers', 'certification_blockers', 'blocking_gates', 'provenance_mismatch', 'deferred_checks' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) $out[ $key ] = self::bounded_scalar_list( $data[ $key ], 12 );
		}
		foreach ( array( 'current', 'target', 'build', 'connection', 'write_authority' ) as $nested_key ) {
			if ( empty( $data[ $nested_key ] ) || ! is_array( $data[ $nested_key ] ) ) continue;
			$nested = array();
			foreach ( array(
				'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity',
				'environment', 'control_plane_version', 'mcp_adapter_version', 'connection_certified',
				'ready', 'state', 'candidate_binding_match', 'current_source_commit_sha', 'candidate_source_commit_sha'
			) as $key ) {
				if ( array_key_exists( $key, $data[ $nested_key ] ) && ( is_scalar( $data[ $nested_key ][ $key ] ) || null === $data[ $nested_key ][ $key ] ) ) {
					$nested[ $key ] = $data[ $nested_key ][ $key ];
				}
			}
			$out[ $nested_key ] = $nested;
		}
		if ( 'workflow_providers' === $name ) {
			unset( $out['providers'] );
			$out['provider_count'] = isset( $data['provider_count'] ) ? max( 0, (int) $data['provider_count'] ) : 0;
		}
		return $out;
	}

	private static function bounded_scalar_list( $value, $max_items ) {
		$value = is_array( $value ) ? array_values( $value ) : array();
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) && null !== $item ) continue;
			$out[] = is_string( $item ) ? substr( $item, 0, 191 ) : $item;
			if ( count( $out ) >= max( 1, (int) $max_items ) ) break;
		}
		return $out;
	}

	private static function bound_session_safe_report( array $report ) {
		$report['payload_reduced'] = false;
		$report['max_response_bytes'] = self::MAX_SESSION_SAFE_REPORT_BYTES;
		$report['response_bytes'] = 0;
		$encoded = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$bytes = false === $encoded ? self::MAX_SESSION_SAFE_REPORT_BYTES + 1 : strlen( $encoded );
		if ( $bytes > self::MAX_SESSION_SAFE_REPORT_BYTES ) {
			$reduced_sections = array();
			foreach ( isset( $report['sections'] ) && is_array( $report['sections'] ) ? $report['sections'] : array() as $bundle => $section ) {
				$section = is_array( $section ) ? $section : array();
				$reduced_sections[ $bundle ] = array(
					'state' => isset( $section['state'] ) ? (string) $section['state'] : '',
					'partial' => ! empty( $section['partial'] ),
					'failed_checks' => self::bounded_scalar_list( isset( $section['failed_checks'] ) ? $section['failed_checks'] : array(), 8 ),
					'retryable_checks' => self::bounded_scalar_list( isset( $section['retryable_checks'] ) ? $section['retryable_checks'] : array(), 8 ),
					'check_count' => isset( $section['check_count'] ) ? max( 0, (int) $section['check_count'] ) : 0,
					'evidence_digest' => isset( $section['evidence_digest'] ) ? (string) $section['evidence_digest'] : self::digest( $section ),
				);
			}
			$report['sections'] = $reduced_sections;
			$report['payload_reduced'] = true;
			$report['client_action'] = ! empty( $report['partial'] )
				? 'inspect_partial_summary_then_query_one_bundle'
				: 'use_summary_then_query_one_bundle_only_if_needed';
		}
		$encoded = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$bytes = false === $encoded ? self::MAX_SESSION_SAFE_REPORT_BYTES + 1 : strlen( $encoded );
		if ( $bytes > self::MAX_SESSION_SAFE_REPORT_BYTES ) {
			$section_digests = array();
			foreach ( isset( $report['sections'] ) && is_array( $report['sections'] ) ? $report['sections'] : array() as $bundle => $section ) {
				$section_digests[ $bundle ] = array(
					'state' => is_array( $section ) && isset( $section['state'] ) ? (string) $section['state'] : '',
					'partial' => is_array( $section ) && ! empty( $section['partial'] ),
					'evidence_digest' => is_array( $section ) && isset( $section['evidence_digest'] ) ? (string) $section['evidence_digest'] : self::digest( $section ),
				);
			}
			$report['sections'] = $section_digests;
			$report['payload_reduced'] = true;
			$report['client_action'] = 'query_exactly_one_generation_bound_bundle_for_details';
		}
		$report['response_bytes'] = 0;
		for ( $i = 0; $i < 3; $i++ ) {
			$encoded = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$report['response_bytes'] = false === $encoded ? 0 : strlen( $encoded );
		}
		$encoded = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $encoded && strlen( $encoded ) <= self::MAX_SESSION_SAFE_REPORT_BYTES ) return $report;

		$minimal = array(
			'contract' => 'mad4b.session-safe-diagnostics.v1',
			'state' => isset( $report['state'] ) ? (string) $report['state'] : 'partial',
			'partial' => ! empty( $report['partial'] ),
			'read_transaction_id' => isset( $report['read_transaction_id'] ) ? (string) $report['read_transaction_id'] : '',
			'snapshot_id' => isset( $report['snapshot_id'] ) ? (string) $report['snapshot_id'] : '',
			'runtime_generation' => isset( $report['runtime_generation'] ) ? (string) $report['runtime_generation'] : '',
			'generation_match' => ! empty( $report['generation_match'] ),
			// These semantics are safety-critical and must survive payload reduction.
			// The legacy alias alone is ambiguous to older clients.
			'valid_for_merge' => ! empty( $report['valid_for_merge'] ),
			'valid_for_session_evidence_merge' => ! empty( $report['valid_for_session_evidence_merge'] ),
			'valid_for_release_merge' => false,
			'merge_scope' => 'session_safe_subject_evidence_only',
			'deep_acceptance_required' => true,
			'subject_blockers' => isset( $report['subject_blockers'] ) && is_array( $report['subject_blockers'] ) ? array_values( array_slice( $report['subject_blockers'], 0, 8 ) ) : array(),
			'operator_summary' => isset( $report['operator_summary'] ) && is_array( $report['operator_summary'] ) ? $report['operator_summary'] : array(),
			'release_acceptance_deferred_checks' => isset( $report['release_acceptance_deferred_checks'] ) && is_array( $report['release_acceptance_deferred_checks'] ) ? array_values( array_slice( $report['release_acceptance_deferred_checks'], 0, 12 ) ) : array(),
			'performance_observation' => isset( $report['performance_observation'] ) && is_array( $report['performance_observation'] ) ? array(
				'contract' => isset( $report['performance_observation']['contract'] ) ? (string) $report['performance_observation']['contract'] : '',
				'classification' => isset( $report['performance_observation']['classification'] ) ? (string) $report['performance_observation']['classification'] : '',
				'diagnostic_budget_ratio' => isset( $report['performance_observation']['diagnostic_budget_ratio'] ) ? (float) $report['performance_observation']['diagnostic_budget_ratio'] : 0,
				'comparison_required' => ! empty( $report['performance_observation']['comparison_required'] ),
				'comparison_baseline_scope' => isset( $report['performance_observation']['comparison_baseline_scope'] ) ? (string) $report['performance_observation']['comparison_baseline_scope'] : '',
				'client_action' => isset( $report['performance_observation']['client_action'] ) ? (string) $report['performance_observation']['client_action'] : '',
			) : array(),
			'recommended_next_step' => isset( $report['recommended_next_step'] ) && is_array( $report['recommended_next_step'] ) ? array(
				'action' => isset( $report['recommended_next_step']['action'] ) ? (string) $report['recommended_next_step']['action'] : '',
				'ability' => isset( $report['recommended_next_step']['ability'] ) ? (string) $report['recommended_next_step']['ability'] : '',
				'read_only' => ! empty( $report['recommended_next_step']['read_only'] ),
				'explicit_authority_required' => ! empty( $report['recommended_next_step']['explicit_authority_required'] ),
				'automatic_apply_allowed' => false,
			) : array(),
			'section_digests' => array(),
			'session_termination_count' => isset( $report['session_termination_count'] ) ? max( 0, (int) $report['session_termination_count'] ) : 0,
			'payload_reduced' => true,
			'max_response_bytes' => self::MAX_SESSION_SAFE_REPORT_BYTES,
			'client_action' => 'query_exactly_one_generation_bound_bundle_for_details',
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
			'response_bytes' => 0,
		);
		foreach ( isset( $report['sections'] ) && is_array( $report['sections'] ) ? $report['sections'] : array() as $bundle => $section ) {
			$minimal['section_digests'][ sanitize_key( (string) $bundle ) ] = self::digest( $section );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$encoded = wp_json_encode( $minimal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$minimal['response_bytes'] = false === $encoded ? 0 : strlen( $encoded );
		}
		return $minimal;
	}

	private static function bundle_checks( $bundle ) {
		if ( 'identity' === $bundle ) {
			return array(
				'site_profile' => static function () { return self::profile_projection(); },
				'build' => static function () { return self::deep_build_projection(); },
				'connection' => static function () { return self::connection_projection(); },
				'reconnect' => static function () { return self::reconnect_projection(); },
			);
		}
		if ( 'runtime' === $bundle ) {
			return array(
				'write_authority' => static function () { return self::deep_write_authority_projection(); },
				'skills_runtime' => static function () { return self::deep_skills_projection(); },
				'update_state' => static function () { return self::deep_update_projection(); },
			);
		}
		if ( 'certification' === $bundle ) {
			return array(
				'staging_certification' => static function () {
					return class_exists( 'MAD4B_SCP_Staging_Certification' )
						? MAD4B_SCP_Staging_Certification::status( array( 'compact' => true ) )
						: array( 'ready' => false, 'state' => 'unavailable' );
				},
				'managed_skills_reconciliation' => static function () {
					return class_exists( 'MAD4B_SCP_Remote_Operation_Parity' )
						? self::bounded_keys( MAD4B_SCP_Remote_Operation_Parity::managed_skills_reconciliation_status(), array( 'contract', 'supported', 'state', 'ready', 'reconciliation_required', 'blind_retry_allowed', 'next_action', 'read_only', 'mutation_performed' ) )
						: array( 'ready' => false, 'state' => 'unavailable' );
				},
				'browser_acceptance_receipt' => static function () {
					return class_exists( 'MAD4B_SCP_Remote_Operation_Parity' )
						? self::bounded_keys( MAD4B_SCP_Remote_Operation_Parity::browser_acceptance_receipt_status(), array( 'contract', 'ready', 'state', 'candidate_match', 'build_fingerprint_match', 'observed_at', 'blockers', 'read_only', 'mutation_performed' ) )
						: array( 'ready' => false, 'state' => 'unavailable' );
				},
			);
		}
		return array(
			'workflow_providers' => static function () { return self::workflow_provider_projection(); },
			'catalog_inventory' => static function () { return self::deep_catalog_projection(); },
		);
	}

	private static function session_safe_bundle_checks( $bundle ) {
		if ( 'identity' === $bundle ) {
			return array(
				'site_profile' => static function () { return self::profile_projection(); },
				'build' => static function () { return self::build_projection(); },
				'connection' => static function () { return self::session_safe_connection_projection(); },
				'reconnect' => static function () { return self::session_safe_reconnect_projection(); },
			);
		}
		if ( 'runtime' === $bundle ) {
			return array(
				'write_authority' => static function () { return self::session_safe_write_authority_projection(); },
				'adapter_lifecycle' => static function () { return self::adapter_lifecycle_projection(); },
				'database_topology' => static function () {
					return class_exists( 'MAD4B_SCP_Database_Topology' ) && method_exists( 'MAD4B_SCP_Database_Topology', 'status' )
						? MAD4B_SCP_Database_Topology::status( false )
						: array( 'ready' => null, 'state' => 'unavailable', 'blockers' => array( 'database_topology_unavailable' ), 'read_only' => true, 'mutation_performed' => false );
				},
				'mcp_protocol_profile' => static function () {
					return class_exists( 'MAD4B_SCP_MCP_Protocol_Profile' ) && method_exists( 'MAD4B_SCP_MCP_Protocol_Profile', 'status' )
						? MAD4B_SCP_MCP_Protocol_Profile::status()
						: array( 'ready' => null, 'blocker' => 'mcp_protocol_profile_unavailable', 'authorizing' => false );
				},
				'skills_runtime' => static function () { return self::skills_projection(); },
				'update_state' => static function () { return self::update_projection(); },
			);
		}
		if ( 'certification' === $bundle ) {
			return array(
				'deep_certification' => static function () {
					return array(
						'state' => 'deferred_explicit_diagnostic',
						'ready' => null,
						'deferred_checks' => array( 'staging_certification', 'managed_skills_reconciliation', 'browser_acceptance_receipt' ),
						'read_only' => true,
						'mutation_performed' => false,
					);
				},
			);
		}
		return array(
			'catalog_inventory' => static function () { return self::catalog_projection(); },
			'workflow_provider_runtime' => static function () {
				return array(
					'state' => 'deferred_explicit_diagnostic',
					'ready' => null,
					'deferred_checks' => array( 'workflow_provider_runtime_inventory' ),
					'read_only' => true,
					'mutation_performed' => false,
				);
			},
		);
	}

	private static function build_projection() {
		$status = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		if ( is_wp_error( $status ) ) return $status;
		$result = self::bounded_keys( $status, array(
			'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest',
			'artifact_identity', 'mcp_adapter_version', 'identity_ready', 'identity_mismatch', 'observed_at',
		) );
		$result['full_runtime_hash_validation_deferred'] = true;
		return $result;
	}

	private static function deep_build_projection() {
		$status = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest',
			'artifact_identity', 'mcp_adapter_version', 'runtime_manifest_match', 'stale',
			'provenance_mismatch', 'observed_at',
		) );
	}

	private static function profile_projection() {
		$status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return array(
			'configured' => ! empty( $status['configured'] ),
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'revision' => isset( $status['revision'] ) ? (int) $status['revision'] : 0,
			'profile_digest' => isset( $status['profile_digest'] ) ? strtolower( (string) $status['profile_digest'] ) : '',
			'exact_profile_bound' => ! empty( $status['exact_profile_bound'] ) || ( class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ),
			'write_enabled' => ! empty( $status['write_enabled'] ),
			'skills_enabled' => ! empty( $status['skills_enabled'] ),
		);
	}

	private static function candidate_projection() {
		$status = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		if ( is_wp_error( $status ) ) return array( 'required' => false, 'match' => false, 'state' => 'unavailable' );
		return self::bounded_keys( $status, array(
			'contract', 'required', 'stored_bound', 'identity_completeness', 'match',
			'stored_source_commit_sha', 'stored_build_fingerprint', 'stored_package_manifest_digest', 'stored_artifact_identity',
			'current_source_commit_sha', 'current_build_fingerprint', 'current_package_manifest_digest', 'current_artifact_identity',
		) );
	}

	private static function catalog_projection() {
		$registration = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registration['mad4b-chatgpt'] ) && is_array( $registration['mad4b-chatgpt'] ) ? $registration['mad4b-chatgpt'] : array();
		$authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'persisted_status' )
			? MAD4B_SCP_Staging_Write_Authority::persisted_status()
			: array();
		$identity = array(
			'chatgpt_registered' => ! empty( $chatgpt['registered'] ),
			'chatgpt_materialized' => ! empty( $chatgpt['materialized'] ),
			'chatgpt_tool_count' => isset( $chatgpt['tool_count'] ) ? max( 0, (int) $chatgpt['tool_count'] ) : 0,
			'write_tool_count' => isset( $authority['write_tool_count'] ) ? max( 0, (int) $authority['write_tool_count'] ) : 0,
			'write_inventory_fingerprint' => isset( $authority['write_inventory_fingerprint'] ) ? strtolower( (string) $authority['write_inventory_fingerprint'] ) : '',
			'provider_blocked_fingerprint' => isset( $authority['provider_blocked_fingerprint'] ) ? strtolower( (string) $authority['provider_blocked_fingerprint'] ) : '',
			'authority_source_commit_sha' => isset( $authority['source_commit_sha'] ) ? strtolower( (string) $authority['source_commit_sha'] ) : '',
		);
		$identity['provider_inventory_digest'] = self::digest( $identity );
		$identity['runtime_catalog_rebuild_deferred'] = true;
		$identity['raw_sql_breakglass_in_write_inventory'] = false;
		return $identity;
	}

	private static function deep_catalog_projection() {
		$chatgpt = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::chatgpt_tools() : array();
		$write = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::write_tools() : array();
		$chatgpt = array_values( array_unique( array_map( 'strval', is_array( $chatgpt ) ? $chatgpt : array() ) ) );
		$write = array_values( array_unique( array_map( 'strval', is_array( $write ) ? $write : array() ) ) );
		sort( $chatgpt, SORT_STRING );
		sort( $write, SORT_STRING );
		$digest = self::digest( array( 'chatgpt_tools' => $chatgpt, 'write_tools' => $write ) );
		return array(
			'chatgpt_tool_count' => count( $chatgpt ),
			'write_tool_count' => count( $write ),
			'provider_inventory_digest' => $digest,
			'raw_sql_breakglass_in_write_inventory' => in_array( 'mad4b/database-raw-query', $write, true ),
		);
	}

	private static function session_safe_connection_projection() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$registration = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registration['mad4b-chatgpt'] ) && is_array( $registration['mad4b-chatgpt'] ) ? $registration['mad4b-chatgpt'] : array();
		$adapter_available = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		$provider = class_exists( 'MAD4B_SCP_Provider_Contracts' ) && method_exists( 'MAD4B_SCP_Provider_Contracts', 'runtime_identity_status' )
			? MAD4B_SCP_Provider_Contracts::runtime_identity_status( 'mcp_adapter', $adapter_available )
			: array();
		$handshake = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) && method_exists( 'MAD4B_SCP_External_Handshake_Evidence', 'persisted_identity_status' )
			? MAD4B_SCP_External_Handshake_Evidence::persisted_identity_status()
			: array();
		$fact = array(
			'profile_configured' => ! empty( $profile['configured'] ),
			'profile_environment_match' => ! empty( $profile['environment_match'] ),
			'profile_origin_match' => ! empty( $profile['origin_match'] ),
			'adapter_available' => $adapter_available,
			'adapter_identity_ok' => ! empty( $provider['identity_contract_ok'] ),
			'chatgpt_actual_registered' => ! empty( $chatgpt['registered'] ),
		);
		$projection = class_exists( 'MAD4B_SCP_Truth_Projection' ) && method_exists( 'MAD4B_SCP_Truth_Projection', 'session_connection_identity' )
			? MAD4B_SCP_Truth_Projection::session_connection_identity( $fact )
			: array( 'ready' => false, 'state' => 'blocked_identity', 'blockers' => array( 'truth_projection_unavailable' ) );
		return array(
			'contract' => 'mad4b.session-safe-connection-identity.v1',
			'ready' => ! empty( $projection['ready'] ),
			'state' => isset( $projection['state'] ) ? sanitize_key( (string) $projection['state'] ) : 'blocked_identity',
			'environment' => isset( $profile['environment'] ) ? sanitize_key( (string) $profile['environment'] ) : '',
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'mcp_adapter_available' => $adapter_available,
			'mcp_adapter_identity_ok' => ! empty( $provider['identity_contract_ok'] ),
			'chatgpt_registered' => ! empty( $chatgpt['registered'] ),
			'chatgpt_materialized' => ! empty( $chatgpt['materialized'] ),
			'chatgpt_tool_count' => isset( $chatgpt['tool_count'] ) ? max( 0, (int) $chatgpt['tool_count'] ) : 0,
			'external_handshake_evidence_present' => ! empty( $handshake['evidence_present'] ),
			'external_handshake_live_verification_deferred' => true,
			'blockers' => isset( $projection['blockers'] ) && is_array( $projection['blockers'] ) ? $projection['blockers'] : array(),
			'deep_route_validation_deferred' => true,
			'deep_peer_inventory_deferred' => true,
			'deep_oauth_validation_deferred' => true,
			'provider_runtime_hash_validation_deferred' => true,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function session_safe_reconnect_projection() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$registration = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $registration['mad4b-chatgpt'] ) && is_array( $registration['mad4b-chatgpt'] ) ? $registration['mad4b-chatgpt'] : array();
		$recovery = class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ? MAD4B_SCP_Upgrade_Continuity::recovery_status() : array();
		$fact = array(
			'profile_configured' => ! empty( $profile['configured'] ),
			'profile_origin_match' => ! empty( $profile['origin_match'] ),
			'profile_environment_match' => ! empty( $profile['environment_match'] ),
			'chatgpt_actual_registered' => ! empty( $chatgpt['registered'] ),
		);
		$projection = class_exists( 'MAD4B_SCP_Truth_Projection' ) && method_exists( 'MAD4B_SCP_Truth_Projection', 'session_reconnect_identity' )
			? MAD4B_SCP_Truth_Projection::session_reconnect_identity( $fact )
			: array( 'ready' => false, 'state' => 'blocked_identity', 'blockers' => array( 'truth_projection_unavailable' ) );
		return array(
			'contract' => 'mad4b.session-safe-reconnect-identity.v1',
			'ready' => ! empty( $projection['ready'] ),
			'state' => isset( $projection['state'] ) ? sanitize_key( (string) $projection['state'] ) : 'blocked_identity',
			'blockers' => isset( $projection['blockers'] ) && is_array( $projection['blockers'] ) ? $projection['blockers'] : array(),
			'resource' => class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : '',
			'chatgpt_registered' => ! empty( $chatgpt['registered'] ),
			'upgrade_recovery_state' => isset( $recovery['state'] ) ? sanitize_key( (string) $recovery['state'] ) : '',
			'deep_local_oauth_status_deferred' => true,
			'deep_oauth_bridge_status_deferred' => true,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function connection_projection() {
		$status = class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'environment', 'control_plane_version', 'mcp_adapter_version',
			'local_transport_ready', 'local_transport_validation_state', 'local_transport_deep_validation_ready',
			'remote_endpoint_preflight_ready', 'remote_endpoint_preflight_state', 'remote_endpoint_deep_preflight_ready',
			'connection_certified', 'connection_certification_state',
			'local_blockers', 'remote_preflight_blockers', 'certification_blockers', 'certification_deferred_checks',
		) );
	}

	private static function reconnect_projection() {
		$status = class_exists( 'MAD4B_SCP_Reconnect_Hardening' ) ? MAD4B_SCP_Reconnect_Hardening::reconnect_status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'ready', 'blockers', 'resource', 'local_oauth_effective',
			'oauth_resource_bridge_effective', 'chatgpt_registered', 'chatgpt_registration_identity_ready',
			'chatgpt_registration_projection', 'chatgpt_registration_deep_check_deferred', 'chatgpt_error',
			'missed_rest_recovery_state', 'missed_rest_recovery_blocker',
			'write_auto_enabled', 'production_authority_auto_enabled', 'breakglass_auto_enabled',
		) );
	}

	private static function write_authority_projection() {
		$status = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'persisted_status' )
			? MAD4B_SCP_Staging_Write_Authority::persisted_status()
			: array();
		$binding = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();
		if ( is_wp_error( $status ) ) return $status;
		$result = self::bounded_keys( $status, array(
			'contract', 'ready', 'state', 'eligible', 'blocker', 'blockers',
			'write_tool_count', 'write_inventory_fingerprint', 'provider_blocked_fingerprint',
			'wildcard_grants', 'breakglass_included', 'production_auto_enable', 'breakglass_auto_enable',
			'source_commit_sha', 'observed_at',
		) );
		$projection = class_exists( 'MAD4B_SCP_Truth_Projection' ) && method_exists( 'MAD4B_SCP_Truth_Projection', 'candidate_binding_bound_ready' )
			? MAD4B_SCP_Truth_Projection::candidate_binding_bound_ready( $result, is_array( $binding ) ? $binding : array(), 'runtime_authority_candidate_not_reconciled' )
			: array();
		if ( empty( $projection ) ) {
			// Persisted authority is evidence, not current truth. If the canonical
			// reducer is unavailable, preserve that evidence explicitly but never
			// promote it to effective/current readiness.
			$existing_blockers = isset( $result['blockers'] ) && is_array( $result['blockers'] ) ? $result['blockers'] : array();
			$result['persisted_authority_ready'] = ! empty( $result['ready'] );
			$result['candidate_binding_required'] = is_array( $binding ) && ! empty( $binding['required'] );
			$result['candidate_binding_match'] = false;
			$result['effective_authority_ready'] = false;
			$result['ready'] = false;
			$result['state'] = 'truth_projection_unavailable';
			$result['blocker'] = 'truth_projection_unavailable';
			$result['blockers'] = array_values( array_unique( array_merge( $existing_blockers, array( 'truth_projection_unavailable' ) ) ) );
			$result['current_source_commit_sha'] = is_array( $binding ) && isset( $binding['current_source_commit_sha'] ) ? (string) $binding['current_source_commit_sha'] : '';
			$result['candidate_source_commit_sha'] = is_array( $binding ) && isset( $binding['stored_source_commit_sha'] ) ? (string) $binding['stored_source_commit_sha'] : '';
			$result['deep_authority_scan_deferred'] = true;
			return $result;
		}
		$result['persisted_authority_ready'] = ! empty( $projection['persisted_ready'] );
		$result['candidate_binding_required'] = ! empty( $projection['candidate_binding_required'] );
		$result['candidate_binding_match'] = ! empty( $projection['candidate_binding_match'] );
		$result['effective_authority_ready'] = ! empty( $projection['effective_ready'] );
		$result['ready'] = ! empty( $projection['effective_ready'] );
		$result['state'] = isset( $projection['state'] ) ? sanitize_key( (string) $projection['state'] ) : 'blocked';
		$result['blocker'] = isset( $projection['blocker'] ) ? sanitize_key( (string) $projection['blocker'] ) : '';
		$result['blockers'] = isset( $projection['blockers'] ) && is_array( $projection['blockers'] ) ? $projection['blockers'] : array();
		$result['current_source_commit_sha'] = isset( $projection['current_source_commit_sha'] ) ? (string) $projection['current_source_commit_sha'] : '';
		$result['candidate_source_commit_sha'] = isset( $projection['candidate_source_commit_sha'] ) ? (string) $projection['candidate_source_commit_sha'] : '';
		$result['deep_authority_scan_deferred'] = true;
		return $result;
	}

	/**
	 * Session-safe subject truth is stronger than the generic compact preflight:
	 * keep full Live Truth/certification deferred, but perform the single bulk
	 * managed-agent grant snapshot used by mutation authorization. This prevents
	 * persisted checkpoint + candidate binding from being reported as current
	 * subject readiness while broad/stale live grant drift would block mutation.
	 */
	private static function session_safe_write_authority_projection() {
		$result = self::write_authority_projection();
		if ( is_wp_error( $result ) ) return $result;
		$current = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			&& method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'current_execution_readiness' )
			? MAD4B_SCP_Staging_Write_Authority::current_execution_readiness()
			: array();

		$result['current_grant_snapshot_performed'] = true;
		$result['current_grant_snapshot_ready'] = is_array( $current ) && ! empty( $current['current_grant_snapshot_ready'] );
		$result['current_readiness_blockers'] = is_array( $current ) && isset( $current['blockers'] ) && is_array( $current['blockers'] )
			? array_values( array_unique( array_map( 'sanitize_key', $current['blockers'] ) ) )
			: array( 'write_current_readiness_unavailable' );
		$current_ready = is_array( $current ) && ! empty( $current['ready'] );
		$result['effective_authority_ready'] = ! empty( $result['effective_authority_ready'] ) && $current_ready;
		$result['ready'] = (bool) $result['effective_authority_ready'];

		if ( ! $result['ready'] ) {
			$result['state'] = is_array( $current ) && ! empty( $current['state'] )
				? sanitize_key( (string) $current['state'] )
				: 'blocked_current_drift';
			$existing = isset( $result['blockers'] ) && is_array( $result['blockers'] ) ? $result['blockers'] : array();
			$result['blockers'] = array_values( array_unique( array_merge( $existing, $result['current_readiness_blockers'] ) ) );
		}
		// Full Live Truth, write certification and provider canaries remain outside
		// this session-safe request even though the current grant snapshot is live.
		$result['deep_authority_scan_deferred'] = true;
		return $result;
	}

	private static function deep_write_authority_projection() {
		$status = class_exists( 'MAD4B_SCP_Live_Truth' ) && method_exists( 'MAD4B_SCP_Live_Truth', 'current_authority_status' )
			? MAD4B_SCP_Live_Truth::current_authority_status()
			: array(
				'contract' => 'mad4b.deep-write-authority-projection.v1',
				'ready' => false,
				'state' => 'live_truth_unavailable',
				'blocker' => 'live_truth_unavailable',
				'blockers' => array( 'live_truth_unavailable' ),
			);
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'truth_contract', 'ready', 'state', 'eligible', 'blocker', 'blockers',
			'runtime_reconciled', 'candidate_binding_required', 'candidate_binding_match',
			'current_source_commit_sha', 'candidate_source_commit_sha', 'write_tool_count',
			'write_inventory_fingerprint', 'provider_blocked_fingerprint', 'wildcard_grants',
			'breakglass_included', 'production_auto_enable', 'breakglass_auto_enable', 'observed_at',
		) );
	}

	private static function adapter_lifecycle_projection() {
		$expected = array(
			'mad4b/adapters-inventory',
			'media/search',
			'media/get',
			'media/update-metadata',
			'fluentforms/status',
			'fluentforms/list-forms',
			'jetformbuilder/status',
			'litespeed/status',
			'mad4b/content-modeling-context',
			'mad4b/taxonomy-get-term',
			'mad4b/taxonomy-update-term',
			'mad4b/translation-status',
			'mad4b/translation-list-languages',
			'mad4b/translation-set-post-language',
		);
		$missing = array();
		foreach ( $expected as $ability_name ) {
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) $missing[] = $ability_name;
		}
		return array(
			'contract' => 'mad4b.adapter-lifecycle-projection.v1',
			'ready' => empty( $missing ),
			'state' => empty( $missing ) ? 'ready' : 'adapter_ability_lifecycle_incomplete',
			'expected_count' => count( $expected ),
			'registered_count' => count( $expected ) - count( $missing ),
			'missing_abilities' => $missing,
			'registration_only' => true,
			'authority_evaluated' => false,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function skills_projection() {
		$status = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) && method_exists( 'MAD4B_SCP_Skill_Runtime_Certification', 'persisted_status' )
			? MAD4B_SCP_Skill_Runtime_Certification::persisted_status()
			: array( 'ready' => false, 'state' => 'unavailable' );
		if ( is_wp_error( $status ) ) return $status;
		$result = self::bounded_keys( $status, array(
			'contract', 'ready', 'historical_ready', 'state', 'persistence', 'blocker', 'blockers',
			'historical_evidence_only', 'build_identity_current', 'stale_reasons',
			'provider_count', 'expected_provider_count', 'managed_skill_count',
			'expected_managed_skill_count', 'source_commit_sha', 'build_fingerprint',
			'package_manifest_digest', 'artifact_identity', 'observed_at',
		) );
		$current = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) && method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' )
			? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status()
			: array();
		$projection = class_exists( 'MAD4B_SCP_Truth_Projection' ) && method_exists( 'MAD4B_SCP_Truth_Projection', 'candidate_identity_bound_ready' )
			? MAD4B_SCP_Truth_Projection::candidate_identity_bound_ready( $result, is_array( $current ) ? $current : array(), 'skill_runtime_candidate_identity_unproven', 'historical_evidence' )
			: array();
		if ( empty( $projection ) ) {
			$existing_blockers = isset( $result['blockers'] ) && is_array( $result['blockers'] ) ? $result['blockers'] : array();
			$result['recorded_ready'] = array_key_exists( 'historical_ready', $result ) ? ! empty( $result['historical_ready'] ) : ! empty( $result['ready'] );
			$result['recorded_source_commit_sha'] = isset( $result['source_commit_sha'] ) ? (string) $result['source_commit_sha'] : '';
			$result['recorded_build_fingerprint'] = isset( $result['build_fingerprint'] ) ? (string) $result['build_fingerprint'] : '';
			$result['recorded_package_manifest_digest'] = isset( $result['package_manifest_digest'] ) ? (string) $result['package_manifest_digest'] : '';
			$result['recorded_artifact_identity'] = isset( $result['artifact_identity'] ) ? (string) $result['artifact_identity'] : '';
			$result['current_source_commit_sha'] = is_array( $current ) && isset( $current['source_commit_sha'] ) ? (string) $current['source_commit_sha'] : '';
			$result['current_build_fingerprint'] = is_array( $current ) && isset( $current['build_fingerprint'] ) ? (string) $current['build_fingerprint'] : '';
			$result['current_package_manifest_digest'] = is_array( $current ) && isset( $current['package_manifest_digest'] ) ? (string) $current['package_manifest_digest'] : '';
			$result['current_artifact_identity'] = is_array( $current ) && isset( $current['artifact_identity'] ) ? (string) $current['artifact_identity'] : '';
			$result['current_candidate_match'] = false;
			$result['effective_skill_ready'] = false;
			$result['effective_skill_ready_scope'] = 'candidate_identity_bound_checkpoint_only';
			$result['candidate_identity_bound_ready'] = false;
			$result['live_skill_ready'] = null;
			$result['ready'] = null;
			$result['state'] = 'truth_projection_unavailable';
			$result['blockers'] = array_values( array_unique( array_merge( $existing_blockers, array( 'truth_projection_unavailable' ) ) ) );
			$result['live_skill_evaluation_deferred'] = true;
			return $result;
		}
		$result['recorded_ready'] = ! empty( $projection['recorded_ready'] );
		$result['recorded_source_commit_sha'] = isset( $projection['recorded_source_commit_sha'] ) ? (string) $projection['recorded_source_commit_sha'] : '';
		$result['recorded_build_fingerprint'] = isset( $projection['recorded_build_fingerprint'] ) ? (string) $projection['recorded_build_fingerprint'] : '';
		$result['recorded_package_manifest_digest'] = isset( $projection['recorded_package_manifest_digest'] ) ? (string) $projection['recorded_package_manifest_digest'] : '';
		$result['recorded_artifact_identity'] = isset( $projection['recorded_artifact_identity'] ) ? (string) $projection['recorded_artifact_identity'] : '';
		$result['current_source_commit_sha'] = isset( $projection['current_source_commit_sha'] ) ? (string) $projection['current_source_commit_sha'] : '';
		$result['current_build_fingerprint'] = isset( $projection['current_build_fingerprint'] ) ? (string) $projection['current_build_fingerprint'] : '';
		$result['current_package_manifest_digest'] = isset( $projection['current_package_manifest_digest'] ) ? (string) $projection['current_package_manifest_digest'] : '';
		$result['current_artifact_identity'] = isset( $projection['current_artifact_identity'] ) ? (string) $projection['current_artifact_identity'] : '';
		$result['current_candidate_match'] = ! empty( $projection['current_candidate_match'] );
		// Backward-compatible evidence readiness: this proves only that the stored
		// certification was ready for the exact current four-part package identity.
		// It is not a live registry/filesystem/provider certification.
		$result['effective_skill_ready'] = ! empty( $projection['effective_ready'] );
		$result['effective_skill_ready_scope'] = 'candidate_identity_bound_checkpoint_only';
		$result['candidate_identity_bound_ready'] = ! empty( $projection['effective_ready'] );
		$result['live_skill_ready'] = null;
		$result['ready'] = null;
		$result['state'] = ! empty( $projection['effective_ready'] )
			? 'candidate_identity_bound_live_validation_deferred'
			: ( isset( $projection['state'] ) ? sanitize_key( (string) $projection['state'] ) : 'unavailable' );
		$result['blockers'] = isset( $projection['blockers'] ) && is_array( $projection['blockers'] ) ? $projection['blockers'] : array();
		$result['live_skill_evaluation_deferred'] = true;
		return $result;
	}

	private static function deep_skills_projection() {
		$status = class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) && method_exists( 'MAD4B_SCP_Skill_Runtime_Certification', 'current_status' )
			? MAD4B_SCP_Skill_Runtime_Certification::current_status()
			: array( 'ready' => false, 'state' => 'unavailable' );
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'ready', 'state', 'persistence', 'blocker', 'blockers',
			'provider_count', 'expected_provider_count', 'managed_skill_count',
			'expected_managed_skill_count', 'observed_at',
		) );
	}

	private static function update_projection() {
		$status = class_exists( 'MAD4B_SCP_Self_Update' ) && method_exists( 'MAD4B_SCP_Self_Update', 'cached_status' )
			? MAD4B_SCP_Self_Update::cached_status( array() )
			: array();
		if ( is_wp_error( $status ) ) return $status;
		$native = isset( $status['native_wordpress_update'] ) && is_array( $status['native_wordpress_update'] ) ? $status['native_wordpress_update'] : array();
		return array(
			'contract' => isset( $status['contract'] ) ? (string) $status['contract'] : '',
			'current' => isset( $status['current'] ) && is_array( $status['current'] ) ? self::bounded_keys( $status['current'], array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) ) : array(),
			'manifest_state' => isset( $native['manifest_state'] ) ? sanitize_key( (string) $native['manifest_state'] ) : '',
			'manifest_error' => isset( $native['manifest_error'] ) ? sanitize_key( (string) $native['manifest_error'] ) : '',
			'target' => isset( $native['target'] ) && is_array( $native['target'] ) ? self::bounded_keys( $native['target'], array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity', 'sha256' ) ) : array(),
			'native_update_ready' => ! empty( $native['ready'] ),
			'production_remote_upload_allowed' => ! empty( $status['production_remote_upload_allowed'] ),
			'mutation_performed' => false,
		);
	}

	private static function deep_update_projection() {
		$status = class_exists( 'MAD4B_SCP_Self_Update' ) ? MAD4B_SCP_Self_Update::status( array() ) : array();
		if ( is_wp_error( $status ) ) return $status;
		$native = isset( $status['native_wordpress_update'] ) && is_array( $status['native_wordpress_update'] ) ? $status['native_wordpress_update'] : array();
		return array(
			'contract' => isset( $status['contract'] ) ? (string) $status['contract'] : '',
			'current' => isset( $status['current'] ) && is_array( $status['current'] ) ? self::bounded_keys( $status['current'], array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) ) : array(),
			'manifest_state' => isset( $native['manifest_state'] ) ? sanitize_key( (string) $native['manifest_state'] ) : '',
			'manifest_error' => isset( $native['manifest_error'] ) ? sanitize_key( (string) $native['manifest_error'] ) : '',
			'target' => isset( $native['target'] ) && is_array( $native['target'] ) ? self::bounded_keys( $native['target'], array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity', 'sha256' ) ) : array(),
			'native_update_ready' => ! empty( $native['ready'] ),
			'production_remote_upload_allowed' => ! empty( $status['production_remote_upload_allowed'] ),
			'mutation_performed' => false,
		);
	}

	private static function workflow_provider_projection() {
		$status = class_exists( 'MAD4B_SCP_Workflow_Providers' ) ? MAD4B_SCP_Workflow_Providers::status() : array();
		if ( is_wp_error( $status ) ) return $status;
		$providers = isset( $status['providers'] ) && is_array( $status['providers'] ) ? $status['providers'] : array();
		$summary = array();
		foreach ( $providers as $provider_id => $provider ) {
			$provider = is_array( $provider ) ? $provider : array();
			$operations = isset( $provider['operations'] ) && is_array( $provider['operations'] ) ? $provider['operations'] : array();
			$counts = array( 'available' => 0, 'blocked' => 0, 'unavailable' => 0, 'other' => 0 );
			foreach ( $operations as $operation ) {
				$state = is_array( $operation ) && isset( $operation['state'] ) ? sanitize_key( (string) $operation['state'] ) : 'other';
				if ( ! array_key_exists( $state, $counts ) ) $state = 'other';
				$counts[ $state ]++;
			}
			$summary[ sanitize_key( (string) $provider_id ) ] = array(
				'adapter_id' => isset( $provider['adapter_id'] ) ? sanitize_key( (string) $provider['adapter_id'] ) : '',
				'provider_key' => isset( $provider['provider_key'] ) ? sanitize_key( (string) $provider['provider_key'] ) : '',
				'adapter_available' => ! empty( $provider['adapter_available'] ),
				'provider_release_ring' => isset( $provider['provider_release_ring'] ) ? sanitize_key( (string) $provider['provider_release_ring'] ) : '',
				'provider_profile_fingerprint' => isset( $provider['provider_profile_fingerprint'] ) ? strtolower( (string) $provider['provider_profile_fingerprint'] ) : '',
				'capability_certification_fingerprint' => isset( $provider['capability_certification_fingerprint'] ) ? strtolower( (string) $provider['capability_certification_fingerprint'] ) : '',
				'operation_count' => count( $operations ),
				'operation_states' => $counts,
			);
		}
		return array(
			'contract' => isset( $status['contract'] ) ? (string) $status['contract'] : '',
			'default_provider' => isset( $status['default_provider'] ) ? sanitize_key( (string) $status['default_provider'] ) : '',
			'provider_count' => count( $summary ),
			'providers' => $summary,
			'provider_summary_digest' => self::digest( $summary ),
			'mutation_performed' => false,
		);
	}

	private static function ability_schema( $ability, $kind ) {
		if ( ! is_object( $ability ) ) return array();
		$method = 'output' === $kind ? 'get_output_schema' : 'get_input_schema';
		if ( ! method_exists( $ability, $method ) ) return array();
		$value = $ability->{$method}();
		return is_array( $value ) ? $value : array();
	}

	private static function ability_meta( $ability ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) return array();
		$value = $ability->get_meta();
		return is_array( $value ) ? $value : array();
	}

	private static function generation_changed_envelope( $bundle, $transaction_id, $sequence, $expected_generation, array $snapshot, $reason ) {
		$session_report = 'session_safe_diagnostics' === (string) $bundle;
		return array(
			'contract' => self::CONTRACT,
			'state' => 'generation_changed',
			'partial' => true,
			'bundle' => $bundle,
			'read_transaction_id' => $transaction_id,
			'sequence' => $sequence,
			'snapshot_id' => isset( $snapshot['snapshot_id'] ) ? (string) $snapshot['snapshot_id'] : '',
			'expected_runtime_generation' => $expected_generation,
			'runtime_generation' => isset( $snapshot['runtime_generation'] ) ? (string) $snapshot['runtime_generation'] : '',
			'generation_match' => false,
			'valid_for_merge' => false,
			'valid_for_bundle_evidence_merge' => false,
			'valid_for_session_evidence_merge' => false,
			'valid_for_release_merge' => false,
			'merge_scope' => $session_report ? 'session_safe_subject_evidence_only' : 'generation_bound_bundle_evidence_only',
			'deep_acceptance_required' => true,
			'resume_permitted' => false,
			'discard_partial' => true,
			'reason' => sanitize_key( (string) $reason ),
			'checks' => array(),
			'failed_checks' => array(),
			'retryable_checks' => array(),
			'skipped_budget_checks' => array(),
			'projection_freshness' => 'live',
			'observed_at' => gmdate( 'c' ),
			'client_action' => 'restart_read_transaction',
			'read_only' => true,
			'mutation_performed' => false,
			'production_mutation_performed' => false,
		);
	}

	private static function session_safe_next_step( $partial, array $subject_blockers, array $operator_summary = array() ) {
		$subject_blockers = self::bounded_scalar_list( $subject_blockers, 8 );
		if ( $partial ) {
			return array(
				'action' => 'inspect_partial_report_then_retry_missing_scope',
				'ability' => '',
				'read_only' => true,
				'explicit_authority_required' => false,
				'automatic_apply_allowed' => false,
			);
		}

		$operator_actions = isset( $operator_summary['next_actions'] ) && is_array( $operator_summary['next_actions'] )
			? self::bounded_scalar_list( $operator_summary['next_actions'], 8 )
			: array();
		// Dependency order is independent of the order blockers were collected.
		// Never ask for grant convergence while its runtime or writer is blocked.
		$operator_actions = array_values( array_intersect( array(
			'deploy_exact_certified_runtime_release',
			'inspect_database_writer_topology',
			'repair_query_monitor_db_attribution_then_retry',
			'inspect_operational_readiness',
			'repair_adapter_ability_lifecycle_registration',
			'reconcile_exact_staging_write_authority',
			'reconcile_managed_skills',
		), $operator_actions ) );
		foreach ( $operator_actions as $action ) {
			$action = sanitize_key( (string) $action );
			if ( 'repair_adapter_ability_lifecycle_registration' === $action ) {
				return array(
					'action' => 'materialize_governed_read_adapter_lifecycle_then_retry',
					'ability' => 'mad4b/read-execute',
					'target_ability' => 'mad4b/adapters-inventory',
					'why' => 'adapter_ability_lifecycle_is_incomplete_on_the_current_request_generation',
					'read_only' => true,
					'explicit_authority_required' => false,
					'automatic_apply_allowed' => false,
					'persistent_mutation_allowed' => false,
				);
			}
			if ( 'deploy_exact_certified_runtime_release' === $action ) {
				return array(
					'action' => $action,
					'ability' => 'mad4b/control-plane-native-plan',
					'why' => 'mcp_protocol_or_runtime_release_identity_is_not_currently_certified',
					'read_only' => true,
					'explicit_authority_required' => false,
					'automatic_apply_allowed' => false,
				);
			}
			if ( 'inspect_database_writer_topology' === $action || 'repair_query_monitor_db_attribution_then_retry' === $action || 'inspect_operational_readiness' === $action ) {
				return array(
					'action' => 'inspect_operational_readiness' === $action ? $action : 'inspect_database_writer_topology',
					'ability' => 'mad4b/session-safe-diagnostics',
					'why' => 'inspect_exact_operational_blockers_before_selecting_a_bounded_repair',
					'read_only' => true,
					'explicit_authority_required' => false,
					'automatic_apply_allowed' => false,
				);
			}
			if ( 'reconcile_exact_staging_write_authority' === $action ) {
				return array(
					'action' => 'request_staging_write_authority_handshake',
					'ability' => 'mad4b/staging-write-authority-convergence-handshake',
					'why' => 'current_write_authority_or_candidate_binding_requires_reconciliation',
					'read_only' => true,
					'explicit_authority_required' => false,
					'automatic_apply_allowed' => false,
				);
			}
			if ( 'reconcile_managed_skills' === $action ) {
				return array(
					'action' => 'inspect_then_explicitly_reconcile_managed_skills',
					'ability' => 'mad4b/reconcile-managed-skills',
					'why' => 'managed_skills_runtime_not_effective',
					'read_only' => false,
					'explicit_authority_required' => true,
					'automatic_apply_allowed' => false,
				);
			}
		}

		// Backward-compatible fallback for clients that receive a report produced
		// without the richer operator summary.
		if ( in_array( 'write_authority_not_effective', $subject_blockers, true ) ) {
			return array(
				'action' => 'request_staging_write_authority_handshake',
				'ability' => 'mad4b/staging-write-authority-convergence-handshake',
				'why' => 'current_write_authority_or_candidate_binding_requires_reconciliation',
				'read_only' => true,
				'explicit_authority_required' => false,
				'automatic_apply_allowed' => false,
			);
		}
		if ( in_array( 'skills_runtime_not_effective', $subject_blockers, true ) ) {
			return array(
				'action' => 'inspect_then_explicitly_reconcile_managed_skills',
				'ability' => 'mad4b/reconcile-managed-skills',
				'why' => 'managed_skills_runtime_not_effective',
				'read_only' => false,
				'explicit_authority_required' => true,
				'automatic_apply_allowed' => false,
			);
		}
		return array(
			'action' => 'continue_with_single_target_operation',
			'ability' => '',
			'read_only' => true,
			'explicit_authority_required' => false,
			'automatic_apply_allowed' => false,
		);
	}

	private static function performance_observation( array $metrics, $budget_ms, $runtime_generation, $diagnostic_elapsed_ms ) {
		$budget_ms = max( 1, (int) $budget_ms );
		$diagnostic_elapsed_ms = max( 0, (int) $diagnostic_elapsed_ms );
		$request_elapsed_ms = isset( $metrics['request_elapsed_ms'] ) ? max( 0, (int) $metrics['request_elapsed_ms'] ) : 0;
		$headroom_ms = max( 0, $budget_ms - $diagnostic_elapsed_ms );
		$budget_ratio = $budget_ms > 0 ? min( 10, round( $diagnostic_elapsed_ms / $budget_ms, 4 ) ) : 0;
		return array(
			'contract' => 'mad4b.session-safe-performance-observation.v1',
			'classification' => $diagnostic_elapsed_ms > $budget_ms ? 'diagnostic_budget_exceeded' : 'observed_within_diagnostic_budget',
			'diagnostic_budget_ms' => $budget_ms,
			'diagnostic_elapsed_ms' => $diagnostic_elapsed_ms,
			'diagnostic_budget_headroom_ms' => $headroom_ms,
			'diagnostic_budget_ratio' => $budget_ratio,
			'request_elapsed_ms' => $request_elapsed_ms,
			'request_overhead_ms' => max( 0, $request_elapsed_ms - $diagnostic_elapsed_ms ),
			'db_query_count' => isset( $metrics['db_query_count'] ) ? max( 0, (int) $metrics['db_query_count'] ) : 0,
			'included_file_count' => isset( $metrics['included_file_count'] ) ? max( 0, (int) $metrics['included_file_count'] ) : 0,
			'memory_usage_bytes' => isset( $metrics['memory_usage_bytes'] ) ? max( 0, (int) $metrics['memory_usage_bytes'] ) : 0,
			'peak_memory_bytes' => isset( $metrics['peak_memory_bytes'] ) ? max( 0, (int) $metrics['peak_memory_bytes'] ) : 0,
			'runtime_generation' => preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $runtime_generation ) ) ? strtolower( (string) $runtime_generation ) : '',
			'comparison_required' => true,
			'comparison_baseline_scope' => 'previous_exact_staging_release',
			'comparison_metrics' => array( 'request_elapsed_ms', 'db_query_count', 'included_file_count', 'memory_usage_bytes', 'peak_memory_bytes' ),
			'fixed_universal_db_query_threshold_applied' => false,
			'fixed_universal_included_file_threshold_applied' => false,
			'fixed_universal_memory_threshold_applied' => false,
			'regression_policy' => 'compare_exact_release_baseline_then_review_material_regression',
			'client_action' => 'compare_against_previous_exact_staging_release_before_performance_acceptance',
			'authorizing' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function request_metrics() {
		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		return array(
			'request_elapsed_ms' => $elapsed_ms,
			'memory_usage_bytes' => function_exists( 'memory_get_usage' ) ? (int) memory_get_usage( true ) : 0,
			'peak_memory_bytes' => function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : 0,
			'included_file_count' => function_exists( 'get_included_files' ) ? count( get_included_files() ) : 0,
			'db_query_count' => function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0,
			'external_network_calls_started_by_report' => 0,
			'deep_integrity_hashes_started_by_report' => 0,
		);
	}

	private static function transaction_id( $value ) {
		$value = trim( (string) $value );
		if ( 1 === preg_match( '/^rtx_[A-Za-z0-9._-]{8,80}$/', $value ) ) return $value;
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		return 'rtx_' . preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $uuid );
	}

	private static function session_fingerprint() {
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) return '';
		$value = isset( $identity['session_fingerprint'] ) ? strtolower( trim( (string) $identity['session_fingerprint'] ) ) : '';
		return self::is_sha( $value, 64 ) ? $value : '';
	}

	private static function active_plugin_generation() {
		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? array_values( array_unique( array_map( 'strval', $active ) ) ) : array();
		sort( $active, SORT_STRING );
		$network = array();
		if ( is_multisite() ) {
			$network_status = get_site_option( 'active_sitewide_plugins', array() );
			$network = is_array( $network_status ) ? array_keys( $network_status ) : array();
			$network = array_values( array_unique( array_map( 'strval', $network ) ) );
			sort( $network, SORT_STRING );
		}
		return self::digest( array( 'active' => $active, 'network' => $network ) );
	}

	private static function bounded_keys( $status, array $keys ) {
		if ( ! is_array( $status ) ) return array();
		$result = array();
		foreach ( $keys as $key ) if ( array_key_exists( $key, $status ) ) $result[ $key ] = $status[ $key ];
		return $result;
	}

	private static function generation_matches( $expected, $current ) {
		$expected = strtolower( trim( (string) $expected ) );
		$current = strtolower( trim( (string) $current ) );
		return self::is_sha( $expected, 64 ) && self::is_sha( $current, 64 ) && hash_equals( $current, $expected );
	}

	private static function is_sha( $value, $length ) {
		return 1 === preg_match( '/^[a-f0-9]{' . (int) $length . '}$/', strtolower( trim( (string) $value ) ) );
	}

	private static function digest( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function canonicalize( $value ) {
		if ( is_object( $value ) ) $value = get_object_vars( $value );
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
