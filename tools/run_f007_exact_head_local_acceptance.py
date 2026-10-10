#!/usr/bin/env python3
"""MAD4B Feature 007 fail-closed local acceptance. Not a Staging/release attestation.

Never execute suite commands until the checked-out repository HEAD is exactly
the user-approved SHA. All status values are bounded and exclude raw stderr,
paths, credentials, and source content. No external I/O is attempted.
"""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import time
from pathlib import Path

PLUGIN = Path("wp-content/plugins/mad4b-site-control-plane")
LINT = (
    "includes/class-mad4b-scp-operational-integrity.php",
    "includes/class-mad4b-scp-site-profile.php",
    "includes/class-mad4b-scp-site-profile-admin.php",
    "includes/class-mad4b-scp-deployment-mode-resolver.php",
    "includes/class-mad4b-scp-servers.php",
    "includes/class-mad4b-scp-content-jobs.php",
    "includes/class-mad4b-scp-context-authority.php",
    "tests/trusted-brand-scope-runtime.php",
    "tests/operational-integrity-local-runtime.php",
    "tests/content-job-transaction-fault-runtime.php",
    "tests/site-profile-general-distribution-runtime.php",
)
RUNTIME = (
    "tests/operational-integrity-local-runtime.php",
    "tests/trusted-brand-scope-runtime.php",
    "tests/content-job-transaction-fault-runtime.php",
    "tests/deployment-mode-resolver-runtime.php",
    "tests/site-profile-general-distribution-runtime.php",
    "tests/site-profile-lifecycle-matrix-runtime.php",
)
CONTRACTS = (
    "tests/context-authority-contract.py",
)
ALL = tuple(("php_lint", x) for x in LINT) + tuple(("php_runtime", x) for x in RUNTIME) + tuple(("python_contract", x) for x in CONTRACTS)


def observe_head(root: Path) -> str | None:
    try:
        p = subprocess.run(["git", "-C", str(root), "rev-parse", "HEAD"],
                           capture_output=True, text=True, timeout=10, check=False)
        return p.stdout.strip().lower() if p.returncode == 0 else None
    except (OSError, subprocess.TimeoutExpired):
        return None


def assess(root: Path, expected_head: str, timeout: int = 90, php: str = "php"):
    root = root.resolve()
    observed = observe_head(root)
    report = {
        "contract": "mad4b.f007-exact-head-local-acceptance.v1",
        "expected_head": expected_head,
        "observed_head": observed,
        "exact_head_match": bool(observed and observed == expected_head),
        "native_gate": "BLOCKED",
        "operational_acceptance": False,
        "staging_acceptance": False,
        "host_acceptance": False,
        "mcp_acceptance": False,
        "production_authorized": False,
        "publication_authorized": False,
        "suites": [],
    }
    # Fail before any PHP/Python execution or importing repository code.
    if not report["exact_head_match"]:
        report["suites"] = [
            {"kind": kind, "name": name, "result": "NOT_RUN_HEAD_MISMATCH"}
            for kind, name in ALL
        ]
        return report

    runtime = shutil.which(php)
    for kind, name in ALL:
        script = root / PLUGIN / name
        record = {"kind": kind, "name": name, "result": "NOT_RUN", "duration_ms": 0}
        if not script.is_file():
            record["result"] = "MISSING_FILE"
        elif kind.startswith("php") and runtime is None:
            record["result"] = "PHP_UNAVAILABLE"
        else:
            argv = (
                [runtime, "-l", str(script)] if kind == "php_lint"
                else [runtime, str(script)] if kind == "php_runtime"
                else [shutil.which("python3") or "python3", str(script)]
            )
            started = time.monotonic()
            try:
                finished = subprocess.run(argv, cwd=root, capture_output=True, timeout=timeout, check=False)
                record["result"] = "PASS" if finished.returncode == 0 else "FAIL"
                record["exit_code"] = finished.returncode
            except subprocess.TimeoutExpired:
                record["result"] = "TIMEOUT"
            except OSError:
                record["result"] = "RUNTIME_UNAVAILABLE"
            record["duration_ms"] = int((time.monotonic() - started) * 1000)
        report["suites"].append(record)
    report["counts"] = {
        "passed": sum(x["result"] == "PASS" for x in report["suites"]),
        "total": len(report["suites"]),
    }
    report["native_gate"] = (
        "PASS" if report["counts"]["passed"] == report["counts"]["total"] else "BLOCKED"
    )
    return report


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[1])
    cli.add_argument("--expected-head", required=True)
    cli.add_argument("--timeout", type=int, default=90)
    cli.add_argument("--php", default="php")
    args = cli.parse_args()
    if not 1 <= args.timeout <= 300:
        cli.error("timeout must be between 1 and 300 seconds")
    if len(args.expected_head) != 40 or any(c not in "0123456789abcdef" for c in args.expected_head):
        cli.error("expected-head must be a lower-case 40-digit SHA-1")
    result = assess(args.repo_root, args.expected_head, args.timeout, args.php)
    print(json.dumps(result, indent=2, sort_keys=True))
    raise SystemExit(0 if result["native_gate"] == "PASS" else 1)


if __name__ == "__main__":
    main()
