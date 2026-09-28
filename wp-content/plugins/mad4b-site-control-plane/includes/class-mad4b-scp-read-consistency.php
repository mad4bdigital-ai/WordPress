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
		$result['valid_for_merge'] = true;
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
					'check_count' => count( self::bundle_checks( $bundle ) ),
					'evidence_digest' => self::digest( array( 'bundle' => $bundle, 'state' => 'skipped_budget', 'generation' => $runtime_generation ) ),
				);
				continue;
			}

			$result = MAD4B_SCP_Connector_Resilience::run_checks(
				self::bundle_checks( $bundle ),
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
						'check_count' => count( self::bundle_checks( $remaining_bundle ) ),
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

		$report = array(
			'contract' => 'mad4b.session-safe-diagnostics.v1',
			'state' => $partial ? 'partial' : 'ready',
			'partial' => $partial,
			'read_transaction_id' => $transaction_id,
			'snapshot_id' => isset( $after['snapshot_id'] ) ? (string) $after['snapshot_id'] : '',
			'runtime_generation' => $runtime_generation,
			'generation_match' => true,
			'valid_for_merge' => true,
			'projection_freshness' => 'live',
			'observed_at' => gmdate( 'c' ),
			'elapsed_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'budget_ms' => $budget_ms,
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

	private static function compact_bundle_result( $bundle, array $result ) {
		$checks = array();
		foreach ( isset( $result['checks'] ) && is_array( $result['checks'] ) ? $result['checks'] : array() as $name => $check ) {
			$check = is_array( $check ) ? $check : array();
			$checks[ sanitize_key( (string) $name ) ] = array(
				'ok' => ! empty( $check['ok'] ),
				'state' => isset( $check['state'] ) ? sanitize_key( (string) $check['state'] ) : '',
				'category' => isset( $check['category'] ) ? sanitize_key( (string) $check['category'] ) : '',
				'elapsed_ms' => isset( $check['elapsed_ms'] ) ? max( 0, (int) $check['elapsed_ms'] ) : 0,
				'summary' => self::compact_status_data( $name, isset( $check['data'] ) ? $check['data'] : array() ),
				'evidence_digest' => self::digest( isset( $check['data'] ) ? $check['data'] : array() ),
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
			'profile_digest', 'exact_profile_bound', 'write_enabled', 'skills_enabled',
			'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest',
			'artifact_identity', 'mcp_adapter_version', 'runtime_manifest_match', 'stale',
			'local_transport_ready', 'remote_endpoint_preflight_ready', 'connection_certified',
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
			'seo_publication_authorized', 'production_activation_authorized', 'observed_at'
		);
		$out = array();
		foreach ( $scalar_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) continue;
			$value = $data[ $key ];
			if ( is_scalar( $value ) || null === $value ) $out[ $key ] = $value;
		}
		foreach ( array( 'blockers', 'local_blockers', 'remote_preflight_blockers', 'certification_blockers', 'blocking_gates', 'provenance_mismatch' ) as $key ) {
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
		$encoded = wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$report['response_bytes'] = false === $encoded ? 0 : strlen( $encoded );
		return $report;
	}

	private static function bundle_checks( $bundle ) {
		if ( 'identity' === $bundle ) {
			return array(
				'site_profile' => static function () { return self::profile_projection(); },
				'build' => static function () { return self::build_projection(); },
				'connection' => static function () { return self::connection_projection(); },
				'reconnect' => static function () { return self::reconnect_projection(); },
			);
		}
		if ( 'runtime' === $bundle ) {
			return array(
				'write_authority' => static function () { return self::write_authority_projection(); },
				'skills_runtime' => static function () { return self::skills_projection(); },
				'update_state' => static function () { return self::update_projection(); },
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
			'catalog_inventory' => static function () { return self::catalog_projection(); },
		);
	}

	private static function build_projection() {
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

	private static function connection_projection() {
		$status = class_exists( 'MAD4B_SCP_Connection_Status' ) ? MAD4B_SCP_Connection_Status::status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'environment', 'control_plane_version', 'mcp_adapter_version',
			'local_transport_ready', 'remote_endpoint_preflight_ready', 'connection_certified',
			'local_blockers', 'remote_preflight_blockers', 'certification_blockers',
		) );
	}

	private static function reconnect_projection() {
		$status = class_exists( 'MAD4B_SCP_Reconnect_Hardening' ) ? MAD4B_SCP_Reconnect_Hardening::reconnect_status() : array();
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'ready', 'blockers', 'resource', 'local_oauth_effective',
			'oauth_resource_bridge_effective', 'chatgpt_registered', 'chatgpt_error',
			'missed_rest_recovery_state', 'missed_rest_recovery_blocker',
			'write_auto_enabled', 'production_authority_auto_enabled', 'breakglass_auto_enabled',
		) );
	}

	private static function write_authority_projection() {
		$status = class_exists( 'MAD4B_SCP_Live_Truth' ) && method_exists( 'MAD4B_SCP_Live_Truth', 'current_authority_status' )
			? MAD4B_SCP_Live_Truth::current_authority_status()
			: ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::status() : array() );
		if ( is_wp_error( $status ) ) return $status;
		return self::bounded_keys( $status, array(
			'contract', 'truth_contract', 'ready', 'state', 'eligible', 'blocker', 'blockers',
			'runtime_reconciled', 'candidate_binding_required', 'candidate_binding_match',
			'current_source_commit_sha', 'candidate_source_commit_sha', 'write_tool_count',
			'write_inventory_fingerprint', 'provider_blocked_fingerprint', 'wildcard_grants',
			'breakglass_included', 'production_auto_enable', 'breakglass_auto_enable', 'observed_at',
		) );
	}

	private static function skills_projection() {
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
