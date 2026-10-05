#!/usr/bin/env python3
"""Static governance contract for the dynamic MAD4B runtime release set."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "config"
INCLUDES = ROOT / "includes"
REPO = ROOT.parents[2]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)


policy = json.loads((CONFIG / "runtime-release-policy.json").read_text(encoding="utf-8"))
profiles = json.loads((CONFIG / "certified-provider-profiles.json").read_text(encoding="utf-8"))
handoff = json.loads((CONFIG / "staging-deployment-handoff.json").read_text(encoding="utf-8"))
protocol = json.loads((CONFIG / "mcp-protocol-profiles.json").read_text(encoding="utf-8"))

require(policy.get("contract") == "mad4b.runtime-release-policy.v1", "runtime release policy contract mismatch")
require(policy.get("target_adapter_version") == "0.7.0", "runtime release policy target must be MCP Adapter 0.7.0")
require(policy.get("update_order") == ["control_plane", "mcp_adapter"], "runtime release update order must cross a reboot boundary")
require(policy.get("pair_certification_required") is True, "exact pair certification must remain required")
require(policy.get("exact_provider_profile_required") is True, "exact provider profile must remain required")
require(policy.get("authority", {}).get("production_auto_apply") is False, "Production auto apply must remain disabled")
require(policy.get("authority", {}).get("breakglass_required") is False, "Breakglass must not be required")

require(handoff.get("contract") == "mad4b.wordpress-deployment-handoff.v2", "staging deployment handoff contract mismatch")
handoff_adapter = ((handoff.get("target") or {}).get("mcp_adapter") or {})
target_adapter_version = str(policy.get("target_adapter_version") or "")
require(
    handoff_adapter.get("required_version_source") == "runtime_release_policy.target_adapter_version",
    "staging deployment handoff must declare runtime release policy as the Adapter version source",
)
require(
    handoff_adapter.get("required_version") == target_adapter_version,
    "staging deployment handoff Adapter version drifted from runtime release policy",
)

authority_handoff = ((handoff.get("post_deploy") or {}).get("authority_handoff") or {})
require(
    authority_handoff.get("contract") == "mad4b.staging-write-post-deploy-handoff.v1",
    "post-deploy governed write authority handoff contract missing",
)
require(
    authority_handoff.get("current_truth_source") == "mad4b/staging-write-authority-convergence-handshake",
    "post-deploy authority handoff must use the narrow read-only convergence handshake",
)
require(
    authority_handoff.get("compatibility_plan_ability") == "mad4b/staging-write-grant-reconciliation-plan",
    "post-deploy authority handoff compatibility plan missing",
)
require(
    authority_handoff.get("apply_ability") == "mad4b/staging-write-authority-convergence-apply",
    "post-deploy authority handoff must use narrow write-only convergence apply",
)
require(
    authority_handoff.get("required_confirmation") == "ENABLE GOVERNED STAGING WRITE AUTHORITY",
    "post-deploy authority handoff confirmation drift",
)
for key in (
    "exact_runtime_identity_required",
    "exact_site_profile_binding_required",
    "exact_grant_inventory_required",
    "exact_transport_grant_inventory_required",
    "read_only_plan_before_apply",
    "explicit_apply_required",
    "fail_closed_on_allowlist_drift",
    "same_cycle_readback_required",
    "append_only_audit_required",
):
    require(authority_handoff.get(key) is True, f"post-deploy authority handoff invariant missing: {key}")
for key in (
    "automatic_apply_allowed",
    "production_allowed",
    "developer_authority_included",
    "developer_breakglass_included",
    "generic_raw_sql_breakglass_included",
    "wildcard_grants_allowed",
    "caller_supplied_provider_allowed",
):
    require(authority_handoff.get(key) is False, f"post-deploy authority handoff widened forbidden authority: {key}")

operational = ((handoff.get("post_deploy") or {}).get("operational_readiness") or {})
require(
    operational.get("contract") == "mad4b.staging-operational-readiness.v1",
    "lane-aware post-deploy operational readiness contract missing",
)
require(
    operational.get("truth_model") == "lane_aware_fail_closed",
    "post-deploy operational readiness truth model drift",
)
require(
    operational.get("required_sequence") == [
        "exact_runtime_identity",
        "certified_mcp_protocol_profile",
        "query_monitor_observer_attribution",
        "database_topology_write_safe",
        "chatgpt_catalog_preflight",
        "abuse_budget_discovery_admission",
        "current_write_authority_and_candidate_binding",
        "provider_closure_current_authority_consistency",
        "session_safe_subject_readiness",
        "lane_level_operational_readiness",
    ],
    "post-deploy operational readiness sequence drift",
)
truth_sources = operational.get("read_only_truth_sources") or {}
require(truth_sources.get("runtime_and_session") == "mad4b/session-safe-diagnostics", "session-safe operational truth source drift")
require(truth_sources.get("catalog_and_protocol") == "mad4b/chatgpt-tool-projection-status", "catalog/protocol operational truth source drift")
require(truth_sources.get("current_write_authority") == "mad4b/staging-write-authority-convergence-handshake", "write authority operational truth source drift")
require(truth_sources.get("provider_closure") == "mad4b/provider-closure-matrix", "provider closure operational truth source drift")
require(truth_sources.get("full_authority_and_lanes") == "mad4b/full-staging-authority-handshake", "lane readiness operational truth source drift")

smoke = operational.get("smoke_checks") or {}
require(smoke.get("discovery_surface") == "mad4b/tool-discover", "operational discovery smoke surface drift")
for key in (
    "discovery_must_not_fail_on_certified_query_monitor_observer",
    "rate_limit_storage_must_report_actionable_topology_recovery",
    "direct_catalog_must_remain_within_tool_and_serialized_byte_budgets",
):
    require(smoke.get(key) is True, f"operational smoke invariant missing: {key}")

degraded = operational.get("graceful_degradation") or {}
require(
    degraded.get("unavailable_lane_policy") == "fail_closed_without_disabling_ready_sibling_lanes",
    "graceful degradation policy drift",
)
require(degraded.get("developer_host_limitations_do_not_false_mark_governed_write_unready") is True, "Developer host limits must not falsely disable governed Write")
require(degraded.get("production_is_never_inferred_from_staging_lane_readiness") is True, "Staging lane readiness must never imply Production")
require("database_topology_write_safe" in (degraded.get("governed_write_lane_requires") or []), "governed Write lane lost DB topology gate")
require("process_backend_ready" in (degraded.get("developer_lane_requires") or []), "Developer lane lost process backend gate")
require("normal_no_network_execution_ready" in (degraded.get("developer_lane_requires") or []), "Developer lane lost network-isolation gate")

developer_host_recovery = degraded.get("developer_host_recovery") or {}
require(developer_host_recovery.get("truth_source") == "mad4b/full-staging-authority-handshake.developer_execution.host_recovery", "Developer host recovery truth source drift")
require(developer_host_recovery.get("owner") == "host_platform_operator", "Developer host recovery ownership drift")
require(developer_host_recovery.get("accepted_resource_limiter") == "prlimit", "Developer resource limiter certification drift")
require(developer_host_recovery.get("resource_limiter_config_constant") == "MAD4B_MCP_DEVELOPER_PRLIMIT_BIN", "Developer resource limiter override drift")
require(developer_host_recovery.get("accepted_network_isolation_backends") == ["bubblewrap", "unshare-net"], "Developer network sandbox certification drift")
require(developer_host_recovery.get("network_sandbox_config_constant") == "MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN", "Developer network sandbox override drift")
for key in (
    "proc_open_required",
    "non_root_worker_required",
    "php_binary_required_for_lint",
    "developer_lane_remains_blocked_until_reverified",
    "governed_write_sibling_lane_may_remain_available",
):
    require(developer_host_recovery.get(key) is True, f"Developer host recovery required invariant missing: {key}")
for key in (
    "wordpress_self_repair_allowed",
    "automatic_install_allowed",
    "weaker_unsandboxed_fallback_allowed",
    "production_mutation_allowed",
):
    require(developer_host_recovery.get(key) is False, f"Developer host recovery widened unsafe fallback: {key}")

recovery = operational.get("recovery") or {}
rate_recovery = recovery.get("rate_limit_topology_failure") or {}
require(rate_recovery.get("read_first") == "mad4b/session-safe-diagnostics", "rate-limit recovery must start with session-safe diagnostics")
require(rate_recovery.get("bounded_repair") == "mad4b/query-monitor-db-attribution-bootstrap", "rate-limit recovery lost bounded Query Monitor repair")
require(rate_recovery.get("blind_retry_allowed") is False, "rate-limit topology recovery must forbid blind retries")
write_recovery = recovery.get("write_authority_drift") or {}
require(write_recovery.get("plan_first") == "mad4b/staging-write-authority-convergence-handshake", "write authority recovery plan drift")
require(write_recovery.get("apply") == "mad4b/staging-write-authority-convergence-apply", "write authority recovery apply drift")
require(write_recovery.get("automatic_apply_allowed") is False, "write authority recovery must remain explicit")
protocol_recovery = recovery.get("protocol_profile_drift") or {}
require(protocol_recovery.get("exact_adapter_pair_certification_required") is True, "protocol recovery must remain exact-pair certified")
require(protocol_recovery.get("fallback_protocol_negotiation_allowed") is False, "protocol recovery must not silently downgrade")
catalog_recovery = recovery.get("catalog_refresh") or {}
require(catalog_recovery.get("visibility_change_never_changes_execution_authority") is True, "catalog refresh must not widen authority")

router = recovery.get("deterministic_router") or {}
require(router.get("source") == "mad4b/session-safe-diagnostics.recommended_next_step", "deterministic recovery router source drift")
require(router.get("one_next_step_only") is True, "deterministic recovery router must expose one next step only")
require(router.get("parallel_diagnostic_fanout_allowed") is False, "deterministic recovery router must deny parallel diagnostic fan-out")
write_route = router.get("write_authority_not_effective") or {}
require(write_route.get("action") == "request_full_staging_authority_handshake", "write recovery route drift")
require(write_route.get("ability") == "mad4b/full-staging-authority-handshake", "write recovery ability drift")
require(write_route.get("read_only") is True and write_route.get("automatic_apply_allowed") is False, "write recovery route must remain read-only and non-automatic")
skills_route = router.get("skills_runtime_not_effective") or {}
require(skills_route.get("ability") == "mad4b/reconcile-managed-skills", "skills recovery ability drift")
require(skills_route.get("explicit_authority_required") is True and skills_route.get("automatic_apply_allowed") is False, "skills recovery must require explicit authority")
partial_route = router.get("partial_report") or {}
require(partial_route.get("mutation_target") == "" and partial_route.get("automatic_apply_allowed") is False, "partial report recovery must not invent a mutation target")
ready_route = router.get("ready_subject") or {}
require(ready_route.get("action") == "continue_with_single_target_operation", "ready subject routing drift")

performance = operational.get("performance") or {}
require(
    performance.get("contract") == "mad4b.staging-operational-performance-comparison.v1",
    "post-deploy comparative performance contract missing",
)
require(
    performance.get("source") == "mad4b/session-safe-diagnostics.performance_observation",
    "post-deploy performance source drift",
)
require(performance.get("exact_runtime_generation_required") is True, "performance comparison must bind exact runtime generation")
require(performance.get("exact_site_profile_required") is True, "performance comparison must bind exact Site Profile")
require(performance.get("comparison_required") is True, "performance comparison must remain mandatory for acceptance")
require(performance.get("baseline_scope") == "previous_exact_staging_release", "performance baseline scope drift")
require(
    performance.get("metrics") == [
        "request_elapsed_ms",
        "db_query_count",
        "included_file_count",
        "memory_usage_bytes",
        "peak_memory_bytes",
    ],
    "post-deploy performance metric set drift",
)
require(performance.get("explicit_diagnostic_budget_is_hard_boundary") is True, "explicit diagnostic budget must remain a hard boundary")
require(performance.get("total_request_elapsed_is_comparative_signal") is True, "total request elapsed must remain comparative rather than misusing the diagnostic budget")
require(performance.get("diagnostic_elapsed_is_budgeted_signal") is True, "diagnostic elapsed must remain the budgeted signal")
for key in (
    "universal_db_query_threshold",
    "universal_included_file_threshold",
    "universal_memory_threshold",
    "automatic_tuning_allowed",
    "automatic_index_ddl_allowed",
    "automatic_cache_authority_allowed",
    "authorizing",
    "production_mutation_allowed",
):
    require(performance.get(key) is False, f"post-deploy performance policy widened unsafe automation or invented a universal threshold: {key}")
require(performance.get("material_regression_requires_review") is True, "material performance regression must require review")
require(performance.get("missing_baseline_state") == "baseline_required_not_failure", "missing performance baseline semantics drift")
require(performance.get("regression_state") == "review_required", "performance regression state drift")
require(performance.get("no_regression_state") == "accepted_comparative_observation", "no-regression performance state drift")

acceptance = operational.get("acceptance") or {}
for key in (
    "operational_ready_requires_all_requested_lanes_ready",
    "degraded_mode_is_explicit",
    "available_lanes_must_be_reported",
    "blocked_lanes_must_be_reported",
    "blockers_must_have_recovery_action",
):
    require(acceptance.get(key) is True, f"operational acceptance invariant missing: {key}")
for key in (
    "automatic_authority_mutation_allowed",
    "production_mutation_allowed",
    "generic_raw_sql_breakglass_included",
):
    require(acceptance.get(key) is False, f"operational acceptance widened forbidden authority: {key}")

require(
    acceptance.get("operational_state_enum") == [
        "authority_convergence_required",
        "degraded_host_execution",
        "operationally_blocked",
        "operationally_ready",
    ],
    "operational acceptance state enum drift",
)
require(
    acceptance.get("provider_write_authority_state_enum") == [
        "write_authority_reconciliation_required",
        "write_authority_current",
    ],
    "provider write-authority acceptance state enum drift",
)
require(
    acceptance.get("state_is_derived_from_current_truth_not_persisted_checkpoint_only") is True,
    "operational state must remain bound to current runtime truth",
)

adapter_profiles = (profiles.get("providers") or {}).get("mcp_adapter") or {}
p070 = adapter_profiles.get("0.7.0") or {}
require(p070.get("version") == "0.7.0", "MCP Adapter 0.7.0 exact profile missing")
protocol_adapter = protocol.get("certified_adapter") or {}
require(
    protocol_adapter.get("version") == policy.get("target_adapter_version"),
    "MCP protocol catalog Adapter drifted from runtime release policy",
)
require(
    protocol_adapter.get("version_source") == "runtime_release_policy.target_adapter_version",
    "MCP protocol catalog lacks canonical Adapter version source",
)
transport_compat = p070.get("transport_compatibility") or {}
expected_protocols = list(transport_compat.get("modern_per_request_revisions") or []) + list(
    reversed(transport_compat.get("legacy_session_revisions") or [])
)
require(
    protocol.get("supported_protocol_versions") == expected_protocols,
    "MCP protocol catalog revisions drifted from certified Adapter profile",
)
require("2026-07-28" in expected_protocols, "MCP Adapter 0.7.0 modern protocol revision is not release-certified")
require(
    p070.get("archive_sha256") == "9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79",
    "MCP Adapter 0.7.0 official release SHA drift",
)
require(p070.get("archive_bytes") == 435201, "MCP Adapter 0.7.0 official release size drift")
require(
    p070.get("package_url") == "https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip",
    "MCP Adapter 0.7.0 release URL drift",
)

pre_staging_workflow = (REPO / ".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text(encoding="utf-8")
certified_materializer = (ROOT / "tests/materialize-certified-mcp-adapter.py").read_text(encoding="utf-8")
require(
    pre_staging_workflow.count("materialize-certified-mcp-adapter.py") >= 2,
    "Pre-Staging runtime jobs must materialize the certified Adapter release",
)
require(
    "--archive wp-content/plugins/mcp-adapter.zip" not in pre_staging_workflow,
    "Pre-Staging runtime regressed to the stale repository Adapter fixture",
)
for marker in (
    "runtime-release-policy.json",
    "certified-provider-profiles.json",
    "archive_sha256",
    "archive_bytes",
    "ALLOWED_INITIAL_PREFIX",
):
    require(marker in certified_materializer, "certified Adapter materializer invariant missing: " + marker)
runtime_surface = p070.get("runtime_surface") or {}
runtime_symbols = p070.get("runtime_symbols") or []
require(runtime_surface.get("contract") == "mad4b.mcp-runtime-surface.v1", "MCP Adapter 0.7.0 runtime surface contract missing")
require(runtime_surface.get("discovery") == "exact_archive_php_namespace_surface", "MCP Adapter runtime surface is not generated from the exact archive")
require(runtime_surface.get("fail_closed_on_unprofiled_symbol") is True, "MCP runtime surface must fail closed on unprofiled symbols")
require(runtime_surface.get("symbol_count") == len(runtime_symbols), "MCP runtime surface symbol count drift")
require(runtime_surface.get("file_count") == len({str(row.get("file") or "") for row in runtime_symbols if isinstance(row, dict)}), "MCP runtime surface file count drift")
runtime_symbol_names = {str(row.get("symbol") or "") for row in runtime_symbols if isinstance(row, dict)}
require(len(runtime_symbol_names) >= 20, "MCP Adapter 0.7.0 generated runtime symbol set is incomplete")
require("WP\\MCP\\Domain\\Tools\\McpToolValidator" not in runtime_symbol_names, "removed 0.6.1 validator leaked into 0.7.0 symbol surface")
require(not any("\\DTO\\" in name for name in runtime_symbol_names), "removed generated DTO class leaked into 0.7.0 symbol surface")
require("WP\\MCP\\Transport\\Infrastructure\\McpWireOrchestrator" in runtime_symbol_names, "0.7.0 wire orchestrator is not certified")
require("WP\\MCP\\Transport\\Infrastructure\\JsonRpcRequestDecoder" in runtime_symbol_names, "0.7.0 wire decoder is not dynamically certified")
require(len(p070.get("critical_files") or {}) >= 20, "MCP Adapter 0.7.0 critical file manifest is too small")

transport = p070.get("transport_compatibility") or {}
require(
    transport.get("legacy_session_revisions") == ["2024-11-05", "2025-06-18", "2025-11-25"],
    "0.7.0 legacy session revision contract drift",
)
require(
    transport.get("modern_per_request_revisions") == ["2026-07-28"],
    "0.7.0 modern per-request revision contract drift",
)
require(
    transport.get("permission_callback_wp_error_passthrough") is False,
    "0.7.0 permission WP_Error collapse must remain explicit",
)
require(
    transport.get("mad4b_transport_admission_bridge_required") is True,
    "MAD4B admission bridge requirement missing from 0.7.0 profile",
)
require(
    transport.get("authenticated_tools_call_revalidation_required") is True,
    "authenticated tools/call revalidation requirement missing",
)
require(
    set(transport.get("recovery_read_tools") or []) == {
        "mad4b/site-profile-status",
        "mad4b/session-safe-diagnostics",
        "mad4b/site-info",
    },
    "recovery read tool acceptance set drift",
)

provider_contracts = (INCLUDES / "class-mad4b-scp-provider-contracts.php").read_text(encoding="utf-8")
require("public static function get_for_version" in provider_contracts, "exact provider version resolver missing")
require("'runtime_symbols'," in provider_contracts and "'runtime_surface'," in provider_contracts, "generated runtime surface replacement missing")
require("return array();" in provider_contracts, "unknown exact provider version must fail closed")

provenance = (INCLUDES / "class-mad4b-scp-mcp-class-provenance.php").read_text(encoding="utf-8")
require("critical_classes( array $contract = array() )" in provenance, "class provenance is not version-scoped")
require("get_for_version( self::PROVIDER, $installed_version )" in provenance, "installed Adapter version is not resolved exactly")
require("version_profile_unavailable" in provenance, "unknown Adapter version does not fail closed")
require("$contract['runtime_symbols']" in provenance, "version-scoped generated symbol surface is not consumed")

dependency = (INCLUDES / "class-mad4b-scp-dependency-manager.php").read_text(encoding="utf-8")
require("private static function bootstrap_mcp_adapter()" in dependency, "bootstrap MCP Adapter resolver missing")
require("$bundle = self::bundled_archive_status( $bootstrap_sha );" in dependency, "bundled repair is not pinned to bootstrap Adapter identity")
require("$installed_certified = '' !== $installed_version ? self::certified_mcp_adapter( $installed_version ) : array();" in dependency, "installed MCP Adapter is not version-certified exactly")
require("'transition_supported' => $transition_supported" in dependency, "dependency status does not expose transition compatibility")

bridge = (INCLUDES / "class-mad4b-scp-mcp-adapter-metadata-bridge.php").read_text(encoding="utf-8")
require("const VERSION = '0.6.1'" not in bridge, "metadata bridge still pins Adapter 0.6.1")
require("public static function certified_version()" in bridge, "dynamic certified Adapter version resolver missing")
require("MAD4B_SCP_Runtime_Release_Set::target_adapter_version()" in bridge, "metadata bridge is not release-set aware")

package = (INCLUDES / "class-mad4b-scp-plugin-package.php").read_text(encoding="utf-8")
require("certified_upstream_release" in package, "certified upstream package source missing")
require("certified_upstream_url_allowed" in package, "upstream release URL guard missing")
require("WordPress/mcp-adapter/releases/download/v" in package, "upstream package source is not pinned to WordPress MCP Adapter releases")
require("MAD4B_SCP_Runtime_Release_Set::component_apply_in_progress( 'mcp_adapter' )" in package, "protected Adapter bypass is not release-set scoped")
require("0 === strpos( strtolower( $plugin_file ), 'mcp-adapter/' )" in package, "generic MCP Adapter protection was removed")

remote = (INCLUDES / "class-mad4b-scp-remote-plugin-update.php").read_text(encoding="utf-8")
require("0 === strpos( strtolower( $plugin_file ), 'mcp-adapter/' )" in remote, "generic remote updater must continue denying MCP Adapter")

runtime = (INCLUDES / "class-mad4b-scp-runtime-release-set.php").read_text(encoding="utf-8")
runtime_ui = (INCLUDES / "class-mad4b-scp-runtime-components-admin-ui.php").read_text(encoding="utf-8")
servers = (INCLUDES / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
projection = (INCLUDES / "class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
catalog_diag = (INCLUDES / "class-mad4b-scp-mcp-catalog-diagnostics.php").read_text(encoding="utf-8")
for needle in (
    "mad4b.runtime-release-set.v1",
    "update_control_plane",
    "control_plane_updated_awaiting_resume",
    "update_mcp_adapter",
    "awaiting_runtime_readback",
    "reboot_boundary_between_components",
    "MAD4B_SCP_MCP_Class_Provenance::status",
    "MAD4B_SCP_Plugin_Package::apply",
    "MAD4B_SCP_Self_Update::native_apply",
    "check_admin_referer( 'mad4b_runtime_release_set_apply' )",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "'staging' !== sanitize_key",
    "breakglass_active()",
):
    require(needle in runtime, f"runtime release-set invariant missing: {needle}")
require("production_allowed' => false" in runtime, "runtime release-set Production mutation must remain denied")
require("const BOOTSTRAP_APPLY_ABILITY = 'mad4b/runtime-release-set-bootstrap-apply'" in runtime, "runtime release-set bootstrap step-up ability missing")
require("'chatgpt_direct_step_up' => true" in runtime, "runtime release-set bootstrap is not projected as reviewed direct step-up")
require("verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE )" in runtime, "runtime release-set bootstrap lacks exact authority step-up scope check")
require("verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID )" in runtime, "runtime release-set bootstrap lacks exact ChatGPT client attribution")
bootstrap_permission = runtime.split("public static function can_bootstrap_apply", 1)[1].split("public static function chatgpt_step_up_tools", 1)[0]
require("MAD4B_SCP_Policy::can_mutate()" not in bootstrap_permission, "runtime release-set bootstrap incorrectly depends on general mutation authority")
catalog_projection = runtime.split("public static function chatgpt_step_up_tools", 1)[1].split("public static function bootstrap_apply", 1)[0]
require("verified_bearer" not in catalog_projection, "runtime release-set catalog projection must remain bearer-independent")
require("can_bootstrap_apply" not in catalog_projection, "runtime release-set catalog projection must not execute request-time authorization")
require("MAD4B_SCP_Runtime_Release_Set::BOOTSTRAP_APPLY_ABILITY" in servers, "ChatGPT reviewed direct step-up catalog omits runtime release-set bootstrap")
require("MAD4B_SCP_Runtime_Release_Set::chatgpt_step_up_tools()" in servers, "ChatGPT enrollment candidates omit runtime release-set step-up projection")
require("array_merge( $optional, $base_optional )" in projection, "explicit dynamic projections must outrank optional direct step-ups in plan budget")
require("array_merge( $dynamic_optional, self::chatgpt_reviewed_direct_step_up_tools() )" in servers, "server materialization must preserve explicit dynamic projection priority")
require("MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() : array(),\n\t\t\t\tMAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools()" in catalog_diag, "shared catalog diagnostics must preserve dynamic-first optional ordering")
require("add_filter( 'plugin_action_links_'" not in runtime, "runtime release-set mutation must not be injected into the generic Plugins action row")
plugin_links = runtime.split("public static function plugin_action_links( $links )", 1)[1].split("public static function handle_admin_apply()", 1)[0]
require("mad4b_runtime_release_set_apply" not in plugin_links, "compatibility plugin-action shim must remain presentation-only")
for marker in (
    "'maintenance' => __( 'Runtime Update'",
    "private static function render_maintenance()",
    "mad4b_runtime_release_set_apply",
    "Update compatible runtime",
    "wp_nonce_field( 'mad4b_runtime_release_set_apply' )",
):
    require(marker in runtime_ui, f"dedicated runtime maintenance UI marker missing: {marker}")
require("self::apply_internal( $input, false, true )" in runtime, "remote bootstrap apply is not separated from local wp-admin apply")
require("MAD4B_SCP_Self_Update::native_apply(" in runtime and "(bool) $bootstrap_step_up" in runtime, "Control Plane component does not inherit bootstrap revalidation")
require(runtime.count("$revalidate = self::can_bootstrap_apply( $input );") >= 2, "each remote runtime component must revalidate OAuth step-up immediately before mutation")
require("self::apply_internal(" in runtime and "true,\n\t\t\tfalse" in runtime, "local wp-admin runtime update must remain independent of OAuth step-up")
require("caller_url_allowed' => false" in runtime, "caller-controlled package URL must remain denied")
require("generic_plugin_update_for_adapter_allowed' => false" in runtime, "generic Adapter update must remain denied")

self_update = (INCLUDES / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
require("runtime_release_set" in self_update, "Control Plane update manifest does not carry runtime release-set identity")
require("mad4b_self_update_runtime_release_set_adapter_url_invalid" in self_update, "runtime release-set Adapter URL is not validated")
require("mad4b.staging-write-post-deploy-handoff.v1" in self_update, "self-update authority handoff projection missing")
require("'authority_handoff' => $authority_handoff" in self_update, "native release plan does not expose independent authority handoff truth")
require("'current_authority_blockers' => $current_blockers" in self_update, "self-update projection drops exact current authority blockers")
require("'automatic_apply_allowed' => false" in self_update, "post-release authority handoff must never auto-apply")
require("'developer_breakglass_included' => false" in self_update, "post-release authority handoff must exclude Developer Breakglass")

main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
require("class-mad4b-scp-runtime-release-set.php" in main, "runtime release-set class is not loaded")
require("MAD4B_SCP_Runtime_Release_Set::boot();" in main, "runtime release-set class is not booted")

# Release-channel publication is a baseline-owned governance concern and is
# intentionally certified in a separate governance PR after this runtime
# implementation is merged. This implementation contract must not self-certify
# release-critical workflow mutations.
print("mad4b.runtime-release-set-contract.v1: PASS")
