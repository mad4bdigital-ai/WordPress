#!/usr/bin/env python3
"""Execute both bounded Owner Enrollment PHP behavioral fixtures in CI.

This is a real, fail-closed runtime invocation, not a source contract
assertion or a PHP syntax-only check. Both fixture paths are immutable,
repository-owned and cannot be replaced by caller input.
"""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
FIXTURES = (
    "enrollment-dispatch-runtime.php",
    "dynamic-first-party-enrollment-runtime.php",
)


def main():
    for name in FIXTURES:
        path = (ROOT / name).resolve()
        if path.parent != ROOT or not path.is_file():
            print("OWNER_ENROLLMENT_REGRESSION_FAIL: missing trusted fixture", file=sys.stderr)
            return 2
        try:
            result = subprocess.run(
                ["php", str(path)], cwd=ROOT, capture_output=True,
                text=True, check=False, timeout=60,
            )
        except (OSError, subprocess.TimeoutExpired):
            print("OWNER_ENROLLMENT_REGRESSION_FAIL: PHP unavailable or timeout", file=sys.stderr)
            return 2
        if result.returncode != 0:
            print("OWNER_ENROLLMENT_REGRESSION_FAIL: " + name, file=sys.stderr)
            print((result.stdout + "\n" + result.stderr)[-2000:], file=sys.stderr)
            return 1
        print("OWNER_ENROLLMENT_REGRESSION_PASS: " + name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
