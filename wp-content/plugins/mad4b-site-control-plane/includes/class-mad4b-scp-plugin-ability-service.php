<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed plugin activation/deactivation executor.
 * Called exclusively through the existing Abilities facade to preserve
 * public callback names, scopes, preflight, and readback semantics.
 * The read-only MAD4B_SCP_Plugin_Lifecycle service remains the planner.
 */
final class MAD4B_SCP_Plugin_Ability_Service {
	private static function plugin_site_active( $plugin ) {
		$active_plugins = get_option( 'active_plugins', array() );
		return is_array( $active_plugins ) && in_array( (string) $plugin, $active_plugins, true );
	}

	public static function plugin_activate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_capability_denied', 'Current user cannot activate plugins.' );
		$scope = isset( $input['activation_scope'] ) ? sanitize_key( (string) $input['activation_scope'] ) : 'site';
		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) return new WP_Error( 'mad4b_plugin_activation_scope_invalid', 'activation_scope must be site or network.' );
		if ( 'network' === $scope && ! is_multisite() ) return new WP_Error( 'mad4b_plugin_network_scope_unavailable', 'Network activation requires WordPress multisite.' );
		$network_active = is_multisite() ? is_plugin_active_for_network( $plugin ) : false;
		if ( 'site' === $scope && $network_active ) return new WP_Error( 'mad4b_plugin_network_activation_controls_site', 'Site-scoped lifecycle changes are not meaningful while the plugin is network-active; change network scope first.' );
		$current = 'network' === $scope ? $network_active : self::plugin_site_active( $plugin );
		if ( $current !== (bool) $input['expected_active'] ) return new WP_Error( 'mad4b_stale_plugin_state', 'Plugin active state changed in the requested activation scope since it was reviewed.', array( 'activation_scope' => $scope, 'current_active' => $current ) );
		if ( $current ) return new WP_Error( 'mad4b_plugin_already_active', 'Plugin is already active in the requested activation scope.' );
		if ( 'network' === $scope && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_network_plugin_capability_denied', 'Network-wide plugin activation requires manage_network_plugins.' );
		if ( ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, 'activate' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_policy_denied', 'Plugin lifecycle mutation is disabled or the plugin is not explicitly allowlisted.' );
		$preflight = class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) ? MAD4B_SCP_Plugin_Lifecycle::mutation_preflight( $plugin, true, $input ) : new WP_Error( 'mad4b_plugin_lifecycle_preflight_unavailable', 'Plugin lifecycle preflight is unavailable.' );
		if ( is_wp_error( $preflight ) ) return $preflight;
		$result = activate_plugin( $plugin, '', 'network' === $scope ); if ( is_wp_error( $result ) ) return $result;
		$readback = MAD4B_SCP_Plugin_Lifecycle::verify_state( $plugin, true, $scope );
		if ( is_wp_error( $readback ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/plugin-activate', array( 'plugin' => $plugin, 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'readback_verified' => false ), 'failure' );
			return $readback;
		}
		MAD4B_SCP_Audit::record( 'mad4b/plugin-activate', array( 'plugin' => $plugin, 'activation_scope' => $scope, 'reason' => sanitize_text_field( $input['reason'] ), 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true ) );
		return array( 'plugin' => $plugin, 'active' => true, 'activation_scope' => $scope, 'network_active' => (bool) $readback['network_active'], 'site_active' => (bool) $readback['site_active'], 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true );
	}

	public static function plugin_deactivate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_capability_denied', 'Current user cannot deactivate plugins.' );
		$scope = isset( $input['activation_scope'] ) ? sanitize_key( (string) $input['activation_scope'] ) : 'site';
		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) return new WP_Error( 'mad4b_plugin_activation_scope_invalid', 'activation_scope must be site or network.' );
		if ( 'network' === $scope && ! is_multisite() ) return new WP_Error( 'mad4b_plugin_network_scope_unavailable', 'Network deactivation requires WordPress multisite.' );
		$network_active = is_multisite() ? is_plugin_active_for_network( $plugin ) : false;
		if ( 'site' === $scope && $network_active ) return new WP_Error( 'mad4b_plugin_network_activation_controls_site', 'Site-scoped lifecycle changes are not meaningful while the plugin is network-active; change network scope first.' );
		$current = 'network' === $scope ? $network_active : self::plugin_site_active( $plugin );
		if ( $current !== (bool) $input['expected_active'] ) return new WP_Error( 'mad4b_stale_plugin_state', 'Plugin active state changed in the requested activation scope since it was reviewed.', array( 'activation_scope' => $scope, 'current_active' => $current ) );
		if ( ! $current ) return new WP_Error( 'mad4b_plugin_already_inactive', 'Plugin is already inactive in the requested activation scope.' );
		$self = plugin_basename( MAD4B_SCP_FILE );
		$name = isset( $plugins[ $plugin ]['Name'] ) ? strtolower( $plugins[ $plugin ]['Name'] ) : '';
		$text_domain = isset( $plugins[ $plugin ]['TextDomain'] ) ? strtolower( $plugins[ $plugin ]['TextDomain'] ) : '';
		$is_mcp_adapter = 0 === strpos( strtolower( $plugin ), 'mcp-adapter/' ) || 'mcp-adapter' === $text_domain || false !== strpos( $name, 'mcp adapter' );
		if ( $plugin === $self || $is_mcp_adapter || MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin ) ) return new WP_Error( 'mad4b_control_plane_dependency_protected', 'The control plane, its MCP Adapter dependency, and protected plugins cannot be deactivated through the normal admin surface.' );
		if ( ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, 'deactivate' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_policy_denied', 'Plugin lifecycle mutation is disabled or the plugin is not explicitly allowlisted.' );
		$preflight = class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) ? MAD4B_SCP_Plugin_Lifecycle::mutation_preflight( $plugin, false, $input ) : new WP_Error( 'mad4b_plugin_lifecycle_preflight_unavailable', 'Plugin lifecycle preflight is unavailable.' );
		if ( is_wp_error( $preflight ) ) return $preflight;
		$network = 'network' === $scope;
		if ( $network && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_network_plugin_capability_denied', 'Network-wide plugin deactivation requires manage_network_plugins.' );
		deactivate_plugins( $plugin, false, $network );
		$readback = MAD4B_SCP_Plugin_Lifecycle::verify_state( $plugin, false, $scope );
		if ( is_wp_error( $readback ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/plugin-deactivate', array( 'plugin' => $plugin, 'network_wide' => $network, 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'readback_verified' => false ), 'failure' );
			return $readback;
		}
		MAD4B_SCP_Audit::record( 'mad4b/plugin-deactivate', array( 'plugin' => $plugin, 'activation_scope' => $scope, 'network_wide' => $network, 'reason' => sanitize_text_field( $input['reason'] ), 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true ) );
		return array( 'plugin' => $plugin, 'active' => false, 'activation_scope' => $scope, 'site_active' => (bool) $readback['site_active'], 'network_active' => (bool) $readback['network_active'], 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true );
	}

}
