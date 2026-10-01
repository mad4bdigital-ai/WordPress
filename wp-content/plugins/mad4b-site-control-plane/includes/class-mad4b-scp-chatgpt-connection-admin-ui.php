<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guided, non-secret ChatGPT custom MCP connection surface.
 *
 * This page does not create a ChatGPT connector, store OAuth credentials, or
 * certify an external connection. It presents the exact gateway/OAuth values
 * needed by ChatGPT. Portable Production connection remains read-only, while an
 * explicitly enrolled exact Production Site Profile may enable governed writes
 * through the same approval-bound authority pipeline. Developer and Breakglass
 * remain separate and non-Production.
 */
final class MAD4B_SCP_ChatGPT_Connection_Admin_UI {
	const CONTRACT = 'mad4b.chatgpt-connection-ui.v3';
	const PAGE_SLUG = 'mad4b-control-plane-chatgpt';
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const CHATGPT_REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
	const CHATGPT_CREATE_URL = 'https://chatgpt.com/plugins#settings/Connectors?create-connector=true&redirectAfter=%2Fplugins';

	private static $booted = false;
	private static $status_cache = null;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 35 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Connect to ChatGPT', 'mad4b-site-control-plane' ),
			__( 'Connect to ChatGPT', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function status() {
		if ( is_array( self::$status_cache ) ) return self::$status_cache;
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( class_exists( 'MAD4B_SCP_Site_Profile' ) ? sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() ) : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' ) );
		$profile = class_exists( 'MAD4B_SCP_Staging_OAuth_Autoconfig' ) ? MAD4B_SCP_Staging_OAuth_Autoconfig::status() : array();
		$portable = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) ? MAD4B_SCP_Portable_Readonly_Connection::status() : array();
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) && method_exists( 'MAD4B_SCP_Local_OAuth_Server', 'runtime_identity_status' )
			? MAD4B_SCP_Local_OAuth_Server::runtime_identity_status()
			: array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) && method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'runtime_identity_status' )
			? MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()
			: array();
		$external_evidence = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' ) && method_exists( 'MAD4B_SCP_External_Handshake_Evidence', 'persisted_identity_status' )
			? MAD4B_SCP_External_Handshake_Evidence::persisted_identity_status()
			: array();
		$external_evidence_present = ! empty( $external_evidence['previously_verified_external_session'] );
		$external_certification_state = $external_evidence_present
			? ( isset( $external_evidence['certification_projection_state'] ) ? sanitize_key( (string) $external_evidence['certification_projection_state'] ) : 'previously_verified_revalidation_deferred' )
			: 'not_certified';
		$server_url = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
		$cimd_ready = ! empty( $local['client_id_metadata_document_supported'] )
			&& isset( $local['cimd_chatgpt_client_id'] )
			&& is_string( $local['cimd_chatgpt_client_id'] )
			&& hash_equals( self::CHATGPT_CLIENT_ID, $local['cimd_chatgpt_client_id'] );
		$gateway_registered = class_exists( 'MAD4B_SCP_Servers' ) && in_array( 'mad4b-chatgpt', MAD4B_SCP_Servers::expected_server_ids(), true );
		$portable_ready = ! empty( $portable['effective'] );
		$production_readonly_enabled = 'production' === $environment && ( ! empty( $profile['production_readonly_enabled'] ) || $portable_ready );
		$production_readonly_auto_enabled = 'production' === $environment && $portable_ready;
		$profile_oauth_ready = class_exists( 'MAD4B_SCP_Site_Profile' ) && (
			'production' === $environment
				? ( MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment() && MAD4B_SCP_Site_Profile::oauth_enabled() )
				: ( MAD4B_SCP_Site_Profile::nonproduction_governed( 'oauth' ) && MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
		);
		$production_governed_write_enabled = 'production' === $environment && $profile_oauth_ready && MAD4B_SCP_Site_Profile::write_enabled();
		$environment_ready = $portable_ready || $profile_oauth_ready;
		$oauth_canary_available = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment() && MAD4B_SCP_Site_Profile::oauth_enabled();
		$step_up_available = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) && MAD4B_SCP_OAuth_Resource_Bridge::authority_step_up_scope_available();
		$scopes = $step_up_available ? array( 'mad4b:read', 'mad4b:authority:step-up', 'offline_access' ) : array( 'mad4b:read', 'offline_access' );
		$ready = $environment_ready && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] ) && $cimd_ready && $gateway_registered;

		self::$status_cache = array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'staging_only_initially' => false,
			'production_readonly_profile_supported' => true,
			'production_readonly_enabled' => $production_readonly_enabled,
			'production_readonly_auto_enabled' => $production_readonly_auto_enabled,
			'production_readonly_opt_in_required' => 'production' === $environment && ! $production_readonly_enabled && ! $production_governed_write_enabled,
			'production_readonly_write_enabled' => false,
			'production_governed_write_enabled' => (bool) $production_governed_write_enabled,
			'production_write_policy' => 'production' === $environment ? 'exact_profile_plus_exact_one_time_approval' : 'not_applicable',
			'authority_step_up_available' => (bool) $step_up_available,
			'production_readonly_breakglass_enabled' => false,
			'profile_oauth_ready' => (bool) $profile_oauth_ready,
			'portable_readonly_ready' => (bool) $portable_ready,
			'portable_profile_binding_state' => isset( $portable['profile_binding_state'] ) ? sanitize_key( (string) $portable['profile_binding_state'] ) : '',
			'foreign_profile_quarantined' => ! empty( $portable['foreign_profile_quarantined'] ),
			'requires_site_enrollment' => ! empty( $portable['requires_site_enrollment'] ),
			'portable_blocker' => isset( $portable['blocker'] ) ? sanitize_key( (string) $portable['blocker'] ) : '',
			'environment_ready' => (bool) $environment_ready,
			'oauth_canary_available' => (bool) $oauth_canary_available,
			'server_url' => $server_url,
			'authentication' => 'OAuth',
			'client_registration' => 'client_id_metadata_document',
			'client_id' => self::CHATGPT_CLIENT_ID,
			'redirect_uri' => self::CHATGPT_REDIRECT_URI,
			'token_endpoint_auth_method' => 'none',
			'scopes' => $scopes,
			'gateway_server_id' => 'mad4b-chatgpt',
			'gateway_registered' => $gateway_registered,
			'local_oauth_effective' => ! empty( $local['effective'] ),
			'bridge_effective' => ! empty( $bridge['effective'] ),
			'cimd_supported' => ! empty( $local['client_id_metadata_document_supported'] ),
			'chatgpt_cimd_policy_ready' => $cimd_ready,
			'ready_for_chatgpt_draft' => (bool) $ready,
			'creates_chatgpt_connector' => false,
			'stores_chatgpt_credentials' => false,
			// Passive admin never performs the deep current-build/catalog revalidation
			// required to claim present-tense certification. Preserve the stronger
			// persisted fact separately: a previous real external ChatGPT OAuth/MCP
			// session completed initialize + exact tools/list on this site.
			'external_connection_certified' => false,
			'external_connection_certification_deferred' => $external_evidence_present,
			'external_connection_certification_state' => $external_certification_state,
			'external_connection_evidence_present' => $external_evidence_present,
			'external_connection_last_verified_at' => isset( $external_evidence['verified_at'] ) ? sanitize_text_field( (string) $external_evidence['verified_at'] ) : '',
			'external_connection_evidence_projection' => 'persisted_identity',
			'generic_filesystem_exposed' => false,
			'generic_database_exposed' => false,
			'write_admin_breakglass_exposed' => false,
			'admin_status_projection' => 'runtime_identity',
			'deep_oauth_status_deferred' => true,
		);
		return self::$status_cache;
	}

	public static function enqueue_assets() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page-scoped read-only selection.
		if ( self::PAGE_SLUG !== $page ) return;
		wp_enqueue_script(
			'mad4b-scp-chatgpt-connection',
			plugins_url( 'assets/chatgpt-connection.js', MAD4B_SCP_FILE ),
			array(),
			MAD4B_SCP_VERSION,
			true
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to inspect ChatGPT connection settings.', 'mad4b-site-control-plane' ) );
		$status = self::status();
		$site_name = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$name = '' !== $site_name ? 'MAD4B WordPress — ' . $site_name : 'MAD4B WordPress';
		$description = 'Governed read-only WordPress MCP access through the MAD4B ChatGPT gateway.';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Connect WordPress to ChatGPT', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Use the dedicated mad4b-chatgpt gateway. ChatGPT discovers OAuth and uses its Client ID Metadata Document automatically; no WordPress-side ChatGPT client secret or manual client registration is required.', 'mad4b-site-control-plane' ); ?></p>

			<?php if ( ! empty( $status['foreign_profile_quarantined'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<strong><?php echo esc_html__( 'A Site Profile from another tenant is quarantined.', 'mad4b-site-control-plane' ); ?></strong>
					<?php echo esc_html__( ' Read-only OAuth is bound dynamically to this site’s current origin and local Administrators. No write, Skills, Developer or Breakglass authority is inherited. Explicitly enroll this site before enabling governed features.', 'mad4b-site-control-plane' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( 'production' === $status['environment'] ) : ?>
				<?php $production_governed = ! empty( $status['production_governed_write_enabled'] ); ?>
				<div class="notice notice-<?php echo ( $production_governed || ! empty( $status['production_readonly_enabled'] ) ) ? 'success' : 'warning'; ?> inline"><p>
					<strong><?php
						echo esc_html(
							$production_governed
								? __( 'Production governed OAuth and write authority are enabled.', 'mad4b-site-control-plane' )
								: ( ! empty( $status['production_readonly_enabled'] )
									? __( 'Production read-only OAuth profile is enabled.', 'mad4b-site-control-plane' )
									: __( 'Production read-only OAuth requires explicit administrator opt-in.', 'mad4b-site-control-plane' ) )
						);
					?></strong>
					<?php echo esc_html__( ' Portable mode is read-only. An exact enrolled Production Site Profile with explicit Production write confirmation may bootstrap governed OAuth directly; every Production write remains bound to exact grants and a one-time approval. Developer and Breakglass remain unavailable.', 'mad4b-site-control-plane' ); ?>
				</p></div>
				<?php if ( ! $production_governed && empty( $status['production_readonly_auto_enabled'] ) ) : ?>
					<form class="mad4b-settings-ajax-form" data-mad4b-refresh-selector=".wrap" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:14px 0 20px">
						<?php wp_nonce_field( 'mad4b_production_readonly_oauth' ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( ! empty( $status['production_readonly_enabled'] ) ? 'mad4b_disable_production_readonly_oauth' : 'mad4b_enable_production_readonly_oauth' ); ?>">
						<div class="mad4b-settings-feedback" data-mad4b-settings-feedback aria-live="polite"></div>
						<button type="submit" class="button <?php echo empty( $status['production_readonly_enabled'] ) ? 'button-primary' : ''; ?>">
							<?php echo esc_html( ! empty( $status['production_readonly_enabled'] ) ? __( 'Disable Production read-only OAuth', 'mad4b-site-control-plane' ) : __( 'Enable Production read-only OAuth', 'mad4b-site-control-plane' ) ); ?>
						</button>
					</form>
				<?php else : ?>
					<p><strong><?php echo esc_html__( 'Portable read-only auto-connect is active for this site.', 'mad4b-site-control-plane' ); ?></strong> <?php echo esc_html__( 'Write authority is separate and remains disabled until the exact Production Site Profile explicitly enables it.', 'mad4b-site-control-plane' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="notice notice-<?php echo ! empty( $status['ready_for_chatgpt_draft'] ) ? 'success' : 'warning'; ?> inline"><p>
				<strong><?php echo esc_html( ! empty( $status['ready_for_chatgpt_draft'] ) ? __( 'Ready to create or refresh a ChatGPT draft plugin.', 'mad4b-site-control-plane' ) : __( 'ChatGPT connection prerequisites are not complete yet.', 'mad4b-site-control-plane' ) ); ?></strong>
			</p></div>

			<table class="widefat striped" style="max-width:1100px;margin-top:16px">
				<tbody>
					<?php self::field_row( 'Name', $name ); ?>
					<?php self::field_row( 'Description', $description ); ?>
					<?php self::field_row( 'Server URL', $status['server_url'] ); ?>
					<?php self::field_row( 'Authentication', 'OAuth' ); ?>
					<?php self::field_row( 'Client registration', 'CIMD / ChatGPT managed' ); ?>
					<?php self::field_row( 'ChatGPT Client Identity', self::CHATGPT_CLIENT_ID ); ?>
					<?php self::field_row( 'ChatGPT Redirect URI', self::CHATGPT_REDIRECT_URI ); ?>
					<?php self::field_row( 'Token endpoint auth', 'none (public client + PKCE S256)' ); ?>
					<?php self::field_row( 'Scopes', implode( ' ', $status['scopes'] ) ); ?>
					<?php self::field_row( 'Gateway', 'mad4b-chatgpt — governed read + approval-bound mutation projection' ); ?>
				</tbody>
			</table>

			<p style="margin-top:18px">
				<a class="button button-primary" href="<?php echo esc_url( self::CHATGPT_CREATE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open ChatGPT Plugin Builder', 'mad4b-site-control-plane' ); ?></a>
				<?php if ( ! empty( $status['oauth_canary_available'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MAD4B_SCP_Local_OAuth_Browser_Canary::PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Run OAuth Canary', 'mad4b-site-control-plane' ); ?></a>
				<?php endif; ?>
			</p>

			<h2><?php echo esc_html__( 'What to enter in ChatGPT', 'mad4b-site-control-plane' ); ?></h2>
			<ol style="max-width:980px">
				<li><?php echo esc_html__( 'Open the ChatGPT plugin builder and enter the Name, Description and Server URL shown above.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'Choose OAuth authentication. Do not create or paste a WordPress-side ChatGPT client secret.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'ChatGPT discovers the authorization-server metadata, presents its HTTPS Client ID Metadata Document, and the server validates that document plus the exact redirect URI.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'Run the ChatGPT tool scan, complete WordPress login/consent, then create or refresh the draft plugin.', 'mad4b-site-control-plane' ); ?></li>
			</ol>

			<h2><?php echo esc_html__( 'Gateway safety boundary', 'mad4b-site-control-plane' ); ?></h2>
			<p><?php echo esc_html__( 'The ChatGPT gateway excludes generic filesystem/database inspection and raw Breakglass. Portable Production mode remains read-only; an exact Production Site Profile may enable only governed, exactly granted mutations with one-time approval. Developer and Developer Breakglass remain non-Production.', 'mad4b-site-control-plane' ); ?></p>

			<h2><?php echo esc_html__( 'Current readiness', 'mad4b-site-control-plane' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th>Environment</th><td><?php echo esc_html( $status['environment'] ); ?></td></tr>
					<tr><th>Site Profile binding</th><td><?php echo esc_html( '' !== $status['portable_profile_binding_state'] ? $status['portable_profile_binding_state'] : ( ! empty( $status['profile_oauth_ready'] ) ? 'exact' : 'unconfigured' ) ); ?></td></tr>
					<tr><th>Foreign profile quarantined</th><td><?php echo ! empty( $status['foreign_profile_quarantined'] ) ? 'yes' : 'no'; ?></td></tr>
					<?php if ( 'production' === $status['environment'] ) : ?>
					<tr><th>Production read-only profile</th><td><?php echo ! empty( $status['production_readonly_enabled'] ) ? 'enabled' : 'disabled'; ?></td></tr>
					<tr><th>Production governed write</th><td><?php echo ! empty( $status['production_governed_write_enabled'] ) ? 'enabled · exact approval required' : 'disabled'; ?></td></tr>
					<?php endif; ?>
					<tr><th>ChatGPT gateway registered</th><td><?php echo ! empty( $status['gateway_registered'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>Local OAuth effective</th><td><?php echo ! empty( $status['local_oauth_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>OAuth resource bridge effective</th><td><?php echo ! empty( $status['bridge_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>CIMD advertised</th><td><?php echo ! empty( $status['cimd_supported'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>ChatGPT CIMD policy ready</th><td><?php echo ! empty( $status['chatgpt_cimd_policy_ready'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>External ChatGPT connection certification</th><td><?php echo esc_html( ! empty( $status['external_connection_certified'] ) ? 'certified' : ( ! empty( $status['external_connection_certification_deferred'] ) ? 'previously verified · deep revalidation deferred' : 'not certified' ) ); ?></td></tr>
					<?php if ( ! empty( $status['external_connection_last_verified_at'] ) ) : ?>
					<tr><th>Last verified external session</th><td><?php echo esc_html( $status['external_connection_last_verified_at'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function field_row( $label, $value ) {
		$id = 'mad4b-chatgpt-' . sanitize_key( $label );
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td><input id="' . esc_attr( $id ) . '" type="text" readonly value="' . esc_attr( (string) $value ) . '" style="width:min(100%,760px)"> <button type="button" class="button mad4b-chatgpt-copy" data-copy-target="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'mad4b-site-control-plane' ) . '</button></td></tr>';
	}
}
