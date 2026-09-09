<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.4.0-rc.6
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: mcp-adapter
 * Author: MAD4B
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-site-control-plane
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MAD4B_SCP_VERSION', '0.4.0-rc.6' );
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
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-budgets.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-provider-isolation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-peer-governance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-store.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-oauth-autoconfig.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-server.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-oauth-lifecycle.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-consent-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-key-path-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-init-lock.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-loopback-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-browser-canary.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-resource-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-request-context-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-jwt-header-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-outbound-budget-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-subject-gate.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-profile-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-challenge-alignment.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-transport-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mutation-manager.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-reversible-adapter-mutations.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-discovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-ability-overrides.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-autoconfig.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-seeder.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-resource-reader.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-resource-writer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-snapshot-identity.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-exporter.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-adapter-base.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-skills-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-runtime-component-adapters.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-elementor-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-jetengine-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-jetsmartfilters-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-bitflows-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-media-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-seo-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-woocommerce-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-polylang-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-litespeed-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-repository-family-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-planning-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-rest-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-write-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governance-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-ability.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-experience.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-coverage-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-components-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skills-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin.php';

// Staging Skills are zero-touch by default. Explicit operator configuration
// always wins, Production is never auto-enabled, and scripts remain gated.
MAD4B_SCP_Skill_Autoconfig::bootstrap();

// Exact-origin Staging write authority is a separate governed plane. It may
// configure the mutation gate only for staging.egypttourgates.com; Production
// and breakglass are never auto-enabled.
MAD4B_SCP_Staging_Write_Authority::bootstrap();

// active_plugins ordering is not sufficient evidence of runtime ownership: a
// hosting/MU bootstrap can claim the MCP Adapter class first. On the exact
// Staging origin only, reconcile the reviewed Hostinger collision by installing
// an integrity-checked early MU bootstrap for the next request. No plugin is
// disabled and Production is never mutated.
MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();

// Register lazy Abilities/MCP callbacks before plugins_loaded so a third-party
// REST prime cannot consume the one-shot MCP Adapter init action first.
MAD4B_SCP_MCP_Registration_Bridge::boot_early();
MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();

// Provider kill-switch filters must exist before plugins_loaded provider bootstraps.
MAD4B_SCP_MCP_Provider_Isolation::boot_early();
MAD4B_SCP_External_Handshake_Evidence::boot();
MAD4B_SCP_ChatGPT_OAuth_Lifecycle::boot();
MAD4B_SCP_Local_OAuth_Consent_UI::boot();
register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'MAD4B_SCP_Plugin', 'boot' ), 20 );