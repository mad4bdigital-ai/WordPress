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
CANDIDATE318 = ROOT/"deploy/host-authority/v82/318-mad4b-native-host-exact-grant.php"
EXPECTED318 = "56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be"
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
    # Receipt certification must be populated only by original independently
    # signed evidence readback, never inferred from this observer.
    pending=[k for k,v in checks.items() if not v]
    return {
        "contract":"mad4b.unified-host-v2.readonly-release-admission.v1",
        "observed_utc":datetime.now(timezone.utc).isoformat(),
        "source_candidate_sha256":vetted,
        "original318_live_sha256":current,
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
