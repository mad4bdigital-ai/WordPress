<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only WordPress-admin file manager handoff.
 *
 * Third-party file managers are human-facing tools, not remote mutation
 * authority or a substitute for the governed package-update pipeline.
 * Site/business schemas remain owned by each WordPress installation.
 */
final class MAD4B_SCP_Admin_File_Workspace {
	const CONTRACT = 'mad4b.wp-admin-file-workspace-handoff.v1';

	public static function status() {
		$admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		$may_update = $admin && current_user_can( 'update_plugins' );
		$detected = false;
		$active = false;
		$inspection = $admin ? 'plugin_inventory_unavailable' : 'administrator_required';

		// Do not load plugin.php, initialize a foreign plugin or perform network
		// work in an MCP/protocol request. An admin can inspect live installed
		// plugin metadata when WordPress has already loaded that facility.
		if ( $admin && function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			$inspection = is_array( $plugins ) ? 'inspected' : 'inventory_invalid';
			if ( is_array( $plugins ) ) {
				foreach ( $plugins as $file => $metadata ) {
					if ( ! is_string( $file ) || ! is_array( $metadata ) || ! isset( $metadata['Name'] ) || ! is_string( $metadata['Name'] ) ) continue;
					// Advisory discovery only; never trust a displayed name as
					// a capability or use it to locate an executable endpoint.
					if ( false === stripos( $metadata['Name'], 'file manager' ) ) continue;
					$detected = true;
					$site_active = function_exists( 'is_plugin_active' ) && is_plugin_active( $file );
					$network_active = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file );
					$active = $active || $site_active || $network_active;
				}
			}
		}

		return array(
			'contract' => self::CONTRACT,
			'inspection' => $inspection,
			'manager_detected' => $detected,
			'manager_active' => $active,
			'wp_admin_capability' => $admin,
			'wp_admin_update_capability' => $may_update,
			'operation' => 'manual_admin_review_only',
			'file_manager_remote_api_enabled' => false,
			'el_finder_remote_transport_enabled' => false,
			'arbitrary_path_or_url_allowed' => false,
			'direct_core_plugin_file_edit_allowed' => false,
			'site_business_definitions_owned_by_site' => true,
			'governed_update_plan_ability' => 'mad4b/control-plane-upload-plan',
			'governed_update_apply_ability' => 'mad4b/control-plane-upload-apply',
			'requires_exact_plan_approval_provenance_and_rollback' => true,
			'staging_candidate_oauth_step_up_required' => true,
			'production_write_authorized' => false,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}
}
