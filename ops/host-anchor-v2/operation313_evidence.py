#!/usr/bin/env python3
"""Operation-aware Original 313 Host v2 signed evidence precondition.

The independent Original 313 signing key MUST NOT be Broker's authority key.
Pure verifier: does not sign receipts or accept handler success as readback.
Host Issue/Revoke cannot reuse Code Release's expected_readback_sha256 check.
"""
from __future__ import annotations
import base64
import json
import re
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

ISSUE="company.host-authority.anchor.issue"
REVOKE="company.host-authority.anchor.revoke"
CONTRACT="mad4b.original313.host-anchor-operation-proof.v1"
FIELDS=(
    "contract","operation_id","job_id","company","environment","site_uuid",
    "origin","target_host_id","ssh_principal","command_sha256","plan_sha256",
    "grant_revision","anchor_revision_before","anchor_revision_after",
    "anchor_nonce","reserved_job_id","result_state","original318_snapshot_sha256",
    "revision_event_sha256","issued_epoch","expires_epoch",
)
EXPECTED={ISSUE:"ANCHOR_ACTIVE_INDEPENDENTLY_READ",
          REVOKE:"ANCHOR_REVOKED_AND_PRIOR_GRANTS_INELIGIBLE"}
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")

class EvidenceDenied(ValueError): pass

def need(ok, reason):
    if not ok:raise EvidenceDenied(reason)

def canonical(d):
    return json.dumps(d,sort_keys=True,separators=(",",":"),
                      ensure_ascii=False).encode()

def verify_independent_operation_proof(proof, independent313_pubkey,
                                       job, original312, readback, *, now=None):
    instant=int(time.time()) if now is None else now
    need(type(proof) is dict and set(proof)==set(FIELDS)|{"signature"},
         "HOST313_EXACT_PROOF_SHAPE_REQUIRED")
    need(type(job) is dict and type(original312) is dict and type(readback) is dict,
         "HOST313_ORIGINAL_READBACK_REQUIRED")
    op=proof["operation_id"]
    need(proof["contract"]==CONTRACT and op in EXPECTED
         and proof["result_state"]==EXPECTED[op],
         "HOST313_OPERATION_OR_RESULT_INVALID")
    for key in ("job_id","reserved_job_id","anchor_nonce"):
        need(type(proof[key]) is str and HEX32.fullmatch(proof[key]) is not None,
             "HOST313_ID_INVALID:"+key)
    for key in ("command_sha256","plan_sha256",
                "original318_snapshot_sha256","revision_event_sha256"):
        need(type(proof[key]) is str and HEX64.fullmatch(proof[key]) is not None,
             "HOST313_DIGEST_INVALID:"+key)
    for key in ("grant_revision","anchor_revision_before","anchor_revision_after"):
        need(type(proof[key]) is int and proof[key]>=0,
             "HOST313_REVISION_INVALID:"+key)
    need(proof["anchor_revision_after"]==proof["anchor_revision_before"]+1,
         "HOST313_NONMONOTONIC_ANCHOR_REVISION")
    need(proof["grant_revision"]>0 or
         (op==ISSUE and proof["anchor_revision_before"]==0),
         "HOST313_BOOTSTRAP_SCOPE_INVALID")
    need(type(proof["issued_epoch"]) is int
         and type(proof["expires_epoch"]) is int
         and instant-120<=proof["issued_epoch"]<=instant+10
         and instant<proof["expires_epoch"]<=proof["issued_epoch"]+120,
         "HOST313_EVIDENCE_EXPIRED")
    need(type(independent313_pubkey) is bytes and len(independent313_pubkey)==32,
         "HOST313_INDEPENDENT_KEY_INVALID")
    try:
        signature=base64.b64decode(proof["signature"],validate=True)
        need(len(signature)==64,"HOST313_SIGNATURE_LENGTH_INVALID")
        Ed25519PublicKey.from_public_bytes(independent313_pubkey).verify(
            signature,canonical({k:proof[k] for k in FIELDS}))
    except (InvalidSignature,ValueError,TypeError) as exc:
        raise EvidenceDenied("HOST313_INDEPENDENT_SIGNATURE_INVALID") from exc
    for key in ("operation_id","job_id","company","environment","site_uuid",
                "origin","target_host_id","ssh_principal","command_sha256",
                "plan_sha256"):
        need(proof.get(key)==job.get(key),"HOST313_ORIGINAL_JOB_CHANGED:"+key)
    need(job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type")=="signed_host_principal_v2"
         and job.get("executed") is False,
         "HOST313_NOT_AN_ORIGINAL_V2_JOB")
    need(proof["reserved_job_id"]==proof["job_id"]
         and original312.get("job_id")==proof["job_id"]
         and original312.get("command_sha256")==proof["command_sha256"]
         and original312.get("signature_verified") is True,
         "HOST313_ORIGINAL312_NOT_VERIFIED")
    if proof["anchor_revision_before"]==0:
        need(op==ISSUE and proof["grant_revision"]==0
             and original312.get("contract")==
                 "mad4b.original312.host-principal.bootstrap-issue.v1"
             and original312.get("bootstrap_signature_verified") is True,
             "HOST313_FIRST_ANCHOR_PURPOSE_INVALID")
    else:
        need(original312.get("contract")==
                 "mad4b.original312.host-principal.exact-job.v2"
             and original312.get("independent_authority_readback_matched") is True
             and original312.get("grant_revision")==proof["grant_revision"],
             "HOST313_EXECUTION_GRANT_REQUIRED")
    need(readback.get("source")=="independent_original318_and_central_revision_ledger"
         and readback.get("fresh_wp_readback") is True
         and readback.get("fresh_central_highwater_verified") is True
         and readback.get("original_reserved_job_id")==proof["job_id"]
         and readback.get("original318_snapshot_sha256")==
              proof["original318_snapshot_sha256"]
         and readback.get("revision_event_sha256")==proof["revision_event_sha256"]
         and readback.get("anchor_revision")==proof["anchor_revision_after"]
         and readback.get("anchor_nonce")==proof["anchor_nonce"]
         and readback.get("company")==proof["company"]
         and readback.get("environment")==proof["environment"]
         and readback.get("site_uuid")==proof["site_uuid"],
         "HOST313_OPERATION_INDEPENDENT_READBACK_NOT_CURRENT")
    if op==ISSUE:
        need(readback.get("anchor_signature_verified") is True
             and readback.get("anchor_current") is True
             and readback.get("anchor_revoked") is False,
             "HOST313_ANCHOR_ISSUE_NOT_EFFECTIVE")
    else:
        need(readback.get("anchor_current") is False
             and readback.get("anchor_revoked") is True
             and readback.get("prior_grants_ineligible") is True,
             "HOST313_ANCHOR_REVOKE_NOT_EFFECTIVE")
    return {"contract":CONTRACT,
            "state":"SIGNED_INDEPENDENT_HOST_OPERATION_PROOF_VERIFIED",
            "job_id":proof["job_id"],"operation_id":op,
            "anchor_revision":proof["anchor_revision_after"],
            "signed_independent_evidence_verified":True,
            "original313_certified":False,
            "central_receipt_created":False,
            "execution_authorized":False}
