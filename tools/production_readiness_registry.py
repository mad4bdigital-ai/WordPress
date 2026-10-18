#!/usr/bin/env python3
"""Detect new MAD4B capability surface and enforce reviewed, non-authorizing CI coverage.

No WordPress/Production connection, write, approval, shell or privileged action.
Compares immutable Git base against exact HEAD, not an untrusted cached snapshot.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
GOV = "governance/production-readiness"
PLUGIN = "wp-content/plugins/mad4b-site-control-plane/"
CONFIG = PLUGIN + "config/"
COVERAGE = GOV + "/capability-coverage.json"
CONTROLS = GOV + "/controls.json"
SHA = re.compile(r"^[a-f0-9]{40}$")
CAP = re.compile(r"['\"](mad4b/[a-z0-9][a-z0-9._/-]{1,120})['\"]")
PHP_DECLARATION = re.compile(
    r"wp_register_ability\s*\(|(?:self|static)::register\s*\(|"
    r"\$this->add\s*\(|register_abilities\s*\(", re.I
)
CAP_CATALOGS = {
    "operation": CONFIG + "operation-registry.json",
    "adapter": CONFIG + "adapter-support-catalog.json",
    "overlay": CONFIG + "wordpress-domain-catalog.json",
    "gap": CONFIG + "functional-gap-policy.json",
    "workstream": CONFIG + "feature-007-workstream-certification.json",
}
PRIORITIES = {"P0", "P1"}


def git(*args, checked=True):
    p = subprocess.run(["git", *args], cwd=ROOT, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, check=False)
    if checked and p.returncode:
        raise ValueError("Git evidence unavailable: " + " ".join(args[:3]) +
                         ": " + p.stderr.decode(errors="replace")[:400])
    return p


def git_text(commit, path):
    assert SHA.fullmatch(commit)
    if not path.startswith((PLUGIN, GOV + "/")):
        raise ValueError("Out-of-scope Git source")
    p = git("show", commit + ":" + path, checked=False)
    if p.returncode:
        # For an added file, absence at baseline is normal.
        if b"exists on disk" in p.stderr or b"does not exist" in p.stderr or b"fatal: path" in p.stderr:
            return None
        raise ValueError("Unresolved git evidence for " + path)
    return p.stdout.decode("utf-8")


def json_file(commit, path):
    raw = git_text(commit, path)
    return json.loads(raw) if raw is not None else None


def ensure(ok, msg):
    if not ok:
        raise ValueError(msg)


def identifier(kind, value):
    return kind + ":" + str(value)


def catalog_snapshot(ref):
    result = {}
    for kind, path in CAP_CATALOGS.items():
        j = json_file(ref, path)
        if j is None:
            continue
        if kind == "operation":
            values = [x["id"] for x in j.get("operations", [])]
        elif kind == "adapter":
            values = [x["id"] for x in j.get("families", [])]
            values.extend(x["id"] for x in j.get("priority_external", []))
        elif kind == "overlay":
            values = [x["provider_id"] for x in j.get("overlays", [])]
        elif kind == "gap":
            values = list(j.get("families", {}))
        else:
            values = [x["id"] for x in j.get("workstreams", [])]
        ensure(len(values) == len(set(values)), "Duplicate capability in " + path)
        for val in values:
            k = identifier(kind, val)
            ensure(k not in result, "Duplicated capability " + k)
            result[k] = path
    return result


def changed_paths(base, head):
    out = git("diff", "--name-status", "--no-renames", base, head, "--").stdout.decode()
    rows = []
    for line in out.splitlines():
        cells = line.split("\t")
        ensure(len(cells) == 2 and cells[0] in {"A", "M", "D", "T"},
               "Ambiguous rename or modification evidence " + line)
        rows.append((cells[0], cells[1]))
    return rows


def php_capabilities(ref, path):
    src = git_text(ref, path)
    if not src:
        return set()
    if not PHP_DECLARATION.search(src):
        return set()
    # Conservative static surface. This is a candidate extractor, not an
    # authoritative WordPress Ability census or proof of runtime registration.
    return {identifier("ability", m) for m in CAP.findall(src)}


def new_source_ids(base, head, changed):
    old = catalog_snapshot(base)
    new = catalog_snapshot(head)
    added = {k: new[k] for k in new.keys() - old.keys()}
    removed = {k: old[k] for k in old.keys() - new.keys()}
    for status, path in changed:
        if not path.startswith(PLUGIN) or not path.endswith(".php") or "/tests/" in path:
            continue
        before = php_capabilities(base, path)
        after = php_capabilities(head, path)
        for cap in after - before:
            added.setdefault(cap, path)
        for cap in before - after:
            removed.setdefault(cap, path)
        # Independently detectable new service, even without a literal
        # Ability identifier. No implicit capability on renames: renames are
        # represented as D+A and therefore require a reviewed migration.
        if status == "A" and ("/includes/" in path or path.count("/") == PLUGIN.count("/")):
            added["service:" + path] = path
        if status == "D" and ("/includes/" in path or path.count("/") == PLUGIN.count("/")):
            removed["service:" + path] = path
        # Asset changes need their own UX regression, not authorization.
    for status, path in changed:
        if path.startswith(PLUGIN + "assets/") and path.endswith((".js", ".css")):
            if status == "A":
                added["admin_asset:" + path] = path
            if status == "D":
                removed["admin_asset:" + path] = path
    return old, new, added, removed


def classify(path, cap, coverage):
    text = (path + " " + cap).lower()
    rules = coverage["domain_rules"]
    for rule in rules:
        if rule["id"] != "platform" and re.search(rule["pattern"], text):
            return rule
    return next(r for r in rules if r["id"] == "platform")


def validate_coverage(coverage, controls, base, head, changed, added, removed):
    ensure(coverage.get("contract") == "mad4b.readiness-capability-coverage.v1",
           "Invalid coverage contract")
    ensure(coverage.get("authorizing") is False and
           coverage.get("production_promotion_from_ci") is False,
           "Coverage CI cannot authorize Production")
    guards = coverage["guarantees"]
    ensure(all(guards.values()), "Coverage guard disabled")
    ensure(len(coverage.get("domain_rules", [])) >= 8, "Impact mapping incomplete")
    ctl_ids = {c["id"] for c in controls["controls"]}
    old_controls = json_file(base, CONTROLS)
    ensure(old_controls is not None, "Missing reviewed base controls")
    disappeared = {x["id"] for x in old_controls["controls"]} - ctl_ids
    ensure(not disappeared, "A historical control was deleted: " + repr(sorted(disappeared)))
    ensure(len(ctl_ids) >= 170, "Baseline controls removed")
    for entry in controls["controls"]:
        if "lifecycle" not in entry:
            continue
        ensure(entry["lifecycle"] in {"active", "deprecated", "superseded"},
               "Bad control lifecycle " + entry["id"])
        if entry["lifecycle"] == "superseded":
            alt = entry.get("superseded_by")
            ensure(alt in ctl_ids and alt != entry["id"],
                   "Superseded control must name valid replacement: " + entry["id"])
    rules = coverage["domain_rules"]
    ensure(len({x["id"] for x in rules}) == len(rules), "Impact rule collision")
    for rule in rules:
        ensure(rule.get("risk") in PRIORITIES, "Unknown mapped priority")
        ensure(rule.get("controls") and set(rule["controls"]) <= ctl_ids,
               "Impact rule references absent controls")
        re.compile(rule["pattern"])
    reviews = coverage.get("reviewed_capabilities", [])
    ids = [x.get("id") for x in reviews]
    ensure(len(ids) == len(set(ids)), "Duplicate capability coverage IDs")
    changed_set = {p for _, p in changed}
    failures = []
    accepted = []
    for capability, path in sorted(added.items()):
        rule = classify(path, capability, coverage)
        rec = next((x for x in reviews if x.get("id") == capability), None)
        missing = []
        if rec is None:
            missing = ["reviewed_coverage_missing"]
        else:
            ensure(rec.get("status") == "reviewed_for_ci_only" and
                   rec.get("production_authorized") is False and
                   rec.get("live_evidence_required") is True,
                   "Untrusted capability review status: " + capability)
            ensure(rec.get("owner_role") and len(rec.get("review_rationale", "")) >= 24,
                   "Incomplete reviewer rationale: " + capability)
            ctl = rec.get("control_ids", [])
            tests = rec.get("regression_tests", [])
            ensure(isinstance(ctl, list) and len(ctl) >= 2 and
                   len(set(ctl)) == len(ctl) and set(ctl) <= ctl_ids,
                   "Insufficient / unknown control coverage: " + capability)
            ensure(set(ctl) & set(rule["controls"]),
                   "Coverage does not address mapped impact: " + capability)
            ensure(isinstance(tests, list) and tests,
                   "A newly introduced capability must provide regression tests")
            for test in tests:
                ensure(isinstance(test, str) and (test.startswith(PLUGIN + "tests/") or
                       test.startswith("tests/")) and test in changed_set and
                       (ROOT / test).is_file(),
                       "Test evidence must be committed with capability: " + capability)
            accepted.append(capability)
        if missing:
            failures.append({"id": capability, "source": path,
                             "risk": rule["risk"], "controls": rule["controls"],
                             "reasons": missing})
    lifecycle = coverage.get("lifecycle", [])
    lifecycle_ids = [x.get("id") for x in lifecycle]
    ensure(len(lifecycle_ids) == len(set(lifecycle_ids)), "Duplicate lifecycle entries")
    for removed_cap, path in sorted(removed.items()):
        event = next((x for x in lifecycle if x.get("id") == removed_cap), None)
        if event is None:
            failures.append({"id": removed_cap, "source": path, "risk": "P0",
                             "reasons": ["decommission_or_supersession_review_missing"]})
            continue
        ensure(event.get("state") in {"deprecated", "superseded"} and
               len(event.get("rationale", "")) >= 24 and
               event.get("production_authorized") is False,
               "Retirement must be reviewed and nonauthorizing: " + removed_cap)
        if event["state"] == "superseded":
            ensure(event.get("replacement") and event["replacement"] != removed_cap,
                   "Superseded capability missing replacement " + removed_cap)
    for x in reviews:
        ensure(x.get("id") in added, "Stale or invented current-PR coverage: " + str(x.get("id")))
    return failures, accepted


def impact_rows(changed, coverage, added, removed):
    rows = []
    for status, path in changed:
        if not path.startswith(PLUGIN) and not path.startswith((
                GOV + "/", ".github/workflows/", "tools/")):
            continue
        rule = classify(path, path, coverage)
        rows.append({
            "path": path,
            "change": status,
            "area": rule["id"],
            "risk": rule["risk"],
            "suggested_controls": rule["controls"],
            "introduced": sorted(k for k, v in added.items() if v == path),
            "removed": sorted(k for k, v in removed.items() if v == path),
        })
    return rows


def evaluate(base, head):
    ensure(SHA.fullmatch(base or "") and SHA.fullmatch(head or ""),
           "Exact base and head commit SHAs are required")
    ensure(git("rev-parse", "HEAD").stdout.decode().strip() == head,
           "Working tree must be exact requested HEAD")
    ensure(git("merge-base", "--is-ancestor", base, head, checked=False).returncode == 0,
           "Reviewed PR base is not ancestor of exact HEAD")
    changed = changed_paths(base, head)
    controls = json.loads((ROOT / CONTROLS).read_text(encoding="utf-8"))
    coverage = json.loads((ROOT / COVERAGE).read_text(encoding="utf-8"))
    old, current, added, removed = new_source_ids(base, head, changed)
    failures, reviewed = validate_coverage(
        coverage, controls, base, head, changed, added, removed)
    impact = impact_rows(changed, coverage, added, removed)
    return {
        "contract": "mad4b.dynamic-readiness-capability-impact.v1",
        "source_base_sha": base, "exact_head_sha": head,
        "authorizing": False, "production_authorized": False,
        "production_ready": False, "mutation_performed": False,
        "static_discovery_is_not_live_proof": True,
        "decision": "PASS_COVERAGE_ONLY" if not failures else "FAIL_UNCOVERED_CHANGES",
        "catalog_baseline_count": len(old),
        "catalog_current_count": len(current),
        "introduced": [{"id": k, "source": v} for k, v in sorted(added.items())],
        "retired": [{"id": k, "source": v} for k, v in sorted(removed.items())],
        "reviewed_for_ci_only": reviewed,
        "coverage_gaps": failures,
        "impact": impact,
        "counts": {"changed_paths": len(changed), "catalog_growth": len(current) - len(old),
                   "new_capabilities": len(added), "removed_capabilities": len(removed),
                   "coverage_gaps": len(failures),
                   "impacted_files": len(impact)},
    }


def emit(report, out):
    target = Path(out)
    target.mkdir(parents=True, exist_ok=True)
    (target / "capability-impact.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with (target / "capability-impact.csv").open("w", newline="", encoding="utf-8-sig") as fh:
        w = csv.writer(fh)
        w.writerow(("Path", "Change", "Area", "Risk", "Suggested control IDs",
                    "New capability IDs", "Removed capability IDs"))
        for x in report["impact"]:
            w.writerow((x["path"], x["change"], x["area"], x["risk"],
                        ", ".join(x["suggested_controls"]),
                        ", ".join(x["introduced"]), ", ".join(x["removed"])))
    # Exact-head append-only logical event: immutable GitHub Actions artifact
    # tied to head+run ID. This never changes repository source or approvals.
    event = {key: report[key] for key in (
        "source_base_sha", "exact_head_sha", "decision", "counts",
        "introduced", "retired", "coverage_gaps", "authorizing",
        "production_authorized")}
    event.update({"contract": "mad4b.readiness-history-event.v1",
                  "github_run_id": os.environ.get("GITHUB_RUN_ID"),
                  "retention_note": "Run-scoped artifact; durable source history remains in Git."})
    (target / "capability-history-event.json").write_text(
        json.dumps(event, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    lines = ["## Dynamic Readiness Coverage", "",
             "**{}** — review gate only; not Production authorization.".format(report["decision"]),
             "", "| Measure | Count |", "| --- | ---: |"]
    for k, v in report["counts"].items():
        lines.append("| {} | {} |".format(k, v))
    if report["coverage_gaps"]:
        lines += ["", "### Uncovered capability / lifecycle changes", ""]
        for g in report["coverage_gaps"]:
            lines.append("- {}: {} ({})".format(
                g["id"], ", ".join(g["reasons"]), g["source"]))
    else:
        lines += ["", "No newly introduced uncovered capabilities detected.",
                  "Existing capabilities are NOT automatically certified."]
    lines += ["", "Impact CSV and SHA-bound historical event are uploaded as artifacts.", ""]
    path = target / "capability-impact.md"
    path.write_text("\n".join(lines), encoding="utf-8")
    return path


def self_test():
    cfg = {"domain_rules": [
        {"id": "authority", "pattern": "oauth|security", "risk": "P0",
         "controls": ["SECURITY-01", "OAUTH-01"]},
        {"id": "platform", "pattern": ".*", "risk": "P0",
         "controls": ["PLATFORM-01"]}]}
    assert classify("includes/oauth.php", "ability:mad4b/auth", cfg)["id"] == "authority"
    assert classify("other.php", "service:x", cfg)["id"] == "platform"
    assert len(CAP.findall("self::register('mad4b/read-one', array())")) == 1
    assert PHP_DECLARATION.search("wp_register_ability ('mad4b/example')")
    assert not PHP_DECLARATION.search("echo 'mad4b/foo'")
    for source in ("<?php $this->add('mad4b/example', 'Title')",
                   "<?php self::register( 'mad4b/example', 'Title')"):
        assert PHP_DECLARATION.search(source)
    assert not SHA.fullmatch("incorrect")
    print("DYNAMIC_READINESS_COVERAGE_SELF_TEST: PASS")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["evaluate", "self-test"])
    parser.add_argument("--base-sha", default=os.environ.get("BASE_SHA", ""))
    parser.add_argument("--head-sha", default=os.environ.get("HEAD_SHA", ""))
    parser.add_argument("--out", default="artifacts/production-readiness")
    args = parser.parse_args()
    if args.command == "self-test":
        self_test()
        return 0
    try:
        result = evaluate(args.base_sha, args.head_sha)
        p = emit(result, args.out)
        print("DYNAMIC_READINESS_REPORT:", p)
        print("DYNAMIC_READINESS_RESULT:", result["decision"])
        return 0 if not result["coverage_gaps"] else 1
    except (ValueError, KeyError, TypeError, OSError) as exc:
        print("DYNAMIC_READINESS_FAIL_CLOSED:", str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
