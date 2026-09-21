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
		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'installed' => __( 'Installed Plugins', 'mad4b-site-control-plane' ),
			'priority' => __( 'Priority Coverage', 'mad4b-site-control-plane' ),
			'requests' => __( 'Support Requests', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';

		MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'MAD4B Adapter Coverage', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Read-only adapter readiness workspace. It separates discovery, support coverage, provider certification and runtime blockers. Unknown plugins are never auto-installed, auto-enabled, auto-generated or granted write authority.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>'; return; }

		$counts = isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ? $snapshot['counts'] : array();
		MAD4B_SCP_Admin_Experience::stages( self::coverage_stages( $counts ) );
		MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );

		if ( 'overview' === $tab ) self::render_overview( $snapshot, $counts );
		if ( 'installed' === $tab ) self::render_plugins( isset( $snapshot['plugins'] ) && is_array( $snapshot['plugins'] ) ? $snapshot['plugins'] : array() );
		if ( 'priority' === $tab ) self::render_priority( isset( $snapshot['priority_external'] ) && is_array( $snapshot['priority_external'] ) ? $snapshot['priority_external'] : array() );
		if ( 'requests' === $tab ) self::render_requests( isset( $snapshot['support_requests'] ) && is_array( $snapshot['support_requests'] ) ? $snapshot['support_requests'] : array() );
		echo '</div>';
	}

	private static function coverage_stages( array $counts ) {
		$installed = isset( $counts['installed'] ) ? (int) $counts['installed'] : 0;
		$needs_adapter = isset( $counts['adapter_required'] ) ? (int) $counts['adapter_required'] : 0;
		$needs_cert = isset( $counts['adapter_present_certification_required'] ) ? (int) $counts['adapter_present_certification_required'] : 0;
		$side_channel = isset( $counts['adapter_present_side_channel_blocked'] ) ? (int) $counts['adapter_present_side_channel_blocked'] : 0;
		return array(
			array( 'label' => __( 'Discover', 'mad4b-site-control-plane' ), 'state' => $installed > 0 ? 'complete' : 'pending', 'detail' => __( 'Inventory installed and active plugins.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
			array( 'label' => __( 'Match adapter', 'mad4b-site-control-plane' ), 'state' => 0 === $needs_adapter ? 'complete' : 'attention', 'detail' => __( 'Map each plugin to a governed support strategy.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'requests' ) ),
			array( 'label' => __( 'Certify provider', 'mad4b-site-control-plane' ), 'state' => 0 === $needs_cert ? 'complete' : 'attention', 'detail' => __( 'Verify exact provider version and contract before mutation.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
			array( 'label' => __( 'Clear runtime blockers', 'mad4b-site-control-plane' ), 'state' => 0 === $side_channel ? 'complete' : 'blocked', 'detail' => __( 'Resolve parallel MCP/write-plane isolation blockers.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ) ),
		);
	}

	private static function render_overview( array $snapshot, array $counts ) {
		$supported = (int) ( isset( $counts['supported_reversible'] ) ? $counts['supported_reversible'] : 0 )
			+ (int) ( isset( $counts['supported_governed'] ) ? $counts['supported_governed'] : 0 )
			+ (int) ( isset( $counts['read_only_supported'] ) ? $counts['read_only_supported'] : 0 );
		$needs_adapter = isset( $counts['adapter_required'] ) ? (int) $counts['adapter_required'] : 0;
		$needs_cert = isset( $counts['adapter_present_certification_required'] ) ? (int) $counts['adapter_present_certification_required'] : 0;
		$side_channel = isset( $counts['adapter_present_side_channel_blocked'] ) ? (int) $counts['adapter_present_side_channel_blocked'] : 0;
		$priority_missing = isset( $counts['priority_external_missing'] ) ? (int) $counts['priority_external_missing'] : 0;

		MAD4B_SCP_Admin_Experience::cards( array(
			array( 'label' => 'Installed', 'value' => isset( $counts['installed'] ) ? (string) $counts['installed'] : '0', 'state' => ! empty( $counts['installed'] ) ? 'complete' : 'pending', 'help' => 'Plugins included in runtime discovery.' ),
			array( 'label' => 'Supported', 'value' => (string) $supported, 'state' => 'complete', 'help' => 'Reversible, governed or read-only coverage.' ),
			array( 'label' => 'Needs adapter', 'value' => (string) $needs_adapter, 'state' => 0 === $needs_adapter ? 'complete' : 'attention', 'help' => 'No silent fallback to write authority.' ),
			array( 'label' => 'Needs certification', 'value' => (string) $needs_cert, 'state' => 0 === $needs_cert ? 'complete' : 'attention', 'help' => 'Adapter exists but exact provider proof is missing.' ),
			array( 'label' => 'Runtime blocked', 'value' => (string) $side_channel, 'state' => 0 === $side_channel ? 'complete' : 'blocked', 'help' => 'Parallel MCP/write-plane risk remains.' ),
			array( 'label' => 'Priority missing', 'value' => (string) $priority_missing, 'state' => 0 === $priority_missing ? 'complete' : 'pending', 'help' => 'Priority external plugins not installed on this site.' ),
		) );

		if ( $side_channel > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Highest-priority attention', 'One or more adapters are blocked by a parallel MCP/write surface. Keep normal mutation denied until the provider-native plane is isolated and certified.', 'blocked', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ), 'Inspect runtime blockers' );
		} elseif ( $needs_cert > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Complete exact provider certification for adapters that are present but not yet mutation-ready.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'installed' ), 'Inspect provider certification' );
		} elseif ( $needs_adapter > 0 ) {
			MAD4B_SCP_Admin_Experience::next_step( 'Next step', 'Review deterministic adapter support requests for plugins that do not yet have a governed support contract.', 'attention', MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'requests' ), 'Review support requests' );
		} else {
			MAD4B_SCP_Admin_Experience::next_step( 'Coverage ready', 'No unresolved adapter, certification or side-channel blockers are reported by the current discovery snapshot.', 'complete' );
		}

		echo '<h2>' . esc_html__( 'Coverage counts', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped" style="max-width:1000px"><tbody>';
		foreach ( array( 'installed', 'active', 'supported_reversible', 'supported_governed', 'read_only_supported', 'adapter_registered_inactive', 'adapter_present_certification_required', 'adapter_present_side_channel_blocked', 'adapter_required', 'excluded_high_risk', 'priority_external_missing' ) as $key ) {
			echo '<tr><th>' . esc_html( str_replace( '_', ' ', $key ) ) . '</th><td>' . esc_html( isset( $counts[ $key ] ) ? (string) $counts[ $key ] : '0' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'How to read coverage', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p><strong>supported_reversible</strong> — ' . esc_html__( 'bounded mutation plus certified restore/undo contract.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>supported_governed</strong> — ' . esc_html__( 'governed support exists but reversibility may be intentionally narrower.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_present_certification_required</strong> — ' . esc_html__( 'implementation exists, but the exact provider runtime is not yet trusted for mutation.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_present_side_channel_blocked</strong> — ' . esc_html__( 'a parallel write/MCP plane prevents normal writer authority until isolation is certified.', 'mad4b-site-control-plane' ) . '</p>';
		echo '<p><strong>adapter_required</strong> — ' . esc_html__( 'no supported adapter contract exists; write remains denied by default.', 'mad4b-site-control-plane' ) . '</p></div>';
	}

	private static function render_plugins( array $items ) {
		echo '<h2>' . esc_html__( 'Installed plugins', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Use this table to distinguish installation state from governance readiness. An active plugin is not automatically a mutation-ready provider.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No plugins discovered.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Active</th><th>Family</th><th>Adapter</th><th>Coverage</th><th>Provider cert</th><th>Runtime blocker</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			$provider_cert = empty( $item['provider_certification_required'] ) ? 'not-required' : ( ! empty( $item['provider_certification_ok'] ) ? 'certified' : 'required' );
			$runtime_blocker = isset( $item['side_channel_blocker'] ) ? (string) $item['side_channel_blocker'] : '';
			echo '<tr><td><strong>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</strong><br><code>' . esc_html( isset( $item['plugin_file'] ) ? $item['plugin_file'] : '' ) . '</code></td>';
			echo '<td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td>' . esc_html( ! empty( $item['active'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $item['family'] ) ? $item['family'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td><strong>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</strong></td><td>' . esc_html( $provider_cert ) . '</td><td><code>' . esc_html( $runtime_blocker ) . '</code></td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_priority( array $items ) {
		echo '<h2>' . esc_html__( 'Priority external coverage', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Priority external families remain first-class coverage targets even when their archives are not stored in this repository.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'All configured priority external plugins are installed.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Plugin</th><th>Adapter</th><th>Coverage</th><th>Risk</th><th>Support request</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$request = isset( $item['support_request']['support_request_id'] ) ? (string) $item['support_request']['support_request_id'] : '';
			echo '<tr><td>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</td><td><code>' . esc_html( isset( $item['adapter_id'] ) ? $item['adapter_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['coverage_state'] ) ? $item['coverage_state'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td><code>' . esc_html( $request ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_requests( array $items ) {
		echo '<h2>' . esc_html__( 'Adapter support requests', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'These are deterministic evidence records only. They do not install plugins, generate PHP, create NHI/grants, approve tickets or enable mutation.', 'mad4b-site-control-plane' ) . '</p>';
		if ( ! $items ) { echo '<p>' . esc_html__( 'No adapter support requests are currently required.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Request</th><th>Plugin</th><th>Reason</th><th>Risk</th><th>Requested contracts</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$contracts = isset( $item['requested_contracts'] ) && is_array( $item['requested_contracts'] ) ? implode( ', ', $item['requested_contracts'] ) : '';
			echo '<tr><td><code>' . esc_html( isset( $item['support_request_id'] ) ? $item['support_request_id'] : '' ) . '</code></td><td>' . esc_html( isset( $item['plugin_name'] ) ? $item['plugin_name'] : '' ) . '</td><td>' . esc_html( isset( $item['reason_code'] ) ? $item['reason_code'] : '' ) . '</td><td>' . esc_html( isset( $item['risk'] ) ? $item['risk'] : '' ) . '</td><td>' . esc_html( $contracts ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
