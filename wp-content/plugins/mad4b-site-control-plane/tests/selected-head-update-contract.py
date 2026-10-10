#!/usr/bin/env python3
"""Feature 007 optional selected-HEAD MCP update contract. Source-only gate."""
from pathlib import Path
root=Path(__file__).resolve().parents[1]
selected=(root/"includes/class-mad4b-scp-selected-head-update.php").read_text()
native=(root/"includes/class-mad4b-scp-self-update.php").read_text()
bootstrap=(root/"mad4b-site-control-plane.php").read_text()
servers=(root/"includes/class-mad4b-scp-servers.php").read_text()
fixture=(root/"tests/selected-head-update-runtime.php").read_text()
workflow=(root.parents[3]/".github/workflows/feature-007-pre-staging-hybrid-audit.yml").read_text() if (root.parents[3]/".github/workflows/feature-007-pre-staging-hybrid-audit.yml").is_file() else ""
assert "class-mad4b-scp-selected-head-update.php" in bootstrap
assert "MAD4B_SCP_Selected_Head_Update::boot();" in bootstrap
assert "public static function plan(" in selected
assert "public static function apply(" in selected
assert "MAD4B_SCP_SELECTED_HEAD_UPDATES_ENABLED" in selected
assert "MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED" in selected
assert "'default_release_channel_unchanged' => true" in selected
assert "'automatic_update' => false" in selected
assert "'production_allowed' => false" in selected
assert "'public' => false" in selected
assert "INSTALL SELECTED HEAD ON STAGING" in selected
assert "MAD4B_SCP_Staging_Source_Selector::resolve" in selected
assert "self::REPO . '/releases/download/'" in selected
assert "mad4b-site-control-plane-update-' . $sha . '.json" in selected
assert "mad4b-site-control-plane-' . $sha . '.zip" in selected
assert "staging_candidate_certified" in selected
assert "release_root_trust_verified" in selected
assert "release_verdict_run_id" in selected
assert "hash_equals( $zip_url, (string) $m['package_url'] )" in selected
assert "hash_equals( $plan['plan_sha256'], $expected )" in selected
assert "self::can_apply( $input )" in selected
assert "MAD4B_SCP_Self_Update::can_upload_apply" in selected
assert "MAD4B_SCP_Authorization::authorize_mutation" in selected
assert "MAD4B_SCP_Self_Update::upload_plan" in selected
assert "MAD4B_SCP_Self_Update::upload_apply( $upload )" in selected
assert "mad4b_selected_head_selected_head_moved" not in selected
assert "selected_head_moved" in selected
assert "'caller_url_allowed' => true" not in selected
assert "package_base64' => base64_encode( $bytes )" in selected
assert "'staging_candidate_upload'" in selected
assert "MAD4B_SCP_Staging_Source_Selector::resolve" in native
assert "self::verify_archive( $tmp, $plan['target'] )" in native
assert "self::apply_verified_archive" in native
assert "self::restore_activation_state" in native
assert "'mad4b/control-plane-selected-head-plan'" in servers
assert "'mad4b/control-plane-selected-head-apply'" in servers
assert "selected-head-update-runtime.php" in workflow
for mode in ("disabled", "normal", "master", "missing", "uncertified", "drift", "bad-archive"):
    assert mode in workflow, "Missing isolated execution of "+mode
    assert mode in fixture or mode in ("normal", "disabled")
print("PASS opt-in MCP selected-HEAD contract (no default channel changes, controlled package and rollback backend)")
