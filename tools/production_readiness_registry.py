#!/usr/bin/env python3
"""Detect new MAD4B capability surface and enforce reviewed, non-authorizing CI coverage.

No WordPress/Production connection, write, approval, shell or privileged action.
Compares immutable Git base against exact HEAD, not an untrusted cached snapshot.
"""
from __future__ import annotations

import argparse
import csv
from readiness_csv import safe_writer
import hashlib
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
# Immutable minimum review perimeter independent of mutable source policy.
REQUIRED_SOURCE_ROOT = "wp-content/plugins/mad4b-site-control-plane/"
REQUIRED_EXTENSIONS = frozenset((".php", ".js", ".css", ".json"))
REQUIRED_SUBTREES = frozenset(("includes/", "assets/", "config/"))
ALLOWED_EXCLUSIONS = frozenset(("tests/", "docs/", "languages/"))
REST_ROUTE = re.compile(r"""register_rest_route\s*\(\s*['"]([a-z0-9._/-]+)['"]\s*,\s*['"]([^'"]+)['"]""", re.I)
AJAX_ROUTE = re.compile(r"""add_action\s*\(\s*['"](wp_ajax_(?:nopriv_)?[a-z0-9_-]+)['"]""", re.I)
CRON_SCHEDULE = re.compile(r"""wp_schedule_(?:single_)?event\s*\(\s*[^,]+,\s*(?:[^,]+,\s*)?['"]([a-z0-9_-]+)['"]""", re.I)


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
            priority = [x["id"] for x in j.get("priority_external", [])]
            ensure(len(priority) == len(set(priority)), "Duplicate priority external adapter")
            # A priority provider may intentionally be the same adapter family.
            # Count it once; this is not a source integrity violation.
            values.extend(x for x in priority if x not in values)
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


def registered_surfaces(ref, path):
    """Static candidate REST, AJAX and Cron interfaces; never runtime proof."""
    src = git_text(ref, path)
    if not src:
        return set()
    rows = {identifier("rest", ns + "/" + route.lstrip("/"))
            for ns, route in REST_ROUTE.findall(src)}
    rows.update(identifier("ajax", x) for x in AJAX_ROUTE.findall(src))
    rows.update(identifier("cron", x) for x in CRON_SCHEDULE.findall(src))
    return rows


def is_reviewed_runtime_path(path, coverage):
    cfg = coverage["code_change_rules"]
    root = REQUIRED_SOURCE_ROOT
    if not path.startswith(root):
        return False
    rel = path[len(root):]
    if any(rel.startswith(x) for x in cfg["excluded_subtrees"]):
        return False
    if not any(rel.endswith(x) for x in cfg["covered_extensions"]):
        return False
    return (any(rel.startswith(x) for x in cfg["included_subtrees"])
            or (cfg.get("root_php") and "/" not in rel and rel.endswith(".php")))


def current_blob_sha(commit, path):
    ensure(SHA.fullmatch(commit), "Invalid exact source commit")
    sha = git("rev-parse", commit + ":" + path).stdout.decode().strip()
    ensure(SHA.fullmatch(sha), "Invalid source blob for " + path)
    ensure(git("cat-file", "-t", sha).stdout.decode().strip() == "blob",
           "Reviewed object is not a Git blob: " + path)
    return sha


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
        old_routes, new_routes = registered_surfaces(base, path), registered_surfaces(head, path)
        for route in new_routes - old_routes:
            added.setdefault(route, path)
        for route in old_routes - new_routes:
            removed.setdefault(route, path)
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
    # Match the relative feature name, never the global wp-content/plugins prefix.
    relative = path[len(REQUIRED_SOURCE_ROOT):] if path.startswith(REQUIRED_SOURCE_ROOT) else path
    # Both a changed source path and a new service ID can be expressed as
    # repository-absolute paths. Strip the global wp-content/ prefix in BOTH
    # cases; otherwise every changed PHP file spuriously matches the "content"
    # domain and a correct risk review is rejected as unrelated.
    cap_name = cap[len("service:" + REQUIRED_SOURCE_ROOT):] if cap.startswith("service:" + REQUIRED_SOURCE_ROOT) else (
        cap[len(REQUIRED_SOURCE_ROOT):] if cap.startswith(REQUIRED_SOURCE_ROOT) else cap
    )
    text = (relative + " " + cap_name).lower()
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
    required_guards = (
        "new_capability_requires_reviewed_coverage",
        "new_capability_requires_new_test_evidence",
        "retired_capability_requires_traceable_supersession",
        "unknown_change_kind_fails_closed",
        "new_capabilities_do_not_self_authorize",
        "past_controls_remain_immutable_by_default",
        "existing_behavior_changes_require_reviewed_test_evidence",
        "new_capability_control_growth_decision_required",
        "registered_interface_additions_require_coverage",
        "control_metadata_history_is_sha_bound",
        "policy_scope_protected_against_reduction",
        "impact_rules_cannot_shadow_existing_rules",
        "regression_tests_must_execute_in_ci",
    )
    ensure(all(guards.get(k) is True for k in required_guards),
           "Required coverage guards missing or disabled")
    ensure(len(coverage.get("domain_rules", [])) >= 8, "Impact mapping incomplete")
    growth = coverage.get("capability_growth_policy", {})
    ensure(growth.get("contract") == "mad4b.readiness-control-growth.v1"
           and all(growth.get(k) is True for k in (
               "require_explicit_new_control_decision",
               "require_review_rationale_for_reuse",
               "require_new_control_ids_for_expansion",
               "preserve_existing_control_ids"))
           and growth.get("automated_approval") is False,
           "Explicit checklist growth policy missing")
    code_rules = coverage.get("code_change_rules", {})
    ensure(code_rules.get("contract") == "mad4b.readiness-reviewed-changes.v1"
           and code_rules.get("authorizing") is False
           and code_rules.get("production_authorized") is False
           and code_rules.get("require_current_git_blob") is True
           and code_rules.get("require_same_pr_regression_test") is True
           and code_rules.get("require_executed_regression_test") is True,
           "Exact source-change review gate disabled")
    ensure(code_rules.get("runtime_root") == REQUIRED_SOURCE_ROOT
           and code_rules.get("root_php") is True
           and set(code_rules.get("covered_extensions", [])) >= REQUIRED_EXTENSIONS
           and set(code_rules.get("included_subtrees", [])) >= REQUIRED_SUBTREES
           and set(code_rules.get("excluded_subtrees", [])) <= ALLOWED_EXCLUSIONS,
           "Protected runtime coverage scope changed or weakened")
    ctl_ids = {c["id"] for c in controls["controls"]}
    old_controls = json_file(base, CONTROLS)
    ensure(old_controls is not None, "Missing reviewed base controls")
    old_control_ids = {x["id"] for x in old_controls["controls"]}
    new_control_ids = ctl_ids - old_control_ids
    disappeared = old_control_ids - ctl_ids
    ensure(not disappeared, "A historical control was deleted: " + repr(sorted(disappeared)))
    ensure(len(ctl_ids) >= 170, "Baseline controls removed")
    prior_control_rows = {x["id"]: x for x in old_controls["controls"]}
    for entry in controls["controls"]:
        old_row = prior_control_rows.get(entry["id"])
        if old_row and old_row.get("priority") == "P0":
            ensure(entry.get("priority") == "P0",
                   "A historical P0 control must not be silently downgraded: " +
                   entry["id"])
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
    # Deterministic invariant tests run against every proposed policy change,
    # not only during the test suite. New broad patterns cannot shadow existing
    # security, MCP, data, Admin, or update categories unnoticed.
    category_probes = (
        ("includes/class-mad4b-scp-admin-options.php", "admin"),
        ("includes/class-mad4b-scp-database-schema.php", "data"),
        ("includes/class-mad4b-scp-mcp-hybrid.php", "mcp"),
        ("includes/class-mad4b-scp-authentication.php", "authority"),
        ("includes/class-mad4b-scp-plugin-rollback.php", "update"),
        ("assets/js/admin-ui.js", "admin"),
    )
    for relative, expected in category_probes:
        actual = classify(REQUIRED_SOURCE_ROOT + relative,
                          REQUIRED_SOURCE_ROOT + relative, coverage)["id"]
        ensure(actual == expected,
               "Impact classification contract drift: " + relative +
               " expected " + expected + " got " + actual)
    reviews = coverage.get("reviewed_capabilities", [])
    # Reviews are a persistent versioned registry. A previously reviewed
    # capability remains in Git after its introduction PR is merged.
    previous = json_file(base, COVERAGE) or {
        "reviewed_capabilities": [], "lifecycle": [], "domain_rules": []
    }
    previously_reviewed = {x.get("id") for x in previous.get("reviewed_capabilities", [])}
    ids = [x.get("id") for x in reviews]
    ensure(previously_reviewed <= set(ids),
           "Previously reviewed capability history must not be deleted")
    old_review_rows = {x["id"]: x for x in previous.get("reviewed_capabilities", [])}
    current_review_rows = {x["id"]: x for x in reviews}
    for old_id, old_entry in old_review_rows.items():
        newer = current_review_rows[old_id]
        ensure(set(old_entry.get("control_ids", [])) <= set(newer.get("control_ids", []))
               and set(old_entry.get("regression_tests", [])) <=
               set(newer.get("regression_tests", [])),
               "Historical capability review coverage cannot shrink: " + old_id)
    previous_retired = {x.get("id") for x in previous.get("lifecycle", [])}
    ensure(previous_retired <= {x.get("id") for x in coverage.get("lifecycle", [])},
           "A historical decommission or supersession receipt was removed")
    old_retired_rows = {x["id"]: x for x in previous.get("lifecycle", [])}
    new_retired_rows = {x["id"]: x for x in coverage.get("lifecycle", [])}
    ensure(all(new_retired_rows.get(k) == v for k, v in old_retired_rows.items()),
           "Historical decommission evidence cannot be silently changed")
    old_rules = {x["id"]: x for x in previous.get("domain_rules", [])}
    new_rules = {x["id"]: x for x in coverage.get("domain_rules", [])}
    migrations = coverage.get("policy_migrations", [])
    previous_migrations = previous.get("policy_migrations", [])
    migration_by_id = {x.get("id") : x for x in migrations}
    ensure(len(migration_by_id) == len(migrations),
           "Duplicate policy migration identity")
    ensure(all(migration_by_id.get(x["id"]) == x for x in previous_migrations),
           "Historical policy migration records cannot change")
    for rule_id, prior_rule in old_rules.items():
        current_rule = new_rules.get(rule_id)
        ensure(current_rule is not None, "Historical impact-mapping rule removed")
        if prior_rule == current_rule:
            continue
        previous_digest = hashlib.sha256(
            json.dumps(prior_rule, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()
        current_digest = hashlib.sha256(
            json.dumps(current_rule, sort_keys=True, separators=(",", ":")).encode()
        ).hexdigest()
        match = [x for x in migrations if x.get("rule_id") == rule_id
                 and x.get("from_sha256") == previous_digest
                 and x.get("to_sha256") == current_digest]
        ensure(len(match) == 1,
               "Historical impact-mapping rules require exact reviewed migration")
        migration = match[0]
        ensure(migration.get("status") == "reviewed_for_ci_only"
               and migration.get("production_authorized") is False
               and migration.get("live_evidence_required") is True
               and migration.get("owner_role")
               and len(migration.get("review_rationale", "")) >= 40,
               "Policy migration review must not grant Production authority")
        ensure(set(prior_rule.get("controls", [])) <= set(current_rule.get("controls", []))
               and (prior_rule.get("risk") != "P0" or current_rule.get("risk") == "P0"),
               "Policy migration cannot downgrade historical P0 or remove linked controls")
        tests = migration.get("regression_tests", [])
        changed_test_files = {name for _, name in changed}
        ensure(isinstance(tests, list) and len(tests) > 0
               and all(test in changed_test_files and
                       (test.startswith("tests/") or test.startswith(PLUGIN + "tests/"))
                       and (ROOT / test).is_file() for test in tests),
               "Policy migration requires new regression tests in current PR")
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
            needs_new = rec.get("needs_new_control")
            ensure(type(needs_new) is bool,
                   "New capability requires explicit checklist growth decision: " + capability)
            if needs_new:
                additions = set(rec.get("new_control_ids", []))
                ensure(additions and additions <= new_control_ids and additions <= set(ctl),
                       "New checklist controls must be added and mapped in same PR: " + capability)
            else:
                ensure(len(rec.get("coverage_reuse_rationale", "")) >= 24,
                       "Reused checklist coverage requires rationale: " + capability)
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
        ensure(x.get("id") in added or x.get("id") in previously_reviewed,
               "Coverage can only introduce an ID with new code evidence: " +
               str(x.get("id")))
        ensure(x.get("status") == "reviewed_for_ci_only" and
               x.get("production_authorized") is False and
               x.get("live_evidence_required") is True,
               "Historical coverage cannot grant runtime authority: " + str(x.get("id")))
        ensure(isinstance(x.get("control_ids"), list) and
               len(set(x["control_ids"])) == len(x["control_ids"]) and
               set(x["control_ids"]) <= ctl_ids,
               "Historical review must retain valid control IDs: " + str(x.get("id")))
    # A changed existing runtime/config path is a changed behavior candidate
    # even if no newly registered Ability or public endpoint can be seen.
    records = coverage.get("reviewed_changes", [])
    old_records = previous.get("reviewed_changes", [])
    keys = [(x.get("path"), x.get("source_blob_sha")) for x in records]
    ensure(len(keys) == len(set(keys)), "Duplicate exact-source review")
    old_map = {(x["path"], x["source_blob_sha"]): x for x in old_records}
    new_map = {(x.get("path"), x.get("source_blob_sha")): x for x in records}
    ensure(set(old_map) <= set(new_map), "Reviewed source history must be preserved")
    ensure(all(new_map.get(k) == v for k, v in old_map.items()),
           "Reviewed historical code evidence cannot be rewritten")
    for rec in records:
        ensure(rec.get("status") == "reviewed_for_ci_only"
               and rec.get("production_authorized") is False
               and rec.get("live_evidence_required") is True
               and rec.get("owner_role")
               and len(rec.get("review_rationale", "")) >= 24,
               "Invalid nonauthorizing code review record")
        linked, tests = rec.get("control_ids", []), rec.get("regression_tests", [])
        ensure(isinstance(linked, list) and len(linked) >= 2
               and len(set(linked)) == len(linked) and set(linked) <= ctl_ids
               and isinstance(tests, list) and len(tests) > 0,
               "Code review requires mapped controls and tests")
        ensure(isinstance(rec.get("path"), str)
               and is_reviewed_runtime_path(rec["path"], coverage)
               and SHA.fullmatch(str(rec.get("source_blob_sha", ""))),
               "Invalid reviewed Git blob identity or source scope")
    reviewed_behavior = []
    for status, path in changed:
        if status not in {"A", "M", "T"} or not is_reviewed_runtime_path(path, coverage):
            continue
        identity = (path, current_blob_sha(head, path))
        rec = new_map.get(identity)
        if rec is None:
            rule = classify(path, path, coverage)
            failures.append({"id": "behavior:" + path, "source": path,
                             "risk": rule["risk"], "controls": rule["controls"],
                             "reasons": ["exact_source_change_review_missing"]})
            continue
        rule = classify(path, path, coverage)
        ensure(set(rec["control_ids"]) & set(rule["controls"]),
               "Behavior review does not address impacted area: " + path)
        if identity not in old_map:
            ensure(all(isinstance(test, str) and
                       (test.startswith("tests/") or test.startswith(PLUGIN + "tests/"))
                       and test in changed_set and (ROOT / test).is_file()
                       for test in rec["regression_tests"]),
                   "Changed behavior requires a regression test in same PR: " + path)
        reviewed_behavior.append({"path": path, "git_blob": identity[1],
                                  "control_ids": rec["control_ids"]})
    return failures, accepted, reviewed_behavior


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
    failures, reviewed, behavior = validate_coverage(
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
        "reviewed_behavior_changes": behavior,
        "required_regression_tests": sorted({
            test
            for record in coverage.get("reviewed_changes", [])
            if record["path"] in {item["path"] for item in behavior}
            for test in record.get("regression_tests", [])
        } | {
            test
            for record in coverage.get("reviewed_capabilities", [])
            if record["id"] in reviewed
            for test in record.get("regression_tests", [])
        } | {
            test
            for record in coverage.get("policy_migrations", [])
            for test in record.get("regression_tests", [])
            if test in {name for _, name in changed}
        }),
        "suggested_checklist_growth": [
            {"id": capability, "source": path,
             "existing_controls": classify(path, capability, coverage)["controls"],
             "explicit_growth_decision_required": True}
            for capability, path in sorted(added.items())
        ],
        "coverage_gaps": failures,
        "impact": impact,
        "counts": {"changed_paths": len(changed), "catalog_growth": len(current) - len(old),
                   "new_capabilities": len(added), "removed_capabilities": len(removed),
                   "coverage_gaps": len(failures),
                   "impacted_files": len(impact),
                   "reviewed_behavior_changes": len(behavior)},
    }


def control_digest(row):
    """Content-addressed definition identity, not proof of runtime execution."""
    return hashlib.sha256(json.dumps(row, ensure_ascii=False, sort_keys=True,
                         separators=(",", ":")).encode("utf-8")).hexdigest()


def control_delta(previous, current):
    """Diff historical Control definitions without promoting evidence state."""
    prior = {x["id"]: x for x in (previous or {}).get("controls", [])}
    result = []
    for row in current["controls"]:
        old = prior.get(row["id"])
        old_sha = control_digest(old) if old is not None else None
        new_sha = control_digest(row)
        state = ("NEW" if old is None else
                 "UNCHANGED" if old_sha == new_sha else "REVISED")
        result.append({"id": row["id"], "state": state,
                       "base_definition_sha256": old_sha,
                       "head_definition_sha256": new_sha,
                       "authorizing": False, "live_evidence_status": "UNVERIFIED"})
    return result


def emit(report, out):
    target = Path(out)
    target.mkdir(parents=True, exist_ok=True)
    (target / "capability-impact.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with (target / "capability-impact.csv").open("w", newline="", encoding="utf-8-sig") as fh:
        w = safe_writer(fh)
        w.writerow(("Path", "Change", "Area", "Risk", "Suggested control IDs",
                    "New capability IDs", "Removed capability IDs"))
        for x in report["impact"]:
            w.writerow((x["path"], x["change"], x["area"], x["risk"],
                        ", ".join(x["suggested_controls"]),
                        ", ".join(x["introduced"]), ", ".join(x["removed"])))
    # All control statuses are projected for the exact HEAD. They are never
    # self-certified; CI coverage is distinct from signed live acceptance.
    current_controls = json.loads((ROOT / CONTROLS).read_text(encoding="utf-8"))
    previous_controls = json_file(report["source_base_sha"], CONTROLS)
    previous_ids = {x["id"] for x in (previous_controls or {}).get("controls", [])}
    revisions = control_delta(previous_controls, current_controls)
    revisions_by_id = {r["id"]: r for r in revisions}
    uncovered_controls = {
        control for gap in report["coverage_gaps"]
        for control in gap.get("controls", [])
    }
    impacted_controls = {
        control for row in report["impact"]
        for control in row.get("suggested_controls", [])
    }
    control_rows = [{
        "id": row["id"], "domain": row["domain"], "priority": row["priority"],
        "source_base_sha": report["source_base_sha"],
        "exact_head_sha": report["exact_head_sha"],
        "introduced_in_diff": row["id"] not in previous_ids,
        "definition_change": revisions_by_id[row["id"]]["state"],
        "base_definition_sha256": revisions_by_id[row["id"]]["base_definition_sha256"],
        "head_definition_sha256": revisions_by_id[row["id"]]["head_definition_sha256"],
        "source_change_impact": row["id"] in impacted_controls,
        "ci_coverage_status": (
            "BLOCKED_BY_UNCOVERED_CHANGE" if row["id"] in uncovered_controls
            else "NO_UNCOVERED_CHANGE_DETECTED"
        ),
        "live_evidence_status": "UNVERIFIED",
        "production_authorized": False,
    } for row in current_controls["controls"]]
    control_record = {
        "contract": "mad4b.readiness-control-head-history.v1",
        "base_sha": report["source_base_sha"],
        "exact_head_sha": report["exact_head_sha"],
        "authorizing": False,
        "all_live_evidence_unverified": True,
        "controls": control_rows,
    }
    (target / "control-history.json").write_text(
        json.dumps(control_record, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8")
    with (target / "control-history.csv").open(
            "w", newline="", encoding="utf-8-sig") as fh:
        writer = safe_writer(fh)
        writer.writerow(("Control ID", "Domain", "Priority", "Exact HEAD",
                         "New in diff", "Source impacted", "Coverage check",
                         "Live evidence", "Production authorized"))
        for row in control_rows:
            writer.writerow((
                row["id"], row["domain"], row["priority"],
                row["exact_head_sha"],
                "YES" if row["introduced_in_diff"] else "NO",
                "YES" if row["source_change_impact"] else "NO",
                row["ci_coverage_status"], "UNVERIFIED", "NO",
            ))
    state_counts = {
        status: sum(r["state"] == status for r in revisions)
        for status in ("NEW", "REVISED", "UNCHANGED")
    }
    delta = {
        "contract": "mad4b.readiness-control-delta.v1",
        "base_sha": report["source_base_sha"],
        "exact_head_sha": report["exact_head_sha"],
        "authorizing": False,
        "production_authorized": False,
        "counts": state_counts,
        "revisions": revisions,
    }
    (target / "control-delta.json").write_text(
        json.dumps(delta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    with (target / "control-delta.csv").open("w", newline="",
                                                encoding="utf-8-sig") as fh:
        writer = safe_writer(fh)
        writer.writerow(("Control ID", "Definition delta", "Base SHA256",
                         "Head SHA256", "Site evidence", "Production authorization"))
        for item in revisions:
            writer.writerow((item["id"], item["state"],
                             item["base_definition_sha256"] or "",
                             item["head_definition_sha256"], "UNVERIFIED", "NO"))
    report["counts"]["controls_new"] = state_counts["NEW"]
    report["counts"]["controls_revised"] = state_counts["REVISED"]
    report["counts"]["controls_unchanged"] = state_counts["UNCHANGED"]
    # Exact-head append-only logical event: immutable GitHub Actions artifact
    # tied to head+run ID. This never changes repository source or approvals.
    event = {key: report[key] for key in (
        "source_base_sha", "exact_head_sha", "decision", "counts",
        "introduced", "retired", "coverage_gaps", "authorizing",
        "production_authorized")}
    event.update({"contract": "mad4b.readiness-history-event.v1",
                  "github_run_id": os.environ.get("GITHUB_RUN_ID"),
                  "retention_note": "Run-scoped artifact; durable source history remains in Git.",
                  "control_history_count": len(control_rows),
                  "control_delta": state_counts,
                  "new_control_ids": sorted(x["id"] for x in control_rows
                                            if x["introduced_in_diff"])})
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
    lines += ["", "Per-control history and impact CSV are SHA-bound and remain non-authorizing.", ""]
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
    sample = {"controls": [{"id": "GOVERNANCE-01", "priority": "P0"}]}
    assert control_delta(sample, sample)[0]["state"] == "UNCHANGED"
    assert control_delta(sample, {"controls": [
        {"id": "GOVERNANCE-01", "priority": "P0", "acceptance": "changed"}]
    })[0]["state"] == "REVISED"
    assert control_delta(None, sample)[0]["state"] == "NEW"
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
