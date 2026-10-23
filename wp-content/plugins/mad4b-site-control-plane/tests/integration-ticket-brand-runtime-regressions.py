#!/usr/bin/env python3
"""Run exact isolated PHP regression fixtures for CI readiness evidence.

No WordPress bootstrap, network request, subprocess shell, release mutation or
external site authorization is performed. Nonzero results fail closed.
"""
from pathlib import Path
import shutil
import subprocess
import sys

TESTS = (
    "ai-approval-target-context-contract.php",
    "content-job-dependency-recovery-contract.php",
    "context-semantic-source-approval-runtime.php",
    "content-bundle-semantic-contract-runtime.php",
    "approval-decision-runtime.php",
    "context-preflight-runtime.php",
    "reconnect-hardening-runtime.php",
    "runtime-restart-grace-readonly-runtime.php",
    "guided-runtime-convergence-runtime.php",
    "skill-context-preflight-runtime.php",
    "wpcli-passive-gate-contract.php",
    "local-client-vps-port-runtime.php",
    "local-client-credential-lifecycle-runtime.php",
)
ROOT = Path(__file__).resolve().parent
php = shutil.which("php")
if not php:
    raise SystemExit("FAIL: trusted PHP executable unavailable")
for name in TESTS:
    fixture = ROOT / name
    if not fixture.is_file() or fixture.resolve().parent != ROOT:
        raise SystemExit("FAIL: missing or unsafe PHP fixture " + name)
    try:
        result = subprocess.run(
            [php, str(fixture)], cwd=ROOT.parent,
            capture_output=True, text=True, timeout=35, check=False
        )
    except (subprocess.TimeoutExpired, OSError) as exc:
        raise SystemExit("FAIL: " + name + ": " + str(exc))
    if result.returncode != 0:
        raise SystemExit(
            "FAIL: " + name + " exit=" + str(result.returncode)
            + "\n" + (result.stdout + result.stderr)[-3000:]
        )
    print("PASS:", name)
print("INTEGRATED_TICKET_BRAND_RUNTIME_REGRESSIONS: PASS")
