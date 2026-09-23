#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
inc = root / "includes"

full = (inc / "class-mad4b-scp-full-staging-authority.php").read_text(encoding="utf-8")
servers = (inc / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
developer = (inc / "class-mad4b-scp-developer-runtime.php").read_text(encoding="utf-8")
developer_authority = (inc / "class-mad4b-scp-developer-authority.php").read_text(encoding="utf-8")
oauth = (inc / "class-mad4b-scp-local-oauth-server.php").read_text(encoding="utf-8")
ui = (inc / "class-mad4b-scp-local-oauth-consent-ui.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")

required = [
    "mad4b.full-staging-authority.v1",
    "mad4b/full-staging-authority-status",
    "mad4b/full-staging-authority-plan",
    "mad4b/full-staging-authority-apply",
    "ENABLE FULL STAGING AUTHORITY",
    "'production_allowed' => false",
    "'generic_raw_sql_breakglass_requested' => false",
    "MAD4B_SCP_Site_Profile_Write_Enablement::enable_write",
    "MAD4B_SCP_Developer_Authority::apply",
    "MAD4B_SCP_Developer_Authority::breakglass_apply",
    "MAD4B_SCP_Staging_Write_Authority::reconcile",
    "MAD4B_SCP_Staging_Write_Candidate_Binding::bind",
    "mad4b/full-staging-authority-fail-closed",
    "mad4b_scp_developer_kill_switch",
    "post_write_enable_plan_blocked",
    "mad4b_full_authority_post_write_enable_plan_blocked",
    "generic_raw_sql_breakglass_gate_enabled",
    "MAD4B_MCP_BREAKGLASS_ENABLED",
    "mad4b_full_authority_raw_sql_breakglass_denied",
]
for marker in required:
    assert marker in full, marker

developer_apply = full.index("MAD4B_SCP_Developer_Authority::apply")
breakglass_apply = full.index("MAD4B_SCP_Developer_Authority::breakglass_apply")
write_reconcile = full.index("MAD4B_SCP_Staging_Write_Authority::reconcile")
candidate_bind = full.index("MAD4B_SCP_Staging_Write_Candidate_Binding::bind")
assert developer_apply < breakglass_apply < write_reconcile < candidate_bind
assert "mad4b/full-staging-authority-prepared" in full
tail_after_binding = full[candidate_bind:]
assert "mad4b/full-staging-authority-prepared" not in tail_after_binding
assert "MAD4B_SCP_Developer_Authority::apply" not in tail_after_binding
assert "MAD4B_SCP_Staging_Write_Authority::reconcile" not in tail_after_binding
assert "'requested_authorities' => array( 'write', 'developer', 'developer_breakglass' )" in full
assert "'generic_raw_sql_breakglass_requested' => false" in full
assert "'mad4b/database-raw-query'" not in full

assert "class-mad4b-scp-full-staging-authority.php" in servers
assert "MAD4B_SCP_Full_Staging_Authority::enrollment_tools()" in servers
assert "array_diff( $enrollment_candidates, MAD4B_SCP_Full_Staging_Authority::enrollment_tools() )" in servers

bg_start = developer.index("public static function breakglass_flag_enabled()")
bg_end = developer.index("public static function configured_agent_public_id()", bg_start)
bg_body = developer[bg_start:bg_end]
assert "MAD4B_MCP_DEVELOPER_BREAKGLASS_ENABLED" in bg_body
assert "MAD4B_MCP_BREAKGLASS_ENABLED" not in bg_body
assert "global_breakglass_gate_disabled" not in developer_authority

assert "developer_authority_ready" in oauth
assert "developer_breakglass_authority_ready" in oauth
assert "full_staging_authority_ready" in oauth
assert "'generic_raw_sql_breakglass_included' => false" in oauth
assert "What you are approving now" in ui
assert "Approve read access" in ui
assert "Deny access" in ui
assert "Generic raw-SQL Breakglass" in ui
assert "Current Staging authority" in ui

assert "0.4.0-rc.57" in plugin
assert "release=0.4.0-rc.57" in runtime_build

print("mad4b.full-staging-authority-contract.v1: PASS")
