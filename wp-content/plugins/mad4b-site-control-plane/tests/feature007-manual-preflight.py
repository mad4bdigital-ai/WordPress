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
EXT = Path("specs/007-content-intelligence-workflow-platform/extensions/competitive-experience")
LINT = (
    "class-mad4b-scp-agent-registry.php",
    "class-mad4b-scp-assistant-planning.php",
    "class-mad4b-scp-assistant-bootstrap-diagnostic.php",
    "class-mad4b-scp-assistant-convergence.php",
    "class-mad4b-scp-assistant-task-contract.php",
    "class-mad4b-scp-assistant-read-work-operations.php",
    "class-mad4b-scp-oauth-consent-projection-view.php",
    "class-mad4b-scp-developer-runtime.php",
    "class-mad4b-scp-developer-host-capabilities.php",
    "class-mad4b-scp-full-staging-authority.php",
    "class-mad4b-scp-local-oauth-server.php",
)
FIXTURES = (
    "assistant-planning-runtime.php",
    "assistant-bootstrap-diagnostic-runtime.php",
    "assistant-entrypoint-registration-runtime.php",
    "assistant-convergence-runtime.php",
    "assistant-read-work-runtime.php",
    "oauth-consent-projection-view-runtime.php",
    "g7-compensation-safety-runtime.php",
    "g8-automation-slo-runtime.php",
    "g9-resilience-gates-runtime.php",
    "g9-resilience-state-runtime.php",
)
PY_CHECKS = (
    "oauth-consent-script-syntax.py",
    "developer-runtime-contract.py",
    "full-staging-authority-contract.py",
    "g6-delivery-integrity.py",
    "g9-delivery-contract.py",
    "g9-security-source-contract.py",
)

def digest(data):
    return hashlib.sha256(data).hexdigest()

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
        result = run(root, ["git", "-C", str(root), *arguments])
        if result["result"] != "PASS":
            raise ValueError("GIT_CHECK_FAILED:" + " ".join(arguments[:2]))
        return subprocess.check_output(["git", "-C", str(root), *arguments],
                                       text=True, timeout=20).strip()
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
    if len(paths) != ledger["changed_file_count"] or any(owners.count(p) != 1 for p in paths):
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
    return {"changed_paths": len(paths), "path_sha256": actual,
            "workflow_sha256": digest(workflow), "G6_G8_hashes_match": True}

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
        assert digest(b"abc") == "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        print("OFFLINE_PRECHECK_SELFTEST_PASS; not a release certificate")
        return 0
    if not args.expected_head or not args.base_sha or not args.report:
        parser.error("Exact --expected-head, --base-sha and --report are required")
    if any(not re.fullmatch(r"[a-f0-9]{40}", x) for x in (args.expected_head, args.base_sha)):
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
    except (ValueError, OSError, subprocess.CalledProcessError, json.JSONDecodeError, KeyError) as exc:
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
                if filename == "oauth-consent-script-syntax.py" and shutil.which("node") is None:
                    receipt["results"].append({"case": "python:" + filename,
                                               "result": "BLOCKED", "reason": "NODE_RUNTIME_UNAVAILABLE"})
                    continue
                result = run(root, [sys.executable, str(PLUGIN / "tests" / filename)], env)
                result["case"] = "python:" + filename
                receipt["results"].append(result)
        states = [item["result"] for item in receipt["results"]]
        receipt["status"] = ("FAIL" if "FAIL" in states else
                             "BLOCKED" if "BLOCKED" in states else
                             "LOCAL_SOURCE_CHECKS_PASS_EXTERNAL_ACCEPTANCE_PENDING")
    report.parent.mkdir(parents=True, exist_ok=True)
    report.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({"head": args.expected_head, "status": receipt["status"],
                      "report": str(report)}, sort_keys=True))
    return 1 if receipt["status"] == "FAIL" else 2 if receipt["status"] == "BLOCKED" else 0

if __name__ == "__main__":
    sys.exit(main())
