<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Database-backed authority for exceptional runtime gates.
 *
 * This replaces deployment-constant enablement as the authoritative source for
 * generic Raw-SQL Breakglass and Production mutation policy. Mutating the gate
 * record is exact-plan-bound, audited, profile-bound and requires the dedicated
 * ChatGPT authority step-up scope. The record is intentionally site-local: a
 * Staging database can configure only Staging Raw-SQL Breakglass; Production
 * mutation flags can be enabled only while executing on the exact enrolled
 * Production origin.
 */
final class MAD4B_SCP_Governed_Runtime_Gates {
	const CONTRACT = 'mad4b.governed-runtime-gates.v1';
	const OPTION = 'mad4b_scp_governed_runtime_gates_v1';
	const VERSION = 1;

	const STATUS_ABILITY = 'mad4b/runtime-gates-status';
	const PLAN_ABILITY = 'mad4b/runtime-gates-plan';
	const HANDSHAKE_ABILITY = 'mad4b/runtime-gates-handshake';
	const APPLY_ABILITY = 'mad4b/runtime-gates-apply';

	const CONFIRM_ENABLE = 'ENABLE GOVERNED HIGH RISK RUNTIME GATES';
	const CONFIRM_UPDATE = 'UPDATE GOVERNED RUNTIME GATES';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 17 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 17 );
	}

	public static function bootstrap_runtime() {
		if ( 'production' !== self::environment() ) return;
		if ( ! self::production_auto_enable() ) return;
		if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
	}

	public static function enrollment_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::HANDSHAKE_ABILITY, self::APPLY_ABILITY );
	}

	public static function chatgpt_read_tools() {
		return array( self::HANDSHAKE_ABILITY );
	}

	public static function chatgpt_catalog_read_tools() {
		return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::HANDSHAKE_ABILITY );
	}

	public static function chatgpt_step_up_tools() {
		if ( ! self::exact_profile_bound() ) return array();
		if ( ! in_array( self::environment(), array( 'staging', 'production' ), true ) ) return array();
		return array( self::APPLY_ABILITY );
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) return;
		wp_register_ability_category( 'mad4b-runtime-gates', array(
			'label' => 'MAD4B Governed Runtime Gates',
			'description' => 'Database-backed, plan-bound exceptional runtime authority gates.',
		) );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
		$priority = function_exists( 'has_filter' ) ? has_filter( 'wp_register_ability_args', $augment ) : false;
		if ( false !== $priority ) remove_filter( 'wp_register_ability_args', $augment, (int) $priority );

		try {
			self::register_ability( self::STATUS_ABILITY, 'Governed Runtime Gates Status', 'status', true, self::schema( array() ) );
			self::register_ability( self::PLAN_ABILITY, 'Plan Governed Runtime Gates', 'plan', true, self::desired_schema() );
			self::register_ability( self::HANDSHAKE_ABILITY, 'Governed Runtime Gates Handshake', 'handshake', true, self::desired_schema() );
			self::register_ability( self::APPLY_ABILITY, 'Apply Governed Runtime Gates', 'apply', false, self::apply_schema() );
		} finally {
			if ( false !== $priority ) add_filter( 'wp_register_ability_args', $augment, (int) $priority, 2 );
		}
	}

	private static function register_ability( $name, $label, $method, $readonly, array $input_schema ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $readonly
				? 'Read or plan the exact database-backed exceptional runtime gate policy.'
				: 'Persist one exact audited runtime gate policy after explicit high-risk authority confirmation.',
			'category' => 'mad4b-runtime-gates',
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => array( __CLASS__, $readonly ? 'can_access' : 'can_apply' ),
			'input_schema' => $input_schema,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => self::meta( $readonly ),
		) );
	}

	private static function schema( array $properties, array $required = array() ) {
		$out = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $out['required'] = $required;
		return $out;
	}

	private static function desired_schema() {
		return self::schema( array(
			'raw_sql_breakglass_enabled' => array( 'type' => 'boolean' ),
			'raw_sql_write_enabled' => array( 'type' => 'boolean' ),
			'raw_sql_ddl_enabled' => array( 'type' => 'boolean' ),
			'production_mutation_enabled' => array( 'type' => 'boolean' ),
			'production_auto_enable' => array( 'type' => 'boolean' ),
		) );
	}

	private static function apply_schema() {
		$properties = self::desired_schema()['properties'];
		$properties['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$properties['expected_policy_revision'] = array( 'type' => 'integer', 'minimum' => 0 );
		$properties['expected_policy_digest'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$properties['expected_site_uuid'] = array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 );
		$properties['expected_profile_revision'] = array( 'type' => 'integer', 'minimum' => 1 );
		$properties['expected_profile_digest'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$properties['expected_source_commit_sha'] = array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' );
		$properties['expected_build_fingerprint'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$properties['confirmation'] = array( 'type' => 'string', 'enum' => array( self::CONFIRM_ENABLE, self::CONFIRM_UPDATE ) );
		return self::schema(
			$properties,
			array(
				'raw_sql_breakglass_enabled', 'raw_sql_write_enabled', 'raw_sql_ddl_enabled',
				'production_mutation_enabled', 'production_auto_enable',
				'expected_plan_sha256', 'expected_policy_revision', 'expected_policy_digest',
				'expected_site_uuid', 'expected_profile_revision', 'expected_profile_digest',
				'expected_source_commit_sha', 'expected_build_fingerprint', 'confirmation',
			)
		);
	}

	private static function meta( $readonly ) {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array(
				'public' => false,
				'type' => 'tool',
				'surface' => $readonly ? 'read' : 'enrollment',
				'mad4b_governed_runtime_gates' => self::CONTRACT,
				'generic_remote_admin' => false,
				'production_allowed' => true,
				'generic_raw_sql_breakglass_configuration' => true,
			),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => ! $readonly,
				'idempotent' => (bool) $readonly,
			),
		);
	}

	public static function can_access( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_runtime_gates_admin_required', 'Administrator capability is required.' );
		if ( ! self::exact_profile_bound() ) return new WP_Error( 'mad4b_runtime_gates_profile_required', 'An exact enrolled Site Profile is required.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_runtime_gates_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_runtime_gates_bearer_required', 'Verified OAuth bearer identity is required.' );
		return true;
	}

	public static function can_apply( $input = null ) {
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) return new WP_Error( 'mad4b_runtime_gates_step_up_required', 'Dedicated authority step-up scope is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) return new WP_Error( 'mad4b_runtime_gates_chatgpt_client_required', 'Runtime gate mutation requires the exact ChatGPT CIMD client.' );
		return true;
	}

	public static function raw_sql_breakglass_enabled() {
		$status = self::effective_status();
		return ! empty( $status['raw_sql_breakglass_enabled'] );
	}

	public static function raw_sql_write_enabled() {
		$status = self::effective_status();
		return ! empty( $status['raw_sql_write_enabled'] );
	}

	public static function raw_sql_ddl_enabled() {
		$status = self::effective_status();
		return ! empty( $status['raw_sql_ddl_enabled'] );
	}

	public static function production_mutation_enabled() {
		$status = self::effective_status();
		return ! empty( $status['production_mutation_enabled'] );
	}

	public static function production_auto_enable() {
		$status = self::effective_status();
		return ! empty( $status['production_auto_enable'] );
	}

	public static function status( $input = null ) {
		$record = self::record();
		$effective = self::effective_status( $record );
		return array_merge( $record, $effective, array(
			'contract' => self::CONTRACT,
			'policy_digest' => self::record_digest( $record ),
			'environment' => self::environment(),
			'current_site_uuid' => self::site_uuid(),
			'current_profile_revision' => self::profile_revision(),
			'current_profile_digest' => self::profile_digest(),
			'database_authoritative' => true,
			'legacy_constant_authoritative' => false,
			'read_only' => true,
			'mutation_performed' => false,
		) );
	}

	public static function plan( $input = array() ) {
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		$input = is_array( $input ) ? $input : array();
		$record = self::record();
		$desired = self::desired_from_input( $input, $record );
		$provenance = self::provenance();
		if ( is_wp_error( $provenance ) ) return $provenance;

		$blockers = array();
		$environment = self::environment();
		if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ) $blockers[] = 'unsupported_environment';
		if ( ( $desired['production_mutation_enabled'] || $desired['production_auto_enable'] ) && 'production' !== $environment ) $blockers[] = 'production_environment_required';
		if ( $desired['raw_sql_write_enabled'] && ! $desired['raw_sql_breakglass_enabled'] ) $blockers[] = 'raw_sql_write_requires_breakglass';
		if ( $desired['raw_sql_ddl_enabled'] && ! $desired['raw_sql_write_enabled'] ) $blockers[] = 'raw_sql_ddl_requires_write';
		if ( $desired['production_auto_enable'] && ! $desired['production_mutation_enabled'] ) $blockers[] = 'production_auto_enable_requires_mutation';
		if ( 'production' === $environment && $desired['production_mutation_enabled'] && ! MAD4B_SCP_Site_Profile::write_enabled() ) $blockers[] = 'production_profile_write_confirmation_required';
		if ( 'production' === $environment && $desired['production_mutation_enabled']
			&& ( ! class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) || ! MAD4B_SCP_Staging_OAuth_Autoconfig::production_profile_enabled() ) ) {
			$blockers[] = 'production_oauth_opt_in_required';
		}
		if ( 'production' === $environment && $desired['raw_sql_breakglass_enabled'] && ! $desired['production_mutation_enabled'] ) $blockers[] = 'production_breakglass_requires_mutation';
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) $blockers[] = 'audit_storage_not_ready';

		$before = self::record_flags( $record );
		$effective_before = self::effective_status( $record );
		$enabling = ( $desired['raw_sql_breakglass_enabled'] && empty( $effective_before['raw_sql_breakglass_enabled'] ) )
			|| ( $desired['raw_sql_write_enabled'] && empty( $effective_before['raw_sql_write_enabled'] ) )
			|| ( $desired['raw_sql_ddl_enabled'] && empty( $effective_before['raw_sql_ddl_enabled'] ) )
			|| ( $desired['production_mutation_enabled'] && empty( $effective_before['production_mutation_enabled'] ) )
			|| ( $desired['production_auto_enable'] && empty( $effective_before['production_auto_enable'] ) );
		$confirmation = $enabling ? self::CONFIRM_ENABLE : self::CONFIRM_UPDATE;

		$plan = array(
			'contract' => self::CONTRACT,
			'operation' => 'apply_governed_runtime_gates',
			'environment' => $environment,
			'site_uuid' => self::site_uuid(),
			'site_profile_revision' => self::profile_revision(),
			'site_profile_digest' => self::profile_digest(),
			'policy_revision' => isset( $record['revision'] ) ? (int) $record['revision'] : 0,
			'policy_digest' => self::record_digest( $record ),
			'source_commit_sha' => $provenance['source_commit_sha'],
			'build_fingerprint' => $provenance['build_fingerprint'],
			'before' => $before,
			'effective_before' => array(
				'raw_sql_breakglass_enabled' => ! empty( $effective_before['raw_sql_breakglass_enabled'] ),
				'raw_sql_write_enabled' => ! empty( $effective_before['raw_sql_write_enabled'] ),
				'raw_sql_ddl_enabled' => ! empty( $effective_before['raw_sql_ddl_enabled'] ),
				'production_mutation_enabled' => ! empty( $effective_before['production_mutation_enabled'] ),
				'production_auto_enable' => ! empty( $effective_before['production_auto_enable'] ),
			),
			'after' => $desired,
			'required_confirmation' => $confirmation,
			'hard_blockers' => array_values( array_unique( $blockers ) ),
			'ready_to_apply' => empty( $blockers ),
			'production_mutation_requested' => (bool) $desired['production_mutation_enabled'],
			'raw_sql_breakglass_requested' => (bool) $desired['raw_sql_breakglass_enabled'],
			'raw_sql_write_requested' => (bool) $desired['raw_sql_write_enabled'],
			'raw_sql_ddl_requested' => (bool) $desired['raw_sql_ddl_enabled'],
			'database_authoritative' => true,
		);
		$plan['plan_sha256'] = self::plan_sha256( $plan );
		return $plan;
	}

	public static function handshake( $input = array() ) {
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		return array(
			'contract' => 'mad4b.governed-runtime-gates-handshake.v1',
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'environment' => $plan['environment'],
			'ready_to_apply' => ! empty( $plan['ready_to_apply'] ),
			'hard_blockers' => $plan['hard_blockers'],
			'current' => $plan['before'],
			'requested' => $plan['after'],
			'exact_apply' => array(
				'raw_sql_breakglass_enabled' => (bool) $plan['after']['raw_sql_breakglass_enabled'],
				'raw_sql_write_enabled' => (bool) $plan['after']['raw_sql_write_enabled'],
				'raw_sql_ddl_enabled' => (bool) $plan['after']['raw_sql_ddl_enabled'],
				'production_mutation_enabled' => (bool) $plan['after']['production_mutation_enabled'],
				'production_auto_enable' => (bool) $plan['after']['production_auto_enable'],
				'expected_plan_sha256' => $plan['plan_sha256'],
				'expected_policy_revision' => (int) $plan['policy_revision'],
				'expected_policy_digest' => $plan['policy_digest'],
				'expected_site_uuid' => $plan['site_uuid'],
				'expected_profile_revision' => (int) $plan['site_profile_revision'],
				'expected_profile_digest' => $plan['site_profile_digest'],
				'expected_source_commit_sha' => $plan['source_commit_sha'],
				'expected_build_fingerprint' => $plan['build_fingerprint'],
				'confirmation' => $plan['required_confirmation'],
			),
			'client_action' => ! empty( $plan['ready_to_apply'] ) ? 'apply_exact_handshake' : 'repair_blockers_then_request_fresh_handshake',
		);
	}

	public static function apply( $input ) {
		$access = self::can_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_runtime_gates_input_invalid', 'Input must be an object.' );

		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['ready_to_apply'] ) ) return new WP_Error( 'mad4b_runtime_gates_plan_blocked', 'Runtime gate plan contains hard blockers.', array( 'blockers' => $plan['hard_blockers'] ) );
		$match = self::match_expected_plan( $plan, $input );
		if ( is_wp_error( $match ) ) return $match;
		if ( ! hash_equals( (string) $plan['required_confirmation'], (string) $input['confirmation'] ) ) return new WP_Error( 'mad4b_runtime_gates_confirmation_required', 'Exact runtime gate confirmation is required.' );

		$before_raw = get_option( self::OPTION, false );
		$before_exists = false !== $before_raw;
		$before = self::record();
		if ( (int) $before['revision'] !== (int) $plan['policy_revision']
			|| ! hash_equals( (string) $plan['policy_digest'], self::record_digest( $before ) ) ) {
			return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Runtime gate policy changed after plan validation and before persistence.' );
		}
		$revalidated = self::plan( $input );
		if ( is_wp_error( $revalidated ) ) return $revalidated;
		if ( empty( $revalidated['ready_to_apply'] )
			|| ! hash_equals( (string) $plan['plan_sha256'], (string) $revalidated['plan_sha256'] ) ) {
			return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Runtime gate plan changed immediately before persistence.' );
		}
		$next = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'revision' => (int) $plan['policy_revision'] + 1,
			'site_uuid' => $plan['site_uuid'],
			'profile_revision' => (int) $plan['site_profile_revision'],
			'profile_digest' => $plan['site_profile_digest'],
			'raw_sql_breakglass_enabled' => (bool) $plan['after']['raw_sql_breakglass_enabled'],
			'raw_sql_write_enabled' => (bool) $plan['after']['raw_sql_write_enabled'],
			'raw_sql_ddl_enabled' => (bool) $plan['after']['raw_sql_ddl_enabled'],
			'production_mutation_enabled' => (bool) $plan['after']['production_mutation_enabled'],
			'production_auto_enable' => (bool) $plan['after']['production_auto_enable'],
			'updated_at' => gmdate( 'c' ),
		);

		if ( ! self::persist_exact( $next, $before_exists, $before_raw ) ) return new WP_Error( 'mad4b_runtime_gates_persist_conflict', 'Runtime gate policy changed concurrently or could not be persisted and verified.' );
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::record( 'mad4b/runtime-gates-applied', array(
			'previous_revision' => (int) $plan['policy_revision'],
			'revision' => (int) $next['revision'],
			'previous_policy_digest' => $plan['policy_digest'],
			'policy_digest' => self::record_digest( $next ),
			'site_uuid' => $next['site_uuid'],
			'profile_revision' => $next['profile_revision'],
			'profile_digest' => $next['profile_digest'],
			'environment' => $plan['environment'],
			'source_commit_sha' => $plan['source_commit_sha'],
			'build_fingerprint' => $plan['build_fingerprint'],
			'raw_sql_breakglass_enabled' => $next['raw_sql_breakglass_enabled'],
			'raw_sql_write_enabled' => $next['raw_sql_write_enabled'],
			'raw_sql_ddl_enabled' => $next['raw_sql_ddl_enabled'],
			'production_mutation_enabled' => $next['production_mutation_enabled'],
			'production_auto_enable' => $next['production_auto_enable'],
			'database_authoritative' => true,
		), 'ok' ) : new WP_Error( 'mad4b_runtime_gates_audit_unavailable' );

		if ( is_wp_error( $audit ) ) {
			$rolled_back = self::restore_exact( $before_exists, $before_raw, $next );
			if ( ! $rolled_back ) {
				return new WP_Error( 'mad4b_runtime_gates_audit_rollback_failed', 'Audit evidence failed and the runtime gate policy could not be safely rolled back because the stored value changed concurrently.' );
			}
			return new WP_Error( 'mad4b_runtime_gates_audit_failed', 'Runtime gate policy was rolled back because append-only audit evidence could not be committed.' );
		}
		self::bootstrap_runtime();
		$after = self::status();
		return array(
			'contract' => self::CONTRACT,
			'state' => 'runtime_gates_ready',
			'revision' => (int) $next['revision'],
			'policy_digest' => self::record_digest( $next ),
			'raw_sql_breakglass_enabled' => ! empty( $after['raw_sql_breakglass_enabled'] ),
			'raw_sql_write_enabled' => ! empty( $after['raw_sql_write_enabled'] ),
			'raw_sql_ddl_enabled' => ! empty( $after['raw_sql_ddl_enabled'] ),
			'production_mutation_enabled' => ! empty( $after['production_mutation_enabled'] ),
			'production_auto_enable' => ! empty( $after['production_auto_enable'] ),
			'database_authoritative' => true,
			'legacy_constant_authoritative' => false,
			'mutation_performed' => true,
			'production_mutation_performed' => false,
		);
	}

	private static function exact_profile_bound() {
		return class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::configured()
			&& MAD4B_SCP_Site_Profile::origin_enrolled()
			&& MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
	}

	private static function environment() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : 'unknown';
	}

	private static function site_uuid() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) ) : '';
	}

	private static function profile_revision() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) ? (int) MAD4B_SCP_Site_Profile::revision() : 0;
	}

	private static function profile_digest() {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) ? strtolower( trim( (string) MAD4B_SCP_Site_Profile::profile_digest() ) ) : '';
	}

	private static function default_record() {
		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'revision' => 0,
			'site_uuid' => self::site_uuid(),
			'profile_revision' => self::profile_revision(),
			'profile_digest' => self::profile_digest(),
			'raw_sql_breakglass_enabled' => false,
			'raw_sql_write_enabled' => false,
			'raw_sql_ddl_enabled' => false,
			'production_mutation_enabled' => false,
			'production_auto_enable' => false,
			'updated_at' => '',
		);
	}

	private static function record() {
		$value = get_option( self::OPTION, false );
		if ( ! is_array( $value ) || self::CONTRACT !== ( isset( $value['contract'] ) ? (string) $value['contract'] : '' ) || self::VERSION !== (int) ( isset( $value['version'] ) ? $value['version'] : 0 ) ) return self::default_record();
		$record = self::default_record();
		foreach ( array_keys( $record ) as $key ) if ( array_key_exists( $key, $value ) ) $record[ $key ] = $value[ $key ];
		$record['revision'] = max( 0, (int) $record['revision'] );
		$record['profile_revision'] = max( 0, (int) $record['profile_revision'] );
		$record['site_uuid'] = strtolower( trim( (string) $record['site_uuid'] ) );
		$record['profile_digest'] = strtolower( trim( (string) $record['profile_digest'] ) );
		foreach ( array( 'raw_sql_breakglass_enabled', 'raw_sql_write_enabled', 'raw_sql_ddl_enabled', 'production_mutation_enabled', 'production_auto_enable' ) as $key ) $record[ $key ] = ! empty( $record[ $key ] );
		$record['updated_at'] = (string) $record['updated_at'];
		return $record;
	}

	private static function effective_status( $record = null ) {
		if ( ! is_array( $record ) ) $record = self::record();
		$binding_match = self::exact_profile_bound()
			&& '' !== (string) $record['site_uuid']
			&& hash_equals( self::site_uuid(), (string) $record['site_uuid'] )
			&& self::profile_revision() === (int) $record['profile_revision']
			&& '' !== (string) $record['profile_digest']
			&& hash_equals( self::profile_digest(), (string) $record['profile_digest'] );
		$environment = self::environment();
		$profile_write_enabled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::write_enabled();
		$production_oauth_enabled = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' )
			&& method_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig', 'production_profile_enabled' )
			&& MAD4B_SCP_Staging_OAuth_Autoconfig::production_profile_enabled();
		$production = $binding_match
			&& 'production' === $environment
			&& ! empty( $record['production_mutation_enabled'] )
			&& $profile_write_enabled
			&& $production_oauth_enabled;
		$raw = $binding_match && ! empty( $record['raw_sql_breakglass_enabled'] ) && ( 'production' !== $environment || $production );
		$raw_write = $raw && ! empty( $record['raw_sql_write_enabled'] );
		$raw_ddl = $raw_write && ! empty( $record['raw_sql_ddl_enabled'] );
		$auto = $production && ! empty( $record['production_auto_enable'] );
		$blockers = array();
		if ( ( ! empty( $record['raw_sql_breakglass_enabled'] ) || ! empty( $record['raw_sql_write_enabled'] ) || ! empty( $record['raw_sql_ddl_enabled'] ) || ! empty( $record['production_mutation_enabled'] ) || ! empty( $record['production_auto_enable'] ) ) && ! $binding_match ) $blockers[] = 'runtime_gate_profile_binding_stale';
		if ( ! empty( $record['production_mutation_enabled'] ) && 'production' !== $environment ) $blockers[] = 'production_environment_required';
		if ( 'production' === $environment && ! empty( $record['production_mutation_enabled'] ) && ! $profile_write_enabled ) $blockers[] = 'production_profile_write_confirmation_required';
		if ( 'production' === $environment && ! empty( $record['production_mutation_enabled'] ) && ! $production_oauth_enabled ) $blockers[] = 'production_oauth_opt_in_required';
		if ( 'production' === $environment && ! empty( $record['raw_sql_breakglass_enabled'] ) && ! $production ) $blockers[] = 'production_breakglass_requires_effective_mutation';
		if ( ! empty( $record['raw_sql_write_enabled'] ) && empty( $record['raw_sql_breakglass_enabled'] ) ) $blockers[] = 'raw_sql_write_requires_breakglass';
		if ( ! empty( $record['raw_sql_ddl_enabled'] ) && empty( $record['raw_sql_write_enabled'] ) ) $blockers[] = 'raw_sql_ddl_requires_write';
		if ( ! empty( $record['production_auto_enable'] ) && empty( $record['production_mutation_enabled'] ) ) $blockers[] = 'production_auto_enable_requires_mutation';
		return array(
			'profile_binding_match' => $binding_match,
			'production_oauth_opt_in_effective' => (bool) $production_oauth_enabled,
			'raw_sql_breakglass_enabled' => $raw,
			'raw_sql_write_enabled' => $raw_write,
			'raw_sql_ddl_enabled' => $raw_ddl,
			'production_mutation_enabled' => $production,
			'production_auto_enable' => $auto,
			'blockers' => array_values( array_unique( $blockers ) ),
			'ready' => empty( $blockers ),
		);
	}

	private static function record_flags( array $record ) {
		return array(
			'raw_sql_breakglass_enabled' => ! empty( $record['raw_sql_breakglass_enabled'] ),
			'raw_sql_write_enabled' => ! empty( $record['raw_sql_write_enabled'] ),
			'raw_sql_ddl_enabled' => ! empty( $record['raw_sql_ddl_enabled'] ),
			'production_mutation_enabled' => ! empty( $record['production_mutation_enabled'] ),
			'production_auto_enable' => ! empty( $record['production_auto_enable'] ),
		);
	}

	private static function desired_from_input( array $input, array $record ) {
		$current = self::record_flags( $record );
		$out = array();
		foreach ( array_keys( $current ) as $key ) $out[ $key ] = array_key_exists( $key, $input ) ? (bool) $input[ $key ] : (bool) $current[ $key ];
		return $out;
	}

	private static function record_digest( array $record ) {
		$copy = $record;
		unset( $copy['updated_at'] );
		ksort( $copy, SORT_STRING );
		$json = wp_json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function plan_sha256( array $plan ) {
		unset( $plan['plan_sha256'] );
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function provenance() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_runtime_gates_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$p = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $p ) || empty( $p['manifest_present'] ) || empty( $p['manifest_valid'] ) || empty( $p['runtime_manifest_match'] ) || ! empty( $p['stale'] ) || ! empty( $p['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_runtime_gates_provenance_not_ready', 'Exact current build provenance is not ready.' );
		if ( empty( $p['source_commit_sha'] ) || empty( $p['build_fingerprint'] ) ) return new WP_Error( 'mad4b_runtime_gates_provenance_incomplete', 'Build provenance identity is incomplete.' );
		return array(
			'source_commit_sha' => strtolower( (string) $p['source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $p['build_fingerprint'] ),
		);
	}

	private static function match_expected_plan( array $plan, array $input ) {
		$strings = array(
			'plan_sha256' => isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '',
			'policy_digest' => isset( $input['expected_policy_digest'] ) ? strtolower( trim( (string) $input['expected_policy_digest'] ) ) : '',
			'site_uuid' => isset( $input['expected_site_uuid'] ) ? strtolower( trim( (string) $input['expected_site_uuid'] ) ) : '',
			'site_profile_digest' => isset( $input['expected_profile_digest'] ) ? strtolower( trim( (string) $input['expected_profile_digest'] ) ) : '',
			'source_commit_sha' => isset( $input['expected_source_commit_sha'] ) ? strtolower( trim( (string) $input['expected_source_commit_sha'] ) ) : '',
			'build_fingerprint' => isset( $input['expected_build_fingerprint'] ) ? strtolower( trim( (string) $input['expected_build_fingerprint'] ) ) : '',
		);
		foreach ( $strings as $key => $expected ) {
			$current = isset( $plan[ $key ] ) ? strtolower( (string) $plan[ $key ] ) : '';
			if ( '' === $expected || ! hash_equals( $current, $expected ) ) return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Runtime gate plan identity changed before apply.', array( 'field' => $key ) );
		}
		if ( (int) $plan['policy_revision'] !== (int) ( isset( $input['expected_policy_revision'] ) ? $input['expected_policy_revision'] : -1 ) ) return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Runtime gate policy revision changed before apply.', array( 'field' => 'policy_revision' ) );
		if ( (int) $plan['site_profile_revision'] !== (int) ( isset( $input['expected_profile_revision'] ) ? $input['expected_profile_revision'] : -1 ) ) return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Site Profile revision changed before apply.', array( 'field' => 'site_profile_revision' ) );
		foreach ( array( 'raw_sql_breakglass_enabled', 'raw_sql_write_enabled', 'raw_sql_ddl_enabled', 'production_mutation_enabled', 'production_auto_enable' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || (bool) $plan['after'][ $key ] !== (bool) $input[ $key ] ) return new WP_Error( 'mad4b_runtime_gates_plan_stale', 'Requested runtime gate state changed before apply.', array( 'field' => $key ) );
		}
		return true;
	}

	private static function clear_option_cache() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function stored_value_matches( $expected ) {
		self::clear_option_cache();
		$stored = get_option( self::OPTION, false );
		return maybe_serialize( $stored ) === maybe_serialize( $expected );
	}

	private static function persist_exact( array $record, $existed, $previous_raw ) {
		global $wpdb;
		if ( ! $existed ) {
			$created = add_option( self::OPTION, $record, '', false );
			return $created && self::stored_value_matches( $record );
		}
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $record ),
				self::OPTION,
				maybe_serialize( $previous_raw )
			)
		);
		if ( 1 !== (int) $updated ) return false;
		return self::stored_value_matches( $record );
	}

	private static function restore_exact( $existed, $previous_raw, array $current_record ) {
		global $wpdb;
		$current_serialized = maybe_serialize( $current_record );
		if ( $existed ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $previous_raw ),
					self::OPTION,
					$current_serialized
				)
			);
			if ( 1 !== (int) $updated ) return false;
			return self::stored_value_matches( $previous_raw );
		}
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$current_serialized
			)
		);
		self::clear_option_cache();
		return 1 === (int) $deleted && false === get_option( self::OPTION, false );
	}
}
