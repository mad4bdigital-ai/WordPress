from pathlib import Path

root = Path(__file__).resolve().parents[1]
gate = (root / "includes/class-mad4b-scp-oauth-subject-gate.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
boot = (root / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")

required_gate_markers = (
    "mad4b.oauth-subject-gate.v1",
    "MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS",
    "/mcp/mad4b-read",
    "iss+sub+aud+resource",
    "mad4b_oauth_subject_not_approved",
    "mad4b_oauth_subject_policy_unconfigured",
    "WWW-Authenticate",
    "fail_closed",
)
for marker in required_gate_markers:
    assert marker in gate, f"missing subject-gate marker: {marker}"

assert "class-mad4b-scp-oauth-subject-gate.php" in plugin, "subject gate must be loaded by the plugin entry point"
assert "MAD4B_SCP_OAuth_Subject_Gate::boot()" in boot, "subject gate must boot with the control plane"

for forbidden in (
    "wp_create_user(",
    "wp_insert_user(",
    "wp_update_user(",
    "update_option(",
    "add_option(",
    "delete_option(",
    "wp_remote_post(",
    "wp_remote_request(",
    "wp_delete_post(",
    "wp_insert_post(",
):
    assert forbidden not in gate, f"subject gate must not create mutation authority: {forbidden}"

print("mad4b.oauth-subject-gate.contract.v1: PASS")
