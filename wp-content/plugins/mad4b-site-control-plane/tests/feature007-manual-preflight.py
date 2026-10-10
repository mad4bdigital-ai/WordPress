#!/usr/bin/env python3
"""Exact-head, offline Feature 007 source preflight. Never a release certificate."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

PLUGIN = Path("wp-content/plugins/mad4b-site-control-plane")
WF = Path(".github/workflows/feature-007-spec-ci.yml")
LEDGER = Path("specs/007-content-intelligence-workflow-platform/change-slices.json")
ACI01_INTEGRITY = Path("specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/task-integrity.py")
EXT = Path("specs/007-content-intelligence-workflow-platform/extensions/competitive-experience")
LINT = (
    "class-mad4b-scp-agent-registry.php",
    "class-mad4b-scp-assistant-planning.php",
    "class-mad4b-scp-assistant-bootstrap-diagnostic.php",
    "class-mad4b-scp-assistant-convergence.php",
    "class-mad4b-scp-solution-discovery.php",
    "class-mad4b-scp-capability-atlas.php",
    "class-mad4b-scp-assistant-solution-router.php",
    "class-mad4b-scp-assistant-task-contract.php",
    "class-mad4b-scp-assistant-task-journal-bridge.php",
    "class-mad4b-scp-operation-journal.php",
    "class-mad4b-scp-assistant-read-work-operations.php",
    "class-mad4b-scp-remote-work-queue.php",
    "class-mad4b-scp-oauth-consent-projection-view.php",
    "class-mad4b-scp-developer-runtime.php",
    "class-mad4b-scp-developer-host-capabilities.php",
    "class-mad4b-scp-full-staging-authority.php",
    "class-mad4b-scp-staging-write-authority-convergence.php",
    "class-mad4b-scp-browser-acceptance-admin-ui.php",
    "class-mad4b-scp-local-oauth-server.php",
    "class-mad4b-scp-assistant-operator-workspace.php",
    "class-mad4b-scp-aci01-intake-preview.php",
    "class-mad4b-scp-aci01-evidence-preview.php",
    "class-mad4b-scp-aci01-opportunity-preview.php",
    "class-mad4b-scp-aci01-runtime-binding.php",
    "class-mad4b-scp-aci01-semantic-recipe.php",
    "class-mad4b-scp-aci01-native-relation-audit.php",
    "class-mad4b-scp-aci01-recipe-gap.php",
    "class-mad4b-scp-content-experience-governance.php",
    "class-mad4b-scp-content-experience-profiles.php",
    "class-mad4b-scp-content-intelligence-pipeline.php",
    "class-mad4b-scp-staging-certification.php",
    "class-mad4b-scp-operational-remediation.php",
    "class-mad4b-scp-skill-abilities.php",
    "class-mad4b-scp-context-authority.php",
    "class-mad4b-scp-brand-context-reconstruction.php",
    "class-mad4b-scp-market-growth-policies.php",
    "class-mad4b-scp-market-content-exchange.php",
    "class-mad4b-scp-business-activity-contracts.php",
    "class-mad4b-scp-activity-source-reconciliation.php",
    "class-mad4b-scp-activity-sync-runtime.php",
    "class-mad4b-scp-activity-google-docs-adapter.php",
    "class-mad4b-scp-activity-import-review.php",
    "class-mad4b-scp-activity-import-modes.php",
    "class-mad4b-scp-activity-import-authority.php",
    "class-mad4b-scp-activity-import-snapshot.php",
    "class-mad4b-scp-activity-import-reconciliation.php",
    "class-mad4b-scp-activity-wpai-observer.php",
    "class-mad4b-scp-activity-import-experience.php",
    "class-mad4b-scp-activity-import-xlsx.php",
    "class-mad4b-scp-activity-import-batches.php",
    "class-mad4b-scp-import-acceptance-gates.php",
    "class-mad4b-scp-import-mapping-evolution.php",
    "class-mad4b-scp-import-wpml-readback.php",
    "class-mad4b-scp-import-schema-onboarding.php",
    "class-mad4b-scp-external-media-ingest.php",
    "class-mad4b-scp-content-experience-profiles.php",
    "adapters/class-mad4b-scp-media-adapter.php",
    "class-mad4b-scp-recovery-attempt-budget.php",
    "adapters/class-mad4b-scp-context-adapter.php",
    "adapters/class-mad4b-scp-dynamic-content-adapter.php",
    "class-mad4b-scp-chatgpt-tool-projection.php",
    "class-mad4b-scp-host-bridge.php",
    "adapters/class-mad4b-scp-aci01-read-adapter.php",
)
FIXTURES = (
    "assistant-planning-runtime.php",
    "assistant-bootstrap-diagnostic-runtime.php",
    "assistant-convergence-runtime.php",
    "solution-discovery-runtime.php",
    "capability-atlas-contract.php",
    "assistant-solution-router-runtime.php",
    "assistant-task-journal-bridge-runtime.php",
    "operation-journal-exact-cas-runtime.php",
    "staging-write-authority-postcondition-runtime.php",
    "staging-convergence-coverage-runtime.php",
    "operational-remediation-control-runtime.php",
    "skill-context-preflight-runtime.php",
    "brand-context-reconstruction-state-machine-runtime.php",
    "market-growth-competitor-dmc-runtime.php",
    "business-activity-contract-runtime.php",
    "business-activity-source-reconciliation-runtime.php",
    "business-activity-sync-runtime.php",
    "imp01-import-preview-runtime.php",
    "imp04-reconciliation-runtime.php",
    "imp05-wpai-observer-runtime.php",
    "imp06-import-ux-runtime.php",
    "imp07-import-source-runtime.php",
    "imp08-batch-review-runtime.php",
    "imp09-import-acceptance-runtime.php",
    "imp10-mapping-evolution-runtime.php",
    "imp10-wpml-readback-runtime.php",
    "imp12-schema-onboarding-runtime.php",
    "imp13-commercial-source-safety-runtime.php",
    "projection-registration-lifecycle.php",
    "host-bridge-contract.php",
    "browser-acceptance-admin-setup-contract.php",
    "staging-browser-site-selection-contract.php",
    "assistant-read-work-runtime.php",
    "assistant-entrypoint-registration-runtime.php",
    "oauth-consent-projection-view-runtime.php",
    "g7-compensation-safety-runtime.php",
    "g8-automation-slo-runtime.php",
    "g9-resilience-gates-runtime.php",
    "g9-resilience-state-runtime.php",
    "assistant-operator-workspace-runtime.php",
    "aci01-intake-preview-contract.php",
    "aci01-evidence-read-contract.php",
    "aci01-opportunity-candidate-contract.php",
    "aci01-p0-governed-contract.php",
    "aci01-native-relation-audit-contract.php",
    "aci01-recipe-gap-contract.php",
    "runtime-content-experience-smoke.php",
    "content-intelligence-pipeline-contract.php",
)
NODE_FIXTURES = (
    "tools/solution-discovery/test-federation.mjs",
    "tools/solution-discovery/test-source-evidence.mjs",
)
PY_CHECKS = (
    "oauth-consent-script-syntax.py",
    "developer-runtime-contract.py",
    "full-staging-authority-contract.py",
    "staging-write-authority-convergence-contract.py",
    "staging-certification-contract.py",
    "operational-remediation-source-contract.py",
    "governed-tour-publication-handoff-contract.py",
    "brand-context-reconstruction-source-contract.py",
    "market-growth-competitor-dmc-source-contract.py",
    "business-activity-multisource-source-contract.py",
    "business-activity-sync-runtime-contract.py",
    "imp01-import-source-contract.py",
    "imp02-import-modes-contract.py",
    "imp03-governed-import-contract.py",
    "imp04-imp05-source-contract.py",
    "imp06-import-ux-source-contract.py",
    "imp07-import-source-contract.py",
    "imp08-batch-source-contract.py",
    "imp09-import-acceptance-source-contract.py",
    "imp10-mapping-wpml-source-contract.py",
    "imp11-batch-mapping-source-contract.py",
    "imp12-schema-onboarding-source-contract.py",
    "imp13-archival-and-commercial-source-contract.py",
    "chatgpt-dynamic-tool-projection-contract.py",
    "host-runner-kernel-contract.py",
    "host-environment-sync-runner-contract.py",
    "g6-delivery-integrity.py",
    "g9-delivery-contract.py",
    "g9-security-source-contract.py",
)
INTEGRITY_ERRORS = (ValueError, OSError, subprocess.CalledProcessError,
                    subprocess.TimeoutExpired, json.JSONDecodeError, KeyError, TypeError)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def exact_git_sha(value):
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{40}", value) is not None

def run(root, args, env=None, timeout=50):
    try:
        result = subprocess.run(args, cwd=root, env=env, capture_output=True,
                                text=True, stdin=subprocess.DEVNULL,
                                timeout=timeout, check=False)
        return {"result": "PASS" if result.returncode == 0 else "FAIL",
                "exit_code": result.returncode,
                "output_sha256": digest((result.stdout + result.stderr).encode()),
                "output_tail": (result.stdout + result.stderr)[-400:]}
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {"result": "FAIL", "exception": type(exc).__name__}

def integrity(root, base, head):
    def git(*arguments):
        try:
            return subprocess.check_output(["git", "-C", str(root), *arguments],
                                           stderr=subprocess.PIPE, text=True, timeout=20).strip()
        except subprocess.CalledProcessError:
            raise ValueError("GIT_CHECK_FAILED:" + " ".join(arguments[:2]))
    if git("rev-parse", "HEAD") != head:
        raise ValueError("HEAD_MISMATCH")
    if git("status", "--porcelain", "--untracked-files=all"):
        raise ValueError("WORKTREE_DIRTY")
    x = subprocess.run(["git", "-C", str(root), "merge-base", "--is-ancestor", base, head],
                       capture_output=True, timeout=20)
    if x.returncode:
        raise ValueError("BASE_NOT_ANCESTOR")
    paths = sorted(git("diff", "--name-only", base + "..." + head).splitlines())
    ledger = json.loads((root / LEDGER).read_text(encoding="utf-8"))
    owners = [p for item in ledger["slices"] for p in item["path_globs"]]
    if len(paths) != ledger["changed_file_count"] or sorted(owners) != paths:
        # The registry must own exactly these paths, not stale or surplus entries.
        raise ValueError("CUMULATIVE_OWNERSHIP_INVALID")
    actual = digest(("\n".join(paths) + "\n").encode())
    if actual != ledger["changed_paths_sha256"]:
        raise ValueError("CUMULATIVE_PATH_HASH_INVALID")
    workflow = (root / WF).read_bytes()
    if b"  ga-gb-assistant-planning:" not in workflow or b"  oauth-operational-ux:" not in workflow:
        raise ValueError("REQUIRED_CI_JOBS_MISSING")
    for group in ("g6", "g8"):
        record = json.loads((root / EXT / (group + "-delivery.json")).read_text(encoding="utf-8"))
        items = [p for p in record["workflow_paths"] if p["path"] == str(WF)]
        if len(items) != 1 or items[0]["sha256"] != digest(workflow) or items[0]["bytes"] != len(workflow):
            raise ValueError("WORKFLOW_MANIFEST_DRIFT:" + group)
    tree = git("rev-parse", "HEAD^{tree}")
    # Recheck identity and cleanliness after reading the bound metadata too.
    if git("rev-parse", "HEAD") != head:
        raise ValueError("HEAD_MISMATCH")
    if git("status", "--porcelain", "--untracked-files=all"):
        raise ValueError("WORKTREE_DIRTY")
    return {"changed_paths": len(paths), "path_sha256": actual,
            "workflow_sha256": digest(workflow), "source_tree_sha": tree,
            "G6_G8_hashes_match": True}

def python_check(root, filename, env):
    case = "python:" + filename
    if filename in ("host-runner-kernel-contract.py",
                    "host-environment-sync-runner-contract.py") and os.name != "posix":
        return {"case": case, "result": "BLOCKED",
                "reason": "POSIX_HOST_RUNNER_REQUIRED"}
    dependencies = {
        "oauth-consent-script-syntax.py": ("node", "NODE_RUNTIME_UNAVAILABLE"),
        # This contract invokes generic `php -l` itself, independently of the
        # two explicit PHP matrix executable names. Missing php is no code FAIL.
        "developer-runtime-contract.py": ("php", "PHP_LINTER_RUNTIME_UNAVAILABLE"),
    }
    if filename in dependencies:
        executable, reason = dependencies[filename]
        if shutil.which(executable, path=env["PATH"]) is None:
            return {"case": case, "result": "BLOCKED", "reason": reason,
                    "required_executable": executable}
    result = run(root, [sys.executable, "-B", str(PLUGIN / "tests" / filename)], env)
    result["case"] = case
    return result

def final_integrity(receipt, root, base, head):
    receipt["source_immutable_verified"] = False
    if "integrity_error" in receipt:
        return
    try:
        final = integrity(root, base, head)
        if final != receipt["integrity"]:
            raise ValueError("SOURCE_SNAPSHOT_CHANGED")
        receipt["final_integrity"] = final
        receipt["source_immutable_verified"] = True
    except INTEGRITY_ERRORS as exc:
        receipt["final_integrity_error"] = str(exc)
        receipt["status"] = "FAIL"

def self_test():
    from unittest.mock import patch
    assert digest(b"abc") == "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
    assert exact_git_sha("a" * 40)
    for malformed in ("a" * 39, "a" * 41, "A" * 40, "a" * 40 + "\n", None, 1):
        assert not exact_git_sha(malformed), "Malformed exact head accepted"
    calls = []
    def failed_check(root, command, env):
        calls.append(command)
        return {"result": "FAIL", "exit_code": 1}
    with patch.object(shutil, "which", return_value=None), patch.dict(globals(), {"run": failed_check}):
        blocked = python_check(Path("."), "developer-runtime-contract.py", {"PATH": ""})
        assert blocked["result"] == "BLOCKED" and blocked["required_executable"] == "php"
        assert not calls, "Missing PHP must not execute a known PHP-dependent contract"
        blocked_node = python_check(Path("."), "oauth-consent-script-syntax.py", {"PATH": ""})
        assert blocked_node["result"] == "BLOCKED" and not calls
        failed = python_check(Path("."), "g9-delivery-contract.py", {"PATH": ""})
        assert failed["result"] == "FAIL" and len(calls) == 1, "Source FAIL must not become BLOCKED"
    snapshot = {"source_tree_sha": "a" * 40, "workflow_sha256": "b" * 64}
    with patch.dict(globals(), {"integrity": lambda *args: dict(snapshot)}):
        receipt = {"integrity": dict(snapshot), "status": "BLOCKED"}
        final_integrity(receipt, Path("."), "a" * 40, "b" * 40)
        assert receipt["source_immutable_verified"] and receipt["status"] == "BLOCKED"
        receipt = {"integrity": dict(snapshot, workflow_sha256="c" * 64), "status": "BLOCKED"}
        final_integrity(receipt, Path("."), "a" * 40, "b" * 40)
        assert receipt["status"] == "FAIL" and receipt["final_integrity_error"] == "SOURCE_SNAPSHOT_CHANGED"
    for reason in ("HEAD_MISMATCH", "WORKTREE_DIRTY"):
        def changed(*arguments):
            raise ValueError(reason)
        with patch.dict(globals(), {"integrity": changed}):
            receipt = {"integrity": snapshot, "status": "BLOCKED"}
            final_integrity(receipt, Path("."), "a" * 40, "b" * 40)
            assert receipt["status"] == "FAIL" and not receipt["source_immutable_verified"]
            assert receipt["final_integrity_error"] == reason
    print("OFFLINE_PRECHECK_SELFTEST_PASS; SHA, missing-engine, source-FAIL and immutable-source denials; not a release certificate")

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--expected-head")
    parser.add_argument("--base-sha")
    parser.add_argument("--php74", default="php7.4")
    parser.add_argument("--php83", default="php8.3")
    parser.add_argument("--report")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    if not args.expected_head or not args.base_sha or not args.report:
        parser.error("Exact --expected-head, --base-sha and --report are required")
    if any(not exact_git_sha(x) for x in (args.expected_head, args.base_sha)):
        parser.error("A full exact Git SHA is mandatory")
    root = Path(__file__).resolve().parents[4]
    report = Path(args.report).resolve()
    if report == root or root in report.parents:
        parser.error("Report must be outside source tree")
    receipt = {"contract": "mad4b.feature007-manual-repository-preflight.v1",
               "observed_at": datetime.now(timezone.utc).isoformat(),
               "head": args.expected_head, "base": args.base_sha,
               "release_certified": False, "staging_accepted": False,
               "production_authorized": False, "host_isolation_certified": False,
               "external_provider_certified": False, "github_ci_certified": False,
               "network_isolated": False, "results": []}
    try:
        receipt["integrity"] = integrity(root, args.base_sha, args.expected_head)
    except INTEGRITY_ERRORS as exc:
        receipt["integrity_error"] = str(exc)
        receipt["status"] = "FAIL"
    if "integrity_error" not in receipt:
        with tempfile.TemporaryDirectory(prefix="mad4b-offline-ci-") as temp:
            env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
                   "HOME": temp, "TMPDIR": temp, "TMP": temp, "TEMP": temp,
                   "LANG": "C", "LC_ALL": "C"}
            # Windows child-process loader needs these OS-owned variables.
            # Do not forward database, OAuth, provider or cloud credentials.
            for key in ("SystemRoot", "WINDIR", "PATHEXT"):
                if key in os.environ:
                    env[key] = os.environ[key]
            for version, name in (("7.4", args.php74), ("8.3", args.php83)):
                binary = shutil.which(name)
                if binary is None:
                    receipt["results"].append({"case": "PHP_" + version, "result": "BLOCKED",
                                               "reason": "EXACT_PHP_RUNTIME_UNAVAILABLE"})
                    continue
                check = run(root, [binary, "-r", "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"], env)
                if check["result"] == "PASS" and check["output_tail"] != version:
                    check["result"] = "FAIL"
                    check["reason"] = "WRONG_INTERPRETER_VERSION"
                check["case"] = "PHP_" + version + "_VERSION"
                receipt["results"].append(check)
                if check["result"] != "PASS":
                    continue
                for filename in LINT:
                    result = run(root, [binary, "-l", str(PLUGIN / "includes" / filename)], env)
                    result["case"] = version + ":lint:" + filename
                    receipt["results"].append(result)
                for filename in FIXTURES:
                    result = run(root, [binary, str(PLUGIN / "tests" / filename)], env)
                    result["case"] = version + ":fixture:" + filename
                    receipt["results"].append(result)
            for filename in PY_CHECKS:
                receipt["results"].append(python_check(root, filename, env))
            spec_result = run(root, [sys.executable, "-B", str(ACI01_INTEGRITY),
                                     "--self-test"], env)
            spec_result["case"] = "aci01:spec-integrity:negative-self-test"
            spec_result["authorizing"] = False
            receipt["results"].append(spec_result)
            for filename in NODE_FIXTURES:
                if shutil.which("node", path=env["PATH"]) is None:
                    receipt["results"].append({"case": "node:" + filename,
                        "result": "BLOCKED", "reason": "NODE_RUNTIME_UNAVAILABLE"})
                else:
                    result = run(root, ["node", filename], env)
                    result["case"] = "node:" + filename
                    receipt["results"].append(result)
        states = [item["result"] for item in receipt["results"]]
        receipt["status"] = ("FAIL" if "FAIL" in states else
                             "BLOCKED" if "BLOCKED" in states else
                             "LOCAL_SOURCE_CHECKS_PASS_EXTERNAL_ACCEPTANCE_PENDING")
    # All test output belongs to the exact starting source only while HEAD,
    # tracked files and bound fingerprints still match after the checks finish.
    final_integrity(receipt, root, args.base_sha, args.expected_head)
    report.parent.mkdir(parents=True, exist_ok=True)
    report.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({"head": args.expected_head, "status": receipt["status"],
                      "report": str(report)}, sort_keys=True))
    return 1 if receipt["status"] == "FAIL" else 2 if receipt["status"] == "BLOCKED" else 0

if __name__ == "__main__":
    sys.exit(main())
