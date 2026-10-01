#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROJECTION = (ROOT / "includes" / "class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
SERVERS = (ROOT / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
DIAG = (ROOT / "includes" / "class-mad4b-scp-mcp-catalog-diagnostics.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")

def require(condition, message):
    if not condition:
        raise SystemExit(message)

# Bootstrap and contract identity.
for marker in [
    "class-mad4b-scp-chatgpt-tool-projection.php",
    "mad4b.chatgpt-tool-projection.v1",
    "mad4b/chatgpt-tool-projection-status",
    "mad4b/chatgpt-tool-projection-discover",
    "mad4b/chatgpt-tool-projection-plan",
    "mad4b/chatgpt-tool-projection-apply",
]:
    require(marker in MAIN or marker in PROJECTION, f"dynamic projection bootstrap/contract missing: {marker}")

# Any currently registered WordPress Ability is in the projection universe.
for marker in [
    "wp_get_abilities()",
    "all_site_ability_names",
    "ability_names",
    "input_schema_sha256",
    "currently_projected",
]:
    require(marker in PROJECTION, f"all-site Ability discovery invariant missing: {marker}")

# Projection is schema-pinned and exact-plan fenced.
for marker in [
    "expected_plan_sha256",
    "plan_sha256",
    "hash_equals",
    "mad4b_chatgpt_projection_plan_drift",
    "effective_projection_rows",
    "input_schema_sha256",
]:
    require(marker in PROJECTION, f"projection drift fence missing: {marker}")

# Projection itself is a bounded Staging governance mutation, not authority creation.
for marker in [
    "AUTHORITY_STEP_UP_SCOPE",
    "CHATGPT_CIMD_CLIENT_ID",
    "staging",
    "origin_enrolled",
    "site_urls_match_enrollment",
    "projection_changes_authority",
    "authority_widened",
]:
    require(marker in PROJECTION, f"projection governance boundary missing: {marker}")

for forbidden in [
    "grant_ability(",
    "database-raw-query' => true",
    "production_mutation' => true",
    "MAD4B_MCP_BREAKGLASS_ENABLED', true",
]:
    require(forbidden not in PROJECTION, f"projection registry widens authority: {forbidden}")

# Breakglass can only enter through an explicit current-authority opt-in.
for marker in [
    "include_breakglass",
    "mad4b_chatgpt_projection_breakglass_explicit_opt_in_required",
    "MAD4B_SCP_Policy::can_breakglass()",
]:
    require(marker in PROJECTION, f"breakglass projection guard missing: {marker}")

# The compact catalog remains a stable base plus optional dynamic projection set.
for marker in [
    "public static function chatgpt_base_tools()",
    "public static function chatgpt_tools()",
    "MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names()",
    "array_merge( $base, $dynamic )",
]:
    require(marker in SERVERS, f"dynamic tools/list composition missing: {marker}")

# Dynamic tools are optional: bad schemas/budget never evict required transport tools.
for marker in [
    "$dynamic_optional",
    "MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names()",
    "MCP_Catalog_Diagnostics::preflight",
]:
    require(marker in SERVERS, f"dynamic projection preflight isolation missing: {marker}")

for marker in [
    "dynamic_projection",
    "dynamic_readonly",
    "dynamic_breakglass",
    "reviewed direct step-ups and",
    "projected_ability_names()",
]:
    require(marker in DIAG, f"dynamic projection classification missing: {marker}")

# Mutating dynamic projections are hidden without exact ChatGPT step-up;
# readonly projections remain discoverable without gaining authority.
for marker in [
    "( $dynamic && ! $dynamic_readonly )",
    "$step_up_visible",
    "MAD4B_SCP_Policy::can_breakglass()",
    "mad4b_required_catalog_schema_invalid",
]:
    require(marker in DIAG, f"dynamic tools/list request-time gate missing: {marker}")

# Exact plan validates the resulting real MCP catalog, not only stored metadata.
for marker in [
    "MCP_Catalog_Diagnostics::budget_projection",
    "MCP_Catalog_Diagnostics::preflight",
    "mad4b_chatgpt_projection_preflight_blocked",
]:
    require(marker in PROJECTION, f"exact resulting catalog preflight missing: {marker}")

print("mad4b.chatgpt-dynamic-tool-projection.v1: PASS")
