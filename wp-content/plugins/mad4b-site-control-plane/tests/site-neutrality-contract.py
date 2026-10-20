#!/usr/bin/env python3
"""Owned subprocess runner for site-neutral runtime behavior; no live requests."""
import os
from pathlib import Path
import shutil
import subprocess
import sys

PLUGIN = Path(__file__).resolve().parents[1]
ROOT = PLUGIN.parents[2]
php = os.environ.get("MAD4B_TEST_PHP_BIN") or shutil.which("php")
if not php:
    raise SystemExit("Site neutrality requires a PHP runtime")
commands = [
    [sys.executable, str(ROOT / "tools/check_plugin_site_neutrality.py"), "--self-test"],
    [sys.executable, str(ROOT / "tests/staging-public-http-contract.py")],
    [php, str(PLUGIN / "tests/elementor-site-neutral-runtime.php")],
    [php, str(PLUGIN / "tests/object-cache-diagnostics-runtime.php")],
    [php, str(PLUGIN / "tests/admin-file-workspace-runtime.php")],
]
for command in commands:
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=30)
    if result.returncode or result.stderr or "Warning:" in result.stdout:
        raise SystemExit("SITE_NEUTRALITY: failed subprocess:\n" + result.stdout[-1600:] + result.stderr[-1600:])
    print(result.stdout.strip())
# Keep the advisory file-manager check out of passive/cache status and
# require that all actual writes remain inside the existing signed ZIP lane.
self_update = (PLUGIN / "includes/class-mad4b-scp-self-update.php").read_text()
workspace = (PLUGIN / "includes/class-mad4b-scp-admin-file-workspace.php").read_text()
deep = self_update.split("public static function status( $input = array() )", 1)[1].split("public static function cached_status( $input = array() )", 1)[0]
cached = self_update.split("public static function cached_status( $input = array() )", 1)[1].split("public static function upload_plan( $input )", 1)[0]
assert "'wordpress_admin_file_workspace' => self::admin_file_workspace_handoff()" in deep
assert "admin_file_workspace_handoff()" not in cached
for required in ("'file_manager_remote_api_enabled' => false", "'el_finder_remote_transport_enabled' => false", "'arbitrary_path_or_url_allowed' => false", "'production_write_authorized' => false", "'mutation_performed' => false"):
    assert required in workspace, required
assert "'caller_path_allowed' => false" in self_update
assert "self::verify_archive( $tmp, $plan['target'] )" in self_update

print("SITE_NEUTRALITY_CONTRACT: PASS; source and hermetic fixtures only; live acceptance not inferred")
