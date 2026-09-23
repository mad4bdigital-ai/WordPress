<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.4.0-rc.50
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: MAD4B
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-site-control-plane
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MAD4B_SCP_VERSION', '0.4.0-rc.50' );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dependency-manager.php';
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
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-subject-user-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-observer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-query-monitor-evidence-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-query-performance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-unchanged-attestation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wpml-response-contract.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-provider-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-planner.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-verdict-reducer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-runner.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-core.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-request-context-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-jwt-header-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-outbound-budget-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-subject-gate.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-profile-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-challenge-alignment.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-transport-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-google-drive-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-preflight.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-intelligence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-fence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mutation-manager.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-reversible-adapter-mutations.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-discovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-functional-gap-runtime-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-code-snippets-runtime-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-lifecycle.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-workflow-providers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operating-model.php';
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
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-context-adapter.php';
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
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-form-provider-adapters.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-wp-import-export-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-repository-family-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-rescue-v1.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-candidate-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-planning-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-rest-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-write-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-truth.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governance-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-ability.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-experience.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-coverage-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-components-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skills-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-upgrade-continuity.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-reconnect-hardening.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin.php';

MAD4B_SCP_Site_Profile::bootstrap();
$mad4b_upgrade_continuity = MAD4B_SCP_Upgrade_Continuity::pre_boot();
if ( ! empty( $mad4b_upgrade_continuity['recovered'] ) ) {
	MAD4B_SCP_Site_Profile::reset_cache();
	MAD4B_SCP_Site_Profile::bootstrap();
}
unset( $mad4b_upgrade_continuity );
MAD4B_SCP_Site_Profile::boot();
MAD4B_SCP_Upgrade_Continuity::boot();
MAD4B_SCP_Reconnect_Hardening::boot();
MAD4B_SCP_Dependency_Manager::boot();
MAD4B_SCP_OAuth_Subject_User_Bridge::boot();

MAD4B_SCP_Live_Acceptance_Observer::boot_early();
MAD4B_SCP_Query_Monitor_Evidence_Bridge::boot_early();
MAD4B_SCP_Admin_Query_Performance::boot();
MAD4B_SCP_Live_Acceptance_Finalizer::boot_early();
MAD4B_SCP_Production_Unchanged_Attestation::boot_early();
MAD4B_SCP_WPML_Response_Contract::boot_early();
MAD4B_SCP_Live_Truth::boot_early();
MAD4B_SCP_Staging_Certification::boot();
MAD4B_SCP_Acceptance_Core::boot_early();
MAD4B_SCP_Connection_Ability::boot();
MAD4B_SCP_Context_Authority::boot();
MAD4B_SCP_Plugin_Lifecycle::boot();
MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::boot();
MAD4B_SCP_Code_Snippets_Runtime_Diagnostic::boot();
MAD4B_SCP_Workflow_Providers::boot();
MAD4B_SCP_Operating_Model::boot();
MAD4B_SCP_Governed_Ability_Overrides::boot();
$mad4b_write_augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
if ( false === has_filter( 'wp_register_ability_args', $mad4b_write_augment ) ) add_filter( 'wp_register_ability_args', $mad4b_write_augment, 70, 2 );
unset( $mad4b_write_augment );
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'register_status_ability' ), 35 );
MAD4B_SCP_Staging_Write_Candidate_Binding::boot();
MAD4B_SCP_Staging_Write_Planning_Guard::boot();
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_REST_Compatibility', 'register_ability' ), 36 );
add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'register_ability' ), 37 );
MAD4B_SCP_Governance_Abilities::boot();
MAD4B_SCP_Skill_Abilities::boot();
MAD4B_SCP_Skills_Adapter::boot();
MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();
remove_action( 'plugins_loaded', array( 'MAD4B_SCP_Skill_Provider_Discovery', 'bootstrap' ), 30 );
MAD4B_SCP_Skill_Autoconfig::bootstrap();
MAD4B_SCP_Staging_Write_Authority::bootstrap();
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();
MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();
MAD4B_SCP_MCP_Request_Scope::bootstrap();
MAD4B_SCP_MCP_Registration_Bridge::boot_early();
MAD4B_SCP_MCP_Registration_Rescue::boot();
MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();
MAD4B_SCP_MCP_Provider_Isolation::boot_early();
MAD4B_SCP_External_Handshake_Evidence::boot();
MAD4B_SCP_ChatGPT_OAuth_Lifecycle::boot();
MAD4B_SCP_Local_OAuth_Consent_UI::boot();
MAD4B_SCP_Context_Admin_UI::boot();
register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );
add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );
add_action( 'admin_init', static function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'mad4b-control-plane-skills' !== $page ) return;
	MAD4B_SCP_Skill_Runtime_Certification::observe();
}, 110 );
