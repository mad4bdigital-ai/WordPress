#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
s = (root / "includes/class-mad4b-scp-unified-agent-connection.php").read_text()
ui = (root / "includes/class-mad4b-scp-chatgpt-connection-admin-ui.php").read_text()
boot = (root / "mad4b-site-control-plane.php").read_text()
checks = {
    "bootstrap": "MAD4B_SCP_Unified_Agent_Connection::boot();" in boot,
    "entrypoint": "MAD4B_SCP_Unified_Agent_Connection::render();" in ui,
    "agent_registry": "MAD4B_SCP_Agent_Registry::get_agent_by_public_id" in s and "MAD4B_SCP_Agent_Registry::create_agent(" in s,
    "just_in_time": "public static function activate()" in s and "check_admin_referer( 'mad4b_unified_agent_activate' )" in s,
    "explicit_consent": all(x in s for x in ("approve_connection", "approve_password")),
    "auth_modes": all("'" + x + "'" in s for x in ("oauth","both","application_password")),
    "app_password": "WP_Application_Passwords::create_new_application_password( $user->ID" in s,
    "revocation": "WP_Application_Passwords::delete_application_password" in s,
    "external_vault": "MAD4B_AGENT_CONNECTION_VAULT_DIR" in s and "realpath( ABSPATH )" in s and "fopen( $file, 'x' )" in s,
    "no_password_echo": "echo $result[0]" not in s and "echo $record['password']" not in s,
    "runtime_policy": "MAD4B_SCP_Site_Profile::origin_enrolled()" in s and "can_connect_user( get_current_user_id() )" in s,
    "exact_read_only": "array( 'mad4b/site-info', 'mad4b/site-profile-status' )" in s and "'write_grants_added' => 0" in s,
    "receipt": "'external_session_verified' => false" in s,
    "ai_only_policy_form": "mad4b_unified_agent_review_policy" in s and "name=\"review_mode\"" in s,
    "separate_review_consent": "confirm_review_delegation" in s and "mad4b_unified_review_consent_required" in s,
    "authority_reuse": "MAD4B_SCP_Context_Authority::set_review_policy(" in s and "MAD4B_SCP_Context_Authority::ai_review_policy_status()" in s,
    "canonical_staging_agent": "MAD4B_SCP_Site_Profile::agent_slug()" in s and "mad4b_unified_review_staging_only" in s,
    "no_review_auto_grant": "'write_grants_added' => 0" in s and "MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-write'" not in s,
}
for key, ok in checks.items():
    if not ok: raise SystemExit("UNIFIED_CONNECTION_CONTRACT_FAIL:" + key)
import subprocess
fixture = root / "tests/unified-agent-connection-runtime.php"
for command in (["php", "-l", str(fixture)], ["php", str(fixture)]):
    result = subprocess.run(command, cwd=root, text=True, capture_output=True, timeout=60, check=False)
    if result.returncode or (len(command) == 2 and "UNIFIED_CONNECTION_RUNTIME: PASS" not in result.stdout):
        raise SystemExit("UNIFIED_CONNECTION_RUNTIME_FAIL\n" + result.stdout + result.stderr)
print("UNIFIED_CONNECTION_CONTRACT: PASS", len(checks))
