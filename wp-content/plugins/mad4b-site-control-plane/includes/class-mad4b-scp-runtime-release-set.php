<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact, fail-closed coordinator for the MAD4B Control Plane + MCP Adapter pair.
 *
 * The Control Plane update channel is authoritative for the target release set.
 * MCP Adapter remains protected from generic plugin mutation and is opened only
 * inside this request-local coordinator after exact pair validation.
 */
final class MAD4B_SCP_Runtime_Release_Set {
	const CONTRACT = 'mad4b.runtime-release-set.v1';
	const PLAN_CONTRACT = 'mad4b.runtime-release-set-plan.v1';
	const APPLY_CONTRACT = 'mad4b.runtime-release-set-apply.v1';
	const POLICY_CONTRACT = 'mad4b.runtime-release-policy.v1';
	const TRANSACTION_CONTRACT = 'mad4b.runtime-release-transaction.v1';
	const TRANSACTION_OPTION = 'mad4b_runtime_release_transaction_v1';
	const LAST_RECEIPT_OPTION = 'mad4b_runtime_release_receipt_v1';
	const CONFIRMATION = 'APPLY CERTIFIED STAGING RUNTIME RELEASE SET';
	const BOOTSTRAP_APPLY_CONTRACT = 'mad4b.runtime-release-set-bootstrap-apply.v1';
	const BOOTSTRAP_APPLY_ABILITY = 'mad4b/runtime-release-set-bootstrap-apply';

	private static $booted = false;
	private static $component_context = array();

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_action( 'admin_post_mad4b_runtime_release_set_apply', array( __CLASS__, 'handle_admin_apply' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'reconcile_admin_readback' ), 45 );

		$plugin = plugin_basename( MAD4B_SCP_FILE );
		add_filter( 'plugin_action_links_' . $plugin, array( __CLASS__, 'plugin_action_links' ), 25, 1 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/runtime-release-set-status' ) ) {
			wp_register_ability(
				'mad4b/runtime-release-set-status',
				array(
					'label' => 'MAD4B Runtime Release Set Status',
					'description' => 'Read the exact Control Plane and MCP Adapter pair state without mutation or outbound refresh.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'status' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => array( 'type' => 'object', 'additionalProperties' => false ),
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

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/runtime-release-set-plan' ) ) {
			wp_register_ability(
				'mad4b/runtime-release-set-plan',
				array(
					'label' => 'Plan Certified Runtime Release Set',
					'description' => 'Resolve the exact certified Control Plane and MCP Adapter transition without mutation.',
					'category' => 'mad4b-read',
					'execute_callback' => array( __CLASS__, 'plan' ),
					'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
					'input_schema' => self::plan_schema(),
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

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'mad4b/runtime-release-set-apply' ) ) {
			wp_register_ability(
				'mad4b/runtime-release-set-apply',
				array(
					'label' => 'Apply Certified Runtime Release Set',
					'description' => 'Advance one exact Staging Control Plane and MCP Adapter release-set transition with bounded reboot continuation.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'apply' ),
					'permission_callback' => array( __CLASS__, 'can_apply' ),
					'input_schema' => self::apply_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
						'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					),
				)
			);
		}

		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( self::BOOTSTRAP_APPLY_ABILITY ) ) {
			wp_register_ability(
				self::BOOTSTRAP_APPLY_ABILITY,
				array(
					'label' => 'Bootstrap Certified Runtime Release Set',
					'description' => 'Apply the exact certified Staging Control Plane and MCP Adapter release set using the dedicated OAuth authority step-up lane without enabling general Write, Developer, Breakglass, or raw SQL authority.',
					'category' => 'mad4b-admin',
					'execute_callback' => array( __CLASS__, 'bootstrap_apply' ),
					'permission_callback' => array( __CLASS__, 'can_bootstrap_apply' ),
					'input_schema' => self::apply_schema(),
					'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
					'meta' => array(
						'public' => false,
						'show_in_rest' => false,
						'mcp' => array(
							'public' => false,
							'type' => 'tool',
							'surface' => 'enrollment',
							'chatgpt_direct_step_up' => true,
							'exact_chatgpt_client_required' => true,
							'bootstrap_only' => true,
							'normal_write_authority_required' => false,
							'authority_mutation_allowed' => false,
							'production_allowed' => false,
							'generic_raw_sql_breakglass_included' => false,
						),
						'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					),
				)
			);
		}

	}

	public static function can_apply( $input = null ) {
		$granted = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $granted ) || ! $granted ) return $granted;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled.' );
		$staging = self::staging_guard();
		if ( is_wp_error( $staging ) ) return $staging;
		if ( self::breakglass_active() ) return new WP_Error( 'mad4b_runtime_release_set_breakglass_denied', 'Runtime release-set updates are unavailable while Breakglass is active.' );
		if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
		return MAD4B_SCP_Authorization::authorize_mutation(
			'mad4b/runtime-release-set-apply',
			'mad4b-admin',
			'core',
			is_array( $input ) ? $input : array()
		);
	}


	public static function can_bootstrap_apply( $input = null ) {
		$admin = MAD4B_SCP_Policy::can_admin();
		if ( is_wp_error( $admin ) || ! $admin ) return $admin;
		if ( ! current_user_can( 'update_plugins' ) ) return new WP_Error( 'mad4b_runtime_release_set_bootstrap_update_capability_required', 'Plugin update capability is required.' );
		$staging = self::staging_guard();
		if ( is_wp_error( $staging ) ) return $staging;
		if ( self::breakglass_active() ) return new WP_Error( 'mad4b_runtime_release_set_bootstrap_breakglass_denied', 'Runtime release-set bootstrap is unavailable while Breakglass is active.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) {
			return new WP_Error( 'mad4b_runtime_release_set_bootstrap_bearer_required', 'Runtime release-set bootstrap requires a verified OAuth bearer.' );
		}
		if ( ! defined( 'MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) {
			return new WP_Error( 'mad4b_runtime_release_set_bootstrap_step_up_scope_required', 'Runtime release-set bootstrap requires the dedicated Staging authority step-up scope.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' )
			|| ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) {
			return new WP_Error( 'mad4b_runtime_release_set_bootstrap_chatgpt_client_required', 'Runtime release-set bootstrap requires OAuth attribution to the exact ChatGPT CIMD client.' );
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! MAD4B_SCP_Site_Profile::user_is_enrolled( $user_id ) ) {
			return new WP_Error( 'mad4b_runtime_release_set_bootstrap_subject_not_enrolled', 'Runtime release-set bootstrap requires the enrolled administrator.' );
		}
		if ( ! MAD4B_SCP_Site_Profile::write_enabled() ) {
			return new WP_Error( 'mad4b_runtime_release_set_bootstrap_profile_write_disabled', 'Runtime release-set bootstrap requires the enrolled Site Profile write flag.' );
		}
		return true;
	}

	public static function chatgpt_step_up_tools() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) return array();
		if ( 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) return array();
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return array();
		if ( self::breakglass_active() ) return array();
		return array( self::BOOTSTRAP_APPLY_ABILITY );
	}

	public static function bootstrap_apply( $input ) {
		$access = self::can_bootstrap_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		$result = self::apply_internal( $input, false, true );
		if ( is_wp_error( $result ) ) return $result;
		if ( is_array( $result ) ) {
			$result['bootstrap_contract'] = self::BOOTSTRAP_APPLY_CONTRACT;
			$result['bootstrap_only'] = true;
			$result['normal_write_authority_required'] = false;
			$result['authority_mutation_performed'] = false;
			$result['developer_authority_mutation_performed'] = false;
			$result['developer_breakglass_mutation_performed'] = false;
			$result['production_mutation_performed'] = false;
		}
		return $result;
	}

	public static function target_adapter_version() {
		$policy = self::policy();
		return isset( $policy['target_adapter_version'] ) ? trim( (string) $policy['target_adapter_version'] ) : '';
	}

	public static function component_apply_in_progress( $provider = '' ) {
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) return ! empty( self::$component_context );
		return isset( self::$component_context[ $provider ] ) && is_array( self::$component_context[ $provider ] );
	}

	public static function active_component_target_version( $provider ) {
		$provider = sanitize_key( (string) $provider );
		return isset( self::$component_context[ $provider ]['version'] )
			? (string) self::$component_context[ $provider ]['version']
			: '';
	}

	public static function component_mode( $provider ) {
		$provider = sanitize_key( (string) $provider );
		return isset( self::$component_context[ $provider ]['mode'] )
			? sanitize_key( (string) self::$component_context[ $provider ]['mode'] )
			: '';
	}

	public static function status( $input = array() ) {
		unset( $input );
		$policy = self::policy();
		$control = class_exists( 'MAD4B_SCP_Self_Update' ) ? MAD4B_SCP_Self_Update::cached_status() : array();
		$current_control = isset( $control['current'] ) && is_array( $control['current'] ) ? $control['current'] : array();
		$cached_target = isset( $control['native_wordpress_update']['target'] ) && is_array( $control['native_wordpress_update']['target'] )
			? $control['native_wordpress_update']['target']
			: array();
		$current_adapter = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::installed_version( 'mcp_adapter' ) : '';
		$release_set = self::release_set_from_control_target( $cached_target, $policy );
		$target_adapter = isset( $release_set['mcp_adapter']['version'] ) ? (string) $release_set['mcp_adapter']['version'] : self::target_adapter_version();
		$transaction = self::read_transaction();
		$readback = self::runtime_readback( $target_adapter, $release_set, false );

		$control_target_sha = isset( $release_set['control_plane']['source_commit_sha'] ) ? (string) $release_set['control_plane']['source_commit_sha'] : '';
		$current_control_sha = isset( $current_control['source_commit_sha'] ) ? (string) $current_control['source_commit_sha'] : '';
		$control_current = '' !== $control_target_sha && '' !== $current_control_sha && hash_equals( $control_target_sha, $current_control_sha );
		$adapter_current = '' !== $target_adapter && '' !== $current_adapter && hash_equals( $target_adapter, $current_adapter );
		$ready = $control_current && $adapter_current && ! empty( $readback['ready'] );

		return array(
			'contract' => self::CONTRACT,
			'current' => array(
				'control_plane' => $current_control,
				'mcp_adapter' => array( 'version' => $current_adapter ),
			),
			'target' => $release_set,
			'release_set_sha256' => self::release_set_digest( $release_set ),
			'control_plane_current' => $control_current,
			'mcp_adapter_current' => $adapter_current,
			'runtime_readback' => $readback,
			'ready' => $ready,
			'state' => $ready ? 'ready' : ( self::transaction_active( $transaction ) ? 'transition_in_progress' : 'update_or_refresh_required' ),
			'transaction' => self::public_transaction( $transaction ),
			'update_order' => isset( $policy['update_order'] ) ? $policy['update_order'] : array( 'control_plane', 'mcp_adapter' ),
			'pair_certification_required' => true,
			'generic_plugin_update_for_adapter_allowed' => false,
			'production_auto_apply' => false,
			'breakglass_included' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public static function plan( $input ) {
		$input = is_array( $input ) ? $input : array();
		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		$policy = self::policy();
		$blockers = array();

		if ( empty( $policy ) || self::POLICY_CONTRACT !== ( isset( $policy['contract'] ) ? (string) $policy['contract'] : '' ) ) {
			$blockers[] = 'runtime_release_policy_invalid';
		}
		$staging = self::staging_guard();
		if ( is_wp_error( $staging ) ) $blockers[] = $staging->get_error_code();
		if ( ! current_user_can( 'update_plugins' ) ) $blockers[] = 'update_plugins_capability_required';
		if ( self::breakglass_active() ) $blockers[] = 'breakglass_active';

		$control_plan = class_exists( 'MAD4B_SCP_Self_Update' )
			? MAD4B_SCP_Self_Update::native_plan( array( 'reason' => $reason ) )
			: new WP_Error( 'mad4b_runtime_release_set_control_plane_unavailable', 'Control Plane self-update coordinator is unavailable.' );
		if ( is_wp_error( $control_plan ) ) {
			$blockers[] = $control_plan->get_error_code();
			$control_plan = array();
		}

		$current_control = isset( $control_plan['current'] ) && is_array( $control_plan['current'] ) ? $control_plan['current'] : array();
		$target_control = isset( $control_plan['target'] ) && is_array( $control_plan['target'] ) ? $control_plan['target'] : array();
		$release_set = self::release_set_from_control_target( $target_control, $policy );
		$release_set_sha = self::release_set_digest( $release_set );
		if ( '' === $release_set_sha ) $blockers[] = 'runtime_release_set_identity_incomplete';

		$current_control_sha = isset( $current_control['source_commit_sha'] ) ? (string) $current_control['source_commit_sha'] : '';
		$target_control_sha = isset( $release_set['control_plane']['source_commit_sha'] ) ? (string) $release_set['control_plane']['source_commit_sha'] : '';
		$control_update_required = '' !== $target_control_sha && ( '' === $current_control_sha || ! hash_equals( $target_control_sha, $current_control_sha ) );

		$control_blockers = isset( $control_plan['blockers'] ) && is_array( $control_plan['blockers'] ) ? $control_plan['blockers'] : array();
		if ( ! $control_update_required ) $control_blockers = array_values( array_diff( $control_blockers, array( 'already_on_exact_source_commit' ) ) );
		foreach ( $control_blockers as $item ) $blockers[] = 'control_plane:' . sanitize_key( (string) $item );

		$current_adapter = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::installed_version( 'mcp_adapter' ) : '';
		$target_adapter = isset( $release_set['mcp_adapter']['version'] ) ? (string) $release_set['mcp_adapter']['version'] : '';
		$adapter_update_required = '' !== $target_adapter && ( '' === $current_adapter || ! hash_equals( $target_adapter, $current_adapter ) );

		$supported = isset( $policy['supported_transition_adapter_versions'] ) && is_array( $policy['supported_transition_adapter_versions'] )
			? array_map( 'strval', $policy['supported_transition_adapter_versions'] )
			: array();
		if ( '' === $current_adapter || ! in_array( $current_adapter, $supported, true ) ) $blockers[] = 'current_adapter_not_supported_transition';
		if ( '' === $target_adapter ) $blockers[] = 'target_adapter_version_missing';

		$profile = array();
		if ( ! $control_update_required && '' !== $target_adapter && class_exists( 'MAD4B_SCP_Provider_Contracts' ) && method_exists( 'MAD4B_SCP_Provider_Contracts', 'get_for_version' ) ) {
			$profile = MAD4B_SCP_Provider_Contracts::get_for_version( 'mcp_adapter', $target_adapter );
			if ( empty( $profile ) ) $blockers[] = 'target_adapter_profile_not_certified';
			elseif ( ! self::profile_matches_release_set( $profile, $release_set ) ) $blockers[] = 'target_adapter_profile_release_set_mismatch';
		}

		$transaction = self::read_transaction();
		if ( self::transaction_active( $transaction ) ) {
			$stored_sha = isset( $transaction['release_set_sha256'] ) ? (string) $transaction['release_set_sha256'] : '';
			if ( '' === $stored_sha || '' === $release_set_sha || ! hash_equals( $stored_sha, $release_set_sha ) ) $blockers[] = 'active_transaction_release_set_drift';
		}

		$readback = ( ! $control_update_required && ! $adapter_update_required )
			? self::runtime_readback( $target_adapter, $release_set, true )
			: array( 'ready' => false, 'state' => 'deferred_until_exact_pair_installed' );

		$operation = 'current';
		if ( $control_update_required ) $operation = 'update_control_plane';
		elseif ( $adapter_update_required ) $operation = 'update_mcp_adapter';
		elseif ( self::transaction_active( $transaction ) && ! empty( $readback['ready'] ) ) $operation = 'finalize_release_set';
		elseif ( self::transaction_active( $transaction ) ) $operation = 'await_runtime_readback';

		$plan = array(
			'contract' => self::PLAN_CONTRACT,
			'operation' => $operation,
			'current' => array(
				'control_plane' => $current_control,
				'mcp_adapter' => array( 'version' => $current_adapter ),
			),
			'target' => $release_set,
			'release_set_sha256' => $release_set_sha,
			'control_plane' => array(
				'update_required' => $control_update_required,
				'plan_sha256' => isset( $control_plan['plan_sha256'] ) ? (string) $control_plan['plan_sha256'] : '',
			),
			'mcp_adapter' => array(
				'update_required' => $adapter_update_required,
				'current_version' => $current_adapter,
				'target_version' => $target_adapter,
				'exact_profile_ready' => ! empty( $profile ),
			),
			'runtime_readback' => $readback,
			'transaction' => self::public_transaction( $transaction ),
			'update_order' => array( 'control_plane', 'mcp_adapter' ),
			'reboot_boundary_between_components' => true,
			'pair_certification_required' => true,
			'generic_plugin_update_for_adapter_allowed' => false,
			'caller_url_allowed' => false,
			'caller_path_allowed' => false,
			'caller_package_bytes_allowed' => false,
			'backup_required' => true,
			'rollback_on_component_disk_readback_failure' => true,
			'production_allowed' => false,
			'breakglass_included' => false,
			'eligible' => empty( $blockers ),
			'blockers' => array_values( array_unique( $blockers ) ),
			'reason' => $reason,
			'mutation_performed' => false,
			'authorizing' => false,
		);
		sort( $plan['blockers'], SORT_STRING );
		$plan['plan_sha256'] = self::digest( $plan );
		$plan['write_binding'] = array( 'expected_plan_sha256' => $plan['plan_sha256'] );
		return $plan;
	}

	public static function apply( $input ) {
		$access = self::can_apply( $input );
		if ( is_wp_error( $access ) || ! $access ) return $access;
		return self::apply_internal( $input, false, false );
	}

	private static function apply_internal( $input, $local_admin, $bootstrap_step_up = false ) {
		$input = is_array( $input ) ? $input : array();
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		$confirmation = isset( $input['confirmation'] ) ? (string) $input['confirmation'] : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) ) return new WP_Error( 'mad4b_runtime_release_set_plan_digest_required', 'Exact runtime release-set plan digest is required.' );
		if ( ! hash_equals( self::CONFIRMATION, $confirmation ) ) return new WP_Error( 'mad4b_runtime_release_set_confirmation_required', 'Exact runtime release-set confirmation is required.' );

		if ( $local_admin ) {
			$staging = self::staging_guard();
			if ( is_wp_error( $staging ) ) return $staging;
			if ( ! current_user_can( 'update_plugins' ) ) return new WP_Error( 'mad4b_runtime_release_set_update_capability_required', 'Plugin update capability is required.' );
			if ( self::breakglass_active() ) return new WP_Error( 'mad4b_runtime_release_set_breakglass_denied', 'Runtime release-set updates are unavailable while Breakglass is active.' );
		}

		$reason = isset( $input['reason'] ) ? sanitize_text_field( (string) $input['reason'] ) : '';
		$plan = self::plan( array( 'reason' => $reason ) );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! hash_equals( $plan['plan_sha256'], $expected ) ) return new WP_Error(
			'mad4b_runtime_release_set_plan_changed',
			'Runtime release-set plan changed since review.',
			array( 'current_plan_sha256' => $plan['plan_sha256'], 'expected_plan_sha256' => $expected )
		);
		if ( empty( $plan['eligible'] ) ) return new WP_Error( 'mad4b_runtime_release_set_preflight_blocked', 'Runtime release-set preflight blocked the mutation.', array( 'blockers' => $plan['blockers'] ) );

		$operation = isset( $plan['operation'] ) ? (string) $plan['operation'] : '';
		if ( 'current' === $operation ) return self::result( $plan, 'current', false, false );

		if ( 'finalize_release_set' === $operation ) {
			self::commit_transaction( $plan, 'explicit_finalize' );
			return self::result( $plan, 'committed', false, false );
		}
		if ( 'await_runtime_readback' === $operation ) return self::result( $plan, 'awaiting_runtime_readback', false, true );

		if ( 'update_control_plane' === $operation ) {
			$transaction = self::begin_transaction( $plan, 'control_plane_update_pending' );
			if ( is_wp_error( $transaction ) ) return $transaction;
			if ( $bootstrap_step_up ) {
				$revalidate = self::can_bootstrap_apply( $input );
				if ( is_wp_error( $revalidate ) || ! $revalidate ) {
					self::fail_transaction( $transaction, 'control_plane_bootstrap_revalidation_failed', is_wp_error( $revalidate ) ? $revalidate->get_error_code() : 'bootstrap_revalidation_failed' );
					return is_wp_error( $revalidate ) ? $revalidate : new WP_Error( 'mad4b_runtime_release_set_bootstrap_revalidation_failed', 'Runtime release-set bootstrap became ineligible before Control Plane mutation.' );
				}
			}
			$cp = MAD4B_SCP_Self_Update::native_apply(
				array(
					'reason' => $reason,
					'expected_plan_sha256' => (string) $plan['control_plane']['plan_sha256'],
				),
				(bool) $bootstrap_step_up
			);
			if ( is_wp_error( $cp ) ) {
				self::fail_transaction( $transaction, 'control_plane_apply_failed', $cp->get_error_code() );
				return $cp;
			}
			self::transition_transaction( $transaction, 'control_plane_updated_awaiting_resume', array(
				'control_plane_result' => self::bounded_component_result( $cp ),
			) );
			return self::result( $plan, 'control_plane_updated_awaiting_resume', true, true );
		}

		if ( 'update_mcp_adapter' === $operation ) {
			$transaction = self::transaction_active( self::read_transaction() )
				? self::read_transaction()
				: self::begin_transaction( $plan, 'adapter_update_pending' );
			if ( is_wp_error( $transaction ) ) return $transaction;
			if ( $bootstrap_step_up ) {
				$revalidate = self::can_bootstrap_apply( $input );
				if ( is_wp_error( $revalidate ) || ! $revalidate ) {
					self::fail_transaction( $transaction, 'mcp_adapter_bootstrap_revalidation_failed', is_wp_error( $revalidate ) ? $revalidate->get_error_code() : 'bootstrap_revalidation_failed' );
					return is_wp_error( $revalidate ) ? $revalidate : new WP_Error( 'mad4b_runtime_release_set_bootstrap_revalidation_failed', 'Runtime release-set bootstrap became ineligible before MCP Adapter mutation.' );
				}
			}
			$target_version = (string) $plan['mcp_adapter']['target_version'];
			$component = self::with_component_context(
				'mcp_adapter',
				$target_version,
				'forward',
				static function () use ( $reason ) {
					$args = array(
						'provider_id' => 'mcp_adapter',
						'source' => 'certified_upstream_release',
						'reason' => $reason,
					);
					$component_plan = MAD4B_SCP_Plugin_Package::plan( $args );
					if ( is_wp_error( $component_plan ) ) return $component_plan;
					if ( empty( $component_plan['eligible'] ) ) return new WP_Error( 'mad4b_runtime_release_set_adapter_plan_blocked', 'Certified MCP Adapter package plan is blocked.', array( 'blockers' => $component_plan['blockers'] ) );
					$args['expected_plan_sha256'] = $component_plan['plan_sha256'];
					return MAD4B_SCP_Plugin_Package::apply( $args );
				}
			);
			if ( is_wp_error( $component ) ) {
				self::fail_transaction( $transaction, 'mcp_adapter_apply_failed', $component->get_error_code() );
				return $component;
			}
			self::transition_transaction( $transaction, 'awaiting_runtime_readback', array(
				'mcp_adapter_result' => self::bounded_component_result( $component ),
			) );
			return self::result( $plan, 'awaiting_runtime_readback', true, true );
		}

		return new WP_Error( 'mad4b_runtime_release_set_operation_invalid', 'Runtime release-set operation is invalid.' );
	}

	private static function with_component_context( $provider, $version, $mode, $callback ) {
		$provider = sanitize_key( (string) $provider );
		$version = trim( (string) $version );
		if ( '' === $provider || '' === $version || ! is_callable( $callback ) ) return new WP_Error( 'mad4b_runtime_release_set_component_context_invalid', 'Runtime release-set component context is invalid.' );
		if ( isset( self::$component_context[ $provider ] ) ) return new WP_Error( 'mad4b_runtime_release_set_component_context_reentrant', 'Runtime release-set component context is already active.' );
		self::$component_context[ $provider ] = array( 'version' => $version, 'mode' => sanitize_key( (string) $mode ) );
		try {
			return call_user_func( $callback );
		} finally {
			unset( self::$component_context[ $provider ] );
		}
	}

	private static function release_set_from_control_target( array $target, array $policy ) {
		$set = isset( $target['runtime_release_set'] ) && is_array( $target['runtime_release_set'] )
			? $target['runtime_release_set']
			: array();
		$adapter = isset( $set['mcp_adapter'] ) && is_array( $set['mcp_adapter'] ) ? $set['mcp_adapter'] : array();
		if ( empty( $adapter ) ) {
			$version = isset( $policy['target_adapter_version'] ) ? trim( (string) $policy['target_adapter_version'] ) : '';
			$profile = '' !== $version && class_exists( 'MAD4B_SCP_Provider_Contracts' ) && method_exists( 'MAD4B_SCP_Provider_Contracts', 'get_for_version' )
				? MAD4B_SCP_Provider_Contracts::get_for_version( 'mcp_adapter', $version )
				: array();
			$adapter = array(
				'version' => $version,
				'archive_sha256' => isset( $profile['archive_sha256'] ) ? strtolower( (string) $profile['archive_sha256'] ) : '',
				'archive_bytes' => isset( $profile['archive_bytes'] ) ? (int) $profile['archive_bytes'] : 0,
				'package_url' => isset( $profile['package_url'] ) ? (string) $profile['package_url'] : '',
			);
		}
		$control = array();
		foreach ( array( 'version', 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest', 'size_bytes' ) as $field ) {
			if ( isset( $target[ $field ] ) ) $control[ $field ] = $target[ $field ];
		}
		return array(
			'contract' => self::CONTRACT,
			'control_plane' => $control,
			'mcp_adapter' => array(
				'version' => isset( $adapter['version'] ) ? trim( (string) $adapter['version'] ) : '',
				'archive_sha256' => isset( $adapter['archive_sha256'] ) ? strtolower( trim( (string) $adapter['archive_sha256'] ) ) : '',
				'archive_bytes' => isset( $adapter['archive_bytes'] ) ? (int) $adapter['archive_bytes'] : 0,
				'package_url' => isset( $adapter['package_url'] ) ? trim( (string) $adapter['package_url'] ) : '',
			),
			'pair_certification_required' => true,
			'production_auto_apply' => false,
		);
	}

	private static function profile_matches_release_set( array $profile, array $set ) {
		$adapter = isset( $set['mcp_adapter'] ) && is_array( $set['mcp_adapter'] ) ? $set['mcp_adapter'] : array();
		if ( empty( $adapter ) ) return false;
		$checks = array(
			'version' => isset( $profile['version'] ) ? (string) $profile['version'] : '',
			'archive_sha256' => isset( $profile['archive_sha256'] ) ? strtolower( (string) $profile['archive_sha256'] ) : '',
			'archive_bytes' => isset( $profile['archive_bytes'] ) ? (int) $profile['archive_bytes'] : 0,
			'package_url' => isset( $profile['package_url'] ) ? (string) $profile['package_url'] : '',
		);
		return hash_equals( (string) $adapter['version'], $checks['version'] )
			&& hash_equals( (string) $adapter['archive_sha256'], $checks['archive_sha256'] )
			&& (int) $adapter['archive_bytes'] === $checks['archive_bytes']
			&& hash_equals( (string) $adapter['package_url'], $checks['package_url'] )
			&& ! empty( $profile['critical_files'] )
			&& ! empty( $profile['runtime_classes'] );
	}

	private static function runtime_readback( $target_version, array $release_set, $deep ) {
		$target_version = trim( (string) $target_version );
		$current = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::installed_version( 'mcp_adapter' ) : '';
		$out = array(
			'ready' => false,
			'state' => 'target_not_installed',
			'installed_version' => $current,
			'target_version' => $target_version,
			'provider_contract_ok' => false,
			'class_provenance_ready' => false,
			'mixed_runtime' => false,
		);
		if ( '' === $target_version || ! hash_equals( $target_version, $current ) ) return $out;
		$profile = method_exists( 'MAD4B_SCP_Provider_Contracts', 'get_for_version' )
			? MAD4B_SCP_Provider_Contracts::get_for_version( 'mcp_adapter', $target_version )
			: array();
		if ( empty( $profile ) || ! self::profile_matches_release_set( $profile, $release_set ) ) {
			$out['state'] = 'target_profile_mismatch';
			return $out;
		}
		$provider = MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter' );
		$out['provider_contract_ok'] = ! empty( $provider['runtime_contract_ok'] );
		if ( class_exists( 'MAD4B_SCP_MCP_Class_Provenance', false ) ) {
			$provenance = MAD4B_SCP_MCP_Class_Provenance::status( (bool) $deep, (bool) $deep );
			$out['class_provenance_ready'] = ! empty( $provenance['ready'] );
			$out['mixed_runtime'] = ! empty( $provenance['mixed_runtime'] );
			$out['class_provenance_state'] = isset( $provenance['state'] ) ? (string) $provenance['state'] : '';
		}
		$out['ready'] = $out['provider_contract_ok'] && $out['class_provenance_ready'] && ! $out['mixed_runtime'];
		$out['state'] = $out['ready'] ? 'certified_pair_runtime' : 'runtime_revalidation_required';
		return $out;
	}

	private static function policy() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/runtime-release-policy.json' : '';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) return array();
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || self::POLICY_CONTRACT !== ( isset( $data['contract'] ) ? (string) $data['contract'] : '' ) ) return array();
		return $data;
	}

	private static function staging_guard() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
			|| ! MAD4B_SCP_Site_Profile::configured()
			|| ! MAD4B_SCP_Site_Profile::origin_enrolled()
			|| ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			|| 'staging' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) ) {
			return new WP_Error( 'mad4b_runtime_release_set_staging_profile_required', 'Runtime release-set mutation requires the exact enrolled Staging Site Profile.' );
		}
		return true;
	}

	private static function breakglass_active() {
		if ( defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) ) return true;
		return class_exists( 'MAD4B_SCP_Policy' ) && MAD4B_SCP_Policy::can_breakglass();
	}

	private static function begin_transaction( array $plan, $state ) {
		$current = self::read_transaction();
		if ( self::transaction_active( $current ) ) {
			$expected = isset( $plan['release_set_sha256'] ) ? (string) $plan['release_set_sha256'] : '';
			$actual = isset( $current['release_set_sha256'] ) ? (string) $current['release_set_sha256'] : '';
			if ( '' === $expected || '' === $actual || ! hash_equals( $expected, $actual ) ) return new WP_Error( 'mad4b_runtime_release_set_transaction_conflict', 'Another runtime release-set transaction is active.' );
			return $current;
		}
		$transaction = array(
			'contract' => self::TRANSACTION_CONTRACT,
			'transaction_id' => wp_generate_uuid4(),
			'state' => sanitize_key( (string) $state ),
			'started_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'release_set_sha256' => isset( $plan['release_set_sha256'] ) ? (string) $plan['release_set_sha256'] : '',
			'target' => isset( $plan['target'] ) ? $plan['target'] : array(),
			'previous' => isset( $plan['current'] ) ? $plan['current'] : array(),
			'plan_sha256' => isset( $plan['plan_sha256'] ) ? (string) $plan['plan_sha256'] : '',
			'production_mutation' => false,
			'breakglass_included' => false,
		);
		return self::write_transaction( $transaction );
	}

	private static function transition_transaction( array $transaction, $state, array $extra = array() ) {
		$transaction['state'] = sanitize_key( (string) $state );
		$transaction['updated_at'] = gmdate( 'c' );
		foreach ( $extra as $key => $value ) $transaction[ sanitize_key( (string) $key ) ] = $value;
		return self::write_transaction( $transaction );
	}

	private static function fail_transaction( array $transaction, $state, $error_code ) {
		return self::transition_transaction( $transaction, $state, array( 'error_code' => sanitize_key( (string) $error_code ) ) );
	}

	private static function commit_transaction( array $plan, $source ) {
		$transaction = self::read_transaction();
		$receipt = array(
			'contract' => 'mad4b.runtime-release-receipt.v1',
			'transaction_id' => isset( $transaction['transaction_id'] ) ? (string) $transaction['transaction_id'] : '',
			'committed_at' => gmdate( 'c' ),
			'commit_source' => sanitize_key( (string) $source ),
			'release_set_sha256' => isset( $plan['release_set_sha256'] ) ? (string) $plan['release_set_sha256'] : '',
			'target' => isset( $plan['target'] ) ? $plan['target'] : array(),
			'runtime_readback' => isset( $plan['runtime_readback'] ) ? $plan['runtime_readback'] : array(),
			'production_mutation' => false,
			'breakglass_included' => false,
		);
		update_option( self::LAST_RECEIPT_OPTION, $receipt, false );
		delete_option( self::TRANSACTION_OPTION );
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) MAD4B_SCP_Audit::record( 'mad4b/runtime-release-set-apply', $receipt );
		return $receipt;
	}

	private static function read_transaction() {
		$value = get_option( self::TRANSACTION_OPTION, array() );
		if ( ! is_array( $value ) || self::TRANSACTION_CONTRACT !== ( isset( $value['contract'] ) ? (string) $value['contract'] : '' ) ) return array();
		$digest = isset( $value['integrity'] ) ? (string) $value['integrity'] : '';
		unset( $value['integrity'] );
		if ( '' === $digest || ! hash_equals( $digest, self::transaction_integrity( $value ) ) ) return array();
		$value['integrity'] = $digest;
		return $value;
	}

	private static function write_transaction( array $transaction ) {
		unset( $transaction['integrity'] );
		$transaction['integrity'] = self::transaction_integrity( $transaction );
		update_option( self::TRANSACTION_OPTION, $transaction, false );
		return $transaction;
	}

	private static function transaction_integrity( array $transaction ) {
		unset( $transaction['integrity'] );
		$key = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'mad4b-runtime-release-set' );
		return hash_hmac( 'sha256', self::canonical_json( $transaction ), $key );
	}

	private static function transaction_active( array $transaction ) {
		if ( empty( $transaction ) ) return false;
		$state = isset( $transaction['state'] ) ? (string) $transaction['state'] : '';
		return ! in_array( $state, array( 'committed', 'cancelled', 'rolled_back' ), true );
	}

	private static function public_transaction( array $transaction ) {
		if ( empty( $transaction ) ) return array();
		return array(
			'transaction_id' => isset( $transaction['transaction_id'] ) ? (string) $transaction['transaction_id'] : '',
			'state' => isset( $transaction['state'] ) ? (string) $transaction['state'] : '',
			'started_at' => isset( $transaction['started_at'] ) ? (string) $transaction['started_at'] : '',
			'updated_at' => isset( $transaction['updated_at'] ) ? (string) $transaction['updated_at'] : '',
			'release_set_sha256' => isset( $transaction['release_set_sha256'] ) ? (string) $transaction['release_set_sha256'] : '',
			'production_mutation' => false,
			'breakglass_included' => false,
		);
	}

	public static function reconcile_admin_readback() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return;
		if ( class_exists( 'MAD4B_SCP_MCP_Request_Scope', false ) && MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath() ) return;
		$transaction = self::read_transaction();
		if ( ! self::transaction_active( $transaction ) || 'awaiting_runtime_readback' !== ( isset( $transaction['state'] ) ? (string) $transaction['state'] : '' ) ) return;
		$target = isset( $transaction['target'] ) && is_array( $transaction['target'] ) ? $transaction['target'] : array();
		$adapter = isset( $target['mcp_adapter'] ) && is_array( $target['mcp_adapter'] ) ? $target['mcp_adapter'] : array();
		$readback = self::runtime_readback( isset( $adapter['version'] ) ? $adapter['version'] : '', $target, true );
		if ( empty( $readback['ready'] ) ) return;
		$plan = array(
			'release_set_sha256' => isset( $transaction['release_set_sha256'] ) ? $transaction['release_set_sha256'] : '',
			'target' => $target,
			'runtime_readback' => $readback,
		);
		self::commit_transaction( $plan, 'admin_readback_reconciliation' );
	}

	public static function plugin_action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( 'update_plugins' ) ) return $links;
		$transaction = self::read_transaction();
		$label = self::transaction_active( $transaction )
			? __( 'Continue compatible runtime update', 'mad4b-site-control-plane' )
			: __( 'Update compatible runtime set', 'mad4b-site-control-plane' );
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mad4b_runtime_release_set_apply' ),
			'mad4b_runtime_release_set_apply'
		);
		$links['mad4b_runtime_release_set'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		return $links;
	}

	public static function handle_admin_apply() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) wp_die( esc_html__( 'You are not allowed to update plugins.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'mad4b_runtime_release_set_apply' );
		$reason = 'WordPress administrator requested the exact compatible MAD4B runtime release set.';
		$plan = self::plan( array( 'reason' => $reason ) );
		if ( is_wp_error( $plan ) ) self::redirect_admin_result( 'plan_error', $plan->get_error_code() );
		if ( empty( $plan['eligible'] ) ) self::redirect_admin_result( 'blocked', implode( ',', $plan['blockers'] ) );
		$result = self::apply_internal(
			array(
				'reason' => $reason,
				'expected_plan_sha256' => $plan['plan_sha256'],
				'confirmation' => self::CONFIRMATION,
			),
			true,
			false
		);
		if ( is_wp_error( $result ) ) self::redirect_admin_result( 'apply_error', $result->get_error_code() );
		$state = isset( $result['state'] ) ? sanitize_key( (string) $result['state'] ) : 'success';
		self::redirect_admin_result( $state, '' );
	}

	private static function redirect_admin_result( $state, $detail ) {
		$url = add_query_arg(
			array(
				'mad4b_runtime_release_set' => sanitize_key( (string) $state ),
				'mad4b_runtime_release_detail' => sanitize_key( (string) $detail ),
			),
			admin_url( 'plugins.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) return;
		$state = isset( $_GET['mad4b_runtime_release_set'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_runtime_release_set'] ) ) : '';
		if ( '' === $state ) return;
		$detail = isset( $_GET['mad4b_runtime_release_detail'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_runtime_release_detail'] ) ) : '';
		$class = in_array( $state, array( 'committed', 'current' ), true ) ? 'notice notice-success' : ( in_array( $state, array( 'apply_error', 'plan_error', 'blocked' ), true ) ? 'notice notice-error' : 'notice notice-info' );
		$message = 'MAD4B runtime release set: ' . str_replace( '_', ' ', $state );
		if ( '' !== $detail ) $message .= ' (' . $detail . ')';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function result( array $plan, $state, $mutated, $reboot ) {
		return array(
			'contract' => self::APPLY_CONTRACT,
			'state' => sanitize_key( (string) $state ),
			'operation' => isset( $plan['operation'] ) ? (string) $plan['operation'] : '',
			'release_set_sha256' => isset( $plan['release_set_sha256'] ) ? (string) $plan['release_set_sha256'] : '',
			'target' => isset( $plan['target'] ) ? $plan['target'] : array(),
			'transaction' => self::public_transaction( self::read_transaction() ),
			'mutation_performed' => (bool) $mutated,
			'runtime_reboot_required' => (bool) $reboot,
			'production_mutation' => false,
			'breakglass_included' => false,
			'authorizing' => false,
		);
	}

	private static function bounded_component_result( $result ) {
		if ( ! is_array( $result ) ) return array();
		$out = array();
		foreach ( array( 'contract', 'operation', 'before_version', 'after_version', 'plan_sha256', 'package_sha256', 'readback_verified', 'runtime_reboot_required' ) as $field ) {
			if ( array_key_exists( $field, $result ) ) $out[ $field ] = $result[ $field ];
		}
		return $out;
	}

	private static function release_set_digest( array $set ) {
		$control = isset( $set['control_plane'] ) && is_array( $set['control_plane'] ) ? $set['control_plane'] : array();
		$adapter = isset( $set['mcp_adapter'] ) && is_array( $set['mcp_adapter'] ) ? $set['mcp_adapter'] : array();
		foreach ( array( 'source_commit_sha', 'archive_sha256', 'build_fingerprint', 'package_manifest_digest' ) as $field ) if ( empty( $control[ $field ] ) ) return '';
		foreach ( array( 'version', 'archive_sha256', 'archive_bytes', 'package_url' ) as $field ) if ( empty( $adapter[ $field ] ) ) return '';
		return self::digest( array(
			'contract' => self::CONTRACT,
			'control_plane' => $control,
			'mcp_adapter' => $adapter,
			'pair_certification_required' => true,
			'production_auto_apply' => false,
		) );
	}

	private static function digest( $value ) {
		return hash( 'sha256', self::canonical_json( $value ) );
	}

	private static function canonical_json( $value ) {
		$value = self::canonicalize( $value );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
			foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
			return $value;
		}
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}

	private static function plan_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			'required' => array( 'reason' ),
			'additionalProperties' => false,
		);
	}

	private static function apply_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
				'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'confirmation' => array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) ),
			),
			'required' => array( 'reason', 'expected_plan_sha256', 'confirmation' ),
			'additionalProperties' => false,
		);
	}
}
