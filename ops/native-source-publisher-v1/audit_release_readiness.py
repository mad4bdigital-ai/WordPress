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
import ast
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile

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


def original_worker_contract_probes() -> dict:
    """Use the installed first-party *read-only* validators on temp plans.

    This does not call execute_claimed, create signed authority or claim a Job.
    It proves whether an arbitrary dynamically bound provider can pass the
    306 plan store and whether a core plan maps to a unique operation.
    """
    sys.path.insert(0, str(TESTS))
    sys.path.insert(0, str(INSTALLED_REGISTRY.parent))
    from test_native_source_publisher_v1 import SourcePublisher, lib
    import admission
    import registry
    import worker_adapter

    fixture = SourcePublisher("test_two_operators_two_sites_one_engine")
    fixture.setUp()
    try:
        plan = fixture.plan1
        with tempfile.TemporaryDirectory(prefix="mad4b-source-original313-contract-") as temp:
            plan_root = Path(temp)
            plan_root.chmod(0o700)
            path = plan_root / (plan["plan_sha256"] + ".json")
            path.write_text(json.dumps(plan, sort_keys=True))
            path.chmod(0o600)
            try:
                admission.checked_plan(plan_root, plan["plan_sha256"], fixture.manifest1)
                envelope = "ACCEPTED"
            except registry.RegistryDenied as exc:
                envelope = "DENIED:" + str(exc)

            core_doc = lib.core_plan(plan)
            path.write_text(json.dumps(core_doc, sort_keys=True))
            try:
                admission.checked_plan(plan_root, plan["plan_sha256"], fixture.manifest1)
                original = "ACCEPTED"
            except registry.RegistryDenied as exc:
                original = "DENIED:" + str(exc)

            class TempIndex:
                manifests = fixture.registry.manifests

                @staticmethod
                def command_sha256(op: str, plan_sha256: str) -> str:
                    # Test-only unique hashes; no original Broker claims.
                    return registry.sha(
                        json.dumps([op, plan_sha256], separators=(",", ":")).encode()
                    )

            mapped = worker_adapter.indexed_plans(TempIndex(), plan_root)
            operations = sorted({record["operation"] for record in mapped.values()})
            return {
                "test_scope": "ORIGINAL_VALIDATORS_ON_EPHEMERAL_PLAN_STORE",
                "original313_accepts_dynamic_envelope": envelope,
                "original313_accepts_plain_core_plan": original,
                "one_core_plan_indexed_under_operations": operations,
                "operation_exclusive": operations == [fixture.manifest1["operation_id"]],
                "job_issued": False,
                "grant_issued": False,
                "installed_worker_modified": False,
            }
    finally:
        fixture.doCleanups()


def verified_registry_candidates(candidates: dict[str, dict]) -> set[str]:
    """Never trust index name presence as installed executable verification.

    The installed first-party Registry rechecks root ownership, pinned
    manifest, provider handler, verifier, source dependencies and evidence
    schema SHAs. Finally require the installed contents equal candidate
    bytes exactly. This is still not a signed original 312/313 authority.
    """
    if not INSTALLED_REGISTRY.is_file() or INSTALLED_REGISTRY.is_symlink():
        return set()
    path=INSTALLED_REGISTRY.parent/"registry.py"
    if not path.is_file() or path.is_symlink():
        return set()
    spec=importlib.util.spec_from_file_location(
        "_mad4b_dynamic_readonly_firstparty_registry",path
    )
    if spec is None or spec.loader is None:
        return set()
    module=importlib.util.module_from_spec(spec)
    try:
        spec.loader.exec_module(module)
        installed=module.Registry(INSTALLED_REGISTRY.parent)
        return {
            op for op, candidate in candidates.items()
            if op in installed.manifests and
            installed.resolve(op)==candidate
        }
    except (OSError,ValueError,KeyError,RuntimeError,TypeError):
        return set()


def verifier_environment_contract_probe() -> dict:
    """Observe a verifier/schema mismatch without executing signed 313.

    Installed evidence.verify_signed requires the production_mutation flag
    to match the job environment. A source verifier that always emits false
    cannot satisfy a Production proof. This is a static contract audit only.
    """
    verifier=HERE/"original_313_independent_readback.py"
    tree=ast.parse(verifier.read_text())
    value=None
    for func in tree.body:
        if isinstance(func,ast.FunctionDef) and func.name=="evidence_for_original_job":
            for node in ast.walk(func):
                if isinstance(node,ast.Dict):
                    for key,item in zip(node.keys,node.values):
                        if (isinstance(key,ast.Constant) and
                            key.value=="production_mutation"):
                            value=(item.value if isinstance(item,ast.Constant) else "DYNAMIC")
    installed_evidence=INSTALLED_REGISTRY.parent/"evidence.py"
    authority_requires_environment_match=(
        installed_evidence.is_file() and
        'evidence.get("production_mutation") is (proof["environment"]=="production")'
        in installed_evidence.read_text()
    )
    return {
        "candidate_production_mutation_expression":value,
        "original313_requires_env_bound_mutation_flag":authority_requires_environment_match,
        "production_proof_compatible":value=="DYNAMIC" and authority_requires_environment_match,
        "original_independent_signer_modified":False,
        "signed_proof_created":False,
    }


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
    verified=verified_registry_candidates(candidates)
    not_verified=sorted(set(candidates)-verified)
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
        "registry_hash_verified_candidate_operations": sorted(verified),
        "registry_unverified_candidate_operations": not_verified,
        "all_candidate_manifests_sha_verified": not not_verified,
        "native_policy_file_present": POLICY_PATH.exists(),
        "central_source_review_module_bootstrapped": bootstrapped,
        "first_party_signed_enrollment_verified": False,
    }


def evaluate() -> dict:
    probes = candidate_probes()
    original_contract = original_worker_contract_probes()
    verifier_environment = verifier_environment_contract_probe()
    installed = installed_status()
    blockers = []
    if probes["different_git_source_for_same_target"] != "DENIED":
        blockers.append("SOURCE_TARGET_EQUIVALENCE_NOT_ENFORCED")
    if probes["changed_count_mismatch"] != "DENIED":
        blockers.append("DERIVED_CHANGED_COUNT_NOT_REVALIDATED")
    if original_contract["original313_accepts_dynamic_envelope"] != "ACCEPTED":
        blockers.append("ORIGINAL313_PLAN_ENVELOPE_NOT_COMPATIBLE")
    if not original_contract["operation_exclusive"]:
        blockers.append("ORIGINAL306_PLAN_OPERATION_NOT_UNIQUELY_BOUND")
    if not verifier_environment["production_proof_compatible"]:
        blockers.append("ORIGINAL313_PRODUCTION_EVIDENCE_ENVIRONMENT_MISMATCH")
    if not installed["all_candidate_operations_present_in_index"]:
        blockers.append("FIRST_PARTY_SOURCE_PROVIDER_NOT_ENROLLED")
    if (installed["all_candidate_operations_present_in_index"]
        and not installed["all_candidate_manifests_sha_verified"]):
        blockers.append("FIRST_PARTY_PROVIDER_CONTENT_NOT_VERIFIED")
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
        "original_worker_contract_probes": original_contract,
        "verifier_environment_contract_probe": verifier_environment,
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
