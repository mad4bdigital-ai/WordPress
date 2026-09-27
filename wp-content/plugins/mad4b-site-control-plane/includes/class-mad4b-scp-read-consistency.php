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
	const DEFAULT_SNAPSHOT_TTL_SECONDS = 120;
	const DEFAULT_BUNDLE_BUDGET_MS = 8000;
	const MAX_BUNDLE_BUDGET_MS = 12000;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 26 );
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
			'generic_batch_executor_exposed' => in_array( 'mad4b/execute-many', $chatgpt, true ) || in_array( 'mad4b/execute-many', $write, true ),
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
