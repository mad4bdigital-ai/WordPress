#!/usr/bin/env python3
"""Exact-head native offline QA; never changes the WordPress site or repository."""
from __future__ import annotations
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
REPOSITORY = ROOT.parents[2]
PREFIX = Path("wp-content/plugins/mad4b-site-control-plane")

def command(argv, cwd):
    try:
        result = subprocess.run(argv, cwd=str(cwd), capture_output=True, text=True,
                                timeout=120, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        return {"state": "BLOCKED", "error": type(error).__name__, "code": 2}
    return {"state": "PASS" if result.returncode == 0 else "FAIL",
            "code": result.returncode, "stdout": result.stdout[-1600:],
            "stderr": result.stderr[-1600:]}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--expected-head", required=True)
    parser.add_argument("--php", default="php", help="path to actual native PHP CLI, not WASM")
    args = parser.parse_args()
    if not __import__("re").fullmatch(r"[a-f0-9]{40}", args.expected_head):
        parser.error("expected HEAD must be an exact 40-char lowercase commit")
    report = {"contract": "mad4b.recovery-no-ci-preflight.v1",
              "expected_head": args.expected_head, "tests": [], "release_certified": False}
    git = shutil.which("git")
    php = shutil.which(args.php) if os.path.basename(args.php) == args.php else args.php
    if not git:
        report["blocker"] = "native_git_missing"
    else:
        sha = command([git, "rev-parse", "HEAD"], REPOSITORY)
        dirty = command([git, "status", "--porcelain", "--untracked-files=no"], REPOSITORY)
        if sha["state"] != "PASS" or sha["stdout"].strip() != args.expected_head:
            report["blocker"] = "exact_source_head_mismatch"
        elif dirty["state"] != "PASS" or dirty["stdout"].strip():
            report["blocker"] = "tracked_repository_worktree_dirty"
    if not php or not Path(php).is_file() and not shutil.which(php):
        report["blocker"] = report.get("blocker", "native_php_cli_missing")
    if report.get("blocker"):
        report["state"] = "BLOCKED"
        print(json.dumps(report, indent=2))
        return 2
    php_version = command([php, "-r", "echo PHP_VERSION_ID;"], ROOT)
    report["native_php_version"] = php_version["stdout"].strip()
    if php_version["state"] != "PASS" or not php_version["stdout"].strip().isdigit() or int(php_version["stdout"].strip()) < 70400:
        report["state"] = "BLOCKED"
        report["blocker"] = "php_7_4_or_later_required"
        print(json.dumps(report, indent=2))
        return 2
    checks = [
        ["lint:recovery-lifecycle", [php, "-l", str(ROOT / "includes/class-mad4b-scp-recovery-lifecycle.php")]],
        ["lint:staging-certification", [php, "-l", str(ROOT / "includes/class-mad4b-scp-staging-certification.php")]],
        ["lint:runtime-recovery-workspace", [php, "-l", str(ROOT / "includes/class-mad4b-scp-runtime-recovery-workspace.php")]],
        ["php:staging-independent-evidence", [php, str(ROOT / "tests/staging-independent-evidence-runtime.php")]],
        ["python:staging-independent-evidence", [sys.executable, str(ROOT / "tests/staging-independent-evidence-contract.py")]],
        ["lint:recovery-workspace", [php, "-l", str(ROOT / "includes/class-mad4b-scp-runtime-recovery-workspace.php")]],
        ["lint:host-bridge", [php, "-l", str(ROOT / "includes/class-mad4b-scp-host-bridge.php")]],
        ["php:host-bridge", [php, str(ROOT / "tests/host-bridge-contract.php")]],
        ["python:host-environment-sync", [sys.executable, str(ROOT / "tests/host-environment-sync-runner-contract.py")]],
        ["lint:runtime-convergence", [php, "-l", str(ROOT / "includes/class-mad4b-scp-runtime-convergence.php")]],
        ["lint:skill-certification", [php, "-l", str(ROOT / "includes/class-mad4b-scp-skill-runtime-certification.php")]],
        ["lint:recovery-native-test", [php, "-l", str(ROOT / "tests/recovery-lifecycle-runtime.php")]],
        ["php:recovery-lifecycle", [php, str(ROOT / "tests/recovery-lifecycle-runtime.php")]],
        ["php:recovery-workspace", [php, str(ROOT / "tests/runtime-recovery-workspace-runtime.php")]],
        ["lint:guided-operator", [php, "-l", str(ROOT / "includes/class-mad4b-scp-guided-operator-experience.php")]],
        ["php:guided-operator", [php, str(ROOT / "tests/guided-operator-experience-runtime.php")]],
        ["lint:enrollment-skills-preflight", [php, "-l", str(ROOT / "includes/class-mad4b-scp-enrollment-dispatch.php")]],
        ["php:enrollment-skills-preflight", [php, str(ROOT / "tests/enrollment-skills-preflight-runtime.php")]],
        ["php:enrollment-request-permission", [php, str(ROOT / "tests/enrollment-request-permission-runtime.php")]],
        ["python:enrollment-request-permission", [sys.executable, str(ROOT / "tests/enrollment-request-permission-contract.py")]],
        ["python:enrollment-skills-preflight", [sys.executable, str(ROOT / "tests/enrollment-skills-preflight-contract.py")]],
        ["python:guided-contract", [sys.executable, str(ROOT / "tests/guided-operator-experience-contract.py")]],
        ["php:scenario-registry", [php, str(ROOT / "tests/auto-reconcile-scenario-registry-runtime.php")]],
        ["python:recovery-static", [sys.executable, str(ROOT / "tests/wordpress-recovery-lifecycle-contract.py")]],
    ]
    for name, argv in checks:
        result = command(argv, ROOT)
        report["tests"].append({"name": name, **result})
    report["state"] = "PASS" if all(test["state"] == "PASS" for test in report["tests"]) else "FAIL"
    report["native_tests_passed"] = sum(test["state"] == "PASS" for test in report["tests"])
    report["native_tests_total"] = len(report["tests"])
    report["release_certified"] = False
    print(json.dumps(report, indent=2))
    return 0 if report["state"] == "PASS" else 1

if __name__ == "__main__":
    sys.exit(main())
