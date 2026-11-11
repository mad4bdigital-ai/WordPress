#!/usr/bin/env python3
"""Non-mutating regression check for native WordPress Production authority parity.

A remote MCP bearer (even one mapped to an enrolled WP user) must never
inherit the normal WordPress cookie / explicit WP-CLI --user authority path.
No permanent test fixtures, administrative grants or global flags are set.
"""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
policy = (root / "includes/class-mad4b-scp-policy.php").read_text()
commit_guard = (root / "includes/class-mad4b-scp-execution-commit-guard.php").read_text()
delegation = (root / "includes/class-mad4b-scp-delegation-mode.php").read_text()
runtime_gates = (root / "includes/class-mad4b-scp-governed-runtime-gates.php").read_text()
full = (root / "includes/class-mad4b-scp-full-staging-authority.php").read_text()
staging = (root / "includes/class-mad4b-scp-staging-write-planning-guard.php").read_text()

checks = {
    "production_native_principal_exception": "native_production_candidate" in policy,
    "hard_kill_switch_preserved": "empty( $gate['explicit_kill_switch'] )" in policy,
    "profile_write_required": "'site_profile_governed_write_ready'" in policy,
    "enrolled_origin_required": "MAD4B_SCP_Site_Profile::origin_enrolled()" in policy,
    "governed_site_write_required_for_native_user": "MAD4B_SCP_Site_Profile::governed_write_ready()" in policy,
    "native_wordpress_principal_verified": "MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity )" in policy,
    "wordpress_admin_cap_required": "user_can( $native_user, 'manage_options' )" in policy,
    "remote_mcp_identity_cannot_borrow_native_exception": "if ( empty( $gate['effective'] ) ) return false;" in policy and "MAD4B_SCP_Delegation_Mode::verified_wordpress_user( $identity )" in policy,
    "independent_nhi_requires_remote_gate": "if ( empty( $gate['effective'] ) ) return false;" in policy,
    "wordpress_cookie_verified": "wp_validate_auth_cookie( '', 'logged_in' )" in delegation,
    "wordpress_rest_nonce_verified": "wp_verify_nonce( $nonce, 'wp_rest' )" in delegation,
    "explicit_wp_cli_user_verified": "WP_CLI::get_config( 'user' )" in delegation,
    "commit_guard_actor_aware": "private static function actor_mutation_gate_status()" in commit_guard,
    "commit_guard_native_production_source": "'verified_native_wordpress_production'" in commit_guard,
    "commit_guard_default_denies_unverified": "array( 'effective' => false, 'source' => 'unavailable' )" in commit_guard,
    "commit_guard_fresh_capture": commit_guard.count("self::actor_mutation_gate_status()") == 2,
    "raw_sql_only_with_database_gate": "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()" in policy,
    "breakglass_separate_from_normal_write": "function can_breakglass()" in policy and "if ( ! self::can_mutate() ) return false;" in policy,
    "remote_production_gate_not_removed": "&& $production_oauth_enabled" in runtime_gates,
    "staging_full_authority_is_special": "generic_raw_sql_breakglass_gate_enabled" in full and "MAD4B_SCP_Governed_Runtime_Gates::raw_sql_breakglass_enabled()" in full,
    "approval_plan_binds_to_governed_write": "MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-write', 'core', $input )" in staging,
}
for k, value in checks.items():
    if not value:
        raise SystemExit("FAIL " + k)
release=(root/'MAD4B-RUNTIME-BUILD.txt').read_text()
plugin=(root/'mad4b-site-control-plane.php').read_text()
readme=(root/'README.md').read_text()
versions=[
    re.search(r"(?m)^ \* Version: (0\.4\.0-rc\.\d+)$",plugin),
    re.search(r"define\( 'MAD4B_SCP_VERSION', '(0\.4\.0-rc\.\d+)' \)",plugin),
    re.search(r"(?m)^release=(0\.4\.0-rc\.\d+)$",release),
    re.search(r"Current plugin version: \*\*(0\.4\.0-rc\.\d+)\*\*\.",readme)
]
if not all(versions) or len({x.group(1) for x in versions}) != 1:
    raise SystemExit("FAIL release_version_consistency")
checks["release_version_consistency"] = True
policy_json=json.loads((root/'config/runtime-release-policy.json').read_text())
handoff=json.loads((root/'config/staging-deployment-handoff.json').read_text())
adapter_dependency=handoff["target"]["mcp_adapter"]
if policy_json.get("installed_adapter_version_gate") is not False or "target_adapter_version" in policy_json:
    raise SystemExit("FAIL hardcoded Adapter version reintroduced to runtime policy")
if adapter_dependency.get("version_gate") is not False or "required_version" in adapter_dependency:
    raise SystemExit("FAIL deployment handoff reintroduced fixed Adapter version")
if adapter_dependency.get("compatibility_source") != "dependency_manager.adapter_read_runtime_observation":
    raise SystemExit("FAIL installed adapter compatibility source missing")
if adapter_dependency.get("exact_release_target_source") != "certified_runtime_release_set.mcp_adapter":
    raise SystemExit("FAIL immutable update target source missing")
checks["adapter_capability_first_not_version_pinned"] = True
print(json.dumps({
    "state":"PASS", "contract":"mad4b.production-native-gates-regression.v1",
    "checks":len(checks), "release_version":versions[0].group(1),
    "adapter_version_gate":False,
    "grant_changes":False, "remote_production_enabled":False
}, sort_keys=True))
