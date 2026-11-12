#!/usr/bin/env python3
"""Read-only, fail-closed release audit for dynamically catalogued source writes.

This does not activate the publisher, alter a policy, grant permissions,
or invoke an enrolled worker. Its two probes use temporary git repositories
and source-file fixtures; they intentionally report existing gaps.
"""
from __future__ import annotations
from copy import deepcopy
from datetime import datetime, timezone
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
TESTS = ROOT / "tests"
INSTALLED_REGISTRY = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled/index.json"
)
POLICY_PATH = Path("/etc/mad4b/native-source-publisher-policy.v2.json")


def candidate_probes() -> dict:
    sys.path.insert(0, str(TESTS))
    from test_native_source_publisher_v1 import (
        SourcePublisher, JS2, lib,
    )

    fixture = SourcePublisher("test_two_operators_two_sites_one_engine")
    fixture.setUp()
    try:
        different = deepcopy(fixture.request1)
        different["files"][0]["source"] = JS2
        try:
            lib.make_plan(different, fixture.policy, fixture.registry)
            cross_source = "ACCEPTED"
        except lib.Denied:
            cross_source = "DENIED"
        derived = deepcopy(fixture.plan1)
        derived["changed_count"] = 999
        try:
            lib.check_plan(derived, fixture.policy, registry=fixture.registry)
            incorrect_count = "ACCEPTED"
        except lib.Denied:
            incorrect_count = "DENIED"
        return {
            "test_scope": "EPHEMERAL_GIT_FIXTURES_ONLY",
            "operation_discovery": "TWO_DISTINCT_PROVIDER_MANIFESTS",
            "different_git_source_for_same_target": cross_source,
            "changed_count_mismatch": incorrect_count,
            "site_files_touched": False,
        }
    finally:
        fixture.doCleanups()


def installed_status() -> dict:
    """Read all candidate manifest definitions; never assume an operation ID."""
    generated = HERE / "catalog/generated"
    candidates = {}
    for candidate_file in sorted(generated.glob("*.manifest.json")):
        if candidate_file.is_symlink() or not candidate_file.is_file():
            raise ValueError("UNSAFE_SOURCE_MANIFEST_CANDIDATE")
        candidate = json.loads(candidate_file.read_text())
        op = candidate.get("operation_id")
        if (not isinstance(op, str) or
                candidate_file.name != op + ".manifest.json" or
                op in candidates):
            raise ValueError("INVALID_DYNAMIC_MANIFEST_OR_DUPLICATE")
        candidates[op] = candidate
    if not candidates:
        raise ValueError("SOURCE_CATALOG_CANDIDATES_EMPTY")

    index_entries = {}
    if INSTALLED_REGISTRY.is_file() and not INSTALLED_REGISTRY.is_symlink():
        index = json.loads(INSTALLED_REGISTRY.read_text())
        for row in index.get("providers", []):
            if not isinstance(row, dict):
                continue
            path = row.get("manifest", "")
            if isinstance(path, str) and path.endswith("/manifest.json"):
                operation = Path(path).parent.name
                index_entries[operation] = row
    unregistered = sorted(set(candidates) - set(index_entries))
    # Presence in an index is not signature verification. This audit never
    # claims that an installed provider is authorized without the first-party
    # 306/313 readback and independently signed receipts.
    main = ROOT / "wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php"
    bootstrapped = (
        "class-mad4b-scp-native-source-release-review.php" in main.read_text()
    )
    return {
        "candidate_operations": sorted(candidates),
        "unregistered_candidate_operations": unregistered,
        "all_candidate_operations_present_in_index": not unregistered,
        "native_policy_file_present": POLICY_PATH.exists(),
        "central_source_review_module_bootstrapped": bootstrapped,
        "first_party_signed_enrollment_verified": False,
    }


def evaluate() -> dict:
    probes = candidate_probes()
    installed = installed_status()
    blockers = []
    if probes["different_git_source_for_same_target"] != "DENIED":
        blockers.append("SOURCE_TARGET_EQUIVALENCE_NOT_ENFORCED")
    if probes["changed_count_mismatch"] != "DENIED":
        blockers.append("DERIVED_CHANGED_COUNT_NOT_REVALIDATED")
    if not installed["all_candidate_operations_present_in_index"]:
        blockers.append("FIRST_PARTY_SOURCE_PROVIDER_NOT_ENROLLED")
    if not installed["native_policy_file_present"]:
        blockers.append("ROOT_OWNED_NATIVE_POLICY_NOT_ENROLLED")
    if not installed["central_source_review_module_bootstrapped"]:
        blockers.append("CENTRAL_SOURCE_READ_BRIDGE_NOT_INSTALLED")
    return {
        "contract": "mad4b.native-source-dynamic-release-readonly-audit.v1",
        "observed_utc": datetime.now(timezone.utc).isoformat(),
        "state": "HOLD" if blockers else "SOURCE_AUDIT_PASS__SIGNED_AUTHORITY_STILL_REQUIRED",
        "blockers": blockers,
        "ephemeral_security_probes": probes,
        "installed_status": installed,
        "first_party_signed_original313_receipt_verified": False,
        "authorization_issued": False,
        "live_deployment_performed": False,
        "production_changed": False,
    }


def main() -> int:
    audit = evaluate()
    print(json.dumps(audit, indent=2, sort_keys=True))
    return 2 if audit["state"] == "HOLD" else 0


if __name__ == "__main__":
    raise SystemExit(main())
