"""Read-only, site-scoped inter-release Original 313 finalization admission.

The adapter must be a first-party installed, independent Original 313
signature verifier. This module cannot create/certify a receipt by itself.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re

CONTRACT = "mad4b.company-release-prior-finalization-guard.v1"
CERTIFICATION_BRIDGE = "mad4b.original313-finalization-verified-binding.v1"
NONCE = re.compile(r"^[a-zA-Z0-9_.-]{12,120}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")


class PendingFinalization(ValueError):
    pass


def policy_contract(entry: dict) -> dict:
    settings = entry.get("finalization_policy", {})
    if (not isinstance(settings, dict) or
            set(settings) - {"require_prior_original313"} or
            type(settings.get("require_prior_original313", False)) is not bool):
        raise PendingFinalization("PRIOR_ORIGINAL313_POLICY_INVALID")
    return {"contract": CONTRACT,
            "require_prior_original313": settings.get(
                "require_prior_original313", False)}


def pending_overlap(plan: dict, receipts: Path, read_receipt) -> list[dict]:
    selected = {r["target"] for r in plan["files"]}
    pending = []
    for path in sorted(receipts.glob("*.json")):
        if path.name.endswith((".backup.json", ".intent.json",
                              ".rollback-intent.json")):
            continue
        doc, raw = read_receipt(path)
        if doc.get("contract") != "mad4b.company-code-release.receipt.v1":
            raise PendingFinalization("PRIOR_ORIGINAL313_LEDGER_CONTRACT_INVALID")
        if doc.get("site_uuid") != plan["site_uuid"] or doc.get("state") != "APPLIED_VERIFIED":
            continue
        nonce = doc.get("nonce")
        sha = doc.get("plan_sha256")
        files = doc.get("files")
        if (not isinstance(nonce, str) or NONCE.fullmatch(nonce) is None or
                path.name != nonce + ".json" or
                not isinstance(sha, str) or SHA256.fullmatch(sha) is None or
                not isinstance(files, list) or
                not all(isinstance(f, str) for f in files) or
                len(set(files)) != len(files)):
            raise PendingFinalization("PRIOR_ORIGINAL313_LEDGER_IDENTITY_DRIFT")
        if selected.isdisjoint(files):
            continue
        if doc.get("original_313_receipt") is True:
            # Host-controlled or tenant-controlled flag is NEVER authority.
            raise PendingFinalization("PRIOR_ORIGINAL313_HOST_SELF_CERTIFICATION")
        pending.append({"plan_sha256": sha, "nonce": nonce,
                        "site_uuid":doc["site_uuid"],
                        "host_receipt_sha256": hashlib.sha256(raw).hexdigest(),
                        "overlap": sorted(selected.intersection(files))})
    return pending


def admit(plan: dict, entry: dict, receipts: Path, read_receipt,
          first_party_verifier=None) -> dict:
    cfg = policy_contract(entry)
    if not cfg["require_prior_original313"]:
        return {"contract": CONTRACT, "state": "DISABLED_BY_SITE_POLICY",
                "overlap_checked": False}
    pending = pending_overlap(plan, receipts, read_receipt)
    if not pending:
        return {"contract": CONTRACT, "state": "NO_OVERLAPPING_HOST_APPLY",
                "overlap_checked": True}
    if not callable(first_party_verifier):
        raise PendingFinalization("PRIOR_ORIGINAL313_VERIFIER_NOT_ENROLLED")
    for record in pending:
        # Bridge output is accepted only from first-party adapter wired by
        # installed Original 313 Host handler. It MUST independently verify
        # Original 313 signature and identity, not echo ledger fields.
        certified = first_party_verifier(record)
        if (not isinstance(certified, dict) or
                certified.get("contract") != CERTIFICATION_BRIDGE or
                certified.get("independent_original313_signature_verified") is not True or
                certified.get("plan_sha256") != record["plan_sha256"] or
                certified.get("site_uuid") != record["site_uuid"] or
                certified.get("nonce") != record["nonce"] or
                certified.get("host_receipt_sha256") !=
                    record["host_receipt_sha256"]):
            raise PendingFinalization(
                "PRIOR_ORIGINAL313_INDEPENDENT_CERTIFICATION_MISSING")
    return {"contract": CONTRACT, "state": "PRIOR_ORIGINAL313_VERIFIED",
            "overlap_checked": True, "verified_host_applies": len(pending)}
