#!/usr/bin/env python3
"""Read-only MAD4B readiness quality, release-blocker and KPI projection.

Source-only P0 coverage is measurable in this job; the remaining KPI inputs
require separately observed/verified data. Never interpret no evidence as 0%.
Never authorize Production or synthesize trusted Staging evidence.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "governance/production-readiness/quality-policy.json"
GOALS = (
    "p0_change_coverage", "false_positive_rate", "pr_ci_evaluation_duration",
    "live_evidence_integrity", "unauthorized_production_promotions",
)
CI_SCOPE = "wp-content/plugins/mad4b-site-control-plane/"
FORMAT = "mad4b.readiness-quality-report.v1"


def ensure(value, reason):
    if not value:
        raise ValueError(reason)


def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def verify_policy(policy):
    ensure(policy.get("contract") == "mad4b.readiness-quality-policy.v1",
           "Invalid quality policy")
    ensure(policy.get("authorizing") is False
           and policy.get("auto_deploy") is False
           and policy.get("production_promotion") is False,
           "Quality controls cannot authorize or deploy")
    goals = policy.get("goals", [])
    ensure({x.get("id") for x in goals} == set(GOALS)
           and len(goals) == len(GOALS), "All five KPI definitions are required")
    targets = {
        "p0_change_coverage": (100, "min"),
        "false_positive_rate": (5, "max_exclusive"),
        "pr_ci_evaluation_duration": (10, "max"),
        "live_evidence_integrity": (100, "min"),
        "unauthorized_production_promotions": (0, "max"),
    }
    for item in goals:
        ensure((item.get("target"), item.get("direction")) == targets[item["id"]]
               and isinstance(item.get("minimum_sample"), int)
               and item["minimum_sample"] >= 1,
               "KPI goal cannot be weakened")
    trust = policy.get("data_trust", {})
    ensure(all(trust.get(k) is True for k in (
        "manual_json_is_advisory_only", "no_ci_evidence_self_signature",
        "external_receipt_verification_required", "production_audit_required")),
        "Trust boundary is disabled")


def unknown(item, detail):
    return {
        "id": item["id"], "unit": item["unit"], "target": item["target"],
        "direction": item["direction"], "status": "INSUFFICIENT_EVIDENCE",
        "measured_value": None, "sample_size": 0,
        "provenance": "UNAVAILABLE", "reason": detail,
    }


def evaluate(impact, readiness, policy):
    verify_policy(policy)
    ensure(impact.get("contract") == "mad4b.dynamic-readiness-capability-impact.v1",
           "Unexpected capability impact contract")
    ensure(readiness.get("contract") == "mad4b.production-readiness-evaluation.v1",
           "Unexpected repository readiness contract")
    head = impact.get("exact_head_sha")
    ensure(head and head == readiness.get("source_head_sha") and len(head) == 40,
           "Exact-head cross-report identity mismatch")
    ensure(impact.get("authorizing") is False and
           impact.get("production_authorized") is False and
           readiness.get("production_authorized") is False,
           "Readiness report attempted self-authorization")
    by_id = {x["id"]: x for x in policy["goals"]}
    metrics = [unknown(x, "Independent data source not connected to this read-only CI job")
               for x in policy["goals"]]
    result = {x["id"]: x for x in metrics}

    impacted = {
        row["path"] for row in impact.get("impact", [])
        if row.get("risk") == "P0"
        and row.get("change") in ("A", "M", "T")
        and row.get("path", "").startswith(CI_SCOPE)
        and not "/tests/" in row.get("path", "")
        and row.get("path", "").endswith((".php", ".js", ".css", ".json"))
    }
    approved_blobs = {x["path"] for x in impact.get("reviewed_behavior_changes", [])}
    measured = len(impacted & approved_blobs)
    if impacted:
        percentage = round(100 * measured / len(impacted), 2)
        target = by_id["p0_change_coverage"]
        result["p0_change_coverage"] = {
            "id": target["id"], "unit": target["unit"],
            "target": target["target"], "direction": target["direction"],
            "status": ("OBSERVED_NOT_PRODUCTION_CERTIFIED"
                       if percentage >= 100 else "BELOW_TARGET"),
            "measured_value": percentage, "sample_size": len(impacted),
            "provenance": "EXACT_HEAD_SOURCE_DIFF_ONLY",
            "reason": ("Source review presence only; test quality and live site "
                       "behavior require separate evidence"),
        }

    gaps = impact.get("coverage_gaps", [])
    required = readiness.get("required_workflows", [])
    complete = bool(required) and all(
        x.get("status") == "completed" and x.get("conclusion") == "success"
        for x in required
    )
    blockers = []
    if gaps:
        blockers.append("UNCOVERED_REPOSITORY_CHANGES")
    if not complete:
        blockers.append("INCOMPLETE_REQUIRED_EXACT_HEAD_CI")
    blockers.extend(("UNVERIFIED_SIGNED_STAGING_RECEIPTS",
                     "INDEPENDENT_PRODUCTION_PROMOTION_NOT_GRANTED"))
    return {
        "contract": FORMAT, "exact_head_sha": head,
        "authorizing": False, "production_authorized": False,
        "production_ready": False, "production_decision": "NO-GO",
        "repository_ci_all_required_complete": complete,
        "metrics": [result[key] for key in GOALS],
        "blocking_reasons": blockers,
        "data_trust": "REPOSITORY_ONLY_WITHOUT_SIGNED_SITE_OR_PRODUCTION_AUDIT",
        "metrics_are_not_release_authority": True,
    }


def write_report(report, output):
    location = Path(output)
    location.mkdir(parents=True, exist_ok=True)
    (location / "quality-scorecard.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8")
    lines = ["# MAD4B readiness quality scorecard", "",
             "Release decision: **NO-GO**. No independent Production authority.", "",
             "| Indicator | Measured | Target | Evidence status |",
             "| --- | ---: | ---: | --- |"]
    for metric in report["metrics"]:
        actual = ("Unknown" if metric["measured_value"] is None else
                  str(metric["measured_value"]) + (
                      "%" if metric["unit"] == "percent" else
                      " " + metric["unit"]))
        lines.append("| {} | {} | {} | {} |".format(
            metric["id"], actual, metric["target"], metric["status"]))
    lines.extend(["", "## Blocking conditions", ""])
    for reason in report["blocking_reasons"]:
        lines.append("- " + reason)
    lines.extend(["", "Unknown metrics do not count as passing. "
                  "Source coverage alone never proves runtime acceptance.", ""])
    (location / "quality-scorecard.md").write_text("\n".join(lines), encoding="utf-8")
    return location / "quality-scorecard.md"


def self_test():
    policy = load(POLICY)
    verify_policy(policy)
    sha = "a" * 40
    impact = {
        "contract": "mad4b.dynamic-readiness-capability-impact.v1",
        "exact_head_sha": sha, "authorizing": False,
        "production_authorized": False,
        "impact": [{"path": CI_SCOPE + "includes/example.php",
                    "risk": "P0", "change": "M"}],
        "reviewed_behavior_changes": [], "coverage_gaps": []
    }
    readiness = {
        "contract": "mad4b.production-readiness-evaluation.v1",
        "source_head_sha": sha, "production_authorized": False,
        "required_workflows": [{"status": "completed", "conclusion": "success"}]
    }
    out = evaluate(impact, readiness, policy)
    assert out["production_decision"] == "NO-GO"
    assert out["metrics"][0]["status"] == "BELOW_TARGET"
    assert out["metrics"][0]["measured_value"] == 0
    assert all(x["measured_value"] is None for x in out["metrics"][1:])
    impact["reviewed_behavior_changes"] = [{"path": CI_SCOPE + "includes/example.php"}]
    out = evaluate(impact, readiness, policy)
    assert out["metrics"][0]["measured_value"] == 100
    assert out["production_authorized"] is False
    readiness["source_head_sha"] = "b" * 40
    try:
        evaluate(impact, readiness, policy)
    except ValueError as exc:
        assert "identity mismatch" in str(exc)
    else:
        raise AssertionError("Mixed exact HEAD cannot be accepted")
    invalid = json.loads(json.dumps(policy))
    invalid["goals"] = invalid["goals"][:-1]
    try:
        verify_policy(invalid)
    except ValueError:
        pass
    else:
        raise AssertionError("Missing KPI must be rejected")
    print("READINESS_QUALITY_FAIL_CLOSED_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["evaluate", "self-test"])
    parser.add_argument("--impact", default="artifacts/production-readiness/capability-impact.json")
    parser.add_argument("--readiness", default="artifacts/production-readiness/readiness.json")
    parser.add_argument("--out", default="artifacts/production-readiness")
    args = parser.parse_args()
    if args.command == "self-test":
        self_test()
        return 0
    try:
        score = evaluate(load(args.impact), load(args.readiness), load(POLICY))
        output = write_report(score, args.out)
    except (ValueError, OSError, KeyError, TypeError) as exc:
        print("READINESS_QUALITY_FAIL_CLOSED:", exc, file=sys.stderr)
        return 2
    print("READINESS_QUALITY_REPORT:", output)
    print("READINESS_QUALITY_RELEASE_DECISION:", score["production_decision"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
