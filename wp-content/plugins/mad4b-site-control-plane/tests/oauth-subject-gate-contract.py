from pathlib import Path

root = Path(__file__).resolve().parents[1]
gate = (root / "includes/class-mad4b-scp-oauth-subject-gate.php").read_text(encoding="utf-8")
bridge = (root / "includes/class-mad4b-scp-oauth-resource-bridge.php").read_text(encoding="utf-8")
plugin = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
boot = (root / "includes/class-mad4b-scp-plugin.php").read_text(encoding="utf-8")

required_gate_markers = (
    "mad4b.oauth-subject-gate.v2",
    "trusted_issuers",
    "allowed_subjects_for_issuer",
    "is_trusted_issuer",
    "subject_allowed",
    "resource_for_route( $route )",
    "issuer+subject+aud+resource",
    "mad4b_oauth_subject_not_approved",
    "post_signature_subject_reauthorization",
    "pre-cryptographic-deny-then-rs256-verify-and-reauthorize",
    "claims_used_before_signature' => 'deny-only'",
    "WWW-Authenticate",
    "fail_closed",
)
for marker in required_gate_markers:
    assert marker in gate, f"missing subject-gate marker: {marker}"

for marker in (
    "resource_for_route",
    "foreach ( array( 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-developer', 'mad4b-developer-breakglass' ) as $server_id )",
    "if ( '/mcp/' . $server_id === $route ) return self::resource_identifier( $server_id )",
    "resource_identifier( 'mad4b-chatgpt' )",
    "resource_identifier( 'mad4b-enrollment' )",
    "resource_identifier( 'mad4b-developer' )",
    "resource_identifier( 'mad4b-developer-breakglass' )",
    "subject_allowed( $issuer, $validated['subject'] )",
    "mad4b_oauth_subject_not_approved",
    "openssl_verify",
):
    assert marker in bridge, f"bridge must preserve exact resource and cryptographic subject binding: {marker}"

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
    "openssl_verify(",
):
    assert forbidden not in gate, f"subject gate must remain deny-only and non-authorizing: {forbidden}"

print("mad4b.oauth-subject-gate.contract.v2: PASS")
