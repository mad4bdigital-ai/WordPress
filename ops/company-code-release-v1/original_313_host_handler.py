#!/usr/bin/env python3
"""Source-only first-party original 313 Code Release host adapter.

An original 305 enrollment is necessary but not sufficient. No code release
occurs unless the *running* original 306 worker has registered the exact
operation, the original WordPress 312 verifies a claimed Job matching a
pinned Git plan, and an independently issued exact-plan Broker release grant
passes the host-policy verification. This module NEVER enrolls itself,
creates a Job/Claim/Grant, or certifies a 313 receipt.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import stat
from typing import Callable

import company_code_release as release
import original_313_code_release_contract as original
import company_lifecycle_broker as lifecycle

HOST = "srv2030908"
PLANS = Path("/var/lib/mad4b/company-code-release/plans")
GRANTS = Path("/var/lib/mad4b/company-code-release/grants")
ORIGINAL_313 = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "plugin-install-v1/claimed-job-worker.release.py"
)
ORIGINAL_313_EXECUTOR = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "plugin-install-v1/original-wpcli-executor.py"
)
ORIGINAL_312 = Path(
    "/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py"
)
DYNAMIC_INSTALLED_REGISTRY = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
DYNAMIC_PLAN_STORE = Path(
    "/var/lib/mad4b/dynamic-operation-registry/plans"
)
WORKER_OPERATION = "company.code.release"


class NotEnrolled(RuntimeError):
    pass


def regular_private(path: Path, *, max_bytes: int = 2_000_000) -> bytes:
    release.no_link(path)
    if not path.is_file():
        raise NotEnrolled("ORIGINAL_ENROLLED_ARTIFACT_MISSING")
    s = path.lstat()
    if (not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or
            stat.S_IMODE(s.st_mode) & 0o077 or not 10 < s.st_size < max_bytes):
        raise NotEnrolled("ORIGINAL_ARTIFACT_NOT_ROOT_PRIVATE")
    return path.read_bytes()


def trusted_load(path: Path) -> dict:
    obj = json.loads(regular_private(path))
    if not isinstance(obj, dict):
        raise NotEnrolled("ORIGINAL_ARTIFACT_NOT_OBJECT")
    return obj


def exact_receipt_files(job_id: str, plan_digest: str) -> tuple[Path, Path]:
    if not isinstance(job_id, str) or original.RX32.fullmatch(job_id) is None:
        raise NotEnrolled("ORIGINAL_JOB_ID_INVALID")
    if not isinstance(plan_digest, str) or original.RX64.fullmatch(plan_digest) is None:
        raise NotEnrolled("ORIGINAL_PLAN_SHA_INVALID")
    return PLANS / (plan_digest + ".json"), GRANTS / (job_id + ".json")


def current_original_worker_digests(worker: Path = ORIGINAL_313) -> dict:
    """Read the actual first-party 306 worker digest table, without executing a Job."""
    if worker != ORIGINAL_313 or worker.is_symlink() or not worker.is_file():
        raise NotEnrolled("ORIGINAL_313_WORKER_LOCATION_NOT_PINNED")
    info = worker.stat()
    if info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
        raise NotEnrolled("ORIGINAL_313_WORKER_NOT_TRUSTED")
    spec = importlib.util.spec_from_file_location(
        "_mad4b_original313_code_release_worker", str(worker)
    )
    if spec is None or spec.loader is None:
        raise NotEnrolled("ORIGINAL_313_WORKER_NOT_LOADABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    result = module.operation_digests()
    if not isinstance(result, dict):
        raise NotEnrolled("ORIGINAL_313_DIGEST_TABLE_INVALID")
    return result


def dynamic_operation_enrolled(plan: dict) -> bool:
    """First-party installed Registry, not a source catalog or an ad-hoc flag."""
    root = DYNAMIC_INSTALLED_REGISTRY
    if not root.exists():
        return False
    if not root.is_dir() or root.is_symlink() or root.stat().st_uid != 0 or (
        stat.S_IMODE(root.stat().st_mode) & 0o022
    ):
        raise NotEnrolled("DYNAMIC_FIRST_PARTY_REGISTRY_UNTRUSTED")
    registry_file = root / "registry.py"
    if registry_file.is_symlink() or not registry_file.is_file() or (
        registry_file.stat().st_uid != 0 or
        stat.S_IMODE(registry_file.stat().st_mode) & 0o022
    ):
        raise NotEnrolled("DYNAMIC_REGISTRY_MODULE_NOT_INSTALLED")
    spec = importlib.util.spec_from_file_location(
        "_mad4b_original_dynamic_operation_registry", registry_file
    )
    if spec is None or spec.loader is None:
        raise NotEnrolled("DYNAMIC_REGISTRY_MODULE_NOT_LOADABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    index = module.Registry(root)
    manifest = index.resolve(WORKER_OPERATION)
    source = Path(__file__)
    if index.root / manifest["handler"]["path"] != source or (
        module.sha(module.protected_file(source, index.root, maximum=2_000_000))
        != manifest["handler"]["sha256"]
    ):
        raise NotEnrolled("DYNAMIC_HOST_HANDLER_NOT_PINNED")
    expected = original.command_sha256(plan["plan_sha256"])
    if index.command_sha256(WORKER_OPERATION, plan["plan_sha256"]) != expected:
        raise NotEnrolled("DYNAMIC_COMMAND_AND_RELEASE_PLAN_DIVERGED")
    store = DYNAMIC_PLAN_STORE
    if not store.is_dir() or store.is_symlink() or store.stat().st_uid != 0 or (
        stat.S_IMODE(store.stat().st_mode) & 0o022
    ):
        raise NotEnrolled("DYNAMIC_REGISTERED_PLAN_STORE_MISSING")
    enrolled_plan = module.checked_json(
        store / (plan["plan_sha256"] + ".json"), store
    )
    if enrolled_plan != plan or (
        module.sha(module.canonical({
            k:v for k,v in enrolled_plan.items()
            if k not in ("plan_sha256","changed_count")
        })) != plan["plan_sha256"]
    ):
        raise NotEnrolled("DYNAMIC_REGISTERED_PLAN_MISMATCH")
    return True


def installed_original_operation(
    plan: dict,
    *,
    worker: Path = ORIGINAL_313,
    executor: Path = ORIGINAL_313_EXECUTOR,
    digest_reader: Callable[[Path], dict] = current_original_worker_digests,
    operation_inspector: Callable[[Path], set[str]] =
        lifecycle.original_handler_operations,
) -> None:
    # Require BOTH the running 306 worker digest table AND 313 executor choice
    # to be registered. Merely adding a catalog row is never enough.
    if dynamic_operation_enrolled(plan):
        return
    if worker != ORIGINAL_313 or executor != ORIGINAL_313_EXECUTOR:
        raise NotEnrolled("ORIGINAL_WORKER_LOCATION_NOT_PINNED")
    expected = original.command_sha256(plan["plan_sha256"])
    if digest_reader(worker).get(expected) != WORKER_OPERATION:
        raise NotEnrolled("ORIGINAL_306_COMMAND_DIGEST_NOT_ENROLLED")
    if WORKER_OPERATION not in operation_inspector(executor):
        raise NotEnrolled("ORIGINAL_313_CODE_RELEASE_HANDLER_NOT_ENROLLED")


def original_claim(
    job_id: str,
    plan: dict,
    *,
    operation_inspector: Callable[[Path], set[str]] =
        lifecycle.original_handler_operations,
    status_provider: Callable[[str, str], dict] =
        lifecycle.live_reconcile,
    signed_authority: Callable[[str, str, str], dict] | None = None,
) -> dict:
    """Confirm actual running operation registration before querying 312."""
    original.require_plan(plan)
    installed_original_operation(plan, operation_inspector=operation_inspector)
    state = status_provider(plan["company"], plan["environment"])
    basic = (state.get("contract") ==
             "mad4b.company-code-release.lifecycle-bridge.v1" and
             state.get("company") == plan["company"] and
             state.get("environment") == plan["environment"] and
             state.get("site_uuid") == plan["site_uuid"] and
             state.get("canonical_origin") == plan["origin"] and
             state.get("original_305_standing_wpcli_verified") is True and
             state.get("original_312_verifier_registered") is True)
    legacy_registered = (
        state.get("original_313_code_release_handler_registered") is True and
        state.get("company_code_release_dispatch_ability_registered") is True)
    # An enrolled dynamic 306/313 implementation is a separate first-party
    # route: it need not be listed in the legacy static --operation choices.
    # The installed immutable plan and pinned handler were verified above,
    # and actual 312 signed authority is re-fetched below for THIS Job.
    dynamic_registered = (
        state.get("dynamic_registry_enrolled") is True and
        state.get("dynamic_306_digest_registered") is True and
        state.get("dynamic_313_independent_verifier_registered") is True and
        state.get("dynamic_313_finalizer_connected") is True)
    if not basic or not (legacy_registered or dynamic_registered):
        raise NotEnrolled("ORIGINAL_COMPANY_RELEASE_NOT_ENROLLED")
    if signed_authority is None:
        # Original verifier signs its own short-lived WordPress status request,
        # but does not create an execution authorization for an unknown Job.
        if ORIGINAL_312.is_symlink() or not ORIGINAL_312.is_file():
            raise NotEnrolled("ORIGINAL_312_VERIFIER_NOT_PINNED")
        info = ORIGINAL_312.stat()
        if info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
            raise NotEnrolled("ORIGINAL_312_VERIFIER_UNTRUSTED")
        spec = importlib.util.spec_from_file_location(
            "_mad4b_original312_code_release", str(ORIGINAL_312))
        if spec is None or spec.loader is None:
            raise NotEnrolled("ORIGINAL_312_VERIFIER_NOT_LOADABLE")
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        signed_authority = mod.verify
    candidate = original.candidate_original_operation(plan)
    authority = signed_authority(
        job_id, HOST, candidate["command_sha256"]
    )
    original.eligible_original_312_claim(authority, plan, job_id)
    return authority


def execute_once(
    job_id: str, plan: dict, policy: dict, release_grant: dict,
    *,
    original_claim_provider: Callable[[str, dict], dict] = original_claim,
    runtime_check: Callable[[dict, dict], None] =
        release.assert_runtime_binding,
    code_check: Callable[[dict], None] = release.php_lint,
    candidate_preflight_check: Callable[[dict], None] = release.preflight_candidate_php,
) -> dict:
    """Single already-admitted operation; no worker registration or 313 fraud.

    Production CLI may call this only after original_claim() verifies a live
    registered 313 worker and a real signed original 312 Job. Tests can inject
    valid fixtures but cannot turn a source-only file into an installed worker.
    """
    # Caller-supplied booleans or cached 312 dictionaries are not admissible
    # as execution permission. The production CLI always invokes the actual
    # registered first-party 306/312 verifier via original_claim().
    # The provider injection exists for hermetic unit tests only.
    original_authority_proof = original_claim_provider(job_id, plan)
    original.eligible_original_312_claim(original_authority_proof, plan, job_id)
    if not isinstance(policy, dict) or policy.get("contract") != release.POLICY_CONTRACT:
        raise NotEnrolled("PINNED_HOST_POLICY_MISSING")
    # Original 312 Job and short-lived host grant must be bound to the SAME
    # original job ID. This field is included in the Broker signed payload
    # and cannot be added by a local operator after signing.
    if (not isinstance(release_grant, dict) or
            release_grant.get("original_job_id") != job_id):
        raise NotEnrolled("ORIGINAL_312_JOB_AND_BROKER_GRANT_NOT_BOUND")
    release.verify_grant(plan, policy, release_grant, "apply")
    receipts = Path(policy["receipt_dir"])
    # Host operation performs its own original-native runtime checks and
    # persists private backup + one-time receipt, with automatic rollback.
    result = release.apply_release(
        plan, policy, release_grant, receipts,
        runtime_check=runtime_check, validate_code=code_check,
        preflight_code=candidate_preflight_check,
    )
    host_receipt_state = result.get("state")
    observed_state = {
        "APPLIED_VERIFIED": "HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313",
        "ROLLED_BACK": "HOST_APPLY_ROLLED_BACK",
        "ABORTED_NO_MUTATION": "HOST_APPLY_ABORTED_NO_MUTATION",
        "ROLLBACK_UNVERIFIED": "HOST_APPLY_INCIDENT_RECOVERY_REQUIRED",
    }.get(host_receipt_state, "HOST_APPLY_RESULT_UNRECOGNIZED_HOLD")
    return {
        "contract": "mad4b.company-code-release.original313-candidate.v1",
        "state": observed_state,
        "incident_requires_recovery": host_receipt_state == "ROLLBACK_UNVERIFIED",
        "job_id": job_id,
        "plan_sha256": plan["plan_sha256"],
        "host_receipt_state": result.get("state"),
        "original_313_certified": False,
        "original_313_receipt_generated": False,
        "independent_original_313_required": True,
    }


def cli() -> int:
    """Disabled-by-default host entrypoint; no arbitrary paths or commands."""
    import argparse
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--job", required=True)
    parser.add_argument("--plan-sha256", required=True)
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise NotEnrolled("ROOT_HOST_OPERATOR_REQUIRED")
    planfile, grantfile = exact_receipt_files(args.job, args.plan_sha256)
    # Only read immutable root-owned plan data before original worker
    # enrollment. No target release code is executed until full admission.
    plan = trusted_load(planfile)
    installed_original_operation(plan)
    if plan.get("plan_sha256") != args.plan_sha256:
        raise NotEnrolled("PLAN_PATH_AND_CONTENT_DRIFT")
    policy = release.trusted_config(release.POLICY_PATH)
    grant = trusted_load(grantfile)
    result = execute_once(args.job, plan, policy, grant)
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["state"] == "HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313" else 3


if __name__ == "__main__":
    try:
        raise SystemExit(cli())
    except (NotEnrolled, original.GateDenied, release.Denied, OSError,
            ValueError, KeyError) as exc:
        # Do not reveal the original grant payload or private key through
        # native logs or web response.
        print(json.dumps({"state":"ORIGINAL_313_CODE_RELEASE_DENIED",
                          "reason":type(exc).__name__}))
        raise SystemExit(2)
