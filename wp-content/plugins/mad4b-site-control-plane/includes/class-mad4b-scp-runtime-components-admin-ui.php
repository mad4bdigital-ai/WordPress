<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only workspace for Core, plugins, MU plugins, drop-ins and themes. */
final class MAD4B_SCP_Runtime_Components_Admin_UI {
	const PAGE_SLUG = 'mad4b-runtime-components';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 31 );
	}

	public static function register_menu() {
		add_submenu_page(
			MAD4B_SCP_Admin_UI::PAGE_SLUG,
			__( 'Runtime Components', 'mad4b-site-control-plane' ),
			__( 'Runtime Components', 'mad4b-site-control-plane' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_runtime_components_capability_denied', 'Administrator capability is required to inspect runtime components.' );
		if ( ! class_exists( 'MAD4B_SCP_Runtime_Component_Catalog' ) ) return new WP_Error( 'mad4b_runtime_components_unavailable', 'Runtime component inventory is unavailable.' );
		return MAD4B_SCP_Runtime_Component_Catalog::inventory();
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to inspect runtime components.', 'mad4b-site-control-plane' ) );
		$snapshot = self::snapshot();
		$tabs = array(
			'overview' => __( 'Overview', 'mad4b-site-control-plane' ),
			'core' => __( 'WordPress Core', 'mad4b-site-control-plane' ),
			'plugins' => __( 'Plugins', 'mad4b-site-control-plane' ),
			'mu-plugins' => __( 'MU Plugins', 'mad4b-site-control-plane' ),
			'drop-ins' => __( 'Drop-ins', 'mad4b-site-control-plane' ),
			'themes' => __( 'Themes', 'mad4b-site-control-plane' ),
			'astra' => __( 'Astra / Child', 'mad4b-site-control-plane' ),
		);
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';

		MAD4B_SCP_Admin_Experience::styles();
		echo '<div class="wrap mad4b-scp-admin-page"><h1>' . esc_html__( 'MAD4B Runtime Components', 'mad4b-site-control-plane' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Read-only runtime inventory across WordPress Core, regular plugins, must-use plugins, bootstrap drop-ins and themes. Astra and Astra Child receive specialized inspection while Core, MU plugins and drop-ins remain outside normal write authority.', 'mad4b-site-control-plane' ) . '</p>';
		if ( is_wp_error( $snapshot ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $snapshot->get_error_message() ) . '</p></div></div>'; return; }

		MAD4B_SCP_Admin_Experience::stages( self::stages( $snapshot ) );
		MAD4B_SCP_Admin_Experience::tabs( self::PAGE_SLUG, $tabs, $tab );

		if ( 'overview' === $tab ) self::render_overview( $snapshot );
		if ( 'core' === $tab ) self::render_core( isset( $snapshot['wordpress_core'] ) ? $snapshot['wordpress_core'] : array() );
		if ( 'plugins' === $tab ) self::render_plugins( isset( $snapshot['regular_plugins'] ) ? $snapshot['regular_plugins'] : array() );
		if ( 'mu-plugins' === $tab ) self::render_mu_plugins( isset( $snapshot['mu_plugins'] ) ? $snapshot['mu_plugins'] : array() );
		if ( 'drop-ins' === $tab ) self::render_drop_ins( isset( $snapshot['drop_ins'] ) ? $snapshot['drop_ins'] : array() );
		if ( 'themes' === $tab ) self::render_themes( isset( $snapshot['themes'] ) ? $snapshot['themes'] : array() );
		if ( 'astra' === $tab ) self::render_astra( isset( $snapshot['astra'] ) ? $snapshot['astra'] : array(), isset( $snapshot['astra_children'] ) ? $snapshot['astra_children'] : array() );
		echo '</div>';
	}

	private static function stages( array $snapshot ) {
		$core = isset( $snapshot['wordpress_core'] ) && is_array( $snapshot['wordpress_core'] ) ? $snapshot['wordpress_core'] : array();
		$themes = isset( $snapshot['themes'] ) && is_array( $snapshot['themes'] ) ? $snapshot['themes'] : array();
		return array(
			array( 'label' => __( 'Discover runtime', 'mad4b-site-control-plane' ), 'state' => 'complete', 'detail' => __( 'Inventory all WordPress component classes without mutation.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'overview' ) ),
			array( 'label' => __( 'Classify lifecycle', 'mad4b-site-control-plane' ), 'state' => 'complete', 'detail' => __( 'Separate regular plugins, MU plugins, drop-ins, themes and Core.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'mu-plugins' ) ),
			array( 'label' => __( 'Inspect theme topology', 'mad4b-site-control-plane' ), 'state' => ! empty( $themes['count'] ) ? 'complete' : 'pending', 'detail' => __( 'Resolve parent/child themes and specialized Astra state.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'themes' ) ),
			array( 'label' => __( 'Keep bootstrap authority closed', 'mad4b-site-control-plane' ), 'state' => empty( $core['mutation_exposed'] ) ? 'complete' : 'blocked', 'detail' => __( 'Core, MU plugins and drop-ins expose no normal mutation abilities.', 'mad4b-site-control-plane' ), 'url' => MAD4B_SCP_Admin_Experience::tab_url( self::PAGE_SLUG, 'core' ) ),
		);
	}

	private static function render_overview( array $snapshot ) {
		$counts = isset( $snapshot['counts'] ) && is_array( $snapshot['counts'] ) ? $snapshot['counts'] : array();
		$core = isset( $snapshot['wordpress_core'] ) && is_array( $snapshot['wordpress_core'] ) ? $snapshot['wordpress_core'] : array();
		$astra = isset( $snapshot['astra'] ) && is_array( $snapshot['astra'] ) ? $snapshot['astra'] : array();
		MAD4B_SCP_Admin_Experience::cards( array(
			array( 'label' => 'WordPress Core', 'value' => isset( $core['version'] ) ? (string) $core['version'] : '', 'state' => 'complete', 'help' => 'Runtime version and policy state; no Core mutation authority.' ),
			array( 'label' => 'Regular plugins', 'value' => isset( $counts['regular_plugins'] ) ? (string) $counts['regular_plugins'] : '0', 'state' => 'complete', 'help' => 'Governed separately by Adapter Coverage.' ),
			array( 'label' => 'MU plugins', 'value' => isset( $counts['mu_plugins'] ) ? (string) $counts['mu_plugins'] : '0', 'state' => 'complete', 'help' => 'Always-loaded bootstrap extensions; no activation toggle.' ),
			array( 'label' => 'Drop-ins', 'value' => isset( $counts['drop_ins'] ) ? (string) $counts['drop_ins'] : '0', 'state' => empty( $counts['drop_ins'] ) ? 'pending' : 'attention', 'help' => 'Bootstrap replacements such as object-cache.php or db.php.' ),
			array( 'label' => 'Themes', 'value' => isset( $counts['themes'] ) ? (string) $counts['themes'] : '0', 'state' => ! empty( $counts['themes'] ) ? 'complete' : 'pending', 'help' => 'Parent/child topology is resolved at runtime.' ),
			array( 'label' => 'Astra', 'value' => ! empty( $astra['available'] ) ? 'installed' : 'not installed', 'state' => ! empty( $astra['available'] ) ? 'complete' : 'pending', 'help' => 'Runtime-first specialized Astra adapter.' ),
		) );
		MAD4B_SCP_Admin_Experience::next_step( 'Runtime coverage boundary', 'Use Adapter Coverage for plugin-specific write readiness. This page intentionally keeps WordPress Core, MU plugins, drop-ins and theme inspection read-only until a separate certified mutation contract is designed.', 'complete' );
		echo '<div class="mad4b-scp-panel"><h2>' . esc_html__( 'Component classes', 'mad4b-site-control-plane' ) . '</h2><p><code>wordpress_core</code> · <code>regular_plugin</code> · <code>mu_plugin</code> · <code>drop_in</code> · <code>theme</code> · <code>child_theme</code></p></div>';
	}

	private static function render_core( array $core ) {
		echo '<h2>' . esc_html__( 'WordPress Core', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Local runtime facts and cached update evidence only. No remote checksum request, Core update or filesystem mutation is performed.', 'mad4b-site-control-plane' ) . '</p>';
		self::key_value_table( $core );
	}

	private static function render_plugins( array $plugins ) {
		$counts = isset( $plugins['counts'] ) && is_array( $plugins['counts'] ) ? $plugins['counts'] : array();
		echo '<h2>' . esc_html__( 'Regular plugins', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p>' . esc_html__( 'Regular plugin lifecycle and adapter certification remain in the dedicated Adapter Coverage workspace.', 'mad4b-site-control-plane' ) . '</p>';
		self::key_value_table( $counts );
		$url = admin_url( 'admin.php?page=' . MAD4B_SCP_Adapter_Coverage_Admin_UI::PAGE_SLUG . '&tab=installed' );
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open Adapter Coverage', 'mad4b-site-control-plane' ) . '</a></p>';
	}

	private static function render_mu_plugins( array $inventory ) {
		echo '<h2>' . esc_html__( 'Must-Use plugins', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'MU plugins are always-loaded bootstrap code. They do not support the normal activate/deactivate lifecycle, and normal MAD4B write authority remains denied.', 'mad4b-site-control-plane' ) . '</p>';
		$items = isset( $inventory['items'] ) && is_array( $inventory['items'] ) ? $inventory['items'] : array();
		if ( ! $items ) echo '<p>' . esc_html__( 'No runtime MU plugins discovered.', 'mad4b-site-control-plane' ) . '</p>';
		else {
			echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>File</th><th>Lifecycle</th><th>Mutation scope</th></tr></thead><tbody>';
			foreach ( $items as $item ) echo '<tr><td><strong>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</strong></td><td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td><code>' . esc_html( isset( $item['plugin_file'] ) ? $item['plugin_file'] : '' ) . '</code></td><td>' . esc_html( isset( $item['activation_model'] ) ? $item['activation_model'] : '' ) . '</td><td><code>' . esc_html( isset( $item['mutation_scope'] ) ? $item['mutation_scope'] : '' ) . '</code></td></tr>';
			echo '</tbody></table></div>';
		}
		if ( ! empty( $inventory['repository_artifacts'] ) ) echo '<p><strong>' . esc_html__( 'Repository evidence:', 'mad4b-site-control-plane' ) . '</strong> <code>' . esc_html( implode( ', ', $inventory['repository_artifacts'] ) ) . '</code></p>';
	}

	private static function render_drop_ins( array $inventory ) {
		echo '<h2>' . esc_html__( 'WordPress Drop-ins', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Drop-ins participate in WordPress bootstrap and are intentionally separated from both regular and MU plugins.', 'mad4b-site-control-plane' ) . '</p>';
		$items = isset( $inventory['items'] ) && is_array( $inventory['items'] ) ? $inventory['items'] : array();
		if ( ! $items ) { echo '<p>' . esc_html__( 'No recognized drop-ins discovered.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>File</th><th>Name</th><th>Version</th><th>Runtime signal</th><th>Mutation scope</th></tr></thead><tbody>';
		foreach ( $items as $item ) echo '<tr><td><code>' . esc_html( isset( $item['file'] ) ? $item['file'] : '' ) . '</code></td><td>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</td><td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td>' . esc_html( null === $item['runtime_signal'] ? 'n/a' : ( $item['runtime_signal'] ? 'yes' : 'no' ) ) . '</td><td><code>' . esc_html( isset( $item['mutation_scope'] ) ? $item['mutation_scope'] : '' ) . '</code></td></tr>';
		echo '</tbody></table></div>';
	}

	private static function render_themes( array $inventory ) {
		echo '<h2>' . esc_html__( 'Themes', 'mad4b-site-control-plane' ) . '</h2>';
		echo '<p class="mad4b-scp-section-lead">' . esc_html__( 'Runtime-first parent/child topology. Repository-tracked WordPress themes and externally installed themes are both visible.', 'mad4b-site-control-plane' ) . '</p>';
		$items = isset( $inventory['items'] ) && is_array( $inventory['items'] ) ? $inventory['items'] : array();
		if ( ! $items ) { echo '<p>' . esc_html__( 'No themes discovered.', 'mad4b-site-control-plane' ) . '</p>'; return; }
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Theme</th><th>Version</th><th>Stylesheet</th><th>Parent template</th><th>Type</th><th>Active</th><th>Repository</th><th>Errors</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$active = ! empty( $item['active_stylesheet'] ) ? 'stylesheet' : ( ! empty( $item['active_template'] ) ? 'parent' : 'no' );
			echo '<tr><td><strong>' . esc_html( isset( $item['name'] ) ? $item['name'] : '' ) . '</strong></td><td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td><code>' . esc_html( isset( $item['stylesheet'] ) ? $item['stylesheet'] : '' ) . '</code></td><td><code>' . esc_html( isset( $item['template'] ) ? $item['template'] : '' ) . '</code></td><td>' . esc_html( isset( $item['component_type'] ) ? $item['component_type'] : '' ) . '</td><td>' . esc_html( $active ) . '</td><td>' . esc_html( ! empty( $item['repository_tracked'] ) ? 'tracked' : 'runtime-only' ) . '</td><td><code>' . esc_html( ! empty( $item['error_codes'] ) ? implode( ',', $item['error_codes'] ) : '' ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_astra( array $astra, array $children ) {
		echo '<h2>' . esc_html__( 'Astra runtime', 'mad4b-site-control-plane' ) . '</h2>';
		MAD4B_SCP_Admin_Experience::cards( array(
			array( 'label' => 'Astra installed', 'value' => ! empty( $astra['available'] ) ? 'yes' : 'no', 'state' => ! empty( $astra['available'] ) ? 'complete' : 'pending', 'help' => 'Detected from the live WordPress theme registry.' ),
			array( 'label' => 'Astra version', 'value' => isset( $astra['version'] ) ? (string) $astra['version'] : '', 'state' => ! empty( $astra['available'] ) ? 'complete' : 'pending', 'help' => 'Runtime theme header version.' ),
			array( 'label' => 'Astra Child themes', 'value' => isset( $children['count'] ) ? (string) $children['count'] : '0', 'state' => ! empty( $children['count'] ) ? 'complete' : 'pending', 'help' => 'Any theme declaring Template: astra.' ),
			array( 'label' => 'Astra Addon runtime', 'value' => ! empty( $astra['astra_addon_runtime_detected'] ) ? 'detected' : 'not detected', 'state' => ! empty( $astra['astra_addon_runtime_detected'] ) ? 'complete' : 'pending', 'help' => 'Separate plugin-layer runtime signal.' ),
		) );
		if ( ! empty( $astra['theme_mods'] ) ) {
			echo '<div class="mad4b-scp-panel"><h3>' . esc_html__( 'Astra theme mods', 'mad4b-site-control-plane' ) . '</h3><p>' . esc_html__( 'Values are intentionally not exposed. Only keys and a fingerprint are returned.', 'mad4b-site-control-plane' ) . '</p>';
			self::key_value_table( $astra['theme_mods'] );
			echo '</div>';
		}
		$items = isset( $children['items'] ) && is_array( $children['items'] ) ? $children['items'] : array();
		if ( ! $items ) return;
		echo '<h3>' . esc_html__( 'Astra Child override inventory', 'mad4b-site-control-plane' ) . '</h3><div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th>Child</th><th>Version</th><th>Active</th><th>Files</th><th>Overrides</th><th>Scan bounded</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$fs = isset( $item['filesystem'] ) && is_array( $item['filesystem'] ) ? $item['filesystem'] : array();
			echo '<tr><td><code>' . esc_html( isset( $item['stylesheet'] ) ? $item['stylesheet'] : '' ) . '</code></td><td>' . esc_html( isset( $item['version'] ) ? $item['version'] : '' ) . '</td><td>' . esc_html( ! empty( $item['active_stylesheet'] ) ? 'yes' : 'no' ) . '</td><td>' . esc_html( isset( $fs['returned_file_count'] ) ? (string) $fs['returned_file_count'] : '0' ) . '</td><td><code>' . esc_html( ! empty( $fs['override_files'] ) ? implode( ', ', $fs['override_files'] ) : '' ) . '</code></td><td>' . esc_html( ! empty( $fs['truncated'] ) ? 'truncated' : 'complete' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function key_value_table( array $values ) {
		echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><tbody>';
		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) ) $display = $value ? 'true' : 'false';
			elseif ( null === $value ) $display = 'null';
			elseif ( is_scalar( $value ) ) $display = (string) $value;
			else $display = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			echo '<tr><th style="width:240px"><code>' . esc_html( (string) $key ) . '</code></th><td><code>' . esc_html( (string) $display ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
