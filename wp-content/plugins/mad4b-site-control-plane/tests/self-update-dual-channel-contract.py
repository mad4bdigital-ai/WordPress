#!/usr/bin/env python3
from pathlib import Path

root = Path("wp-content/plugins/mad4b-site-control-plane")
self_update = (root / "includes" / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
bootstrap = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
workflow = Path(".github/workflows/mad4b-control-plane-update-channel.yml").read_text(encoding="utf-8")

required_self_update = [
    "final class MAD4B_SCP_Self_Update",
    "mad4b.control-plane-self-update.v1",
    "mad4b/control-plane-update-status",
    "mad4b/control-plane-upload-plan",
    "mad4b/control-plane-upload-apply",
    "plugin_action_links_",
    "after_plugin_row_",
    "admin_post_mad4b_control_plane_native_update",
    "handle_native_update",
    "download_url",
    "wp_safe_redirect",
    "mad4b-site-control-plane-update-channel",
    "mad4b-site-control-plane-update.json",
    "release_verdict_success",
    "MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED",
    "package_base64",
    "expected_plan_sha256",
    "MAD4B_SCP_Authorization::authorize_mutation",
    "MAD4B_SCP_Policy::prepare_backup_root()",
    "new Plugin_Upgrader",
    "overwrite_package",
    "base64_decode( $encoded, true )",
    "ZipArchive",
    "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json",
    "mad4b_self_update_zip_symlink_forbidden",
    "rollback_on_failed_readback",
    "release_channel_bound",
    "target_not_current_governed_release",
    "MAD4B_SCP_Site_Profile::environment_allowed( array( 'production' ), 'write' )",
    "caller_url_allowed",
    "caller_path_allowed",
]
for marker in required_self_update:
    if marker not in self_update:
        raise SystemExit(f"missing dual-channel self-update invariant: {marker}")

if "'release_channel_bound' => true" not in self_update:
    raise SystemExit("governed file upload is not bound to the repository release channel")
if "target_not_current_governed_release:" not in self_update:
    raise SystemExit("governed file upload does not reject non-release package identities")

# Remote upload remains bounded file input only; no caller URL/path input is accepted.
plan_schema = self_update.split("private static function plan_schema()", 1)[1].split("private static function apply_schema()", 1)[0]
for forbidden in ("url", "path", "package_url", "package_path"):
    if f"'{forbidden}' =>" in plan_schema:
        raise SystemExit(f"caller-controlled {forbidden} leaked into upload plan schema")

apply_schema = self_update.split("private static function apply_schema()", 1)[1].split("private static function digest(", 1)[0]
if "package_base64" not in apply_schema or "expected_plan_sha256" not in apply_schema:
    raise SystemExit("upload apply schema is not exact-plan plus bounded-file based")

# Remote upload is Staging-only and Production native updates require an explicit constant.
if "return 'staging' === $environment" not in self_update:
    raise SystemExit("governed upload is not explicitly Staging-bound")
if "defined( 'MAD4B_SCP_PRODUCTION_SELF_UPDATE_ENABLED' )" not in self_update:
    raise SystemExit("Production native self-update opt-in gate missing")
for forbidden_hook in (
    "pre_set_site_transient_update_plugins",
    "site_transient_update_plugins",
    "auto_update_plugin",
    "upgrader_pre_download",
    "upgrader_pre_install",
    "upgrader_post_install",
):
    if forbidden_hook in self_update:
        raise SystemExit(f"self-update must not alter WordPress updater routine: {forbidden_hook}")
if "'automatic_update_enabled' => false" not in self_update:
    raise SystemExit("automatic Control Plane update policy is not fail-closed")

# Exact archive verification must happen before either channel mutates installed bytes.
verify = self_update.split("private static function verify_archive(", 1)[1].split("private static function installed_identity(", 1)[0]
for marker in (
    "hash_file( 'sha256', $path )",
    "mad4b-site-control-plane/",
    "MAD4B-BUILD-PROVENANCE.json",
    "source_commit_sha",
    "build_fingerprint",
    "package_manifest_digest",
):
    if marker not in verify:
        raise SystemExit(f"archive verifier invariant missing: {marker}")

# Common rollback/readback semantics must be used by the managed upload path.
managed_apply = self_update.split("private static function apply_verified_archive(", 1)[1].split("private static function fetch_manifest(", 1)[0]
for marker in ("backup_current()", "verify_installed_identity", "rollback(", "restore_activation_state"):
    if marker not in managed_apply:
        raise SystemExit(f"managed upload rollback/readback invariant missing: {marker}")

# Ability must be present on the normal governed write projection.
for marker in (
    "'mad4b/control-plane-upload-apply'",
    "'mad4b/plugin-package-apply', 'mad4b/control-plane-upload-apply'",
):
    if marker not in servers:
        raise SystemExit(f"self-update write projection invariant missing: {marker}")

# Bootstrap must load and boot the coordinator.
for marker in (
    "class-mad4b-scp-self-update.php",
    "MAD4B_SCP_Self_Update::boot();",
):
    if marker not in bootstrap:
        raise SystemExit(f"self-update bootstrap invariant missing: {marker}")

# Publishing is fail-closed on exact successful master Release Verdict and exact package artifact.
required_workflow = [
    "workflow_run:",
    "MAD4B Release Verdict",
    "github.event.workflow_run.conclusion == 'success'",
    "github.event.workflow_run.head_branch == 'master'",
    'test "$current_master" = "$SOURCE_SHA"',
    "mad4b-control-plane-package.yml",
    "mad4b-site-control-plane-general-distribution-kit-$SOURCE_SHA",
    "RELEASE-ROOT-TRUST-VERIFICATION.json",
    "release_verdict_success",
    "mad4b-site-control-plane-$SOURCE_SHA.zip",
    "mad4b-site-control-plane-update.json",
    "--clobber",
]
for marker in required_workflow:
    if marker not in workflow:
        raise SystemExit(f"update-channel publishing invariant missing: {marker}")

# The update-channel workflow may publish release assets only; it may not deploy to a site.
for forbidden in ("ssh ", "scp ", "rsync ", "wp plugin update", "wp plugin install", "curl -X POST"):
    if forbidden in workflow:
        raise SystemExit(f"update-channel workflow unexpectedly deploys or shells: {forbidden}")

print("mad4b.control-plane-self-update.v1 dual-channel contract: PASS")
