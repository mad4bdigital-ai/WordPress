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
    "developer_authority_plan_blocked",
    "developer_breakglass_plan_blocked",
    "developer_breakglass_hard_blockers",
    "mad4b_full_authority_developer_plan_blocked",
    "mad4b_full_authority_developer_breakglass_plan_blocked",
    "generic_raw_sql_breakglass_gate_enabled",
    "MAD4B_MCP_BREAKGLASS_ENABLED",
    "mad4b_full_authority_raw_sql_breakglass_denied",
    "public static function can_apply( $input = null )",
    "AUTHORITY_STEP_UP_SCOPE",
    "verified_bearer_has_scope",
    "mad4b_full_authority_step_up_scope_required",
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
assert "public static function chatgpt_read_tools()" in full
assert "return array( self::STATUS_ABILITY, self::PLAN_ABILITY );" in full
assert "public static function chatgpt_step_up_tools()" in full
step_up = full.split("public static function chatgpt_step_up_tools()", 1)[1].split("public static function register_category()", 1)[0]
for marker in [
    "MAD4B_SCP_Site_Profile::configured()",
    "MAD4B_SCP_Site_Profile::current_environment()",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    "self::generic_raw_sql_breakglass_gate_enabled()",
    "return array( self::APPLY_ABILITY );",
]:
    assert marker in step_up, marker
for forbidden in [
    "self::can_access()",
    "self::status()",
    "self::plan()",
    "ready_to_apply",
    "hard_blockers",
]:
    assert forbidden not in step_up, f"tools/list step-up projection must stay lifecycle-stable and off the full authority plan hotpath: {forbidden}"

apply_body = full.split("public static function apply( $input )", 1)[1].split("private static function developer_apply_input", 1)[0]
for marker in [
    "$access = self::can_apply( $input );",
    "$plan = self::plan();",
    "empty( $plan['ready_to_apply'] )",
    "self::match_expected_plan( $plan, $input )",
]:
    assert marker in apply_body, marker
assert "MAD4B_SCP_Full_Staging_Authority::enrollment_tools()" in servers
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in servers
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in servers
assert "private static function chatgpt_internal_enrollment_mutations()" in servers
assert "private static function chatgpt_enrollment_candidates()" in servers
assert "array_diff( $tools, MAD4B_SCP_Developer_Authority::enrollment_tools() )" in servers
assert "array_diff( $tools, MAD4B_SCP_Full_Staging_Authority::enrollment_tools() )" in servers

chatgpt_map = servers.split("'mad4b-chatgpt' => array_merge(", 1)[1].split("'mad4b-enrollment' =>", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" not in chatgpt_map
chatgpt_tools = servers.split("public static function chatgpt_tools()", 1)[1].split("private static function chatgpt_internal_enrollment_mutations()", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in chatgpt_tools
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in chatgpt_tools
assert "$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $step_up )" in chatgpt_tools
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
]:
    assert low_level not in chatgpt_tools
full_catalog = servers.split("public static function chatgpt_full_catalog_candidates()", 1)[1].split("public static function is_chatgpt_full_catalog_candidate", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in full_catalog
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in full_catalog
assert full.count("self::meta( true, 'read' )") >= 2
assert "self::meta( false, 'enrollment' )" in full
assert "'permission_callback' => array( __CLASS__, 'can_apply' )" in full

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
assert "Approve governed access" in ui
assert "Read identity + Staging authority step-up" in ui
assert "mad4b:authority:step-up" in ui
assert "Deny access" in ui
assert "Generic raw-SQL Breakglass" in ui
assert "Current Staging authority" in ui

assert "0.4.0-rc.59" in plugin
assert "release=0.4.0-rc.59" in runtime_build

print("mad4b.full-staging-authority-contract.v6: PASS")
