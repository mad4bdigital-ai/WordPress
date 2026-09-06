<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only transport/connection console. It is not a connection wizard. */
final class MAD4B_SCP_Connection_Admin_UI {
	const PAGE_SLUG = 'mad4b-control-plane-connection';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'MAD4B Connection', 'mad4b-site-control-plane' ),
			__( 'Connection', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_connection_ui_capability_denied', 'Administrator capability is required to inspect connection readiness.' );
		if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) return new WP_Error( 'mad4b_connection_status_unavailable', 'MAD4B connection readiness service is unavailable.' );
		return MAD4B_SCP_Connection_Status::status();
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect MAD4B connection readiness.', 'mad4b-site-control-plane' ) );
		$status = self::snapshot();
		echo '<div class="wrap"><h1>' . esc_html__( 'MAD4B Connection', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only transport evidence. This screen does not create credentials, connect a client, make outbound requests, enable mutation, grant an agent, approve a ticket, or alter MCP configuration.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $status ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $status->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<h2>' . esc_html__( 'Connection truth', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Environment' => $status['environment'],
			'Environment is staging' => ! empty( $status['environment_is_staging'] ),
			'Site URL' => $status['site_url'],
			'HTTPS' => ! empty( $status['https'] ),
			'MCP Adapter version' => $status['mcp_adapter_version'],
			'MCP Adapter certified' => ! empty( $status['mcp_adapter_certified'] ),
			'Local transport ready' => ! empty( $status['local_transport_ready'] ),
			'Remote endpoint preflight ready' => ! empty( $status['remote_endpoint_preflight_ready'] ),
			'Connection certified' => ! empty( $status['connection_certified'] ),
		) );
		self::blockers( 'Local blockers', $status['local_blockers'] );
		self::blockers( 'Remote preflight blockers', $status['remote_preflight_blockers'] );
		self::blockers( 'Certification blockers', $status['certification_blockers'] );

		echo '<h2>' . esc_html__( 'MAD4B MCP endpoints', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Surface</th><th>Server</th><th>Endpoint</th><th>Registered</th><th>REST route</th><th>Permission binding</th></tr></thead><tbody>';
		foreach ( $status['servers'] as $server ) {
			echo '<tr><td>' . esc_html( $server['surface'] ) . '</td><td><code>' . esc_html( $server['server_id'] ) . '</code></td><td><code>' . esc_html( $server['endpoint'] ) . '</code></td><td>' . esc_html( self::yesno( $server['registered'] ) ) . '</td><td>' . esc_html( self::yesno( $server['route_registered'] ) ) . '</td><td>' . esc_html( self::yesno( $server['permission_callback_match'] ) ) . '<br><code>' . esc_html( $server['permission_callback'] ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		$write = isset( $status['write_surface'] ) && is_array( $status['write_surface'] ) ? $status['write_surface'] : array();
		echo '<h2>' . esc_html__( 'Governed write ingress', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'mad4b-write is a write-only projection of explicitly non-readonly governed Abilities. It is not a generic dispatcher and it requires grants bound to the actual write transport server.', 'mad4b-site-control-plane' ) . '</p>';
		self::kv( array(
			'Server' => isset( $write['server_id'] ) ? $write['server_id'] : 'mad4b-write',
			'Endpoint' => isset( $write['endpoint'] ) ? $write['endpoint'] : '',
			'Registered' => ! empty( $write['registered'] ),
			'REST route registered' => ! empty( $write['route_registered'] ),
			'Permission binding exact' => ! empty( $write['permission_callback_match'] ),
			'Mounted write tools' => isset( $write['mounted_write_tool_count'] ) ? (int) $write['mounted_write_tool_count'] : 0,
			'Global mutation configured' => ! empty( $write['mutation_global_enabled'] ),
			'Mutation effective for current request' => ! empty( $write['mutation_effective_for_current_request'] ),
			'Exact transport grant required' => ! empty( $write['exact_transport_grant_required'] ),
			'Generic dispatcher exposed' => ! empty( $write['generic_dispatcher_exposed'] ),
		) );

		$auth = $status['authentication'];
		echo '<h2>' . esc_html__( 'Authentication / subject bridge', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Transport model' => $auth['transport_model'],
			'Credential material exposed' => ! empty( $auth['credential_material_exposed'] ),
			'Credential creation supported here' => ! empty( $auth['credential_creation_supported_here'] ),
			'Remote subject bridge required' => ! empty( $auth['remote_subject_bridge_required'] ),
			'Current request subject authenticated' => ! empty( $auth['current_request_subject']['authenticated'] ),
			'Current request auth method' => isset( $auth['current_request_subject']['auth_method'] ) ? $auth['current_request_subject']['auth_method'] : '',
			'Subject fingerprint present' => ! empty( $auth['current_request_subject']['subject_fingerprint_present'] ),
			'Token scope count' => isset( $auth['current_request_subject']['token_scope_count'] ) ? (int) $auth['current_request_subject']['token_scope_count'] : 0,
		) );

		echo '<h2>' . esc_html__( 'External handshake boundary', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Externally verified' => ! empty( $status['external_handshake']['verified'] ),
			'Status' => $status['external_handshake']['status'],
			'Note' => $status['external_handshake']['note'],
		) );

		$peer = $status['mcp_peer_governance'];
		echo '<h2>' . esc_html__( 'MCP side-channel governance', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Inventory ready' => ! empty( $peer['inventory_ready'] ),
			'Write side-channel detected' => ! empty( $peer['write_side_channel_detected'] ),
			'Adapter server count' => isset( $peer['server_count'] ) ? (int) $peer['server_count'] : 0,
			'External Adapter peer count' => isset( $peer['external_peer_count'] ) ? (int) $peer['external_peer_count'] : 0,
			'Foreign MCP route count' => isset( $peer['foreign_transport']['route_count'] ) ? (int) $peer['foreign_transport']['route_count'] : 0,
			'Foreign MCP plugin count' => isset( $peer['foreign_transport']['plugin_count'] ) ? (int) $peer['foreign_transport']['plugin_count'] : 0,
		) );
		self::blockers( 'MCP blockers', isset( $peer['blockers'] ) ? $peer['blockers'] : array() );
		self::code_list( 'Foreign MCP routes', isset( $peer['foreign_transport']['routes'] ) ? $peer['foreign_transport']['routes'] : array() );
		self::code_list( 'Foreign MCP plugins', isset( $peer['foreign_transport']['plugins'] ) ? $peer['foreign_transport']['plugins'] : array() );

		echo '<h2>' . esc_html__( 'Breakglass', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Configured enabled' => ! empty( $status['breakglass']['configured_enabled'] ),
			'Effective for current request' => ! empty( $status['breakglass']['effective_for_current_request'] ),
		) );
		echo '</div>';
	}

	private static function kv( array $items ) {
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $items as $label => $value ) {
			if ( is_bool( $value ) ) $value = self::yesno( $value );
			echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function blockers( $title, $items ) {
		$items = is_array( $items ) ? $items : array();
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'None.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<ul>';
		foreach ( $items as $item ) echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
		echo '</ul>';
	}

	private static function code_list( $title, $items ) {
		$items = is_array( $items ) ? $items : array();
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'None detected.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<ul>';
		foreach ( $items as $item ) echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
		echo '</ul>';
	}

	private static function yesno( $value ) { return $value ? 'yes' : 'no'; }
}
