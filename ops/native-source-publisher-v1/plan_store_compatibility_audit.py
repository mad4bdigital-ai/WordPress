#!/usr/bin/env python3
"""Read-only compatibility audit for dynamic provider enrollment.

Reveals existing plans that may become ambiguous or fail closed if a new
source-family provider shares their contract. Never edits plans, claims Jobs,
issues an approval, or enrolls keys.
"""
from __future__ import annotations

from collections import Counter
import json
from pathlib import Path
import re
import stat
from typing import Any

PLAN_ROOT = Path("/var/lib/mad4b/dynamic-operation-registry/plans")
MAX_PLANS = 256
HEX64 = re.compile(r"^[a-f0-9]{64}$")


class CompatibilityDenied(ValueError):
    pass


def store_is_trusted(root: Path) -> None:
    root = Path(root)
    if (not root.is_absolute() or root.is_symlink() or not root.is_dir() or
        root.stat().st_uid != 0 or stat.S_IMODE(root.stat().st_mode) & 0o022):
        raise CompatibilityDenied("PLAN_STORE_NOT_FIRST_PARTY_TRUSTED")


def summarize(
    registry: Any,
    existing_plan_root: Path,
    candidate_manifests: dict[str, dict],
) -> dict:
    """Static risk analysis, not a declaration of successful migration.

    Registry is expected to be a first-party verified original Registry.
    Candidate manifests are hypothetical changes not independently enrolled.
    A collision means review is mandatory, not proof of failed execution.
    """
    store = Path(existing_plan_root)
    store_is_trusted(store)
    if not isinstance(candidate_manifests, dict) or not candidate_manifests:
        raise CompatibilityDenied("CANDIDATE_MANIFESTS_REQUIRED")
    registered = registry.manifests
    if not isinstance(registered, dict):
        raise CompatibilityDenied("INSTALLED_REGISTRY_INVALID")
    existing_contracts: dict[str, set[str]] = {}
    for op, manifest in registered.items():
        name = manifest.get("plan_contract")
        if not isinstance(name, str) or not name:
            raise CompatibilityDenied("EXISTING_PROVIDER_PLAN_CONTRACT_INVALID")
        existing_contracts.setdefault(name, set()).add(op)
    candidate_contracts: dict[str, set[str]] = {}
    for op, manifest in candidate_manifests.items():
        if op in registered:
            raise CompatibilityDenied("CANDIDATE_ALREADY_ENROLLED_REVIEW_UPGRADE")
        if not isinstance(manifest, dict) or manifest.get("operation_id") != op:
            raise CompatibilityDenied("CANDIDATE_OPERATION_MANIFEST_DRIFT")
        contract = manifest.get("plan_contract")
        if not isinstance(contract, str) or not contract:
            raise CompatibilityDenied("CANDIDATE_PLAN_CONTRACT_MISSING")
        candidate_contracts.setdefault(contract, set()).add(op)

    paths = sorted(store.glob("*.json"))
    if len(paths) > MAX_PLANS:
        raise CompatibilityDenied("PLAN_STORE_COUNT_EXCEEDS_VERIFIED_CAP")
    counter: Counter[str] = Counter()
    ambiguous: Counter[str] = Counter()
    previously_unique: Counter[str] = Counter()
    previously_ambiguous: Counter[str] = Counter()
    unregistered: Counter[str] = Counter()
    for path in paths:
        if (path.is_symlink() or not path.is_file() or
            HEX64.fullmatch(path.stem) is None):
            raise CompatibilityDenied("UNTRUSTED_PLAN_FILENAME_OR_LINK")
        st=path.stat()
        if (st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022 or
            st.st_size < 5 or st.st_size > 250_000):
            raise CompatibilityDenied("UNTRUSTED_PLAN_CONTENT_OR_PERMISSIONS")
        doc=json.loads(path.read_bytes())
        if not isinstance(doc, dict):
            raise CompatibilityDenied("PLAN_DOC_NOT_OBJECT")
        if doc.get("plan_sha256") != path.stem:
            raise CompatibilityDenied("PLAN_ID_FILENAME_MISMATCH")
        contract=doc.get("contract")
        if not isinstance(contract,str) or not contract:
            raise CompatibilityDenied("PLAN_CONTRACT_MISSING")
        counter[contract] += 1
        if contract in candidate_contracts:
            if contract in existing_contracts:
                ambiguous[contract] += 1
                if len(existing_contracts[contract]) == 1:
                    previously_unique[contract] += 1
                else:
                    previously_ambiguous[contract] += 1
            else:
                unregistered[contract] += 1

    return {
        "contract": "mad4b.native-source-plan-store-compatibility-audit.v1",
        "state": "HOLD__PENDING_LEGACY_CONFLICT_RECONCILIATION"
                 if sum(ambiguous.values()) else
                 "NO_SHARED_CONTRACT_PLAN_CONFLICT_OBSERVED",
        "plan_count": len(paths),
        "plan_contract_counts": dict(sorted(counter.items())),
        "ambiguous_existing_plan_count": sum(ambiguous.values()),
        "legacy_plans_with_one_prior_provider": sum(previously_unique.values()),
        "legacy_plans_with_multiple_prior_providers": sum(previously_ambiguous.values()),
        "migration_signed_association_required": sum(ambiguous.values()) > 0,
        "existing_plan_counts_on_colliding_contracts":
            dict(sorted(ambiguous.items())),
        "candidate_only_existing_plan_count": sum(unregistered.values()),
        "affected_existing_provider_ids": sorted({
            op for contract in ambiguous for op in existing_contracts[contract]
        }),
        "candidate_colliding_provider_ids": sorted({
            op for contract in ambiguous for op in candidate_contracts[contract]
        }),
        "signatures_or_existing_claims_verified": False,
        "legacy_approval_or_claim_issued": False,
        "plan_store_modified": False,
        "provider_enrolled": False,
        "live_deployment_performed": False,
    }
