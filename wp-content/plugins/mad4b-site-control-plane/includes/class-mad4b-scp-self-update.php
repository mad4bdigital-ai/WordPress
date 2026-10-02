<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Multi-channel, fail-closed self update for MAD4B Site Control Plane.
 *
 * Channel A: WordPress-native manual plugin update from a repository-owned,
 * Release-Verdict-gated immutable package.
 *
 * Channel B: governed file upload + apply ability. The caller supplies package
 * bytes only (base64) plus exact identity metadata. Caller URLs and filesystem
 * paths are never accepted.
 *
 * Channel C: governed native release pull. The caller supplies no package URL,
 * filesystem path, archive bytes, target SHA, or version. The target is derived
 * exclusively from the fixed repository release manifest, revalidated at apply,
 * downloaded into protected MAD4B storage, then passed through the same exact
 * archive/provenance verifier.
 *
 * All MAD4B-owned update channels share the same backup/readback/rollback semantics.
 * WordPress automatic-update state is observed read-only and remains outside this
 * coordinator's authority. MAD4B never publishes a core update transient/package
 * offer and never opts itself into core automatic updates; every MAD4B-owned
 * package action uses the governed manifest, exact-build verification, backup,
 * readback and rollback path below.
 */
final class MAD4B_SCP_Self_Update {
	const CONTRACT              = 'mad4b.control-plane-self-update.v1';
	const PLAN_CONTRACT         = 'mad4b.control-plane-upload-plan.v1';
	const APPLY_CONTRACT        = 'mad4b.control-plane-upload-apply.v1';
	const NATIVE_PLAN_CONTRACT  = 'mad4b.control-plane-native-plan.v1';
	const NATIVE_APPLY_CONTRACT = 'mad4b.control-plane-native-apply.v1';
	const BOOTSTRAP_APPLY_CONTRACT = 'mad4b.control-plane-bootstrap-apply.v1';
	const BOOTSTRAP_APPLY_ABILITY  = 'mad4b/control-plane-bootstrap-apply';
	const BOOTSTRAP_CONFIRMATION   = 'APPLY EXACT STAGING CONTROL PLANE BOOTSTRAP UPDATE';
	const MANIFEST_CONTRACT     = 'mad4b.control-plane-update-channel.v1';
	const POINTER_CONTRACT      = 'mad4b.control-plane-update-pointer.v1';
	const RELEASE_TAG           = 'mad4b-site-control-plane-update-channel';
	const POINTER_URL           = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update-pointer.json';
	const MANIFEST_URL          = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update.json';
	const MAX_UPLOAD_BYTES      = 16777216; // 16 MiB decoded.
	const POINTER_CACHE_TTL     = 300;
	const MANIFEST_CACHE_TTL    = 86400;
	const POINTER_TRANSIENT     = 'mad4b_scp_update_pointer_v1';
	const MANIFEST_TRANSIENT    = 'mad4b_scp_update_manifest_v1'; // Legacy/cache-only compatibility slot.
	const MANIFEST_TRANSIENT_PREFIX = 'mad4b_scp_update_manifest_sha_';
	const RECOVERY_CRON_HOOK      = 'mad4b_control_plane_recovery_update';
	const RECOVERY_STATUS_OPTION  = 'mad4b_scp_recovery_update_status_v1';

	private static $booted = false;
	private static $managed_apply = false;

	/** True only while the governed self-update owns WordPress Plugin_Upgrader. */
	public static function managed_apply_in_progress() {
		return (bool) self::$managed_apply;
	}
	private static $rendered_update_rows = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_filter( 'mad4b_scp_authorization_input', array( __CLASS__, 'authorization_input' ), 20, 3 );

		// WordPress-admin update channel. Keep the exact plugin hook for the
		// ordinary installation path, and add a generic realpath-bound fallback
		// for deployments where WordPress exposes the same plugin through a
		// different basename (for example a renamed/symlinked release directory).
		// This still does not alter WordPress core update transients or automatic
		// update routines; every visible update action routes through the governed
		// manifest verifier and rollback/readback path below.
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		add_filter( 'plugin_action_links_' . $plugin, array( __CLASS__, 'plugin_action_links' ), 20, 1 );
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links_fallback' ), 20, 4 );
		add_filter( 'plugin_auto_update_setting_html', array( __CLASS__, 'plugin_auto_update_setting_html' ), 20, 3 );
		add_action( 'after_plugin_row_' . $plugin, array( __CLASS__, 'render_update_row' ), 10, 3 );
		add_action( 'after_plugin_row', array( __CLASS__, 'render_update_row_fallback' ), 10, 3 );
		add_action( 'admin_post_mad4b_control_plane_native_update', array( __CLASS__, 'handle_native_update' ) );
		add_action( 'admin_post_mad4b_control_plane_refresh_update', array( __CLASS__, 'handle_refresh_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'native_update_notice' ) );

		// Forward recovery path: exact governed Staging may refresh the signed,
		// immutable release channel from WP-Cron without depending on a healthy MCP
		// transport or an interactive wp-admin request. The cron is never scheduled
		// from an MCP/OAuth protocol hotpath and is never eligible in Production.
		add_action( self::RECOVERY_CRON_HOOK, array( __CLASS__, 'run_recovery_update' ) );
		add_action( 'init', array( __CLASS__, 'ensure_recovery_update_schedule' ), 40 );
	}


	public static function ensure_recovery_update_schedule() {
		if ( ! self::recovery_update_eligible() ) return;
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& ( MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath()
				|| MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath() ) ) return;
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) return;
		if ( wp_next_scheduled( self::RECOVERY_CRON_HOOK ) ) return;
		wp_schedule_event( time() + 300, 'hourly', self::RECOVERY_CRON_HOOK );
	}

	public static function run_recovery_update() {
		$status = array(
			'contract' => 'mad4b.control-plane-recovery-update.v1',
			'checked_at' => gmdate( 'c' ),
			'eligible' => false,
			'update_available' => false,
			'applied' => false,
			'state' => 'ineligible',
			'blocker' => '',
			'automatic_mutation_retry_allowed' => false,
			'preflight_recheck_allowed' => true,
			'operator_action_required' => false,
			'production_mutation_performed' => false,
		);
		if ( ! self::recovery_update_eligible() ) {
			$status['blocker'] = 'governed_staging_managed_runtime_required';
			// A stale cron event may survive a Staging -> Production environment
			// transition. Fail closed without writing even diagnostic state so this
			// recovery lane remains literally mutation-free outside eligible Staging.
			return $status;
		}
		$status['eligible'] = true;

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) {
			$status['state'] = 'manifest_unavailable';
			$status['blocker'] = $manifest->get_error_code();
			self::persist_recovery_status( $status );
			return $status;
		}
		if ( ! self::update_available( $manifest ) ) {
			$status['state'] = 'current';
			self::persist_recovery_status( $status );
			return $status;
		}
		$status['update_available'] = true;
		$status['target'] = self::public_manifest( $manifest );

		$maintenance = self::maintenance_preflight( 'governed_staging_recovery_cron' );
		if ( is_wp_error( $maintenance ) ) {
			$data = method_exists( $maintenance, 'get_error_data' ) ? $maintenance->get_error_data() : array();
			$data = is_array( $data ) ? $data : array();
			$status = array_merge( $status, $data );
			$status['state'] = ! empty( $data['operator_action_required'] ) ? 'operator_action_required' : 'deferred_runtime_maintenance';
			$status['blocker'] = $maintenance->get_error_code();
			$status['operator_action_required'] = ! empty( $data['operator_action_required'] );
			self::persist_recovery_status( $status );
			return $status;
		}
		$continuation_policy = self::post_update_continuation_policy();
		if ( is_wp_error( $continuation_policy ) ) {
			$status['state'] = 'operator_action_required';
			$status['blocker'] = $continuation_policy->get_error_code();
			$status['operator_action_required'] = true;
			self::persist_recovery_status( $status );
			return $status;
		}
		$status['continuation_mode'] = isset( $continuation_policy['mode'] ) ? sanitize_key( (string) $continuation_policy['mode'] ) : '';

		$tmp = self::download_governed_release_to_protected_storage( $manifest );
		if ( is_wp_error( $tmp ) ) {
			$status['state'] = 'download_failed';
			$status['blocker'] = $tmp->get_error_code();
			self::persist_recovery_status( $status );
			return $status;
		}
		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$status['state'] = 'verification_failed';
			$status['blocker'] = $verified->get_error_code();
			self::persist_recovery_status( $status );
			return $status;
		}

		$result = self::apply_verified_archive( $tmp, $manifest, 'governed_staging_recovery_cron', '', $verified );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $result ) ) {
			$status['state'] = 'apply_failed';
			$status['blocker'] = $result->get_error_code();
			self::persist_recovery_status( $status );
			return $status;
		}
		$status['applied'] = true;
		$status['state'] = 'updated';
		self::persist_recovery_status( $status );
		return $status;
	}

	private static function recovery_update_eligible() {
		if ( ! self::environment_allowed( true ) ) return false;
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return false;
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() ) return false;
		if ( ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) return false;
		return 'staging' === sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
	}

	private static function persist_recovery_status( array $status ) {
		if ( function_exists( 'update_option' ) ) update_option( self::RECOVERY_STATUS_OPTION, $status, false );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		self::register_ability(
			'mad4b/control-plane-update-status',
			'MAD4B Control Plane Update Status',
			'status',
			array( 'type' => 'object', 'additionalProperties' => false ),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-upload-plan',
			'Plan MAD4B Control Plane File Upload Update',
			'upload_plan',
			self::plan_schema(),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-upload-apply',
			'Upload and Apply MAD4B Control Plane ZIP',
			'upload_apply',
			self::apply_schema(),
			false,
			array( __CLASS__, 'can_upload_apply' )
		);

		self::register_ability(
			'mad4b/control-plane-native-plan',
			'Plan Governed Native MAD4B Control Plane Update',
			'native_plan',
			self::native_plan_schema(),
			true,
			array( 'MAD4B_SCP_Policy', 'can_read' )
		);

		self::register_ability(
			'mad4b/control-plane-native-apply',
			'Apply Governed Native MAD4B Control Plane Update',
			'native_apply',
			self::native_apply_schema(),
			false,
			array( __CLASS__, 'can_native_apply' )
		);

		self::register_ability(
			self::BOOTSTRAP_APPLY_ABILITY,
			'Bootstrap Exact MAD4B Control Plane Update',
			'bootstrap_native_apply',
			self::bootstrap_apply_schema(),
			false,
			array( __CLASS__, 'can_bootstrap_native_apply' ),
			'enrollment',
			array(
				'mad4b_control_plane_bootstrap' => self::BOOTSTRAP_APPLY_CONTRACT,
				'bootstrap_only' => true,
				'normal_write_authority_required' => false,
				'authority_mutation_allowed' => false,
				'production_allowed' => false,
				'generic_raw_sql_breakglass_included' => false,
				'chatgpt_direct_step_up' => true,
				'exact_chatgpt_client_required' => true,
			)
		);
	}

	private static function register_ability( $name, $label, $method, $schema, $readonly, $permission, $surface = '', array $extra_mcp = array() ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		$surface = '' !== (string) $surface ? sanitize_key( (string) $surface ) : ( $readonly ? 'read' : 'admin' );
		$mcp = array_merge(
			array( 'public' => false, 'type' => 'tool', 'surface' => $surface ),
			$extra_mcp
		);
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the fail-closed multi-channel MAD4B self-update coordinator.',
				'category' => $readonly ? 'mad4b-read' : 'mad4b-admin',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => $permission,
				'input_schema' => $schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => $mcp,
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => ! $readonly,
						'idempotent' => (bool) $readonly,
					),
				),
			)
		);
	}

	public static function authorization_input( $clean, $ability_name, $original = null ) {
		unset( $original );
		if ( 'mad4b/control-plane-upload-apply' !== (string) $ability_name || ! is_array( $clean ) ) return $clean;
		unset( $clean['package_base64'] );
		$clean['package_transport'] = 'bounded_base64_zip';
		return $clean;
	}

	public static function can_upload_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( ! self::environment_allowed( true ) ) return new WP_Error( 'mad4b_self_update_staging_only', 'Remote Control Plane file upload is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation(
			'mad4b/control-plane-upload-apply',
			'mad4b-admin',
			'core',
			is_array( $input ) ? $input : array()
		);
	}

	public static function can_native_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( ! self::environment_allowed( true ) ) return new WP_Error( 'mad4b_self_update_staging_only', 'Remote Control Plane native release pull is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation(
			'mad4b/control-plane-native-apply',
			'mad4b-admin',
			'core',
			is_array( $input ) ? $input : array()
		);
	}

	/**
	 * Project only the bootstrap self-update mutation onto the ChatGPT step-up
	 * scope while normal Write Authority is fail-closed on candidate drift.
	 * Catalog construction is network-free and performs no mutation.
	 */
	public static function chatgpt_step_up_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return array();
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
		if ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) return array();
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective() ) return array();
		return array( self::BOOTSTRAP_APPLY_ABILITY );
	}

	public static function can_bootstrap_native_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_bearer_required', 'Bootstrap Control Plane self-update requires a verified OAuth bearer.' );
		}
		if ( ! defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_step_up_scope_required', 'Bootstrap Control Plane self-update requires the dedicated Staging authority step-up scope.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_chatgpt_client_required', 'Bootstrap Control Plane self-update requires OAuth attribution to the exact ChatGPT CIMD client.' );
		}
		if ( ! self::environment_allowed( true ) ) return new WP_Error( 'mad4b_self_update_bootstrap_staging_only', 'Bootstrap Control Plane self-update is Staging-only.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_profile_not_exact', 'Bootstrap Control Plane self-update requires the exact enrolled Staging Site Profile.' );
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_subject_not_enrolled', 'Bootstrap Control Plane self-update requires the enrolled administrator.' );
		}
		// Authentication/scope/client admission precedes runtime eligibility; both remain mandatory.
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		if ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_breakglass_denied', 'Generic raw-SQL Breakglass must remain disabled during bootstrap self-update.' );
		}
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_breakglass_active', 'Bootstrap Control Plane self-update is unavailable while generic Breakglass is active.' );
		}
		$bootstrap_policy = self::bootstrap_candidate_drift_policy();
		if ( empty( $bootstrap_policy['eligible'] ) ) {
			return new WP_Error(
				'mad4b_self_update_bootstrap_authority_not_clean',
				'Bootstrap self-update is allowed only for clean exact grants plus stale candidate binding.',
				array(
					'blockers' => isset( $bootstrap_policy['blockers'] ) ? $bootstrap_policy['blockers'] : array( 'bootstrap_policy_unavailable' ),
					'bootstrap_policy' => $bootstrap_policy,
				)
			);
		}
		return true;
	}


	private static function bootstrap_candidate_drift_policy() {
		$out = array(
			'contract' => 'mad4b.self-update-bootstrap-candidate-drift-policy.v1',
			'eligible' => false,
			'mode' => 'candidate_drift_only',
			'blockers' => array(),
			'environment' => '',
			'authority_checkpoint_exists' => false,
			'prior_authority_effective' => null,
			'candidate_binding_required' => null,
			'candidate_binding_match' => null,
			'authority_carry_forward' => false,
			'authority_mutation_allowed' => false,
			'grant_mutation_allowed' => false,
			'candidate_binding_mutation_allowed' => false,
			'post_update_candidate_rebind_required' => true,
			'automatic_mutation_retry_allowed' => false,
			'production_mutation_allowed' => false,
			'breakglass_allowed' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
		$blockers = array();

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) {
			$blockers[] = 'site_profile_unconfigured';
		} else {
			$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
			$out['environment'] = $environment;
			if ( 'staging' !== $environment ) $blockers[] = 'environment_not_staging';
			if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) $blockers[] = 'site_profile_not_exact';
			if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) $blockers[] = 'site_profile_write_disabled';
		}
		if ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) $blockers[] = 'breakglass_enabled';
		if ( class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass() ) $blockers[] = 'breakglass_active';

		if ( ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'persistence_checkpoint' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'reconciliation_plan' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'effective' ) ) {
			$blockers[] = 'write_authority_runtime_unavailable';
		} else {
			$checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();
			$out['authority_checkpoint_exists'] = is_array( $checkpoint ) && ! empty( $checkpoint['exists'] );
			if ( ! is_array( $checkpoint )
				|| 'mad4b.governed-write-authority-persistence-checkpoint.v1' !== ( isset( $checkpoint['contract'] ) ? (string) $checkpoint['contract'] : '' )
				|| empty( $checkpoint['exists'] ) ) {
				$blockers[] = 'prior_authority_checkpoint_required';
			} else {
				$checkpoint_status = isset( $checkpoint['status'] ) && is_array( $checkpoint['status'] ) ? $checkpoint['status'] : array();
				if ( empty( $checkpoint_status['ready'] )
					|| 'ready' !== ( isset( $checkpoint_status['state'] ) ? (string) $checkpoint_status['state'] : '' )
					|| ! empty( $checkpoint_status['blocker'] )
					|| empty( $checkpoint_status['write_inventory_fingerprint'] )
					|| 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $checkpoint_status['write_inventory_fingerprint'] ) ) ) {
					$blockers[] = 'persisted_authority_not_clean';
				}
			}

			$effective = MAD4B_SCP_Staging_Write_Authority::effective();
			if ( ! is_bool( $effective ) ) {
				$blockers[] = 'authority_effective_state_invalid';
			} else {
				$out['prior_authority_effective'] = $effective;
				if ( $effective ) $blockers[] = 'prior_authority_effective_use_normal_path';
			}

			$plan = MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
			if ( is_wp_error( $plan ) || ! is_array( $plan ) ) {
				$blockers[] = 'write_authority_plan_unavailable';
			} else {
				if ( empty( $plan['eligible'] ) || empty( $plan['current_ready'] ) || empty( $plan['agent_present'] ) ) $blockers[] = 'write_grants_not_clean';
				foreach ( array(
					'exact_grants_missing_count',
					'stale_allow_grants_count',
					'unreviewed_stale_allow_grants_count',
					'broad_environment_grants_count',
					'duplicate_exact_allow_grants_count',
					'current_agent_wildcard_grants',
					'global_registry_wildcard_grants',
				) as $field ) {
					$value = isset( $plan[ $field ] ) ? $plan[ $field ] : 0;
					$count = is_array( $value ) ? count( $value ) : (int) $value;
					if ( $count > 0 ) $blockers[] = $field;
				}
				if ( ! empty( $plan['grant_blockers'] ) ) $blockers[] = 'grant_blockers_present';
				foreach ( array( 'write_inventory_fingerprint', 'grant_rows_fingerprint', 'persisted_grant_records_fingerprint' ) as $field ) {
					if ( empty( $plan[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $plan[ $field ] ) ) ) $blockers[] = 'invalid_' . $field;
				}
			}

			$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
			if ( ! is_array( $binding ) || ! isset( $binding['required'], $binding['match'] ) || ! is_bool( $binding['required'] ) || ! is_bool( $binding['match'] ) ) {
				$blockers[] = 'candidate_binding_state_invalid';
			} else {
				$out['candidate_binding_required'] = $binding['required'];
				$out['candidate_binding_match'] = $binding['match'];
				if ( ! $binding['required'] || $binding['match'] ) $blockers[] = 'candidate_drift_not_the_bootstrap_blocker';
				foreach ( array(
					'current_source_commit_sha' => 40,
					'current_build_fingerprint' => 64,
					'current_package_manifest_digest' => 64,
				) as $field => $length ) {
					$value = isset( $binding[ $field ] ) ? strtolower( trim( (string) $binding[ $field ] ) ) : '';
					if ( 1 !== preg_match( '/^[a-f0-9]{' . (int) $length . '}$/', $value ) ) $blockers[] = 'current_candidate_identity_invalid:' . $field;
				}
				$artifact = isset( $binding['current_artifact_identity'] ) ? trim( (string) $binding['current_artifact_identity'] ) : '';
				if ( '' === $artifact || strlen( $artifact ) > 191 || 1 !== preg_match( '/^mad4b-site-control-plane-[A-Za-z0-9._-]+-[A-Fa-f0-9]{40}$/', $artifact ) ) {
					$blockers[] = 'current_candidate_identity_invalid:artifact_identity';
				}
			}
		}

		$out['blockers'] = array_values( array_unique( array_map( 'sanitize_key', $blockers ) ) );
		sort( $out['blockers'], SORT_STRING );
		$out['eligible'] = empty( $out['blockers'] );
		return $out;
	}

	public static function status( $input = array() ) {
		unset( $input );
		$current = self::installed_identity();
		$manifest = self::fetch_manifest();
		$manifest_error = is_wp_error( $manifest ) ? $manifest->get_error_code() : '';
		$native_ready = ! is_wp_error( $manifest ) && self::environment_allowed( false );
		$remote_ready = self::environment_allowed( true ) && current_user_can( 'update_plugins' );
		$ui_state = self::native_update_ui_state( $manifest );
		$auto_update = self::wordpress_auto_update_state();
		$environment_resolution = self::environment_resolution();
		$normal_write_effective = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		$bootstrap_step_up_available = in_array( self::BOOTSTRAP_APPLY_ABILITY, self::chatgpt_step_up_tools(), true );
		$maintenance_projection = self::maintenance_status_projection();
		$continuation_projection = self::continuation_policy_projection();
		$bootstrap_drift_policy = self::bootstrap_candidate_drift_policy();
		$bootstrap_drift_eligible = ! empty( $bootstrap_drift_policy['eligible'] )
			&& ! empty( $continuation_projection['blocked'] )
			&& 'mad4b_self_update_continuation_prior_authority_drift' === ( isset( $continuation_projection['blocker'] ) ? (string) $continuation_projection['blocker'] : '' );
		$apply_preflight_ready = ! empty( $maintenance_projection['safe_to_acquire'] )
			&& ( empty( $continuation_projection['blocked'] ) || $bootstrap_drift_eligible );

		return array(
			'contract' => self::CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'current' => $current,
			'native_wordpress_update' => array(
				'ready' => (bool) $native_ready,
				'environment_resolution' => $environment_resolution,
				'surface' => 'wp_admin_plugins_page',
				'manual_only' => true,
				'manual_only_scope' => 'mad4b_governed_native_action',
				'governed_action_manual_only' => true,
				'wordpress_core_auto_update_observed' => (bool) $auto_update['effective_enabled'],
				'wordpress_core_auto_update_governed' => false,
				'admin_update_capability' => (bool) current_user_can( 'update_plugins' ),
				'ui_hook_mode' => 'exact_hook_plus_realpath_fallback',
				'ui_state' => $ui_state['state'],
				'ui_blockers' => $ui_state['blockers'],
				'apply_preflight_ready' => (bool) $apply_preflight_ready,
				'maintenance_preflight' => $maintenance_projection,
				'continuation_policy' => $continuation_projection,
				'bootstrap_candidate_drift' => $bootstrap_drift_policy,
				'apply_mode' => $bootstrap_drift_eligible ? 'local_admin_candidate_drift_bootstrap' : 'normal',
				'modifies_core_update_transients' => false,
				'automatic_update_enabled' => (bool) $auto_update['effective_enabled'],
				'automatic_update_observation' => $auto_update,
				'manifest_url' => self::MANIFEST_URL,
				'pointer_url' => self::POINTER_URL,
				'release_channel_entrypoint' => 'pointer_first',
				'manifest_resolution' => is_wp_error( $manifest ) ? 'unavailable' : self::manifest_resolution( $manifest ),
				'manifest_state' => is_wp_error( $manifest ) ? 'unavailable' : 'ready',
				'manifest_error' => $manifest_error,
				'target' => is_wp_error( $manifest ) ? array() : self::public_manifest( $manifest ),
			),
			'governed_file_upload' => array(
				'ready' => (bool) $remote_ready,
				'staging_only' => true,
				'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
				'caller_url_allowed' => false,
				'caller_path_allowed' => false,
				'exact_plan_required' => true,
				'exact_approval_required' => true,
				'rollback_required' => true,
			),
			'bootstrap_step_up' => array(
				'contract' => self::BOOTSTRAP_APPLY_CONTRACT,
				'ability' => self::BOOTSTRAP_APPLY_ABILITY,
				'available' => (bool) $bootstrap_step_up_available,
				'staging_only' => true,
				'oauth_scope' => 'mad4b:authority:step-up',
				'bootstrap_only' => true,
				'normal_write_authority_effective' => (bool) $normal_write_effective,
				'requires_clean_exact_grants' => true,
				'requires_candidate_drift' => true,
				'authority_mutation_allowed' => false,
				'production_allowed' => false,
				'generic_raw_sql_breakglass_included' => false,
			),
			'governed_native_release_pull' => array(
				'ready' => (bool) ( $remote_ready && ! is_wp_error( $manifest ) ),
				'staging_only' => true,
				'fixed_manifest_url' => self::MANIFEST_URL,
				'fixed_pointer_url' => self::POINTER_URL,
				'pointer_first' => true,
				'legacy_fallback_policy' => 'network_or_http_unavailable_only',
				'caller_url_allowed' => false,
				'caller_path_allowed' => false,
				'caller_package_bytes_allowed' => false,
				'target_derived_from_release_manifest' => true,
				'exact_plan_required' => true,
				'exact_approval_required' => true,
				'archive_integrity_required' => true,
				'embedded_provenance_required' => true,
				'rollback_required' => true,
				'release_channel_bound' => true,
			),
			'production_remote_upload_allowed' => false,
			'production_remote_native_pull_allowed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function cached_status( $input = array() ) {
		unset( $input );
		$current = self::installed_identity();
		$manifest = self::cached_manifest();
		$manifest_error = is_wp_error( $manifest ) ? $manifest->get_error_code() : '';
		$native_ready = ! is_wp_error( $manifest ) && self::environment_allowed( false );
		$remote_ready = self::environment_allowed( true ) && current_user_can( 'update_plugins' );
		$ui_state = self::native_update_ui_state( $manifest );
		$auto_update = self::wordpress_auto_update_state();
		$environment_resolution = self::environment_resolution();
		$maintenance_projection = self::maintenance_status_projection();
		$continuation_projection = array(
			'contract' => 'mad4b.self-update-continuation-policy.v1',
			'state' => 'deferred_cache_only',
			'deep_authority_presence_scan_deferred' => true,
			'read_only' => true,
			'mutation_performed' => false,
		);
		$apply_preflight_ready = null;

		return array(
			'contract' => self::CONTRACT,
			'projection' => 'cache_only',
			'outbound_network_performed' => false,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'current' => $current,
			'native_wordpress_update' => array(
				'ready' => (bool) $native_ready,
				'environment_resolution' => $environment_resolution,
				'manual_only' => true,
				'manual_only_scope' => 'mad4b_governed_native_action',
				'governed_action_manual_only' => true,
				'wordpress_core_auto_update_observed' => (bool) $auto_update['effective_enabled'],
				'wordpress_core_auto_update_governed' => false,
				'ui_state' => $ui_state['state'],
				'ui_blockers' => $ui_state['blockers'],
				'apply_preflight_ready' => (bool) $apply_preflight_ready,
				'maintenance_preflight' => $maintenance_projection,
				'continuation_policy' => $continuation_projection,
				'automatic_update_enabled' => (bool) $auto_update['effective_enabled'],
				'automatic_update_observation' => $auto_update,
				'pointer_url' => self::POINTER_URL,
				'release_channel_entrypoint' => 'pointer_first',
				'manifest_resolution' => is_wp_error( $manifest ) ? 'not_cached' : self::manifest_resolution( $manifest ),
				'manifest_state' => is_wp_error( $manifest ) ? 'not_cached' : 'ready',
				'manifest_error' => $manifest_error,
				'target' => is_wp_error( $manifest ) ? array() : self::public_manifest( $manifest ),
			),
			'governed_file_upload' => array( 'ready' => (bool) $remote_ready, 'staging_only' => true ),
			'production_remote_upload_allowed' => false,
			'production_remote_native_pull_allowed' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}

	public static function upload_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$identity = self::normalize_requested_identity( $input );
		if ( is_wp_error( $identity ) ) return $identity;

		$current = self::installed_identity();
		$blockers = array();
		$release_manifest = self::fetch_manifest( true );

		if ( ! self::environment_allowed( true ) ) $blockers[] = 'staging_enrolled_write_profile_required';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( $identity['size_bytes'] < 1 || $identity['size_bytes'] > self::MAX_UPLOAD_BYTES ) $blockers[] = 'archive_size_out_of_bounds';
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $identity['source_commit_sha'] ) ) $blockers[] = 'already_on_exact_source_commit';
		if ( ! empty( $current['version'] ) && version_compare( $current['version'], $identity['version'], '>' ) ) $blockers[] = 'target_version_older_than_runtime';
		if ( is_wp_error( $release_manifest ) ) {
			$blockers[] = 'governed_release_manifest_unavailable';
		} else {
			foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
				if ( ! isset( $release_manifest[ $field ] ) || ! hash_equals( (string) $release_manifest[ $field ], (string) $identity[ $field ] ) ) {
					$blockers[] = 'target_not_current_governed_release:' . $field;
				}
			}
			if ( (int) $release_manifest['size_bytes'] !== (int) $identity['size_bytes'] ) $blockers[] = 'target_not_current_governed_release:size_bytes';
		}

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'operation' => 'replace',
			'channel' => 'governed_file_upload',
			'current' => $current,
			'target' => $identity,
			'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
			'caller_url_allowed' => false,
			'caller_path_allowed' => false,
			'backup_required' => true,
			'activation_state_preserved' => true,
			'archive_integrity_required' => true,
			'embedded_provenance_required' => true,
			'rollback_on_failed_readback' => true,
			'release_channel_bound' => true,
			'release_channel' => is_wp_error( $release_manifest ) ? array() : self::public_manifest( $release_manifest ),
			'production_allowed' => false,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '',
			'mutation_performed' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
		return $plan;
	}

	public static function upload_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_self_update_plan_digest_required', 'expected_plan_sha256 from the reviewed upload plan is required.' );

		$plan_input = $input;
		unset( $plan_input['package_base64'], $plan_input['expected_plan_sha256'], $plan_input['_mad4b_approval_ticket_id'], $plan_input['_mad4b_context_receipt'] );
		$plan = self::upload_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_self_update_plan_changed', 'Control Plane upload plan changed since review.', array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected ) );
		}
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_self_update_preflight_blocked', 'Control Plane upload preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$encoded = isset( $input['package_base64'] ) ? preg_replace( '/\s+/', '', (string) $input['package_base64'] ) : '';
		if ( '' === $encoded ) return new WP_Error( 'mad4b_self_update_file_required', 'package_base64 is required.' );
		if ( strlen( $encoded ) > (int) ceil( self::MAX_UPLOAD_BYTES * 4 / 3 ) + 16 ) return new WP_Error( 'mad4b_self_update_encoded_payload_too_large', 'Encoded package exceeds the bounded upload budget.' );

		$bytes = base64_decode( $encoded, true );
		unset( $encoded );
		if ( false === $bytes ) return new WP_Error( 'mad4b_self_update_base64_invalid', 'Uploaded package is not valid strict base64.' );
		if ( strlen( $bytes ) !== (int) $plan['target']['size_bytes'] ) return new WP_Error( 'mad4b_self_update_size_mismatch', 'Uploaded package size does not match the reviewed plan.' );
		$actual_sha = hash( 'sha256', $bytes );
		if ( ! hash_equals( $plan['target']['archive_sha256'], $actual_sha ) ) return new WP_Error( 'mad4b_self_update_archive_hash_mismatch', 'Uploaded package SHA-256 does not match the reviewed plan.' );

		$tmp = self::temp_archive_path();
		if ( is_wp_error( $tmp ) ) return $tmp;
		if ( false === file_put_contents( $tmp, $bytes, LOCK_EX ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_temp_write_failed', 'Unable to stage uploaded package.' );
		}
		unset( $bytes );
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$verified = self::verify_archive( $tmp, $plan['target'] );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $verified;
		}

		$apply_target = array_merge(
			$plan['target'],
			isset( $plan['release_channel'] ) && is_array( $plan['release_channel'] ) ? $plan['release_channel'] : array()
		);
		$result = self::apply_verified_archive( $tmp, $apply_target, 'governed_file_upload', $expected, $verified );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $result;
	}

	public static function native_plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = self::installed_identity();
		$manifest = self::fetch_manifest( true );
		$blockers = array();

		if ( ! self::environment_allowed( true ) ) $blockers[] = 'staging_enrolled_write_profile_required';
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( is_wp_error( $manifest ) ) {
			$blockers[] = $manifest->get_error_code();
			$target = array();
		} else {
			$target = self::public_manifest( $manifest );
			if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $manifest['source_commit_sha'] ) ) $blockers[] = 'already_on_exact_source_commit';
			if ( ! empty( $current['version'] ) && version_compare( $current['version'], $manifest['version'], '>' ) ) $blockers[] = 'target_version_older_than_runtime';
		}

		$plan = array(
			'contract' => self::NATIVE_PLAN_CONTRACT,
			'plugin' => plugin_basename( MAD4B_SCP_FILE ),
			'operation' => 'replace',
			'channel' => 'governed_native_release_pull',
			'current' => $current,
			'target' => $target,
			'fixed_manifest_url' => self::MANIFEST_URL,
			'fixed_pointer_url' => self::POINTER_URL,
			'pointer_first' => true,
			'legacy_fallback_policy' => 'network_or_http_unavailable_only',
			'release_resolution' => is_wp_error( $manifest ) ? 'unavailable' : self::manifest_resolution( $manifest ),
			'caller_url_allowed' => false,
			'caller_path_allowed' => false,
			'caller_package_bytes_allowed' => false,
			'target_derived_from_release_manifest' => true,
			'backup_required' => true,
			'activation_state_preserved' => true,
			'archive_integrity_required' => true,
			'embedded_provenance_required' => true,
			'rollback_on_failed_readback' => true,
			'release_channel_bound' => true,
			'production_allowed' => false,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '',
			'mutation_performed' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
		return $plan;
	}

	public static function native_apply( $input, $bootstrap_revalidate = false ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/' , $expected ) ) return new WP_Error( 'mad4b_self_update_plan_digest_required', 'expected_plan_sha256 from the reviewed native release plan is required.' );

		$plan_input = array( 'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : '' );
		$plan = self::native_plan( $plan_input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected ) ) {
			return new WP_Error( 'mad4b_self_update_plan_changed', 'Control Plane native release plan changed since review.', array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected ) );
		}
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_self_update_preflight_blocked', 'Control Plane native release preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) return $manifest;
		$current_target = self::public_manifest( $manifest );
		foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes' ) as $field ) {
			if ( ! array_key_exists( $field, $plan['target'] ) || ! array_key_exists( $field, $current_target ) || (string) $plan['target'][ $field ] !== (string) $current_target[ $field ] ) {
				return new WP_Error( 'mad4b_self_update_native_release_drift', 'Governed release channel changed after native update planning.', array( 'field' => $field ) );
			}
		}

		$tmp = self::download_governed_release_to_protected_storage( $manifest );
		if ( is_wp_error( $tmp ) ) return $tmp;

		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $verified;
		}

		// Bootstrap authority is intentionally narrow and can become invalid while
		// the governed release is being downloaded and verified. Re-evaluate the
		// live grant/binding state immediately before the filesystem mutation so a
		// concurrent reconcile, grant change, Breakglass enablement, or candidate
		// bind fails closed instead of using a stale pre-download authorization.
		if ( $bootstrap_revalidate ) {
			$bootstrap_access = self::can_bootstrap_native_apply( $input );
			if ( is_wp_error( $bootstrap_access ) || ! $bootstrap_access ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return is_wp_error( $bootstrap_access )
					? $bootstrap_access
					: new WP_Error( 'mad4b_self_update_bootstrap_revalidation_failed', 'Bootstrap Control Plane self-update became ineligible before mutation.' );
			}
		}

		$result = self::apply_verified_archive(
			$tmp,
			$manifest,
			$bootstrap_revalidate ? 'governed_native_release_pull_bootstrap' : 'governed_native_release_pull',
			$expected,
			$verified,
			(bool) $bootstrap_revalidate
		);
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $result;
	}

	public static function bootstrap_native_apply( $input ) {
		$input = is_array( $input ) ? $input : array();
		$access = self::can_bootstrap_native_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		$confirmation = isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '';
		if ( ! hash_equals( self::BOOTSTRAP_CONFIRMATION, $confirmation ) ) {
			return new WP_Error( 'mad4b_self_update_bootstrap_confirmation_required', 'Exact bootstrap self-update confirmation is required.' );
		}
		$result = self::native_apply(
			array(
				'reason' => isset( $input['reason'] ) ? (string) $input['reason'] : '',
				'expected_plan_sha256' => isset( $input['expected_plan_sha256'] ) ? (string) $input['expected_plan_sha256'] : '',
			),
			true
		);
		if ( is_wp_error( $result ) ) return $result;
		if ( is_array( $result ) ) {
			$result['bootstrap_contract'] = self::BOOTSTRAP_APPLY_CONTRACT;
			$result['bootstrap_only'] = true;
			$result['authority_mutation_performed'] = false;
			$result['grant_mutation_performed'] = false;
			$result['developer_authority_mutation_performed'] = false;
			$result['developer_breakglass_mutation_performed'] = false;
			$result['production_mutation_performed'] = false;
		}
		return $result;
	}

	private static function is_control_plane_plugin_file( $plugin_file ) {
		$plugin_file = ltrim( wp_normalize_path( (string) $plugin_file ), '/' );
		if ( '' === $plugin_file ) return false;

		$canonical = wp_normalize_path( MAD4B_SCP_FILE );
		$candidate = defined( 'WP_PLUGIN_DIR' )
			? wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file )
			: '';

		if ( '' !== $candidate && $candidate === $canonical ) return true;

		$candidate_real = '' !== $candidate ? realpath( $candidate ) : false;
		$canonical_real = realpath( MAD4B_SCP_FILE );
		if ( false !== $candidate_real && false !== $canonical_real ) {
			return wp_normalize_path( $candidate_real ) === wp_normalize_path( $canonical_real );
		}

		return plugin_basename( MAD4B_SCP_FILE ) === $plugin_file;
	}

	private static function native_update_action_link( array $links ) {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return $links;

		// Auto-update state belongs in WordPress' native Auto-updates column.
		// Keep the plugin action row limited to actual plugin/update actions.
		// wp-admin/plugins.php is a latency-sensitive render path. Never perform
		// outbound HTTP here; use only a previously verified manifest cache.
		$manifest = self::cached_manifest();
		$ui = self::native_update_ui_state( $manifest );
		if ( 'available' === $ui['state'] && is_array( $manifest ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mad4b_control_plane_native_update' ),
				'mad4b_control_plane_native_update'
			);
			$label = sprintf(
				/* translators: %s: target version. */
				__( 'Update MAD4B to %s', 'mad4b-site-control-plane' ),
				$manifest['display_version']
			);
			$links['mad4b_update'] = '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</a>';
			return $links;
		}

		if ( in_array( $ui['state'], array( 'current', 'manifest_unavailable' ), true ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mad4b_control_plane_refresh_update' ),
				'mad4b_control_plane_refresh_update'
			);
			$label = 'manifest_unavailable' === $ui['state']
				? __( 'Retry MAD4B update check', 'mad4b-site-control-plane' )
				: __( 'Check MAD4B update', 'mad4b-site-control-plane' );
			$links['mad4b_update_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			return $links;
		}

		if ( 'policy_blocked' === $ui['state'] ) {
			$links['mad4b_update_state'] = '<span aria-label="' . esc_attr__( 'MAD4B update policy status', 'mad4b-site-control-plane' ) . '">'
				. esc_html__( 'MAD4B update blocked by policy', 'mad4b-site-control-plane' ) . '</span>';
		}
		return $links;
	}

	private static function native_update_ui_state( $manifest = null ) {
		$blockers = array();
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( ! self::environment_allowed( false ) ) $blockers[] = 'native_update_environment_policy_blocked';
		if ( null === $manifest ) $manifest = self::fetch_manifest();
		if ( is_wp_error( $manifest ) ) {
			$blockers[] = sanitize_key( $manifest->get_error_code() );
			return array( 'state' => 'manifest_unavailable', 'blockers' => array_values( array_unique( $blockers ) ) );
		}
		if ( ! empty( $blockers ) ) return array( 'state' => 'policy_blocked', 'blockers' => array_values( array_unique( $blockers ) ) );
		return array(
			'state' => self::update_available( $manifest ) ? 'available' : 'current',
			'blockers' => array(),
		);
	}

	private static function wordpress_auto_update_state() {
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		$global_enabled = false;
		$global_source = 'unavailable';

		// Mirror wp_is_auto_update_enabled_for_type( 'plugin' ) without mutating
		// WordPress update transients or enrolling this plugin into core updates.
		if ( function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			$global_enabled = (bool) wp_is_auto_update_enabled_for_type( 'plugin' );
			$global_source = 'wp_is_auto_update_enabled_for_type';
		} else {
			if ( ! class_exists( 'WP_Automatic_Updater' ) && defined( 'ABSPATH' ) ) {
				$updater_file = ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
				if ( is_readable( $updater_file ) ) require_once $updater_file;
			}
			if ( class_exists( 'WP_Automatic_Updater' ) ) {
				$updater = new WP_Automatic_Updater();
				$global_enabled = ! $updater->is_disabled();
				$global_enabled = (bool) apply_filters( 'plugins_auto_update_enabled', $global_enabled );
				$global_source = 'automatic_updater_fallback';
			}
		}

		$selected_plugins = (array) get_site_option( 'auto_update_plugins', array() );
		$selected = in_array( $plugin, $selected_plugins, true );

		$metadata = null;
		$metadata_source = 'none';
		$update_info = get_site_transient( 'update_plugins' );
		if ( is_object( $update_info ) ) {
			foreach ( array( 'response', 'no_update' ) as $bucket_name ) {
				$bucket = isset( $update_info->{$bucket_name} ) ? (array) $update_info->{$bucket_name} : array();
				if ( array_key_exists( $plugin, $bucket ) ) {
					$metadata = $bucket[ $plugin ];
					$metadata_source = $bucket_name;
					break;
				}
			}
		}
		$update_supported = null !== $metadata;

		$payload = array(
			'id' => $plugin,
			'slug' => 'mad4b-site-control-plane',
			'plugin' => $plugin,
			'new_version' => '',
			'url' => '',
			'package' => '',
			'icons' => array(),
			'banners' => array(),
			'banners_rtl' => array(),
			'tested' => '',
			'requires_php' => '',
			'compatibility' => new stdClass(),
		);
		if ( is_object( $metadata ) ) {
			$payload = array_merge( $payload, get_object_vars( $metadata ) );
		} elseif ( is_array( $metadata ) ) {
			$payload = array_merge( $payload, $metadata );
		}
		// The exact installed plugin basename is authoritative even if update
		// metadata contains a stale or foreign plugin field.
		$payload['plugin'] = $plugin;
		if ( empty( $payload['id'] ) ) $payload['id'] = $plugin;

		$forced = function_exists( 'wp_is_auto_update_forced_for_item' )
			? wp_is_auto_update_forced_for_item( 'plugin', null, (object) $payload )
			: apply_filters( 'auto_update_' . 'plugin', null, (object) $payload );
		$forced = is_null( $forced ) ? null : (bool) $forced;

		// Match the Plugins list-table preference semantics: a forced decision
		// overrides the stored selection; otherwise the item must be selected and
		// recognized by WordPress update metadata.
		$item_enabled = is_null( $forced ) ? ( $selected && $update_supported ) : $forced;
		$effective_enabled = $global_enabled && $item_enabled;
		$current_offer_present = 'response' === $metadata_source;

		$blockers = array();
		if ( ! $global_enabled ) $blockers[] = 'wordpress_plugin_auto_updates_globally_disabled';
		if ( false === $forced ) {
			$blockers[] = 'wordpress_plugin_auto_update_forced_disabled';
		} elseif ( null === $forced ) {
			if ( ! $selected ) $blockers[] = 'plugin_not_selected_for_auto_update';
			if ( $selected && ! $update_supported ) $blockers[] = 'wordpress_update_metadata_not_supported';
		}
		sort( $blockers, SORT_STRING );

		return array(
			'contract' => 'mad4b.wordpress-plugin-auto-update-observation.v1',
			'plugin' => $plugin,
			'global_type_enabled' => (bool) $global_enabled,
			'global_state_source' => $global_source,
			'selected_in_site_option' => (bool) $selected,
			'forced' => $forced,
			'forced_state' => is_null( $forced ) ? 'not_forced' : ( $forced ? 'forced_enabled' : 'forced_disabled' ),
			'update_metadata_supported' => (bool) $update_supported,
			'update_metadata_source' => $metadata_source,
			'current_update_offer_present' => (bool) $current_offer_present,
			'effective_enabled' => (bool) $effective_enabled,
			'current_offer_auto_update_eligible' => (bool) ( $effective_enabled && $current_offer_present ),
			'filesystem_execution_preflight' => 'deferred_to_wordpress_automatic_updater',
			'mad4b_auto_update_mutation_performed' => false,
			'blockers' => $blockers,
		);
	}

	public static function plugin_auto_update_setting_html( $html, $plugin_file, $plugin_data = array() ) {
		unset( $plugin_data );
		if ( ! self::is_control_plane_plugin_file( $plugin_file ) ) return $html;

		$state = self::wordpress_auto_update_state();
		$title = ! empty( $state['blockers'] )
			? implode( ', ', $state['blockers'] )
			: __( 'WordPress core automatic-update policy is enabled; it is separate from the MAD4B governed release verifier.', 'mad4b-site-control-plane' );

		if ( ! empty( $state['selected_in_site_option'] ) || ! empty( $state['effective_enabled'] ) ) {
			$text = __( 'WordPress auto-update selected · governed update remains manual', 'mad4b-site-control-plane' );
		} else {
			$text = __( 'Governed updates only · automatic update disabled', 'mad4b-site-control-plane' );
		}

		return '<span class="label mad4b-auto-update-state" title="' . esc_attr( $title ) . '">'
			. esc_html( $text ) . '</span>';
	}

	public static function plugin_action_links( $links ) {
		return self::native_update_action_link( is_array( $links ) ? $links : array() );
	}

	public static function plugin_action_links_fallback( $links, $plugin_file, $plugin_data = array(), $context = '' ) {
		unset( $plugin_data, $context );
		$links = is_array( $links ) ? $links : array();
		if ( ! self::is_control_plane_plugin_file( $plugin_file ) ) return $links;
		return self::native_update_action_link( $links );
	}

	public static function render_update_row( $plugin_file, $plugin_data = array(), $status = '' ) {
		unset( $plugin_data, $status );
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return;
		if ( ! self::is_control_plane_plugin_file( $plugin_file ) ) return;
		$row_key = wp_normalize_path( (string) $plugin_file );
		if ( isset( self::$rendered_update_rows[ $row_key ] ) ) return;

		$manifest = self::cached_manifest();
		$ui = self::native_update_ui_state( $manifest );
		if ( 'current' === $ui['state'] ) return;
		self::$rendered_update_rows[ $row_key ] = true;

		if ( 'available' === $ui['state'] && is_array( $manifest ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mad4b_control_plane_native_update' ),
				'mad4b_control_plane_native_update'
			);
			$current = self::installed_identity();
			$current_display = isset( $current['version'] ) ? (string) $current['version'] : '';
			if ( ! empty( $current['source_commit_sha'] ) ) $current_display .= '+build.' . substr( (string) $current['source_commit_sha'], 0, 7 );
			$target_display = isset( $manifest['display_version'] ) ? (string) $manifest['display_version'] : (string) $manifest['version'];
			$message = sprintf(
				/* translators: 1: current exact build display, 2: target exact build display. */
				__( 'A governed MAD4B build update is available: %1$s → %2$s.', 'mad4b-site-control-plane' ),
				$current_display,
				$target_display
			);
			echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>'
				. esc_html( $message ) . ' <a href="' . esc_url( $url ) . '">'
				. esc_html__( 'Update now', 'mad4b-site-control-plane' ) . '</a></p></div></td></tr>';
			return;
		}

		if ( 'manifest_unavailable' === $ui['state'] ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=mad4b_control_plane_refresh_update' ),
				'mad4b_control_plane_refresh_update'
			);
			$reason = ! empty( $ui['blockers'] ) ? implode( ', ', $ui['blockers'] ) : 'manifest_unavailable';
			echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-error notice-alt"><p>'
				. esc_html__( 'MAD4B could not verify the governed update channel.', 'mad4b-site-control-plane' )
				. ' ' . esc_html( $reason ) . ' <a href="' . esc_url( $url ) . '">'
				. esc_html__( 'Retry update check', 'mad4b-site-control-plane' ) . '</a></p></div></td></tr>';
			return;
		}

		if ( 'policy_blocked' === $ui['state'] ) {
			$reason = ! empty( $ui['blockers'] ) ? implode( ', ', $ui['blockers'] ) : 'policy_blocked';
			$environment_resolution = self::environment_resolution();
			$environment_help = '';
			if (
				in_array( 'native_update_environment_policy_blocked', (array) $ui['blockers'], true )
				&& 'staging' === (string) ( isset( $environment_resolution['suggested_environment'] ) ? $environment_resolution['suggested_environment'] : '' )
				&& 'staging' !== (string) ( isset( $environment_resolution['effective_environment'] ) ? $environment_resolution['effective_environment'] : '' )
			) {
				$environment_help = ' <a href="' . esc_url( admin_url( 'admin.php?page=mad4b-control-plane-site-profile' ) ) . '">'
					. esc_html__( 'Bind this exact origin as Staging', 'mad4b-site-control-plane' ) . '</a>'
					. ' — ' . esc_html__( 'no wp-config.php edit required.', 'mad4b-site-control-plane' );
			}
			echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>'
				. esc_html__( 'MAD4B update is currently blocked by policy.', 'mad4b-site-control-plane' )
				. ' ' . esc_html( $reason ) . $environment_help . '</p></div></td></tr>';
		}
	}

	public static function render_update_row_fallback( $plugin_file, $plugin_data = array(), $status = '' ) {
		if ( ! self::is_control_plane_plugin_file( $plugin_file ) ) return;
		self::render_update_row( $plugin_file, $plugin_data, $status );
	}

	public static function handle_refresh_update() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) wp_die( esc_html__( 'You are not allowed to check plugin updates.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_control_plane_refresh_update' );
		self::clear_manifest_cache();
		delete_site_transient( 'update_plugins' );

		// Explicit refresh is the only wp-admin UI action allowed to perform
		// outbound manifest I/O. The plugins table itself remains cache-only.
		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) self::redirect_native_result( 'manifest_error', $manifest->get_error_code() );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public static function handle_native_update() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) wp_die( esc_html__( 'You are not allowed to update plugins.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_control_plane_native_update' );
		if ( ! self::environment_allowed( false ) ) wp_die( esc_html__( 'MAD4B self-update is not enabled for this environment.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );

		$manifest = self::fetch_manifest( true );
		if ( is_wp_error( $manifest ) ) self::redirect_native_result( 'manifest_error', $manifest->get_error_code() );
		if ( ! self::update_available( $manifest ) ) self::redirect_native_result( 'current', '' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $manifest['package_url'], 30 );
		if ( is_wp_error( $tmp ) ) self::redirect_native_result( 'download_error', $tmp->get_error_code() );

		$verified = self::verify_archive( $tmp, $manifest );
		if ( is_wp_error( $verified ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::redirect_native_result( 'verify_error', $verified->get_error_code() );
		}

		$bootstrap_mode = false;
		$continuation_probe = self::post_update_continuation_policy();
		if ( is_wp_error( $continuation_probe )
			&& 'mad4b_self_update_continuation_prior_authority_drift' === $continuation_probe->get_error_code() ) {
			$bootstrap_policy = self::bootstrap_candidate_drift_policy();
			if ( ! empty( $bootstrap_policy['eligible'] ) ) $bootstrap_mode = true;
		}

		$result = self::apply_verified_archive(
			$tmp,
			$manifest,
			$bootstrap_mode ? 'wordpress_admin_candidate_drift_bootstrap' : 'wordpress_admin_plugin_update',
			'',
			$verified,
			$bootstrap_mode
		);
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $result ) ) {
			$details = method_exists( $result, 'get_error_data' ) ? $result->get_error_data() : array();
			self::redirect_native_result( 'apply_error', $result->get_error_code(), is_array( $details ) ? $details : array() );
		}
		self::redirect_native_result( $bootstrap_mode ? 'bootstrap_success' : 'success', '' );
	}

	public static function native_update_notice() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return;
		$state = isset( $_GET['mad4b_control_plane_update'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_control_plane_update'] ) ) : '';
		if ( '' === $state ) return;
		$code = isset( $_GET['mad4b_update_code'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_update_code'] ) ) : '';
		if ( 'success' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MAD4B Site Control Plane updated and exact-build readback passed.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		if ( 'bootstrap_success' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MAD4B Site Control Plane updated through the bounded candidate-drift bootstrap path. Governed write authority remains quarantined until the exact new candidate is explicitly rebound.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		if ( 'current' === $state ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'MAD4B Site Control Plane is already on the current governed build.', 'mad4b-site-control-plane' ) . '</p></div>';
			return;
		}
		$message = __( 'MAD4B Site Control Plane update did not complete.', 'mad4b-site-control-plane' );
		if ( '' !== $code ) $message .= ' ' . sprintf( __( 'Reason: %s', 'mad4b-site-control-plane' ), $code );
		if ( 'mad4b_self_update_continuation_prior_authority_drift' === $code ) {
			$message .= ' ' . __( 'Review governed write authority and reconcile its binding to the currently installed build before retrying this update.', 'mad4b-site-control-plane' );
		}

		$maintenance_state = isset( $_GET['mad4b_update_maintenance_state'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_update_maintenance_state'] ) ) : '';
		if ( '' !== $maintenance_state ) {
			$owner = isset( $_GET['mad4b_update_maintenance_owner'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_update_maintenance_owner'] ) ) : '';
			$retry = isset( $_GET['mad4b_update_retry_after'] ) ? min( 1200, absint( wp_unslash( $_GET['mad4b_update_retry_after'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect evidence.
			$source = isset( $_GET['mad4b_update_fence_source'] ) ? sanitize_text_field( wp_unslash( $_GET['mad4b_update_fence_source'] ) ) : '';
			$count = isset( $_GET['mad4b_update_fence_count'] ) ? min( 16, absint( wp_unslash( $_GET['mad4b_update_fence_count'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect evidence.
			$conflict = isset( $_GET['mad4b_update_fence_conflict'] ) && '1' === sanitize_key( wp_unslash( $_GET['mad4b_update_fence_conflict'] ) );
			$message .= ' ' . sprintf( __( 'Maintenance state: %s.', 'mad4b-site-control-plane' ), $maintenance_state );
			if ( '' !== $owner ) $message .= ' ' . sprintf( __( 'Owner: %s.', 'mad4b-site-control-plane' ), $owner );
			if ( $retry > 0 ) $message .= ' ' . sprintf( __( 'Retry after: %d seconds.', 'mad4b-site-control-plane' ), $retry );
			if ( '' !== $source ) $message .= ' ' . sprintf( __( 'Fence source: %s.', 'mad4b-site-control-plane' ), $source );
			if ( $count > 0 ) $message .= ' ' . sprintf( __( 'Active fences: %d.', 'mad4b-site-control-plane' ), $count );
			if ( $conflict ) $message .= ' ' . __( 'Conflicting maintenance fence tokens require operator inspection.', 'mad4b-site-control-plane' );
		}
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function redirect_native_result( $state, $code, array $details = array() ) {
		$args = array( 'mad4b_control_plane_update' => sanitize_key( (string) $state ) );
		if ( '' !== (string) $code ) $args['mad4b_update_code'] = sanitize_key( (string) $code );
		if ( ! empty( $details['maintenance_classification'] ) ) $args['mad4b_update_maintenance_state'] = sanitize_key( (string) $details['maintenance_classification'] );
		if ( ! empty( $details['maintenance_owner'] ) ) $args['mad4b_update_maintenance_owner'] = sanitize_key( (string) $details['maintenance_owner'] );
		if ( ! empty( $details['retry_after_seconds'] ) ) $args['mad4b_update_retry_after'] = min( 1200, absint( $details['retry_after_seconds'] ) );
		if ( ! empty( $details['maintenance_fence_source'] ) ) $args['mad4b_update_fence_source'] = sanitize_text_field( (string) $details['maintenance_fence_source'] );
		if ( ! empty( $details['maintenance_active_fence_count'] ) ) $args['mad4b_update_fence_count'] = min( 16, absint( $details['maintenance_active_fence_count'] ) );
		if ( ! empty( $details['maintenance_fence_token_conflict'] ) ) $args['mad4b_update_fence_conflict'] = '1';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'plugins.php' ) ) );
		exit;
	}

	private static function update_available( array $manifest ) {
		$current = self::installed_identity();
		if ( ! empty( $current['source_commit_sha'] ) && hash_equals( $current['source_commit_sha'], $manifest['source_commit_sha'] ) ) return false;
		if ( ! empty( $current['version'] ) && version_compare( $current['version'], $manifest['version'], '>' ) ) return false;
		return true;
	}

	private static function maintenance_status_projection() {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' )
			|| ! method_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease', 'preflight' ) ) {
			return array(
				'contract' => 'mad4b.runtime-maintenance-preflight.v1',
				'classification' => 'UNAVAILABLE',
				'safe_to_acquire' => false,
				'retryable' => false,
				'operator_action_required' => true,
				'automatic_mutation_retry_allowed' => false,
				'preflight_recheck_allowed' => true,
				'read_only' => true,
				'mutation_performed' => false,
			);
		}
		$status = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_status' );
		if ( ! is_array( $status ) ) return array(
			'contract' => 'mad4b.runtime-maintenance-preflight.v1',
			'classification' => 'INVALID',
			'safe_to_acquire' => false,
			'retryable' => false,
			'operator_action_required' => true,
			'automatic_mutation_retry_allowed' => false,
			'preflight_recheck_allowed' => true,
			'read_only' => true,
			'mutation_performed' => false,
		);
		$out = self::bounded_maintenance_evidence( $status );
		$out['classification'] = isset( $status['classification'] ) ? sanitize_key( (string) $status['classification'] ) : '';
		$out['safe_to_acquire'] = ! empty( $status['safe_to_acquire'] );
		$out['read_only'] = true;
		return $out;
	}

	private static function continuation_policy_projection() {
		$policy = self::post_update_continuation_policy();
		if ( is_wp_error( $policy ) ) {
			$data = $policy->get_error_data();
			$data = is_array( $data ) ? $data : array();
			return array(
				'contract' => 'mad4b.self-update-continuation-policy.v1',
				'blocked' => true,
				'blocker' => sanitize_key( (string) $policy->get_error_code() ),
				'required' => null,
				'mode' => 'blocked',
				'prior_authority_effective' => isset( $data['prior_authority_effective'] ) ? (bool) $data['prior_authority_effective'] : null,
				'candidate_binding_required' => isset( $data['candidate_binding_required'] ) ? (bool) $data['candidate_binding_required'] : null,
				'candidate_binding_match' => isset( $data['candidate_binding_match'] ) ? (bool) $data['candidate_binding_match'] : null,
				'operator_action' => 'mad4b_self_update_continuation_prior_authority_drift' === $policy->get_error_code() ? 'reconcile_staging_write_authority' : '',
				'automatic_mutation_retry_allowed' => false,
				'bootstrap_without_authority' => false,
				'production_mutation_allowed' => false,
				'authority_created' => false,
				'read_only' => true,
				'mutation_performed' => false,
			);
		}
		return array(
			'contract' => 'mad4b.self-update-continuation-policy.v1',
			'blocked' => false,
			'blocker' => '',
			'required' => ! empty( $policy['required'] ),
			'mode' => isset( $policy['mode'] ) ? sanitize_key( (string) $policy['mode'] ) : '',
			'write_profile_enabled' => ! empty( $policy['write_profile_enabled'] ),
			'authority_checkpoint_exists' => ! empty( $policy['authority_checkpoint_exists'] ),
			'prior_authority_effective' => ! empty( $policy['prior_authority_effective'] ),
			'candidate_binding_match' => ! empty( $policy['candidate_binding_match'] ),
			'bootstrap_without_authority' => ! empty( $policy['bootstrap_without_authority'] ),
			'production_mutation_allowed' => false,
			'authority_created' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function bounded_maintenance_evidence( $status ) {
		$status = is_array( $status ) ? $status : array();
		return array(
			'maintenance_contract' => isset( $status['contract'] ) ? sanitize_text_field( (string) $status['contract'] ) : '',
			'maintenance_classification' => isset( $status['classification'] ) ? sanitize_key( (string) $status['classification'] ) : '',
			'maintenance_owner' => isset( $status['owner'] ) ? sanitize_key( (string) $status['owner'] ) : '',
			'maintenance_fence_source' => isset( $status['fence_source'] ) ? sanitize_text_field( (string) $status['fence_source'] ) : '',
			'maintenance_active_fence_count' => isset( $status['active_fence_count'] ) ? absint( $status['active_fence_count'] ) : 0,
			'maintenance_legacy_only_fence' => ! empty( $status['legacy_only_fence'] ),
			'maintenance_fence_token_conflict' => ! empty( $status['fence_token_conflict'] ),
			'maintenance_soft_lease_expired' => ! empty( $status['soft_lease_expired'] ),
			'maintenance_hard_expires_at' => isset( $status['hard_expires_at'] ) ? absint( $status['hard_expires_at'] ) : 0,
			'retry_after_seconds' => isset( $status['retry_after_seconds'] ) ? absint( $status['retry_after_seconds'] ) : 0,
			'retryable' => ! empty( $status['retryable'] ),
			'operator_action_required' => ! empty( $status['operator_action_required'] ),
			'automatic_mutation_retry_allowed' => false,
			'preflight_recheck_allowed' => true,
			'mutation_performed' => false,
		);
	}

	private static function maintenance_preflight( $requester ) {
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' )
			|| ! method_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease', 'preflight' ) ) {
			return new WP_Error( 'mad4b_self_update_maintenance_preflight_unavailable', 'Runtime maintenance preflight is unavailable.' );
		}
		$status = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( $requester );
		if ( ! is_array( $status ) || 'mad4b.runtime-maintenance-preflight.v1' !== ( isset( $status['contract'] ) ? (string) $status['contract'] : '' ) ) {
			return new WP_Error( 'mad4b_self_update_maintenance_preflight_invalid', 'Runtime maintenance preflight returned an invalid contract.' );
		}
		if ( ! empty( $status['safe_to_acquire'] ) ) return $status;
		$classification = isset( $status['classification'] ) ? sanitize_key( (string) $status['classification'] ) : '';
		$code = 'fence_conflict' === $classification
			? 'mad4b_runtime_maintenance_fence_conflict'
			: ( 'stale_repair_required' === $classification ? 'mad4b_runtime_maintenance_stale_repair_required' : 'mad4b_runtime_maintenance_busy' );
		return new WP_Error( $code, 'MAD4B runtime maintenance prevents Control Plane replacement.', self::bounded_maintenance_evidence( $status ) );
	}

	/**
	 * Decide whether an update must carry an existing governed-write authority
	 * across the replacement boundary.
	 *
	 * Site Profile write_enabled is capability intent, not proof that write
	 * authority has ever been reconciled. A brand-new Staging site with no
	 * persisted authority checkpoint may therefore update without a continuation
	 * permit; post-update authority remains owner-gated and fail-closed. Once any
	 * durable authority checkpoint exists, ambiguous/blocked/stale state must not
	 * be reclassified as bootstrap.
	 */
	private static function post_update_continuation_policy() {
		$out = array(
			'contract' => 'mad4b.self-update-continuation-policy.v1',
			'required' => false,
			'mode' => 'not_applicable',
			'write_profile_enabled' => false,
			'authority_checkpoint_exists' => false,
			'prior_authority_effective' => false,
			'candidate_binding_match' => false,
			'bootstrap_without_authority' => false,
			'production_mutation_allowed' => false,
			'authority_created' => false,
		);

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return $out;
		$environment = sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() );
		$write_enabled = MAD4B_SCP_Site_Profile::write_enabled();
		$out['write_profile_enabled'] = (bool) $write_enabled;
		if ( 'staging' !== $environment || ! $write_enabled ) return $out;

		if ( ! class_exists( 'MAD4B_SCP_Post_Update_Continuation' )
			|| ! class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'persistence_checkpoint' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'authority_presence_status' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			|| ! method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'effective' ) ) {
			return new WP_Error(
				'mad4b_self_update_continuation_authority_state_unavailable',
				'Unable to classify the pre-update governed-write authority state safely.'
			);
		}

		$checkpoint = MAD4B_SCP_Staging_Write_Authority::persistence_checkpoint();
		if ( ! is_array( $checkpoint )
			|| 'mad4b.governed-write-authority-persistence-checkpoint.v1' !== ( isset( $checkpoint['contract'] ) ? (string) $checkpoint['contract'] : '' ) ) {
			return new WP_Error(
				'mad4b_self_update_continuation_authority_checkpoint_invalid',
				'Persisted governed-write authority checkpoint could not be classified safely.'
			);
		}

		$out['authority_checkpoint_exists'] = ! empty( $checkpoint['exists'] );
		if ( empty( $checkpoint['exists'] ) ) {
			// A missing checkpoint is only a clean bootstrap when no managed write
			// agent/grants already exist. Use the bounded presence probe rather than
			// rebuilding the complete provider/write reconciliation inventory.
			$presence = MAD4B_SCP_Staging_Write_Authority::authority_presence_status();
			if ( ! is_array( $presence )
				|| 'mad4b.governed-write-authority-presence.v1' !== ( isset( $presence['contract'] ) ? (string) $presence['contract'] : '' ) ) {
				return new WP_Error(
					'mad4b_self_update_continuation_bootstrap_snapshot_unavailable',
					'Unable to prove that this Staging site has no prior governed-write authority.'
				);
			}
			if ( empty( $presence['fresh_bootstrap_candidate'] ) || ! empty( $presence['authority_residue_without_checkpoint'] ) ) {
				return new WP_Error(
					'mad4b_self_update_continuation_bootstrap_authority_residue',
					'Governed-write authority residue exists without a durable checkpoint; repair authority state before updating.'
				);
			}
			$out['mode'] = 'bootstrap_no_prior_authority';
			$out['bootstrap_without_authority'] = true;
			return $out;
		}

		$status = isset( $checkpoint['status'] ) && is_array( $checkpoint['status'] ) ? $checkpoint['status'] : null;
		if ( ! is_array( $status )
			|| empty( $status['ready'] )
			|| 'ready' !== ( isset( $status['state'] ) ? (string) $status['state'] : '' )
			|| ! empty( $status['blocker'] )
			|| empty( $status['write_inventory_fingerprint'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $status['write_inventory_fingerprint'] ) ) ) {
			return new WP_Error(
				'mad4b_self_update_continuation_prior_authority_not_effective',
				'A persisted governed-write authority checkpoint exists but is not safely continuable.'
			);
		}

		$binding = MAD4B_SCP_Staging_Write_Authority::candidate_binding_status();
		$effective = MAD4B_SCP_Staging_Write_Authority::effective();
		if ( ! is_array( $binding ) || ! isset( $binding['required'], $binding['match'] ) || ! is_bool( $binding['required'] ) || ! is_bool( $binding['match'] ) || ! is_bool( $effective ) ) {
			return new WP_Error( 'mad4b_self_update_continuation_authority_state_unavailable', 'Unable to classify the pre-update governed-write candidate binding safely.' );
		}
		$out['prior_authority_effective'] = (bool) $effective;
		$out['candidate_binding_match'] = is_array( $binding ) && ! empty( $binding['match'] );
		if ( ! $effective || ( is_array( $binding ) && ! empty( $binding['required'] ) && empty( $binding['match'] ) ) ) {
			return new WP_Error(
				'mad4b_self_update_continuation_prior_authority_drift',
				'Existing governed-write authority is stale or candidate-bound to a different build; reconcile it before updating.',
				array(
					'prior_authority_effective' => (bool) $effective,
					'candidate_binding_required' => is_array( $binding ) && ! empty( $binding['required'] ),
					'candidate_binding_match' => is_array( $binding ) && ! empty( $binding['match'] ),
					'operator_action' => 'reconcile_staging_write_authority',
					'automatic_mutation_retry_allowed' => false,
				)
			);
		}

		$out['required'] = true;
		$out['mode'] = 'carry_forward_effective_authority';
		return $out;
	}

	private static function apply_verified_archive( $path, array $target, $channel, $plan_sha256, array $verified_archive, $bootstrap_candidate_drift = false ) {
		$runtime_php_files = isset( $verified_archive['runtime_php_files'] ) && is_array( $verified_archive['runtime_php_files'] )
			? array_values( array_filter( array_map( 'strval', $verified_archive['runtime_php_files'] ) ) )
			: array();
		if ( empty( $runtime_php_files ) ) {
			return new WP_Error( 'mad4b_self_update_verified_runtime_index_missing', 'Verified Control Plane archive is missing the bounded PHP runtime index.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Maintenance_Lease' ) ) {
			return new WP_Error( 'mad4b_self_update_maintenance_lease_unavailable', 'Control Plane replacement requires the shared runtime maintenance lease.' );
		}

		$lease_owner = 'self_update_replacement';
		$maintenance_preflight = self::maintenance_preflight( $lease_owner );
		if ( is_wp_error( $maintenance_preflight ) ) {
			$data = method_exists( $maintenance_preflight, 'get_error_data' ) ? $maintenance_preflight->get_error_data() : array();
			self::audit( $channel, $target, false, array_merge(
				array( 'failure_phase' => 'pre_update_maintenance', 'failure_code' => $maintenance_preflight->get_error_code() ),
				is_array( $data ) ? $data : array()
			) );
			return $maintenance_preflight;
		}
		$lease_token = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner );
		if ( is_wp_error( $lease_token ) ) {
			$fresh = self::maintenance_preflight( $lease_owner );
			if ( is_wp_error( $fresh ) ) {
				$data = method_exists( $fresh, 'get_error_data' ) ? $fresh->get_error_data() : array();
				self::audit( $channel, $target, false, array_merge(
					array( 'failure_phase' => 'pre_update_maintenance_race', 'failure_code' => $fresh->get_error_code() ),
					is_array( $data ) ? $data : array()
				) );
				return $fresh;
			}
			self::audit( $channel, $target, false, array( 'failure_phase' => 'pre_update_maintenance_acquire', 'failure_code' => $lease_token->get_error_code() ) );
			return $lease_token;
		}

		$core_maintenance_open = false;
		$upgrader = null;
		try {
			$before = self::activation_state();
			$backup = self::backup_current();
			if ( is_wp_error( $backup ) ) return $backup;

			$lease_refresh = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, $lease_owner );
			if ( is_wp_error( $lease_refresh ) ) return $lease_refresh;

			$continuation = array();
			if ( $bootstrap_candidate_drift ) {
				$bootstrap_policy = self::bootstrap_candidate_drift_policy();
				if ( empty( $bootstrap_policy['eligible'] ) ) {
					self::audit( $channel, $target, false, array(
						'failure_phase' => 'pre_update_bootstrap_candidate_drift_policy',
						'failure_code' => 'mad4b_self_update_bootstrap_authority_not_clean',
						'bootstrap_policy' => $bootstrap_policy,
						'authority_created' => false,
						'production_mutation_performed' => false,
					) );
					return new WP_Error(
						'mad4b_self_update_bootstrap_authority_not_clean',
						'Candidate-drift bootstrap became ineligible before replacement.',
						array( 'blockers' => isset( $bootstrap_policy['blockers'] ) ? $bootstrap_policy['blockers'] : array(), 'mutation_performed' => false )
					);
				}
				$continuation_policy = array(
					'contract' => 'mad4b.self-update-continuation-policy.v1',
					'required' => false,
					'mode' => 'bootstrap_candidate_drift_quarantined',
					'write_profile_enabled' => true,
					'authority_checkpoint_exists' => true,
					'prior_authority_effective' => false,
					'candidate_binding_match' => false,
					'bootstrap_without_authority' => false,
					'bootstrap_candidate_drift' => true,
					'authority_carry_forward' => false,
					'post_update_candidate_rebind_required' => true,
					'production_mutation_allowed' => false,
					'authority_created' => false,
				);
			} else {
				$continuation_policy = self::post_update_continuation_policy();
				if ( is_wp_error( $continuation_policy ) ) {
					self::audit( $channel, $target, false, array(
						'failure_phase' => 'pre_update_continuation_policy',
						'failure_code' => $continuation_policy->get_error_code(),
						'authority_created' => false,
						'production_mutation_performed' => false,
					) );
					return $continuation_policy;
				}
			}
			$continuation_required = ! empty( $continuation_policy['required'] );
			if ( $continuation_required ) {
				$continuation_target = $target;
				$provenance = isset( $verified_archive['provenance'] ) && is_array( $verified_archive['provenance'] ) ? $verified_archive['provenance'] : array();
				if ( isset( $provenance['artifact_identity'] ) ) $continuation_target['artifact_identity'] = (string) $provenance['artifact_identity'];
				$continuation = MAD4B_SCP_Post_Update_Continuation::prepare( $continuation_target, $channel, $plan_sha256, $lease_token );
				if ( is_wp_error( $continuation ) ) {
					self::audit( $channel, $target, false, array(
						'failure_phase' => 'pre_update_continuation_prepare',
						'failure_code' => $continuation->get_error_code(),
						'continuation_policy_mode' => isset( $continuation_policy['mode'] ) ? sanitize_key( (string) $continuation_policy['mode'] ) : '',
						'authority_created' => false,
					) );
					return $continuation;
				}
				if ( isset( $continuation['classification'] ) && MAD4B_SCP_Post_Update_Continuation::CLASS_HARD === (string) $continuation['classification'] ) {
					MAD4B_SCP_Post_Update_Continuation::cancel( 'pre_update_hard_block', $continuation_target );
					return new WP_Error( 'mad4b_self_update_continuation_hard_blocked', 'Control Plane update is blocked by a high-risk post-update continuation delta.', array(
						'reasons' => isset( $continuation['classification_reasons'] ) ? $continuation['classification_reasons'] : array(),
					) );
				}
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! empty( $continuation_policy['bootstrap_candidate_drift'] ) ) {
				$bootstrap_recheck = self::bootstrap_candidate_drift_policy();
				if ( empty( $bootstrap_recheck['eligible'] ) ) {
					self::audit( $channel, $target, false, array(
						'failure_phase' => 'pre_replacement_candidate_drift_bootstrap_revalidation',
						'failure_code' => 'mad4b_self_update_bootstrap_authority_changed_before_replacement',
						'bootstrap_policy' => $bootstrap_recheck,
						'authority_created' => false,
						'production_mutation_performed' => false,
					) );
					return new WP_Error(
						'mad4b_self_update_bootstrap_authority_changed_before_replacement',
						'Governed-write authority changed after candidate-drift bootstrap admission; replacement was not started.',
						array(
							'blockers' => isset( $bootstrap_recheck['blockers'] ) ? $bootstrap_recheck['blockers'] : array(),
							'mutation_performed' => false,
						)
					);
				}
			}

			if ( ! empty( $continuation_policy['bootstrap_without_authority'] ) ) {
				$bootstrap_recheck = self::post_update_continuation_policy();
				$bootstrap_changed = is_wp_error( $bootstrap_recheck )
					|| ! is_array( $bootstrap_recheck )
					|| empty( $bootstrap_recheck['bootstrap_without_authority'] )
					|| ! empty( $bootstrap_recheck['required'] )
					|| 'bootstrap_no_prior_authority' !== ( isset( $bootstrap_recheck['mode'] ) ? (string) $bootstrap_recheck['mode'] : '' );
				if ( $bootstrap_changed ) {
					$code = is_wp_error( $bootstrap_recheck )
						? $bootstrap_recheck->get_error_code()
						: 'mad4b_self_update_bootstrap_authority_changed_before_replacement';
					self::audit( $channel, $target, false, array(
						'failure_phase' => 'pre_replacement_bootstrap_revalidation',
						'failure_code' => $code,
						'continuation_policy_mode' => 'bootstrap_no_prior_authority',
						'authority_created' => false,
						'production_mutation_performed' => false,
					) );
					return new WP_Error(
						'mad4b_self_update_bootstrap_authority_changed_before_replacement',
						'Governed-write authority changed after bootstrap admission; replacement was not started.',
						array( 'cause_code' => sanitize_key( (string) $code ), 'mutation_performed' => false )
					);
				}
			}

			self::$managed_apply = true;
			$skin = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			try {
				// Plugin_Upgrader::install(overwrite_package=true) clears the destination
				// but does not enable the active-plugin maintenance hooks used by upgrade().
				// Hold WordPress' own maintenance window across replacement/readback while
				// the shared DB lease protects MAD4B request-serving work on both sides.
				$core_maintenance_open = true;
				$upgrader->maintenance_mode( true );
				$installed = $upgrader->install( $path, array( 'overwrite_package' => true ) );
			} catch ( Throwable $throwable ) {
				$installed = new WP_Error(
					'mad4b_self_update_install_exception',
					'Control Plane installer raised an unexpected exception.',
					array( 'error_class' => get_class( $throwable ) )
				);
			} finally {
				self::$managed_apply = false;
			}

			if ( is_wp_error( $installed ) || true !== $installed ) {
				$error = is_wp_error( $installed ) ? $installed : ( method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null );
				$rollback = self::rollback( $backup, $before, $runtime_php_files );
				if ( ! empty( $continuation ) && class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ) MAD4B_SCP_Post_Update_Continuation::cancel( 'install_failed', isset( $continuation['target_identity'] ) ? $continuation['target_identity'] : array() );
				self::audit( $channel, $target, false, array(
					'plan_sha256' => $plan_sha256,
					'failure_phase' => 'install',
					'failure_code' => is_wp_error( $error ) ? $error->get_error_code() : 'plugin_upgrader_failed',
					'core_maintenance_window_used' => true,
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
				return new WP_Error( 'mad4b_self_update_install_failed', 'Control Plane installation failed and rollback was attempted.', array( 'rollback_ok' => ! is_wp_error( $rollback ) ) );
			}

			$lease_refresh = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, $lease_owner );
			if ( is_wp_error( $lease_refresh ) ) {
				$rollback = self::rollback( $backup, $before, $runtime_php_files );
				if ( ! empty( $continuation ) && class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ) MAD4B_SCP_Post_Update_Continuation::cancel( 'maintenance_fence_lost', isset( $continuation['target_identity'] ) ? $continuation['target_identity'] : array() );
				self::audit( $channel, $target, false, array(
					'plan_sha256' => $plan_sha256,
					'failure_phase' => 'post_install_maintenance_fence',
					'failure_code' => $lease_refresh->get_error_code(),
					'core_maintenance_window_used' => true,
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
				return new WP_Error( 'mad4b_self_update_maintenance_fence_lost', 'Control Plane replacement lost its runtime maintenance fence after installation; rollback was attempted.', array(
					'cause_code' => $lease_refresh->get_error_code(),
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
			}

			$runtime_cache = self::invalidate_runtime_caches( $runtime_php_files );
			$activation = self::restore_activation_state( $before );
			$readback_target = $target;
			$verified_provenance = isset( $verified_archive['provenance'] ) && is_array( $verified_archive['provenance'] ) ? $verified_archive['provenance'] : array();
			if ( isset( $verified_provenance['artifact_identity'] ) ) $readback_target['artifact_identity'] = (string) $verified_provenance['artifact_identity'];
			$readback = is_wp_error( $activation ) ? $activation : self::verify_installed_identity( $readback_target );
			if ( is_wp_error( $readback ) ) {
				$rollback = self::rollback( $backup, $before, $runtime_php_files );
				if ( ! empty( $continuation ) && class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ) MAD4B_SCP_Post_Update_Continuation::cancel( 'readback_failed', isset( $continuation['target_identity'] ) ? $continuation['target_identity'] : array() );
				self::audit( $channel, $target, false, array(
					'plan_sha256' => $plan_sha256,
					'failure_phase' => 'readback',
					'failure_code' => $readback->get_error_code(),
					'core_maintenance_window_used' => true,
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
				return new WP_Error( 'mad4b_self_update_readback_failed', 'Control Plane exact build readback failed and rollback was attempted.', array(
					'cause_code' => $readback->get_error_code(),
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
			}

			$continuation_readback = array();
			if ( ! empty( $continuation ) && class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ) {
				$continuation_readback = MAD4B_SCP_Post_Update_Continuation::mark_readback_verified( is_array( $readback ) ? $readback : array() );
				if ( is_wp_error( $continuation_readback ) ) {
					$rollback = self::rollback( $backup, $before, $runtime_php_files );
					MAD4B_SCP_Post_Update_Continuation::cancel( 'continuation_readback_failed', isset( $continuation['target_identity'] ) ? $continuation['target_identity'] : array() );
					return new WP_Error( 'mad4b_self_update_continuation_readback_failed', 'Exact package readback passed but the one-time continuation permit could not be bound to it; rollback was attempted.', array(
						'rollback_ok' => ! is_wp_error( $rollback ),
					) );
				}
				$continuation = $continuation_readback;
			}

			$convergence = array();
			if ( class_exists( 'MAD4B_SCP_Runtime_Convergence' ) && method_exists( 'MAD4B_SCP_Runtime_Convergence', 'mark_post_update_pending' ) ) {
				$convergence = MAD4B_SCP_Runtime_Convergence::mark_post_update_pending( $readback_target, $channel, $plan_sha256, $continuation );
			}
			$convergence_state = is_array( $convergence ) && isset( $convergence['state'] ) ? sanitize_key( (string) $convergence['state'] ) : '';
			if ( 'checkpoint_persist_failed' === $convergence_state ) {
				$rollback = self::rollback( $backup, $before, $runtime_php_files );
				if ( ! empty( $continuation ) && class_exists( 'MAD4B_SCP_Post_Update_Continuation' ) ) MAD4B_SCP_Post_Update_Continuation::cancel( 'convergence_checkpoint_failed', isset( $continuation['target_identity'] ) ? $continuation['target_identity'] : array() );
				self::audit( $channel, $target, false, array(
					'plan_sha256' => $plan_sha256,
					'failure_phase' => 'post_update_convergence_checkpoint',
					'failure_code' => 'mad4b_self_update_convergence_checkpoint_persist_failed',
					'readback' => $readback,
					'runtime_cache_invalidation' => $runtime_cache,
					'post_update_convergence' => $convergence,
					'core_maintenance_window_used' => true,
					'rollback_ok' => ! is_wp_error( $rollback ),
				) );
				return new WP_Error(
					'mad4b_self_update_convergence_checkpoint_persist_failed',
					'Control Plane replacement could not establish its durable post-update convergence checkpoint; rollback was attempted.',
					array(
						'rollback_ok' => ! is_wp_error( $rollback ),
						'post_update_convergence' => $convergence,
					)
				);
			}

			self::audit( $channel, $target, true, array(
				'plan_sha256' => $plan_sha256,
				'readback' => $readback,
				'runtime_cache_invalidation' => $runtime_cache,
				'post_update_convergence' => $convergence,
				'post_update_continuation' => $continuation,
				'post_update_continuation_policy' => $continuation_policy,
				'core_maintenance_window_used' => true,
				'pre_replacement_runtime_lease' => true,
			) );
			delete_site_transient( 'update_plugins' );
			// Preserve the already verified release manifest across the immediate
			// post-update redirect. Deleting it here forced plugins.php to block on
			// a new remote GitHub request and could trip upstream gateway timeouts.

			return array(
				'contract' => 'governed_native_release_pull' === (string) $channel ? self::NATIVE_APPLY_CONTRACT : self::APPLY_CONTRACT,
				'channel' => $channel,
				'plugin' => plugin_basename( MAD4B_SCP_FILE ),
				'before' => $before,
				'after' => $readback,
				'archive_sha256' => $target['archive_sha256'],
				'source_commit_sha' => $target['source_commit_sha'],
				'build_fingerprint' => $target['build_fingerprint'],
				'package_manifest_digest' => $target['package_manifest_digest'],
				'plan_sha256' => $plan_sha256,
				'readback_verified' => true,
				'rollback_required' => false,
				'runtime_cache_invalidation' => $runtime_cache,
				'core_maintenance_window_used' => true,
				'pre_replacement_runtime_lease' => true,
				'runtime_reboot_required' => true,
				'post_update_convergence' => $convergence,
				'post_update_continuation' => $continuation,
				'post_update_continuation_policy' => $continuation_policy,
				'production_mutation_performed' => false,
				'authority_created' => false,
				'authorizing' => false,
			);
		} finally {
			if ( $core_maintenance_open && is_object( $upgrader ) && method_exists( $upgrader, 'maintenance_mode' ) ) {
				try {
					$upgrader->maintenance_mode( false );
				} catch ( Throwable $maintenance_error ) {
					// WordPress ignores stale .maintenance after its bounded core window;
					// never mask the update result with a cleanup-only exception.
				}
			}
			MAD4B_SCP_Runtime_Maintenance_Lease::release( $lease_token, $lease_owner );
		}
	}

	private static function download_governed_release_to_protected_storage( array $manifest ) {
		$tmp = self::temp_archive_path();
		if ( is_wp_error( $tmp ) ) return $tmp;

		$response = wp_safe_remote_get(
			$manifest['package_url'],
			array(
				'timeout' => 30,
				'redirection' => 3,
				'stream' => true,
				'filename' => $tmp,
				'limit_response_size' => self::MAX_UPLOAD_BYTES + 1,
				'user-agent' => 'MAD4B-Site-Control-Plane/' . MAD4B_SCP_VERSION,
				'headers' => array( 'Accept' => 'application/zip' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_download_failed', 'Unable to download the exact governed Control Plane release.', array( 'cause' => $response->get_error_code() ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_download_http_error', 'Governed Control Plane release returned a non-200 response.' );
		}

		$size = @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || (int) $size !== (int) $manifest['size_bytes'] || (int) $size > self::MAX_UPLOAD_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'mad4b_self_update_native_size_mismatch', 'Downloaded governed release size does not match the exact manifest.' );
		}
		@chmod( $tmp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $tmp;
	}

	private static function manifest_transient_key( $source_commit_sha ) {
		$source_commit_sha = strtolower( trim( (string) $source_commit_sha ) );
		return self::MANIFEST_TRANSIENT_PREFIX . $source_commit_sha;
	}

	private static function manifest_resolution( array $manifest ) {
		return isset( $manifest['_mad4b_resolution'] ) ? (string) $manifest['_mad4b_resolution'] : 'legacy_compatibility_cache';
	}

	private static function cache_busted_url( $url ) {
		return (string) $url . '?mad4b_cb=' . rawurlencode( uniqid( 'mad4b-', true ) );
	}

	private static function fetch_release_json( $url, $max_bytes, $kind, $cache_bust = false ) {
		$request_url = $cache_bust ? self::cache_busted_url( $url ) : (string) $url;
		$response = wp_safe_remote_get(
			$request_url,
			array(
				'timeout' => 12,
				'redirection' => 3,
				'user-agent' => 'MAD4B-Site-Control-Plane/' . MAD4B_SCP_VERSION,
				'headers' => array(
					'Accept' => 'application/json',
					'Cache-Control' => 'no-cache',
					'Pragma' => 'no-cache',
				),
			)
		);
		$prefix = 'mad4b_self_update_' . sanitize_key( (string) $kind );
		if ( is_wp_error( $response ) ) return new WP_Error( $prefix . '_fetch_failed', 'Unable to fetch the MAD4B release-channel document.', array( 'cause' => $response->get_error_code() ) );
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) return new WP_Error( $prefix . '_http_error', 'MAD4B release-channel document returned a non-200 response.', array( 'status' => $status ) );
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > (int) $max_bytes ) return new WP_Error( $prefix . '_invalid', 'MAD4B release-channel document body is invalid.' );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) return new WP_Error( $prefix . '_invalid', 'MAD4B release-channel document is not valid JSON.' );
		return array( 'body' => $body, 'data' => $data );
	}

	private static function validate_pointer( array &$pointer ) {
		if ( self::POINTER_CONTRACT !== ( isset( $pointer['contract'] ) ? (string) $pointer['contract'] : '' ) ) return new WP_Error( 'mad4b_self_update_pointer_contract_mismatch', 'Update pointer contract mismatch.' );
		if ( 'mad4bdigital-ai/WordPress' !== ( isset( $pointer['repository'] ) ? (string) $pointer['repository'] : '' ) ) return new WP_Error( 'mad4b_self_update_pointer_repository_mismatch', 'Update pointer repository mismatch.' );
		if ( self::RELEASE_TAG !== ( isset( $pointer['release_tag'] ) ? (string) $pointer['release_tag'] : '' ) ) return new WP_Error( 'mad4b_self_update_pointer_release_tag_mismatch', 'Update pointer release channel mismatch.' );

		$source = isset( $pointer['source_commit_sha'] ) ? strtolower( trim( (string) $pointer['source_commit_sha'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $source ) ) return new WP_Error( 'mad4b_self_update_pointer_source_invalid', 'Update pointer exact source commit is invalid.' );
		$pointer['source_commit_sha'] = $source;

		$expected_asset = 'mad4b-site-control-plane-update-' . $source . '.json';
		$asset = isset( $pointer['manifest_asset'] ) ? trim( (string) $pointer['manifest_asset'] ) : '';
		if ( ! hash_equals( $expected_asset, $asset ) ) return new WP_Error( 'mad4b_self_update_pointer_asset_invalid', 'Update pointer immutable manifest asset is invalid.' );
		$pointer['manifest_asset'] = $asset;

		$digest = isset( $pointer['manifest_sha256'] ) ? strtolower( trim( (string) $pointer['manifest_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) return new WP_Error( 'mad4b_self_update_pointer_digest_invalid', 'Update pointer manifest digest is invalid.' );
		$pointer['manifest_sha256'] = $digest;

		$pointer['release_verdict_run_id'] = isset( $pointer['release_verdict_run_id'] ) ? absint( $pointer['release_verdict_run_id'] ) : 0;
		if ( $pointer['release_verdict_run_id'] < 1 ) return new WP_Error( 'mad4b_self_update_pointer_verdict_invalid', 'Update pointer Release Verdict identity is invalid.' );
		foreach ( array( 'release_verdict_success', 'release_root_trust_verified', 'published_from_master' ) as $field ) {
			if ( ! isset( $pointer[ $field ] ) || true !== $pointer[ $field ] ) return new WP_Error( 'mad4b_self_update_pointer_trust_invalid', 'Update pointer is not bound to the trusted master Release Verdict.', array( 'field' => $field ) );
		}
		return true;
	}

	private static function immutable_manifest_url( array $pointer ) {
		return 'https://github.com/mad4bdigital-ai/WordPress/releases/download/' . self::RELEASE_TAG . '/' . $pointer['manifest_asset'] . '?mad4b_manifest_sha256=' . $pointer['manifest_sha256'];
	}

	private static function validate_pointer_manifest_binding( array $pointer, array $manifest ) {
		if ( empty( $manifest['source_commit_sha'] ) || ! hash_equals( $pointer['source_commit_sha'], (string) $manifest['source_commit_sha'] ) ) return new WP_Error( 'mad4b_self_update_pointer_source_mismatch', 'Immutable update manifest does not match the pointer source commit.' );
		if ( empty( $manifest['release_verdict_run_id'] ) || (int) $pointer['release_verdict_run_id'] !== (int) $manifest['release_verdict_run_id'] ) return new WP_Error( 'mad4b_self_update_pointer_verdict_mismatch', 'Immutable update manifest does not match the pointer Release Verdict identity.' );
		return true;
	}

	private static function fetch_pointer( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::POINTER_TRANSIENT );
			if ( is_array( $cached ) ) {
				$valid = self::validate_pointer( $cached );
				if ( is_wp_error( $valid ) ) return $valid;
				return $cached;
			}
		}
		$fetched = self::fetch_release_json( self::POINTER_URL, 16384, 'pointer', true );
		if ( is_wp_error( $fetched ) ) return $fetched;
		$pointer = $fetched['data'];
		$valid = self::validate_pointer( $pointer );
		if ( is_wp_error( $valid ) ) return $valid;
		set_transient( self::POINTER_TRANSIENT, $pointer, self::POINTER_CACHE_TTL );
		return $pointer;
	}

	private static function pointer_legacy_fallback_allowed( $error ) {
		if ( ! is_wp_error( $error ) ) return false;
		return in_array( $error->get_error_code(), array( 'mad4b_self_update_pointer_fetch_failed', 'mad4b_self_update_pointer_http_error' ), true );
	}

	private static function fetch_legacy_manifest( $pointer_error = null ) {
		$fetched = self::fetch_release_json( self::MANIFEST_URL, 65536, 'manifest', true );
		if ( is_wp_error( $fetched ) ) return $fetched;
		$manifest = $fetched['data'];
		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) ) return $valid;
		$manifest['_mad4b_resolution'] = 'legacy_stable_fallback';
		if ( is_wp_error( $pointer_error ) ) $manifest['_mad4b_pointer_error'] = $pointer_error->get_error_code();
		set_transient( self::MANIFEST_TRANSIENT, $manifest, self::POINTER_CACHE_TTL );
		return $manifest;
	}

	private static function cached_manifest() {
		$pointer = get_transient( self::POINTER_TRANSIENT );
		if ( is_array( $pointer ) ) {
			$valid_pointer = self::validate_pointer( $pointer );
			if ( is_wp_error( $valid_pointer ) ) return $valid_pointer;
			$cached = get_transient( self::manifest_transient_key( $pointer['source_commit_sha'] ) );
			if ( is_array( $cached ) && isset( $cached['manifest'], $cached['manifest_sha256'] ) && is_array( $cached['manifest'] ) ) {
				if ( ! hash_equals( $pointer['manifest_sha256'], strtolower( trim( (string) $cached['manifest_sha256'] ) ) ) ) return new WP_Error( 'mad4b_self_update_manifest_cache_binding_mismatch', 'Cached immutable manifest digest does not match the current pointer.' );
				$manifest = $cached['manifest'];
				$valid_manifest = self::validate_manifest( $manifest );
				if ( is_wp_error( $valid_manifest ) ) return $valid_manifest;
				$binding = self::validate_pointer_manifest_binding( $pointer, $manifest );
				if ( is_wp_error( $binding ) ) return $binding;
				$manifest['_mad4b_resolution'] = 'pointer_immutable_cache';
				return $manifest;
			}
			return new WP_Error( 'mad4b_self_update_manifest_not_cached', 'Governed immutable update manifest is not cached; use the explicit refresh action.' );
		}

		$legacy = get_transient( self::MANIFEST_TRANSIENT );
		if ( is_array( $legacy ) ) {
			$valid = self::validate_manifest( $legacy );
			if ( is_wp_error( $valid ) ) return $valid;
			if ( empty( $legacy['_mad4b_resolution'] ) ) $legacy['_mad4b_resolution'] = 'legacy_compatibility_cache';
			return $legacy;
		}
		return new WP_Error( 'mad4b_self_update_manifest_not_cached', 'Governed update manifest is not cached; use the explicit refresh action.' );
	}

	private static function fetch_manifest( $force = false ) {
		if ( ! $force ) {
			$cached = self::cached_manifest();
			if ( ! is_wp_error( $cached ) ) return $cached;
		}

		$pointer = self::fetch_pointer( $force );
		if ( is_wp_error( $pointer ) ) {
			if ( ! self::pointer_legacy_fallback_allowed( $pointer ) ) return $pointer;
			return self::fetch_legacy_manifest( $pointer );
		}

		$fetched = self::fetch_release_json( self::immutable_manifest_url( $pointer ), 65536, 'manifest', false );
		if ( is_wp_error( $fetched ) ) return $fetched;
		$actual_digest = hash( 'sha256', $fetched['body'] );
		if ( ! hash_equals( $pointer['manifest_sha256'], $actual_digest ) ) return new WP_Error( 'mad4b_self_update_manifest_digest_mismatch', 'Immutable update manifest bytes do not match the pointer digest.' );

		$manifest = $fetched['data'];
		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) ) return $valid;
		$binding = self::validate_pointer_manifest_binding( $pointer, $manifest );
		if ( is_wp_error( $binding ) ) return $binding;
		$manifest['_mad4b_resolution'] = 'pointer_immutable';
		$manifest['_mad4b_pointer_manifest_sha256'] = $pointer['manifest_sha256'];

		set_transient(
			self::manifest_transient_key( $pointer['source_commit_sha'] ),
			array(
				'manifest' => $manifest,
				'manifest_sha256' => $pointer['manifest_sha256'],
				'source_commit_sha' => $pointer['source_commit_sha'],
			),
			self::MANIFEST_CACHE_TTL
		);
		// Preserve a short-lived cache-only compatibility slot for older UI paths
		// and safe rollback to a runtime that predates pointer-first resolution.
		set_transient( self::MANIFEST_TRANSIENT, $manifest, self::POINTER_CACHE_TTL );
		return $manifest;
	}

	private static function clear_manifest_cache() {
		$pointer = get_transient( self::POINTER_TRANSIENT );
		if ( is_array( $pointer ) && ! empty( $pointer['source_commit_sha'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/', strtolower( (string) $pointer['source_commit_sha'] ) ) ) {
			delete_transient( self::manifest_transient_key( strtolower( (string) $pointer['source_commit_sha'] ) ) );
		}
		delete_transient( self::POINTER_TRANSIENT );
		delete_transient( self::MANIFEST_TRANSIENT );
	}

	private static function validate_manifest( array &$manifest ) {
		if ( self::MANIFEST_CONTRACT !== ( isset( $manifest['contract'] ) ? (string) $manifest['contract'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_contract_mismatch', 'Update manifest contract mismatch.' );
		if ( 'mad4bdigital-ai/WordPress' !== ( isset( $manifest['repository'] ) ? (string) $manifest['repository'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_repository_mismatch', 'Update manifest repository mismatch.' );
		if ( self::RELEASE_TAG !== ( isset( $manifest['release_tag'] ) ? (string) $manifest['release_tag'] : '' ) ) return new WP_Error( 'mad4b_self_update_manifest_release_tag_mismatch', 'Update manifest release channel mismatch.' );

		foreach ( array( 'source_commit_sha' => 40, 'archive_sha256' => 64, 'build_fingerprint' => 64, 'package_manifest_digest' => 64 ) as $field => $length ) {
			$value = isset( $manifest[ $field ] ) ? strtolower( trim( (string) $manifest[ $field ] ) ) : '';
			if ( 1 !== preg_match( '/^[a-f0-9]{' . (int) $length . '}$/', $value ) ) return new WP_Error( 'mad4b_self_update_manifest_identity_invalid', 'Update manifest exact identity is incomplete.', array( 'field' => $field ) );
			$manifest[ $field ] = $value;
		}

		$version = isset( $manifest['version'] ) ? trim( (string) $manifest['version'] ) : '';
		if ( '' === $version || strlen( $version ) > 64 ) return new WP_Error( 'mad4b_self_update_manifest_version_invalid', 'Update manifest version is invalid.' );
		$manifest['version'] = $version;
		$manifest['display_version'] = $version . '+build.' . substr( $manifest['source_commit_sha'], 0, 7 );

		$url = isset( $manifest['package_url'] ) ? trim( (string) $manifest['package_url'] ) : '';
		$expected_path = '/mad4bdigital-ai/WordPress/releases/download/' . self::RELEASE_TAG . '/mad4b-site-control-plane-' . $manifest['source_commit_sha'] . '.zip';
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '' )
			|| 'github.com' !== ( isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '' )
			|| $expected_path !== ( isset( $parts['path'] ) ? (string) $parts['path'] : '' )
			|| ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['port'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return new WP_Error( 'mad4b_self_update_manifest_package_url_invalid', 'Update manifest package URL is outside the fixed repository release channel.' );
		}
		$manifest['package_url'] = $url;
		$manifest['size_bytes'] = isset( $manifest['size_bytes'] ) ? absint( $manifest['size_bytes'] ) : 0;
		if ( $manifest['size_bytes'] < 1 || $manifest['size_bytes'] > self::MAX_UPLOAD_BYTES ) return new WP_Error( 'mad4b_self_update_manifest_size_invalid', 'Update package size is outside the bounded self-update budget.' );
		$manifest['release_verdict_run_id'] = isset( $manifest['release_verdict_run_id'] ) ? absint( $manifest['release_verdict_run_id'] ) : 0;
		if ( $manifest['release_verdict_run_id'] < 1 ) return new WP_Error( 'mad4b_self_update_release_verdict_identity_missing', 'Update manifest Release Verdict identity is invalid.' );
		if ( ! isset( $manifest['release_verdict_success'] ) || true !== $manifest['release_verdict_success'] ) return new WP_Error( 'mad4b_self_update_release_verdict_missing', 'Update manifest is not bound to a successful Release Verdict.' );
		if ( ! isset( $manifest['published_from_master'] ) || true !== $manifest['published_from_master'] ) return new WP_Error( 'mad4b_self_update_master_publication_missing', 'Update manifest is not bound to an exact master publication.' );
		if ( ! isset( $manifest['release_root_trust_verified'] ) || true !== $manifest['release_root_trust_verified'] ) return new WP_Error( 'mad4b_self_update_release_root_trust_missing', 'Update manifest is not bound to verified release-root trust.' );
		return true;
	}

	private static function normalize_requested_identity( array $input ) {
		$identity = array(
			'version' => isset( $input['version'] ) ? trim( (string) $input['version'] ) : '',
			'source_commit_sha' => isset( $input['source_commit_sha'] ) ? strtolower( trim( (string) $input['source_commit_sha'] ) ) : '',
			'archive_sha256' => isset( $input['archive_sha256'] ) ? strtolower( trim( (string) $input['archive_sha256'] ) ) : '',
			'build_fingerprint' => isset( $input['build_fingerprint'] ) ? strtolower( trim( (string) $input['build_fingerprint'] ) ) : '',
			'package_manifest_digest' => isset( $input['package_manifest_digest'] ) ? strtolower( trim( (string) $input['package_manifest_digest'] ) ) : '',
			'size_bytes' => isset( $input['size_bytes'] ) ? absint( $input['size_bytes'] ) : 0,
		);
		if ( '' === $identity['version'] || strlen( $identity['version'] ) > 64 ) return new WP_Error( 'mad4b_self_update_target_version_invalid', 'Target version is invalid.' );
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $identity['source_commit_sha'] ) ) return new WP_Error( 'mad4b_self_update_target_source_invalid', 'Target source_commit_sha is invalid.' );
		foreach ( array( 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $identity[ $field ] ) ) return new WP_Error( 'mad4b_self_update_target_identity_invalid', 'Target exact build identity is invalid.', array( 'field' => $field ) );
		}
		return $identity;
	}

	private static function verify_archive( $path, array $target ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_self_update_archive_missing', 'Control Plane archive is unavailable.' );
		$sha = strtolower( (string) hash_file( 'sha256', $path ) );
		if ( ! hash_equals( $target['archive_sha256'], $sha ) ) return new WP_Error( 'mad4b_self_update_archive_hash_mismatch', 'Control Plane archive SHA-256 mismatch.' );
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_self_update_zip_runtime_unavailable', 'ZipArchive is required for Control Plane archive validation.' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) return new WP_Error( 'mad4b_self_update_zip_invalid', 'Control Plane archive cannot be opened.' );
		$required = array(
			'mad4b-site-control-plane/mad4b-site-control-plane.php' => false,
			'mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json' => false,
		);
		$runtime_php_files = array();
		$runtime_php_limit = 2000;
		if ( $zip->numFiles > 10000 ) {
			$zip->close();
			return new WP_Error( 'mad4b_self_update_zip_entry_count_exceeded', 'Control Plane archive contains too many entries.' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( '' === $name || 0 === strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( '/' . $name, '/../' ) || 0 !== strpos( $name, 'mad4b-site-control-plane/' ) ) {
				$zip->close();
				return new WP_Error( 'mad4b_self_update_zip_path_invalid', 'Control Plane archive contains an unsafe or unexpected path.' );
			}
			if ( array_key_exists( $name, $required ) ) $required[ $name ] = true;
			if ( preg_match( '/\.php$/i', $name ) ) {
				$relative = substr( $name, strlen( 'mad4b-site-control-plane/' ) );
				if ( count( $runtime_php_files ) >= $runtime_php_limit ) {
					$zip->close();
					return new WP_Error( 'mad4b_self_update_php_file_count_exceeded', 'Control Plane archive contains too many PHP runtime files for bounded cache invalidation.' );
				}
				$runtime_php_files[] = $relative;
			}
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
				$opsys = 0; $attr = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && 3 === (int) $opsys ) {
					$mode = ( $attr >> 16 ) & 0xF000;
					if ( 0xA000 === $mode ) {
						$zip->close();
						return new WP_Error( 'mad4b_self_update_zip_symlink_forbidden', 'Control Plane archive contains a symlink entry.' );
					}
				}
			}
		}
		foreach ( $required as $name => $present ) {
			if ( ! $present ) {
				$zip->close();
				return new WP_Error( 'mad4b_self_update_zip_required_file_missing', 'Control Plane archive is missing a required exact-build file.', array( 'file' => $name ) );
			}
		}

		$raw = $zip->getFromName( 'mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json' );
		$zip->close();
		if ( ! is_string( $raw ) || strlen( $raw ) > 4194304 ) return new WP_Error( 'mad4b_self_update_provenance_invalid', 'Embedded build provenance is invalid.' );
		$provenance = json_decode( $raw, true );
		if ( ! is_array( $provenance ) || 'mad4b.build-provenance.v1' !== ( isset( $provenance['contract'] ) ? (string) $provenance['contract'] : '' ) ) return new WP_Error( 'mad4b_self_update_provenance_contract_mismatch', 'Embedded build provenance contract mismatch.' );
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			$actual = isset( $provenance[ $field ] ) ? strtolower( trim( (string) $provenance[ $field ] ) ) : '';
			if ( ! hash_equals( $target[ $field ], $actual ) ) return new WP_Error( 'mad4b_self_update_provenance_identity_mismatch', 'Embedded build provenance does not match the reviewed target.', array( 'field' => $field ) );
		}
		if ( isset( $provenance['version'] ) && '' !== trim( (string) $provenance['version'] ) && ! hash_equals( $target['version'], trim( (string) $provenance['version'] ) ) ) {
			return new WP_Error( 'mad4b_self_update_provenance_version_mismatch', 'Embedded build version does not match the reviewed target.' );
		}
		return array( 'archive_sha256' => $sha, 'provenance' => $provenance, 'runtime_php_files' => array_values( array_unique( $runtime_php_files ) ) );
	}

	private static function installed_plugin_version_from_disk() {
		$path = defined( 'MAD4B_SCP_FILE' ) ? (string) MAD4B_SCP_FILE : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) return '';

		if ( function_exists( 'get_file_data' ) ) {
			$headers = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
			$version = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
			if ( '' !== $version ) return $version;
		}

		$raw = file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $raw ) ) return '';
		if ( 1 !== preg_match( '/^[ \\t\\/*#@]*Version:\\s*(.+)$/mi', $raw, $matches ) ) return '';
		return trim( (string) $matches[1] );
	}

	private static function installed_provenance() {
		$path = rtrim( (string) MAD4B_SCP_DIR, "/\\\\" ) . '/MAD4B-BUILD-PROVENANCE.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return array();
		$raw = file_get_contents( $path );
		$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $row ) ? $row : array();
	}

	private static function installed_identity() {
		$disk_version = self::installed_plugin_version_from_disk();
		$identity = array(
			'version' => '' !== $disk_version ? $disk_version : ( defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '' ),
			'source_commit_sha' => '',
			'build_fingerprint' => '',
			'package_manifest_digest' => '',
			'artifact_identity' => '',
		);
		$row = self::installed_provenance();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( isset( $row[ $field ] ) ) $identity[ $field ] = strtolower( trim( (string) $row[ $field ] ) );
		}
		if ( isset( $row['artifact_identity'] ) ) $identity['artifact_identity'] = trim( (string) $row['artifact_identity'] );
		return $identity;
	}

	private static function verify_installed_identity( array $target ) {
		$current = self::installed_identity();
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $field ) {
			if ( empty( $current[ $field ] ) || ! hash_equals( $target[ $field ], $current[ $field ] ) ) return new WP_Error( 'mad4b_self_update_installed_identity_mismatch', 'Installed Control Plane identity does not match the target after replacement.', array( 'field' => $field, 'current' => isset( $current[ $field ] ) ? $current[ $field ] : '' ) );
		}
		$expected_artifact = isset( $target['artifact_identity'] ) ? trim( (string) $target['artifact_identity'] ) : '';
		if ( '' !== $expected_artifact && ( empty( $current['artifact_identity'] ) || ! hash_equals( $expected_artifact, (string) $current['artifact_identity'] ) ) ) {
			return new WP_Error( 'mad4b_self_update_installed_artifact_identity_mismatch', 'Installed Control Plane artifact identity does not match the exact target after replacement.', array(
				'current' => isset( $current['artifact_identity'] ) ? $current['artifact_identity'] : '',
			) );
		}

		$target_version = isset( $target['version'] ) ? trim( (string) $target['version'] ) : '';
		$disk_version = isset( $current['version'] ) ? trim( (string) $current['version'] ) : '';
		$provenance = self::installed_provenance();
		$provenance_version = isset( $provenance['control_plane_version'] ) ? trim( (string) $provenance['control_plane_version'] ) : '';
		$loaded_version = defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '';

		if ( '' === $disk_version || ( '' !== $target_version && ! hash_equals( $target_version, $disk_version ) ) ) {
			return new WP_Error( 'mad4b_self_update_installed_version_mismatch', 'Installed Control Plane plugin header version does not match the target after replacement.', array(
				'target_version' => $target_version,
				'installed_disk_version' => $disk_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
		if ( '' === $provenance_version || ( '' !== $target_version && ! hash_equals( $target_version, $provenance_version ) ) ) {
			return new WP_Error( 'mad4b_self_update_installed_provenance_version_mismatch', 'Installed Control Plane provenance version does not match the target after replacement.', array(
				'target_version' => $target_version,
				'installed_disk_version' => $disk_version,
				'provenance_version' => $provenance_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
		if ( ! hash_equals( $disk_version, $provenance_version ) ) {
			return new WP_Error( 'mad4b_self_update_disk_provenance_version_mismatch', 'Installed Control Plane plugin header and provenance versions disagree after replacement.', array(
				'installed_disk_version' => $disk_version,
				'provenance_version' => $provenance_version,
				'loaded_runtime_version' => $loaded_version,
			) );
		}
		return $current;
	}

	private static function invalidate_runtime_caches( array $runtime_php_files ) {
		$root = defined( 'MAD4B_SCP_DIR' ) ? realpath( MAD4B_SCP_DIR ) : false;
		$limit = 2000;
		$result = array(
			'contract' => 'mad4b.self-update-runtime-cache-invalidation.v2',
			'source' => 'verified_archive_index',
			'available' => function_exists( 'wp_opcache_invalidate' ) || function_exists( 'opcache_invalidate' ),
			'archive_php_file_count' => count( $runtime_php_files ),
			'files_considered' => 0,
			'invalidation_attempts' => 0,
			'invalidation_successes' => 0,
			'invalidation_failures' => 0,
			'missing_after_install' => 0,
			'invalid_member_paths' => 0,
			'bounded_file_limit' => $limit,
			'filesystem_tree_scan_used' => false,
			'global_opcache_reset_used' => false,
			'shell_used' => false,
		);
		clearstatcache( true );
		if ( false === $root || ! is_dir( $root ) ) return $result;
		$root_normalized = rtrim( wp_normalize_path( $root ), '/' );
		foreach ( array_slice( array_values( array_unique( $runtime_php_files ) ), 0, $limit ) as $relative ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			if ( '' === $relative || ! preg_match( '/\.php$/i', $relative ) || false !== strpos( '/' . $relative, '/../' ) ) {
				$result['invalid_member_paths']++;
				continue;
			}
			$candidate = realpath( $root_normalized . '/' . $relative );
			if ( false === $candidate ) {
				$result['missing_after_install']++;
				continue;
			}
			$candidate = wp_normalize_path( $candidate );
			if ( 0 !== strpos( $candidate, $root_normalized . '/' ) ) {
				$result['invalid_member_paths']++;
				continue;
			}
			$result['files_considered']++;
			if ( empty( $result['available'] ) ) continue;
			$result['invalidation_attempts']++;
			$ok = function_exists( 'wp_opcache_invalidate' )
				? wp_opcache_invalidate( $candidate, true )
				: opcache_invalidate( $candidate, true );
			if ( false === $ok ) $result['invalidation_failures']++;
			else $result['invalidation_successes']++;
		}
		return $result;
	}

	private static function backup_current() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		$source = wp_normalize_path( MAD4B_SCP_DIR );
		if ( ! is_dir( $source ) || is_link( $source ) ) return new WP_Error( 'mad4b_self_update_backup_source_invalid', 'Installed Control Plane directory is unavailable for backup.' );
		$destination = trailingslashit( $root ) . 'control-plane-self-update-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false );
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_self_update_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable.' );
		$copy = copy_dir( $source, $destination );
		if ( is_wp_error( $copy ) ) return $copy;
		return array( 'created' => true, 'backup_path' => $destination, 'backup_id' => basename( $destination ) );
	}

	private static function rollback( array $backup, array $before, array $runtime_php_files ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( empty( $backup['backup_path'] ) || ! is_dir( $backup['backup_path'] ) ) return new WP_Error( 'mad4b_self_update_rollback_backup_missing', 'Control Plane rollback backup is missing.' );
		if ( ! WP_Filesystem() ) return new WP_Error( 'mad4b_self_update_rollback_filesystem_unavailable', 'WordPress filesystem abstraction is unavailable for rollback.' );
		global $wp_filesystem;
		$root = untrailingslashit( wp_normalize_path( MAD4B_SCP_DIR ) );
		if ( is_plugin_active( plugin_basename( MAD4B_SCP_FILE ) ) ) deactivate_plugins( plugin_basename( MAD4B_SCP_FILE ), true, is_multisite() && is_plugin_active_for_network( plugin_basename( MAD4B_SCP_FILE ) ) );
		if ( file_exists( $root ) && ! $wp_filesystem->delete( $root, true ) ) return new WP_Error( 'mad4b_self_update_rollback_delete_failed', 'Unable to remove failed Control Plane candidate before rollback.' );
		$copy = copy_dir( $backup['backup_path'], $root );
		if ( is_wp_error( $copy ) ) return $copy;
		wp_clean_plugins_cache( true );
		// Target PHP may have been compiled after its first invalidation (for
		// example during activation/readback). Invalidate overlapping paths again
		// after old bytes are restored and before the old plugin is reactivated.
		self::invalidate_runtime_caches( $runtime_php_files );
		return self::restore_activation_state( $before );
	}

	private static function activation_state() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		return array(
			'version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'active' => is_plugin_active( $plugin ),
			'network_active' => is_multisite() ? is_plugin_active_for_network( $plugin ) : false,
		);
	}

	private static function restore_activation_state( array $before ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = plugin_basename( MAD4B_SCP_FILE );
		$active = is_plugin_active( $plugin );
		if ( ! empty( $before['active'] ) && ! $active ) {
			$result = activate_plugin( $plugin, '', ! empty( $before['network_active'] ) );
			if ( is_wp_error( $result ) ) return $result;
		} elseif ( empty( $before['active'] ) && $active ) {
			deactivate_plugins( $plugin, false, ! empty( $before['network_active'] ) );
		}
		return true;
	}

	private static function temp_archive_path() {
		if ( ! class_exists( 'MAD4B_SCP_Policy' ) ) return new WP_Error( 'mad4b_self_update_protected_storage_unavailable', 'Protected MAD4B storage policy is unavailable.' );
		$root = MAD4B_SCP_Policy::prepare_backup_root();
		if ( is_wp_error( $root ) ) return $root;
		$base = trailingslashit( $root ) . 'control-plane-upload-staging';
		if ( is_link( $base ) ) return new WP_Error( 'mad4b_self_update_temp_symlink_forbidden', 'Self-update staging directory cannot be a symlink.' );
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) return new WP_Error( 'mad4b_self_update_temp_dir_failed', 'Unable to prepare protected self-update staging directory.' );
		@chmod( $base, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_writable( $base ) ) return new WP_Error( 'mad4b_self_update_temp_dir_not_writable', 'Protected self-update staging directory is not writable.' );
		return trailingslashit( $base ) . wp_generate_uuid4() . '.zip';
	}

	private static function environment_resolution() {
		if ( class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'environment_resolution' ) ) {
			$resolution = MAD4B_SCP_Site_Profile::environment_resolution();
			if ( is_array( $resolution ) && ! empty( $resolution['effective_environment'] ) ) return $resolution;
		}
		$wordpress = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		return array(
			'contract' => 'mad4b.site-profile-environment-resolution.v1',
			'wordpress_environment' => $wordpress,
			'profile_environment' => '',
			'exact_profile_bound' => false,
			'effective_environment' => $wordpress,
			'effective_source' => 'wordpress',
			'suggested_environment' => $wordpress,
			'wordpress_profile_mismatch' => false,
			'hostname_hint_used_for_authority' => false,
		);
	}

	private static function environment_allowed( $remote ) {
		$resolution = self::environment_resolution();
		$environment = sanitize_key( (string) ( isset( $resolution['effective_environment'] ) ? $resolution['effective_environment'] : '' ) );
		if ( $remote ) {
			return 'staging' === $environment
				&& class_exists( 'MAD4B_SCP_Site_Profile' )
				&& MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' );
		}
		if ( in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return true;
		return 'production' === $environment
			&& defined( 'MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED' )
			&& true === MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED
			&& class_exists( 'MAD4B_SCP_Site_Profile' )
			&& MAD4B_SCP_Site_Profile::environment_allowed( array( 'production' ), 'write' );
	}

	private static function public_manifest( array $manifest ) {
		return array(
			'version' => $manifest['version'],
			'display_version' => $manifest['display_version'],
			'source_commit_sha' => $manifest['source_commit_sha'],
			'archive_sha256' => $manifest['archive_sha256'],
			'build_fingerprint' => $manifest['build_fingerprint'],
			'package_manifest_digest' => $manifest['package_manifest_digest'],
			'size_bytes' => $manifest['size_bytes'],
			'release_verdict_run_id' => isset( $manifest['release_verdict_run_id'] ) ? absint( $manifest['release_verdict_run_id'] ) : 0,
			'release_verdict_success' => true,
			'release_root_trust_verified' => ! empty( $manifest['release_root_trust_verified'] ),
			'published_from_master' => ! empty( $manifest['published_from_master'] ),
		);
	}

	private static function audit( $channel, array $target, $success, array $extra = array() ) {
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return;
		$payload = array_merge(
			array(
				'channel' => $channel,
				'plugin' => plugin_basename( MAD4B_SCP_FILE ),
				'target_source_commit_sha' => isset( $target['source_commit_sha'] ) ? $target['source_commit_sha'] : '',
				'target_archive_sha256' => isset( $target['archive_sha256'] ) ? $target['archive_sha256'] : '',
				'target_build_fingerprint' => isset( $target['build_fingerprint'] ) ? $target['build_fingerprint'] : '',
				'target_package_manifest_digest' => isset( $target['package_manifest_digest'] ) ? $target['package_manifest_digest'] : '',
				'production_mutation_performed' => false,
			),
			$extra
		);
		MAD4B_SCP_Audit::record( 'mad4b/control-plane-self-update', $payload, $success ? 'success' : 'failure' );
	}

	private static function native_plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function native_apply_schema() {
		$schema = self::native_plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$schema['required'][] = 'expected_plan_sha256';
		return $schema;
	}

	private static function bootstrap_apply_schema() {
		$schema = self::native_apply_schema();
		$schema['properties']['confirmation'] = array( 'type' => 'string', 'enum' => array( self::BOOTSTRAP_CONFIRMATION ) );
		$schema['required'][] = 'confirmation';
		return $schema;
	}
	private static function plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'version' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'source_commit_sha' => array( 'type' => 'string', 'minLength' => 40, 'maxLength' => 40, 'pattern' => '^[A-Fa-f0-9]{40}$' ),
				'archive_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'build_fingerprint' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'package_manifest_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'size_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_UPLOAD_BYTES ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes', 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function apply_schema() {
		$schema = self::plan_schema();
		$schema['properties']['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
		$schema['properties']['package_base64'] = array( 'type' => 'string', 'minLength' => 16, 'maxLength' => (int) ceil( self::MAX_UPLOAD_BYTES * 4 / 3 ) + 16 );
		$schema['required'][] = 'expected_plan_sha256';
		$schema['required'][] = 'package_base64';
		return $schema;
	}

	private static function digest( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
