#!/usr/bin/env python3
"""Read-only candidate requests for preserving legacy Original306 plan routing.

The current installed Registry, original admission.checked_plan, and plan
store form the input. It does NOT sign associations, issue grants, alter
plans, mark Jobs claimable or write a migration snapshot into trusted stores.
A verified existing legacy provider is required; ambiguity never guesses.
"""
from __future__ import annotations

from pathlib import Path
from typing import Any
import hashlib
import json
import re

SHA = re.compile(r"^[a-f0-9]{64}$")


class MigrationHold(ValueError):
    pass


def canonical(value: Any) -> bytes:
    return json.dumps(value,sort_keys=True,ensure_ascii=False,
                      separators=(",",":")).encode()


def inventory(
    registry: Any,
    plan_store: Path,
    candidates: dict[str,dict],
    *,
    admission_module: Any,
    registry_module: Any,
) -> dict:
    """Build unsigned, exact-original-operation plan association requests.

    Every candidate source operation is discovered from its manifest; no
    company or operation name is embedded in the matching logic.
    """
    root=Path(plan_store)
    if (not root.is_absolute() or root.is_symlink() or not root.is_dir() or
        root.stat().st_uid!=0 or root.stat().st_mode & 0o022):
        raise MigrationHold("LEGACY_PLAN_STORE_NOT_ROOT_TRUSTED")
    if not isinstance(candidates,dict) or not candidates:
        raise MigrationHold("SOURCE_CANDIDATE_CATALOG_REQUIRED")
    existing=registry.manifests
    if not isinstance(existing,dict):
        raise MigrationHold("LEGACY_PROVIDER_REGISTRY_INVALID")
    colliding={
        m.get("plan_contract") for m in candidates.values()
        if isinstance(m,dict) and
        isinstance(m.get("plan_contract"),str) and
        any(e.get("plan_contract")==m["plan_contract"]
            for e in existing.values())
    }
    results=[]
    for path in sorted(root.glob("*.json")):
        if path.is_symlink() or not path.is_file() or not SHA.fullmatch(path.stem):
            raise MigrationHold("LEGACY_PLAN_PATH_INVALID")
        doc=registry_module.checked_json(path,root)
        if doc.get("plan_sha256")!=path.stem:
            raise MigrationHold("LEGACY_PLAN_ID_MISMATCH")
        contract=doc.get("contract")
        if contract not in colliding:
            continue
        eligible=[]
        for op,manifest in existing.items():
            if manifest.get("plan_contract")!=contract:
                continue
            try:
                checked=admission_module.checked_plan(root,path.stem,manifest)
                if checked.get("plan_sha256")==path.stem:
                    eligible.append(op)
            except Exception:
                # If a previously enrolled operation cannot validate a
                # matching contract, this plan requires review rather than
                # a guessed or new-provider reassignment.
                pass
        if len(eligible)!=1:
            results.append({
                "plan_sha256":path.stem,
                "state":"HOLD__EXISTING_OPERATION_ATTRIBUTION_NOT_UNIQUE",
                "eligible_existing_provider_count":len(eligible),
                "association_issued":False,
            })
            continue
        prior=existing[eligible[0]]
        results.append({
            "contract":"mad4b.original306.legacy-plan-association-request.v1",
            "state":"EXISTING_PROVIDER_UNIQUE__FIRST_PARTY_ATTESTATION_REQUIRED",
            "plan_sha256":path.stem,
            "operation_id":prior["operation_id"],
            "manifest_sha256":hashlib.sha256(canonical(prior)).hexdigest(),
            "company":doc.get("company"),
            "environment":doc.get("environment"),
            "site_uuid":doc.get("site_uuid"),
            "origin":doc.get("origin"),
            "original_305_job_verified":False,
            "original_312_claim_verified":False,
            "approval_reissued":False,
            "association_issued":False,
            "plan_modified":False,
        })
    return {
        "contract":"mad4b.original306.legacy-plan-association-preview.v1",
        "state":"SOURCE_ONLY__ATTESTATION_REQUIRED",
        "existing_plan_count_on_shared_contracts":len(results),
        "uniquely_attributable_legacy_plans":
            sum(x["state"].startswith("EXISTING_PROVIDER_UNIQUE") for x in results),
        "unresolved_legacy_plans":
            sum(x["state"].startswith("HOLD") for x in results),
        "requests":results,
        "first_party_signatures_issued":False,
        "original_305_jobs_verified":False,
        "source_provider_enrolled":False,
        "plan_store_modified":False,
    }


def summary_without_plan_identifiers(preview: dict) -> dict:
    """Safe operational summary; no plan hashes, private file paths or keys."""
    return {k:v for k,v in preview.items() if k!="requests"}
