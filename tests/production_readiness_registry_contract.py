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

with mock.patch.object(reg, "json_file", return_value=copy.deepcopy(controls)):
    failures, accepted = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, changed, added, {})
    assert len(failures) == 1 and "reviewed_coverage_missing" in failures[0]["reasons"]
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
    }]
    failures, accepted = reg.validate_coverage(
        covered, controls, base, head, changed, added, {})
    assert not failures and accepted == [cap]

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
    failures, _ = reg.validate_coverage(
        copy.deepcopy(policy), controls, base, head, changed, {}, gone)
    assert failures and "decommission_or_supersession_review_missing" in failures[0]["reasons"]

    retired = copy.deepcopy(policy)
    retired["lifecycle"] = [{
        "id": cap, "state": "superseded",
        "replacement": "ability:mad4b/replacement",
        "rationale": "Explicit migration from prior approved capability to replacement.",
        "production_authorized": False,
    }]
    failures, accepted = reg.validate_coverage(
        retired, controls, base, head, changed, {}, gone)
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

print("DYNAMIC_READINESS_NEGATIVE_REGRESSIONS: PASS")
