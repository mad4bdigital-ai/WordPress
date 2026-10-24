#!/usr/bin/env python3
"""Run the exact approval-plan fail-closed PHP regression in CI.

Production Readiness requires an executable .py entrypoint; PHP test is run,
not merely parsed. No WordPress connection, mutation or owner approval.
"""
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
PHP = ROOT / "approval-plan-route-preflight-runtime.php"


def main() -> int:
    # A real PHP fixture passing is insufficient if the merged router was
    # accidentally replaced with older source. Verify both entrypoint guards.
    router = (ROOT.parent / "includes/class-mad4b-scp-adaptive-operation-router.php").read_text()
    guard = (ROOT.parent / "includes/class-mad4b-scp-staging-write-planning-guard.php").read_text()
    if ("approval_plan_execution_preflight" not in router or
            "true !== MAD4B_SCP_Policy::can_mutate()" not in router or
            "mad4b_remote_plan_server_denied" not in guard):
        print("APPROVAL_PLAN_ROUTE_EXECUTOR: source guard missing", file=sys.stderr)
        return 2
    binary = shutil.which("php")
    if binary is None or not PHP.is_file():
        print("APPROVAL_PLAN_ROUTE_EXECUTOR: missing PHP runtime or regression", file=sys.stderr)
        return 2
    try:
        result = subprocess.run(
            [binary, str(PHP)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=40,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        print("APPROVAL_PLAN_ROUTE_EXECUTOR: " + str(exc), file=sys.stderr)
        return 2
    if result.returncode != 0 or "APPROVAL_PLAN_ROUTE_PREFLIGHT_RUNTIME=PASS" not in result.stdout:
        print(result.stdout[-2000:] + result.stderr[-2000:], file=sys.stderr)
        return 1
    print("APPROVAL_PLAN_ROUTE_WRAPPED_BEHAVIOR=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
