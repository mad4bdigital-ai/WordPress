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

require(policy.get("contract") == "mad4b.runtime-release-policy.v1", "runtime release policy contract mismatch")
require(policy.get("target_adapter_version") == "0.7.0", "runtime release policy target must be MCP Adapter 0.7.0")
require(policy.get("update_order") == ["control_plane", "mcp_adapter"], "runtime release update order must cross a reboot boundary")
require(policy.get("pair_certification_required") is True, "exact pair certification must remain required")
require(policy.get("exact_provider_profile_required") is True, "exact provider profile must remain required")
require(policy.get("authority", {}).get("production_auto_apply") is False, "Production auto apply must remain disabled")
require(policy.get("authority", {}).get("breakglass_required") is False, "Breakglass must not be required")

adapter_profiles = (profiles.get("providers") or {}).get("mcp_adapter") or {}
p070 = adapter_profiles.get("0.7.0") or {}
require(p070.get("version") == "0.7.0", "MCP Adapter 0.7.0 exact profile missing")
require(
    p070.get("archive_sha256") == "9168c18dbd018428ff14ee28e7018aa0399d4b8819b7c98b406f6610731c3a79",
    "MCP Adapter 0.7.0 official release SHA drift",
)
require(p070.get("archive_bytes") == 435201, "MCP Adapter 0.7.0 official release size drift")
require(
    p070.get("package_url") == "https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip",
    "MCP Adapter 0.7.0 release URL drift",
)
runtime_classes = p070.get("runtime_classes") or {}
require(len(runtime_classes) >= 10, "MCP Adapter 0.7.0 version-scoped runtime class set is incomplete")
runtime_class_names = {str(row.get("class") or "") for row in runtime_classes.values() if isinstance(row, dict)}
require("WP\\MCP\\Domain\\Tools\\McpToolValidator" not in runtime_class_names, "removed 0.6.1 validator leaked into 0.7.0 class set")
require(not any("\\DTO\\" in name for name in runtime_class_names), "removed generated DTO class leaked into 0.7.0 class set")
require("WP\\MCP\\Transport\\Infrastructure\\McpWireOrchestrator" in runtime_class_names, "0.7.0 wire orchestrator is not certified")
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
require("'runtime_classes'," in provider_contracts, "version profile runtime_classes replacement missing")
require("return array();" in provider_contracts, "unknown exact provider version must fail closed")

provenance = (INCLUDES / "class-mad4b-scp-mcp-class-provenance.php").read_text(encoding="utf-8")
require("critical_classes( array $contract = array() )" in provenance, "class provenance is not version-scoped")
require("get_for_version( self::PROVIDER, $installed_version )" in provenance, "installed Adapter version is not resolved exactly")
require("version_profile_unavailable" in provenance, "unknown Adapter version does not fail closed")
require("$contract['runtime_classes']" in provenance, "version-scoped class set is not consumed")

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
require("self::apply_internal( $input, false, true )" in runtime, "remote bootstrap apply is not separated from local wp-admin apply")
require("MAD4B_SCP_Self_Update::native_apply(" in runtime and "(bool) $bootstrap_step_up" in runtime, "Control Plane component does not inherit bootstrap revalidation")
require(runtime.count("$revalidate = self::can_bootstrap_apply( $input );") >= 2, "each remote runtime component must revalidate OAuth step-up immediately before mutation")
require("self::apply_internal(" in runtime and "true,\n\t\t\tfalse" in runtime, "local wp-admin runtime update must remain independent of OAuth step-up")
require("caller_url_allowed' => false" in runtime, "caller-controlled package URL must remain denied")
require("generic_plugin_update_for_adapter_allowed' => false" in runtime, "generic Adapter update must remain denied")

self_update = (INCLUDES / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
require("runtime_release_set" in self_update, "Control Plane update manifest does not carry runtime release-set identity")
require("mad4b_self_update_runtime_release_set_adapter_url_invalid" in self_update, "runtime release-set Adapter URL is not validated")

main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
require("class-mad4b-scp-runtime-release-set.php" in main, "runtime release-set class is not loaded")
require("MAD4B_SCP_Runtime_Release_Set::boot();" in main, "runtime release-set class is not booted")

# Release-channel publication is a baseline-owned governance concern and is
# intentionally certified in a separate governance PR after this runtime
# implementation is merged. This implementation contract must not self-certify
# release-critical workflow mutations.
print("mad4b.runtime-release-set-contract.v1: PASS")
