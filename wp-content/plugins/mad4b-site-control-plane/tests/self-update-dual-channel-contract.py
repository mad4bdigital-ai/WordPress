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
    "mad4b.control-plane-bootstrap-apply.v1",
    "mad4b/control-plane-bootstrap-apply",
    "APPLY EXACT STAGING CONTROL PLANE BOOTSTRAP UPDATE",
    "public static function chatgpt_step_up_tools()",
    "public static function can_bootstrap_native_apply",
    "public static function bootstrap_native_apply",
    "mad4b_self_update_bootstrap_not_required",
    "mad4b_self_update_bootstrap_authority_not_clean",
    "MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE",
    "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID",
    "'authority_mutation_allowed' => false",
    "'production_allowed' => false",
    "'generic_raw_sql_breakglass_included' => false",
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
    "mad4b.control-plane-update-pointer.v1",
    "mad4b-site-control-plane-update-pointer.json",
    "mad4b-site-control-plane-update.json",
    "pointer_immutable",
    "legacy_stable_fallback",
    "mad4b_self_update_manifest_digest_mismatch",
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


# Bootstrap self-update is a narrow circular-dependency breaker, not a general
# authority bypass. It is pre-registered only because MCP registration precedes
# OAuth bearer verification, and visibility/execution still require exact
# ChatGPT step-up attribution.
for marker in (
    "'chatgpt_direct_step_up' => true",
    "'exact_chatgpt_client_required' => true",
    "MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE",
    "MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID",
    "verified_bearer_client_is",
):
    if marker not in self_update:
        raise SystemExit(f"bootstrap self-update direct step-up guard missing: {marker}")
for marker in (
    "public static function chatgpt_reviewed_direct_step_up_tools()",
    "MAD4B_SCP_Self_Update::BOOTSTRAP_APPLY_ABILITY",
    "$step_up = self::chatgpt_reviewed_direct_step_up_tools();",
    "array_merge( self::chatgpt_dispatch_transport_tools(), $step_up )",
):
    if marker not in servers:
        raise SystemExit(f"bootstrap self-update stable registration invariant missing: {marker}")
chatgpt_direct = servers.split("public static function chatgpt_tools()", 1)[1].split("private static function chatgpt_internal_enrollment_mutations()", 1)[0]
for forbidden in (
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
):
    if forbidden in chatgpt_direct:
        raise SystemExit(f"low-level enrollment primitive leaked into direct ChatGPT catalog: {forbidden}")

for marker in (
    "if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective() ) return array();",
    "if ( MAD4B_SCP_Staging_Write_Authority::effective() )",
    "empty( $authority_plan['eligible'] ) || empty( $authority_plan['current_ready'] )",
    "'exact_grants_missing_count'",
    "'stale_allow_grants_count'",
    "'broad_environment_grants_count'",
    "'current_agent_wildcard_grants'",
    "'global_registry_wildcard_grants'",
    "empty( $binding['required'] ) || ! empty( $binding['match'] )",
):
    if marker not in self_update:
        raise SystemExit(f"bootstrap self-update fail-closed authority invariant missing: {marker}")
native_apply_body = self_update.split("public static function native_apply", 1)[1].split("public static function bootstrap_native_apply", 1)[0]
for marker in (
    "public static function native_apply( $input, $bootstrap_revalidate = false )",
    "if ( $bootstrap_revalidate )",
    "$bootstrap_access = self::can_bootstrap_native_apply( $input );",
    "mad4b_self_update_bootstrap_revalidation_failed",
):
    if marker not in self_update:
        raise SystemExit(f"bootstrap self-update pre-mutation revalidation invariant missing: {marker}")
verify_index = native_apply_body.find("$verified = self::verify_archive( $tmp, $manifest );")
recheck_index = native_apply_body.find("$bootstrap_access = self::can_bootstrap_native_apply( $input );")
apply_index = native_apply_body.find("$result = self::apply_verified_archive( $tmp, $manifest, 'governed_native_release_pull', $expected, $verified );")
if min(verify_index, recheck_index, apply_index) < 0 or not (verify_index < recheck_index < apply_index):
    raise SystemExit("bootstrap authority must be revalidated after archive verification and immediately before filesystem mutation")
bootstrap_apply_body = self_update.split("public static function bootstrap_native_apply", 1)[1].split("private static function is_control_plane_plugin_file", 1)[0]
if "\n\t\t\ttrue\n\t\t);" not in bootstrap_apply_body:
    raise SystemExit("bootstrap self-update must invoke native apply with pre-mutation authority revalidation enabled")

for forbidden in (
    "MAD4B_SCP_Authorization::authorize_mutation(\n\t\t\tself::BOOTSTRAP_APPLY_ABILITY",
    "mad4b/control-plane-bootstrap-upload",
    "package_base64",
):
    bootstrap_apply = self_update.split("public static function bootstrap_native_apply", 1)[1].split("private static function is_control_plane_plugin_file", 1)[0]
    if forbidden in bootstrap_apply:
        raise SystemExit(f"bootstrap self-update widened beyond manifest-derived native apply: {forbidden}")

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
    "self::apply_verified_archive( $tmp, $manifest, 'governed_native_release_pull', $expected, $verified )",
    "'governed_native_release_pull' === (string) $channel ? self::NATIVE_APPLY_CONTRACT : self::APPLY_CONTRACT",
):
    if marker not in self_update:
        raise SystemExit(f"governed native release pull invariant missing: {marker}")

# Verified archive evidence is carried through the exact mutation boundary so
# OPcache invalidation can use the trusted ZIP member index without a post-install tree walk.
for marker in (
    "array $verified_archive",
    "isset( $verified_archive['runtime_php_files'] )",
    "mad4b_self_update_verified_runtime_index_missing",
    "invalidate_runtime_caches( $runtime_php_files )",
):
    if marker not in self_update:
        raise SystemExit(f"verified archive runtime index is not propagated through managed apply: {marker}")

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
for marker in (
    "private static function environment_resolution()",
    "MAD4B_SCP_Site_Profile::environment_resolution()",
    "'environment_resolution' => $environment_resolution",
    "'hostname_hint_used_for_authority' => false",
):
    if marker not in self_update:
        raise SystemExit(f"dynamic self-update environment invariant missing: {marker}")
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
if "Update URI:" in bootstrap:
    raise SystemExit("WordPress.org-compatible distribution must not claim an external Update URI")
for marker in (
    "MAD4B never publishes a core update transient/package",
    "WordPress automatic-update state is observed read-only",
):
    if marker not in self_update:
        raise SystemExit("observation-only WordPress updater boundary missing: " + marker)
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
for marker in (
    "catch ( Throwable $throwable )",
    "mad4b_self_update_install_exception",
    "self::rollback( $backup, $before, $runtime_php_files )",
):
    if marker not in managed_apply:
        raise SystemExit(f"managed apply exception/rollback safety invariant missing: {marker}")

for marker in (
    "$lease_owner = 'self_update_replacement';",
    "MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner )",
    "MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $lease_token, $lease_owner )",
    "$upgrader->maintenance_mode( true );",
    "$upgrader->maintenance_mode( false );",
    "$core_maintenance_open = true;",
    "MAD4B_SCP_Runtime_Maintenance_Lease::release( $lease_token, $lease_owner );",
    "'core_maintenance_window_used' => true",
    "'pre_replacement_runtime_lease' => true",
):
    if marker not in managed_apply:
        raise SystemExit(f"self-update replacement-fence invariant missing: {marker}")
if not (
    managed_apply.index("MAD4B_SCP_Runtime_Maintenance_Lease::acquire( $lease_owner )")
    < managed_apply.index("self::backup_current()")
    < managed_apply.index("$core_maintenance_open = true;")
    < managed_apply.index("$upgrader->maintenance_mode( true );")
    < managed_apply.index("$upgrader->install(")
    < managed_apply.index("MAD4B_SCP_Runtime_Convergence::mark_post_update_pending")
):
    raise SystemExit("self-update replacement window is not continuously fenced")
try_index = managed_apply.find("try {")
catch_index = managed_apply.find("catch ( Throwable $throwable )")
finally_index = managed_apply.find("finally {")
clear_index = managed_apply.find("self::$managed_apply = false;", finally_index)
if min(try_index, catch_index, finally_index, clear_index) < 0 or not (try_index < catch_index < finally_index < clear_index):
    raise SystemExit("managed apply flag must clear even if Plugin_Upgrader throws")
rollback_body = self_update.split("private static function rollback(", 1)[1].split("private static function activation_state()", 1)[0]
copy_index = rollback_body.find("copy_dir( $backup['backup_path'], $root )")
invalidate_index = rollback_body.find("self::invalidate_runtime_caches( $runtime_php_files );")
restore_index = rollback_body.find("self::restore_activation_state( $before )")
if min(copy_index, invalidate_index, restore_index) < 0 or not (copy_index < invalidate_index < restore_index):
    raise SystemExit("rollback must invalidate restored runtime paths before reactivation")

# A verified replacement is not successful until the durable post-update
# convergence checkpoint is established. Failure must rollback before any success audit.
managed_apply = self_update.split("private static function apply_verified_archive(", 1)[1].split("private static function download_governed_release_to_protected_storage", 1)[0]
mark_index = managed_apply.find("MAD4B_SCP_Runtime_Convergence::mark_post_update_pending")
checkpoint_fail_index = managed_apply.find("'checkpoint_persist_failed' === $convergence_state")
rollback_index = managed_apply.find("$rollback = self::rollback( $backup, $before", checkpoint_fail_index)
success_audit_index = managed_apply.find("self::audit( $channel, $target, true", checkpoint_fail_index)
if min(mark_index, checkpoint_fail_index, rollback_index, success_audit_index) < 0 or not (mark_index < checkpoint_fail_index <= rollback_index < success_audit_index):
    raise SystemExit("post-update convergence checkpoint failure must rollback before success audit")
for marker in (
    "'failure_phase' => 'post_update_convergence_checkpoint'",
    "'failure_code' => 'mad4b_self_update_convergence_checkpoint_persist_failed'",
    "mad4b_self_update_convergence_checkpoint_persist_failed",
    "'rollback_ok' => ! is_wp_error( $rollback )",
):
    if marker not in managed_apply:
        raise SystemExit(f"post-update convergence rollback invariant missing: {marker}")

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
subprocess.run(["php", str(root / "tests" / "self-update-pointer-runtime.php")], check=True)

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
    "const POINTER_CONTRACT      = 'mad4b.control-plane-update-pointer.v1';",
    "const POINTER_URL           = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update-pointer.json';",
    "const MANIFEST_URL          = 'https://github.com/mad4bdigital-ai/WordPress/releases/download/mad4b-site-control-plane-update-channel/mad4b-site-control-plane-update.json';",
    "self::fetch_pointer( $force )",
    "self::immutable_manifest_url( $pointer )",
    "hash( 'sha256', $fetched['body'] )",
    "self::validate_pointer_manifest_binding( $pointer, $manifest )",
    "self::manifest_transient_key( $pointer['source_commit_sha'] )",
    "pointer_legacy_fallback_allowed",
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
for marker in (
    "self::installed_identity()",
    "$manifest['display_version']",
    "'+build.'",
    "A governed MAD4B build update is available: %1$s → %2$s.",
):
    assert marker in render_body, f"exact build transition UI invariant missing: {marker}"

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

status_body = self_update.split("public static function status( $input = array() )", 1)[1].split("public static function cached_status", 1)[0]
for marker in (
    "'manual_only_scope' => 'mad4b_governed_native_action'",
    "'governed_action_manual_only' => true",
    "'wordpress_core_auto_update_observed' => (bool) $auto_update['effective_enabled']",
    "'wordpress_core_auto_update_governed' => false",
):
    assert marker in status_body, f"governed/core auto-update distinction missing from live status: {marker}"
    assert marker in cached_status, f"governed/core auto-update distinction missing from cached status: {marker}"

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
    "WordPress auto-update selected · governed update remains manual",
    "Governed updates only · automatic update disabled",
):
    if marker not in column_body:
        raise SystemExit(f"native auto-update column invariant missing: {marker}")

# Pointer-first consumer must fail closed on pointer integrity/binding errors and
# may use the legacy stable manifest only when the pointer cannot be reached.
fetch_manifest_body = self_update.split("private static function fetch_manifest( $force = false )", 1)[1].split("private static function clear_manifest_cache()", 1)[0]
assert fetch_manifest_body.index("self::fetch_pointer( $force )") < fetch_manifest_body.index("self::immutable_manifest_url( $pointer )")
assert "self::pointer_legacy_fallback_allowed( $pointer )" in fetch_manifest_body
assert "mad4b_self_update_manifest_digest_mismatch" in fetch_manifest_body
assert "set_transient(\n\t\t\tself::manifest_transient_key( $pointer['source_commit_sha'] )" in fetch_manifest_body
pointer_fallback = self_update.split("private static function pointer_legacy_fallback_allowed", 1)[1].split("private static function fetch_legacy_manifest", 1)[0]
assert "mad4b_self_update_pointer_fetch_failed" in pointer_fallback
assert "mad4b_self_update_pointer_http_error" in pointer_fallback
for forbidden in ("pointer_contract_mismatch", "pointer_digest_invalid", "pointer_asset_invalid", "pointer_trust_invalid"):
    assert forbidden not in pointer_fallback

# Keep post-update bottleneck regressions inside the plugin-owned contract
# boundary. This contract is already release-critical in both Site Control Plane
# and Control Plane Package workflows, so no repository-root workflow widening
# is required to gate these concurrency/restart invariants.
for command in (
    ["python3", str(root / "tests" / "post-update-bottleneck-hardening-contract.py")],
    ["python3", str(root / "tests" / "schema-lifecycle-same-version-contract.py")],
    ["php", str(root / "tests" / "runtime-restart-barrier-runtime.php")],
    ["php", str(root / "tests" / "runtime-maintenance-lease-runtime.php")],
):
    subprocess.run(command, check=True)

print("mad4b.control-plane-self-update post-update bottleneck regressions: PASS")
