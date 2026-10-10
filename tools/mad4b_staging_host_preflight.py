#!/usr/bin/env python3
"""MAD4B Staging host prerequisite inspector. No install, writes or authority.

Run from the separately authorized Linux host, never as a WordPress MCP command.
Optional --canary executes only a bounded prlimit and isolated /bin/true.
Output is a non-authorizing diagnostic, NEVER a signed host acceptance receipt.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys


def check_commands(which=shutil.which):
    return {
        "prlimit": which("prlimit"),
        "bubblewrap": which("bwrap"),
        "unshare_net": which("unshare"),
    }


def bounded_canary(argv, *, run=subprocess.run):
    try:
        result = run(
            argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, timeout=8, check=False
        )
        return result.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def evaluate(*, binaries, linux, host_environment, uid, canary_requested,
             run=subprocess.run):
    # Enforce site identity from the separate host policy, never request arguments
    # or a public hostname. This is necessary but not proof of Staging enrollment.
    nonroot = uid is not None and uid != 0
    host_confirmed = host_environment == "staging"
    staging_only = linux and host_confirmed and nonroot
    prlimit_present = bool(binaries.get("prlimit"))
    sandbox_type = ("bubblewrap" if binaries.get("bubblewrap") else
                    "unshare-net" if binaries.get("unshare_net") else "")
    true_binary = "/bin/true"
    canary_allowed = bool(canary_requested and staging_only and os.path.isfile(true_binary))
    prlimit_test = None
    sandbox_test = None
    if canary_allowed and prlimit_present:
        prlimit_test = bounded_canary(
            [binaries["prlimit"], "--nofile=64:64", "--", true_binary], run=run
        )
    if canary_allowed and sandbox_type == "bubblewrap":
        sandbox_test = bounded_canary(
            [binaries["bubblewrap"], "--unshare-net", "--die-with-parent",
             "--ro-bind", "/", "/", "--", true_binary], run=run
        )
    elif canary_allowed and sandbox_type == "unshare-net":
        sandbox_test = bounded_canary(
            [binaries["unshare_net"], "--net", "--fork", "--", true_binary], run=run
        )

    blockers = []
    if not linux:
        blockers.append("linux_host_required")
    if not host_confirmed:
        blockers.append("separately_enrolled_staging_host_identity_not_confirmed")
    if not nonroot:
        blockers.append("nonroot_host_worker_required")
    if not prlimit_present:
        blockers.append("resource_limiter_unavailable")
    if not sandbox_type:
        blockers.append("network_isolation_unavailable")
    if not canary_requested:
        blockers.append("behavioral_canary_not_executed")
    elif not canary_allowed:
        blockers.append("behavioral_canary_not_eligible")
    elif prlimit_test is not True:
        blockers.append("prlimit_behavior_failed")
    if canary_allowed and sandbox_test is not True:
        blockers.append("network_isolation_behavior_failed")

    return {
        "contract": "mad4b.staging-host-preflight.v1",
        "read_only": True, "mutation_performed": False, "authorizing": False,
        "environment": "staging" if host_confirmed else "unverified",
        "worker_nonroot": nonroot, "os_linux": bool(linux),
        "resource_limiter_present": prlimit_present,
        "sandbox_type": sandbox_type,
        "behavioral_canary_executed": canary_allowed,
        "prlimit_behavior_pass": prlimit_test,
        "sandbox_behavior_pass": sandbox_test,
        "status": "PRECHECK_PASSED_HOST_REVIEW_REQUIRED" if not blockers else "BLOCKED",
        "blockers": blockers,
        "signed_host_receipt": False,
        "developer_execution_certified": False,
        "production_mutation_allowed": False,
        "next_action": "independent_signed_host_acceptance" if not blockers
            else "host_operator_review_blockers",
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--canary", action="store_true",
        help="Run only bounded prlimit and no-network /bin/true under verified Staging worker"
    )
    args = parser.parse_args(argv)
    report = evaluate(
        binaries=check_commands(),
        linux=sys.platform.startswith("linux"),
        host_environment=os.environ.get("MAD4B_HOST_ENVIRONMENT", ""),
        uid=os.geteuid() if hasattr(os, "geteuid") else None,
        canary_requested=args.canary,
    )
    print(json.dumps(report, sort_keys=True))
    return 0 if report["status"] == "PRECHECK_PASSED_HOST_REVIEW_REQUIRED" else 2


if __name__ == "__main__":
    raise SystemExit(main())
