#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
REMOTE = ROOT / "includes" / "class-mad4b-scp-remote-plugin-update.php"
BOOTSTRAP = ROOT / "mad4b-site-control-plane.php"
SERVERS = ROOT / "includes" / "class-mad4b-scp-servers.php"
REGISTRY = ROOT / "includes" / "class-mad4b-scp-adapter-registry.php"
IMPACT = ROOT / "includes" / "class-mad4b-scp-impact-policy.php"
GRANTS = ROOT / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation.php"


def require(condition, message):
    if not condition:
        raise SystemExit(message)


remote = REMOTE.read_text(encoding="utf-8")
bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
servers = SERVERS.read_text(encoding="utf-8")
registry = REGISTRY.read_text(encoding="utf-8")
impact = IMPACT.read_text(encoding="utf-8")
grants = GRANTS.read_text(encoding="utf-8")

for marker in [
    "final class MAD4B_SCP_Remote_Plugin_Update",
    "mad4b.plugin-remote-update-plan.v1",
    "mad4b.plugin-remote-update-apply.v1",
    "'mad4b/plugin-remote-update-plan'",
    "'mad4b/plugin-remote-update-apply'",
    "MAD4B_SCP_Authorization::authorize_mutation",
    "MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' )",
    "'production_allowed' => false",
    "'caller_supplied_url_allowed' => false",
    "'caller_supplied_path_allowed' => false",
    "'caller_supplied_package_allowed' => false",
    "get_site_transient( 'update_plugins' )",
    "download_url( $url, 300 )",
    "wp_http_validate_url",
    "hash_file( 'sha256'",
    "archive_manifest_sha256",
    "getExternalAttributesIndex",
    "mad4b_remote_plugin_update_zip_symlink_forbidden",
    "Plugin_Upgrader",
    "'overwrite_package' => true",
    "prepare_backup_root",
    "restore_activation_state",
    "rollback_on_failed_readback",
    "mad4b_remote_plugin_update_readback_failed_rolled_back",
    "runtime_reboot_required",
]:
    require(marker in remote, f"remote plugin update governance marker missing: {marker}")

schema = remote.split("private static function plan_schema()", 1)[1].split("private static function apply_schema()", 1)[0]
require("'plugin_file'" in schema and "'reason'" in schema, "remote plan schema must select only installed plugin identity + reason")
for forbidden in ("'url' =>", "'package_url' =>", "'package_path' =>", "'target_version' =>", "'package_sha256' =>", "'package_base64' =>"):
    require(forbidden not in schema, f"caller-controlled remote package authority leaked into schema: {forbidden}")
require("additionalProperties' => false" in schema, "remote plugin update schema must reject unknown caller fields")

build = remote.split("private static function build_plan(", 1)[1].split("private static function snapshot_package(", 1)[0]
for marker in (
    "wordpress_update_offer_missing",
    "wordpress_update_target_not_newer",
    "self::snapshot_package",
    "'package_sha256' =>",
    "'archive_manifest_sha256' =>",
    "'plan_sha256'",
):
    require(marker in build, f"remote plan exact-artifact binding missing: {marker}")

apply = remote.split("public static function apply(", 1)[1].split("private static function build_plan(", 1)[0]
for marker in (
    "self::build_plan( $input, true )",
    "hash_equals( $plan['plan_sha256'], $expected_plan )",
    "self::backup_plugin",
    "self::install_package",
    "self::restore_activation_state",
    "self::verify_installed_readback",
    "self::rollback",
):
    require(marker in apply, f"remote apply safety sequence missing: {marker}")

for primitive in ("eval", "assert", "exec", "shell_exec", "system", "passthru", "popen", "proc_open"):
    pattern = r"(?<![A-Za-z0-9_])" + re.escape(primitive) + r"\s*\("
    require(re.search(pattern, remote) is None, f"forbidden arbitrary execution primitive detected: {primitive}")

require("class-mad4b-scp-remote-plugin-update.php" in bootstrap, "remote plugin update class is not loaded")
require("MAD4B_SCP_Remote_Plugin_Update::boot();" in bootstrap, "remote plugin update abilities are not booted")

for marker in ("'mad4b/plugin-remote-update-plan'", "'mad4b/plugin-remote-update-apply'"):
    require(marker in servers, f"remote plugin update ability missing from server catalog: {marker}")
    require(marker in registry, f"remote plugin update ability missing from runtime inventory: {marker}")

core = servers.split("private static function core_write_candidates()", 1)[1].split("private static function registered_adapter_write_candidates()", 1)[0]
require("'mad4b/plugin-remote-update-apply'" in core, "remote plugin apply must be a governed core write candidate")
require("'mad4b/plugin-remote-update-plan'" not in core, "remote plugin plan must never enter write catalog")

certified = impact.split("elseif ( in_array( $ability_name, array(", 1)[1]
require("'mad4b/plugin-remote-update-apply'" in impact, "remote plugin update must participate in central classification")
high_core = impact.split("$high_core = array(", 1)[1].split(");", 1)[0]
require("'mad4b/plugin-remote-update-apply'" in high_core, "remote plugin update must remain hard high-impact")

allowlist = grants.split("public static function allowed_ability_providers()", 1)[1].split("public static function allowed_abilities()", 1)[0]
require("'mad4b/plugin-remote-update-apply' => 'core'" in allowlist, "remote plugin apply exact Staging grant is not bounded/allowlisted")

print("mad4b.plugin-remote-update-governance.v1: PASS")
