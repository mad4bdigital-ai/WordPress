#!/usr/bin/env python3
"""Execute actual isolated PHP runtime regressions for guarded MCP diagnostics.

This is the trusted Python entrypoint for the Production Readiness CI runner.
It does not substitute PHP lint, fixture presence, or assertions for execution.
No WordPress site connection, grants, writes, or Production authorization.
"""
from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent
TESTS = (
    "http-probe-phase-timing-runtime.php",
    "chatgpt-auto-binding-runtime.php",
    "transport-permission-containment-runtime.php",
    "native-connection-incident-runtime.php",
    "native-descriptor-incident-runtime.php",
    "descriptor-fault-containment-runtime.php",
    "frontend-origin-diagnostic-runtime.php",
    "enrollment-passive-catalog-runtime.php",
    "connection-catalog-readiness-runtime.php",
    "endpoint-descriptor-binding-evidence-runtime.php",
    "native-probe-transport-metadata-runtime.php",
)


def main() -> int:
    php = shutil.which("php")
    if php is None:
        print("MCP_DIAGNOSTIC_REGRESSIONS_FAIL: PHP executable unavailable")
        return 2
    for filename in TESTS:
        path = ROOT / filename
        if not path.is_file() or not path.resolve().is_relative_to(ROOT):
            print("MCP_DIAGNOSTIC_REGRESSIONS_FAIL: missing trusted fixture " + filename)
            return 2
        try:
            result = subprocess.run(
                [php, *(["-n"] if filename == "http-probe-phase-timing-runtime.php" else []), str(path)], cwd=ROOT, capture_output=True, text=True,
                timeout=60, check=False,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            print("MCP_DIAGNOSTIC_REGRESSIONS_FAIL: " + filename + ": " + type(exc).__name__)
            return 1
        if result.returncode != 0:
            print("MCP_DIAGNOSTIC_REGRESSIONS_FAIL: " + filename +
                  " exited " + str(result.returncode))
            print((result.stdout + "\n" + result.stderr)[-1500:])
            return 1
        print("MCP_DIAGNOSTIC_REGRESSION_PASS: " + filename)
    print("MCP_DIAGNOSTIC_REGRESSIONS_ALL_PASS: " + str(len(TESTS)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
