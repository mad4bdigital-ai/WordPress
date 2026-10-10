#!/usr/bin/env python3
"""Exact-SHA, non-authorizing local CI evidence for any WordPress hosting target.

The runner executes read-only repository fixtures on a disposable checkout.
It NEVER installs WordPress, changes the hosted site, promotes a release, or
converts local observations to official GitHub or Staging certification.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

CONTRACT = "mad4b.local-ci-multi-environment.v1"
REPO = "mad4bdigital-ai/WordPress"
SHA = re.compile(r"^[a-f0-9]{40}$")
REF = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9._/-]{0,119}$")
TEST_ROOT = "wp-content/plugins/mad4b-site-control-plane/tests/"
DOCKER_IMAGES = {
    "php": "php:8.3-cli@sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319",
    "python": "python:3.11-slim",
}
# Named gates, not the complete GitHub Actions job graph. Fail closed on coverage.
GATES = [
    ("g9-delivery", "python", "g9-delivery-contract.py"),
    ("g9-source", "python", "g9-security-source-contract.py"),
    ("g9-resilience", "php", "g9-resilience-gates-runtime.php"),
    ("g9-restore", "php", "g9-resilience-state-runtime.php"),
    ("g9-fleet", "php", "g9-fleet-rollout-runtime.php"),
    ("selected-head-contract", "python", "selected-head-update-contract.py"),
    ("selected-head-disabled", "php", "selected-head-update-runtime.php", "disabled"),
    ("selected-head-normal", "php", "selected-head-update-runtime.php", "normal"),
    ("selected-head-master", "php", "selected-head-update-runtime.php", "master"),
    ("selected-head-missing", "php", "selected-head-update-runtime.php", "missing"),
    ("selected-head-uncertified", "php", "selected-head-update-runtime.php", "uncertified"),
    ("selected-head-drift", "php", "selected-head-update-runtime.php", "drift"),
    ("selected-head-bad-archive", "php", "selected-head-update-runtime.php", "bad-archive"),
    ("staging-selector", "php", "staging-source-selector-runtime.php"),
    ("staging-upload-contract", "python", "self-update-staging-candidate-contract.py"),
    ("release-channel-contract", "python", "self-update-dual-channel-contract.py"),
    ("runtime-release-set", "python", "runtime-release-set-contract.py"),
    ("imp07-source", "python", "imp07-import-source-contract.py"),
    ("imp07-runtime", "php", "imp07-import-source-runtime.php"),
    ("enrollment-dispatch", "php", "enrollment-dispatch-runtime.php"),
]
CORE_NAMES = frozenset(("g9-delivery", "g9-source", "selected-head-contract",
                        "selected-head-normal", "selected-head-drift",
                        "selected-head-bad-archive", "staging-selector",
                        "staging-upload-contract", "release-channel-contract",
                        "imp07-source", "enrollment-dispatch"))


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def run(cmd, cwd=None, timeout=120):
    """No shell or output execution. Input must come from local trusted control."""
    try:
        result = subprocess.run(cmd, cwd=str(cwd) if cwd else None, capture_output=True,
                                text=True, timeout=timeout, errors="replace", check=False)
        return result.returncode, (result.stdout + "\n" + result.stderr)[-2500:]
    except (OSError, subprocess.TimeoutExpired) as exc:
        return 125, type(exc).__name__ + ": " + str(exc)[:400]


def clean_reference(kind, ref):
    if kind == "commit":
        if not SHA.fullmatch(ref):
            raise ValueError("commit must be an exact lowercase 40-character SHA")
    elif kind == "pull_request":
        if not re.fullmatch(r"[1-9][0-9]{0,8}", ref):
            raise ValueError("PR must be a positive decimal number")
    elif kind == "branch":
        if (not REF.fullmatch(ref) or ".." in ref or "//" in ref
                or ref.endswith(("/", ".", ".lock"))):
            raise ValueError("unsafe branch reference")
    else:
        raise ValueError("unsupported source type")
    return ref


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def resolve(kind, ref, repo=REPO):
    if repo != REPO:
        raise ValueError("repository not allowlisted by this runner")
    clean_reference(kind, ref)
    if kind == "commit":
        return ref
    path = ("pulls/" + ref) if kind == "pull_request" else ("branches/" + urllib.parse.quote(ref, safe=""))
    url = "https://api.github.com/repos/" + repo + "/" + path
    opener = urllib.request.build_opener(NoRedirect())
    req = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "MAD4B-Local-CI-Exact-Source",
    })
    with opener.open(req, timeout=12) as res:
        if res.status != 200:
            raise ValueError("source lookup failed")
        raw = res.read(262145)
    if len(raw) > 262144:
        raise ValueError("source lookup exceeded bound")
    obj = json.loads(raw)
    if kind == "pull_request":
        source_repo = ((obj.get("head") or {}).get("repo") or {}).get("full_name", "")
        if obj.get("state") != "open" or source_repo.lower() != repo.lower():
            raise ValueError("closed/foreign PR is ineligible")
        digest = (obj.get("head") or {}).get("sha")
    else:
        digest = (obj.get("commit") or {}).get("sha")
    if not isinstance(digest, str) or not SHA.fullmatch(digest):
        raise ValueError("GitHub returned invalid exact SHA")
    return digest


def docker_ready():
    if shutil.which("docker") is None:
        return False
    code, _ = run(["docker", "info", "--format", "{{.ServerVersion}}"], timeout=10)
    return code == 0


def execute_test(runtime, source, kind, args, timeout):
    if runtime == "docker":
        image = DOCKER_IMAGES[kind]
        inspect, _ = run(["docker", "image", "inspect", image], timeout=10)
        if inspect:
            return {"state": "NOT_RUN", "reason": "docker_image_missing:" + image}
        cmd = ["docker", "run", "--rm", "--network", "none", "--read-only",
               "--mount", "type=bind,source=" + str(source) + ",target=/source,readonly",
               "--tmpfs", "/tmp:rw,nosuid,size=64m", "--workdir", "/source",
               image, "php" if kind == "php" else "python3", *args]
    else:
        executable = shutil.which("php" if kind == "php" else "python3")
        if executable is None and kind == "python":
            executable = sys.executable
        if executable is None:
            return {"state": "NOT_RUN", "reason": "native_runtime_missing:" + kind}
        cmd = [executable, *args]
    code, output = run(cmd, cwd=source, timeout=timeout)
    return {"state": "PASS" if code == 0 else "FAIL", "exit_code": code,
            "output_sha256": sha256(output.encode()), "output_tail": output[-1000:]}


def inspect_site_evidence(path, expected_host, expected_sha):
    """MCP/WP-CLI host snapshot is observational, never a signing authority."""
    if not path:
        return {"state": "NOT_RUN", "reason": "host_snapshot_not_supplied"}
    obj = json.loads(Path(path).read_text(encoding="utf-8"))
    required = ("target_type", "site_url", "wordpress_environment",
                "profile_environment", "installed_source_sha")
    if not isinstance(obj, dict) or any(not isinstance(obj.get(k), str) for k in required):
        raise ValueError("host snapshot schema invalid")
    if obj["target_type"] not in ("hostinger", "wordpress_hosted", "wordpress_local"):
        raise ValueError("unknown site target type")
    if not obj["site_url"].startswith("https://") and obj["target_type"] != "wordpress_local":
        raise ValueError("remote WordPress site must be HTTPS")
    if expected_host and obj["site_url"].rstrip("/") != expected_host.rstrip("/"):
        raise ValueError("site URL differs from selected target")
    if not SHA.fullmatch(obj["installed_source_sha"]):
        raise ValueError("host snapshot source SHA invalid")
    return {"state": "OBSERVED_NOT_CERTIFIED", "target_type": obj["target_type"],
            "site_url": obj["site_url"], "wordpress_environment": obj["wordpress_environment"],
            "profile_environment": obj["profile_environment"],
            "installed_source_sha": obj["installed_source_sha"],
            "candidate_already_installed": obj["installed_source_sha"] == expected_sha,
            "deployment_binding_ready": obj.get("deployment_binding_ready") is True,
            "host_snapshot_signed": False, "host_mutation_performed": False}


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--repository-path", required=True)
    ap.add_argument("--source-type", choices=("commit", "pull_request", "branch"), required=True)
    ap.add_argument("--source-reference", required=True)
    ap.add_argument("--expected-sha", required=True)
    ap.add_argument("--repository", default=REPO)
    ap.add_argument("--runtime", choices=("auto", "docker", "native"), default="auto")
    ap.add_argument("--allow-native-execution", action="store_true")
    ap.add_argument("--profile", choices=("core", "extended"), default="extended")
    ap.add_argument("--output", required=True)
    ap.add_argument("--target-type", choices=("hostinger", "wordpress_hosted", "wordpress_local"), default="hostinger")
    ap.add_argument("--site-evidence")
    ap.add_argument("--expected-site-url", default="")
    ap.add_argument("--timeout", type=int, default=90)
    args = ap.parse_args(argv)
    out = Path(args.output).resolve()
    out.mkdir(parents=True, exist_ok=True)
    report = {"contract": CONTRACT, "repository": args.repository,
              "selection": {"type": args.source_type, "reference": args.source_reference},
              "exact_sha": args.expected_sha, "profile": args.profile,
              "hosting_target": args.target_type, "github_ci_certified": False,
              "staging_certified": False, "production_authorized": False,
              "release_promotion_authorized": False, "host_mutation_performed": False,
              "checks": [], "site": {}, "parity_verdict": "LOCAL_CI_PARITY_PARTIAL"}
    try:
        if not SHA.fullmatch(args.expected_sha):
            raise ValueError("expected SHA must be exact lowercase")
        if args.repository != REPO:
            raise ValueError("repository not on this runner's allowlist")
        clean_reference(args.source_type, args.source_reference)
        if resolve(args.source_type, args.source_reference, args.repository) != args.expected_sha:
            raise ValueError("source HEAD changed before tests")
        repo = Path(args.repository_path).resolve()
        if not (repo / ".git").exists():
            raise ValueError("repository path must be a Git checkout")
        runtime = args.runtime
        if runtime == "auto":
            runtime = "docker" if docker_ready() else "native"
        if runtime == "native" and not args.allow_native_execution:
            raise ValueError("native PR code execution requires --allow-native-execution")
        report["runtime"] = runtime
        report["source_verified_before"] = True
        with tempfile.TemporaryDirectory(prefix="mad4b-local-ci-") as temp:
            checkout = Path(temp) / "source"
            code, output = run(["git", "clone", "--quiet", "--no-local", str(repo), str(checkout)], timeout=120)
            if code:
                raise ValueError("isolated checkout failed: " + output)
            code, output = run(["git", "checkout", "--quiet", "--detach", args.expected_sha], cwd=checkout)
            if code:
                raise ValueError("exact commit not present in local Git: " + output)
            code, output = run(["git", "rev-parse", "HEAD"], cwd=checkout)
            if code or output.strip() != args.expected_sha:
                raise ValueError("isolated commit identity mismatch")
            report["tests"] = len(GATES) if args.profile == "extended" else len(CORE_NAMES)
            choices = [g for g in GATES if args.profile == "extended" or g[0] in CORE_NAMES]
            for name, kind, filename, *parameters in choices:
                rel = TEST_ROOT + filename
                if not (checkout / rel).is_file():
                    result = {"state": "FAIL", "reason": "required_test_missing"}
                else:
                    result = execute_test(runtime, checkout, kind, [rel, *parameters], args.timeout)
                report["checks"].append({"name": name, "language": kind, **result})
            if resolve(args.source_type, args.source_reference, args.repository) != args.expected_sha:
                raise ValueError("source HEAD moved during the run")
            report["source_verified_after"] = True
        report["site"] = inspect_site_evidence(args.site_evidence, args.expected_site_url, args.expected_sha)
        if report["site"].get("target_type") not in (None, args.target_type):
            raise ValueError("site snapshot host type conflicts with target type")
        counts = {s: sum(c["state"] == s for c in report["checks"])
                  for s in ("PASS", "FAIL", "NOT_RUN")}
        report["counts"] = counts
        if counts["FAIL"]:
            report["parity_verdict"] = "LOCAL_CI_PARITY_FAIL"
        else:
            # 75 workflow files are not all represented here: never claim full parity.
            report["parity_verdict"] = "LOCAL_CI_PARITY_PARTIAL"
        report["tested_gate_set_passed"] = counts["FAIL"] == 0 and counts["NOT_RUN"] == 0
        report["coverage"] = {"suite": "targeted_repo_contracts", "all_github_workflows_reproduced": False,
                              "wordpress_database_integration_run": False,
                              "browser_matrix_run": False, "hostinger_live_acceptance_run": False}
    except Exception as exc:
        report["parity_verdict"] = "LOCAL_CI_PARITY_FAIL"
        report["blocker"] = type(exc).__name__ + ": " + str(exc)[:500]
    receipt = json.dumps(report, sort_keys=True, indent=2) + "\n"
    (out / "LOCAL-CI-PARITY-REPORT.json").write_text(receipt, encoding="utf-8")
    print(json.dumps({"verdict": report["parity_verdict"], "head": args.expected_sha,
                      "counts": report.get("counts"), "blocker": report.get("blocker"),
                      "report": str(out / "LOCAL-CI-PARITY-REPORT.json")}, sort_keys=True))
    return 0 if report.get("tested_gate_set_passed") and report.get("source_verified_after") else 1


if __name__ == "__main__":
    sys.exit(main())
