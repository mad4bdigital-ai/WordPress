#!/usr/bin/env python3
"""Fail-closed live Github open-issue-to-regression coverage (read-only).

Never promotes GitHub source checks to runtime or Production authority. All
actual open Issues (excluding pull requests) must be in the reviewed source
coverage registry or the append-only governance-ledger exception set.
"""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import sys
import urllib.error
import urllib.request

HERE = Path(__file__).resolve().parent
CONTRACT = "mad4b.open-issue-live-coverage.v1"
MAX_PAGES = 5
MAX_ISSUES = 500
REPO_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


def read_manifest(path):
    raw = json.loads(Path(path).read_text(encoding="utf-8"))
    if raw.get("contract") != "mad4b.open-issue-regressions.v1" or raw.get("authorizing") is not False:
        raise ValueError("unsupported or authorizing issue registry")
    rows = raw.get("issues")
    ledgers = raw.get("ledger_issues")
    if not isinstance(rows, list) or not isinstance(ledgers, list):
        raise ValueError("invalid issue review inventory")
    numbers = [row.get("issue") for row in rows]
    if any(type(n) is not int or n <= 0 for n in numbers + ledgers):
        raise ValueError("invalid issue number")
    if len(set(numbers + ledgers)) != len(numbers + ledgers):
        raise ValueError("duplicate or contradictory issue disposition")
    for row in rows:
        if (not isinstance(row.get("tests"), list) or not row["tests"]
            or not isinstance(row.get("independent_acceptance_required"), list)
            or not row["independent_acceptance_required"]):
            raise ValueError("issue missing source or independent acceptance requirements")
        for name in row["tests"]:
            if (not isinstance(name, str) or Path(name).name != name
                or Path(name).suffix not in (".php", ".py")
                or not (HERE / name).is_file()):
                raise ValueError("missing/unsafe issue regression: " + str(name))
    return raw


def fetch_open_issues(repo, token, api_base="https://api.github.com"):
    if not REPO_RE.fullmatch(repo):
        raise ValueError("invalid repository identifier")
    if not api_base.startswith("https://"):
        raise ValueError("HTTPS API required")
    result = []
    for page in range(1, MAX_PAGES + 1):
        url = f"{api_base}/repos/{repo}/issues?state=open&per_page=100&page={page}"
        headers = {"Accept": "application/vnd.github+json",
                   "User-Agent": "MAD4B-readonly-issue-audit/1",
                   "X-GitHub-Api-Version": "2022-11-28"}
        if token:
            headers["Authorization"] = "Bearer " + token
        request = urllib.request.Request(url, headers=headers, method="GET")
        # A failed REST read must fail the workflow; no fallback to stale snapshots.
        with urllib.request.urlopen(request, timeout=15) as response:
            payload = response.read(1024 * 1024 + 1)
            if len(payload) > 1024 * 1024:
                raise ValueError("GitHub issue page exceeds bounded response")
            items = json.loads(payload.decode("utf-8"))
        if not isinstance(items, list):
            raise ValueError("invalid GitHub issue response")
        result.extend(items)
        if len(result) > MAX_ISSUES:
            raise ValueError("open-issue inventory exceeds review budget")
        if len(items) < 100:
            return result
    raise ValueError("open-issue pagination budget exceeded or truncated")


def classify(open_items, manifest, regression_report=None):
    found = {}
    for item in open_items:
        if not isinstance(item, dict):
            raise ValueError("invalid GitHub issue record")
        if "pull_request" in item:
            continue
        number = item.get("number")
        if type(number) is not int or number <= 0 or number in found:
            raise ValueError("duplicate/invalid live Issue")
        found[number] = {"title": str(item.get("title", ""))[:250],
                         "url": str(item.get("html_url", ""))[:1024]}
    index = {row["issue"]: row for row in manifest["issues"]}
    ledgers = set(manifest["ledger_issues"])
    report_index = {}
    if regression_report is not None:
        if (regression_report.get("contract") != manifest["contract"]
            or regression_report.get("authorizing") is not False
            or regression_report.get("live_acceptance") is not False):
            raise ValueError("invalid/non-fail-closed fixture report")
        report_index = {row["issue"]: row for row in regression_report["issues"]}
    rows, unreviewed = [], []
    for number in sorted(found):
        if number in ledgers:
            status = "PERMANENT_GOVERNANCE_LEDGER_OPEN"
            source = "NOT_APPLICABLE"
            gates = ["Owner-authored append-only governance attestation"]
        elif number in index:
            record = index[number]
            source = report_index.get(number, {}).get("source_regressions", "NOT_EXECUTED")
            if source not in ("PASS", "FAIL", "NOT_EXECUTED"):
                raise ValueError("invalid source regression status")
            status = "OPEN_INDEPENDENT_ACCEPTANCE_REQUIRED"
            gates = record["independent_acceptance_required"]
        else:
            source, status, gates = "UNREVIEWED", "UNMAPPED_OPEN_ISSUE", ["Exact source review and regression/independent acceptance mapping"]
            unreviewed.append(number)
        rows.append(dict(issue=number, **found[number], disposition=status,
                         source_regressions=source, independent_acceptance_required=gates,
                         live_acceptance=False, production_authorized=False))
    incomplete = sorted(number for number in index if number not in found)
    failed_source = [r["issue"] for r in rows if r["source_regressions"] == "FAIL"]
    report = {
        "contract": CONTRACT, "reviewed_registry_contract": manifest["contract"],
        "open_issue_count": len(rows),
        "mapped_open_defects": sum(r["disposition"] == "OPEN_INDEPENDENT_ACCEPTANCE_REQUIRED" for r in rows),
        "governance_ledgers": sorted(set(found) & ledgers),
        "unmapped_open_issues": unreviewed,
        "source_regression_failures": failed_source,
        "stale_registry_entries": incomplete,
        "items": rows, "coverage_pass": not (unreviewed or failed_source),
        "live_acceptance": False, "site_mutation_performed": False,
        "production_authorized": False, "authorizing": False,
    }
    return report


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", type=Path, default=HERE / "open-issue-regressions.json")
    ap.add_argument("--snapshot", type=Path, help="Hermetic fixture only; CI must use --live")
    ap.add_argument("--live", action="store_true")
    ap.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", ""))
    ap.add_argument("--regressions", type=Path)
    ap.add_argument("--output", type=Path)
    args = ap.parse_args()
    if args.live == bool(args.snapshot):
        ap.error("choose exactly one --live or --snapshot")
    try:
        manifest = read_manifest(args.manifest)
        if args.live:
            rows = fetch_open_issues(args.repo, os.environ.get("GITHUB_TOKEN", ""),
                                     os.environ.get("GITHUB_API_URL", "https://api.github.com"))
        else:
            rows = json.loads(args.snapshot.read_text(encoding="utf-8"))
        if not isinstance(rows, list):
            raise ValueError("issue inventory must be a list")
        fixture = json.loads(args.regressions.read_text(encoding="utf-8")) if args.regressions else None
        result = classify(rows, manifest, fixture)
        result["repository"] = args.repo if args.live else "hermetic"
        result["observed_at"] = datetime.now(timezone.utc).isoformat()
        if args.output:
            args.output.parent.mkdir(parents=True, exist_ok=True)
            args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(json.dumps({"coverage_pass": result["coverage_pass"],
                          "open": result["open_issue_count"],
                          "mapped": result["mapped_open_defects"],
                          "unmapped": result["unmapped_open_issues"],
                          "source_failures": result["source_regression_failures"],
                          "live_acceptance": False}, sort_keys=True))
        return 0 if result["coverage_pass"] else 1
    except (ValueError, OSError, urllib.error.URLError, json.JSONDecodeError) as err:
        # Never expose token, private raw response or authentication headers.
        print("OPEN_ISSUE_COVERAGE_UNVERIFIED: " + type(err).__name__, file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
