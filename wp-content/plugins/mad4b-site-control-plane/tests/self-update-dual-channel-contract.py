#!/usr/bin/env python3
from pathlib import Path
import subprocess

root = Path("wp-content/plugins/mad4b-site-control-plane")
self_update = (root / "includes" / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
grants = (root / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation.php").read_text(encoding="utf-8")
authority = (root / "includes" / "class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
authorization = (root / "includes" / "class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
commit_guard = (root / "includes" / "class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
governance = (root / "includes" / "class-mad4b-scp-governance-abilities.php").read_text(encoding="utf-8")
bootstrap = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
readback_runtime = (root / "tests" / "self-update-readback-runtime.php").read_text(encoding="utf-8")

required_self_update = [
    "final class MAD4B_SCP_Self_Update",
    "mad4b.control-plane-self-update.v1",
    "mad4b/control-plane-update-status",
    "mad4b/control-plane-upload-plan",
    "mad4b/control-plane-upload-apply",
    "mad4b/control-plane-native-plan",
    "mad4b/control-plane-native-apply",
    "mad4b.control-plane-native-plan.v1",
    "mad4b.control-plane-native-apply.v1",
    "governed_native_release_pull",
    "caller_package_bytes_allowed",
    "target_derived_from_release_manifest",
    "mad4b_self_update_native_release_drift",
    "mad4b_self_update_native_download_failed",
    "published_from_master",
    "release_root_trust_verified",
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
    "installed_plugin_version_from_disk",
    "installed_provenance",
    "control_plane_version",
    "mad4b_self_update_installed_provenance_version_mismatch",
    "mad4b_self_update_disk_provenance_version_mismatch",
]
for marker in required_self_update:
    if marker not in self_update:
        raise SystemExit(f"missing dual-channel self-update invariant: {marker}")

if "'release_channel_bound' => true" not in self_update:
    raise SystemExit("governed file upload is not bound to the repository release channel")
if "target_not_current_governed_release:" not in self_update:
    raise SystemExit("governed file upload does not reject non-release package identities")

# Package bytes must never enter authorization/approval canonical payloads.
for marker in (
    "add_filter( 'mad4b_scp_authorization_input', array( __CLASS__, 'authorization_input' ), 20, 3 )",
    "unset( $clean['package_base64'] )",
    "$clean['package_transport'] = 'bounded_base64_zip'",
):
    if marker not in self_update:
        raise SystemExit(f"self-update authorization sanitizer invariant missing: {marker}")

if "public static function authorization_input( $input, $ability_name = '' )" not in authority:
    raise SystemExit("central governed authorization input is not ability-aware")
if "apply_filters(" not in authority or "'mad4b_scp_authorization_input'" not in authority:
    raise SystemExit("central governed authorization input filter missing")
if authorization.count("authorization_input( $input, $ability_name )") < 2:
    raise SystemExit("central authorization and execution claim do not share the ability-aware sanitized input")
if "authorization_input( $input, $ability )" not in commit_guard:
    raise SystemExit("execution commit guard does not use the same ability-aware sanitized input")
if "authorization_input( $operation_input, $ability_name )" not in governance:
    raise SystemExit("approval planning does not use the same ability-aware sanitized input")
if "target_fingerprint( $ability_name, $provider, $authorization_input" not in governance:
    raise SystemExit("approval target fingerprint is not computed from sanitized metadata")
if "$agent['public_id'], $server_id, $ability_name, $provider, $target, $authorization_input, $ticket_class" not in governance:
    raise SystemExit("approval ticket payload is not bound to sanitized metadata")

# Governed native release pull is manifest-derived only. The caller may supply
# neither target identity, URL/path, nor package bytes.
native_plan_schema = self_update.split("private static function native_plan_schema()", 1)[1].split("private static function native_apply_schema()", 1)[0]
native_apply_schema = self_update.split("private static function native_apply_schema()", 1)[1].split("private static function plan_schema()", 1)[0]
for forbidden in ("url", "path", "package_url", "package_path", "package_base64", "version", "source_commit_sha", "archive_sha256", "build_fingerprint", "package_manifest_digest", "size_bytes"):
    if f"'{forbidden}' =>" in native_plan_schema or f"'{forbidden}' =>" in native_apply_schema:
        raise SystemExit(f"caller-controlled native release field leaked into schema: {forbidden}")
for marker in (
    "self::fetch_manifest( true )",
    "download_url( $manifest['package_url'], 30 )",
    "self::verify_archive( $tmp, $manifest )",
    "self::apply_verified_archive( $tmp, $manifest, 'governed_native_release_pull', $expected )",
):
    if marker not in self_update:
        raise SystemExit(f"governed native release pull invariant missing: {marker}")

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

# Uploaded archive staging must inherit MAD4B protected-storage policy, never generic web temp fallback.
if "MAD4B_SCP_Policy::prepare_backup_root()" not in self_update:
    raise SystemExit("uploaded archive staging is not rooted in protected MAD4B storage")
if "get_temp_dir()" in self_update:
    raise SystemExit("self-update upload staging must not fall back to generic WordPress temp storage")

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

# Same-request replacement must verify the new on-disk plugin header and canonical
# control_plane_version, never the stale MAD4B_SCP_VERSION loaded before replacement.
if "get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' )" not in self_update:
    raise SystemExit("self-update readback does not read the installed plugin header from disk")
if "isset( $provenance['control_plane_version'] )" not in self_update:
    raise SystemExit("self-update readback is not bound to canonical provenance control_plane_version")
if "if ( ! empty( $row['version'] ) ) $identity['version']" in self_update:
    raise SystemExit("legacy provenance version override can reintroduce stale same-request readback")
for marker in (
    "define( 'MAD4B_SCP_VERSION', '0.4.0-rc.63' )",
    "* Version: 0.4.0-rc.64",
    "'control_plane_version' => '0.4.0-rc.64'",
    "mad4b_self_update_installed_provenance_version_mismatch",
):
    if marker not in readback_runtime:
        raise SystemExit(f"same-request self-update runtime regression fixture missing: {marker}")
subprocess.run(["php", str(root / "tests" / "self-update-readback-runtime.php")], check=True)

# Read-side discovery/status must be projected to both normal read and ChatGPT catalogs.
for marker in (
    "'mad4b/control-plane-update-status'",
    "'mad4b/control-plane-upload-plan'",
):
    if marker not in servers:
        raise SystemExit(f"self-update read projection invariant missing: {marker}")
if servers.count("'mad4b/control-plane-update-status'") < 2:
    raise SystemExit("Control Plane update status is not projected to both read and ChatGPT catalogs")
if servers.count("'mad4b/control-plane-upload-plan'") < 2:
    raise SystemExit("Control Plane upload plan is not projected to both read and ChatGPT catalogs")
if servers.count("'mad4b/control-plane-native-plan'") < 2:
    raise SystemExit("Control Plane native plan is not projected to both read and ChatGPT catalogs")

# Exact governed-write execution must be converged into the canonical Staging NHI inventory.
if "'mad4b/control-plane-upload-apply' => 'core'" not in grants:
    raise SystemExit("Control Plane upload apply is not eligible for exact Staging NHI grant reconciliation")
if "'mad4b/control-plane-native-apply' => 'core'" not in grants:
    raise SystemExit("Control Plane native apply is not eligible for exact Staging NHI grant reconciliation")

# Ability must be present on the normal governed write projection.
for marker in (
    "'mad4b/control-plane-upload-apply'",
    "'mad4b/control-plane-native-apply'",
    "'mad4b/plugin-package-apply'",
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

# Repository-root publishing is deliberately governed in a separate root-governance PR.
# This plugin contract only trusts the fixed repository-owned Release manifest.
for marker in (
    "const RELEASE_TAG           = 'mad4b-site-control-plane-update-channel';",
    "const MANIFEST_URL          = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update.json';",
    "'release_verdict_success'",
    "'release_channel_bound' => true",
):
    if marker not in self_update:
        raise SystemExit(f"fixed governed release-channel invariant missing: {marker}")

print("mad4b.control-plane-self-update.v1 multi-channel contract: PASS")
