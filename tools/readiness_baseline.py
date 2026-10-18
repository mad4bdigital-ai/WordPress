#!/usr/bin/env python3
"""Observed MAD4B capability inventory; not a retrospective approval.

Discovers existing known catalog and static WordPress interfaces at an exact
Git head to supply review worklists for progressively closing baseline gaps.
"""
from __future__ import annotations
import argparse
import csv
from readiness_csv import safe_writer
import json
from pathlib import Path
import sys

import production_readiness_registry as reg

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = "mad4b.baseline-capability-inventory.v1"


def inventory(head):
    reg.ensure(reg.SHA.fullmatch(head), "Exact SHA required")
    reg.ensure(reg.git("rev-parse", "HEAD").stdout.decode().strip() == head,
               "Current checkout must equal exact head")
    catalog = reg.catalog_snapshot(head)
    observed = dict(catalog)
    git_paths = reg.git("ls-tree", "-r", "--name-only", head, "--", reg.PLUGIN)
    source_paths = git_paths.stdout.decode().splitlines()
    for path in source_paths:
        if not path.endswith(".php") or "/tests/" in path:
            continue
        for name in reg.php_capabilities(head, path) | reg.registered_surfaces(head, path):
            observed.setdefault(name, path)
    # Root cause for historical under-coverage is visible rather than
    # silently certifying preexisting providers as already reviewed.
    coverage = json.loads((ROOT / reg.COVERAGE).read_text(encoding="utf-8"))
    approved_source_reviews = {x["id"] for x in coverage["reviewed_capabilities"]}
    records = []
    for identifier, path in sorted(observed.items()):
        rule = reg.classify(path, identifier, coverage)
        records.append({
            "id": identifier, "source": path, "risk": rule["risk"],
            "suggested_controls": rule["controls"], "exact_head_sha": head,
            "historical_review_status": (
                "REVIEWED_SOURCE_COVERAGE_ONLY"
                if identifier in approved_source_reviews else "OBSERVED_UNREVIEWED"
            ),
            "live_site_verified": False,
            "production_authorized": False,
        })
    return {
        "contract": CONTRACT, "exact_head_sha": head,
        "authorizing": False, "production_authorized": False,
        "source_only": True,
        "total_observed": len(records),
        "reviewed_source_entries": len(records) -
        sum(x["historical_review_status"] == "OBSERVED_UNREVIEWED" for x in records),
        "unreviewed_entries": sum(x["historical_review_status"] ==
                                  "OBSERVED_UNREVIEWED" for x in records),
        "caveat": "Static candidates cannot prove all dynamically registered WordPress abilities.",
        "records": records,
    }


def render(report, out):
    folder = Path(out)
    folder.mkdir(parents=True, exist_ok=True)
    (folder / "baseline-inventory.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with (folder / "baseline-inventory.csv").open(
            "w", encoding="utf-8-sig", newline="") as fh:
        writer = safe_writer(fh)
        writer.writerow(("Capability ID", "Source", "Risk", "Suggested controls",
                         "Historical review", "Live verified", "Production authorized"))
        for r in report["records"]:
            writer.writerow((r["id"], r["source"], r["risk"],
                             ", ".join(r["suggested_controls"]),
                             r["historical_review_status"], "NO", "NO"))
    return folder / "baseline-inventory.json"


def self_test():
    sample = {
        "contract": CONTRACT, "exact_head_sha": "a" * 40,
        "authorizing": False, "production_authorized": False,
        "records": [{"id": "operation:example", "historical_review_status":
                     "OBSERVED_UNREVIEWED", "live_site_verified": False}]
    }
    assert sample["production_authorized"] is False
    assert all(r["live_site_verified"] is False for r in sample["records"])
    assert reg.classify("includes/security.php", "ability:mad4b/example", {
        "domain_rules": [
            {"id": "authority", "pattern": "security", "risk": "P0",
             "controls": ["SECURITY-01"]},
            {"id": "platform", "pattern": ".*", "risk": "P0",
             "controls": ["PLATFORM-01"]}]})["id"] == "authority"
    print("BASELINE_INVENTORY_FAIL_CLOSED_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["self-test", "render"])
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--out", default="artifacts/production-readiness")
    args = parser.parse_args()
    if args.command == "self-test":
        self_test()
        return 0
    try:
        print("BASELINE_INVENTORY_ARTIFACT:",
              render(inventory(args.head_sha), args.out))
    except (OSError, ValueError, KeyError, TypeError) as exc:
        print("BASELINE_INVENTORY_FAIL_CLOSED:", str(exc), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
