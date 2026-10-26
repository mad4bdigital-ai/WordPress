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
    "tests/remote-execution/chatgpt-optional-catalog-smoke.php",
    "tests/remote-execution/developer-preparation-bridge-smoke.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/mcp-catalog-adaptive-budget.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/adaptive-interaction-broker/contract-test.php",
    # Exact, hermetic PHP scripts; never promote arbitrary runtime PHP to CLI.
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-domain-executors-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-native-owner-receipts-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-only-local-early-auth-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-grant-mode-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-model-routing-worker-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-worker-guard-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-crossenv-scope-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-credential-expiry-hermetic.php",
    # Exact signed-market and source-bootstrap fixtures execute without WordPress.
    # Review-bound paths only; unlisted PHP still requires PHPUnit.
    "tests/switchers/test-market-compatibility.php",
    "tests/switchers/test-market-context-boundaries.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-bootstrap-compatibility.php",
    # Exact no-network PHP fixture reviewed for external Skill source discovery only.
    "wp-content/plugins/mad4b-site-control-plane/tests/external-skill-sources-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-read-abilities-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-cron-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-authority-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-setup-coverage-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/authority-setup-fields-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/background-authorization-verified-identity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-policy-gate-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-queue-extension-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-workflow-inspect-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-pipeline-admission-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-preflight-denial-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/passwordless-mcp-identity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/cross-plugin-lifecycle-admission-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/browser-operator-integrity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/plugin-lifecycle-preflight-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/local-client-request-boundary-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-admin-integrity-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/google-revocation-boundary-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-resource-signature-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-admin-rest-permissions-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/media-inventory-authorization-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-source-repair-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/unified-approval-ai-first/local-rest-fastpath-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/public-holiday-hermetic-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/application-password-agent-bridge-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-media-evidence-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-history-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/provider-connections-admin-read-hermetic.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/context-ai-agent-review-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/generation-snapshot-exception-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/no-input-read-ability-schema-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/operator-doctor-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/oauth-secondary-diagnostic-exception-runtime.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/staging-mcp-skills-recertify-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/developer-external-backend-discovery-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/developer-runner-lifecycle-fetch-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-backend-registry-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-backend-deactivation-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/runtime-runner-bridge-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/performance-differential-diagnostic-contract.php",
    "wp-content/plugins/mad4b-site-control-plane/tests/mcp-origin-error-preservation-runtime.php",
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


def has_assertion_pass(stdout):
    # The allowlisted *executable* fixture must exit zero and report at least
    # one assertion. Accept either positive PASS lines or a bounded terminal
    # counter; reject zero-count, failure and PHP lint-only output.
    if not isinstance(stdout, str) or not stdout:
        return False
    if re.search(r"(?m)^(?:FAIL(?::|\s)|[^\n]{3,130}: FAIL(?:\s|$))", stdout):
        return False
    if re.search(r"(?m)^PASS(?::|\s)", stdout):
        return True
    if re.search(r"(?m)^[A-Za-z][A-Za-z0-9 _./:-]{2,110}: PASS(?: [1-9][0-9]*(?: [A-Za-z][A-Za-z ]{0,30})?)?[ \t]*$", stdout):
        return True
    return bool(re.search(
        r"(?m)^SUMMARY (?:pass=[1-9][0-9]* fail=0|"
        r"checks=[1-9][0-9]* failures=0)[ \t]*$", stdout))


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
                # Require positive assertions, never accept exit(0) or lint-only
                # output. Existing original contracts also print N test cases
                # in an anchored success-only terminal summary.
                ok = ok and has_assertion_pass(result.stdout)
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
    assert has_assertion_pass("PASS: verified real case\n")
    assert has_assertion_pass("SUMMARY pass=8 fail=0\n")
    assert has_assertion_pass("SUMMARY checks=21 failures=0\n")
    assert has_assertion_pass("BROWSER_OPERATOR_INTEGRITY: PASS 15\n")
    assert has_assertion_pass("Context admin recovery render/handler runtime: PASS\n")
    assert has_assertion_pass("PLUGIN_LIFECYCLE_PREFLIGHT: PASS 9 added checks\n")
    assert not has_assertion_pass("PASS: first success\nFAIL: second\n")
    assert not has_assertion_pass("SUMMARY pass=0 fail=0\n")
    assert not has_assertion_pass("SUMMARY pass=8 fail=1\n")
    assert not has_assertion_pass("No syntax errors detected\n")

    assert command_for("tests/production_readiness_registry_contract.py")[0] == sys.executable
    assert command_for("tests/remote-execution/operator-grants-convergence-smoke.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/generation-snapshot-exception-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/adaptive-interaction-broker/contract-test.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/no-input-read-ability-schema-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/operator-doctor-contract.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-secondary-diagnostic-exception-runtime.php")[0] == "php"
    assert command_for("tests/remote-execution/operator-bridge-admission.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-domain-executors-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-native-owner-receipts-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-only-local-early-auth-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-grant-mode-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-model-routing-worker-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-worker-guard-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-crossenv-scope-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-credential-expiry-hermetic.php")[0] == "php"
    for path in (
        "tests/switchers/test-market-compatibility.php",
        "tests/switchers/test-market-context-boundaries.php",
        "wp-content/plugins/mad4b-site-control-plane/tests/market-bootstrap-compatibility.php",
    ):
        assert command_for(path) == ["php", str((ROOT / path).resolve())]
    assert not has_assertion_pass("UNREVIEWED_TEST=PASS\n")
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-read-abilities-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-cron-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/market-source-calendar-authority-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/provider-setup-coverage-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/authority-setup-fields-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/background-authorization-verified-identity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-policy-gate-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-queue-extension-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-workflow-inspect-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-pipeline-admission-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-preflight-denial-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/passwordless-mcp-identity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/cross-plugin-lifecycle-admission-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/browser-operator-integrity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/plugin-lifecycle-preflight-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/local-client-request-boundary-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-admin-integrity-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/google-revocation-boundary-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/oauth-resource-signature-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-admin-rest-permissions-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/media-inventory-authorization-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-source-repair-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/unified-approval-ai-first/local-rest-fastpath-contract.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/public-holiday-hermetic-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/application-password-agent-bridge-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-media-evidence-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/ai-review-lifecycle-history-runtime.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/provider-connections-admin-read-hermetic.php")[0] == "php"
    assert command_for("wp-content/plugins/mad4b-site-control-plane/tests/context-ai-agent-review-runtime.php")[0] == "php"
    try:
        command_for("wp-content/plugins/mad4b-site-control-plane/tests/runtime-gateway-cookie-http.php")
    except ValueError:
        pass
    else:
        raise AssertionError("WP-authenticated HTTP fixture must not run as bare standalone PHP")
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
