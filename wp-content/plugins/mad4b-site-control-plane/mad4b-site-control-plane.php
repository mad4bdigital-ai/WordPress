<?php
/**
 * Plugin Name: MAD4B Site Control Plane
 * Plugin URI: https://github.com/mad4bdigital-ai/WordPress
 * Description: Governed WordPress Abilities and MCP control surfaces for site, content, plugins, filesystem, database, diagnostics, adapters, and breakglass recovery.
 * Version: 0.4.0-rc.96
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: MAD4B
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-site-control-plane
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MAD4B_SCP_VERSION', '0.4.0-rc.96' );
define( 'MAD4B_SCP_FILE', __FILE__ );
define( 'MAD4B_SCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAD4B_SCP_BOOT_RUNTIME_FILE_SHA256', is_readable( __FILE__ ) ? hash_file( 'sha256', __FILE__ ) : '' );
$mad4b_scp_boot_provenance_path = MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json';
define( 'MAD4B_SCP_BOOT_PROVENANCE_SHA256', is_readable( $mad4b_scp_boot_provenance_path ) ? hash_file( 'sha256', $mad4b_scp_boot_provenance_path ) : '' );
unset( $mad4b_scp_boot_provenance_path );

// Keep this tiny lifecycle hook available even on the foreign REST kernel.
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-catalog-lifecycle.php';
register_deactivation_hook( __FILE__, array( 'MAD4B_SCP_Catalog_Lifecycle', 'deactivate' ) );

/*
 * Early zero-touch kernel for unrelated REST/admin-AJAX requests.
 *
 * The full Control Plane is intentionally large and must not be parsed merely
 * because WordPress is serving WP Core, WPML, WooCommerce, Elementor or another
 * provider's REST/AJAX endpoint. Keep only Site Profile + request-scope so the
 * official MCP Adapter can still be disarmed on exact governed sites. MAD4B's
 * own REST/MCP surfaces always continue into the full bootstrap.
 */
$mad4b_scp_early_zero_touch_reason = '';
if ( ! ( defined( 'MAD4B_SCP_FORCE_FULL_BOOT' ) && true === constant( 'MAD4B_SCP_FORCE_FULL_BOOT' ) ) ) {
	$mad4b_scp_early_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$mad4b_scp_early_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$mad4b_scp_early_rest_path = '';

	if ( '' !== trim( $mad4b_scp_early_route ) ) {
		$mad4b_scp_early_rest_path = '/' . ltrim( rtrim( $mad4b_scp_early_route, '/' ), '/' );
	} elseif ( '' !== $mad4b_scp_early_uri ) {
		$mad4b_scp_early_path = wp_parse_url( $mad4b_scp_early_uri, PHP_URL_PATH );
		if ( is_string( $mad4b_scp_early_path ) && '' !== $mad4b_scp_early_path ) {
			$mad4b_scp_early_path = '/' . ltrim( rawurldecode( $mad4b_scp_early_path ), '/' );
			$mad4b_scp_early_prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
			$mad4b_scp_early_needle = '/' . $mad4b_scp_early_prefix . '/';
			$mad4b_scp_early_offset = strpos( $mad4b_scp_early_path, $mad4b_scp_early_needle );
			if ( false !== $mad4b_scp_early_offset ) {
				$mad4b_scp_early_rest_path = '/' . ltrim( substr( $mad4b_scp_early_path, $mad4b_scp_early_offset + strlen( $mad4b_scp_early_needle ) ), '/' );
				$mad4b_scp_early_rest_path = '/' . ltrim( rtrim( $mad4b_scp_early_rest_path, '/' ), '/' );
			}
		}
	}

	if ( '' !== $mad4b_scp_early_rest_path ) {
		$mad4b_scp_early_owned = 0 === strpos( $mad4b_scp_early_rest_path, '/mcp/mad4b-' )
			|| 0 === strpos( $mad4b_scp_early_rest_path, '/mad4b/' );
		if ( ! $mad4b_scp_early_owned ) $mad4b_scp_early_zero_touch_reason = 'foreign_rest';
	} elseif ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		$mad4b_scp_early_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mad4b_scp_early_owned = 0 === strpos( $mad4b_scp_early_action, 'mad4b_' ) || 0 === strpos( $mad4b_scp_early_action, 'mad4b-' );
		if ( ! $mad4b_scp_early_owned ) $mad4b_scp_early_zero_touch_reason = 'foreign_admin_ajax';
	}
}

if ( '' !== $mad4b_scp_early_zero_touch_reason ) {
	if ( ! defined( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON' ) ) define( 'MAD4B_SCP_EARLY_ZERO_TOUCH_REASON', $mad4b_scp_early_zero_touch_reason );
	require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';
	require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-request-scope.php';
	MAD4B_SCP_MCP_Request_Scope::bootstrap();
	unset(
		$mad4b_scp_early_route,
		$mad4b_scp_early_uri,
		$mad4b_scp_early_rest_path,
		$mad4b_scp_early_path,
		$mad4b_scp_early_prefix,
		$mad4b_scp_early_needle,
		$mad4b_scp_early_offset,
		$mad4b_scp_early_owned,
		$mad4b_scp_early_action,
		$mad4b_scp_early_zero_touch_reason
	);
	return;
}
unset( $mad4b_scp_early_zero_touch_reason );

require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-route-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-workspace.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operator-workspace.php';
MAD4B_SCP_Admin_Route_Registry::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-identity-resolver.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-doctor.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-environment.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-maintenance-lease.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-post-update-continuation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-portable-readonly-connection.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dependency-manager.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-database-topology.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-database-failure-semantics.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-schema.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-persisted-contract-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-generation-fence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-database-transaction-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-schema-lifecycle.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-durable-execution.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-network-operation-journal.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-canonicalization.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-time-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-entropy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-resource-constraint-set.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-journal.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-structural-redaction.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-evidence-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-metrics.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-observability.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-projection-hotset-recommender.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dynamic-ttl-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-semantic-diff.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dynamic-provider-contract.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-declarative-adapter-manifest.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wordpress-domain-coverage.php';
require_once MAD4B_SCP_DIR . 'includes/domains/class-mad4b-scp-domain-native-providers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dynamic-recovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-jobs.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-intent-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-artifacts.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-intelligence-pipeline.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-runtime-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-semantic-recipe.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-recipe-gap.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-intake-preview.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-evidence-preview.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-opportunity-preview.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-aci01-native-relation-audit.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dynamic-content-pipeline.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-draft.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-publication-verification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-pack.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-research-intelligence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-search-measurement.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-search-runtime-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-search-eligibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-search-evidence-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-account-budget-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-search-decision-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-search-acceptance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-search-fault-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-bootstrap.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operator-doctor.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-cli.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-host-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-identifiers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-identity-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-abuse-budget.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-legacy-dispatch-migration.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-agent-registry.php';
// Assistant read-only GA/GB bootstrap is a first-class plugin lifecycle contract,
// not a side effect of loading NHI Agent Registry. Hook registration precedes the
// native WordPress Abilities/Adapter lifecycle and never grants execution.
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-planning.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-bootstrap-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-solution-discovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-solution-router.php';
// The capability atlas must be loaded before its lifecycle hook is registered.
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-capability-atlas.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-task-contract.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-task-journal-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-read-work-operations.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-assistant-operator-workspace.php';
MAD4B_SCP_Assistant_Planning::boot();
MAD4B_SCP_Assistant_Bootstrap_Diagnostic::boot();
MAD4B_SCP_Assistant_Convergence::boot();
MAD4B_SCP_Solution_Discovery::boot();
MAD4B_SCP_Capability_Atlas::boot();
MAD4B_SCP_Assistant_Solution_Router::boot();
MAD4B_SCP_Assistant_Operator_Workspace::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-runtime-gates.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connector-resilience.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-read-consistency.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-audit.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-contracts.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g4-provider-families.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g5-external-providers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g5-growth-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g5-provider-profiles.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g5-seo-provider-families.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g5-acceptance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-ai-workspace.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-knowledge-admission.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-provider-routing.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-retrieval-evaluation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-conversation-vault.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-durable-dag-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g6-acceptance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-operations-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-ownership-reconciliation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-operator-journal.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-host-readiness.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-update-acceptance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-action-center.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-read-surfaces.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-compensation-audit.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-release-acceptance-audit.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g7-workload-measurement.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-compatibility-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-auto-reconcile-scenarios.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-runtime-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-circuit-breaker.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-transport-eligibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-health-view.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-capability-traits.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-execution-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-shadow-read.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-callback-order.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-provider-plan.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-addon-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-data-governance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-data-governance-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-decommission-portability.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-decommission-governance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-portability-import.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-scheduler-admission.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-scheduler-backlog.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-impact-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-approval-impact-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization-decision-graph.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-crypto-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-certification-pack-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-receipt.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-approval-tickets.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-budgets.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-provider-isolation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-request-scope.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-diagnostic-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-adapter-metadata-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-peer-governance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-store.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-oauth-autoconfig.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-server.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-oauth-lifecycle.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-consent-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-key-path-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-restore-epoch.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-init-lock.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-loopback-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-local-oauth-browser-canary.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-resource-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-multi-authority-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-subject-user-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-handshake-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-observer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-record.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-supply-provenance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-schema-migration.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-restore-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-capability-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g8-compatibility-fuzz.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-automation-slo.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-query-monitor-evidence-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-query-performance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-query-performance-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-acceptance-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-unchanged-attestation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-promotion-attestation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wpml-response-contract.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-external-wpml-acceptance-finalizer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-truth-projection.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-provider-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-planner.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-verdict-reducer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-runner.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-acceptance-core.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g9-read-surface.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-request-context-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-jwt-header-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-egress-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-outbound-budget-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-subject-gate.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-profile-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-protocol-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-client-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-oauth-challenge-alignment.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-transport-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-transport-admission.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-status.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-semantic-content-field-contracts.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-ai-approval.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-google-drive-context.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-preflight.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-intelligence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-provider-gateway.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-brand-context-builder.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-compatibility-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-commit-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-policy-resolution.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-workstream-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-production-readiness-evaluator.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operator-control-center.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-request-generation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-error-contract-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php';
// The mutation execution-boundary filter must exist before any ability can be
// materialized. Nested write dispatch calls Ability::execute() directly, so
// permission callbacks alone are not a sufficient execution fence.
MAD4B_SCP_Authorization::boot();
MAD4B_SCP_Error_Contract_Registry::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-fence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mutation-manager.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-postcondition-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-reversible-adapter-mutations.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-discovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-autopilot.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-functional-gap-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-functional-gap-runtime-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-code-snippets-runtime-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-developer-host-capabilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-developer-runtime.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-developer-workspace.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-transport-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dependency-impact-graph.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-semantic-intent-router.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-pipeline.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-transaction.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operation-resume.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-execution-state-view.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-activation-state.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-lifecycle.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-wordpress-lifecycle-migration-profile.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin-package.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-plugin-update.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-self-update.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-release-set.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-workflow-providers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-operating-model.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governed-ability-overrides.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-distributed-lock.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-catalog-table-backend.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-catalog-backend-controller.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-ability-contract-inspector.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-capability-descriptor-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-competitive-evidence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-policy-classifier.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-evidence-graph.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-preparation-receipt.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-replay-policy.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-catalog-object-store.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-adapter-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-ability-catalog-transport.php';
MAD4B_SCP_Ability_Catalog_Transport::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-tool-projection.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-unified-capability-gateway.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-autoconfig.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-registry.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-seeder.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-resource-reader.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-resource-writer.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-snapshot-identity.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-exporter.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skill-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-work-queue.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-operation-parity.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-enrollment-dispatch.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-storage.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-manifest.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-planning.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-media-rights.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-media-rights.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-media-manifest.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-remote-media-recovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-profiles.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-bootstrap.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adaptive-search-intelligence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-governance.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-content-experience-runtime.php';
MAD4B_SCP_Content_Experience_Governance::boot();
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-adapter-base.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-aci01-read-adapter.php';
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
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-remote-media-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-seo-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-woocommerce-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-polylang-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-litespeed-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-form-provider-adapters.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-wp-import-export-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-full-content-operations-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-translation-bridge-adapter.php';
require_once MAD4B_SCP_DIR . 'includes/adapters/class-mad4b-scp-repository-family-adapter.php';
MAD4B_SCP_Full_Content_Operations_Adapter::boot();
MAD4B_SCP_Translation_Bridge_Adapter::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-registry.php';
MAD4B_SCP_Adapter_Registry::boot_ability_registration();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-servers.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-runtime-recovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-bridge.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-rescue-v1.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-authority.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-authority-convergence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-candidate-binding.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-write-planning-guard.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-rest-compatibility.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-provider-closure-matrix.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-write-runtime-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-live-truth.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-staging-certification.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-recovery-workspace.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-governance-abilities.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g2-governance-experience.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-g2-permission-changes.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-ability.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-experience.php';
MAD4B_SCP_Admin_Experience::boot();
MAD4B_SCP_G5_External_Providers::boot();
MAD4B_SCP_G5_Growth_Evidence::boot();
MAD4B_SCP_G5_SEO_Provider_Families::boot();
MAD4B_SCP_G5_Acceptance::boot();
MAD4B_SCP_G6_Acceptance::boot();
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-settings-persistence.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-dynamic-content-pipeline-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-context-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-endpoint-diagnostic.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-profile-admin.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-chatgpt-connection-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-adapter-coverage-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-runtime-components-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-browser-acceptance-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-site-capability-discovery.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-capability-browser-provider.php';
add_filter( 'mad4b_browser_acceptance_providers', array( 'MAD4B_SCP_Native_Capability_Browser_Provider', 'register' ), 15 );
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-skills-admin-ui.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-upgrade-continuity.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-reconnect-hardening.php';
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-plugin.php';

/*
 * Register ordinary wp-admin settings handlers before the heavier runtime
 * bootstrap begins. admin-ajax.php is a narrow lifecycle and may be short-
 * circuited by performance/security layers before init; settings actions must
 * therefore exist as soon as their classes are loaded. All boot methods are
 * idempotent and later calls remain safe.
 */
MAD4B_SCP_Context_Admin_UI::boot();
MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();
MAD4B_SCP_Site_Profile_Admin::boot();
MAD4B_SCP_Admin_Settings_Persistence::boot();
MAD4B_SCP_Staging_OAuth_Autoconfig::boot_admin_actions_early();

MAD4B_SCP_Site_Profile::bootstrap();
$mad4b_upgrade_continuity = MAD4B_SCP_Upgrade_Continuity::pre_boot();
if ( ! empty( $mad4b_upgrade_continuity['recovered'] ) ) {
	MAD4B_SCP_Site_Profile::reset_cache();
	MAD4B_SCP_Site_Profile::bootstrap();
}
// Portable read-only OAuth must observe the final post-recovery Site Profile
// state. Booting it before continuity recovery can freeze request-local OAuth
// constants against stale identity and can mask invalid stored profile state.
MAD4B_SCP_Portable_Readonly_Connection::bootstrap();
unset( $mad4b_upgrade_continuity );
// Classify MCP/OAuth protocol hotpaths before any subsequent runtime bootstrap.
// The classifier is read-only and request-local; early placement prevents
// post-update maintenance/recovery hooks from being treated like ordinary
// request-serving work during the first reconnect after a package replacement.
MAD4B_SCP_MCP_Request_Scope::bootstrap();
MAD4B_SCP_Site_Profile::boot();
MAD4B_SCP_Governed_Runtime_Gates::boot();
MAD4B_SCP_Governed_Runtime_Gates::bootstrap_runtime();
MAD4B_SCP_Upgrade_Continuity::boot();
MAD4B_SCP_Reconnect_Hardening::boot();
MAD4B_SCP_Dependency_Manager::boot();
MAD4B_SCP_OAuth_Subject_User_Bridge::boot();
MAD4B_SCP_Production_Certification::boot();
MAD4B_SCP_Production_Readiness_Evaluator::boot();
MAD4B_SCP_Operator_Control_Center::boot();
MAD4B_SCP_Automation_SLO::boot();
MAD4B_SCP_G7_Read_Surfaces::boot();
MAD4B_SCP_ACI01_Intake_Preview::boot();
MAD4B_SCP_ACI01_Evidence_Preview::boot();
MAD4B_SCP_ACI01_Opportunity_Preview::boot();
MAD4B_SCP_ACI01_Native_Relation_Audit::boot();

$mad4b_passive_admin_read = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
	&& MAD4B_SCP_MCP_Request_Scope::current_request_is_passive_admin_hotpath();
$mad4b_diagnostic_catalog_target = class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
	&& method_exists( 'MAD4B_SCP_MCP_Request_Scope', 'endpoint_diagnostic_routing_server_id' )
	? (string) MAD4B_SCP_MCP_Request_Scope::endpoint_diagnostic_routing_server_id()
	: '';

// Definition-only catalog wiring. A MU-signed diagnostic target may select the
// exact provider-backed catalog before worker authorization, but it does not
// make the request non-passive and grants no lifecycle or mutation authority.
if ( ! $mad4b_passive_admin_read || '' !== $mad4b_diagnostic_catalog_target ) {
	MAD4B_SCP_Staging_Certification::boot();
	MAD4B_SCP_Acceptance_Core::boot_early();
	MAD4B_SCP_Connection_Ability::boot();
	MAD4B_SCP_Read_Consistency::boot();
	MAD4B_SCP_Multi_Authority_Registry::boot();
	MAD4B_SCP_Context_Authority::boot();
	MAD4B_SCP_AI_Approval::boot();
	MAD4B_SCP_Provider_Transport_Registry::boot();
	MAD4B_SCP_Dependency_Impact_Graph::boot();
	MAD4B_SCP_Operation_Registry::boot();
	MAD4B_SCP_Operation_Pipeline::boot();
	MAD4B_SCP_Provider_Autopilot::boot();
	MAD4B_SCP_Plugin_Transaction::boot();
	MAD4B_SCP_Operation_Resume::boot();
	MAD4B_SCP_Plugin_Lifecycle::boot();
	MAD4B_SCP_Plugin_Package::boot();
	MAD4B_SCP_Remote_Plugin_Update::boot();
	MAD4B_SCP_Self_Update::boot();
	MAD4B_SCP_Runtime_Release_Set::boot();
	MAD4B_SCP_G9_Read_Surface::boot();
	MAD4B_SCP_Functional_Gap_Runtime_Diagnostic::boot();
	MAD4B_SCP_Code_Snippets_Runtime_Diagnostic::boot();
	MAD4B_SCP_Workflow_Providers::boot();
	MAD4B_SCP_Addon_Registry::boot();
	MAD4B_SCP_Operating_Model::boot();
	MAD4B_SCP_Governed_Ability_Overrides::boot();
	$mad4b_write_augment = array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' );
	if ( false === has_filter( 'wp_register_ability_args', $mad4b_write_augment ) ) add_filter( 'wp_register_ability_args', $mad4b_write_augment, 70, 2 );
	unset( $mad4b_write_augment );
	add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'register_status_ability' ), 35 );
	MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::boot();
	MAD4B_SCP_Staging_Write_Grant_Reconciliation::boot();
	MAD4B_SCP_Staging_Write_Authority_Convergence::boot();
	MAD4B_SCP_Staging_Write_Candidate_Binding::boot();
	MAD4B_SCP_Staging_Write_Planning_Guard::boot();
	add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_REST_Compatibility', 'register_ability' ), 36 );
	add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'register_ability' ), 37 );
	MAD4B_SCP_Governance_Abilities::boot();
	MAD4B_SCP_G2_Governance_Experience::boot();
	MAD4B_SCP_G2_Permission_Changes::boot();
	MAD4B_SCP_Skill_Abilities::boot();
	MAD4B_SCP_Skills_Adapter::boot();
	MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();
}

// Ordinary Control Plane GET/HEAD pages are render requests, not lifecycle jobs.
// Keep the global request bootstrap intentionally narrow: page-specific render
// code may read bounded/current evidence, while provider discovery, acceptance
// telemetry, write/catalog reconciliation, package/update lifecycle and Skill
// certification run only on explicit deep/action/protocol/CLI surfaces.
if ( ! $mad4b_passive_admin_read ) {
	MAD4B_SCP_Adaptive_Runtime_Convergence::boot();
	MAD4B_SCP_Live_Acceptance_Observer::boot_early();
	MAD4B_SCP_Query_Monitor_Evidence_Bridge::boot_early();
	MAD4B_SCP_Admin_Query_Performance::boot();
	MAD4B_SCP_Live_Acceptance_Finalizer::boot_early();
	MAD4B_SCP_Production_Unchanged_Attestation::boot_early();
	MAD4B_SCP_WPML_Response_Contract::boot_early();
	MAD4B_SCP_Live_Truth::boot_early();
	MAD4B_SCP_Context_Provider_Gateway::boot();
	MAD4B_SCP_Brand_Context_Builder::boot();
	remove_action( 'plugins_loaded', array( 'MAD4B_SCP_Skill_Provider_Discovery', 'bootstrap' ), 30 );

	MAD4B_SCP_Skill_Autoconfig::bootstrap();
	MAD4B_SCP_Staging_Write_Authority::bootstrap();
	// Managed MU reconciliation mutates filesystem state and therefore never runs
	// during plugin include. Defer it until init, where lifecycle/auth context is
	// complete; passive Control Plane GET/HEAD never reaches this branch.
	add_action( 'init', array( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', 'bootstrap' ), 20 );
	add_action( 'init', array( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard', 'bootstrap' ), 21 );
	MAD4B_SCP_MCP_Registration_Rescue::boot();
	MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();
	MAD4B_SCP_External_Handshake_Evidence::boot();
	MAD4B_SCP_ChatGPT_OAuth_Lifecycle::boot();
	MAD4B_SCP_Local_OAuth_Consent_UI::boot();
	MAD4B_SCP_Schema_Lifecycle::boot();
}

// Registration/transport hooks are cheap and request-local. Keep them available
// so passive pages can project expected gateway readiness without materializing
// provider registries or replaying REST lifecycle.
MAD4B_SCP_MCP_Runtime_Recovery::boot();
MAD4B_SCP_MCP_Registration_Bridge::boot_early();
MAD4B_SCP_MCP_Provider_Isolation::boot_early();
MAD4B_SCP_Context_Admin_UI::boot();
register_activation_hook( __FILE__, array( 'MAD4B_SCP_Plugin', 'activate' ) );
add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );
unset( $mad4b_passive_admin_read, $mad4b_diagnostic_catalog_target );
