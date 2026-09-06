<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.3.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: mcp-adapter
 * Author: MAD4B
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-site-control-plane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAD4B_SCP_VERSION', '0.3.0' );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-schema.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-identity-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-agent-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-audit.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-contracts.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-impact-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-approval-tickets.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mutation-manager.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-ability-overrides.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-adapter-base.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-elementor-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-jetengine-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-jetsmartfilters-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-bitflows-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-media-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-seo-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-woocommerce-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-polylang-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-litespeed-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governance-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin.php';

register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'MAD4B_SCP_Plugin', 'boot' ), 20 );
