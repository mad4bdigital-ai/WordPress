#!/usr/bin/env python3
"""Execute a bounded operational error regression matrix on exact Git HEAD.

Hermetic fixtures only: actual live ETG/ARE acceptance remains independent.
No network, administrator login, site write, owner claim, or Production action.
Tests remain fixed repo-owned paths and are subprocess-executed, not only linted.
"""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
# Every path is repository-owned; callers cannot supply arbitrary scripts.
FIXTURES = (
    "enrollment-dispatch-runtime.php",
    "dynamic-first-party-enrollment-runtime.php",
    "staging-write-owner-intent-runtime.php",
    "staging-write-owner-transport-runtime.php",
    "staging-write-one-step-review-runtime.php",
    "staging-write-authority-postcondition-runtime.php",
    "frontend-origin-diagnostic-runtime.php",
    "native-incident-mu-runtime.php",
    "native-incident-early-runtime.php",
    "mcp-connection-failsoft-runtime.php",
    "session-safe-transport-resilience-runtime.php",
    "error-contract-registry-runtime.php",
    "connector-resilience-runtime.php",
    "mcp-preflight-registration-failure-runtime.php",
    "wpml-response-contract-runtime.php",
    "live-acceptance-wpml-authority-runtime.php",
    "live-acceptance-wpml-composition-runtime.php",
)


def main():
    for name in FIXTURES:
        path = (ROOT / name).resolve()
        if path.parent != ROOT or not path.is_file():
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: missing trusted fixture", file=sys.stderr)
            return 2
        try:
            result = subprocess.run(
                ["php", str(path)], cwd=ROOT, capture_output=True,
                text=True, check=False, timeout=60,
            )
        except (OSError, subprocess.TimeoutExpired):
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: PHP unavailable or timeout", file=sys.stderr)
            return 2
        if result.returncode != 0:
            print("MAD4B_OPERATIONAL_ERROR_REGRESSION_FAIL: " + name, file=sys.stderr)
            print((result.stdout + "\n" + result.stderr)[-2000:], file=sys.stderr)
            return 1
        print("MAD4B_OPERATIONAL_ERROR_REGRESSION_PASS: " + name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
