<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress control/enqueue plane for bounded Host Runner operations.
 *
 * This class never executes host commands. It writes hashed semantic submissions
 * to a dedicated spool after normal MAD4B authority checks.
 */
final class MAD4B_SCP_Host_Bridge {
	const CONTRACT = 'mad4b.host-bridge.v1';
	const PLAN_CONTRACT = 'mad4b.host-operation-plan.v1';
	const SUBMISSION_CONTRACT = 'mad4b.host-bridge-submission.v1';
	const MAX_JSON_BYTES = 131072;

	private static $booted = false;

	private static $operations = array(
		'runtime.status.read' => array( 'version' => 1, 'risk' => 'read_only', 'approval_required' => false ),
		'filesystem.hash.read' => array( 'version' => 1, 'risk' => 'read_only', 'approval_required' => false ),
		'package.integrity.verify' => array( 'version' => 1, 'risk' => 'read_only', 'approval_required' => false ),
		'workspace.file.replace' => array( 'version' => 1, 'risk' => 'reversible_write', 'approval_required' => true ),
		'workspace.file.rollback' => array( 'version' => 1, 'risk' => 'reversible_write', 'approval_required' => true ),
	);

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register( 'mad4b/host-operation-capabilities', 'Host Operation Capabilities', 'capabilities', true );
		self::register( 'mad4b/host-operation-plan', 'Plan Host Operation', 'plan', true );
		self::register( 'mad4b/host-operation-apply', 'Apply Host Operation Plan', 'apply', false );
		self::register( 'mad4b/host-operation-status', 'Host Operation Status', 'status', true );
		self::register( 'mad4b/host-operation-cancel', 'Cancel Queued Host Operation', 'cancel', false );
		self::register( 'mad4b/host-operation-repair-plan', 'Plan Host Operation Repair', 'repair_plan', true );
		self::register( 'mad4b/host-operation-requeue', 'Requeue Host Operation', 'requeue', false );
		self::register( 'mad4b/host-operation-receipt', 'Read Host Operation Receipt', 'receipt', true );
		self::register( 'mad4b/host-doctor', 'Host Runner Doctor', 'doctor', true );
	}

	private static function register( $name, $label, $method, $readonly ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the bounded MAD4B Host Bridge.',
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
						'destructive' => ! $readonly,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	public static function capabilities( $input = array() ) {
		unset( $input );
		$ops = array();
		foreach ( self::$operations as $id => $row ) {
			$ops[] = array(
				'operation_id' => $id,
				'operation_version' => (int) $row['version'],
				'risk' => (string) $row['risk'],
				'approval_required' => (bool) $row['approval_required'],
				'execution_location' => 'host_runner',
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'operations' => $ops,
			'generic_shell_available' => false,
			'raw_sql_available' => false,
			'caller_executable_paths_allowed' => false,
			'caller_command_strings_allowed' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	public static function plan( $input ) {
		$operation_id = isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '';
		if ( ! isset( self::$operations[ $operation_id ] ) ) return new WP_Error( 'mad4b_host_operation_unknown', 'Host operation is not in the bounded registry.' );
		$args = isset( $input['arguments'] ) && is_array( $input['arguments'] ) ? $input['arguments'] : array();
		$profile_id = isset( $input['runner_profile_id'] ) ? sanitize_key( (string) $input['runner_profile_id'] ) : '';
		if ( '' === $profile_id ) return new WP_Error( 'mad4b_host_runner_profile_required', 'Exact Host Runner profile is required.' );
		$target = self::target_identity();
		if ( is_wp_error( $target ) ) return $target;
		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'operation_id' => $operation_id,
			'operation_version' => (int) self::$operations[ $operation_id ]['version'],
			'risk' => (string) self::$operations[ $operation_id ]['risk'],
			'approval_required' => (bool) self::$operations[ $operation_id ]['approval_required'],
			'runner_profile_id' => $profile_id,
			'target' => $target,
			'arguments' => self::canonicalize( $args ),
			'submission_location' => 'wordpress_request',
			'execution_location' => 'host_runner',
			'production_authorized' => false,
			'created_at' => gmdate( 'c' ),
		);
		if ( is_wp_error( $plan['arguments'] ) ) return $plan['arguments'];
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['authorizing'] = false;
		$plan['mutation_performed'] = false;
		return $plan;
	}

	public static function apply( $input ) {
		$plan = isset( $input['plan'] ) && is_array( $input['plan'] ) ? $input['plan'] : array();
		$plan_check = self::validate_plan( $plan );
		if ( is_wp_error( $plan_check ) ) return $plan_check;
		$operation_id = (string) $plan['operation_id'];
		$is_write = 'read_only' !== (string) self::$operations[ $operation_id ]['risk'];
		$approval_ref = isset( $input['approval_ref'] ) ? trim( (string) $input['approval_ref'] ) : '';
		if ( $is_write && '' === $approval_ref ) return new WP_Error( 'mad4b_host_operation_approval_required', 'Reversible Host operation requires an approval reference.' );

		$authority = array(
			'allowed' => true,
			'policy_decision_sha256' => '',
			'agent_public_id' => '',
			'approval_ticket_id' => '',
		);
		if ( $is_write ) {
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_host_authorization_unavailable', 'MAD4B authorization service is unavailable.' );
			$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
			if ( '' === $server_id ) return new WP_Error( 'mad4b_host_server_id_required', 'Exact MCP server id is required for Host write submission.' );
			$auth_input = array(
				'plan_sha256' => (string) $plan['plan_sha256'],
				'operation_id' => $operation_id,
				'runner_profile_id' => (string) $plan['runner_profile_id'],
				'approval_ref' => $approval_ref,
				'_mad4b_approval_ticket_id' => isset( $input['_mad4b_approval_ticket_id'] ) ? (string) $input['_mad4b_approval_ticket_id'] : '',
			);
			$authority = MAD4B_SCP_Authorization::claim_mutation( 'mad4b/host-operation-apply', $server_id, 'core', $auth_input );
			if ( is_wp_error( $authority ) ) return $authority;
		}

		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		$job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : strtolower( wp_generate_uuid4() );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $job_id ) ) return new WP_Error( 'mad4b_host_job_id_invalid', 'Host job id is invalid.' );
		$idempotency_key = isset( $input['idempotency_key'] ) ? trim( (string) $input['idempotency_key'] ) : '';
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_host_idempotency_invalid', 'Host idempotency key is required.' );

		$submission = array(
			'contract' => self::SUBMISSION_CONTRACT,
			'job_id' => $job_id,
			'idempotency_key' => $idempotency_key,
			'plan' => $plan,
			'plan_sha256' => (string) $plan['plan_sha256'],
			'approval_ref' => $approval_ref,
			'authority' => array(
				'policy_decision_sha256' => isset( $authority['policy_decision_sha256'] ) ? (string) $authority['policy_decision_sha256'] : '',
				'agent_public_id' => isset( $authority['agent_public_id'] ) ? (string) $authority['agent_public_id'] : '',
				'approval_ticket_id' => isset( $authority['approval_ticket_id'] ) ? (string) $authority['approval_ticket_id'] : '',
			),
			'submission_location' => 'wordpress_request',
			'execution_location' => 'host_runner',
			'created_at' => isset( $plan['created_at'] ) ? (string) $plan['created_at'] : '',
			'production_authorized' => false,
		);
		$submission['submission_sha256'] = self::digest( $submission );
		$path = $spool . '/queued/' . $job_id . '.json';
		if ( file_exists( $path ) ) {
			$existing = self::read_json( $path );
			if ( is_wp_error( $existing ) ) return $existing;
			if ( ! hash_equals( (string) $existing['submission_sha256'], (string) $submission['submission_sha256'] ) ) {
				return new WP_Error( 'mad4b_host_job_replay_conflict', 'Host job id already exists with different semantic submission.' );
			}
			$existing['replayed'] = true;
			return $existing;
		}
		$write = self::atomic_write( $path, $submission );
		if ( is_wp_error( $write ) ) return $write;
		$submission['queued'] = true;
		$submission['replayed'] = false;
		$submission['mutation_performed'] = true;
		return $submission;
	}

	public static function status( $input ) {
		$job_id = self::job_id_from_input( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		foreach ( array( 'receipts' => 'completed', 'recovery-required' => 'recovery_required', 'dead-letter' => 'dead_lettered', 'running' => 'running', 'queued' => 'queued', 'cancelled' => 'cancelled' ) as $dir => $state ) {
			$path = $spool . '/' . $dir . '/' . $job_id . '.json';
			if ( is_file( $path ) ) {
				return array(
					'contract' => 'mad4b.host-operation-status.v1',
					'job_id' => $job_id,
					'state' => $state,
					'evidence' => self::read_json( $path ),
					'mutation_performed' => false,
				);
			}
		}
		return new WP_Error( 'mad4b_host_job_missing', 'Host job was not found in the bounded spool.' );
	}

	public static function receipt( $input ) {
		$job_id = self::job_id_from_input( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		$path = $spool . '/receipts/' . $job_id . '.json';
		if ( ! is_file( $path ) ) return new WP_Error( 'mad4b_host_receipt_missing', 'Host execution receipt is not available.' );
		$row = self::read_json( $path );
		if ( is_wp_error( $row ) ) return $row;
		return array( 'contract' => 'mad4b.host-operation-receipt-read.v1', 'receipt' => $row, 'mutation_performed' => false );
	}

	public static function cancel( $input ) {
		$job_id = self::job_id_from_input( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		$queued = $spool . '/queued/' . $job_id . '.json';
		if ( ! is_file( $queued ) ) return new WP_Error( 'mad4b_host_cancel_not_queued', 'Only queued host submissions can be cancelled by the WordPress bridge.' );
		$row = self::read_json( $queued );
		if ( is_wp_error( $row ) ) return $row;
		$cancelled = $spool . '/cancelled/' . $job_id . '.json';
		$mark = array(
			'contract' => 'mad4b.host-operation-cancel.v1',
			'job_id' => $job_id,
			'submission_sha256' => isset( $row['submission_sha256'] ) ? (string) $row['submission_sha256'] : '',
			'requested_at' => gmdate( 'c' ),
			'cooperative_only' => true,
			'external_side_effect_stopped' => false,
			'mutation_performed' => true,
		);
		$write = self::atomic_write( $cancelled, $mark );
		if ( is_wp_error( $write ) ) return $write;
		@unlink( $queued );
		return $mark;
	}

	public static function repair_plan( $input ) {
		$job_id = self::job_id_from_input( $input );
		if ( is_wp_error( $job_id ) ) return $job_id;
		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		$dead = $spool . '/dead-letter/' . $job_id . '.json';
		$recovery = $spool . '/recovery-required/' . $job_id . '.json';
		if ( is_file( $recovery ) ) {
			$incident = self::read_json( $recovery );
			$repair = array(
				'contract' => 'mad4b.host-operation-repair-plan.v1',
				'source_job_id' => $job_id,
				'incident_state' => 'RECOVERY_REQUIRED',
				'incident_sha256' => is_wp_error( $incident ) ? '' : self::digest( $incident ),
				'requeue_allowed' => false,
				'reconciliation_required' => true,
				'blind_retry_allowed' => false,
				'fresh_job_id_required' => true,
				'fresh_idempotency_required' => true,
				'fresh_authorization_required' => true,
				'mutation_performed' => false,
			);
			$repair['repair_plan_sha256'] = self::digest( $repair );
			return $repair;
		}
		if ( ! is_file( $dead ) ) return new WP_Error( 'mad4b_host_incident_missing', 'No dead-letter/recovery incident exists for this Host job.' );
		$incident = self::read_json( $dead );
		if ( is_wp_error( $incident ) ) return $incident;
		$replacement_plan = isset( $input['replacement_plan'] ) && is_array( $input['replacement_plan'] ) ? $input['replacement_plan'] : array();
		$valid = self::validate_plan( $replacement_plan );
		if ( is_wp_error( $valid ) ) return $valid;
		$repair = array(
			'contract' => 'mad4b.host-operation-repair-plan.v1',
			'source_job_id' => $job_id,
			'incident_state' => 'DEAD_LETTERED',
			'incident_sha256' => self::digest( $incident ),
			'replacement_plan' => $replacement_plan,
			'replacement_plan_sha256' => (string) $replacement_plan['plan_sha256'],
			'requeue_allowed' => true,
			'reconciliation_required' => false,
			'blind_retry_allowed' => false,
			'fresh_job_id_required' => true,
			'fresh_idempotency_required' => true,
			'fresh_authorization_required' => true,
			'mutation_performed' => false,
		);
		$repair['repair_plan_sha256'] = self::digest( $repair );
		return $repair;
	}

	public static function requeue( $input ) {
		$repair = isset( $input['repair_plan'] ) && is_array( $input['repair_plan'] ) ? $input['repair_plan'] : array();
		if ( 'mad4b.host-operation-repair-plan.v1' !== (string) ( $repair['contract'] ?? '' ) ) return new WP_Error( 'mad4b_host_repair_plan_invalid', 'Host repair plan contract is invalid.' );
		$repair_sha = isset( $repair['repair_plan_sha256'] ) ? strtolower( trim( (string) $repair['repair_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $repair_sha ) || ! hash_equals( $repair_sha, self::digest( $repair ) ) ) return new WP_Error( 'mad4b_host_repair_plan_digest_invalid', 'Host repair plan digest mismatch.' );
		if ( empty( $repair['requeue_allowed'] ) || ! empty( $repair['reconciliation_required'] ) ) return new WP_Error( 'mad4b_host_requeue_reconciliation_required', 'Recovery-required Host incidents cannot be requeued before reconciliation.' );
		$source_job_id = isset( $repair['source_job_id'] ) ? strtolower( trim( (string) $repair['source_job_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $source_job_id ) ) return new WP_Error( 'mad4b_host_job_id_invalid', 'Source Host job id is invalid.' );
		$spool = self::spool_root();
		if ( is_wp_error( $spool ) ) return $spool;
		$incident_path = $spool . '/dead-letter/' . $source_job_id . '.json';
		if ( ! is_file( $incident_path ) ) return new WP_Error( 'mad4b_host_incident_missing', 'Source dead-letter incident is unavailable.' );
		$incident = self::read_json( $incident_path );
		if ( is_wp_error( $incident ) ) return $incident;
		if ( ! hash_equals( (string) $repair['incident_sha256'], self::digest( $incident ) ) ) return new WP_Error( 'mad4b_host_incident_changed', 'Source Host incident changed since repair planning.' );

		$new_job_id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $new_job_id ) || hash_equals( $new_job_id, $source_job_id ) ) return new WP_Error( 'mad4b_host_requeue_job_id_invalid', 'Requeue requires a fresh Host job id.' );
		$idempotency_key = isset( $input['idempotency_key'] ) ? trim( (string) $input['idempotency_key'] ) : '';
		if ( '' === $idempotency_key || strlen( $idempotency_key ) > 191 ) return new WP_Error( 'mad4b_host_idempotency_invalid', 'Requeue requires a fresh idempotency key.' );

		$apply_input = array(
			'plan' => $repair['replacement_plan'],
			'job_id' => $new_job_id,
			'idempotency_key' => $idempotency_key,
			'approval_ref' => isset( $input['approval_ref'] ) ? (string) $input['approval_ref'] : '',
			'server_id' => isset( $input['server_id'] ) ? (string) $input['server_id'] : '',
			'_mad4b_approval_ticket_id' => isset( $input['_mad4b_approval_ticket_id'] ) ? (string) $input['_mad4b_approval_ticket_id'] : '',
		);
		$result = self::apply( $apply_input );
		if ( is_wp_error( $result ) ) return $result;
		$result['repair_plan_sha256'] = $repair_sha;
		$result['requeued_from_job_id'] = $source_job_id;
		$result['blind_retry'] = false;
		return $result;
	}

	public static function doctor( $input = array() ) {
		unset( $input );
		$spool = self::spool_root( false );
		if ( is_wp_error( $spool ) ) return $spool;
		$counts = array();
		foreach ( array( 'queued', 'running', 'receipts', 'cancelled', 'dead-letter', 'recovery-required' ) as $dir ) {
			$path = $spool . '/' . $dir;
			$counts[ $dir ] = is_dir( $path ) ? count( glob( $path . '/*.json' ) ?: array() ) : 0;
		}
		return array(
			'contract' => 'mad4b.host-bridge-doctor.v1',
			'spool_root' => $spool,
			'counts' => $counts,
			'operator_review_required' => ( $counts['dead-letter'] + $counts['recovery-required'] ) > 0,
			'generic_shell_available' => false,
			'production_authorized' => false,
			'mutation_performed' => false,
		);
	}

	private static function validate_plan( array $plan ) {
		if ( self::PLAN_CONTRACT !== (string) ( $plan['contract'] ?? '' ) ) return new WP_Error( 'mad4b_host_plan_contract_invalid', 'Host plan contract is invalid.' );
		$operation_id = isset( $plan['operation_id'] ) ? (string) $plan['operation_id'] : '';
		if ( ! isset( self::$operations[ $operation_id ] ) ) return new WP_Error( 'mad4b_host_operation_unknown', 'Host operation is not in the bounded registry.' );
		if ( (int) $plan['operation_version'] !== (int) self::$operations[ $operation_id ]['version'] ) return new WP_Error( 'mad4b_host_operation_version_stale', 'Host operation version changed.' );
		$sha = isset( $plan['plan_sha256'] ) ? strtolower( trim( (string) $plan['plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || ! hash_equals( $sha, self::digest( $plan ) ) ) return new WP_Error( 'mad4b_host_plan_digest_invalid', 'Host plan digest mismatch.' );
		$current = self::target_identity();
		if ( is_wp_error( $current ) ) return $current;
		if ( ! isset( $plan['target']['target_fingerprint'] ) || ! hash_equals( (string) $current['target_fingerprint'], (string) $plan['target']['target_fingerprint'] ) ) {
			return new WP_Error( 'mad4b_host_plan_target_stale', 'Host target changed since plan.' );
		}
		if ( 'host_runner' !== (string) ( $plan['execution_location'] ?? '' ) ) return new WP_Error( 'mad4b_host_execution_location_invalid', 'Host plan execution location is not Host Runner.' );
		return true;
	}

	private static function target_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return new WP_Error( 'mad4b_host_site_profile_unavailable', 'Site Profile is unavailable.' );
		$site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
		if ( 1 !== preg_match( '/^[a-f0-9-]{36}$/', $site_uuid ) ) return new WP_Error( 'mad4b_host_site_identity_invalid', 'Site identity is invalid.' );
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$root = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		if ( false === $root ) return new WP_Error( 'mad4b_host_wordpress_root_unavailable', 'WordPress root is unavailable.' );
		$wp_config = $root . DIRECTORY_SEPARATOR . 'wp-config.php';
		if ( is_link( $wp_config ) || ! is_file( $wp_config ) ) return new WP_Error( 'mad4b_host_wp_config_unavailable', 'WordPress configuration identity is unavailable.' );
		$wp_config_sha256 = hash_file( 'sha256', $wp_config );
		if ( ! is_string( $wp_config_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $wp_config_sha256 ) ) {
			return new WP_Error( 'mad4b_host_wp_config_identity_invalid', 'WordPress configuration identity could not be resolved.' );
		}
		$payload = array(
			'site_uuid' => $site_uuid,
			'environment' => $environment,
			'wordpress_root' => $root,
			'wp_config_sha256' => $wp_config_sha256,
		);
		$payload['target_fingerprint'] = self::digest( $payload );
		return $payload;
	}

	private static function spool_root( $create = true ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) return new WP_Error( 'mad4b_host_spool_root_unavailable', 'WordPress content root is unavailable.' );
		$base = realpath( WP_CONTENT_DIR );
		if ( false === $base ) return new WP_Error( 'mad4b_host_spool_root_unavailable', 'WordPress content root is unavailable.' );
		$root = $base . '/mad4b-runner/bridge';
		if ( is_link( $root ) ) return new WP_Error( 'mad4b_host_spool_symlink_forbidden', 'Host bridge spool root cannot be a symlink.' );
		if ( $create ) {
			foreach ( array( '', '/queued', '/running', '/receipts', '/cancelled', '/dead-letter', '/recovery-required' ) as $suffix ) {
				$dir = $root . $suffix;
				if ( is_link( $dir ) ) return new WP_Error( 'mad4b_host_spool_symlink_forbidden', 'Host bridge spool directory cannot be a symlink.' );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'mad4b_host_spool_create_failed', 'Unable to prepare Host bridge spool.' );
			}
		}
		return $root;
	}

	private static function job_id_from_input( $input ) {
		$id = isset( $input['job_id'] ) ? strtolower( trim( (string) $input['job_id'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9-]{36}$/', $id ) ? $id : new WP_Error( 'mad4b_host_job_id_invalid', 'Host job id is invalid.' );
	}

	private static function atomic_write( $path, array $value ) {
		$raw = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $raw || strlen( $raw ) > self::MAX_JSON_BYTES ) return new WP_Error( 'mad4b_host_spool_payload_invalid', 'Host spool payload is invalid or too large.' );
		$tmp = $path . '.tmp-' . wp_generate_uuid4();
		if ( false === file_put_contents( $tmp, $raw . "\n", LOCK_EX ) ) return new WP_Error( 'mad4b_host_spool_write_failed', 'Unable to persist Host spool payload.' );
		if ( ! rename( $tmp, $path ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_host_spool_commit_failed', 'Unable to atomically commit Host spool payload.' ); }
		return true;
	}

	private static function read_json( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) ) return new WP_Error( 'mad4b_host_spool_entry_invalid', 'Host spool entry is unavailable.' );
		$raw = file_get_contents( $path );
		if ( false === $raw || strlen( $raw ) > self::MAX_JSON_BYTES ) return new WP_Error( 'mad4b_host_spool_entry_invalid', 'Host spool entry is invalid.' );
		$row = json_decode( $raw, true );
		return is_array( $row ) ? $row : new WP_Error( 'mad4b_host_spool_entry_invalid', 'Host spool entry is not valid JSON.' );
	}

	private static function digest( array $value ) {
		unset( $value['plan_sha256'], $value['repair_plan_sha256'], $value['submission_sha256'], $value['authorizing'], $value['mutation_performed'], $value['queued'], $value['replayed'] );
		$value = self::canonicalize( $value );
		if ( is_wp_error( $value ) ) return '';
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::canonicalize( $item );
				if ( is_wp_error( $value[ $key ] ) ) return $value[ $key ];
			}
			return $value;
		}
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) || null === $value ) return $value;
		if ( is_float( $value ) && is_finite( $value ) ) return $value;
		return new WP_Error( 'mad4b_host_value_invalid', 'Host operation input contains unsupported value type.' );
	}
}

MAD4B_SCP_Host_Bridge::boot();
