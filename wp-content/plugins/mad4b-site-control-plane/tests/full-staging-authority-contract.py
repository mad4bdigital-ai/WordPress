#!/usr/bin/env python3
from pathlib import Path
import runpy
import re

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
    "mad4b/full-staging-authority-handshake",
    "mad4b/full-staging-authority-apply",
    "ENABLE FULL STAGING AUTHORITY",
    "'production_allowed' => false",
    "'generic_raw_sql_breakglass_requested' => false",
    "MAD4B_SCP_Site_Profile_Write_Enablement::enable_write",
    "MAD4B_SCP_Developer_Authority::apply",
    "MAD4B_SCP_Developer_Authority::breakglass_apply",
    "MAD4B_SCP_Staging_Write_Authority::reconcile",
    "MAD4B_SCP_Staging_Write_Candidate_Binding::bind",
    "audit_binding_snapshot",
    "reviewed_previous_binding",
    "pre_bind_persisted_binding",
    "candidate_binding_lineage",
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
    "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()",
    "mad4b_full_authority_raw_sql_breakglass_denied",
    "public static function can_apply( $input = null )",
    "AUTHORITY_STEP_UP_SCOPE",
    "verified_bearer_has_scope",
    "verified_bearer_client_is",
    "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID",
    "mad4b_full_authority_step_up_scope_required",
    "mad4b_full_authority_chatgpt_client_required",
    "unreviewed_stale_write_authority",
    "'unreviewed_stale_allow_grants_count'",
    "'write_explicit_deny'",
    "'write_provider_unmounted'",
    "'nonreconcilable_write_drift' => $nonreconcilable_write_drift",
    "in_array( $reason, array( 'explicit_deny', 'write_provider_unmounted' ), true )",
    "'write_subject_preflight_blocked'",
    "'write_subject_preflight_blockers' => $write_subject_preflight_blockers",
    "'explicit_mutation_disabled'",
    "MAD4B_SCP_Staging_Write_Authority::bootstrap()",
    "write_runtime_gate_not_ready",
    "mad4b_full_authority_write_runtime_gate_not_ready",
]
for marker in required:
    assert marker in full, marker

for marker in (
    "private static function developer_execution_projection( array $developer_status )",
    "private static function developer_host_recovery( array $blockers )",
    "private static function operational_readiness( $write_ready, $normal_ready, $breakglass_ready, array $write_blockers, array $developer_execution )",
    "'authority_ready' => $normal_ready",
    "'ready_semantics' => 'authority_and_runtime_flags_only'",
    "'process_backend_ready' => $process_ready",
    "'normal_no_network_execution_ready' => $normal_no_network_ready",
    "'execution_ready' => $process_ready && $normal_no_network_ready",
    "'developer_authority_ready' => $normal_ready",
    "'developer_breakglass_authority_ready' => $breakglass_ready",
    "'developer_execution' => $developer_execution",
    "'ready_semantics' => 'authority_ready_legacy_compatibility'",
    "'authority_ready' => $authority_ready",
    "'operational_ready' => ! empty( $operational['ready'] )",
    "'operational_blockers' => isset( $operational['blockers'] ) ? $operational['blockers'] : array()",
    "'operational_client_action' => isset( $operational['client_action'] ) ? $operational['client_action'] : ''",
):
    assert marker in full, marker
developer_projection = full.split("private static function developer_execution_projection", 1)[1].split("private static function compact_string_list", 1)[0]
for source in ("process_backend_blockers", "normal_no_network_execution_blockers"):
    assert source in developer_projection, source
assert "ready_to_apply" not in developer_projection, "host execution projection must remain diagnostic and must not silently redefine authority apply eligibility"

fixable = full.split("'fixable_write_drift' => array(", 1)[1].split("),", 1)[0]
assert "'unreviewed_stale_allow_grants_count'" not in fixable, "unreviewed stale authority must never be classified as auto-fixable"
for marker in (
    "'nonreconcilable_write_drift_counts' => array(",
    "'unreviewed_stale_allow_grants_count' => $unreviewed_stale_count",
    "'exact_grants_missing_count' => $nonreconcilable_missing_count",
    "'exact_grants_missing_count' => $reconcilable_missing_count",
    "'stale_allow_grants_count' => $reviewed_stale_count",
):
    assert marker in full, marker

plan_body = full.split("public static function plan()", 1)[1].split("public static function apply( $input )", 1)[0]
assert "$reviewed_stale_count = $stale_allow_total;" in plan_body, "reviewed stale grants must project directly from the reviewed stale count"
assert "$stale_allow_total - $unreviewed_stale_count" not in plan_body, "unreviewed stale authority is a separate collection and must not be subtracted twice"

reviewed_lineage_capture = full.index("$reviewed_previous_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot")
developer_apply = full.index("MAD4B_SCP_Developer_Authority::apply")
breakglass_apply = full.index("MAD4B_SCP_Developer_Authority::breakglass_apply")
write_reconcile = full.index("MAD4B_SCP_Staging_Write_Authority::reconcile")
pre_bind_capture = full.index("$pre_bind_persisted_binding = MAD4B_SCP_Staging_Write_Candidate_Binding::audit_binding_snapshot")
candidate_bind = full.index("MAD4B_SCP_Staging_Write_Candidate_Binding::bind")
assert reviewed_lineage_capture < developer_apply < breakglass_apply < write_reconcile < pre_bind_capture < candidate_bind
assert "), $reviewed_previous_binding );" in full[candidate_bind:candidate_bind + 2500], "candidate bind did not receive immutable reviewed lineage"
assert "'reviewed_previous_binding' => $reviewed_previous_binding" in full
assert "'pre_bind_persisted_binding' => $pre_bind_persisted_binding" in full
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
assert "return array( self::HANDSHAKE_ABILITY );" in full
assert "public static function chatgpt_catalog_read_tools()" in full
assert "return array( self::STATUS_ABILITY, self::PLAN_ABILITY, self::HANDSHAKE_ABILITY );" in full
assert "public static function handshake()" in full
for marker in [
    "mad4b.full-staging-authority-handshake.v1",
    "MAD4B_SCP_Connector_Resilience::generation_fenced_compact_read",
    "mad4b_full_authority_handshake_resilience_unavailable",
    "8192",
    "'deep_status_direct_projection' => false",
    "'deep_plan_direct_projection' => false",
    "'deep_reads_available_via_governed_dispatch' => true",
    "'exact_apply' => array(",
    "'client_action' => ! empty( $plan['ready_to_apply'] ) ? 'apply_exact_handshake' : 'repair_blockers_then_request_fresh_handshake'",
    "'ready_to_apply_semantics' => 'authority_convergence_only'",
    "'authority_ready' => $authority_ready",
    "'operational_ready' => ! empty( $operational['ready'] )",
    "'operational_blockers' => isset( $operational['blockers'] ) ? $operational['blockers'] : array()",
    "'operational_client_action' => isset( $operational['client_action'] ) ? $operational['client_action'] : ''",
]:
    assert marker in full, marker
handshake_body = full.split("public static function handshake()", 1)[1].split("private static function compact_string_list", 1)[0]
for marker in (
    "$write_checkpoint_ready = ! empty( $write['effective_ready'] );",
    "$write_grant_snapshot_ready = ! empty( $write['current_ready'] );",
    "$write_ready = $write_checkpoint_ready && $write_grant_snapshot_ready;",
    "'write_ready' => $write_ready",
    "'write_checkpoint_ready' => $write_checkpoint_ready",
    "'write_grants_ready' => $write_grant_snapshot_ready",
    "'write_current_grant_snapshot_ready' => $write_grant_snapshot_ready",
    "'write_reconciliation_required' => ! $write_ready",
    "'write_current_readiness_blockers'",
):
    assert marker in handshake_body, marker
assert "MAD4B_SCP_Staging_Write_Authority::effective()" not in handshake_body, "compact handshake must consume the reviewed write-plan snapshot instead of recomputing checkpoint-only readiness"
for marker in (
    "$operational = self::operational_readiness( $write_ready, $normal_ready, $breakglass_ready, $write_blockers, $developer_execution );",
    "'ready_to_apply_semantics' => 'authority_convergence_only'",
    "'operational_ready' => ! empty( $operational['ready'] )",
    "'operational_state' => isset( $operational['state'] ) ? (string) $operational['state'] : 'operationally_blocked'",
    "'lane_readiness' => isset( $operational['lane_readiness'] ) ? $operational['lane_readiness'] : array()",
    "'available_lanes' => isset( $operational['available_lanes'] ) ? $operational['available_lanes'] : array()",
    "'blocked_lanes' => isset( $operational['blocked_lanes'] ) ? $operational['blocked_lanes'] : array()",
    "'degraded_mode' => ! empty( $operational['degraded_mode'] )",
):
    assert marker in handshake_body, marker
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

status_body = full.split("public static function status()", 1)[1].split("public static function handshake()", 1)[0]
for marker in [
    "$write_checkpoint_ready =",
    "$write_grant_snapshot_ready = is_array( $write_plan ) && ! empty( $write_plan['current_ready'] )",
    "! empty( $write_plan['effective_ready'] )",
    "'checkpoint_ready' => $write_checkpoint_ready",
    "'current_grant_snapshot_ready' => $write_grant_snapshot_ready",
    "'current_readiness_blockers'",
]:
    assert marker in status_body, marker
assert "$write_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();" not in status_body

for marker in (
    "$operational = self::operational_readiness(",
    "'ready_semantics' => 'authority_ready_legacy_compatibility'",
    "'authority_ready' => $authority_ready",
    "'operational_ready' => ! empty( $operational['ready'] )",
    "'operational_state' => isset( $operational['state'] ) ? (string) $operational['state'] : 'operationally_blocked'",
    "'lane_readiness' => isset( $operational['lane_readiness'] ) ? $operational['lane_readiness'] : array()",
    "'degraded_mode' => ! empty( $operational['degraded_mode'] )",
):
    assert marker in status_body, marker

host_recovery_body = full.split("private static function developer_host_recovery", 1)[1].split("private static function operational_readiness", 1)[0]
for marker in (
    "'resource_limiter_unavailable'",
    "'install_or_configure_prlimit'",
    "'network_isolation_unavailable'",
    "'install_or_configure_bwrap_or_unshare'",
    "'network_isolation_backend_uncertified'",
    "'replace_uncertified_network_sandbox'",
    "'proc_open_unavailable'",
    "'enable_proc_open_in_host_php_policy'",
    "'root_execution_denied'",
    "'run_php_worker_as_non_root'",
    "'accepted_resource_limiter' => 'prlimit'",
    "'MAD4B_MCP_DEVELOPER_PRLIMIT_BIN'",
    "'accepted_network_isolation_backends' => array( 'bubblewrap', 'unshare-net' )",
    "'MAD4B_MCP_DEVELOPER_NETWORK_SANDBOX_BIN'",
    "'wordpress_self_repair_allowed' => false",
    "'automatic_install_allowed' => false",
    "'weaker_unsandboxed_fallback_allowed' => false",
    "'production_mutation_allowed' => false",
):
    assert marker in host_recovery_body, marker

operational_body = full.split("private static function operational_readiness", 1)[1].split("private static function compact_string_list", 1)[0]
for marker in (
    "'write_authority_not_current'",
    "'developer_authority_not_ready'",
    "'developer_breakglass_authority_not_ready'",
    "'developer_execution_not_ready'",
    "'resolve_developer_host_execution_prerequisites'",
    "'host_recovery' => self::developer_host_recovery( $blockers )",
    "'converge_authority_before_operational_use'",
    "'lane_readiness' => $lane_readiness",
    "'available_lanes' => $available_lanes",
    "'blocked_lanes' => $blocked_lanes",
    "'degraded_mode' => $degraded_mode",
    "'degraded_mode_semantics' => 'unavailable_lanes_fail_closed_available_lanes_remain_usable'",
    "'operationally_ready'",
    "'authority_convergence_required'",
    "'degraded_host_execution'",
    "'operationally_blocked'",
    "'governed_write' => (bool) $write_ready",
    "'developer' => (bool) $normal_ready && $developer_execution_ready",
    "'developer_breakglass' => (bool) $breakglass_ready && $developer_execution_ready",
    "'authorizing' => false",
    "'mutation_performed' => false",
):
    assert marker in operational_body, marker

for marker in [
    "$missing_rows = isset( $write_plan['exact_grants_missing'] )",
    "'explicit_deny'",
    "'write_provider_unmounted'",
    "$hard_blockers[] = 'explicit_deny' === $reason ? 'write_explicit_deny' : 'write_provider_unmounted';",
]:
    assert marker in plan_body, marker
assert plan_body.index("$missing_rows = isset( $write_plan['exact_grants_missing'] )") < plan_body.index("'ready_to_apply' => empty( $hard_blockers )")
assert "MAD4B_SCP_Staging_Write_Authority::reconcile()" not in plan_body
for marker in (
    "$write_subject_preflight_blockers = isset( $write_plan['subject_preflight_blockers'] )",
    "$hard_blockers[] = 'write_subject_preflight_blocked';",
    "defined( 'MAD4B_MCP_MUTATION_ENABLED' )",
    "$hard_blockers[] = 'explicit_mutation_disabled';",
):
    assert marker in plan_body, marker

apply_body = full.split("public static function apply( $input )", 1)[1].split("private static function developer_apply_input", 1)[0]
for marker in [
    "$access = self::can_apply( $input );",
    "$plan = self::plan();",
    "empty( $plan['ready_to_apply'] )",
    "self::match_expected_plan( $plan, $input )",
    "$post_apply_execution = self::developer_execution_projection",
    "$post_apply_operational = self::operational_readiness( true, true, true, array(), $post_apply_execution )",
    "'operational_ready' => ! empty( $post_apply_operational['ready'] )",
]:
    assert marker in apply_body, marker
for marker in (
    "$write_feature_was_enabled = MAD4B_SCP_Site_Profile::write_enabled();",
    "$write_runtime = MAD4B_SCP_Staging_Write_Authority::bootstrap();",
    "empty( $write_runtime['eligible'] )",
    "empty( $write_runtime['mutation_gate_configured'] )",
    "'write_runtime_gate_not_ready'",
):
    assert marker in apply_body, marker
enable_pos = apply_body.index("MAD4B_SCP_Site_Profile_Write_Enablement::enable_write")
runtime_refresh_pos = apply_body.index("$write_runtime = MAD4B_SCP_Staging_Write_Authority::bootstrap();")
developer_pos = apply_body.index("MAD4B_SCP_Developer_Authority::apply")
reconcile_pos = apply_body.index("MAD4B_SCP_Staging_Write_Authority::reconcile")
assert enable_pos < runtime_refresh_pos < developer_pos < reconcile_pos, "same-request Write gate must refresh before Developer/Write convergence"
post_enable_slice = apply_body[enable_pos:developer_pos]
assert post_enable_slice.index("$write_runtime = MAD4B_SCP_Staging_Write_Authority::bootstrap();") < post_enable_slice.index("$plan = self::plan();", post_enable_slice.index("$write_runtime = MAD4B_SCP_Staging_Write_Authority::bootstrap();")), "post-enable plan must be rebuilt after request-local Write bootstrap"
assert "MAD4B_SCP_Full_Staging_Authority::enrollment_tools()" in servers
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" in servers
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in servers
assert "private static function chatgpt_internal_enrollment_mutations()" in servers
assert "private static function chatgpt_enrollment_candidates()" in servers
assert "array_diff( $tools, MAD4B_SCP_Developer_Authority::enrollment_tools() )" in servers
assert "array_diff( $tools, MAD4B_SCP_Full_Staging_Authority::enrollment_tools() )" in servers

# Full Staging Apply is one reviewed direct step-up. The immutable metadata and
# its permission callback both require the exact ChatGPT client.
assert "'chatgpt_direct_step_up' => ! $readonly" in full
assert "'exact_chatgpt_client_required' => ! $readonly" in full
assert "verified_bearer_has_scope" in full
assert "verified_bearer_client_is" in full
assert "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID" in full

chatgpt_map = servers.split("'mad4b-chatgpt' => array_merge(", 1)[1].split("'mad4b-enrollment' =>", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" not in chatgpt_map
reviewed_helper = servers.split("public static function chatgpt_reviewed_direct_step_up_tools()", 1)[1].split("public static function is_chatgpt_direct_step_up_tool", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::APPLY_ABILITY" in reviewed_helper
assert "MAD4B_SCP_Self_Update::BOOTSTRAP_APPLY_ABILITY" in reviewed_helper
assert "MAD4B_SCP_Governed_Runtime_Gates::APPLY_ABILITY" in reviewed_helper

chatgpt_base = servers.split("public static function chatgpt_base_tools()", 1)[1].split("public static function chatgpt_tools()", 1)[0]
chatgpt_tools = servers.split("public static function chatgpt_tools()", 1)[1].split("private static function chatgpt_internal_enrollment_mutations()", 1)[0]
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" in chatgpt_base
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_read_tools()" in chatgpt_base
assert "$step_up = self::chatgpt_reviewed_direct_step_up_tools();" in chatgpt_base
assert "array_merge( self::chatgpt_dispatch_transport_tools(), $step_up )" in chatgpt_base
assert "'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute'" in chatgpt_map
assert "$candidates = array_merge( $core, $bootstrap )" in chatgpt_base
assert "MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names()" in chatgpt_tools
assert "array_merge( $base, $dynamic )" in chatgpt_tools

direct_read_helper = servers.split('public static function chatgpt_direct_read_transport_tools()', 1)[1].split('public static function chatgpt_dispatch_transport_tools()', 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::HANDSHAKE_ABILITY" in direct_read_helper
assert "MAD4B_SCP_Full_Staging_Authority::PLAN_ABILITY" not in direct_read_helper
assert "MAD4B_SCP_Full_Staging_Authority::STATUS_ABILITY" not in direct_read_helper

dispatcher_helper = servers.split('public static function chatgpt_dispatch_transport_tools()', 1)[1].split('public static function chatgpt_reviewed_direct_step_up_tools()', 1)[0]
assert "'mad4b/write-execute'" in dispatcher_helper
assert "'mad4b/enrollment-execute'" in dispatcher_helper
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
]:
    assert low_level not in chatgpt_base
full_catalog = servers.split("public static function chatgpt_full_catalog_candidates()", 1)[1].split("public static function is_chatgpt_full_catalog_candidate", 1)[0]
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_catalog_read_tools()" in full_catalog
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()" in full_catalog
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()" in full_catalog
assert "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()" in full_catalog
assert full.count("self::meta( true, 'read' )") >= 3
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
assert "Read identity + ' . $environment_label . ' authority step-up" in ui
assert "mad4b:authority:step-up" in ui
assert "Deny access" in ui
assert "Generic raw-SQL Breakglass" in ui
assert "Current governed authority" in ui
assert "Full Staging Authority (Staging only)" in ui

header = re.search(r"(?mi)^\s*\*\s*Version:\s*([^\r\n]+)", plugin)
constant = re.search(r"define\(\s*'MAD4B_SCP_VERSION'\s*,\s*'([^']+)'\s*\);", plugin)
marker = re.search(r"(?mi)^release=([^\r\n]+)$", runtime_build)
assert header and constant and marker
version = header.group(1).strip()
assert re.fullmatch(r"\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?", version)
assert constant.group(1).strip() == version
assert marker.group(1).strip() == version

print("mad4b.full-staging-authority-contract.v7: PASS")

# The baseline-owned Full Staging workflow already executes this contract.
# Chain the write-only convergence contract here instead of self-modifying CI.
runpy.run_path(str(root / "tests" / "staging-write-authority-convergence-contract.py"), run_name="__main__")
