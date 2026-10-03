#!/usr/bin/env python3
"""Static guard for MCP Adapter 0.6.x/0.7.x compatibility boundaries."""

from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "includes"


def fail(message: str) -> None:
    raise SystemExit(f"FAIL mcp-adapter-compatibility-contract: {message}")


compat = (INCLUDES / "class-mad4b-scp-mcp-adapter-compatibility.php").read_text(encoding="utf-8")
for marker in (
    "mad4b.mcp-adapter-compatibility.v1",
    "revision_aware",
    "legacy_dto",
    "get_protocol_record",
    "get_protocol_dto",
    "server_tools",
    "bounded_server_tools",
    "count_tools",
    "mad4b_mcp_tool_inventory_overflow",
    "mad4b_mcp_tool_count_projection_drift",
    "wire_data",
    "wire_name",
):
    if marker not in compat:
        fail(f"compatibility seam missing marker: {marker}")

# Production code must not directly bind to the legacy 0.6-only tool projection
# API. The compatibility seam is the sole representation adapter. The guarded
# McpToolValidator use in catalog diagnostics is intentionally retained for
# exact 0.6.x validation only.
for php in INCLUDES.rglob("*.php"):
    if php.name == "class-mad4b-scp-mcp-adapter-compatibility.php":
        continue
    text = php.read_text(encoding="utf-8")
    for pattern, label in (
        (r"->get_protocol_dto\s*\(", "legacy get_protocol_dto"),
        (r"RegisterAbilityAsMcpTool::build\s*\(", "direct legacy ability builder"),
        (r"\$server\s*->\s*get_tools\s*\(\s*\)", "schema-less server get_tools"),
    ):
        if re.search(pattern, text):
            fail(f"{label} escaped compatibility seam in {php.relative_to(ROOT)}")

catalog = (INCLUDES / "class-mad4b-scp-mcp-catalog-diagnostics.php").read_text(encoding="utf-8")
if "class_exists( 'WP\\\\MCP\\\\Domain\\\\Tools\\\\McpToolValidator' )" not in catalog:
    fail("legacy validator is not guarded by class existence")
if "|| ! class_exists( 'WP\\\\MCP\\\\Domain\\\\Tools\\\\McpToolValidator' )" in catalog:
    fail("catalog preflight still requires removed v0.7.0 McpToolValidator")
for marker in (
    "MAD4B_SCP_MCP_Adapter_Compatibility::build_ability_wire",
    "MAD4B_SCP_MCP_Adapter_Compatibility::server_tools",
    "MAD4B_SCP_MCP_Adapter_Compatibility::wire_name",
    "MAD4B_SCP_MCP_Adapter_Compatibility::wire_data",
):
    if marker not in catalog:
        fail(f"catalog diagnostics missing compatibility marker: {marker}")

projection = (INCLUDES / "class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
for marker in (
    "MAD4B_SCP_MCP_Adapter_Compatibility::build_ability_wire",
    "MAD4B_SCP_MCP_Adapter_Compatibility::runtime_tool_wire",
    "MAD4B_SCP_MCP_Adapter_Compatibility::wire_data",
):
    if marker not in projection:
        fail(f"dynamic projection missing compatibility marker: {marker}")

peer = (INCLUDES / "class-mad4b-scp-mcp-peer-governance.php").read_text(encoding="utf-8")
if peer.count("MAD4B_SCP_MCP_Adapter_Compatibility::bounded_server_tools") < 2:
    fail("peer governance does not bound tool inventory through the compatibility seam")
if "MAD4B_SCP_MCP_Adapter_Compatibility::server_tools" in peer:
    fail("peer governance bypasses the bounded compatibility inventory path")

main = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
compat_pos = main.find("class-mad4b-scp-mcp-adapter-compatibility.php")
projection_pos = main.find("class-mad4b-scp-chatgpt-tool-projection.php")
servers_pos = main.find("class-mad4b-scp-servers.php")
if min(compat_pos, projection_pos, servers_pos) < 0 or not (compat_pos < projection_pos < servers_pos):
    fail("compatibility seam is not loaded before projection/server construction")

policy = json.loads((ROOT / "config/runtime-release-policy.json").read_text(encoding="utf-8"))
if policy.get("target_adapter_version") != "0.7.0":
    fail("runtime release policy no longer targets exact MCP Adapter 0.7.0")
if set(policy.get("supported_transition_adapter_versions", [])) != {"0.6.1", "0.7.0"}:
    fail("supported transition set must remain exact 0.6.1 -> 0.7.0")

profiles = json.loads((ROOT / "config/certified-provider-profiles.json").read_text(encoding="utf-8"))
target = profiles.get("providers", {}).get("mcp_adapter", {}).get("0.7.0")
if not isinstance(target, dict) or target.get("version") != "0.7.0":
    fail("exact 0.7.0 provider profile is missing")
runtime_classes = target.get("runtime_classes", {})
classes = {spec.get("class") for spec in runtime_classes.values() if isinstance(spec, dict)}
required = {
    "WP\\MCP\\Core\\McpAdapter",
    "WP\\MCP\\Domain\\Tools\\RegisterAbilityAsMcpTool",
    "WP\\MCP\\Domain\\Tools\\McpTool",
    "WP\\MCP\\Core\\McpVersionNegotiator",
    "WP\\MCP\\Transport\\Infrastructure\\HttpRequestHandler",
    "WP\\MCP\\Transport\\Infrastructure\\McpWireOrchestrator",
}
missing = sorted(required - classes)
if missing:
    fail(f"0.7.0 runtime class profile is incomplete: {missing}")
for removed in (
    "WP\\MCP\\Domain\\Tools\\McpToolValidator",
    "WP\\McpSchema\\Server\\Tools\\DTO\\Tool",
):
    if removed in classes:
        fail(f"0.7.0 profile inherited removed class: {removed}")

mu = (ROOT / "bootstrap/mad4b-mcp-adapter-mu-bootstrap.php").read_text(encoding="utf-8")
for marker in (
    "adapter_version",
    "adapter_profile_source",
    "adapter_profile_ready",
    "certified-provider-profiles.json",
    "exact_adapter_profile_unavailable",
    "'0.6.1' === $mad4b_mcp_mu_adapter_version",
):
    if marker not in mu:
        fail(f"MU bootstrap is not exact-version aware: missing {marker}")
if "empty( $mad4b_mcp_mu_critical_classes )" not in mu:
    fail("legacy 0.6.1 class fallback is not bounded by an empty exact profile")

print("mad4b.site-control-plane.mcp-adapter-compatibility-contract.v1: PASS")
