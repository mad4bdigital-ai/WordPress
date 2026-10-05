<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Administrator onboarding for tenant-neutral site enrollment. */
final class MAD4B_SCP_Site_Profile_Admin {
	const PAGE_SLUG = 'mad4b-control-plane-site-profile';
	const ACTION_SAVE = 'mad4b_site_profile_save';
	const ACTION_DISABLE = 'mad4b_site_profile_disable_authority';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 25 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DISABLE, array( __CLASS__, 'handle_disable' ) );
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

	public static function handle_save() {
		self::require_save_request();
		$input = array(
			'environment' => isset( $_POST['environment'] ) ? wp_unslash( $_POST['environment'] ) : '',
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
			wp_send_json_success( array(
				'message' => __( 'Site Profile saved and verified by persisted readback.', 'mad4b-site-control-plane' ),
				'persistence_verified' => true,
				'readback' => array(
					'revision' => (int) $status['revision'],
					'environment' => (string) $status['configured_environment'],
					'effective_environment' => (string) $status['environment'],
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
		self::redirect( 'saved' );
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
		$state = isset( $_GET['mad4b_site_profile'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_site_profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap" id="mad4b-site-profile-workspace">
			<h1><?php echo esc_html__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Enroll this exact WordPress origin before remote OAuth or governed write authority can become active. Unknown sites remain fail-closed after installation.', 'mad4b-site-control-plane' ); ?></p>
			<?php if ( '' !== $state ) : ?><div class="notice notice-info"><p><?php echo esc_html( $state ); ?></p></div><?php endif; ?>
			<table class="widefat striped" style="max-width:1000px;margin:1em 0">
				<tbody>
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
						<p class="description"><?php esc_html_e( 'No wp-config.php edit is required when WordPress is using its implicit Production default. Saving binds the selected environment to this exact origin; an explicitly configured WordPress environment always wins, and copied profiles remain quarantined.', 'mad4b-site-control-plane' ); ?></p>
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
