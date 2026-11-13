"""Read-only, fail-closed blue/green admission for Original Source Publisher.

There are deliberately NO docker commands, Traefik mutations, signing keys,
approval issuers or apply entry points in this module.
"""
from __future__ import annotations
import importlib.util
from pathlib import Path
import re

s = importlib.util.spec_from_file_location(
    "_mad4b_runtime_candidate_model_v1",
    Path(__file__).with_name("immutable_runtime.py"))
runtime = importlib.util.module_from_spec(s)
s.loader.exec_module(runtime)

CONTRACT = "mad4b.immutable-runtime.bluegreen-admission.v1"
OPERATION = re.compile(r"^[a-z][a-z0-9._-]{2,90}$")
REQUIRED_ROLES = (
    "publisher", "runtime_start", "runtime_probe",
    "traffic_switch", "rollback", "independent_finalization"
)


def blueprint(runtime_plan: dict, *, active_image_id: str,
              candidate_image_id: str, provider_roles: dict,
              installed_manifest_digest: dict[str, str]) -> dict:
    """Build a deterministic desired change, not a grant or traffic command."""
    runtime.verify_plan(runtime_plan)
    require = runtime.require
    require(isinstance(active_image_id, str) and
            re.fullmatch(r"sha256:[a-f0-9]{64}", active_image_id) is not None
            and isinstance(candidate_image_id, str) and
            re.fullmatch(r"sha256:[a-f0-9]{64}", candidate_image_id) is not None
            and active_image_id != candidate_image_id,
            "RUNTIME_SLOTS_MUST_BE_INDEPENDENT")
    require(isinstance(provider_roles, dict) and
            set(REQUIRED_ROLES).issubset(set(provider_roles)) and
            len(provider_roles) <= 64,
            "RUNTIME_PROVIDER_ROLES_INCOMPLETE")
    for role, operation in provider_roles.items():
        require(isinstance(role, str) and OPERATION.fullmatch(role) is not None and
                isinstance(operation, str) and
                OPERATION.fullmatch(operation) is not None,
                "RUNTIME_PROVIDER_OPERATION_ID_INVALID")
    require(isinstance(installed_manifest_digest, dict),
            "RUNTIME_PROVIDER_DIGEST_MAP_INVALID")
    roles = {}
    for role, operation in sorted(provider_roles.items()):
        expected = installed_manifest_digest.get(operation)
        require(isinstance(expected, str) and
                re.fullmatch(r"[a-f0-9]{64}", expected) is not None,
                "RUNTIME_PROVIDER_NOT_REGISTRY_PINNED:" + role)
        roles[role] = {"operation_id":operation,
                       "enrolled_manifest_sha256":expected}
    result = {
        "contract": CONTRACT,
        "site": runtime_plan["site"],
        "runtime_plan_sha256": runtime_plan["runtime_plan_sha256"],
        "active_image_id": active_image_id,
        "candidate_image_id": candidate_image_id,
        "providers": roles,
        "activation_mode": "BLUE_GREEN_CANDIDATE_ONLY",
        "candidate_requires_independent_health": True,
        "original_signed_312_required": True,
        "original_signed_313_required": True,
        "database_backward_compatibility_proof_required": True,
        "traffic_drain_proof_required": True,
        "router_cutover_proof_required": True,
        "recovery_slot_pinned": True,
        "not_an_original_approval_or_grant": True,
        "state": "NOT_AUTHORIZED_FOR_ACTIVATION",
    }
    result["bluegreen_plan_sha256"] = runtime.sha(runtime.canonical(result))
    return result


def audit(*, runtime_plan: dict, image_description: dict,
          registry=None, original_source_policy=None,
          role_bindings: dict | None = None,
          approved_blueprint: dict | None = None,
          running_image_id: str | None = None) -> dict:
    """Independently reconstruct health and enrollment blockers from readback."""
    runtime.verify_plan(runtime_plan)
    holds = []
    image_id = image_description.get("Id")
    labels = image_description.get("Config", {}).get("Labels") or {}
    if not isinstance(image_id, str) or not re.fullmatch(r"sha256:[a-f0-9]{64}", image_id):
        holds.append("OCI_CANDIDATE_IMAGE_ID_UNVERIFIED")
    if labels.get("org.mad4b.runtime.plan") != runtime_plan["runtime_plan_sha256"]:
        holds.append("OCI_PLAN_LABEL_MISMATCH")
    if labels.get("org.mad4b.runtime.site") != runtime_plan["site"]["site_uuid"]:
        holds.append("OCI_SITE_LABEL_MISMATCH")
    if running_image_id is None or not re.fullmatch(
            r"sha256:[a-f0-9]{64}", str(running_image_id)):
        holds.append("ACTIVE_SITE_IMAGE_NOT_PINNED")
    elif running_image_id == image_id:
        holds.append("CANDIDATE_AND_ACTIVE_RUNTIME_IDENTICAL")
    binding_verified = isinstance(approved_blueprint, dict) and (
        approved_blueprint.get("contract") == CONTRACT and
        approved_blueprint.get("runtime_plan_sha256") ==
            runtime_plan["runtime_plan_sha256"] and
        approved_blueprint.get("candidate_image_id") == image_id and
        approved_blueprint.get("active_image_id") == running_image_id and
        approved_blueprint.get("bluegreen_plan_sha256") ==
            runtime.sha(runtime.canonical({
                k:v for k,v in approved_blueprint.items()
                if k!="bluegreen_plan_sha256"})))
    if not binding_verified:
        holds.append("BLUEGREEN_PLAN_NOT_BYTE_BOUND_TO_RUNTIME")
    if registry is None or not isinstance(role_bindings, dict) or (
            not set(REQUIRED_ROLES).issubset(set(role_bindings))):
        holds.append("ORIGINAL_PROVIDER_ROLE_REGISTRY_MISSING")
    else:
        pinned=(approved_blueprint.get("providers",{})
                if binding_verified else {})
        for role, name in sorted(role_bindings.items()):
            if (not isinstance(role,str) or OPERATION.fullmatch(role) is None or
                    not isinstance(name, str) or OPERATION.fullmatch(name) is None):
                holds.append("ROLE_OPERATION_INVALID:" + str(role))
                continue
            try:
                manifest = registry.resolve(name)
                digest = registry.manifest_sha256(name)
                if (not isinstance(manifest,dict) or
                    manifest.get("operation_id")!=name or
                    pinned.get(role)!={
                        "operation_id":name,
                        "enrolled_manifest_sha256":digest}):
                    raise ValueError("manifest mismatch")
            except (ValueError, RuntimeError, KeyError, OSError, AttributeError):
                holds.append("ORIGINAL_PROVIDER_NOT_ENROLLED:" + role)
    if original_source_policy is not True:
        holds.append("ORIGINAL_NATIVE_SOURCE_PUBLISHER_POLICY_NOT_ENROLLED")
    # The original Broker/independent Finalizer must assert these separately
    # during each real Job. This read-only planner cannot replace those.
    holds.extend([
        "ORIGINAL312_SIGNED_PLAN_BOUND_GRANT_NOT_PROVIDED",
        "INDEPENDENT_DATABASE_COMPATIBILITY_NOT_ATTESTED",
        "HTTP_TRAFFIC_DRAIN_AND_COHERENCE_NOT_ATTESTED",
        "ORIGINAL313_INDEPENDENT_FINALIZATION_NOT_ATTESTED",
    ])
    return {
        "contract": CONTRACT,
        "runtime_plan_sha256": runtime_plan["runtime_plan_sha256"],
        "candidate_image_id": image_id,
        "state": "HOLD_ORIGINAL_NATIVE_RUNTIME_ACTIVATION",
        "blockers": sorted(set(holds)),
        "original_receipts_created": False,
        "traffic_switched": False,
        "production_mutated": False,
    }


def simulate_failure(*, active_slot: str = "A",
                     candidate_slot: str = "B",
                     fail_after_phase: str | None = None) -> dict:
    """Pure planning regression; NOT a real load-balancer command or receipt."""
    phases = ("build", "shadow", "health", "drain", "switch", "finalize")
    runtime.require(active_slot != candidate_slot,
                    "SIMULATED_BLUEGREEN_SLOTS_NOT_INDEPENDENT")
    runtime.require(fail_after_phase is None or fail_after_phase in phases,
                    "SIMULATED_FAILURE_PHASE_INVALID")
    current = active_slot
    visited = []
    for phase in phases:
        visited.append(phase)
        if phase == "switch":
            current = candidate_slot
        if phase == fail_after_phase:
            current = active_slot
            return {
                "state": "SIMULATED_ROLLBACK_COMPLETE",
                "active_slot": current,
                "rolled_back_to": active_slot,
                "fault_injected_at": phase,
                "steps": visited,
                "real_traffic_changed": False,
            }
    return {
        "state": "SIMULATED_READY_FOR_INDEPENDENT_CERTIFICATION",
        "active_slot": current,
        "rolled_back_to": None,
        "steps": visited,
        "real_traffic_changed": False,
    }
