<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded non-Production authority bootstrap for the isolated Developer Plane.
 *
 * This class is intentionally mounted only on mad4b-enrollment. It cannot make
 * Production eligible, cannot grant operational mad4b-write authority, cannot
 * grant raw-SQL breakglass, and cannot silently create Developer Breakglass
 * grants. Normal Developer and Developer Breakglass authority are separate
 * plan/apply operations with separate confirmations and audit evidence.
 */
final class MAD4B_SCP_Developer_Authority {
	const CONTRACT = 'mad4b.developer-authority.v1';
	const AGENT_SLUG = 'mad4b-developer-agent';
	const SUBJECT_TYPE = 'oauth_developer';
	const CONFIRM_PROVISION = 'PROVISION MAD4B DEVELOPER AGENT';
	const CONFIRM_DISABLE = 'DISABLE MAD4B DEVELOPER AGENT';
	const CONFIRM_BREAKGLASS = 'PROVISION MAD4B DEVELOPER BREAKGLASS';

	private static $booted = false;
	private static $running = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ), 17 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 17 );
	}

	public static function enrollment_tools() {
		return array(
			'mad4b/developer-authority-status',
			'mad4b/developer-authority-plan',
			'mad4b/developer-authority-apply',
			'mad4b/developer-authority-disable',
			'mad4b/developer-breakglass-authority-plan',
			'mad4b/developer-breakglass-authority-apply',
		);
	}

	public static function register_category() {
		wp_register_ability_category( 'mad4b-developer-governance', array(
			'label' => 'MAD4B Developer Governance',
			'description' => 'Bounded enrollment-time governance for the isolated Developer Agent plane.',
		) );
	}

	public static function register_abilities() {
		self::register(
			'mad4b/developer-authority-status',
			'Developer Authority Status',
			'status',
			true,
			self::schema( array() )
		);
		self::register(
			'mad4b/developer-authority-plan',
			'Developer Authority Plan',
			'plan',
			true,
			self::schema( array() )
		);
		self::register(
			'mad4b/developer-authority-apply',
			'Developer Authority Apply',
			'apply',
			false,
			self::apply_schema( self::CONFIRM_PROVISION )
		);
		self::register(
			'mad4b/developer-authority-disable',
			'Developer Authority Disable',
			'disable',
			false,
			self::schema(
				array(
					'expected_agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
					'expected_agent_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
					'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRM_DISABLE ) ),
				),
				array( 'expected_agent_public_id', 'expected_agent_revision', 'confirmation' )
			)
		);
		self::register(
			'mad4b/developer-breakglass-authority-plan',
			'Developer Breakglass Authority Plan',
			'breakglass_plan',
			true,
			self::schema( array() )
		);
		self::register(
			'mad4b/developer-breakglass-authority-apply',
			'Developer Breakglass Authority Apply',
			'breakglass_apply',
			false,
			self::apply_schema( self::CONFIRM_BREAKGLASS )
		);
	}

	private static function register( $name, $label, $method, $readonly, array $input ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $label . ' for the isolated non-Production Developer Agent plane.',
			'category' => 'mad4b-developer-governance',
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => array( __CLASS__, 'can_access' ),
			'input_schema' => $input,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array(
					'public' => false,
					'type' => 'tool',
					'surface' => 'enrollment',
					'mad4b_developer_authority' => self::CONTRACT,
					'production_allowed' => false,
				),
				'annotations' => array(
					'readonly' => (bool) $readonly,
					'destructive' => ! $readonly,
					'idempotent' => $readonly,
				),
			),
		) );
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private static function apply_schema( $confirmation ) {
		return self::schema(
			array(
				'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'expected_source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
				'expected_site_uuid' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 36 ),
				'expected_environment' => array( 'type' => 'string', 'enum' => array( 'staging', 'development', 'local' ) ),
				'expected_profile_revision' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_profile_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'confirmation' => array( 'type' => 'string', 'enum' => array( $confirmation ) ),
			),
			array(
				'expected_plan_sha256',
				'expected_source_commit_sha',
				'expected_site_uuid',
				'expected_environment',
				'expected_profile_revision',
				'expected_profile_digest',
				'confirmation',
			)
		);
	}

	public static function can_access( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_developer_authority_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_developer_authority_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return new WP_Error( 'mad4b_developer_authority_profile_missing', 'An enrolled Site Profile is required.' );
		$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		if ( ! in_array( $environment, array( 'staging', 'development', 'local' ), true ) ) return new WP_Error( 'mad4b_developer_authority_non_production_only', 'Developer authority provisioning is limited to explicit non-Production environments.' );
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return new WP_Error( 'mad4b_developer_authority_profile_not_exact', 'Current origin and URLs must exactly match the enrolled Site Profile.' );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) return new WP_Error( 'mad4b_developer_authority_subject_not_enrolled', 'The authenticated administrator is not enrolled in this Site Profile.' );
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_developer_authority_identity_unavailable', 'Governance identity is unavailable.' );
		if ( is_wp_error( $identity ) ) return $identity;
		if ( empty( $identity['authenticated'] ) || 'oauth' !== ( isset( $identity['subject_type'] ) ? (string) $identity['subject_type'] : '' ) || 'oauth2_bearer' !== ( isset( $identity['auth_method'] ) ? (string) $identity['auth_method'] : '' ) ) return new WP_Error( 'mad4b_developer_authority_normal_oauth_required', 'Provisioning must originate from the enrolled normal OAuth subject, not from Developer authority itself.' );
		if ( ! self::identity_has_developer_derivation_material( $identity ) ) return new WP_Error( 'mad4b_developer_authority_client_identity_required', 'OAuth subject and client fingerprints are required to derive the isolated Developer identity.' );
		return true;
	}

	public static function status() {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : 'unknown';
		$agent = self::configured_or_slug_agent();
		$normal = self::grant_status( $agent, 'mad4b-developer', self::normal_tools() );
		$breakglass = self::grant_status( $agent, 'mad4b-developer-breakglass', self::breakglass_tools() );
		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'environment' => $environment,
			'production_allowed' => false,
			'agent_present' => is_array( $agent ),
			'agent_public_id' => is_array( $agent ) ? (string) $agent['public_id'] : '',
			'agent_slug' => is_array( $agent ) ? (string) $agent['slug'] : '',
			'agent_status' => is_array( $agent ) ? (string) $agent['status'] : '',
			'agent_revision' => is_array( $agent ) ? (int) $agent['revision'] : 0,
			'agent_user_match' => is_array( $agent ) ? (int) $agent['wp_user_id'] === get_current_user_id() : false,
			'agent_environment_match' => is_array( $agent ) ? $environment === (string) $agent['environment'] : false,
			'configured_agent_public_id' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::configured_agent_public_id() : '',
			'developer_enabled' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) && MAD4B_SCP_Developer_Runtime::developer_flag_enabled(),
			'direct_execution_enabled' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) && MAD4B_SCP_Developer_Runtime::direct_execution_enabled(),
			'kill_switch_enabled' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) && MAD4B_SCP_Developer_Runtime::kill_switch_enabled(),
			'breakglass_enabled' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) && MAD4B_SCP_Developer_Runtime::breakglass_flag_enabled(),
			'normal_authority' => $normal,
			'breakglass_authority' => $breakglass,
		);
	}

	public static function plan() {
		$access = self::can_access();
		if ( is_wp_error( $access ) || ! $access ) return $access;
		return self::build_plan( false );
	}

	public static function breakglass_plan() {
		$access = self::can_access();
		if ( is_wp_error( $access ) || ! $access ) return $access;
		return self::build_plan( true );
	}

	private static function build_plan( $breakglass ) {
		$identity = MAD4B_SCP_Identity_Context::current();
		if ( is_wp_error( $identity ) ) return $identity;
		$provenance = self::provenance();
		if ( is_wp_error( $provenance ) ) return $provenance;

		$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		$site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
		$revision = (int) MAD4B_SCP_Site_Profile::revision();
		$digest = strtolower( (string) MAD4B_SCP_Site_Profile::profile_digest() );
		$derived_subject = self::derived_subject_fingerprint( $identity );
		if ( is_wp_error( $derived_subject ) ) return $derived_subject;

		$agent = self::configured_or_slug_agent();
		$blockers = array();
		if ( is_array( $agent ) ) {
			if ( self::AGENT_SLUG !== (string) $agent['slug'] ) $blockers[] = 'configured_agent_slug_mismatch';
			if ( (int) $agent['wp_user_id'] !== get_current_user_id() ) $blockers[] = 'configured_agent_user_mismatch';
			if ( $environment !== (string) $agent['environment'] ) $blockers[] = 'configured_agent_environment_mismatch';
		}
		$binding = MAD4B_SCP_Agent_Registry::resolve_agent( array(
			'authenticated' => true,
			'subject_type' => self::SUBJECT_TYPE,
			'subject_fingerprint' => $derived_subject,
		) );
		$subject_state = 'unbound';
		if ( is_array( $binding ) ) {
			$subject_state = 'bound';
			if ( ! is_array( $agent ) || (int) $binding['id'] !== (int) $agent['id'] ) $blockers[] = 'developer_subject_bound_elsewhere';
		} elseif ( is_wp_error( $binding ) && 'mad4b_nhi_subject_unbound' !== $binding->get_error_code() ) {
			$blockers[] = $binding->get_error_code();
		}

		$server_id = $breakglass ? 'mad4b-developer-breakglass' : 'mad4b-developer';
		$tools = $breakglass ? self::breakglass_tools() : self::normal_tools();
		$rows = array();
		foreach ( $tools as $ability ) {
			$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( $server_id, $ability ) : null;
			$present = false;
			$error = '';
			if ( null === $provider ) {
				$error = 'ability_not_mounted';
				$blockers[] = 'unmounted:' . $ability;
			} elseif ( is_array( $agent ) ) {
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability, $provider );
				if ( is_array( $grant ) && $environment === (string) $grant['environment'] && 'allow' === (string) $grant['effect'] ) $present = true;
				elseif ( is_wp_error( $grant ) && 'mad4b_nhi_grant_missing' !== $grant->get_error_code() ) $error = $grant->get_error_code();
			}
			$rows[] = array( 'ability' => $ability, 'provider' => null === $provider ? '' : (string) $provider, 'exact_grant_present' => $present, 'error' => $error );
		}
		usort( $rows, static function ( $a, $b ) { return strcmp( $a['ability'], $b['ability'] ); } );

		if ( $breakglass ) {
			if ( ! defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) || true !== constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) $blockers[] = 'global_breakglass_gate_disabled';
			if ( ! is_array( $agent ) ) $blockers[] = 'normal_developer_agent_missing';
			$normal = self::grant_status( $agent, 'mad4b-developer', self::normal_tools() );
			if ( empty( $normal['ready'] ) ) $blockers[] = 'normal_developer_authority_not_ready';
		}

		$plan = array(
			'contract' => self::CONTRACT,
			'operation' => $breakglass ? 'provision_developer_breakglass' : 'provision_developer',
			'production_allowed' => false,
			'environment' => $environment,
			'site_uuid' => $site_uuid,
			'site_profile_revision' => $revision,
			'site_profile_digest' => $digest,
			'source_commit_sha' => (string) $provenance['source_commit_sha'],
			'build_fingerprint' => (string) $provenance['build_fingerprint'],
			'agent_present' => is_array( $agent ),
			'agent_public_id' => is_array( $agent ) ? (string) $agent['public_id'] : '',
			'agent_slug' => self::AGENT_SLUG,
			'developer_subject_fingerprint' => $derived_subject,
			'developer_subject_state' => $subject_state,
			'server_id' => $server_id,
			'tool_count' => count( $rows ),
			'rows' => $rows,
			'blockers' => array_values( array_unique( array_map( 'strval', $blockers ) ) ),
			'ready_to_apply' => empty( $blockers ),
		);
		$plan['plan_sha256'] = self::plan_sha256( $plan );
		return $plan;
	}

	public static function apply( $input ) {
		return self::apply_plan( $input, false );
	}

	public static function breakglass_apply( $input ) {
		return self::apply_plan( $input, true );
	}

	private static function apply_plan( $input, $breakglass ) {
		if ( self::$running ) return new WP_Error( 'mad4b_developer_authority_reentry_denied', 'Developer authority apply is already running in this request.' );
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! is_array( $input ) ) return new WP_Error( 'mad4b_developer_authority_input_invalid', 'Input must be an object.' );
		$confirmation = $breakglass ? self::CONFIRM_BREAKGLASS : self::CONFIRM_PROVISION;
		if ( $confirmation !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_developer_authority_confirmation_required', 'Exact Developer authority confirmation is required.' );

		self::$running = true;
		try {
			$plan = self::build_plan( $breakglass );
			if ( is_wp_error( $plan ) ) return $plan;
			if ( empty( $plan['ready_to_apply'] ) ) return new WP_Error( 'mad4b_developer_authority_plan_blocked', 'Developer authority plan contains blockers.', array( 'blockers' => $plan['blockers'] ) );
			$match = self::match_expected_plan( $plan, $input );
			if ( is_wp_error( $match ) ) return $match;
			if ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) return new WP_Error( 'mad4b_developer_authority_audit_required', 'Ready append-only audit storage is required.' );

			$config_before = self::configuration_snapshot();
			$agent_before = self::configured_or_slug_agent();
			$agent_status_before = is_array( $agent_before ) ? (string) $agent_before['status'] : '';
			$agent_revision_before = is_array( $agent_before ) ? (int) $agent_before['revision'] : 0;

			$authorized = MAD4B_SCP_Audit::record( $breakglass ? 'mad4b/developer-breakglass-authority-authorized' : 'mad4b/developer-authority-authorized', array(
				'contract' => self::CONTRACT,
				'plan_sha256' => $plan['plan_sha256'],
				'source_commit_sha' => $plan['source_commit_sha'],
				'site_uuid' => $plan['site_uuid'],
				'environment' => $plan['environment'],
				'server_id' => $plan['server_id'],
				'tool_count' => $plan['tool_count'],
				'confirmation' => $confirmation,
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $authorized ) ) return new WP_Error( 'mad4b_developer_authority_intent_audit_failed', 'Developer authority intent audit could not be committed.' );

			$agent = self::configured_or_slug_agent();
			$created_agent = false;
			$subject_created = false;
			$created_subject_fingerprint = '';
			$created_grants = array();
			if ( ! is_array( $agent ) ) {
				$label = 'MAD4B Developer Agent';
				$display = trim( (string) MAD4B_SCP_Site_Profile::display_name() );
				if ( '' !== $display ) $label .= ' — ' . $display;
				$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
					'slug' => self::AGENT_SLUG,
					'label' => substr( $label, 0, 191 ),
					'status' => 'enabled',
					'wp_user_id' => get_current_user_id(),
					'environment' => $plan['environment'],
				) );
				if ( is_wp_error( $agent ) ) return $agent;
				$created_agent = true;
			} elseif ( 'enabled' !== (string) $agent['status'] ) {
				$agent = MAD4B_SCP_Agent_Registry::update_agent( $agent['public_id'], array( 'status' => 'enabled' ), (int) $agent['revision'] );
				if ( is_wp_error( $agent ) ) return $agent;
			}

			$identity = array( 'authenticated' => true, 'subject_type' => self::SUBJECT_TYPE, 'subject_fingerprint' => $plan['developer_subject_fingerprint'] );
			$bound_before = MAD4B_SCP_Agent_Registry::resolve_agent( $identity );
			if ( is_wp_error( $bound_before ) && 'mad4b_nhi_subject_unbound' === $bound_before->get_error_code() ) {
				$bind = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], self::SUBJECT_TYPE, $plan['developer_subject_fingerprint'], 'Derived Developer OAuth subject' );
				if ( is_wp_error( $bind ) ) return self::rollback_partial( $agent, $created_agent, false, '', $created_grants, $config_before, $agent_status_before, $bind );
				$subject_created = true;
				$created_subject_fingerprint = (string) $plan['developer_subject_fingerprint'];
			} elseif ( is_array( $bound_before ) && (int) $bound_before['id'] !== (int) $agent['id'] ) {
				return self::rollback_partial( $agent, $created_agent, false, '', $created_grants, $config_before, $agent_status_before, new WP_Error( 'mad4b_developer_subject_bound_elsewhere', 'Derived Developer subject became bound elsewhere before apply.' ) );
			}

			$server_id = $plan['server_id'];
			if ( $breakglass ) add_filter( 'mad4b_scp_allow_developer_breakglass_grant_creation', '__return_true', 999 );
			try {
				foreach ( $plan['rows'] as $row ) {
					if ( ! empty( $row['exact_grant_present'] ) ) continue;
					$created = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], $server_id, $row['ability'], $row['provider'], array(), 'allow', $plan['environment'] );
					if ( is_wp_error( $created ) ) return self::rollback_partial( $agent, $created_agent, $subject_created, $created_subject_fingerprint, $created_grants, $config_before, $agent_status_before, $created );
					$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $row['ability'], $row['provider'] );
					if ( ! is_array( $grant ) ) return self::rollback_partial( $agent, $created_agent, $subject_created, $created_subject_fingerprint, $created_grants, $config_before, $agent_status_before, new WP_Error( 'mad4b_developer_grant_readback_failed', 'Created Developer grant failed immediate readback.' ) );
					$created_grants[] = (int) $grant['id'];
				}
			} finally {
				if ( $breakglass ) remove_filter( 'mad4b_scp_allow_developer_breakglass_grant_creation', '__return_true', 999 );
			}

			if ( $breakglass ) {
				update_option( 'mad4b_scp_developer_breakglass_enabled', '1', false );
			} else {
				update_option( 'mad4b_scp_developer_agent_public_id', (string) $agent['public_id'], false );
				update_option( 'mad4b_scp_developer_enabled', '1', false );
				update_option( 'mad4b_scp_developer_direct_execution_enabled', '1', false );
				update_option( 'mad4b_scp_developer_kill_switch', '0', false );
			}

			$readback = $breakglass ? self::breakglass_plan() : self::plan();
			if ( is_wp_error( $readback ) ) {
				return self::rollback_partial( $agent, $created_agent, $subject_created, $created_subject_fingerprint, $created_grants, $config_before, $agent_status_before, $readback );
			}
			$authority = self::grant_status( $agent, $server_id, $breakglass ? self::breakglass_tools() : self::normal_tools() );
			if ( empty( $authority['ready'] ) ) {
				return self::rollback_partial(
					$agent,
					$created_agent,
					$subject_created,
					$created_subject_fingerprint,
					$created_grants,
					$config_before,
					$agent_status_before,
					new WP_Error( 'mad4b_developer_authority_postcondition_failed', 'Developer authority did not converge after apply.', array( 'authority' => $authority ) )
				);
			}

			$complete = MAD4B_SCP_Audit::record( $breakglass ? 'mad4b/developer-breakglass-authority-complete' : 'mad4b/developer-authority-complete', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => (string) $agent['public_id'],
				'server_id' => $server_id,
				'created_agent' => $created_agent,
				'created_subject' => $subject_created,
				'created_grant_count' => count( $created_grants ),
				'tool_count' => $authority['tool_count'],
				'exact_grant_count' => $authority['exact_grant_count'],
				'source_commit_sha' => $plan['source_commit_sha'],
				'site_uuid' => $plan['site_uuid'],
				'environment' => $plan['environment'],
				'production_mutation' => false,
			), 'ok' );
			if ( is_wp_error( $complete ) ) {
				self::force_fail_closed( 'completion_audit_failed' );
				return self::rollback_partial(
					$agent,
					$created_agent,
					$subject_created,
					$created_subject_fingerprint,
					$created_grants,
					$config_before,
					$agent_status_before,
					new WP_Error( 'mad4b_developer_authority_completion_audit_failed', 'Developer authority completion audit failed after mutation.' )
				);
			}

			return array(
				'contract' => self::CONTRACT,
				'state' => $breakglass ? 'developer_breakglass_authority_ready' : 'developer_authority_ready',
				'agent_public_id' => (string) $agent['public_id'],
				'server_id' => $server_id,
				'created_agent' => $created_agent,
				'created_subject' => $subject_created,
				'created_grant_count' => count( $created_grants ),
				'authority' => $authority,
				'kill_switch_enabled' => MAD4B_SCP_Developer_Runtime::kill_switch_enabled(),
				'developer_enabled' => MAD4B_SCP_Developer_Runtime::developer_flag_enabled(),
				'direct_execution_enabled' => MAD4B_SCP_Developer_Runtime::direct_execution_enabled(),
				'breakglass_enabled' => MAD4B_SCP_Developer_Runtime::breakglass_flag_enabled(),
				'completion_audit_recorded' => true,
				'production_mutation' => false,
			);
		} finally {
			self::$running = false;
		}
	}

	public static function disable( $input ) {
		$access = self::can_access( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		if ( ! is_array( $input ) || self::CONFIRM_DISABLE !== ( isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '' ) ) return new WP_Error( 'mad4b_developer_authority_disable_confirmation_required', 'Exact Developer disable confirmation is required.' );
		$agent = self::configured_or_slug_agent();
		if ( ! is_array( $agent ) ) return new WP_Error( 'mad4b_developer_authority_agent_missing', 'Developer agent is not present.' );
		$expected_id = strtolower( trim( (string) $input['expected_agent_public_id'] ) );
		$expected_revision = absint( $input['expected_agent_revision'] );
		if ( ! hash_equals( strtolower( (string) $agent['public_id'] ), $expected_id ) || $expected_revision !== (int) $agent['revision'] ) return new WP_Error( 'mad4b_developer_authority_disable_stale', 'Developer agent identity or revision changed before disable.' );
		$intent = MAD4B_SCP_Audit::record( 'mad4b/developer-authority-disable-authorized', array(
			'contract' => self::CONTRACT,
			'agent_public_id' => (string) $agent['public_id'],
			'agent_revision' => (int) $agent['revision'],
			'confirmation' => self::CONFIRM_DISABLE,
			'production_mutation' => false,
		), 'ok' );
		if ( is_wp_error( $intent ) ) return $intent;

		update_option( 'mad4b_scp_developer_kill_switch', '1', false );
		update_option( 'mad4b_scp_developer_enabled', '0', false );
		update_option( 'mad4b_scp_developer_direct_execution_enabled', '0', false );
		update_option( 'mad4b_scp_developer_breakglass_enabled', '0', false );
		$disabled = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $agent['revision'] );
		$agent_disabled = ! is_wp_error( $disabled );
		MAD4B_SCP_Audit::record( 'mad4b/developer-authority-disabled', array(
			'contract' => self::CONTRACT,
			'agent_public_id' => (string) $agent['public_id'],
			'kill_switch_enabled' => true,
			'agent_disabled' => $agent_disabled,
			'production_mutation' => false,
		), $agent_disabled ? 'ok' : 'partial' );
		return array(
			'contract' => self::CONTRACT,
			'state' => 'developer_disabled',
			'kill_switch_enabled' => true,
			'agent_disabled' => $agent_disabled,
			'production_mutation' => false,
		);
	}

	private static function rollback_partial( array $agent, $created_agent, $subject_created, $subject_fingerprint, array $grant_ids, array $config_before, $agent_status_before, WP_Error $error ) {
		$rollback_errors = array();
		foreach ( array_reverse( $grant_ids ) as $grant_id ) {
			$result = MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id( $agent['public_id'], (int) $grant_id );
			if ( is_wp_error( $result ) ) $rollback_errors[] = $result->get_error_code() . ':grant:' . (int) $grant_id;
		}
		if ( $subject_created && preg_match( '/^[a-f0-9]{64}$/', (string) $subject_fingerprint ) ) {
			$result = MAD4B_SCP_Agent_Registry::set_subject_status( $agent['public_id'], self::SUBJECT_TYPE, $subject_fingerprint, 'disabled' );
			if ( is_wp_error( $result ) ) $rollback_errors[] = $result->get_error_code() . ':subject';
		}
		if ( $created_agent ) {
			$current = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent['public_id'] );
			if ( is_array( $current ) && 'disabled' !== (string) $current['status'] ) {
				$result = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $current['revision'] );
				if ( is_wp_error( $result ) ) $rollback_errors[] = $result->get_error_code() . ':agent';
			}
		} elseif ( 'disabled' === (string) $agent_status_before ) {
			$current = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $agent['public_id'] );
			if ( is_array( $current ) && 'enabled' === (string) $current['status'] ) {
				$result = MAD4B_SCP_Agent_Registry::disable_agent( $agent['public_id'], (int) $current['revision'] );
				if ( is_wp_error( $result ) ) $rollback_errors[] = $result->get_error_code() . ':agent-status';
			}
		}
		self::restore_configuration( $config_before );
		if ( $rollback_errors ) self::force_fail_closed( 'rollback_incomplete' );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/developer-authority-rollback', array(
				'contract' => self::CONTRACT,
				'agent_public_id' => isset( $agent['public_id'] ) ? (string) $agent['public_id'] : '',
				'cause' => $error->get_error_code(),
				'rollback_errors' => $rollback_errors,
				'kill_switch_enabled' => class_exists( 'MAD4B_SCP_Developer_Runtime' ) && MAD4B_SCP_Developer_Runtime::kill_switch_enabled(),
				'production_mutation' => false,
			), empty( $rollback_errors ) ? 'ok' : 'partial' );
		}
		return new WP_Error(
			'mad4b_developer_authority_apply_failed',
			'Developer authority apply failed and bounded rollback was attempted.',
			array( 'cause' => $error->get_error_code(), 'rollback_errors' => $rollback_errors )
		);
	}

	private static function configuration_snapshot() {
		return array(
			'agent_public_id' => get_option( 'mad4b_scp_developer_agent_public_id', null ),
			'enabled' => get_option( 'mad4b_scp_developer_enabled', null ),
			'direct_execution_enabled' => get_option( 'mad4b_scp_developer_direct_execution_enabled', null ),
			'breakglass_enabled' => get_option( 'mad4b_scp_developer_breakglass_enabled', null ),
			'kill_switch' => get_option( 'mad4b_scp_developer_kill_switch', null ),
		);
	}

	private static function restore_configuration( array $snapshot ) {
		$map = array(
			'agent_public_id' => 'mad4b_scp_developer_agent_public_id',
			'enabled' => 'mad4b_scp_developer_enabled',
			'direct_execution_enabled' => 'mad4b_scp_developer_direct_execution_enabled',
			'breakglass_enabled' => 'mad4b_scp_developer_breakglass_enabled',
			'kill_switch' => 'mad4b_scp_developer_kill_switch',
		);
		foreach ( $map as $key => $option ) {
			if ( ! array_key_exists( $key, $snapshot ) || null === $snapshot[ $key ] ) delete_option( $option );
			else update_option( $option, $snapshot[ $key ], false );
		}
	}

	private static function force_fail_closed( $reason ) {
		update_option( 'mad4b_scp_developer_kill_switch', '1', false );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/developer-authority-fail-closed', array(
				'contract' => self::CONTRACT,
				'reason' => sanitize_key( (string) $reason ),
				'kill_switch_enabled' => true,
				'production_mutation' => false,
			), 'blocked' );
		}
	}

	private static function match_expected_plan( array $plan, array $input ) {
		$checks = array(
			'plan_sha256' => strtolower( trim( (string) $input['expected_plan_sha256'] ) ),
			'source_commit_sha' => strtolower( trim( (string) $input['expected_source_commit_sha'] ) ),
			'site_uuid' => strtolower( trim( (string) $input['expected_site_uuid'] ) ),
			'environment' => sanitize_key( (string) $input['expected_environment'] ),
			'site_profile_digest' => strtolower( trim( (string) $input['expected_profile_digest'] ) ),
		);
		foreach ( $checks as $field => $expected ) {
			$current = isset( $plan[ $field ] ) ? strtolower( trim( (string) $plan[ $field ] ) ) : '';
			if ( '' === $expected || ! hash_equals( $current, $expected ) ) return new WP_Error( 'mad4b_developer_authority_plan_stale', 'Developer authority plan identity changed before apply.', array( 'field' => $field ) );
		}
		if ( (int) $plan['site_profile_revision'] !== absint( $input['expected_profile_revision'] ) ) return new WP_Error( 'mad4b_developer_authority_plan_stale', 'Site Profile revision changed before Developer authority apply.', array( 'field' => 'site_profile_revision' ) );
		return true;
	}

	private static function plan_sha256( array $plan ) {
		unset( $plan['plan_sha256'] );
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function identity_has_developer_derivation_material( array $identity ) {
		$subject = isset( $identity['subject_fingerprint'] ) ? strtolower( trim( (string) $identity['subject_fingerprint'] ) ) : '';
		$client = isset( $identity['client_fingerprint'] ) ? strtolower( trim( (string) $identity['client_fingerprint'] ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $subject ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $client );
	}

	public static function derived_subject_fingerprint( array $identity ) {
		if ( ! self::identity_has_developer_derivation_material( $identity ) ) return new WP_Error( 'mad4b_developer_authority_identity_material_invalid', 'Developer identity derivation requires exact subject and client fingerprints.' );
		return hash( 'sha256', 'oauth-developer' . "\0" . strtolower( (string) $identity['subject_fingerprint'] ) . "\0" . strtolower( (string) $identity['client_fingerprint'] ) );
	}

	private static function configured_or_slug_agent() {
		if ( ! class_exists( 'MAD4B_SCP_Agent_Registry' ) ) return null;
		$configured = class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::configured_agent_public_id() : '';
		if ( '' !== $configured ) {
			$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $configured );
			if ( is_array( $agent ) ) return $agent;
		}
		return method_exists( 'MAD4B_SCP_Agent_Registry', 'get_agent_by_slug' ) ? MAD4B_SCP_Agent_Registry::get_agent_by_slug( self::AGENT_SLUG ) : null;
	}

	private static function normal_tools() {
		return class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( false ) : array();
	}

	private static function breakglass_tools() {
		return class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( true ) : array();
	}

	private static function grant_status( $agent, $server_id, array $tools ) {
		$environment = class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : 'unknown';
		$exact = 0; $missing = array(); $unexpected = array();
		if ( ! is_array( $agent ) ) {
			$missing = $tools;
		} else {
			foreach ( $tools as $ability ) {
				$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( $server_id, $ability ) : null;
				if ( null === $provider ) { $missing[] = $ability; continue; }
				$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability, $provider );
				if ( is_array( $grant ) && 'allow' === (string) $grant['effect'] && $environment === (string) $grant['environment'] ) ++$exact;
				else $missing[] = $ability;
			}
			$desired = array_fill_keys( $tools, true );
			foreach ( MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], $server_id ) as $grant ) {
				if ( 'allow' !== (string) $grant['effect'] ) continue;
				if ( ! isset( $desired[ (string) $grant['ability_name'] ] ) ) $unexpected[] = (string) $grant['ability_name'];
			}
		}
		return array(
			'server_id' => $server_id,
			'tool_count' => count( $tools ),
			'exact_grant_count' => $exact,
			'missing' => array_values( array_unique( $missing ) ),
			'unexpected_allow_grants' => array_values( array_unique( $unexpected ) ),
			'ready' => is_array( $agent ) && count( $tools ) === $exact && empty( $missing ) && empty( $unexpected ),
		);
	}

	private static function provenance() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_status' ) ) return new WP_Error( 'mad4b_developer_authority_provenance_unavailable', 'Build provenance authority is unavailable.' );
		$provenance = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
		if ( ! is_array( $provenance ) || empty( $provenance['manifest_present'] ) || empty( $provenance['manifest_valid'] ) || empty( $provenance['runtime_manifest_match'] ) || ! empty( $provenance['stale'] ) || ! empty( $provenance['provenance_mismatch'] ) ) return new WP_Error( 'mad4b_developer_authority_provenance_not_ready', 'Exact current build provenance is not ready.' );
		$sha = isset( $provenance['source_commit_sha'] ) ? strtolower( (string) $provenance['source_commit_sha'] ) : '';
		$fingerprint = isset( $provenance['build_fingerprint'] ) ? strtolower( (string) $provenance['build_fingerprint'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $sha ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) return new WP_Error( 'mad4b_developer_authority_provenance_invalid', 'Build provenance identity is malformed.' );
		return array( 'source_commit_sha' => $sha, 'build_fingerprint' => $fingerprint );
	}
}

MAD4B_SCP_Developer_Authority::boot();
