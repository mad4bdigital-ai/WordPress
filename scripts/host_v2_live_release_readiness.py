#!/usr/bin/env python3
"""Read-only release admission diagnostics for Original Host Authority v2.

No mutation, grant, provider registration, signer access or publisher. A passing
source hash or existence of a Python file can never certify a live operation.
"""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
LIVE318 = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/"
 "wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php")
REGISTRY = Path("/opt/mad4b-company-transport/native-313-independent/"
 "dynamic-operation-registry-v1/enrolled")
CANDIDATE318 = ROOT/"ops/original318-signed-anchor-candidate-v82/318-mad4b-native-host-exact-grant.php"
EXPECTED318 = "56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be"
# Reviewed v83 successor adds signed current/revoked Original318 snapshots.
# Hash pin is code provenance, NEVER an issuer, signed Job or Original313 receipt.
EXPECTED318_V83 = "1c80391b15c750238339cf43c248dfd6c4017ec80102ddc01e210b9e8ec26b0b"

ISSUE = "company.host-authority.anchor.issue"
REVOKE = "company.host-authority.anchor.revoke"

def digest(path:Path)->str|None:
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 2_000_000:
            return None
        return hashlib.sha256(path.read_bytes()).hexdigest()
    except (OSError,ValueError):
        return None

def inspect(live318:Path=LIVE318,registry:Path=REGISTRY,
            candidate:Path=CANDIDATE318)->dict:
    checks={}
    current=digest(live318)
    vetted=digest(candidate)
    checks["original318_candidate_exact"] = vetted == EXPECTED318
    checks["original318_live_v82"] = current == EXPECTED318
    checks["original318_live_reviewed"] = current in (EXPECTED318, EXPECTED318_V83)
    reviewed_successor = current == EXPECTED318_V83

    try:
        candidate_text=candidate.read_text()
        checks["source_revocation_observer_present"]=(
            "public static function independent_revoked_anchor_observation_v2(" in candidate_text
            and "independent_highwater_verified" in candidate_text
        )
    except (OSError,UnicodeDecodeError):
        checks["source_revocation_observer_present"]=False
    try:
        index_file=registry/"index.json"
        index=json.loads(index_file.read_text())
        if index.get("contract")!="mad4b.dynamic-operation-index.v1":
            raise ValueError("wrong registry contract")
        providers={}
        for row in index.get("providers",[]):
            path=row.get("manifest"); pinned=row.get("sha256")
            if not isinstance(path,str) or not path.startswith(
                "dynamic-operation-registry-v1/providers/") or ".." in Path(path).parts:
                raise ValueError("manifest path untrusted")
            manifest_file=registry/path
            if digest(manifest_file)!=pinned:
                raise ValueError("manifest source digest drift")
            manifest=json.loads(manifest_file.read_text())
            op=manifest.get("operation_id")
            if op in providers:raise ValueError("duplicate registry operation")
            providers[op]=manifest
        checks["installed_registry_manifest_digest_verified"]=True
        checks["host_issue_provider_registered"]=ISSUE in providers
        checks["host_revoke_provider_registered"]=REVOKE in providers
    except (OSError,ValueError,TypeError,KeyError):
        providers={}
        checks["installed_registry_manifest_digest_verified"]=False
        checks["host_issue_provider_registered"]=False
        checks["host_revoke_provider_registered"]=False
    worker_text=""
    evidence_text=""
    try:
        worker_text=(registry/"worker_adapter.py").read_text()
        evidence_text=(registry/"evidence.py").read_text()
    except (OSError,UnicodeDecodeError):
        pass
    # Presence of a source token is diagnostic only, never a live signed grant.
    checks["installed_worker_has_explicit_v2_admission"] = (
        "signed_remote_hostinger_wpcli_v2" in worker_text
        and "resolve_remote_host_v2_claim" in worker_text
    )
    checks["installed_evidence_has_host_v2_operation_contract"] = (
        "signed_remote_hostinger_wpcli_v2" in evidence_text
        and "company.host-authority.anchor.revoke" in evidence_text
    )
    checks["independent_signer_and_real_receipt_certified"] = False
    # Static file presence alone cannot attest to an enrolled current reader.
    try:
        checks["v83_signed_current_readback_code_present"] = (
            reviewed_successor
            and "public static function current_anchor_readback_v2(" in live318.read_text()
            and "signed_anchor_active_in_option" in live318.read_text()
            and "fresh_central_highwater_verified" in live318.read_text()
        )
    except (OSError, UnicodeDecodeError):
        checks["v83_signed_current_readback_code_present"] = False
    # Receipt certification must be populated only by original independently
    # signed evidence readback, never inferred from this observer.
    # A reviewed v83 successor supersedes the historical v82 byte predicate;
    # both still need real, enrolled Original306/312/313 issue/revoke proof.
    informational={"original318_live_v82", "v83_signed_current_readback_code_present"}
    pending=[k for k,v in checks.items()
             if not v and (k not in informational or
                 (k=="original318_live_v82" and not checks["original318_live_reviewed"]))]
    return {
        "contract":"mad4b.unified-host-v2.readonly-release-admission.v1",
        "observed_utc":datetime.now(timezone.utc).isoformat(),
        "source_candidate_sha256":vetted,
        "original318_live_sha256":current,
        "original318_live_reviewed_version":(
            "v83" if reviewed_successor else
            ("v82" if current == EXPECTED318 else "unrecognized")),
        "checks":checks,
        "pending":pending,
        "state":"BLOCKED" if pending else "SOURCE_PREFLIGHT_ONLY_NOT_A_RELEASE",
        "checks_are_non_authorizing":True,
        "release_applied":False,"grant_created":False,
        "anchor_issued":False,"anchor_revoked":False,
        "original313_receipt_signed":False,
        "production_mutated":False,
    }

if __name__=="__main__":
    print(json.dumps(inspect(),sort_keys=True,indent=2))
