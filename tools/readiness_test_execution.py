#!/usr/bin/env python3
"""Execute each newly mapped regression test and record exact-head CI execution.

Local Python scripts are run as subprocesses. PHPUnit files need an available
PHPUnit runner; php -l alone is deliberately not counted as functional testing.
No PR-provided JSON or static filepath can assert test success without execution.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SHA = re.compile(r"^[a-f0-9]{40}$")

# Review-bound CLI PHP scripts, not a blanket PHP execution policy.
# Each path contains exit-on-failure assertions and a positive PASS marker.
# Other PHP files continue to require PHPUnit; unknown paths fail closed.
STANDALONE_PHP_REGRESSIONS = frozenset((
    "tests/remote-execution/operator-grants-convergence-smoke.php",
    "tests/remote-execution/operator-grant-rollout-smoke.php",
    "tests/remote-execution/operator-bridge-admission.php",
    "tests/remote-execution/operator-operation-policy-smoke.php",
    "tests/remote-execution/operator-oauth-session-smoke.php",
    "tests/remote-execution/operator-runner-profile-smoke.php",
    "tests/remote-execution/unified-operator-session-policy-smoke.php",
))



def ensure(ok, message):
    if not ok:
        raise ValueError(message)


def command_for(test):
    path = (ROOT / test).resolve()
    ensure(path.is_relative_to(ROOT), "Test escapes checkout")
    ensure((test.startswith("tests/") or
            test.startswith("wp-content/plugins/mad4b-site-control-plane/tests/")) and
           ".." not in Path(test).parts and path.is_file(),
           "Untrusted or absent regression-test path " + test)
    if path.suffix == ".py":
        return [sys.executable, str(path)]
    if path.suffix == ".php":
        if test in STANDALONE_PHP_REGRESSIONS:
            ensure(not path.is_symlink(), "Standalone test symlink denied " + test)
            return ["php", str(path)]
        # Unreviewed PHP still requires a dedicated trusted PHPUnit runner.
        runner = ROOT / "vendor/bin/phpunit"
        if not runner.is_file():
            runner = ROOT / "wp-content/plugins/mad4b-site-control-plane/vendor/bin/phpunit"
        ensure(runner.is_file(), "No trusted PHPUnit runner for " + test)
        return ["php", str(runner), str(path)]
    raise ValueError("No supported behavioral test executor for " + test)


def run_tests(impact, head, timeout):
    ensure(SHA.fullmatch(head or "") and impact.get("exact_head_sha") == head,
           "Exact HEAD mismatch in regression test plan")
    ensure(impact.get("contract") == "mad4b.dynamic-readiness-capability-impact.v1"
           and impact.get("authorizing") is False and
           impact.get("production_authorized") is False,
           "Untrusted coverage execution request")
    tests = impact.get("required_regression_tests")
    ensure(isinstance(tests, list) and len(tests) == len(set(tests)),
           "Missing or duplicate execution plan")
    results = []
    for name in tests:
        ensure(isinstance(name, str), "Regression path must be string")
        cmd = command_for(name)
        try:
            result = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True,
                                    timeout=timeout, check=False)
            ok = result.returncode == 0
            if name in STANDALONE_PHP_REGRESSIONS:
                # A zero-exit stub is not sufficient evidence of a test.
                ok = ok and bool(re.search(r"(?m)^PASS(?::|\s)", result.stdout))
            tail = (result.stdout + "\n" + result.stderr)[-5000:]
            outcome = "PASS" if ok else "FAIL"
            code = result.returncode
        except (subprocess.TimeoutExpired, OSError) as exc:
            outcome = "FAIL"
            tail = str(exc)[-5000:]
            code = -1
        results.append({
            "path": name, "result": outcome, "return_code": code,
            "output_tail_sha256": hashlib.sha256(tail.encode()).hexdigest(),
            "reason": "" if outcome == "PASS" else tail[-500:],
        })
    done = all(x["result"] == "PASS" for x in results)
    return {
        "contract": "mad4b.readiness-executed-regressions.v1",
        "exact_head_sha": head, "source": "CURRENT_CI_SUBPROCESS_EXECUTION",
        "github_run_id": os.environ.get("GITHUB_RUN_ID"),
        "status": ("PASS_ALL_MAPPED_TESTS" if done else "FAIL_MAPPED_TESTS")
            if tests else "NO_NEW_MAPPED_TESTS",
        "required_count": len(tests), "passed_count": sum(
            x["result"] == "PASS" for x in results),
        "tests": results, "authorizing": False,
        "live_evidence_verified": False, "production_authorized": False,
    }


def self_test():
    assert command_for("tests/production_readiness_registry_contract.py")[0] == sys.executable
    assert command_for("tests/remote-execution/operator-grants-convergence-smoke.php")[0] == "php"
    assert command_for("tests/remote-execution/operator-bridge-admission.php")[0] == "php"
    for path in ("README.md", "../secrets.py", "tests/does-not-exist.py"):
        try:
            command_for(path)
        except ValueError:
            pass
        else:
            raise AssertionError("Unsafe or missing test accepted: " + path)
    head = "a" * 40
    plan = {
        "contract": "mad4b.dynamic-readiness-capability-impact.v1",
        "exact_head_sha": head, "authorizing": False,
        "production_authorized": False, "required_regression_tests": [],
    }
    record = run_tests(plan, head, 15)
    assert record["status"] == "NO_NEW_MAPPED_TESTS"
    assert record["production_authorized"] is False
    plan["required_regression_tests"] = ["tests/not-exist.py"]
    try:
        run_tests(plan, head, 15)
    except ValueError:
        pass
    else:
        raise AssertionError("Nonexecuted file reported as pass")
    print("READINESS_EXECUTED_REGRESSIONS_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("self-test", "evaluate"))
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--impact", default="artifacts/production-readiness/capability-impact.json")
    parser.add_argument("--out", default="artifacts/production-readiness")
    parser.add_argument("--timeout-seconds", type=int, default=120)
    args = parser.parse_args()
    try:
        if args.command == "self-test":
            self_test()
            return 0
        ensure(1 <= args.timeout_seconds <= 300, "Bounded test timeout needed")
        plan = json.loads(Path(args.impact).read_text(encoding="utf-8"))
        report = run_tests(plan, args.head_sha, args.timeout_seconds)
        target = Path(args.out)
        target.mkdir(parents=True, exist_ok=True)
        (target / "executed-regressions.json").write_text(
            json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print("READINESS_EXECUTED_REGRESSIONS:", report["status"],
              report["passed_count"], "/", report["required_count"])
        return 0 if report["status"] != "FAIL_MAPPED_TESTS" else 1
    except (ValueError, OSError, KeyError, TypeError) as exc:
        print("READINESS_EXECUTED_REGRESSIONS_FAIL_CLOSED:", exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
