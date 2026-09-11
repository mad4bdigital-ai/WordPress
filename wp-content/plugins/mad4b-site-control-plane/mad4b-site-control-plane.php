<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.4.0-rc.23
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: mcp-adapter
 * Author: MAD4B
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-site-control-plane
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MAD4B_SCP_VERSION', '0.4.0-rc.23' );
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
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-request-scope.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-adapter-metadata-bridge.php';
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
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-observer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wpml-response-contract.php';
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
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-approval-handoff-adapter.php';
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
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-rescue-v1.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-planning-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-rest-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-write-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-truth.php';
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

// Passive Live Acceptance observers are wired before init but never bootstrap
// authorization, servers, providers or the Ability registry. Their only
// persistent state is bounded/sanitized observability on the exact Staging host.
MAD4B_SCP_Live_Acceptance_Observer::boot_early();
MAD4B_SCP_Live_Acceptance_Finalizer::boot_early();
MAD4B_SCP_WPML_Response_Contract::boot_early();

// Fresh authority/certification truth must be wired before the one-shot Ability
// registry can materialize. Read callbacks stay observational; reconciliation
// remains an init-time lifecycle concern after the full Control Plane boot.
MAD4B_SCP_Live_Truth::boot_early();

// Wire the complete Ability catalog before anything can materialize the
// WordPress Abilities registry. Only registration-time callbacks/filters are
// armed here; reconciliation, certification observation, MCP exposure and
// mutation authorization remain owned by the full init-time boot.
MAD4B_SCP_Connection_Ability::boot();
MAD4B_SCP_Governed_Ability_Overrides::boot();
add_filter( 'wp_register_ability_args', array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' ), 70, 2 );
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'register_status_ability' ), 35 );
MAD4B_SCP_Staging_Write_Planning_Guard::boot();
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_REST_Compatibility', 'register_ability' ), 36 );
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'register_ability' ), 37 );
MAD4B_SCP_Governance_Abilities::boot();
MAD4B_SCP_Skill_Abilities::boot();
MAD4B_SCP_Skills_Adapter::boot();

// WordPress core requests dependency metadata on the Plugins screen even when
// MCP Adapter is already installed. The certified Adapter is distributed from
// the official WordPress GitHub repository rather than WordPress.org, so exact
// governed Staging supplies that one plugin_information result locally.
MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();

// Provider discovery used to run at plugins_loaded priority 30 from the Seeder
// file. The full Control Plane now boots at init so that WordPress 6.9+ never
// initializes the Abilities API before init. Disarm that legacy provider hook;
// MAD4B_SCP_Plugin::boot() reconciles providers immediately after the Seeder has
// reached current-request ready state.
remove_action( 'plugins_loaded', array( 'MAD4B_SCP_Skill_Provider_Discovery', 'bootstrap' ), 30 );

// Staging Skills are zero-touch by default. Explicit operator configuration
// always wins, Production is never auto-enabled, and scripts remain gated.
MAD4B_SCP_Skill_Autoconfig::bootstrap();

// Exact-origin Staging write authority is a separate governed plane. It may
// configure the mutation gate only for staging.egypttourgates.com; Production
// and breakglass are never auto-enabled.
MAD4B_SCP_Staging_Write_Authority::bootstrap();

// Refresh only a recognized MAD4B-managed stale MU bootstrap. The already-
// executing request cannot be replaced in-process; a successful refresh is for
// the next request and is append-only audited. Unrecognized files fail closed.
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();

// active_plugins ordering is not sufficient evidence of runtime ownership: a
// hosting/MU bootstrap can claim the MCP Adapter class first. On exact Staging,
// the managed MU loader pins the official runtime only for MAD4B-owned MCP/admin
// requests. Unrelated Core/WPML/provider requests retain the host baseline.
MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();

// On exact Staging, keep the official MCP Adapter completely out of unrelated
// WordPress REST requests. Only MAD4B MCP transports and MAD4B Control Plane
// diagnostics may enter the MCP/peer registration lifecycle.
MAD4B_SCP_MCP_Request_Scope::bootstrap();

// Register lazy Abilities/MCP callbacks before init. If another MU component
// already primed REST, the bridge performs a bounded init-time recovery without
// replaying the global rest_api_init action.
MAD4B_SCP_MCP_Registration_Bridge::boot_early();
MAD4B_SCP_MCP_Registration_Rescue::boot();
MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();

// Provider kill-switch filters must exist before provider bootstraps.
MAD4B_SCP_MCP_Provider_Isolation::boot_early();
MAD4B_SCP_External_Handshake_Evidence::boot();
MAD4B_SCP_ChatGPT_OAuth_Lifecycle::boot();
MAD4B_SCP_Local_OAuth_Consent_UI::boot();
register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );

// WordPress 6.9+ explicitly forbids initializing the Abilities registry before
// init. Boot the full Control Plane at the earliest init priority instead of
// plugins_loaded: hooks required before init remain armed above, while adapter,
// Skill, certification and admin bootstraps can no longer pull the Ability API
// (or provider code reached through it) into the pre-init phase.
add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );

// Refresh persisted Skill certification only when the administrator opens the
// Skills workspace. By admin_init, the init-time Seeder/provider reconciliation
// has completed. Keeping this page-scoped avoids restoring the old expensive
// every-admin-request observer while making the zero-touch acceptance surface
// report current evidence rather than a stale stored certification.
add_action( 'admin_init', static function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page-scoped read/reconcile hook.
	if ( 'mad4b-control-plane-skills' !== $page ) return;
	MAD4B_SCP_Skill_Runtime_Certification::observe();
}, 110 );
