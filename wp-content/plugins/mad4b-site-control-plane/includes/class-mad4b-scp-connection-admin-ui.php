<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only transport/connection console with staged operator guidance. */
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
		$tabs = array(
			'readiness' => __( 'Readiness', 'mad4b-site-control-plane' ),
			'oauth' => __( 'OAuth & Identity', 'mad4b-site-control-plane' ),
			'endpoints' => __( 'MCP Endpoints', 'mad4b-site-control-plane' ),
			'isolation' => __( 'Isolation & Safety', 'mad4b-site-control-plane' ),
			'certification' => __( 'Certification', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'readiness'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'readiness';

		MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'MAD4B Connection', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Read-only connection workspace. It organizes environment, MCP transport, OAuth authority, isolation and external certification without creating credentials, connecting a client, enabling mutation, granting authority or making outbound discovery requests.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $status ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $status->get_error_message() ) . '</p></div></div>';
			return;
		}

		$oauth = isset( $status['oauth_resource_server'] ) && is_array( $status['oauth_resource_server'] ) ? $status['oauth_resource_server'] : array();
		$local_oauth = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		MAD4B_SCP_Admin_Experience::stages( self::connection_stages( $status, $oauth ) );
		MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );

		if ( 'readiness' === $tab ) self::render_readiness( $status, $oauth, $local_oauth );
		if ( 'oauth' === $tab ) self::render_oauth( $status, $oauth, $local_oauth );
		if ( 'endpoints' === $tab ) self::render_endpoints( $status );
		if ( 'isolation' === $tab ) self::render_isolation( $status );
		if ( 'certification' === $tab ) self::render_certification( $status );
		echo '</div>';
	}

	private static function connection_stages( array $status, array $oauth ) {
		$environment_ready = ! empty( $status['environment_is_staging'] ) && ! empty( $status['https'] ) && ! empty( $status['mcp_adapter_certified'] );
		$local_ready = ! empty( $status['local_transport_ready'] );
		$preflight_ready = ! empty( $status['remote_endpoint_preflight_ready'] );
		$certified = ! empty( $status['connection_certified'] );
		return array(
			array(
				'label' => __( 'Environment', 'mad4b-site-control-plane' ),
				'state' => $environment_ready ? 'complete' : 'attention',
				'detail' => __( 'Staging, HTTPS and certified MCP Adapter.', 'mad4b-site-control-plane' ),
				'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'readiness' ),
			),
			array(
				'label' => __( 'Transport', 'mad4b-site-control-plane' ),
				'state' => MAD4B_SCP_Admin_Experience::state_from_bool( $local_ready, ! empty( $status['local_blockers'] ) ),
				'detail' => __( 'Local MCP routes and permission bindings.', 'mad4b-site-control-plane' ),
				'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'endpoints' ),
			),
			array(
				'label' => __( 'OAuth preflight', 'mad4b-site-control-plane' ),
				'state' => $preflight_ready ? 'complete' : ( ! empty( $oauth['configured'] ) ? 'attention' : 'pending' ),
				'detail' => __( 'Local or external authority is bound to the exact protected resource.', 'mad4b-site-control-plane' ),
				'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'oauth' ),
			),
			array(
				'label' => __( 'External certification', 'mad4b-site-control-plane' ),
				'state' => $certified ? 'complete' : 'pending',
				'detail' => __( 'Real OAuth browser round-trip and MCP client handshake.', 'mad4b-site-control-plane' ),
				'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'certification' ),
			),
		);
	}

	private static function render_readiness( array $status, array $oauth, array $local_oauth ) {
		MAD4B_SCP_Admin_Experience::cards(
			array(
				array( 'label' => 'Environment', 'value' => isset( $status['environment'] ) ? $status['environment'] : 'unknown', 'state' => ! empty( $status['environment_is_staging'] ) ? 'complete' : 'attention', 'help' => 'Staging-first delivery boundary.' ),
				array( 'label' => 'Local transport', 'value' => ! empty( $status['local_transport_ready'] ) ? 'Ready' : 'Blocked', 'state' => MAD4B_SCP_Admin_Experience::state_from_bool( ! empty( $status['local_transport_ready'] ), ! empty( $status['local_blockers'] ) ), 'help' => 'MCP routes and permission binding.' ),
				array( 'label' => 'Remote preflight', 'value' => ! empty( $status['remote_endpoint_preflight_ready'] ) ? 'Ready' : 'Pending', 'state' => ! empty( $status['remote_endpoint_preflight_ready'] ) ? 'complete' : 'attention', 'help' => 'HTTPS + OAuth resource binding.' ),
				array( 'label' => 'Connection certification', 'value' => ! empty( $status['connection_certified'] ) ? 'Certified' : 'Not certified', 'state' => ! empty( $status['connection_certified'] ) ? 'complete' : 'pending', 'help' => 'Requires real external client evidence.' ),
			)
		);
		self::render_next_step( $status, $oauth, $local_oauth );
		echo '<h2>' . esc_html__( 'Connection truth', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Environment' => isset( $status['environment'] ) ? $status['environment'] : '',
			'Environment is staging' => ! empty( $status['environment_is_staging'] ),
			'Site URL' => isset( $status['site_url'] ) ? $status['site_url'] : '',
			'HTTPS' => ! empty( $status['https'] ),
			'MCP Adapter version' => isset( $status['mcp_adapter_version'] ) ? $status['mcp_adapter_version'] : '',
			'MCP Adapter certified' => ! empty( $status['mcp_adapter_certified'] ),
			'Local transport ready' => ! empty( $status['local_transport_ready'] ),
			'Remote endpoint preflight ready' => ! empty( $status['remote_endpoint_preflight_ready'] ),
			'Connection certified' => ! empty( $status['connection_certified'] ),
		) );
		self::blockers( 'Local blockers', isset( $status['local_blockers'] ) ? $status['local_blockers'] : array() );
		self::blockers( 'Remote preflight blockers', isset( $status['remote_preflight_blockers'] ) ? $status['remote_preflight_blockers'] : array() );
		self::blockers( 'Certification blockers', isset( $status['certification_blockers'] ) ? $status['certification_blockers'] : array() );
	}

	private static function render_next_step( array $status, array $oauth, array $local_oauth ) {
		if ( empty( $status['environment_is_staging'] ) ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Confirm that this target is the intended Staging environment before treating readiness as deployment evidence.', 'attention' );
			return;
		}
		if ( empty( $status['local_transport_ready'] ) ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Resolve the local MCP transport blockers before configuring any remote client.', 'blocked', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'endpoints' ), 'Inspect MCP endpoints' );
			return;
		}
		if ( empty( $status['remote_endpoint_preflight_ready'] ) ) {
			$mode = ! empty( $local_oauth['effective'] ) ? 'local WordPress OAuth authority' : ( ! empty( $oauth['configured'] ) ? 'external OAuth authority' : 'OAuth authority' );
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Complete the ' . $mode . ' preflight and clear the exact resource/issuer blockers.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'oauth' ), 'Inspect OAuth & Identity' );
			return;
		}
		if ( empty( $status['connection_certified'] ) ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Run the real external OAuth browser round-trip and MCP client tool scan. Repository or local preflight evidence alone does not certify the connection.', 'pending', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'certification' ), 'Open certification evidence' );
			return;
		}
		MAD4B_SCP_Admin_Experience::next_step( 'Connection certified', 'The connection reports externally verified certification evidence. Continue to governance and adapter coverage before enabling any mutation path.', 'complete' );
	}

	private static function render_oauth( array $status, array $oauth, array $local_oauth ) {
		echo '<h2>' . esc_html__( 'WordPress local OAuth authority', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Standalone mode is a separate local authorization authority. It uses WordPress login/session, Authorization Code + PKCE S256 and a site-local RS256 key outside the web root. This view reads status only.', 'mad4b-site-control-plane' ) . '</p>';
		self::kv( array(
			'Configured' => ! empty( $local_oauth['configured'] ),
			'Effective' => ! empty( $local_oauth['effective'] ),
			'Environment' => isset( $local_oauth['environment'] ) ? $local_oauth['environment'] : '',
			'Production separately approved' => ! empty( $local_oauth['production_approved'] ),
			'Issuer' => isset( $local_oauth['issuer'] ) ? $local_oauth['issuer'] : '',
			'Authorization endpoint' => isset( $local_oauth['authorization_endpoint'] ) ? $local_oauth['authorization_endpoint'] : '',
			'Token endpoint' => isset( $local_oauth['token_endpoint'] ) ? $local_oauth['token_endpoint'] : '',
			'JWKS' => isset( $local_oauth['jwks_uri'] ) ? $local_oauth['jwks_uri'] : '',
			'Revocation endpoint' => isset( $local_oauth['revocation_endpoint'] ) ? $local_oauth['revocation_endpoint'] : '',
			'Client registration mode' => isset( $local_oauth['client_registration_mode'] ) ? $local_oauth['client_registration_mode'] : '',
			'Pre-registered clients' => isset( $local_oauth['client_count'] ) ? (int) $local_oauth['client_count'] : 0,
			'PKCE' => isset( $local_oauth['pkce_methods_supported'] ) && is_array( $local_oauth['pkce_methods_supported'] ) ? implode( ' ', $local_oauth['pkce_methods_supported'] ) : '',
			'Access token signing' => isset( $local_oauth['access_token_signing_alg'] ) ? $local_oauth['access_token_signing_alg'] : '',
			'Private key exposed' => ! empty( $local_oauth['private_key_exposed'] ),
			'Private key stored in database' => ! empty( $local_oauth['private_key_stored_in_database'] ),
			'OAuth store ready' => ! empty( $local_oauth['oauth_store_ready'] ),
			'Runtime error' => isset( $local_oauth['runtime_error'] ) ? $local_oauth['runtime_error'] : '',
		) );

		echo '<h2>' . esc_html__( 'External / federated OAuth resource bridge', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'The same WordPress protected resource can trust an explicitly configured external MAD4B issuer. WordPress receives public verification material only and does not own the external signing key.', 'mad4b-site-control-plane' ) . '</p>';
		self::kv( array(
			'Bridge available' => ! empty( $oauth['available'] ),
			'Bridge configured' => ! empty( $oauth['configured'] ),
			'Bridge effective' => ! empty( $oauth['effective'] ),
			'Environment' => isset( $oauth['environment'] ) ? $oauth['environment'] : '',
			'Production separately approved' => ! empty( $oauth['production_approved'] ),
			'Issuer configured' => ! empty( $oauth['issuer_configured'] ),
			'Issuer' => isset( $oauth['issuer'] ) ? $oauth['issuer'] : '',
			'Resource' => isset( $oauth['resource'] ) ? $oauth['resource'] : '',
			'RFC 9728 metadata' => isset( $oauth['authoritative_metadata_url'] ) ? $oauth['authoritative_metadata_url'] : '',
			'Scopes' => isset( $oauth['scopes_supported'] ) && is_array( $oauth['scopes_supported'] ) ? implode( ' ', $oauth['scopes_supported'] ) : '',
			'WordPress subject ID' => isset( $oauth['wp_user_id'] ) ? (int) $oauth['wp_user_id'] : 0,
			'WordPress subject capable' => ! empty( $oauth['wp_user_capable'] ),
			'HTTPS binding' => ! empty( $oauth['https'] ),
			'Bearer algorithms accepted' => isset( $oauth['accepted_bearer_algorithms'] ) && is_array( $oauth['accepted_bearer_algorithms'] ) ? implode( ' ', $oauth['accepted_bearer_algorithms'] ) : '',
			'JWKS x5c required' => ! empty( $oauth['jwks_x5c_required'] ),
			'Outbound discovery on this screen' => ! empty( $oauth['outbound_discovery_on_admin'] ),
			'OAuth local preflight ready' => ! empty( $oauth['preflight_ready'] ),
		) );
		self::code_list( 'Authorization-server metadata candidates', isset( $oauth['authorization_server_metadata_urls'] ) ? $oauth['authorization_server_metadata_urls'] : array() );
		self::blockers( 'OAuth blockers', isset( $oauth['blockers'] ) ? $oauth['blockers'] : array() );

		$auth = isset( $status['authentication'] ) && is_array( $status['authentication'] ) ? $status['authentication'] : array();
		echo '<h2>' . esc_html__( 'Authentication / subject bridge', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Transport model' => isset( $auth['transport_model'] ) ? $auth['transport_model'] : '',
			'Credential material exposed' => ! empty( $auth['credential_material_exposed'] ),
			'Credential creation supported here' => ! empty( $auth['credential_creation_supported_here'] ),
			'Remote subject bridge required' => ! empty( $auth['remote_subject_bridge_required'] ),
			'Current request subject authenticated' => ! empty( $auth['current_request_subject']['authenticated'] ),
			'Current request auth method' => isset( $auth['current_request_subject']['auth_method'] ) ? $auth['current_request_subject']['auth_method'] : '',
			'Subject fingerprint present' => ! empty( $auth['current_request_subject']['subject_fingerprint_present'] ),
			'Token scope count' => isset( $auth['current_request_subject']['token_scope_count'] ) ? (int) $auth['current_request_subject']['token_scope_count'] : 0,
		) );
	}

	private static function render_endpoints( array $status ) {
		echo '<h2>' . esc_html__( 'MAD4B MCP endpoints', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Inspect the actual registered server surfaces before any client configuration. Permission binding must remain exact for every transport.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Surface</th><th>Server</th><th>Endpoint</th><th>Registered</th><th>REST route</th><th>Permission binding</th></tr></thead><tbody>';
		foreach ( isset( $status['servers'] ) && is_array( $status['servers'] ) ? $status['servers'] : array() as $server ) {
			echo '<tr><td>' . esc_html( isset( $server['surface'] ) ? $server['surface'] : '' ) . '</td><td><code>' . esc_html( isset( $server['server_id'] ) ? $server['server_id'] : '' ) . '</code></td><td><code>' . esc_html( isset( $server['endpoint'] ) ? $server['endpoint'] : '' ) . '</code></td><td>' . esc_html( self::yesno( ! empty( $server['registered'] ) ) ) . '</td><td>' . esc_html( self::yesno( ! empty( $server['route_registered'] ) ) ) . '</td><td>' . esc_html( self::yesno( ! empty( $server['permission_callback_match'] ) ) ) . '<br><code>' . esc_html( isset( $server['permission_callback'] ) ? $server['permission_callback'] : '' ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';

		$write = isset( $status['write_surface'] ) && is_array( $status['write_surface'] ) ? $status['write_surface'] : array();
		echo '<h2>' . esc_html__( 'Governed write ingress', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'mad4b-write is a write-only projection of explicitly non-readonly governed Abilities. It is not a generic dispatcher and requires grants bound to the actual write transport server.', 'mad4b-site-control-plane' ) . '</p>';
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
	}

	private static function render_isolation( array $status ) {
		$isolation = isset( $status['provider_mcp_isolation'] ) && is_array( $status['provider_mcp_isolation'] ) ? $status['provider_mcp_isolation'] : array();
		echo '<h2>' . esc_html__( 'Provider MCP isolation', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Isolation is deny-only and OFF by default. When explicitly enabled it suppresses only certified provider MCP/control routes; unknown MCP routes remain visible to fail-closed peer governance.', 'mad4b-site-control-plane' ) . '</p>';
		self::kv( array(
			'Configured' => ! empty( $isolation['configured'] ),
			'Effective' => ! empty( $isolation['effective'] ),
			'Environment' => isset( $isolation['environment'] ) ? $isolation['environment'] : '',
			'Production separately approved' => ! empty( $isolation['production_approved'] ),
			'Default MCP server suppressed' => ! empty( $isolation['default_server_suppressed'] ),
			'Removed route count' => isset( $isolation['removed_route_count'] ) ? (int) $isolation['removed_route_count'] : 0,
			'Unknown routes fail closed' => ! empty( $isolation['unknown_routes_fail_closed'] ),
			'Changes provider settings' => ! empty( $isolation['changes_provider_settings'] ),
			'Creates authority' => ! empty( $isolation['creates_authority'] ),
		) );
		self::code_list( 'Provider MCP routes removed', isset( $isolation['removed_routes'] ) ? $isolation['removed_routes'] : array() );

		$peer = isset( $status['mcp_peer_governance'] ) && is_array( $status['mcp_peer_governance'] ) ? $status['mcp_peer_governance'] : array();
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
		self::peer_table( 'External Adapter peers', isset( $peer['peers'] ) ? $peer['peers'] : array() );
		self::code_list( 'Foreign MCP routes', isset( $peer['foreign_transport']['routes'] ) ? $peer['foreign_transport']['routes'] : array() );
		self::code_list( 'Foreign MCP plugins', isset( $peer['foreign_transport']['plugins'] ) ? $peer['foreign_transport']['plugins'] : array() );

		$breakglass = isset( $status['breakglass'] ) && is_array( $status['breakglass'] ) ? $status['breakglass'] : array();
		echo '<h2>' . esc_html__( 'Breakglass', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Configured enabled' => ! empty( $breakglass['configured_enabled'] ),
			'Effective for current request' => ! empty( $breakglass['effective_for_current_request'] ),
		) );
	}

	private static function render_certification( array $status ) {
		$handshake = isset( $status['external_handshake'] ) && is_array( $status['external_handshake'] ) ? $status['external_handshake'] : array();
		MAD4B_SCP_Admin_Experience::cards(
			array(
				array( 'label' => 'External handshake', 'value' => ! empty( $handshake['verified'] ) ? 'Verified' : 'Unverified', 'state' => ! empty( $handshake['verified'] ) ? 'complete' : 'pending', 'help' => 'Must come from a real external client round-trip.' ),
				array( 'label' => 'Remote preflight', 'value' => ! empty( $status['remote_endpoint_preflight_ready'] ) ? 'Ready' : 'Pending', 'state' => ! empty( $status['remote_endpoint_preflight_ready'] ) ? 'complete' : 'attention', 'help' => 'Necessary but not sufficient for certification.' ),
				array( 'label' => 'Connection', 'value' => ! empty( $status['connection_certified'] ) ? 'Certified' : 'Not certified', 'state' => ! empty( $status['connection_certified'] ) ? 'complete' : 'pending', 'help' => 'Final connection truth exposed by this site.' ),
			)
		);
		echo '<h2>' . esc_html__( 'External handshake boundary', 'mad4b-site-control-plane' ) . '</h2>';
		self::kv( array(
			'Externally verified' => ! empty( $handshake['verified'] ),
			'Status' => isset( $handshake['status'] ) ? $handshake['status'] : '',
			'Note' => isset( $handshake['note'] ) ? $handshake['note'] : '',
		) );
		self::blockers( 'Certification blockers', isset( $status['certification_blockers'] ) ? $status['certification_blockers'] : array() );
		if ( empty( $status['connection_certified'] ) ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Required evidence', 'Use a real external MCP client to complete OAuth authorization, return through the registered callback, establish the protected-resource session and scan the intended MCP tools. Do not mark this page certified from repository CI or a local self-probe.', 'pending' );
		}
	}

	private static function kv( array $items ) {
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $items as $label => $value ) {
			if ( is_bool( $value ) ) $value = self::yesno( $value );
			echo '<tr><th style="width:260px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function blockers( $title, $items ) {
		$items = is_array( $items ) ? $items : array();
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'None.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<ul>';
		foreach ( $items as $item ) echo '<li><code>' . esc_html( (string) $item ) . '</code></li>';
		echo '</ul>';
	}

	private static function peer_table( $title, $items ) {
		$items = is_array( $items ) ? $items : array();
		$external = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['governed'] ) ) continue;
			$external[] = $item;
			if ( count( $external ) >= 20 ) break;
		}
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $external ) { echo '<p>' . esc_html__( 'None detected.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Server</th><th>Tool count</th><th>Risk count</th><th>Risk reasons</th></tr></thead><tbody>';
		foreach ( $external as $item ) {
			$reasons = isset( $item['risk_reasons'] ) && is_array( $item['risk_reasons'] ) ? array_slice( $item['risk_reasons'], 0, 20 ) : array();
			echo '<tr><td><code>' . esc_html( isset( $item['server_id'] ) ? (string) $item['server_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['tool_count'] ) ? (string) (int) $item['tool_count'] : '0' ) . '</td><td>' . esc_html( isset( $item['risk_count'] ) ? (string) (int) $item['risk_count'] : '0' ) . '</td><td>';
			if ( $reasons ) {
				foreach ( $reasons as $reason ) echo '<code style="margin-right:8px">' . esc_html( (string) $reason ) . '</code>';
			} else {
				echo esc_html__( 'No write risk classified.', 'mad4b-site-control-plane' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
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
