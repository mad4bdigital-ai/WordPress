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
			'expected_revision' => isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : 0,
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
			$verified = is_array( $profile )
				&& ! empty( $profile )
				&& isset( $status['revision'] )
				&& (int) $status['revision'] > (int) $input['expected_revision']
				&& hash_equals( (string) MAD4B_SCP_Site_Profile::profile_digest(), (string) ( isset( $status['profile_digest'] ) ? $status['profile_digest'] : MAD4B_SCP_Site_Profile::profile_digest() ) );
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
					'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
					'display_name' => MAD4B_SCP_Site_Profile::display_name(),
					'chatgpt_app_id' => MAD4B_SCP_Site_Profile::chatgpt_app_id(),
					'features' => isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array(),
				),
			) );
		}
		if ( is_wp_error( $result ) ) self::redirect( $result->get_error_code() );
		self::redirect( 'saved' );
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
		$features = isset( $profile['features'] ) && is_array( $profile['features'] ) ? $profile['features'] : array();
		$related = isset( $profile['related_origins'] ) && is_array( $profile['related_origins'] ) ? $profile['related_origins'] : array();
		$users = MAD4B_SCP_Site_Profile::oauth_user_ids();
		$state = isset( $_GET['mad4b_site_profile'] ) ? sanitize_key( wp_unslash( $_GET['mad4b_site_profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MAD4B Site Profile', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Enroll this exact WordPress origin before remote OAuth or governed write authority can become active. Unknown sites remain fail-closed after installation.', 'mad4b-site-control-plane' ); ?></p>
			<?php if ( '' !== $state ) : ?><div class="notice notice-info"><p><?php echo esc_html( $state ); ?></p></div><?php endif; ?>
			<table class="widefat striped" style="max-width:1000px;margin:1em 0">
				<tbody>
				<tr><th><?php esc_html_e( 'Environment', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['environment'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Observed origin', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['current_origin'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Enrolled origin', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['canonical_origin'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Site UUID', 'mad4b-site-control-plane' ); ?></th><td><code><?php echo esc_html( (string) $status['site_uuid'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Revision', 'mad4b-site-control-plane' ); ?></th><td><?php echo esc_html( (string) $status['revision'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Origin binding', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['origin_match'] ) && ! empty( $status['environment_match'] ) ? 'PASS' : 'BLOCKED'; ?></td></tr>
				</tbody>
			</table>

			<form id="mad4b-site-profile-settings" class="mad4b-settings-ajax-form" data-mad4b-refresh-selector="#mad4b-site-profile-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) ( isset( $status['revision'] ) ? absint( $status['revision'] ) : 0 ) ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<table class="form-table" role="presentation">
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
						<?php if ( 'production' === MAD4B_SCP_Site_Profile::current_environment() ) : ?>
							<?php self::checkbox( 'production_write_confirmed', 'I explicitly authorize governed writes on this Production origin', ! empty( $features['production_write_confirmed'] ) ); ?>
							<label style="display:block;margin:.6em 0" for="mad4b-production-write-confirmation"><?php esc_html_e( 'Type the exact confirmation phrase when Production write is enabled:', 'mad4b-site-control-plane' ); ?></label>
							<code><?php echo esc_html( MAD4B_SCP_Site_Profile::PRODUCTION_WRITE_CONFIRMATION ); ?></code><br />
							<input class="regular-text" autocomplete="off" id="mad4b-production-write-confirmation" name="production_write_confirmation" value="" data-mad4b-one-time-confirm />
						<?php endif; ?>
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
