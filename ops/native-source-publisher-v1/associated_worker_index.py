#!/usr/bin/env python3
"""Non-executing Original306 operation index candidate with signed associations.

Not installed in Original306. This pure adapter preserves the installed
read-only plan validator and original command digest format. It does NOT
execute, claim or reserve any Job, issue Grants or sign Original313 receipts.

When a registered source-family provider shares a plan contract with others,
the entire matching contract is restricted to ONE signed plan association.
Neither an unsigned Job.operation nor the presence of additional providers
can cause a second command mapping. A missing, expired or revoked association
fails the index rather than silently falling back to a legacy operation.
"""
from __future__ import annotations

from pathlib import Path
import re
from typing import Any

import operation_plan_association as binding

SHA = re.compile(r"^[a-f0-9]{64}$")


class SourceIndexDenied(ValueError):
    pass


def indexed_plans_with_association(
    registry: Any,
    plan_root: Path,
    association_root: Path,
    trusted_association_key_b64: str,
    *,
    admission_module: Any,
    registry_module: Any,
    now: int | None = None,
) -> dict[str, dict]:
    """Resolve exact immutable CAS plans to a single permitted operation.

    The calling first-party worker must have already loaded and verified:
      * root-owned Registry with immutable SHA-pinned manifests
      * independent association issuer public key from trusted root policy
      * root-owned read-only plan and association directories
    This function never reads any operation selector from pending Job metadata.
    """
    store = Path(plan_root)
    registry_module.require(
        store.is_absolute() and store.is_dir() and not store.is_symlink()
        and store.stat().st_uid == 0 and not store.stat().st_mode & 0o022,
        "FIRST_PARTY_PLAN_STORE_NOT_TRUSTED",
    )
    paths = sorted(store.glob("*.json"))
    registry_module.require(
        len(paths) <= 256, "DYNAMIC_PLAN_REGISTRY_LIMIT_EXCEEDED"
    )
    result: dict[str, dict] = {}
    for path in paths:
        plan_sha256 = path.stem
        registry_module.require(
            SHA.fullmatch(plan_sha256) is not None,
            "DYNAMIC_PLAN_FILENAME_INVALID",
        )
        original = registry_module.checked_json(path, store)
        matches = {
            op: manifest for op, manifest in registry.manifests.items()
            if original.get("contract") == manifest.get("plan_contract")
        }
        if not matches:
            continue

        contains_source = any(
            manifest.get("source_family") is not None
            for manifest in matches.values()
        )
        if contains_source:
            # Any source-family provider matching this plan contract forces
            # an independent signed association, even when the selected
            # operation is an existing legacy provider. No unsigned fallback.
            try:
                associated = binding.exclusive_operation(
                    original, registry, association_root,
                    trusted_association_key_b64, now=now,
                )
            except binding.AssociationDenied as exc:
                # Expired or absent association denies this one plan, never
                # falling back to an unapproved matching provider. Tampering,
                # scope drift and trust failure remain global blockers.
                if str(exc) in (
                    "ASSOCIATION_FILE_NOT_ENROLLED", "ASSOCIATION_EXPIRED"
                ):
                    continue
                raise SourceIndexDenied(
                    "SIGNED_SOURCE_OPERATION_ASSOCIATION_REQUIRED"
                ) from exc
            operation = associated["operation_id"]
            if operation not in matches:
                raise SourceIndexDenied(
                    "ASSOCIATION_NOT_BOUND_TO_REGISTERED_PLAN_CONTRACT"
                )
            selected = [(operation, matches[operation])]
        else:
            # Other existing operations retain current 306 plan indexing,
            # but are not a fallback for a plan matched by source-family ops.
            selected = sorted(matches.items())

        for operation, manifest in selected:
            verified = admission_module.checked_plan(
                store, plan_sha256, manifest
            )
            digest = registry.command_sha256(
                operation, verified["plan_sha256"]
            )
            registry_module.require(
                digest not in result,
                "DYNAMIC_COMMAND_DIGEST_COLLISION",
            )
            result[digest] = {
                "operation": operation,
                "plan": verified,
                "signed_association_verified": contains_source,
                # The original 306 + fresh 312 checks are still outstanding.
                "job_claimed": False,
                "authority_issued": False,
                "original_313_certified": False,
            }
    return result


def select_operation_for_digest(
    indexed: dict[str, dict],
    command_digest: str,
) -> dict:
    """Pure selection only. The first-party worker still performs 306+312."""
    if not isinstance(command_digest, str) or not SHA.fullmatch(command_digest):
        raise SourceIndexDenied("DYNAMIC_JOB_COMMAND_MISSING")
    if command_digest not in indexed:
        raise SourceIndexDenied(
            "COMMAND_NOT_REGISTERED_OR_ASSOCIATED_WITH_SIGNED_PLAN"
        )
    selected = indexed[command_digest]
    return {
        "operation": selected["operation"],
        "plan": selected["plan"],
        "original_306_claim_verified": False,
        "original_312_verified": False,
        "original_313_certified": False,
    }
