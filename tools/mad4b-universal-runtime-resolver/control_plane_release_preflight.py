"""Review-only release preflight for MAD4B central execution.

Preflight records why a specific site/package is blocked; it cannot issue a
grant, run a shell command, change files, or approve its own evidence. The
Native Operator must independently verify original grants, source evidence,
release scope and readback at operation time.
"""
from __future__ import annotations

import re
from urllib.parse import urlsplit

CONTRACT = "mad4b.control-plane-release-preflight.v1"
CAPABILITY = "control_plane.release.apply"
HEX64 = re.compile(r"^[0-9a-f]{64}$")
HEX40 = re.compile(r"^[0-9a-f]{40}$")


def is_digest(value):
    return isinstance(value, str) and HEX64.fullmatch(value) is not None


def _origin(value):
    if not isinstance(value, str) or not value.startswith("https://"):
        return False
    parsed = urlsplit(value)
    return (parsed.scheme == "https" and bool(parsed.hostname)
            and parsed.username is None and parsed.password is None
            and not parsed.path.strip("/") and not parsed.query and not parsed.fragment)


def preflight(plan, site, source, grant, executor):
    """Assess submitted evidence, never an authority decision."""
    blockers = []
    if not isinstance(plan, dict) or not isinstance(site, dict):
        return {"contract": CONTRACT, "state": "REVIEW_REQUIRED",
                "blockers": ["invalid_input_contract"], "can_submit_to_native_operator": False,
                "execution_performed": False, "production_mutation": False}
    target = plan.get("target_site")
    uuid = plan.get("site_uuid")
    if (plan.get("environment") != "staging"
            or site.get("environment") != "staging"
            or not isinstance(uuid, str) or len(uuid) != 36
            or site.get("site_uuid") != uuid
            or site.get("canonical_origin") != target
            or not _origin(target)
            or site.get("origin_match") is not True
            or site.get("deployment_binding_match") is not True):
        blockers.append("exact_staging_site_identity_mismatch")
    if (plan.get("production_mutation") is not False
            or plan.get("authorization") != "NOT_GRANTED_BY_THIS_PLAN"):
        blockers.append("plan_is_not_non_authorizing_staging")
    if not all(is_digest(plan.get(x)) for x in (
            "candidate_manifest_sha256", "candidate_archive_sha256", "manifest_digest")):
        blockers.append("candidate_digest_invalid")
    if not isinstance(plan.get("source_commit_sha"), str) or not HEX40.fullmatch(plan["source_commit_sha"]):
        blockers.append("candidate_source_sha_invalid")
    if not isinstance(source, dict) or (
            source.get("source_commit_sha") != plan.get("source_commit_sha")
            or source.get("archive_sha256") != plan.get("candidate_archive_sha256")
            or source.get("manifest_digest") != plan.get("manifest_digest")
            or source.get("independent_repository_verified") is not True
            or source.get("git_source_registered") is not True
            or source.get("local_snapshot_only") is not False):
        blockers.append("source_provenance_not_independently_verified")
    if not isinstance(grant, dict) or (
            grant.get("capability") != CAPABILITY
            or grant.get("site_uuid") != uuid
            or grant.get("origin") != target
            or grant.get("environment") != "staging"
            or grant.get("archive_sha256") != plan.get("candidate_archive_sha256")
            or grant.get("original_native_policy_verified") is not True
            or grant.get("revoked") is not False
            or grant.get("signature_verified") is not True
            or grant.get("bound_to_current_operation") is not True):
        blockers.append("exact_native_release_grant_not_verified")
    if not isinstance(executor, dict) or (
            executor.get("site_uuid") != uuid
            or executor.get("origin") != target
            or executor.get("registered") is not True
            or executor.get("native_original_operator") is not True
            or executor.get("readback_supported") is not True
            or executor.get("rollback_supported") is not True
            or executor.get("supports_exact_release_apply") is not True):
        blockers.append("native_release_executor_not_enrolled")
    return {
        "contract": CONTRACT,
        "state": "READY_FOR_INDEPENDENT_NATIVE_ADMISSION" if not blockers else "BLOCKED",
        "blockers": blockers,
        "can_submit_to_native_operator": not blockers,
        "native_operation_authorized": False,
        "execution_performed": False,
        "production_mutation": False,
        "site_uuid": uuid,
        "artifact_sha256": plan.get("candidate_archive_sha256"),
    }


def verify_external_readback(plan, receipt):
    """Accept only verified actual-execution evidence, never planned success."""
    if not isinstance(plan, dict) or not isinstance(receipt, dict):
        return False
    return bool(
        receipt.get("state") == "VERIFIED"
        and receipt.get("execution_performed") is True
        and receipt.get("mutation_performed") is True
        and receipt.get("rollback_available") is True
        and receipt.get("site_uuid") == plan.get("site_uuid")
        and receipt.get("origin") == plan.get("target_site")
        and receipt.get("environment") == "staging"
        and receipt.get("operation") == CAPABILITY
        and receipt.get("archive_sha256") == plan.get("candidate_archive_sha256")
        and receipt.get("manifest_digest") == plan.get("manifest_digest")
        and receipt.get("runtime_manifest_match") is True
        and receipt.get("stale") is False
        and receipt.get("independent_readback") is True
        and is_digest(receipt.get("readback_sha256"))
        and receipt.get("production_mutation") is False
    )
