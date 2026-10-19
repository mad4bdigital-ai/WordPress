#!/usr/bin/env python3
"""Execute pinned standalone PHP synthetic-probe regressions (not PHPUnit).

Exact-head CI-only evidence: no external URL, WordPress mutation, browser
certification, Production promotion, or authorization. Fail closed on PHP errors.
"""
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
PHP = shutil.which("php")
if PHP is None:
    raise SystemExit("FRONTEND_ORIGIN_EARLY_BOOT_FAILURE: php CLI unavailable")

for name in (
    "frontend-origin-pre-pluggable-runtime.php",
    "frontend-origin-diagnostic-runtime.php",
):
    path = (ROOT / name).resolve()
    if path.parent != ROOT or not path.is_file():
        raise SystemExit("FRONTEND_ORIGIN_EARLY_BOOT_FAILURE: trusted fixture missing")
    try:
        result = subprocess.run(
            [PHP, str(path)], cwd=ROOT, text=True, capture_output=True,
            timeout=60, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise SystemExit("FRONTEND_ORIGIN_EARLY_BOOT_FAILURE: php execution unavailable or timeout")
    if result.returncode != 0:
        print("FRONTEND_ORIGIN_EARLY_BOOT_FAILURE: " + name, file=sys.stderr)
        print((result.stdout + "\n" + result.stderr)[-1800:], file=sys.stderr)
        raise SystemExit(1)
    print("FRONTEND_ORIGIN_EARLY_BOOT_PASS: " + name)
