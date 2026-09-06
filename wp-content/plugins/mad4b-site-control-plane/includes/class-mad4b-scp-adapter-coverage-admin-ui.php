<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only product surface for automatic plugin/adapter coverage discovery. */
final class MAD4B_SCP_Adapter_Coverage_Admin_UI {
	const PAGE_SLUG = 'mad4b-adapter-coverage';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Adapter Coverage', 'mad4b-site-control-plane' ),
			__( 'Adapter Coverage', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_adapter_coverage_capability_denied', 'Administrator capability is required to inspect adapter coverage.' );
		if ( ! class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ) return new WP_Error( 'mad4b_plugin_discovery_unavailable', 'Plugin adapter discovery is unavailable.' );
		return MAD4B_SCP_Plugin_Discovery::coverage();
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect adapter coverage.', 'mad4b-site-control-plane' ) );
		$snapshot = self::snapshot();
		echo '<div class="wrap"><h1>' . esc_html__( 'MAD4B Adapter Coverage', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only discovery. Unknown plugins request an adapter contract but are never auto-installed, auto-enabled, auto-generated, or granted write authority.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>'; return; }
		$counts = isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ? $snapshot['counts'] : array();
		echo '<table class="widefat striped" style="max-width:1000px"><tbody>';
		foreach ( array( 'installed', 'active', 'supported_reversible', 'supported_governed', 'read_only_supported', 'adapter_registered_inactive', 'adapter_present_certification_required', 'adapter_present_side_channel_blocked', 'adapter_required', 'excluded_high_risk', 'priority_external_missing' ) as $key ) {
			echo '<tr><th>' . esc_html( str_replace( '_', ' ', $key ) ) . '</th><td>' . esc_html( isset( $counts[ $key ] ) ? (string) $counts[ $key ] : '0' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Installed plugins', 'mad4b-site-control-plane' ) . '</h2>';
		self::render_plugins( isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array() );

		echo '<h2>' . esc_html__( 'Priority external coverage', 'mad4b-site-control-plane' ) . '</h2>';
		self::render_priority( isset( $snapshot['priority_external'] ) && is_array( $snapshot['priority_external'] ) ? $snapshot['priority_external'] : array() );

		echo '<h2>' . esc_html__( 'Adapter support requests', 'mad4b-site-control-plane' ) . '</h2>';
		self::render_requests( isset( $snapshot['support_requests'] ) && is_array( $snapshot['support_requests'] ) ? $snapshot['support_requests'] : array() );
		echo '</div>';
	}

	private static function render_plugins( array $items ) {
		if ( ! $items ) { echo '<p>' . esc_html__( 'No plugins discovered.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Active</th><th>Family</th><th>Adapter</th><th>Coverage</th><th>Provider cert</th><th>Runtime blocker</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			$provider_cert = empty( $item['provider_certification_required'] ) ? 'not-required' : ( ! empty( $item['provider_certification_ok'] ) ? 'certified' : 'required' );
			$runtime_blocker = isset( $item['side_channel_blocker'] ) ? (string) $item['side_channel_blocker'] : '';
			echo '<tr><td><strong>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</strong><br><code>' . esc_html( isset( $item['plugin_file'] ) ? $item['plugin_file'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td>' . esc_html( ! empty( $item['active'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $item['family'] ) ? $item['family'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td><strong>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</strong></td><td>' . esc_html( $provider_cert ) . '</td><td><code>' . esc_html( $runtime_blocker ) . '</code></td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_priority( array $items ) {
		if ( ! $items ) { echo '<p>' . esc_html__( 'All configured priority external plugins are installed.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Plugin</th><th>Adapter</th><th>Coverage</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			echo '<tr><td>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_requests( array $items ) {
		if ( ! $items ) { echo '<p>' . esc_html__( 'No adapter support requests are currently required.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>Request</th><th>Plugin</th><th>Reason</th><th>Risk</th><th>Requested contracts</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$contracts = isset( $item['requested_contracts'] ) && is_array( $item['requested_contracts'] ) ? implode( ', ', $item['requested_contracts'] ) : '';
			echo '<tr><td><code>' . esc_html( isset( $item['support_request_id'] ) ? $item['support_request_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['plugin_name'] ) ? $item['plugin_name'] : '' ) . '</td><td>' . esc_html( isset( $item['reason_code'] ) ? $item['reason_code'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td>' . esc_html( $contracts ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
