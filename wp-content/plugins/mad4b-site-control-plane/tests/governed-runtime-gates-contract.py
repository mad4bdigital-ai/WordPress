#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GATES = (ROOT / "includes/class-mad4b-scp-governed-runtime-gates.php").read_text()
POLICY = (ROOT / "includes/class-mad4b-scp-policy.php").read_text()
WRITE = (ROOT / "includes/class-mad4b-scp-staging-write-authority.php").read_text()
FULL = (ROOT / "includes/class-mad4b-scp-full-staging-authority.php").read_text()
ENROLLMENT = (ROOT / "includes/class-mad4b-scp-enrollment-dispatch.php").read_text()
OAUTH = (ROOT / "includes/class-mad4b-scp-oauth-resource-bridge.php").read_text()
LOCAL_OAUTH = (ROOT / "includes/class-mad4b-scp-local-oauth-server.php").read_text()
SERVERS = (ROOT / "includes/class-mad4b-scp-servers.php").read_text()
PLUGIN = (ROOT / "mad4b-site-control-plane.php").read_text()

required_gates = [
    "const CONTRACT = 'mad4b.governed-runtime-gates.v1';",
    "const OPTION = 'mad4b_scp_governed_runtime_gates_v1';",
    "const STATUS_ABILITY = 'mad4b/runtime-gates-status';",
    "const PLAN_ABILITY = 'mad4b/runtime-gates-plan';",
    "const HANDSHAKE_ABILITY = 'mad4b/runtime-gates-handshake';",
    "const APPLY_ABILITY = 'mad4b/runtime-gates-apply';",
    "ENABLE GOVERNED HIGH RISK RUNTIME GATES",
    "UPDATE GOVERNED RUNTIME GATES",
    "'raw_sql_breakglass_enabled'",
    "'production_mutation_enabled'",
    "'production_auto_enable'",
    "'database_authoritative' => true",
    "'legacy_constant_authoritative' => false",
    "expected_plan_sha256",
    "expected_policy_revision",
    "expected_policy_digest",
    "expected_site_uuid",
    "expected_profile_revision",
    "expected_profile_digest",
    "expected_source_commit_sha",
    "expected_build_fingerprint",
    "production_environment_required",
    "production_profile_write_confirmation_required",
    "production_breakglass_requires_mutation",
    "MAD4B_SCP_Audit::record( 'mad4b/runtime-gates-applied'",
    "self::restore( $before_exists, $before )",
    "MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE",
    "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID",
]
for marker in required_gates:
    if marker not in GATES:
        raise SystemExit("missing governed runtime gate invariant: " + marker)

if "MAD4B_MCP_BREAKGLASS_ENABLED" in GATES:
    raise SystemExit("database-backed runtime gate authority must not read the legacy raw-SQL constant")

for marker in [
    "MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled()",
    "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()",
]:
    if marker not in POLICY:
        raise SystemExit("policy hot path is not database-backed: " + marker)

for marker in [
    "MAD4B_SCP_Governed_Runtime_Gates::production_mutation_enabled()",
    "MAD4B_SCP_Governed_Runtime_Gates::production_auto_enable()",
    "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()",
    "production_mutation_gate_disabled",
    "'configuration_source'] = 'governed_runtime_gates'",
]:
    if marker not in WRITE:
        raise SystemExit("write authority runtime-gate projection missing: " + marker)

for body, name in [(POLICY, "policy"), (FULL, "full authority"), (ENROLLMENT, "enrollment dispatch")]:
    if "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()" not in body:
        raise SystemExit(name + " still bypasses database-backed raw-SQL gate")
    for forbidden_use in [
        "defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' )",
        'defined( "MAD4B_MCP_BREAKGLASS_ENABLED" )',
        "constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' )",
        'constant( "MAD4B_MCP_BREAKGLASS_ENABLED" )',
    ]:
        if forbidden_use in body:
            raise SystemExit(name + " still reads the legacy hardcoded raw-SQL authority gate")

for marker in [
    "MAD4B_SCP_Governed_Runtime_Gates::chatgpt_step_up_tools()",
    "MAD4B_SCP_Governed_Runtime_Gates::chatgpt_catalog_read_tools()",
    "MAD4B_SCP_Governed_Runtime_Gates::chatgpt_read_tools()",
    "MAD4B_SCP_Governed_Runtime_Gates::chatgpt_step_up_tools()",
    "$fallback_candidates = array_merge( $core, $runtime_gate_step_up )",
]:
    if marker not in SERVERS:
        raise SystemExit("ChatGPT runtime-gate projection missing: " + marker)

if "Disabled unless explicitly enabled in wp-config.php." in SERVERS:
    raise SystemExit("Breakglass server description still declares wp-config.php as authority")

for marker in [
    "self::production_approved()",
    "MAD4B_SCP_Governed_Runtime_Gates::chatgpt_step_up_tools()",
    "MAD4B_SCP_Staging_OAuth_Autoconfig::production_profile_enabled()",
    "MAD4B_SCP_Portable_Readonly_Connection::effective()",
]:
    if marker not in OAUTH:
        raise SystemExit("OAuth resource bridge is not database/profile-backed: " + marker)

for marker in [
    "MAD4B_SCP_Staging_OAuth_Autoconfig::production_profile_enabled()",
    "MAD4B_SCP_Portable_Readonly_Connection::effective()",
]:
    if marker not in LOCAL_OAUTH:
        raise SystemExit("Local OAuth Production approval lost a governed read-only authority source: " + marker)

for forbidden in [
    "MAD4B_MCP_OAUTH_PRODUCTION_APPROVED",
]:
    if forbidden in OAUTH:
        raise SystemExit("OAuth Production approval still depends on hardcoded constant: " + forbidden)

for forbidden in [
    "MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED",
]:
    if forbidden in LOCAL_OAUTH:
        raise SystemExit("Local OAuth Production approval still depends on hardcoded constant: " + forbidden)

for marker in [
    "MAD4B_SCP_Governed_Runtime_Gates::boot();",
    "MAD4B_SCP_Governed_Runtime_Gates::bootstrap_runtime();",
]:
    if marker not in PLUGIN:
        raise SystemExit("runtime gate boot wiring missing: " + marker)

print("mad4b.governed-runtime-gates.v1: PASS")
