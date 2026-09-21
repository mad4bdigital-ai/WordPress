<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Guided, non-secret ChatGPT custom MCP connection surface.
 *
 * This page does not create a ChatGPT connector, store OAuth credentials, or
 * certify an external connection. It presents the exact gateway/OAuth values
 * needed by ChatGPT and keeps the browser canary as a secondary diagnostic.
 */
final class MAD4B_SCP_ChatGPT_Connection_Admin_UI {
	const CONTRACT = 'mad4b.chatgpt-connection-ui.v2';
	const PAGE_SLUG = 'mad4b-control-plane-chatgpt';
	const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
	const CHATGPT_REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
	const CHATGPT_CREATE_URL = 'https://chatgpt.com/plugins#settings/Connectors?create-connector=true&redirectAfter=%2Fplugins';

	private static $booted = false;

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
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$server_url = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
		$cimd_ready = ! empty( $local['client_id_metadata_document_supported'] )
			&& isset( $local['cimd_chatgpt_client_id'] )
			&& is_string( $local['cimd_chatgpt_client_id'] )
			&& hash_equals( self::CHATGPT_CLIENT_ID, $local['cimd_chatgpt_client_id'] );
		$gateway_registered = class_exists( 'MAD4B_SCP_Servers' ) && in_array( 'mad4b-chatgpt', MAD4B_SCP_Servers::expected_server_ids(), true );
		$ready = 'staging' === $environment && ! empty( $local['effective'] ) && ! empty( $bridge['effective'] ) && $cimd_ready && $gateway_registered;

		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'staging_only_initially' => true,
			'server_url' => $server_url,
			'authentication' => 'OAuth',
			'client_registration' => 'client_id_metadata_document',
			'client_id' => self::CHATGPT_CLIENT_ID,
			'redirect_uri' => self::CHATGPT_REDIRECT_URI,
			'token_endpoint_auth_method' => 'none',
			'scopes' => array( 'mad4b:read', 'offline_access' ),
			'gateway_server_id' => 'mad4b-chatgpt',
			'gateway_registered' => $gateway_registered,
			'local_oauth_effective' => ! empty( $local['effective'] ),
			'bridge_effective' => ! empty( $bridge['effective'] ),
			'cimd_supported' => ! empty( $local['client_id_metadata_document_supported'] ),
			'chatgpt_cimd_policy_ready' => $cimd_ready,
			'ready_for_chatgpt_draft' => (bool) $ready,
			'creates_chatgpt_connector' => false,
			'stores_chatgpt_credentials' => false,
			'external_connection_certified' => false,
			'generic_filesystem_exposed' => false,
			'generic_database_exposed' => false,
			'write_admin_breakglass_exposed' => false,
		);
	}

	public static function enqueue_assets() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page selection.
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

			<div class="notice notice-<?php echo ! empty( $status['ready_for_chatgpt_draft'] ) ? 'success' : 'warning'; ?> inline"><p>
				<strong><?php echo esc_html( ! empty( $status['ready_for_chatgpt_draft'] ) ? __( 'Ready to create a ChatGPT draft plugin.', 'mad4b-site-control-plane' ) : __( 'ChatGPT connection prerequisites are not complete yet.', 'mad4b-site-control-plane' ) ); ?></strong>
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
					<?php self::field_row( 'Scopes', 'mad4b:read offline_access' ); ?>
					<?php self::field_row( 'Gateway', 'mad4b-chatgpt — safe read projection' ); ?>
				</tbody>
			</table>

			<p style="margin-top:18px">
				<a class="button button-primary" href="<?php echo esc_url( self::CHATGPT_CREATE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open ChatGPT Plugin Builder', 'mad4b-site-control-plane' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MAD4B_SCP_Local_OAuth_Browser_Canary::PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Run OAuth Canary', 'mad4b-site-control-plane' ); ?></a>
			</p>

			<h2><?php echo esc_html__( 'What to enter in ChatGPT', 'mad4b-site-control-plane' ); ?></h2>
			<ol style="max-width:980px">
				<li><?php echo esc_html__( 'Open the ChatGPT plugin builder and enter the Name, Description and Server URL shown above.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'Choose OAuth authentication. Do not create or paste a WordPress-side ChatGPT client secret.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'ChatGPT discovers the authorization-server metadata, presents its HTTPS Client ID Metadata Document, and the server validates that document plus the exact redirect URI.', 'mad4b-site-control-plane' ); ?></li>
				<li><?php echo esc_html__( 'Run the ChatGPT tool scan, complete WordPress login/consent, then create the draft plugin.', 'mad4b-site-control-plane' ); ?></li>
			</ol>

			<h2><?php echo esc_html__( 'Gateway safety boundary', 'mad4b-site-control-plane' ); ?></h2>
			<p><?php echo esc_html__( 'The ChatGPT gateway excludes generic filesystem/database inspection and does not mount content mutation, mad4b-write, mad4b-admin, or mad4b-breakglass.', 'mad4b-site-control-plane' ); ?></p>

			<h2><?php echo esc_html__( 'Current readiness', 'mad4b-site-control-plane' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th>Environment</th><td><?php echo esc_html( $status['environment'] ); ?></td></tr>
					<tr><th>ChatGPT gateway registered</th><td><?php echo ! empty( $status['gateway_registered'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>Local OAuth effective</th><td><?php echo ! empty( $status['local_oauth_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>OAuth resource bridge effective</th><td><?php echo ! empty( $status['bridge_effective'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>CIMD advertised</th><td><?php echo ! empty( $status['cimd_supported'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>ChatGPT CIMD policy ready</th><td><?php echo ! empty( $status['chatgpt_cimd_policy_ready'] ) ? 'yes' : 'no'; ?></td></tr>
					<tr><th>External ChatGPT connection certified</th><td>no</td></tr>
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
