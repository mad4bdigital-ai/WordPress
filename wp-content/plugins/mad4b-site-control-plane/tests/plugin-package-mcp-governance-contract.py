#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PACKAGE = ROOT / "includes" / "class-mad4b-scp-plugin-package.php"
BOOTSTRAP = ROOT / "mad4b-site-control-plane.php"
SERVERS = ROOT / "includes" / "class-mad4b-scp-servers.php"
REGISTRY = ROOT / "includes" / "class-mad4b-scp-adapter-registry.php"\nIMPACT = ROOT / "includes" / "class-mad4b-scp-impact-policy.php"\nGRANTS = ROOT / "includes" / "class-mad4b-scp-staging-write-grant-reconciliation.php"


def require(condition, message):
    if not condition:
        raise SystemExit(message)


package = PACKAGE.read_text(encoding="utf-8")
bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
servers = SERVERS.read_text(encoding="utf-8")
registry = REGISTRY.read_text(encoding="utf-8")\nimpact = IMPACT.read_text(encoding="utf-8")\ngrants = GRANTS.read_text(encoding="utf-8")

for marker in [
    "class MAD4B_SCP_Plugin_Package",
    "mad4b.plugin-package-plan.v1",
    "mad4b.plugin-package-apply.v1",
    "'surface' => 'admin'",
    "'mad4b/plugin-package-plan'",
    "'mad4b/plugin-package-apply'",
    "'readonly' => true",
    "'readonly' => false",
    "MAD4B_SCP_Authorization::authorize_mutation",
    "MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' )",
    "'production_allowed' => false",
    "'caller_supplied_url_allowed' => false",
    "'caller_supplied_path_allowed' => false",
    "expected_plan_sha256",
    "mad4b_plugin_package_plan_changed",
    "MAD4B_SCP_Provider_Contracts::get",
    "archive_sha256",
    "critical_files",
    "hash_file( 'sha256'",
    "prepare_backup_root",
    "Plugin_Upgrader",
    "'overwrite_package' => true",
    "restore_activation_state",
    "rollback_on_failed_disk_readback",
    "mad4b_plugin_package_readback_failed_rolled_back",
    "rollback_error_code",
    "failure_phase",
    "runtime_reboot_required",
    "certified_target_is_older_than_runtime",
    "path_outside_web_roots",
    "auto_certified",
    "certified_repository_archive",
    "repository_source_commit_sha",
    "functional-gap-contract-evidence.generated.json",
    "repository_artifact:",
    "https://raw.githubusercontent.com/mad4bdigital-ai/WordPress/",
    "exact_build_source_bound",
    "package_sha256_verified_at_apply",
    "remote_request_performed",

    "authority_created' => false",
]:
    require(marker in package, f"plugin package governance marker missing: {marker}")

for forbidden in [
    "'package_url'",
    "'download_url'",
    "'archive_path'",
    "'plugin_file' => array(",
    "'target_version' => array(",
    "'archive_sha256' => array(",
    "trailingslashit( WP_PLUGIN_DIR ) . basename( $archive )",
    "mad4b/database-raw-query",
    "shell_exec(",
    "exec(",
    "system(",
    "passthru(",
    "proc_open(",
    "popen(",
]:
    require(forbidden not in package, f"unsafe caller/arbitrary execution surface detected: {forbidden}")

schema = package.split("private static function plan_schema()", 1)[1]
require("'provider_id'" in schema and "'component'" in schema and "'source'" in schema and "'reason'" in schema, "bounded caller schema missing")
require("auto_certified" in schema and "wordpress_update_offer" in schema and "certified_repository_archive" in schema and "certified_local_archive" in schema, "server-resolved package sources missing")
require("'default' => 'auto_certified'" in schema, "zero-touch source selection must remain the default")
require("additionalProperties' => false" in schema, "package input schema must reject unknown caller fields")

plan_body = package.split("public static function plan(", 1)[1].split("public static function apply(", 1)[0]
for forbidden in ["Plugin_Upgrader", "download_url(", "wp_remote_get(", "wp_safe_remote_get(", "copy_dir(", "activate_plugin(", "deactivate_plugins("]:
    require(forbidden not in plan_body, f"read-only plan unexpectedly mutates or downloads: {forbidden}")

apply_body = package.split("public static function apply(", 1)[1].split("private static function authority(", 1)[0]
for marker in [
    "self::plan( $input )",
    "hash_equals( $plan['plan_sha256'], $expected_plan )",
    "self::materialize_package",
    "self::backup_plugin",
    "self::install_package",
    "self::verify_disk_readback",
    "self::rollback",
]:
    require(marker in apply_body, f"apply safety sequence missing: {marker}")

require("class-mad4b-scp-plugin-package.php" in bootstrap, "plugin package class is not loaded by bootstrap")
require("MAD4B_SCP_Plugin_Package::boot();" in bootstrap, "plugin package abilities are not booted")

for marker in [
    "'mad4b/plugin-package-plan'",
    "'mad4b/plugin-package-apply'",
]:
    require(marker in servers, f"plugin package ability missing from MCP server catalog: {marker}")
    require(marker in registry, f"plugin package ability missing from runtime inventory: {marker}")

core = servers.split("private static function core_write_candidates()", 1)[1].split("private static function registered_adapter_write_candidates()", 1)[0]
require("'mad4b/plugin-package-apply'" in core, "package apply must be a governed core write candidate")
require("'mad4b/plugin-package-plan'" not in core, "read-only package plan must never enter write catalog")
require("'mad4b/plugin-package-apply'" in impact.split("$high_core = array(", 1)[1].split(");", 1)[0], "plugin package replacement must remain hard high-impact")
allowlist = grants.split("public static function allowed_ability_providers()", 1)[1].split("public static function allowed_abilities()", 1)[0]
require("'mad4b/plugin-package-apply' => 'core'" in allowlist, "package apply exact Staging grant is not bounded/allowlisted")
require("'wp-import-export/run-import'" not in allowlist and "'wp-import-export/run-export'" not in allowlist, "provider execution abilities must never leak into package grant reconciliation")
chatgpt = servers.split("'mad4b-chatgpt' => array_merge( array(", 1)[1].split("), $governed_status", 1)[0]
require("'mad4b/plugin-package-plan'" in chatgpt, "plugin package plan must be visible on ChatGPT read surface")
repository_fn = package.split("private static function repository_archive_url", 1)[1].split("private static function update_offer", 1)[0]
require("raw.githubusercontent.com/mad4bdigital-ai/WordPress/" in repository_fn, "repository package host/repository must remain fixed server-side")
require("repository_source_commit_sha()" in repository_fn, "repository package must bind to exact packaged source SHA")
require("wp-content/plugins/" in package and "repository_artifact_path" in package, "repository package path must derive from certified provider authority")
require("'package_url'" not in schema and "'archive_path'" not in schema and "'repository_url'" not in schema, "caller must never select repository package location")


print("mad4b.plugin-package-mcp-governance.v1: PASS")
