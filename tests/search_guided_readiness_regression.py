#!/usr/bin/env python3
"""Execute exact allowlisted Search/Context PHP regressions for CI-only readiness.
No network, credentials, WordPress installation or production authorization.
"""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
paths = [
    "wp-content/plugins/mad4b-site-control-plane/tests/context-oauth-lifecycle-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/adaptive-search-profile-admin-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/search-guided-profile-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/search-guided-provider-control.php",
]
for relative in paths:
    target = root / relative
    if not target.is_file() or target.resolve() != (root / relative).resolve():
        raise SystemExit("MISSING_ALLOWLISTED_TEST: " + relative)
    result = subprocess.run(["php", str(target)], cwd=root, capture_output=True, text=True, timeout=120, check=False)
    if result.returncode:
        sys.stderr.write("SEARCH_READINESS_REGRESSION_FAILED: " + relative + "\n" + (result.stdout + result.stderr)[-5000:])
        raise SystemExit(1)
    print("PASS: " + relative)
print("PASS: all four fixed guided Search/OAuth readiness regressions")
