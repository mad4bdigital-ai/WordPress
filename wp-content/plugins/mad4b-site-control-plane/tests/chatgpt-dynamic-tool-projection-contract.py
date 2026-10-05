#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROJECTION = (ROOT / "includes" / "class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
INSPECTOR = (ROOT / "includes" / "class-mad4b-scp-ability-contract-inspector.php").read_text(encoding="utf-8")
SERVERS = (ROOT / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
DIAG = (ROOT / "includes" / "class-mad4b-scp-mcp-catalog-diagnostics.php").read_text(encoding="utf-8")
MAIN = (ROOT / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
FENCE = (ROOT / "includes" / "class-mad4b-scp-execution-fence.php").read_text(encoding="utf-8")
ABILITIES = (ROOT / "includes" / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")

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
    "execution_eligibility_scope",
    "current_runtime_authority_evaluated",
    "current_runtime_authority_required_for_execution",
    "'structural_classification_only'",
    "private static function discovery_query_matches",
    "preg_split( '/[^\\p{L}\\p{N}]+/u'",
    "self::discovery_query_matches( $haystack, $query )",
]:
    require(marker in PROJECTION, f"all-site Ability discovery invariant missing: {marker}")

# Every registered Ability remains visible, but structural classification now
# belongs to the canonical inspector rather than the ChatGPT presentation layer.
for marker in [
    "mad4b.ability-contract-inspector.v1",
    "mad4b.ability-classification.v2",
    "classification",
    "projection_eligible",
    "execution_eligible",
    "execution_lane",
    "projection_blockers",
    "ability_classification_required",
    "ability_projection_policy_blocked",
]:
    require(marker in INSPECTOR, f"canonical Ability fail-closed invariant missing: {marker}")
require("MAD4B_SCP_Ability_Contract_Inspector::inspect" in PROJECTION, "projection does not consume the canonical Ability inspector")

# A pre-tool denial must arm the final callback seal requirement before any
# same-priority filter can overwrite the returned WP_Error.
for marker in [
    "Arm the final callback boundary before any projection/binding/policy",
    "require_projected_call_seal( $name )",
    "mad4b_projection_execution_fence_unavailable",
]:
    require(marker in PROJECTION, f"final projected execution latch missing: {marker}")

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
    "if ( isset( $dynamic[ $ability_name ] ) )",
    "projected_ability_names()",
]:
    require(marker in DIAG, f"dynamic projection classification missing: {marker}")

# Mutating dynamic projections are hidden without exact ChatGPT step-up;
# Breakglass additionally needs an exact server/Ability OAuth scope. Readonly
# projections remain discoverable without gaining authority.
for marker in [
    "( $dynamic && ! $dynamic_readonly )",
    "$step_up_visible",
    "$breakglass_scope_visible",
    "BREAKGLASS_SCOPE",
    "ability:",
    "MAD4B_SCP_Policy::can_breakglass()",
    "mad4b_required_catalog_schema_invalid",
]:
    require(marker in DIAG, f"dynamic tools/list request-time gate missing: {marker}")

for marker in [
    "mad4b_projection_breakglass_scope_required",
    "BREAKGLASS_SCOPE",
    "ability:",
]:
    require(marker in PROJECTION, f"projected Breakglass call admission missing: {marker}")

# Direct tools/list schemas are bounded independently from chunked catalog transport.
for marker in [
    "MAX_SERIALIZED_TOOL_BYTES",
    "mcp_optional_catalog_size_excluded",
    "mcp_required_catalog_size_exceeded",
    "bounded_serialized_tool_bytes",
]:
    require(marker in DIAG, f"serialized tools/list byte budget invariant missing: {marker}")

# Exact plan validates the resulting real MCP catalog, not only stored metadata.
for marker in [
    "MCP_Catalog_Diagnostics::budget_projection",
    "MCP_Catalog_Diagnostics::preflight",
    "mad4b_chatgpt_projection_preflight_blocked",
]:
    require(marker in PROJECTION, f"exact resulting catalog preflight missing: {marker}")

print("mad4b.chatgpt-dynamic-tool-projection.v1: PASS")

# Fixed dispatch is an independently governed child operation. Projection
# visibility may require a direct-call seal, but cannot poison the base
# dispatcher path for the same Ability.
for marker in [
    "governed_child_permit_matches( $name, $input )",
    "with_governed_child( $ability_name, $params, $execute_target, 'fixed_dispatch' )",
]:
    if marker not in (FENCE + ABILITIES):
        raise SystemExit("fixed-dispatch/projection isolation guard missing: " + marker)
