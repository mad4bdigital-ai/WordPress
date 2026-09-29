#!/usr/bin/env python3
from pathlib import Path
import json
import subprocess

root = Path("wp-content/plugins/mad4b-site-control-plane")
self_update = (root / "includes" / "class-mad4b-scp-self-update.php").read_text(encoding="utf-8")
servers = (root / "includes" / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
grants = (root / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation.php").read_text(encoding="utf-8")
authority = (root / "includes" / "class-mad4b-scp-staging-write-authority.php").read_text(encoding="utf-8")
authorization = (root / "includes" / "class-mad4b-scp-authorization.php").read_text(encoding="utf-8")
impact = (root / "includes" / "class-mad4b-scp-impact-policy.php").read_text(encoding="utf-8")
commit_guard = (root / "includes" / "class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
governance = (root / "includes" / "class-mad4b-scp-governance-abilities.php").read_text(encoding="utf-8")
bootstrap = (root / "mad4b-site-control-plane.php").read_text(encoding="utf-8")
readback_runtime = (root / "tests" / "self-update-readback-runtime.php").read_text(encoding="utf-8")
handoff = json.loads((root / "config" / "staging-deployment-handoff.json").read_text(encoding="utf-8"))

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
    "production_remote_native_pull_allowed",
    "plugin_action_links_",
    "after_plugin_row_",
    "admin_post_mad4b_control_plane_native_update",
    "admin_post_mad4b_control_plane_refresh_update",
    "handle_refresh_update",
    "native_update_ui_state",
    "manifest_unavailable",
    "policy_blocked",
    "native_update_environment_policy_blocked",
    "Retry MAD4B update check",
    "'ui_state' =>",
    "'ui_blockers' =>",
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
    "cached_manifest",
    "mad4b_self_update_manifest_not_cached",
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
    "self::download_governed_release_to_protected_storage( $manifest )",
    "private static function download_governed_release_to_protected_storage",
    "'stream' => true",
    "'limit_response_size' => self::MAX_UPLOAD_BYTES + 1",
    "self::temp_archive_path()",
    "self::verify_archive( $tmp, $manifest )",
    "self::apply_verified_archive( $tmp, $manifest, 'governed_native_release_pull', $expected )",
    "'governed_native_release_pull' === (string) $channel ? self::NATIVE_APPLY_CONTRACT : self::APPLY_CONTRACT",
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
    "upgrader_pre_download",
    "upgrader_pre_install",
    "upgrader_post_install",
):
    if forbidden_hook in self_update:
        raise SystemExit(f"self-update must not alter WordPress updater routine: {forbidden_hook}")
for forbidden_registration in (
    "add_filter( 'auto_update_plugin'",
    'add_filter( "auto_update_plugin"',
    "add_action( 'auto_update_plugin'",
    'add_action( "auto_update_plugin"',
):
    if forbidden_registration in self_update:
        raise SystemExit("self-update must observe but never register the auto_update_plugin policy hook")
if "'automatic_update_enabled' => false" in self_update:
    raise SystemExit("automatic update state is still hard-coded instead of observed from WordPress")
for marker in (
    "'automatic_update_enabled' => (bool) $auto_update['effective_enabled']",
    "'current_offer_auto_update_eligible' =>",
    "'filesystem_execution_preflight' => 'deferred_to_wordpress_automatic_updater'",
    "'mad4b_auto_update_mutation_performed' => false",
):
    if marker not in self_update:
        raise SystemExit(f"WordPress auto-update observation invariant missing: {marker}")

# The wp-admin update affordance must survive basename drift without enrolling
# WordPress core updater transients or bypassing the governed verifier.
for marker in (
    "add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links_fallback' ), 20, 4 )",
    "add_action( 'after_plugin_row', array( __CLASS__, 'render_update_row_fallback' ), 10, 3 )",
    "private static function is_control_plane_plugin_file",
    "realpath( $candidate )",
    "realpath( MAD4B_SCP_FILE )",
    "private static $rendered_update_rows = array();",
    "'ui_hook_mode' => 'exact_hook_plus_realpath_fallback'",
    "'admin_update_capability' => (bool) current_user_can( 'update_plugins' )",
    "private static function wordpress_auto_update_state()",
    "mad4b.wordpress-plugin-auto-update-observation.v1",
    "wp_is_auto_update_enabled_for_type",
    "get_site_option( 'auto_update_plugins', array() )",
    "get_site_transient( 'update_plugins' )",
    "wp_is_auto_update_forced_for_item",
    "'automatic_update_observation' => $auto_update",
    "plugin_auto_update_setting_html",
    "mad4b-auto-update-state",
):
    if marker not in self_update:
        raise SystemExit(f"native update UI fallback invariant missing: {marker}")

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
subprocess.run(["php", str(root / "tests" / "self-update-auto-update-state-runtime.php")], check=True)

# Read-side plans remain directly projectable where operator action needs them.
# Broad update status remains in the governed read/logical catalog so composite
# ChatGPT diagnostics stay single-path and bounded.
for marker in (
    "'mad4b/control-plane-update-status'",
    "'mad4b/control-plane-upload-plan'",
):
    if marker not in servers:
        raise SystemExit(f"self-update read projection invariant missing: {marker}")
if servers.count("'mad4b/control-plane-update-status'") != 1:
    raise SystemExit("Control Plane update status must remain read/logical-discovery-only rather than direct ChatGPT status fan-out")
if "self::core_tools( 'mad4b-read' )" not in servers or "public static function chatgpt_full_catalog_candidates()" not in servers:
    raise SystemExit("Control Plane update status lost governed ChatGPT logical discovery path")
read_catalog = servers.split("'mad4b-read' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
direct_read_allowlist = servers.split("public static function chatgpt_direct_read_transport_tools()", 1)[1].split("public static function chatgpt_dispatch_transport_tools()", 1)[0]
for direct_plan in (
    "'mad4b/control-plane-upload-plan'",
    "'mad4b/control-plane-native-plan'",
):
    if direct_plan not in read_catalog:
        raise SystemExit(f"Control Plane plan missing from normal read catalog: {direct_plan}")
    if direct_plan not in direct_read_allowlist:
        raise SystemExit(f"Control Plane plan missing from reviewed direct ChatGPT allowlist: {direct_plan}")

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
if servers.count("'mad4b/control-plane-native-apply'") != 2:
    raise SystemExit("Control Plane native apply projection must be exactly admin + governed write candidate")
if impact.count("'mad4b/control-plane-native-apply'") < 2:
    raise SystemExit("Control Plane native apply must be classified as certified-package and high-impact")

# Bootstrap must load and boot the coordinator.
for marker in (
    "class-mad4b-scp-self-update.php",
    "MAD4B_SCP_Self_Update::boot();",
):
    if marker not in bootstrap:
        raise SystemExit(f"self-update bootstrap invariant missing: {marker}")

channels = handoff.get("self_update", {}).get("channels", {})
if handoff.get("self_update", {}).get("purpose") != "permanent_multi_channel_control_plane_updates_after_first_bootstrap":
    raise SystemExit("deployment handoff still describes the obsolete dual-channel self-update model")
for channel in ("wordpress_native_update", "governed_file_upload", "governed_native_release_pull"):
    if channel not in channels:
        raise SystemExit(f"deployment handoff self-update channel missing: {channel}")
native_handoff = channels["governed_native_release_pull"]
for key, expected in {
    "ability_plan": "mad4b/control-plane-native-plan",
    "ability_apply": "mad4b/control-plane-native-apply",
    "caller_supplied_url_allowed": False,
    "caller_supplied_path_allowed": False,
    "caller_package_bytes_allowed": False,
    "target_derived_from_release_manifest": True,
    "staging_only": True,
    "exact_plan_required": True,
    "exact_one_time_approval_required": True,
    "release_verdict_required": True,
    "production_allowed": False,
}.items():
    if native_handoff.get(key) != expected:
        raise SystemExit(f"deployment handoff governed native pull invariant mismatch: {key}")
if handoff.get("self_update", {}).get("forbidden", {}).get("production_remote_native_pull") is not True:
    raise SystemExit("deployment handoff does not explicitly forbid Production remote native pull")

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

# Ordinary plugins.php rendering must be cache-only. Remote GitHub manifest
# fetches are allowed only on explicit refresh/update/status flows.
action_link_body = self_update.split("private static function native_update_action_link( array $links )", 1)[1].split("private static function native_update_ui_state", 1)[0]
assert "self::cached_manifest()" in action_link_body
assert "self::fetch_manifest(" not in action_link_body

render_body = self_update.split("public static function render_update_row(", 1)[1].split("public static function render_update_row_fallback", 1)[0]
assert "self::cached_manifest()" in render_body
assert "self::fetch_manifest(" not in render_body

refresh_body = self_update.split("public static function handle_refresh_update()", 1)[1].split("public static function handle_native_update()", 1)[0]
assert "self::fetch_manifest( true )" in refresh_body
assert "self::redirect_native_result( 'manifest_error'" in refresh_body

managed_apply = self_update.split("private static function apply_verified_archive(", 1)[1].split("private static function download_governed_release_to_protected_storage", 1)[0]
assert "delete_transient( self::MANIFEST_TRANSIENT )" not in managed_apply

# Session-safe/runtime projections must have a public cache-only status path that
# can never initiate outbound release-channel I/O.
assert "public static function cached_status( $input = array() )" in self_update
cached_status = self_update.split("public static function cached_status( $input = array() )", 1)[1].split("public static function upload_plan", 1)[0]
assert "self::cached_manifest()" in cached_status
assert "'outbound_network_performed' => false" in cached_status
assert "self::fetch_manifest(" not in cached_status
assert "wp_safe_remote_get(" not in cached_status

# MAD4B auto-update observation must render in WordPress' native Auto-updates
# column, not as a plugin action-link that visually diverges from core plugins.
if "add_filter( 'plugin_auto_update_setting_html', array( __CLASS__, 'plugin_auto_update_setting_html' ), 20, 3 )" not in self_update:
    raise SystemExit("native WordPress auto-update column integration missing")
if "$links['mad4b_auto_update_state']" in self_update:
    raise SystemExit("MAD4B auto-update state must not render in plugin action links")
column_body = self_update.split("public static function plugin_auto_update_setting_html(", 1)[1].split("public static function plugin_action_links(", 1)[0]
for marker in (
    "self::is_control_plane_plugin_file( $plugin_file )",
    "self::wordpress_auto_update_state()",
    "mad4b-auto-update-state",
    "Auto-updates enabled",
    "Auto-updates disabled",
):
    if marker not in column_body:
        raise SystemExit(f"native auto-update column invariant missing: {marker}")
