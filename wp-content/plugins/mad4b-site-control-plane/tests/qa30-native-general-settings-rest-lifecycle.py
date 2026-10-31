#!/usr/bin/env python3
"""Execute QA30's source-owned PHP assertions through the bounded Python CI lane.

No WordPress database, SMTP, cookies, deployment, or Production authorization.
"""
from pathlib import Path
import shutil
import subprocess
import sys

folder = Path(__file__).resolve().parent
fixture = folder / "qa30-native-general-settings-rest-lifecycle.php"
bootstrap = folder.parent / "mad4b-site-control-plane.php"
# The QA30 PHP contract is not sufficient unless the *actual* plugin bootstrap
# still registers the native General Settings lock ahead of MCP peer loading.
# This is a static source binding, not a WordPress activation or release check.
if bootstrap.is_symlink() or not bootstrap.is_file():
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL bootstrap source missing")
source = bootstrap.read_text(encoding="utf-8")
native_contract = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-native-general-settings-contract.php';"
mcp_peer = "require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-mcp-peer-governance.php';"
if source.count(native_contract) != 1 or source.count(mcp_peer) != 1:
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL registered source contract changed")
if source.index(native_contract) >= source.index(mcp_peer):
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL native guard must precede MCP peer loading")
php = shutil.which("php")
if not php:
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL PHP CLI unavailable")
if fixture.is_symlink() or not fixture.is_file():
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL fixture missing")
try:
    execution = subprocess.run(
        [php, str(fixture)], cwd=folder, capture_output=True, text=True,
        timeout=30, check=False,
    )
except (OSError, subprocess.TimeoutExpired) as error:
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL " + str(error))
expected = [
    "PASS source_contract_registered_once",
    "PASS locked_urls_and_direct_admin_mail_excluded",
    "PASS unrelated_settings_unchanged",
    "PASS mcp_deferred_during_general_save",
    "PASS no_rest_bootstrap_during_general_save",
    "PASS other_settings_group_untouched",
    "PASS mcp_rest_still_initializes_elsewhere",
    "PASS other_settings_can_initialize_rest",
    "PASS general_get_untouched",
    "PASS other_admin_post_untouched",
    "PASS non_admin_context_untouched",
    "QA30_SOURCE_CONTRACT_REGRESSION_PASS",
]
if execution.returncode != 0 or execution.stdout.strip().splitlines() != expected:
    if execution.stdout:
        sys.stderr.write(execution.stdout[-3000:])
    if execution.stderr:
        sys.stderr.write(execution.stderr[-3000:])
    raise SystemExit("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: FAIL PHP behavior assertions")
print("QA30_NATIVE_GENERAL_SETTINGS_RUNNER: PASS all 11 source assertions executed")
