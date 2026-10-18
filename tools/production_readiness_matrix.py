#!/usr/bin/env python3
"""Read-only, fail-closed MAD4B production readiness matrix validator and renderer.

The editable controls.json defines *questions*, not release authority.
This tool never signs evidence, marks Production ready or executes mutations.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
from pathlib import Path
import re
import sys
from urllib import request, error

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "governance/production-readiness/controls.json"
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
SPEC = ROOT / "specs/007-content-intelligence-workflow-platform"
SHA = re.compile(r"^[a-f0-9]{40}$")
ID = re.compile(r"^[A-Z][A-Z0-9_-]+-[0-9]{2,}$")
GATES = ("P0", "P1")
ALLOWED_OBSERVATIONS = {
    "بانتظار دليل حي", "بانتظار CI", "فشل CI",
    "نجح اختبار مصدر", "محجوب بالمصدر",
}
CORE = "control_plane_core_required"


def load(path):
    with Path(path).open(encoding="utf-8") as fh:
        return json.load(fh)


def ensure(condition, explanation):
    if not condition:
        raise ValueError(explanation)


def validate(root=ROOT, source=SOURCE):
    root, source = Path(root).resolve(), Path(source)
    matrix = load(source)
    ensure(matrix.get("contract") == "mad4b.production-readiness-matrix-source.v1",
           "Unexpected matrix source contract")
    ensure(matrix.get("authorizing") is False and
           matrix.get("automated_promotion") is False,
           "Matrix must never authorize or auto-promote Production")
    controls = matrix.get("controls", [])
    ensure(isinstance(controls, list) and len(controls) >= 170,
           "Historical baseline has 170 controls; deletion requires reviewed migration")
    ensure(matrix["evidence_rules"]["repository_ci_is_not_live_evidence"] is True,
           "CI must not be accepted as live evidence")
    ensure(matrix["evidence_rules"]["production_promotion_requires_separate_authorization"] is True,
           "Production authorization cannot be derived from readiness")
    seen = set()
    for entry in controls:
        control_id = entry.get("id", "")
        ensure(isinstance(control_id, str) and ID.fullmatch(control_id),
               "Invalid control ID: " + repr(control_id))
        ensure(control_id not in seen, "Duplicate control ID: " + control_id)
        seen.add(control_id)
        for field in ("domain", "title", "acceptance", "owner_role",
                      "evidence_requirement", "release_scope"):
            ensure(isinstance(entry.get(field), str) and entry[field].strip(),
                   control_id + " requires " + field)
        ensure(entry.get("priority") in GATES,
               "Unknown priority: " + control_id)
        ensure(entry.get("initial_observation") in ALLOWED_OBSERVATIONS,
               "Unrecognized historical observation: " + control_id)
        src = entry.get("source", {})
        ensure(isinstance(src, dict) and len(src) == 1,
               "Control source must be exactly one path or URL: " + control_id)
        if "path" in src:
            rel = src["path"]
            ensure(isinstance(rel, str) and not rel.startswith("/") and
                   ".." not in Path(rel).parts and
                   (root / rel).resolve().is_relative_to(root),
                   "Out-of-bounds source path: " + control_id)
            ensure((root / rel).is_file(), "Missing source file: " + rel)
        else:
            ensure(isinstance(src.get("url"), str) and
                   src["url"].startswith("https://"),
                   "Untrusted source URL: " + control_id)
        # Historical observations can be retained; evidence never promotes from them.
        ensure(entry.get("evidence_url") in (None, "") or
               isinstance(entry["evidence_url"], str), "Invalid historical evidence URL")
    policy = load(root / "wp-content/plugins/mad4b-site-control-plane/config/production-readiness-policy.json")
    stages = load(root / "wp-content/plugins/mad4b-site-control-plane/config/production-certification-plan.json")
    feature = load(root / "wp-content/plugins/mad4b-site-control-plane/config/feature-007-workstream-certification.json")
    closure = load(root / "specs/007-content-intelligence-workflow-platform/release-closure-readiness.json")
    source_cfg = load(root / "wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json")
    ensure(policy.get("production_auto_apply") is False and
           policy.get("ready_is_not_authorized") is True,
           "Repository Production policy no longer fail-closed")
    ensure(stages.get("authorizing") is False and stages.get("production_mutation_performed") is False,
           "Certification plan must be non-authorizing")
    ensure(len(stages.get("stages", [])) >= 14, "Production stage registry lost entries")
    ensure(len(feature.get("workstreams", [])) >= 23, "Feature 007 registry lost entries")
    ensure(closure.get("production_authorized") is False,
           "Repository closure cannot self-authorize production")
    ensure(len(policy.get("required_repository_workflows", [])) >= 10,
           "Required CI workflows unexpectedly missing")
    ensure(len({s["id"] for s in stages["stages"]}) == len(stages["stages"]),
           "Duplicate production stage")
    ensure(len({s["id"] for s in feature["workstreams"]}) == len(feature["workstreams"]),
           "Duplicate workstream")
    return matrix, policy, stages, feature, closure, source_cfg


def github_workflow_results(repo, exact_sha):
    """Best-effort live GitHub metadata, NEVER a live-site acceptance receipt."""
    token = os.environ.get("GITHUB_TOKEN", "")
    if not token or not repo or not SHA.fullmatch(exact_sha):
        return {}, "not_fetched"
    url = "https://api.github.com/repos/{}/actions/runs?head_sha={}&per_page=100".format(
        repo, exact_sha)
    req = request.Request(url, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": "Bearer " + token,
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "mad4b-readiness-matrix-ci",
    })
    try:
        with request.urlopen(req, timeout=12) as response:
            data = json.load(response)
    except (error.HTTPError, error.URLError, TimeoutError, ValueError):
        return {}, "unavailable"
    outcomes = {}
    for run in data.get("workflow_runs", []):
        if run.get("head_sha") != exact_sha:
            continue
        name = run.get("name", "")
        record = {
            "status": run.get("status", "unknown"),
            "conclusion": run.get("conclusion"),
            "run_id": run.get("id"),
            "url": run.get("html_url"),
        }
        old = outcomes.get(name)
        if not old or int(run.get("run_attempt", 1)) >= int(old.get("run_attempt", 0)):
            record["run_attempt"] = int(run.get("run_attempt", 1))
            outcomes[name] = record
    return outcomes, "github_actions_exact_head"


def snapshot(matrix, policy, stages, feature, closure, source_cfg,
             head, repo, workflows, provenance):
    required = policy["required_repository_workflows"]
    checks = [{
        "workflow": name,
        "status": workflows.get(name, {}).get("status", "unknown"),
        "conclusion": workflows.get(name, {}).get("conclusion"),
        "run_url": workflows.get(name, {}).get("url"),
        "exact_head": head,
    } for name in required]
    ci_ok = bool(checks) and all(c["conclusion"] == "success" and
                                 c["status"] == "completed" for c in checks)
    sources = source_cfg.get("sources", [])
    unresolved_sources = [s.get("id", "unknown") for s in sources if
                          s.get("package_certification") != "certified" or
                          not re.fullmatch(r"[a-f0-9]{64}", s.get("artifact_sha256") or "")]
    counts = {
        "controls": len(matrix["controls"]),
        "domains": len({c["domain"] for c in matrix["controls"]}),
        "P0": sum(c["priority"] == "P0" for c in matrix["controls"]),
        "P1": sum(c["priority"] == "P1" for c in matrix["controls"]),
        "production_stages": len(stages["stages"]),
        "feature_workstreams": len(feature["workstreams"]),
        "core_required": len(closure["profiles"][CORE]["workstream_ids"]),
    }
    # Live evidence in git is contextual only; trusted signing and site-bound
    # runtime verification are separate operations, not performed by this job.
    return {
        "contract": "mad4b.production-readiness-evaluation.v1",
        "authorizing": False,
        "mutation_performed": False,
        "source_head_sha": head,
        "repository": repo,
        "decision": "NO-GO",
        "production_ready": False,
        "production_authorized": False,
        "policy_gate": "independent_live_site_evidence_and_promotion_authority_required",
        "ci_exact_head_all_required_pass": ci_ok,
        "ci_source": provenance,
        "required_workflows": checks,
        "totals": counts,
        "production_stages": [{
            "id": s["id"], "evidence_contract": s.get("evidence_contract"),
            "status": "LIVE_ATTESTATION_UNVERIFIED",
            "source": s.get("producer"),
        } for s in stages["stages"]],
        "feature_workstreams": [{
            "id": s["id"], "gate": s.get("gate"),
            "status": "LIVE_ATTESTATION_UNVERIFIED",
        } for s in feature["workstreams"]],
        "core_workstreams": [{
            "id": item,
            "repository_status": next(
                (e.get("status", "EXTERNAL_PENDING")
                 for e in closure["profiles"][CORE]["open_or_partial"]
                 if e.get("id") == item),
                "DONE_REPOSITORY_ONLY"),
            "live_verified_by_matrix_ci": False,
        } for item in closure["profiles"][CORE]["workstream_ids"]],
        "unpromoted_cross_plugin_sources": unresolved_sources,
        "historical_observations_non_authoritative": True,
    }


def render(matrix, report, output):
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    (output / "readiness.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with (output / "controls.csv").open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.writer(fh)
        writer.writerow(("Control ID", "Domain", "Title", "Acceptance", "Priority",
                         "Scope", "Owner", "Required evidence", "Historical observation",
                         "Source", "Live evidence verified", "Production authorized"))
        for c in matrix["controls"]:
            src = c["source"].get("path") or c["source"].get("url")
            writer.writerow((c["id"], c["domain"], c["title"], c["acceptance"],
                             c["priority"], c["release_scope"], c["owner_role"],
                             c["evidence_requirement"], c["initial_observation"], src,
                             "NO", "NO"))
    def write_projection(filename, headers, data):
        with (output / filename).open("w", encoding="utf-8-sig", newline="") as fh:
            writer = csv.writer(fh)
            writer.writerow(headers)
            writer.writerows(data)

    write_projection("production-stages.csv",
        ("Stage ID", "Evidence contract", "Required live receipt status", "Source producer"),
        ((s["id"], s["evidence_contract"], s["status"], s["source"])
         for s in report["production_stages"]))
    write_projection("feature-workstreams.csv",
        ("Workstream ID", "Required gate", "Live evidence status"),
        ((s["id"], s["gate"], s["status"])
         for s in report["feature_workstreams"]))
    write_projection("core-required-gates.csv",
        ("Core workstream", "Repository evidence status", "Live verified by CI"),
        ((s["id"], s["repository_status"], "NO")
         for s in report["core_workstreams"]))
    write_projection("required-ci.csv",
        ("Workflow", "Exact HEAD", "Run status", "Conclusion", "Run URL"),
        ((s["workflow"], s["exact_head"], s["status"],
          s["conclusion"] or "", s["run_url"] or "")
         for s in report["required_workflows"]))

    lines = [
        "# MAD4B Production Readiness — dynamic CI projection",
        "",
        "**Decision: NO-GO.** This artifact cannot authorize Production.",
        "",
        "| Metric | Current |", "| --- | --- |",
        "| Exact source HEAD | `{}` |".format(report["source_head_sha"]),
        "| Controls / domains | {} / {} |".format(
            report["totals"]["controls"], report["totals"]["domains"]),
        "| P0 / P1 | {} / {} |".format(report["totals"]["P0"], report["totals"]["P1"]),
        "| Production stages / feature workstreams | {} / {} |".format(
            report["totals"]["production_stages"], report["totals"]["feature_workstreams"]),
        "| Required CI on exact SHA | {} |".format(
            "PASS (not live certification)" if report["ci_exact_head_all_required_pass"] else
            "PENDING / FAILED / UNKNOWN"),
        "| Live site evidence | Not verified by CI |",
        "| Independent Production approval | Not granted |",
        "",
        "## Required exact-head CI workflows",
        "",
        "| Workflow | Result | Run |", "| --- | --- | --- |",
    ]
    for row in report["required_workflows"]:
        lines.append("| {} | {} | {} |".format(
            row["workflow"], row["conclusion"] or row["status"],
            row["run_url"] or "not available"))
    lines += ["", "## Protected stages", "",
              "All {} stages require independently verified, signed, site-bound live receipts.".format(
                  len(report["production_stages"])),
              "",
              "## Cross-plugin packages requiring certification", ""]
    lines += ["- " + item for item in report["unpromoted_cross_plugin_sources"]]
    lines += ["", "Repository checks are non-authorizing and NEVER trigger Production deploy.",
              ""]
    (output / "readiness.md").write_text("\n".join(lines), encoding="utf-8")
    return output / "readiness.md"


def self_test():
    report = snapshot(
        {"controls": [{"domain": "QA", "priority": "P0"}]},
        {"required_repository_workflows": ["Required 1"]},
        {"stages": [{"id": "stage1"}]},
        {"workstreams": [{"id": "stream1"}]},
        {"profiles": {CORE: {"workstream_ids": ["core1"], "open_or_partial": []}}},
        {"sources": [{"id": "uncertified", "package_certification": "pending",
                      "artifact_sha256": ""}]},
        "a" * 40, "owner/repo",
        {"Required 1": {"status": "completed", "conclusion": "success"}},
        "unit_test")
    assert report["ci_exact_head_all_required_pass"]
    assert report["decision"] == "NO-GO" and not report["production_authorized"]
    assert report["unpromoted_cross_plugin_sources"] == ["uncertified"]
    report2 = snapshot(
        {"controls": [{"domain": "QA", "priority": "P0"}]},
        {"required_repository_workflows": ["Required 1"]}, {"stages": []},
        {"workstreams": []}, {"profiles": {CORE: {"workstream_ids": [], "open_or_partial": []}}},
        {"sources": []}, "b" * 40, "owner/repo", {}, "unit_test")
    assert not report2["ci_exact_head_all_required_pass"]
    print("MATRIX_FAIL_CLOSED_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["validate", "render", "self-test"])
    parser.add_argument("--root", default=str(ROOT))
    parser.add_argument("--source", default=str(SOURCE))
    parser.add_argument("--out", default="artifacts/production-readiness")
    parser.add_argument("--head-sha", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    args = parser.parse_args()
    if args.command == "self-test":
        self_test()
        return 0
    try:
        matrix, policy, stages, feature, closure, source_cfg = validate(
            args.root, args.source)
    except (KeyError, ValueError, OSError, TypeError) as exc:
        print("MATRIX_CONTRACT_FAIL:", exc, file=sys.stderr)
        return 1
    print("MATRIX_SOURCE_VALID: {} controls / {} domains".format(
        len(matrix["controls"]), len({c["domain"] for c in matrix["controls"]})))
    if args.command == "render":
        ensure(bool(SHA.fullmatch(args.head_sha)), "Exact 40-character head SHA required")
        runs, provenance = github_workflow_results(args.repo, args.head_sha)
        report = snapshot(matrix, policy, stages, feature, closure,
                          source_cfg, args.head_sha, args.repo, runs, provenance)
        path = render(matrix, report, args.out)
        print("READONLY_NO_GO_REPORT:", path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
