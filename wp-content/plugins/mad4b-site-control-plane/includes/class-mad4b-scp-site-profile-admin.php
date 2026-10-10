<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Administrator onboarding for tenant-neutral site enrollment. */
final class MAD4B_SCP_Site_Profile_Admin {
	const PAGE_SLUG = 'mad4b-control-plane-site-profile';
	const ACTION_SAVE = 'mad4b_site_profile_save';
	const ACTION_AUTOPILOT = 'mad4b_site_profile_enable_staging_autopilot';
	const ACTION_DISABLE = 'mad4b_site_profile_disable_authority';
	const ACTION_LEGACY_MIGRATE = 'mad4b_site_profile_explicit_legacy_migrate';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_page' ), 25 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_AUTOPILOT, array( __CLASS__, 'handle_autopilot_enable' ) );
		add_action( 'wp_ajax_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DISABLE, array( __CLASS__, 'handle_disable' ) );
		add_action( 'admin_post_' . self::ACTION_LEGACY_MIGRATE, array( __CLASS__, 'handle_legacy_migrate' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'mad4b-control-plane',
			__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ),
			__( 'Site Profile', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_legacy_migrate() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator permission required.', '', array( 'response' => 403 ) );
		check_admin_referer( self::ACTION_LEGACY_MIGRATE );
		$confirmation = isset( $_POST['legacy_migration_confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['legacy_migration_confirmation'] ) ) : '';
		if ( 'MIGRATE IDENTITY WITHOUT AUTHORITY' !== $confirmation ) self::redirect( 'migration_confirmation_required' );
		$uuid = isset( $_POST['expected_site_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_site_uuid'] ) ) : '';
		$revision = isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : 0;
		$result = MAD4B_SCP_Site_Profile::apply_legacy_migration( $uuid, $revision );
		self::redirect( is_wp_error( $result ) ? sanitize_key( $result->get_error_code() ) : 'legacy_migration_requires_reenrollment' );
	}

	/**
	 * Single-action Staging onboarding. Reuse the existing audited Site
	 * Profile save and guarded wp-config writer; never create a separate
	 * authority path or expose this action over an MCP read/remote URL.
	 */
	public static function handle_autopilot_enable() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( 'POST required.', '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Administrator permission required.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_AUTOPILOT );
		$status = MAD4B_SCP_Site_Profile::status();
		$profile = MAD4B_SCP_Site_Profile::profile();
		$decision = MAD4B_SCP_Staging_Autopilot::decision( $status );
		if ( empty( $decision['admin_autopilot_action_allowed'] ) ||
			'staging' !== (string) ( $status['configured_environment'] ?? '' ) ||
			! is_array( $profile ) || empty( $status['profile_digest'] ) ) {
			self::redirect( 'autopilot_precondition_blocked' );
		}
		$features = isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array();
		$related = isset( $profile['related_origins'] ) && is_array( $profile['related_origins'] ) ? $profile['related_origins'] : array();
		$input = array(
			'environment' => 'staging',
			'environment_sync_mode' => MAD4B_SCP_Site_Profile::ENV_SYNC_HOST_MANAGED,
			'display_name' => (string) ( $profile['display_name'] ?? '' ),
			'chatgpt_app_id' => (string) ( $profile['chatgpt_app_id'] ?? '' ),
			'oauth_user_ids' => implode( ',', (array) ( $profile['oauth_user_ids'] ?? array() ) ),
			'development_origin' => (string) ( $related['development'] ?? '' ),
			'staging_origin' => (string) ( $status['canonical_origin'] ?? '' ),
			'production_origin' => (string) ( $related['production'] ?? '' ),
			'oauth_enabled' => ! empty( $features['oauth'] ),
			'skills_enabled' => ! empty( $features['skills'] ),
			'write_enabled' => ! empty( $features['write'] ),
			'production_write_confirmed' => false,
			'provider_isolation_enabled' => ! empty( $features['provider_isolation'] ),
			'managed_runtime_enabled' => ! empty( $features['managed_runtime'] ),
			'acceptance_enabled' => ! empty( $features['acceptance'] ),
			'expected_revision' => absint( $status['revision'] ),
			'expected_profile_digest' => (string) $status['profile_digest'],
		);
		$result = MAD4B_SCP_Site_Profile::save_current_site( $input );
		if ( is_wp_error( $result ) ) self::redirect( 'autopilot_profile_save_blocked' );
		MAD4B_SCP_Site_Profile::reset_cache();
		$after = MAD4B_SCP_Site_Profile::status();
		$stored = MAD4B_SCP_Site_Profile::profile();
		if ( ! self::persisted_readback_matches( $input, $result, $after, $stored ) ||
			'host_managed' !== (string) ( $after['environment_sync_mode'] ?? '' ) ||
			(string) $status['site_uuid'] !== (string) ( $after['site_uuid'] ?? '' ) ) {
			self::redirect( 'autopilot_profile_readback_blocked' );
		}
		$sync = self::sync_wp_config_after_verified_save( $after );
		$state = (string) ( $sync['state'] ?? 'unknown' );
		self::redirect( 'config_written_verified_new_request_required' === $state
			? 'autopilot_config_written'
			: ( 'already_aligned' === $state ? 'autopilot_already_aligned' : 'autopilot_config_blocked' ) );
	}

	public static function handle_save() {
		self::require_save_request();
		$input = array(
			'environment' => isset( $_POST['environment'] ) ? wp_unslash( $_POST['environment'] ) : '',
			'environment_sync_mode' => isset( $_POST['environment_sync_mode'] ) ? wp_unslash( $_POST['environment_sync_mode'] ) : '',
			'display_name' => isset( $_POST['display_name'] ) ? wp_unslash( $_POST['display_name'] ) : '',
			'chatgpt_app_id' => isset( $_POST['chatgpt_app_id'] ) ? wp_unslash( $_POST['chatgpt_app_id'] ) : '',
			'oauth_user_ids' => isset( $_POST['oauth_user_ids'] ) ? wp_unslash( $_POST['oauth_user_ids'] ) : '',
			'development_origin' => isset( $_POST['development_origin'] ) ? wp_unslash( $_POST['development_origin'] ) : '',
			'staging_origin' => isset( $_POST['staging_origin'] ) ? wp_unslash( $_POST['staging_origin'] ) : '',
			'production_origin' => isset( $_POST['production_origin'] ) ? wp_unslash( $_POST['production_origin'] ) : '',
			'oauth_enabled' => ! empty( $_POST['oauth_enabled'] ),
			'skills_enabled' => ! empty( $_POST['skills_enabled'] ),
			'write_enabled' => ! empty( $_POST['write_enabled'] ),
			'production_write_confirmed' => ! empty( $_POST['production_write_confirmed'] ),
			'production_write_confirmation' => isset( $_POST['production_write_confirmation'] ) ? wp_unslash( $_POST['production_write_confirmation'] ) : '',
			'nonproduction_override_confirmed' => ! empty( $_POST['nonproduction_override_confirmed'] ),
			'nonproduction_override_confirmation' => isset( $_POST['nonproduction_override_confirmation'] ) ? wp_unslash( $_POST['nonproduction_override_confirmation'] ) : '',
			'expected_revision' => isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : 0,
			'expected_profile_digest' => isset( $_POST['expected_profile_digest'] ) && is_string( $_POST['expected_profile_digest'] ) ? wp_unslash( $_POST['expected_profile_digest'] ) : null,
			'provider_isolation_enabled' => ! empty( $_POST['provider_isolation_enabled'] ),
			'managed_runtime_enabled' => ! empty( $_POST['managed_runtime_enabled'] ),
			'acceptance_enabled' => ! empty( $_POST['acceptance_enabled'] ),
		);
		$result = MAD4B_SCP_Site_Profile::save_current_site( $input );
		if ( self::is_ajax_request() ) {
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array(
					'code' => sanitize_key( $result->get_error_code() ),
					'message' => $result->get_error_message(),
					'data' => $result->get_error_data(),
				), 422 );
			}
			MAD4B_SCP_Site_Profile::reset_cache();
			$status = MAD4B_SCP_Site_Profile::status();
			$profile = MAD4B_SCP_Site_Profile::profile();
			$verified = self::persisted_readback_matches( $input, $result, $status, $profile );
			if ( ! $verified ) {
				wp_send_json_error( array(
					'code' => 'mad4b_site_profile_readback_mismatch',
					'message' => __( 'Site Profile write completed but persisted readback did not match the committed revision.', 'mad4b-site-control-plane' ),
				), 500 );
			}
			$environment_sync = self::sync_wp_config_after_verified_save( $status );
			wp_send_json_success( array(
				'environment_sync' => $environment_sync,
				'message' => __( 'Site Profile saved. WordPress bootstrap synchronization status is reported separately.', 'mad4b-site-control-plane' ),
				'persistence_verified' => true,
				'readback' => array(
					'revision' => (int) $status['revision'],
					'environment' => (string) $status['configured_environment'],
					'effective_environment' => (string) $status['environment'],
					'environment_sync_mode' => (string) $status['environment_sync_mode'],
					'environment_sync_state' => (string) $status['environment_sync_state'],
					'site_uuid' => (string) $status['site_uuid'],
					'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
					'display_name' => MAD4B_SCP_Site_Profile::display_name(),
					'chatgpt_app_id' => MAD4B_SCP_Site_Profile::chatgpt_app_id(),
					'features' => isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array(),
				),
			) );
		}
		if ( is_wp_error( $result ) ) self::redirect( $result->get_error_code() );
		// Native admin-post uses the same committed-intent proof as AJAX. A 302
		// success is never published from the write result alone.
		MAD4B_SCP_Site_Profile::reset_cache();
		$status = MAD4B_SCP_Site_Profile::status();
		$profile = MAD4B_SCP_Site_Profile::profile();
		if ( ! self::persisted_readback_matches( $input, $result, $status, $profile ) ) {
			self::redirect( 'mad4b_site_profile_readback_mismatch' );
		}
		$environment_sync = self::sync_wp_config_after_verified_save( $status );
		self::redirect( 'config_written_verified_new_request_required' === ( $environment_sync['state'] ?? '' )
			? 'saved_environment_sync_written' : ( 'already_aligned' === ( $environment_sync['state'] ?? '' ) || 'not_requested' === ( $environment_sync['state'] ?? '' )
			? 'saved' : 'saved_environment_sync_blocked' ) );
	}

	/** Admin-initiated config change only, after a successful audited profile commit. */
	private static function sync_wp_config_after_verified_save( array $status ) {
		require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wp-config-environment-sync.php';
		return MAD4B_SCP_WP_Config_Environment_Sync::apply_from_verified_admin_save( $status );
	}

	/** Revision may reset to 1 on an identity rebind; compare identity and intent. */
	public static function persisted_readback_matches( array $input, array $committed, array $status, array $profile ) {
		if ( empty( $profile ) || empty( $committed['profile_digest'] ) || empty( $status['profile_digest'] ) ) return false;
		foreach ( array( 'revision', 'profile_digest', 'site_uuid', 'configured_environment', 'canonical_origin' ) as $field ) {
			if ( ! isset( $committed[ $field ], $status[ $field ] ) || (string) $committed[ $field ] !== (string) $status[ $field ] ) return false;
		}
		$requested = sanitize_key( (string) ( $input['environment'] ?? '' ) );
		if ( '' !== $requested && ! hash_equals( $requested, (string) $status['configured_environment'] ) ) return false;
		if ( ! hash_equals( (string) MAD4B_SCP_Site_Profile::profile_digest(), (string) $status['profile_digest'] ) ) return false;
		return self::requested_intent_matches_profile( $input, $profile );
	}

	private static function requested_intent_matches_profile( array $input, array $profile ) {
		$environment = isset( $profile['environment'] ) ? sanitize_key( (string) $profile['environment'] ) : '';
		if ( array_key_exists( 'environment_sync_mode', $input )
			&& ( ! is_string( $input['environment_sync_mode'] )
				|| sanitize_key( $input['environment_sync_mode'] ) !== (string) ( $profile['environment_sync_mode'] ?? MAD4B_SCP_Site_Profile::ENV_SYNC_PROFILE_ONLY ) ) ) return false;
		$features = isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array();
		foreach ( array(
			'oauth' => 'oauth_enabled',
			'skills' => 'skills_enabled',
			'write' => 'write_enabled',
			'provider_isolation' => 'provider_isolation_enabled',
			'managed_runtime' => 'managed_runtime_enabled',
			'acceptance' => 'acceptance_enabled',
		) as $feature => $input_key ) {
			if ( (bool) ! empty( $input[ $input_key ] ) !== (bool) ! empty( $features[ $feature ] ) ) return false;
		}
		$expected_production_confirmation = 'production' === $environment && ! empty( $input['write_enabled'] ) && ! empty( $input['production_write_confirmed'] );
		if ( $expected_production_confirmation !== (bool) ! empty( $features['production_write_confirmed'] ) ) return false;
		if ( array_key_exists( 'display_name', $input ) ) {
			$display_name = substr( sanitize_text_field( (string) $input['display_name'] ), 0, 191 );
			if ( $display_name !== (string) ( $profile['display_name'] ?? '' ) ) return false;
		}
		if ( array_key_exists( 'chatgpt_app_id', $input ) ) {
			$app_id = trim( sanitize_text_field( (string) $input['chatgpt_app_id'] ) );
			if ( $app_id !== (string) ( $profile['chatgpt_app_id'] ?? '' ) ) return false;
		}
		$requested_users = self::normalize_user_ids( $input['oauth_user_ids'] ?? array() );
		if ( empty( $requested_users ) ) $requested_users = array( get_current_user_id() );
		$profile_users = self::normalize_user_ids( $profile['oauth_user_ids'] ?? array() );
		sort( $requested_users, SORT_NUMERIC );
		sort( $profile_users, SORT_NUMERIC );
		if ( $requested_users !== $profile_users ) return false;

		$related = isset( $profile['related_origins'] ) && is_array( $profile['related_origins'] ) ? $profile['related_origins'] : array();
		foreach ( array( 'development', 'staging', 'production' ) as $related_environment ) {
			$actual = isset( $related[ $related_environment ] ) ? self::normalize_origin_for_intent( $related[ $related_environment ] ) : '';
			$expected = $related_environment === $environment
				? self::normalize_origin_for_intent( $profile['canonical_origin'] ?? '' )
				: self::normalize_origin_for_intent( $input[ $related_environment . '_origin' ] ?? '' );
			if ( $actual !== $expected ) return false;
		}
		if ( ! empty( $input['nonproduction_override_confirmed'] ) && 'production' !== $environment
			&& empty( $profile['implicit_production_override_confirmed'] ) ) return false;
		return true;
	}

	private static function normalize_origin_for_intent( $url ) {
		if ( ! is_string( $url ) ) return '';
		$url = trim( $url );
		if ( '' === $url ) return '';
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) return '';
		$origin = $scheme . '://' . strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( isset( $parts['port'] ) ) $origin .= ':' . absint( $parts['port'] );
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '';
		$path = '/' === $path ? '' : rtrim( $path, '/' );
		return $origin . $path;
	}

	private static function normalize_user_ids( $value ) {
		if ( is_string( $value ) ) $value = preg_split( '/[\s,]+/', $value );
		if ( ! is_array( $value ) ) return array();
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_int( $item ) && ! is_string( $item ) ) continue;
			$item = trim( (string) $item );
			if ( 1 === preg_match( '/^[1-9][0-9]*$/D', $item ) ) $out[] = (int) $item;
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	public static function handle_disable() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		check_admin_referer( self::ACTION_DISABLE );
		$expected_revision = isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : null;
		$result = MAD4B_SCP_Site_Profile::disable_authority( $expected_revision );
		self::redirect( is_wp_error( $result ) ? $result->get_error_code() : 'authority_disabled' );
	}

	private static function is_ajax_request() {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	private static function require_save_request() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( self::is_ajax_request() ) wp_send_json_error( array( 'code' => 'mad4b_site_profile_post_required', 'message' => __( 'Use the Site Profile save form.', 'mad4b-site-control-plane' ) ), 405 );
			wp_die( esc_html__( 'Use the Site Profile save form.', 'mad4b-site-control-plane' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( self::is_ajax_request() ) wp_send_json_error( array( 'code' => 'mad4b_site_profile_admin_required', 'message' => __( 'Administrator capability is required.', 'mad4b-site-control-plane' ) ), 403 );
			wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ), '', array( 'response' => 403 ) );
		}
		if ( self::is_ajax_request() ) {
			if ( false === check_ajax_referer( self::ACTION_SAVE, '_wpnonce', false ) ) wp_send_json_error( array( 'code' => 'mad4b_site_profile_nonce_invalid', 'message' => __( 'The Site Profile settings request expired. Refresh the page and try again.', 'mad4b-site-control-plane' ) ), 403 );
			return;
		}
		check_admin_referer( self::ACTION_SAVE );
	}

	private static function redirect( $state ) {
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'mad4b_site_profile' => sanitize_key( (string) $state ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$status = MAD4B_SCP_Site_Profile::status();
		$legacy_migration = MAD4B_SCP_Site_Profile::legacy_migration_plan();
		$profile = MAD4B_SCP_Site_Profile::profile();
		$resolution = MAD4B_SCP_Site_Profile::environment_resolution();
		$suggested_environment = isset( $resolution['suggested_environment'] ) ? sanitize_key( (string) $resolution['suggested_environment'] ) : 'production';
		$selected_environment = ! empty( $status['origin_match'] ) && ! empty( $status['environment_match'] ) && ! empty( $status['configured_environment'] )
			? sanitize_key( (string) $status['configured_environment'] )
			: $suggested_environment;
		$features = isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array();
		$related = isset( $profile['related_origins'] ) && is_array( $profile['related_origins'] ) ? $profile['related_origins'] : array();
		$users = MAD4B_SCP_Site_Profile::oauth_user_ids();
		$wordpress_default_production = 'production' === (string) $resolution['wordpress_environment'] && empty( $resolution['wordpress_environment_explicit'] );
		$override_already_confirmed = ! empty( $profile['implicit_production_override_confirmed'] );
		$sync_mode = (string) ( $status['environment_sync_mode'] ?? MAD4B_SCP_Site_Profile::ENV_SYNC_PROFILE_ONLY );
		// First-time Staging enrollment should make Save sufficient for local
		// wp-config correction. Respect an existing profile_only opt-out.
		if ( empty( $status['configured'] ) && 'staging' === $selected_environment ) {
			$sync_mode = MAD4B_SCP_Site_Profile::ENV_SYNC_HOST_MANAGED;
		}
		$sync_state = (string) ( $status['environment_sync_state'] ?? 'profile_only' );
		$host_directive = 'awaiting_host_bootstrap' === $sync_state
			&& ! empty( $status['authority_ready'] )
			&& in_array( $selected_environment, array( 'local', 'development', 'staging' ), true )
			? "define( 'WP_ENVIRONMENT_TYPE', '" . $selected_environment . "' );" : '';

		$autopilot = MAD4B_SCP_Staging_Autopilot::status( array() );
		if ( 'admin_reconcile_available' === $autopilot['state'] ) {
			if ( ! class_exists( 'MAD4B_SCP_WP_Config_Environment_Sync' ) )
				require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wp-config-environment-sync.php';
			$autopilot['local_config_preflight'] = MAD4B_SCP_WP_Config_Environment_Sync::preflight_readonly( $status );
		}
		$state = isset( $_GET['mad4b_site_profile'] ) ? sanitize_key( MAD4B_SCP_Admin_Experience::query_string( 'mad4b_site_profile' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap" id="mad4b-site-profile-workspace">
			<h1><?php echo esc_html__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Enroll this exact WordPress origin before remote OAuth or governed write authority can become active. Unknown sites remain fail-closed after installation.', 'mad4b-site-control-plane' ); ?></p>
			<?php MAD4B_SCP_WordPress_Native_Opt_In::render_admin( $status ); ?>
			<?php if ( '' !== $state ) : ?><div class="notice <?php echo false !== strpos( $state, 'blocked' ) ? 'notice-warning' : 'notice-info'; ?>"><p><?php echo esc_html( $state ); ?></p></div><?php endif; ?>
			<div class="notice notice-info inline" style="max-width:950px;padding:12px 16px">
				<p><strong><?php esc_html_e( 'Staging Autopilot / Assistant Handoff', 'mad4b-site-control-plane' ); ?></strong></p>
				<p><?php echo esc_html( sprintf( 'State: %s | Next action: %s | Actor: %s', $autopilot['state'], $autopilot['next_action_id'], $autopilot['responsible_actor'] ) ); ?></p>
				<?php if ( ! empty( $autopilot['local_config_preflight'] ) ) : ?>
					<p><?php echo esc_html( 'Local wp-config preflight: ' . (string) $autopilot['local_config_preflight']['state'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $autopilot['admin_autopilot_action_allowed'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_AUTOPILOT ); ?>">
						<?php wp_nonce_field( self::ACTION_AUTOPILOT ); ?>
						<?php submit_button( __( 'Enable / Retry Staging Autopilot', 'mad4b-site-control-plane' ), 'primary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'Uses your administrator session and existing explicit Staging attestation. Preserves the enrolled identity and settings, saves host_managed, then attempts only a guarded local wp-config edit. Check the WordPress environment again on a fresh request.', 'mad4b-site-control-plane' ); ?></p>
				<?php endif; ?>
				<?php if ( empty( $status['deployment_binding_configured'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Host deployment binding missing: separately provision a unique host-private binding before clone-safe MCP selected-HEAD operations. This cannot be created from a Site Profile read.', 'mad4b-site-control-plane' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Assistants: mad4b/site-autopilot-status (read-only), mad4b/staging-write-authority-convergence-handshake (review-only), mad4b/full-staging-authority-handshake (review-only). No authority or Production changes are automatic.', 'mad4b-site-control-plane' ); ?></p>
				<?php if ( ! empty( $autopilot['automation_plan'] ) && is_array( $autopilot['automation_plan'] ) ) :
					$plan = $autopilot['automation_plan'];
					$lanes = isset( $plan['assistant_workflow'] ) && is_array( $plan['assistant_workflow'] ) ? $plan['assistant_workflow'] : array();
				?>
				<h3><?php esc_html_e( 'Ordered Staging recovery — independent gates', 'mad4b-site-control-plane' ); ?></h3>
				<p><?php echo esc_html( sprintf( 'Next: %s | Owner: %s | Profile revision: %d',
					(string) ( $plan['next_action_id'] ?? 'none' ), (string) ( $plan['responsible_actor'] ?? 'none' ),
					(int) ( $plan['profile_identity']['revision'] ?? 0 ) ) ); ?></p>
				<table class="widefat striped" style="max-width:920px">
					<thead><tr><th><?php esc_html_e( 'Stage', 'mad4b-site-control-plane' ); ?></th><th><?php esc_html_e( 'Current evidence', 'mad4b-site-control-plane' ); ?></th><th><?php esc_html_e( 'Next safe action', 'mad4b-site-control-plane' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $lanes as $lane ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) ( $lane['step_id'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $lane['state'] ?? 'not_evaluated' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $lane['next_action_id'] ?? 'none' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'WordPress environment alignment and host-private deployment binding are separate checks. Host secrets, sandbox packages and Write grants are never created by opening this screen. Saving the profile changes its revision and requires fresh exact Write approval/readback.', 'mad4b-site-control-plane' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( 'REVIEW_REQUIRED' === ( $legacy_migration['status'] ?? '' ) ) : ?>
			<div class="notice notice-warning" style="max-width:950px;padding:1em">
				<p><strong><?php esc_html_e( 'Legacy Site Profile identity detected — not imported', 'mad4b-site-control-plane' ); ?></strong></p>
				<p><?php esc_html_e( 'Status reads never import legacy identity or grant access. Administrators may migrate this exact identity, then reenroll features independently.', 'mad4b-site-control-plane' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_LEGACY_MIGRATE ); ?>">
					<input type="hidden" name="expected_site_uuid" value="<?php echo esc_attr( (string) $legacy_migration['site_uuid'] ); ?>">
					<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $legacy_migration['revision'] ); ?>">
					<?php wp_nonce_field( self::ACTION_LEGACY_MIGRATE ); ?>
					<label><strong><?php esc_html_e( 'Type MIGRATE IDENTITY WITHOUT AUTHORITY to confirm', 'mad4b-site-control-plane' ); ?></strong><input type="text" name="legacy_migration_confirmation" autocomplete="off" class="regular-text" required></label>
					<?php submit_button( __( 'Migrate legacy identity (no grants)', 'mad4b-site-control-plane' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:1000px;margin:1em 0">
				<tbody>
				<tr><th><?php esc_html_e( 'Environment sync mode', 'mad4b-site-control-plane' ); ?></th><td><strong><?php echo esc_html( $sync_mode ); ?></strong> · <code><?php echo esc_html( $sync_state ); ?></code><p class="description"><?php esc_html_e( 'Profile Only changes MAD4B policy. For an administrator-confirmed Staging profile, Host-Managed Sync attempts a guarded automatic wp-config.php correction on Save. The new environment is effective from the next WordPress request. Explicit host configuration and unwritable/ambiguous files are never overridden.', 'mad4b-site-control-plane' ); ?></p>
				<?php if ( 'blocked_missing_deployment_binding' === $sync_state ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Host deployment binding is missing. If WordPress already reports explicit Staging, the environment is aligned; this warning concerns clone-safe Host identity and selected-HEAD operations only. Provision a unique host-private MAD4B_SCP_DEPLOYMENT_BINDING, then save the exact Site Profile to bind its digest. Never reuse a Production secret.', 'mad4b-site-control-plane' ); ?></p></div><?php endif; ?>
				<?php if ( 'blocked_explicit_host_conflict' === $sync_state ) : ?><div class="notice notice-error inline"><p><?php esc_html_e( 'Host declares another environment explicitly. Site Profile cannot override it; reconcile at the Host after checking its identity.', 'mad4b-site-control-plane' ); ?></p></div><?php endif; ?>
				<?php if ( '' !== $host_directive ) : ?>
				<p><strong><?php esc_html_e( 'Governed MCP Host synchronization:', 'mad4b-site-control-plane' ); ?></strong></p>
				<p><?php esc_html_e( 'From the bound Host Runner profile, call mad4b/host-operation-plan with operation_id=wordpress_environment_sync and a reason. After reviewing the exact SHA-256 plan and obtaining authorization, call mad4b/host-operation-apply. Use mad4b/host-operation-status and mad4b/host-operation-receipt, then call mad4b/host-environment-sync-verification with the exact job ID from a new WordPress request. A queued job or an unsigned Host receipt is not proof of a completed environment change.', 'mad4b-site-control-plane' ); ?></p>
				<p class="description"><?php esc_html_e( 'The Host Runner requires a separately enrolled private (0700) config-backup root and a Host-private Ed25519 receipt-signing key (0600), both outside WordPress. The matching public key must be pinned by the Host in WordPress before a signed MCP readback can succeed. No commands, paths or PHP snippets may be supplied through MCP.', 'mad4b-site-control-plane' ); ?></p>
				<details><summary><?php esc_html_e( 'Host bootstrap directive (reference only)', 'mad4b-site-control-plane' ); ?></summary><pre><code><?php echo esc_html( $host_directive ); ?></code></pre></details>
				<?php endif; ?></td></tr>
				<tr><th><?php esc_html_e( 'WordPress environment', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $resolution['wordpress_environment'] ); ?></code> <small>(<?php echo ! empty( $resolution['wordpress_environment_explicit'] ) ? esc_html__( 'explicit', 'mad4b-site-control-plane' ) : esc_html__( 'default', 'mad4b-site-control-plane' ); ?>)</small></td></tr>
				<tr><th><?php esc_html_e( 'MAD4B effective environment', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['environment'] ); ?></code> <small>(<?php echo esc_html( (string) $resolution['effective_source'] ); ?>)</small></td></tr>
				<tr><th><?php esc_html_e( 'Suggested enrollment environment', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( $suggested_environment ); ?></code><br /><span class="description"><?php esc_html_e( 'Hostname classification is advisory only. It never grants OAuth, Write, Skills, or Breakglass authority.', 'mad4b-site-control-plane' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Observed origin', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['current_origin'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Enrolled origin', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['canonical_origin'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Site UUID', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['site_uuid'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Revision', 'mad4b-site-control-plane' ); ?></th><td><?php echo esc_html( (string) $status['revision'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Origin binding', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['origin_match'] ) && ! empty( $status['environment_match'] ) && ! empty( $status['deployment_binding_match'] ) ? 'PASS' : 'BLOCKED'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Deployment binding', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['deployment_binding_configured'] ) ? esc_html__( 'Configured', 'mad4b-site-control-plane' ) : esc_html__( 'Not configured', 'mad4b-site-control-plane' ); ?> · <?php echo ! empty( $status['same_origin_clone_protection'] ) ? esc_html__( 'same-origin clone protection active', 'mad4b-site-control-plane' ) : esc_html__( 'database/origin binding only', 'mad4b-site-control-plane' ); ?></td></tr>
				</tbody>
			</table>

			<form id="mad4b-site-profile-settings" class="mad4b-settings-ajax-form" data-mad4b-refresh-selector="#mad4b-site-profile-workspace" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) ( isset( $status['revision'] ) ? absint( $status['revision'] ) : 0 ) ); ?>" />
				<input type="hidden" name="expected_profile_digest" value="<?php echo esc_attr( (string) ( $status['profile_digest'] ?? '' ) ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="mad4b-environment"><?php esc_html_e( 'MAD4B environment', 'mad4b-site-control-plane' ); ?></label></th><td>
						<select id="mad4b-environment" name="environment">
						<?php foreach ( array( 'local', 'development', 'staging', 'production' ) as $environment_option ) : ?>
							<option value="<?php echo esc_attr( $environment_option ); ?>" <?php echo $selected_environment === $environment_option ? 'selected' : ''; ?>><?php echo esc_html( ucfirst( $environment_option ) ); ?></option>
						<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'With Staging and Host-Managed Sync selected, Save automatically inserts the Staging environment setting into a writable standard wp-config.php. It takes effect on the next WordPress request. Explicit host settings always win; Profile Only disables file edits.', 'mad4b-site-control-plane' ); ?></p>
					</td></tr>
					<tr><th><label for="mad4b-environment-sync-mode"><?php esc_html_e( 'WordPress environment synchronization', 'mad4b-site-control-plane' ); ?></label></th><td>
						<select id="mad4b-environment-sync-mode" name="environment_sync_mode">
							<option value="profile_only" <?php selected( $sync_mode, 'profile_only' ); ?>><?php esc_html_e( 'Profile Only (MAD4B governance)', 'mad4b-site-control-plane' ); ?></option>
							<option value="host_managed" <?php selected( $sync_mode, 'host_managed' ); ?>><?php esc_html_e( 'Host-Managed Sync (Staging wp-config updated automatically on Save)', 'mad4b-site-control-plane' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Host-Managed Sync automatically attempts the Staging wp-config.php update on an authorized Site Profile Save. On success, WordPress will report Staging on the next request. Other environments, explicit host settings and unsupported files require independent Host reconciliation; the deployment binding remains a separate MCP safety requirement.', 'mad4b-site-control-plane' ); ?></p>
					</td></tr>
					<tr><th><label for="mad4b-display-name"><?php esc_html_e( 'Display name', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text" id="mad4b-display-name" name="display_name" value="<?php echo esc_attr( isset( $profile['display_name'] ) ? $profile['display_name'] : get_bloginfo( 'name' ) ); ?>" /></td></tr>
					<tr><th><label for="mad4b-app-id"><?php esc_html_e( 'ChatGPT App ID', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text" id="mad4b-app-id" name="chatgpt_app_id" value="<?php echo esc_attr( MAD4B_SCP_Site_Profile::chatgpt_app_id() ); ?>" placeholder="plugin_asdk_app_..." /></td></tr>
					<tr><th><label for="mad4b-users"><?php esc_html_e( 'OAuth WordPress user IDs', 'mad4b-site-control-plane' ); ?></label></th><td><input class="regular-text" id="mad4b-users" name="oauth_user_ids" value="<?php echo esc_attr( implode( ',', $users ) ); ?>" /><p class="description"><?php esc_html_e( 'Comma-separated existing users. Each token subject remains bound to its own WordPress user and permissions.', 'mad4b-site-control-plane' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Related origins', 'mad4b-site-control-plane' ); ?></th><td>
						<input class="regular-text" name="development_origin" value="<?php echo esc_attr( isset( $related['development'] ) ? $related['development'] : '' ); ?>" placeholder="https://dev.example.com" /><br />
						<input class="regular-text" name="staging_origin" value="<?php echo esc_attr( isset( $related['staging'] ) ? $related['staging'] : '' ); ?>" placeholder="https://staging.example.com" /><br />
						<input class="regular-text" name="production_origin" value="<?php echo esc_attr( isset( $related['production'] ) ? $related['production'] : '' ); ?>" placeholder="https://example.com" />
					</td></tr>
					<tr><th><?php esc_html_e( 'Features', 'mad4b-site-control-plane' ); ?></th><td>
						<?php self::checkbox( 'oauth_enabled', 'Local/remote OAuth connection', ! empty( $features['oauth'] ) ); ?>
						<?php self::checkbox( 'skills_enabled', 'Skills authoring/export', ! empty( $features['skills'] ) ); ?>
						<?php self::checkbox( 'provider_isolation_enabled', 'Provider MCP isolation', ! empty( $features['provider_isolation'] ) ); ?>
						<?php self::checkbox( 'managed_runtime_enabled', 'Managed MCP runtime pinning', ! empty( $features['managed_runtime'] ) ); ?>
						<?php self::checkbox( 'acceptance_enabled', 'External acceptance evidence', ! empty( $features['acceptance'] ) ); ?>
						<?php self::checkbox( 'write_enabled', 'Governed write authority', ! empty( $features['write'] ) ); ?>
						<div data-mad4b-nonproduction-confirmation data-wordpress-production-default="<?php echo $wordpress_default_production ? '1' : '0'; ?>" data-configured-environment="<?php echo esc_attr( (string) ( $status['configured_environment'] ?? '' ) ); ?>" data-already-confirmed="<?php echo $override_already_confirmed ? '1' : '0'; ?>">
							<p class="description"><?php esc_html_e( 'WordPress is using its implicit Production default. Selecting a non-Production MAD4B environment changes governance policy for this exact origin. Confirm this once for the bound identity/environment.', 'mad4b-site-control-plane' ); ?></p>
							<?php self::checkbox( 'nonproduction_override_confirmed', 'I confirm this exact origin is not Production', false ); ?>
							<label style="display:block;margin:.6em 0" for="mad4b-nonproduction-override-confirmation"><?php esc_html_e( 'Type the exact non-Production confirmation phrase:', 'mad4b-site-control-plane' ); ?></label>
							<code><?php echo esc_html( MAD4B_SCP_Site_Profile::NONPRODUCTION_OVERRIDE_CONFIRMATION ); ?></code><br />
							<input class="regular-text" autocomplete="off" id="mad4b-nonproduction-override-confirmation" name="nonproduction_override_confirmation" value="" data-mad4b-one-time-confirm />
						</div>
						<div data-mad4b-production-confirmation>
							<p class="description"><?php esc_html_e( 'Required only when the selected environment is Production and governed write is enabled. To save Production without writes, clear Governed write authority.', 'mad4b-site-control-plane' ); ?></p>
							<?php self::checkbox( 'production_write_confirmed', 'I explicitly authorize governed writes on this Production origin', false ); ?>
							<label style="display:block;margin:.6em 0" for="mad4b-production-write-confirmation"><?php esc_html_e( 'Type the exact confirmation phrase when Production write is enabled:', 'mad4b-site-control-plane' ); ?></label>
							<code><?php echo esc_html( MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION ); ?></code><br />
							<input class="regular-text" autocomplete="off" id="mad4b-production-write-confirmation" name="production_write_confirmation" value="" data-mad4b-one-time-confirm />
						</div>
					</td></tr>
				</table>
				<div class="mad4b-settings-feedback" data-mad4b-settings-feedback aria-live="polite"></div>
				<?php submit_button( __( 'Save exact site profile', 'mad4b-site-control-plane' ) ); ?>
			</form>

			<?php if ( ! empty( $features['write'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:2em">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DISABLE ); ?>" />
				<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) ( isset( $status['revision'] ) ? absint( $status['revision'] ) : 0 ) ); ?>" />
				<?php wp_nonce_field( self::ACTION_DISABLE ); ?>
				<?php submit_button( __( 'Disable governed write authority', 'mad4b-site-control-plane' ), 'secondary' ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function checkbox( $name, $label, $checked ) {
		echo '<label style="display:block;margin:.4em 0"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}
}

// Routes are declared without booting menus or provider lifecycle on frontend requests.
if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry', false ) ) MAD4B_SCP_Admin_Route_Registry::register( MAD4B_SCP_Site_Profile_Admin::PAGE_SLUG, 'manage_options' );
