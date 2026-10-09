#!/usr/bin/env python3
"""Adversarial tests for dynamic CSO01 acceptance configuration only."""
import copy
from pathlib import Path
from validate_dynamic_acceptance import read_json, validate_bundle, resolve_profile, ROOT

registry = read_json(ROOT / "acceptance" / "registry.json")
profiles = read_json(ROOT / "acceptance" / "profiles.json")

def rejects(label, change):
    r, p = copy.deepcopy(registry), copy.deepcopy(profiles)
    change(r, p)
    faults = validate_bundle(r, p)
    assert faults, label + " must be rejected"
    print("PASS negative:", label, "=>", faults[0])

assert not validate_bundle(registry, profiles), validate_bundle(registry, profiles)
for profile in profiles["profiles"]:
    resolved = resolve_profile(registry, profiles, profile["id"])
    assert resolved["operational_acceptance"] == "NOT_RUN" and not resolved["release_authorized"]
    assert len(resolved["suite_states"]) >= 7 and all(s == "OPEN" for s in resolved["suite_states"].values())
print("PASS baseline: seven supplementary suites remain OPEN")
rejects("duplicate suite", lambda r,p: r["suites"].append(copy.deepcopy(r["suites"][0])))
rejects("drop initial suite", lambda r,p: r["suites"].pop(0))
rejects("false completed gate", lambda r,p: r["suites"][0].update(status="PASS", claim_complete=True))
rejects("cycle", lambda r,p: r["suites"][0].update(depends_on=["CSO-A07"]))
rejects("unknown parent gate", lambda r,p: r["suites"][0].update(parent_gates=["CSO-G999"]))
rejects("unknown evidence type", lambda r,p: r["suites"][0]["checks"][0].update(evidence_type="user_says_pass"))
rejects("untrusted verifier", lambda r,p: r.update(external_acceptance_required=False))
rejects("weaken baseline min threshold", lambda r,p: p["profiles"][1]["thresholds"].update(min_catalog_abilities=2))
rejects("weaken baseline max threshold", lambda r,p: p["profiles"][1]["thresholds"].update(max_search_p95_ms=99999))
rejects("profile cycle", lambda r,p: p["profiles"][0].update(extends="large_catalog"))
rejects("unknown additional check", lambda r,p: p["profiles"][1].update(additional_checks=["unknown_check"]))
overlay = read_json(ROOT / "acceptance" / "site-overlay.example.json")
site = resolve_profile(registry, profiles, overlay["profile_id"], overlay)
assert site["thresholds"]["min_catalog_abilities"] == 6000
assert "client_low_bandwidth_and_offline" in site["required_checks"]
bad = copy.deepcopy(overlay)
bad["thresholds"] = {"max_search_p95_ms":99999}
try:
    resolve_profile(registry, profiles, bad["profile_id"], bad)
    raise AssertionError("site must never loosen threshold")
except ValueError:
    pass
bad = copy.deepcopy(overlay)
bad["production_authorized"] = True
try:
    resolve_profile(registry, profiles, bad["profile_id"], bad)
    raise AssertionError("site overlay must never grant authority")
except ValueError:
    pass
print("CSO01_DYNAMIC_ACCEPTANCE_CONFIG_TESTS: PASS (NOT operational gate PASS)")
