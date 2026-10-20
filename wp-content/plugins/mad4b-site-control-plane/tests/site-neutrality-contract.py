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
print("SITE_NEUTRALITY_CONTRACT: PASS; source and hermetic fixtures only; live acceptance not inferred")
