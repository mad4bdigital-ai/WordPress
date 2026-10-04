#!/usr/bin/env python3
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
inc = root / "includes"

write_only = (inc / "class-mad4b-scp-staging-write-authority-convergence.php").read_text(encoding="utf-8")
servers = (inc / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
parity = (inc / "class-mad4b-scp-remote-operation-parity.php").read_text(encoding="utf-8")
main = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
runtime_build = (root / "MAD4B-RUNTIME-BUILD.txt").read_text(encoding="utf-8")
readme = (root / "README.md").read_text(encoding="utf-8")

required = [
    "mad4b.staging-write-authority-convergence.v1",
    "mad4b/staging-write-authority-convergence-status",
    "mad4b/staging-write-authority-convergence-plan",
    "mad4b/staging-write-authority-convergence-handshake",
    "mad4b/staging-write-authority-convergence-apply",
    "ENABLE GOVERNED STAGING WRITE AUTHORITY",
    "'production_allowed' => false",
    "'developer_authority_requested' => false",
    "'developer_breakglass_requested' => false",
    "'generic_raw_sql_breakglass_requested' => false",
    "'requested_authorities' => array( 'write' )",
    "MAD4B_SCP_Site_Profile_Write_Enablement::enable_write",
    "MAD4B_SCP_Staging_Write_Authority::bootstrap()",
    "MAD4B_SCP_Staging_Write_Authority::reconcile()",
    "MAD4B_SCP_Staging_Write_Candidate_Binding::bind",
    "audit_binding_snapshot",
    "reviewed_previous_binding",
    "pre_bind_persisted_binding",
    "candidate_binding_lineage",
    "mad4b/staging-write-authority-convergence-prepared",
    "mad4b/staging-write-authority-convergence-fail-closed",
    "AUTHORITY_STEP_UP_SCOPE",
    "verified_bearer_has_scope",
    "verified_bearer_client_is",
    "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID",
    "'write_subject_preflight_blocked'",
    "'write_explicit_deny'",
    "'write_provider_unmounted'",
    "'write_feature_enable_required'",
]
for marker in required:
    assert marker in write_only, marker

for forbidden in [
    "MAD4B_SCP_Developer_Authority::apply",
    "MAD4B_SCP_Developer_Authority::breakglass_apply",
    "mad4b_scp_developer_kill_switch",
    "developer-breakglass-shell",
    "developer-breakglass-wp-eval",
    "mad4b/database-raw-query",
    "'requested_authorities' => array( 'write', 'developer'",
]:
    assert forbidden not in write_only, f"write-only convergence leaked higher authority: {forbidden}"

apply = write_only.split("public static function apply( $input )", 1)[1].split("private static function write_plan()", 1)[0]
enable = apply.index("MAD4B_SCP_Site_Profile_Write_Enablement::enable_write")
bootstrap = apply.index("MAD4B_SCP_Staging_Write_Authority::bootstrap()")
reconcile = apply.index("MAD4B_SCP_Staging_Write_Authority::reconcile()")
prepared = apply.index("mad4b/staging-write-authority-convergence-prepared")
bind = apply.index("MAD4B_SCP_Staging_Write_Candidate_Binding::bind")
assert enable < bootstrap < reconcile < prepared < bind

tail = apply[bind:]
for forbidden in [
    "MAD4B_SCP_Staging_Write_Authority::reconcile()",
    "MAD4B_SCP_Site_Profile_Write_Enablement::enable_write",
    "MAD4B_SCP_Developer_Authority",
]:
    assert forbidden not in tail, f"fallible authority mutation appears after candidate-binding commit point: {forbidden}"

fail_closed = write_only.split("private static function fail_closed", 1)[1]
assert "MAD4B_SCP_Staging_Write_Authority::fail_closed_persisted_authority" in fail_closed
assert "update_option( 'mad4b_scp_developer_kill_switch'" not in fail_closed

for marker in [
    "class-mad4b-scp-staging-write-authority-convergence.php",
    "MAD4B_SCP_Staging_Write_Authority_Convergence::enrollment_tools()",
    "MAD4B_SCP_Staging_Write_Authority_Convergence::APPLY_ABILITY",
    "MAD4B_SCP_Staging_Write_Authority_Convergence::chatgpt_catalog_read_tools()",
    "MAD4B_SCP_Staging_Write_Authority_Convergence::chatgpt_step_up_tools()",
]:
    assert marker in servers, marker

enrollment_candidates = servers.split("private static function chatgpt_enrollment_candidates()", 1)[1].split("public static function chatgpt_full_catalog_candidates()", 1)[0]
assert "MAD4B_SCP_Staging_Write_Authority_Convergence::enrollment_tools()" in enrollment_candidates, "write-only authority primitives must not fall through generic enrollment dispatch"

direct_step_up = servers.split("public static function chatgpt_reviewed_direct_step_up_tools()", 1)[1].split("public static function is_chatgpt_direct_step_up_tool", 1)[0]
assert "MAD4B_SCP_Staging_Write_Authority_Convergence::APPLY_ABILITY" in direct_step_up

direct_read = servers.split("public static function chatgpt_direct_read_transport_tools()", 1)[1].split("public static function chatgpt_dispatch_transport_tools()", 1)[0]
assert "MAD4B_SCP_Staging_Write_Authority_Convergence::chatgpt_read_tools()" not in direct_read, "write convergence read surfaces must remain behind governed dispatch to preserve the direct tool budget"
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" not in direct_read, "legacy low-level reconciliation read must not consume the dynamic projection reserve"
base_tools = servers.split("public static function chatgpt_base_tools()", 1)[1].split("public static function chatgpt_tools()", 1)[0]
assert "$write_convergence_read" not in base_tools, "write convergence handshake must not consume a second direct MCP tool slot"

for marker in [
    "'staging_write_authority_convergence' => array(",
    "'remote_ability' => 'mad4b/staging-write-authority-convergence-apply'",
    "'remote_mode' => 'exact_write_only_convergence'",
    "'production_policy' => 'deny'",
    "'human_decision_required' => true",
    "'no-breakglass'",
]:
    assert marker in parity, marker

assert "class-mad4b-scp-staging-write-authority-convergence.php" in main
assert "MAD4B_SCP_Staging_Write_Authority_Convergence::boot();" in main

header = re.search(r"^ \* Version: (0\\.4\\.0-rc\\.[0-9]+)$", main, re.MULTILINE)
constant = re.search(r"MAD4B_SCP_VERSION', '(0\\.4\\.0-rc\\.[0-9]+)'", main)
runtime = re.search(r"^release=(0\\.4\\.0-rc\\.[0-9]+)$", runtime_build, re.MULTILINE)
current = re.search(r"Current plugin version: \\*\\*(0\\.4\\.0-rc\\.[0-9]+)\\*\\*\\.", readme)
assert header and constant and runtime and current, "runtime version evidence missing"
versions = {header.group(1), constant.group(1), runtime.group(1), current.group(1)}
assert len(versions) == 1, f"runtime version evidence drift: {sorted(versions)}"
rc = int(next(iter(versions)).rsplit(".", 1)[1])
assert rc >= 91, "write-only authority convergence requires rc.91 or newer runtime line"
assert "rc.91 decoupled Staging Write authority" in readme

print("staging write authority convergence contract: PASS")
