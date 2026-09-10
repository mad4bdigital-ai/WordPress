<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.4.0-rc.17
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: MAD4B
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MAD4B_SCP_VERSION', '0.4.0-rc.17' );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAD4B_SCP_URL', plugin_dir_url( __FILE__ ) );

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin.php';

// Registration wiring must exist before any legal post-init Abilities registry
// materialization. Keep this phase registration-only: it may bind callbacks, but
// it must not reconcile Skills, certify runtimes, create NHI state, or access
// the Abilities registry itself.
MAD4B_SCP_Plugin::wire_registration_hooks_pre_init();

// Full Control Plane boot still starts only after WordPress init begins. This
// preserves the lifecycle hardening that prevents pre-init Abilities access.
add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );

// The Skills workspace is itself an acceptance surface. Refresh certification
// only there, after init/bootstrap reconciliation has completed. Do not restore
// the older every-admin-request observation path; ordinary wp-admin requests
// must remain free of redundant provider/snapshot certification work.
add_action( 'admin_init', static function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
	if ( 'mad4b-control-plane-skills' !== $page ) return;
	if ( ! class_exists( 'MAD4B_SCP_Skill_Runtime_Certification' ) ) return;
	MAD4B_SCP_Skill_Runtime_Certification::observe();
}, 110 );

register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );
