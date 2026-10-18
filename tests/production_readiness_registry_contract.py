#!/usr/bin/env python3
"""Fail-closed negative regression suite for dynamic capability review enforcement."""
import copy
import sys
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import production_readiness_registry as reg

import json

controls = json.loads((ROOT / reg.CONTROLS).read_text(encoding="utf-8"))
policy = json.loads((ROOT / reg.COVERAGE).read_text(encoding="utf-8"))
base, head = "a" * 40, "b" * 40
path = reg.PLUGIN + "includes/class-mad4b-scp-example-security.php"
cap = "ability:mad4b/dynamic-security-demo"
test_path = "tests/production_readiness_registry_contract.py"
changed = [("A", path), ("A", test_path)]
added = {cap: path}

with mock.patch.object(reg, "json_file", return_value=copy.deepcopy(controls)), \
     mock.patch.object(reg, "current_blob_sha", return_value="c" * 40):
    failures, accepted, _ = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, changed, added, {})
    assert any("reviewed_coverage_missing" in x["reasons"] for x in failures)
    assert not accepted

    covered = copy.deepcopy(policy)
    covered["reviewed_capabilities"] = [{
        "id": cap,
        "control_ids": ["SECURITY-01", "OAUTH-01"],
        "regression_tests": [test_path],
        "owner_role": "Security + Capability Owner",
        "review_rationale": "Explicit security audit regression and owner coverage for new ability.",
        "status": "reviewed_for_ci_only",
        "production_authorized": False,
        "live_evidence_required": True,
        "needs_new_control": False,
        "coverage_reuse_rationale": "These existing security and OAuth controls cover the new ability.",
    }]
    covered["reviewed_changes"] = [{
        "path": path, "source_blob_sha": "c" * 40,
        "control_ids": ["SECURITY-01", "OAUTH-01"],
        "regression_tests": [test_path],
        "owner_role": "Security Capability Owner",
        "review_rationale": "New source is reviewed with corresponding executable security regression.",
        "status": "reviewed_for_ci_only",
        "production_authorized": False,
        "live_evidence_required": True,
    }]
    failures, accepted, _ = reg.validate_coverage(
        covered, controls, base, head, changed, added, {})
    assert not failures and accepted == [cap]

    without_growth = copy.deepcopy(covered)
    without_growth["reviewed_capabilities"][0].pop("needs_new_control")
    try:
        reg.validate_coverage(without_growth, controls, base, head, changed, added, {})
        raise AssertionError("No explicit checklist-growth decision must be denied")
    except ValueError as exc:
        assert "checklist growth decision" in str(exc)

    without_source = copy.deepcopy(covered)
    without_source["reviewed_changes"] = []
    failures, _, _ = reg.validate_coverage(without_source, controls, base, head,
                                           changed, added, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)

    # Modifying a registered source without adding a new capability still
    # requires new tests bound to the exact source blob.
    failures, _, _ = reg.validate_coverage(copy.deepcopy(policy), controls, base,
                                           head, [("M", path)], {}, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)
    stale_blob = copy.deepcopy(covered)
    stale_blob["reviewed_changes"][0]["source_blob_sha"] = "d" * 40
    failures, _, _ = reg.validate_coverage(stale_blob, controls, base, head,
                                           changed, added, {})
    assert any("exact_source_change_review_missing" in x["reasons"] for x in failures)

    stale_test = copy.deepcopy(covered)
    stale_test["reviewed_capabilities"][0]["regression_tests"] = [
        "tests/unrelated-existing-test.py"
    ]
    try:
        reg.validate_coverage(stale_test, controls, base, head, changed, added, {})
        raise AssertionError("Existing unmodified test must not certify new capability")
    except ValueError as exc:
        assert "Test evidence must be committed" in str(exc)

    forged = copy.deepcopy(covered)
    forged["reviewed_capabilities"][0]["production_authorized"] = True
    try:
        reg.validate_coverage(forged, controls, base, head, changed, added, {})
        raise AssertionError("Source coverage cannot authorize production")
    except ValueError as exc:
        assert "Untrusted capability review status" in str(exc)

    gone = {cap: path}
    retirement_changes = [("D", path), ("A", test_path)]
    failures, _, _ = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, retirement_changes, {}, gone)
    assert failures and "decommission_or_supersession_review_missing" in failures[0]["reasons"]

    retired = copy.deepcopy(policy)
    retired["lifecycle"] = [{
        "id": cap, "state": "superseded",
        "replacement": "ability:mad4b/replacement",
        "rationale": "Explicit migration from prior approved capability to replacement.",
        "production_authorized": False,
    }]
    failures, accepted, _ = reg.validate_coverage(
        retired, controls, base, head, retirement_changes, {}, gone)
    assert not failures and not accepted

    controls_missing = copy.deepcopy(controls)
    controls_missing["controls"] = [
        c for c in controls_missing["controls"] if c["id"] != "GOVERNANCE-01"
    ]
    try:
        reg.validate_coverage(policy, controls_missing, base, head, changed, {}, {})
        raise AssertionError("Deleting a historical audit control must fail")
    except ValueError as exc:
        assert "historical control was deleted" in str(exc).lower()

# A reviewed capability persists in Git after its introduction PR has merged.
# Its unchanged historic review must not fail the next unrelated PR.
def previous_review(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(covered)
    raise AssertionError("Unexpected base catalog: " + path)

with mock.patch.object(reg, "json_file", side_effect=previous_review):
    failures, accepted, _ = reg.validate_coverage(
        copy.deepcopy(covered), controls, base, head,
        [("M", "docs/OTHER.md")], {}, {})
    assert not failures and not accepted, "Historical coverage survives later PRs"

    try:
        reg.validate_coverage(copy.deepcopy(policy), controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Deleting historical reviewed coverage must fail")
    except ValueError as exc:
        assert "Previously reviewed capability history" in str(exc)

    narrower = copy.deepcopy(covered)
    narrower["reviewed_capabilities"][0]["control_ids"] = ["SECURITY-01"]
    try:
        reg.validate_coverage(narrower, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Old controls must not disappear from reviewed coverage")
    except ValueError as exc:
        assert "review coverage cannot shrink" in str(exc)

    downgrade = copy.deepcopy(controls)
    next(x for x in downgrade["controls"] if x["id"] == "GOVERNANCE-01")["priority"] = "P1"
    try:
        reg.validate_coverage(copy.deepcopy(covered), downgrade, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Old P0 must not silently become P1")
    except ValueError as exc:
        assert "silently downgraded" in str(exc)

    weakened = copy.deepcopy(covered)
    weakened["domain_rules"][0]["pattern"] = "^$"
    try:
        reg.validate_coverage(weakened, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Weakening an inherited impact mapping must fail")
    except ValueError as exc:
        assert "impact-mapping rules" in str(exc)

def previous_retirement(_commit, path):
    if path == reg.CONTROLS:
        return copy.deepcopy(controls)
    if path == reg.COVERAGE:
        return copy.deepcopy(retired)
    raise AssertionError("Unexpected base catalog: " + path)

with mock.patch.object(reg, "json_file", side_effect=previous_retirement):
    try:
        reg.validate_coverage(copy.deepcopy(policy), controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("Losing a historical retirement record must fail")
    except ValueError as exc:
        assert "historical decommission" in str(exc).lower()

    changed_retirement = copy.deepcopy(retired)
    changed_retirement["lifecycle"][0]["state"] = "deprecated"
    try:
        reg.validate_coverage(changed_retirement, controls, base, head,
                              [("M", "docs/OTHER.md")], {}, {})
        raise AssertionError("An old decommission record cannot be rewritten")
    except ValueError as exc:
        assert "decommission evidence cannot be silently changed" in str(exc)

# Static interface discovery broadens coverage without claiming runtime proof.
interface_source = """<?php
register_rest_route('mad4b/v1', '/exports', array());
add_action('wp_ajax_mad4b_export', 'callback');
wp_schedule_event(time(), 'hourly', 'mad4b_report_cron');
"""
with mock.patch.object(reg, "git_text", return_value=interface_source):
    names = reg.registered_surfaces(head, path)
    assert "rest:mad4b/v1/exports" in names
    assert "ajax:wp_ajax_mad4b_export" in names
    assert "cron:mad4b_report_cron" in names

print("DYNAMIC_READINESS_NEGATIVE_REGRESSIONS: PASS")
