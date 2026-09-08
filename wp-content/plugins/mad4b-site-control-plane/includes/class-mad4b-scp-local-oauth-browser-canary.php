<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Staging-only browser client for proving the local OAuth browser round trip.
 *
 * This class does not enable OAuth, register a hidden client, create credentials,
 * persist PKCE material, persist bearer tokens, or mark external MCP connection
 * certification. The operator must explicitly register the displayed canary
 * client in wp-config.php. PKCE/state/token handling occurs in browser memory or
 * sessionStorage through the bundled JavaScript client.
 */
final class MAD4B_SCP_Local_OAuth_Browser_Canary {
	const CONTRACT = 'mad4b.local-oauth-browser-canary.v1';
	const PAGE_SLUG = 'mad4b-control-plane-oauth-canary';
	const CLIENT_ID = 'mad4b-staging-browser-canary';
	const CALLBACK_MARKER = 'callback';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 40 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'MAD4B OAuth Canary', 'mad4b-site-control-plane' ),
			__( 'OAuth Canary', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function redirect_uri() {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'mad4b_oauth_canary' => self::CALLBACK_MARKER,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function status() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$client_ready = self::client_registered();
		$staging = 'staging' === $environment;
		$can_run = $staging && current_user_can( 'manage_options' ) && $client_ready && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] );

		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'staging_only' => true,
			'client_id' => self::CLIENT_ID,
			'client_registered' => $client_ready,
			'redirect_uri' => self::redirect_uri(),
			'local_oauth_configured' => ! empty( $local['configured'] ),
			'local_oauth_effective' => ! empty( $local['effective'] ),
			'resource_bridge_effective' => ! empty( $bridge['effective'] ),
			'can_run' => (bool) $can_run,
			'persists_pkce_material' => false,
			'persists_bearer_tokens' => false,
			'creates_credentials' => false,
			'creates_clients' => false,
			'changes_configuration' => false,
			'external_connection_certified' => false,
		);
	}

	public static function enqueue_assets() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page selection.
		if ( self::PAGE_SLUG !== $page ) return;

		$status = self::status();
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		wp_enqueue_script(
			'mad4b-scp-local-oauth-canary',
			plugins_url( 'assets/local-oauth-canary.js', MAD4B_SCP_FILE ),
			array(),
			MAD4B_SCP_VERSION,
			true
		);
		wp_localize_script(
			'mad4b-scp-local-oauth-canary',
			'MAD4BLocalOAuthCanary',
			array(
				'contract' => self::CONTRACT,
				'canRun' => ! empty( $status['can_run'] ),
				'clientId' => self::CLIENT_ID,
				'redirectUri' => self::redirect_uri(),
				'issuer' => isset( $local['issuer'] ) ? (string) $local['issuer'] : '',
				'authorizationEndpoint' => isset( $local['authorization_endpoint'] ) ? (string) $local['authorization_endpoint'] : '',
				'tokenEndpoint' => isset( $local['token_endpoint'] ) ? (string) $local['token_endpoint'] : '',
				'jwksUri' => isset( $local['jwks_uri'] ) ? (string) $local['jwks_uri'] : '',
				'resource' => class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::resource_identifier() : '',
				'scope' => 'mad4b:read offline_access',
				'callbackMarker' => self::CALLBACK_MARKER,
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required to run the OAuth canary.', 'mad4b-site-control-plane' ) );
		$status = self::status();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'MAD4B Local OAuth Browser Canary', 'mad4b-site-control-plane' ); ?></h1>
			<p><?php echo esc_html__( 'Staging-only proof of the local browser Authorization Code + PKCE S256 round trip. This does not certify an external MCP client and does not persist OAuth tokens.', 'mad4b-site-control-plane' ); ?></p>

			<table class="widefat striped" style="max-width:980px">
				<tbody>
					<tr><th><?php echo esc_html__( 'Environment', 'mad4b-site-control-plane' ); ?></th><td><?php echo esc_html( $status['environment'] ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'Canary client registered', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['client_registered'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th><?php echo esc_html__( 'Local OAuth effective', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['local_oauth_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th><?php echo esc_html__( 'Resource bridge effective', 'mad4b-site-control-plane' ); ?></th><td><?php echo ! empty( $status['resource_bridge_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th><?php echo esc_html__( 'Tokens persisted by canary', 'mad4b-site-control-plane' ); ?></th><td>no</td></tr>
					<tr><th><?php echo esc_html__( 'External connection certified', 'mad4b-site-control-plane' ); ?></th><td>no</td></tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Explicit Staging configuration', 'mad4b-site-control-plane' ); ?></h2>
			<p><?php echo esc_html__( 'For a fresh Staging configuration, add the following to wp-config.php. If these constants already exist, merge the canary client and issuer-bound subject into the existing arrays instead of defining the constants twice.', 'mad4b-site-control-plane' ); ?></p>
			<textarea readonly rows="22" style="width:100%;max-width:1100px;font-family:monospace"><?php echo esc_textarea( self::configuration_snippet() ); ?></textarea>

			<h2><?php echo esc_html__( 'Browser canary', 'mad4b-site-control-plane' ); ?></h2>
			<p><?php echo esc_html__( 'The verifier and state stay in this browser tab session. The token endpoint response is held only in JavaScript memory; token values are never rendered or stored.', 'mad4b-site-control-plane' ); ?></p>
			<button type="button" class="button button-primary" id="mad4b-local-oauth-canary-start" <?php disabled( empty( $status['can_run'] ) ); ?>><?php echo esc_html__( 'Run Local OAuth Browser Canary', 'mad4b-site-control-plane' ); ?></button>
			<div id="mad4b-local-oauth-canary-result" role="status" aria-live="polite" style="margin-top:16px;max-width:980px"></div>

			<h2><?php echo esc_html__( 'CLI fallback', 'mad4b-site-control-plane' ); ?></h2>
			<p><code>tools/Invoke-MAD4BLocalOAuthStagingCanary.ps1</code></p>
			<p><?php echo esc_html__( 'The bundled PowerShell canary performs the same browser PKCE round trip from an operator workstation and intentionally never prints the access token.', 'mad4b-site-control-plane' ); ?></p>
		</div>
		<?php
	}

	private static function client_registered() {
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' ) ) return false;
		$clients = constant( 'MAD4B_MCP_LOCAL_OAUTH_CLIENTS' );
		if ( ! is_array( $clients ) || ! isset( $clients[ self::CLIENT_ID ] ) || ! is_array( $clients[ self::CLIENT_ID ] ) ) return false;
		$config = $clients[ self::CLIENT_ID ];
		$redirects = isset( $config['redirect_uris'] ) && is_array( $config['redirect_uris'] ) ? $config['redirect_uris'] : array();
		foreach ( $redirects as $redirect ) {
			if ( is_string( $redirect ) && hash_equals( self::redirect_uri(), trim( $redirect ) ) ) return true;
		}
		return false;
	}

	private static function configuration_snippet() {
		$user_id = get_current_user_id();
		$issuer = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::issuer() : untrailingslashit( home_url() ) . '/oauth/mcp';
		$redirect = self::redirect_uri();
		return "define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true );\n"
			. "define( 'MAD4B_MCP_OAUTH_MODE', 'local' );\n"
			. "define( 'MAD4B_MCP_OAUTH_ENABLED', true );\n"
			. "define( 'MAD4B_MCP_OAUTH_WP_USER_ID', " . (int) $user_id . " );\n\n"
			. "define(\n\t'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS',\n\tarray(\n\t\t'" . esc_url_raw( $issuer ) . "' => array( 'user:" . (int) $user_id . "' ),\n\t)\n);\n\n"
			. "define(\n\t'MAD4B_MCP_LOCAL_OAUTH_CLIENTS',\n\tarray(\n\t\t'" . self::CLIENT_ID . "' => array(\n\t\t\t'client_name' => 'MAD4B Staging Browser Canary',\n\t\t\t'redirect_uris' => array( '" . esc_url_raw( $redirect ) . "' ),\n\t\t\t'application_type' => 'web',\n\t\t),\n\t)\n);";
	}
}
