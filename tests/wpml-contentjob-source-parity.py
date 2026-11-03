#!/usr/bin/env python3
"""Fail-closed source-only gates for ETG's exact WPML ContentJob integration.

Intentionally offline: never claims live WordPress, browser or production verification.
"""
from pathlib import Path
import hashlib
import sys

ROOT = Path(__file__).resolve().parents[1]
PAIRS = [
    (
        "wp-content/mu-plugins/349-mad4b-wpml-design-reconciliation.php",
        "tools/dfsb-neutral/staging-mu-provenance/349-mad4b-wpml-design-reconciliation.php",
    ),
    (
        "wp-content/mu-plugins/350-wpml-job-adapter.php",
        "tools/dfsb-neutral/staging-mu-provenance/350-wpml-job-adapter.php",
    ),
]
CHECKS = []
def assert_rule(name, condition):
    CHECKS.append((name, bool(condition)))

for runtime, provenance in PAIRS:
    a = ROOT / runtime
    b = ROOT / provenance
    key = Path(runtime).name
    assert_rule(key + "_canonical_present", a.is_file())
    assert_rule(key + "_provenance_present", b.is_file())
    if a.is_file() and b.is_file():
        assert_rule(key + "_byte_identical", a.read_bytes() == b.read_bytes())
        assert_rule(key + "_staging_guard", b"tours.dev.mad4b.com" in a.read_bytes())
    else:
        assert_rule(key + "_byte_identical", False)
        assert_rule(key + "_staging_guard", False)

owner = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
jobs = (owner / "class-mad4b-scp-content-jobs.php").read_text()
group = (owner / "class-mad4b-scp-content-job-groups.php").read_text()
cwa = (owner / "class-mad4b-scp-contentjob-structural-cwa.php").read_text()
bridge = (ROOT / PAIRS[1][0]).read_text() if (ROOT / PAIRS[1][0]).is_file() else ""
assert_rule("group_ability_bound", "mad4b/content-job-group-plan" in jobs)
assert_rule("structural_cwa_ability_bound", "mad4b/content-job-structural-cwa-apply" in jobs)
assert_rule("individual_cwa_before_group", "Central_Write_Acceptance" in cwa and "reconcile_exact" in cwa)
assert_rule("group_postwrite_visible", "postwrite_verified" in group and "editorial_completed" in group)
assert_rule("current_native_wpml_review", "current_editorial_receipt(" in bridge and "ICL_TM_COMPLETE" in bridge)
assert_rule("original_review_only", "manual_human_editor_attestation_claimed" in bridge and "AI_ASSISTED_EDITORIAL_VERIFICATION" in bridge)
assert_rule("historical_outbox_immutable", "historical" in bridge and "original" in bridge)
assert_rule("no_production_deployment", "wp_get_environment_type()==='staging'" in bridge and "tours.dev.mad4b.com" in bridge)

for name, ok in CHECKS:
    print(("PASS" if ok else "FAIL") + " " + name)
bad = [name for name, ok in CHECKS if not ok]
print(f"WPML_SOURCE_PARITY {len(CHECKS)-len(bad)}/{len(CHECKS)}")
sys.exit(1 if bad else 0)
