#!/usr/bin/env python3
"""Purpose-separated CURRENT Host revision verifier (source candidate only).

This is NOT the live 318 reader or an authority issuer. 'ledger' MUST come
from a root-owned Original ledger, 'checkpoint' from a separately persisted
Central high-water mark, and 'wp' from fresh Original318 WordPress readback.
A saved signed Anchor alone does not prove it has not since been revoked.
"""
from __future__ import annotations
import base64
import hashlib
import json
import re
import time
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

LEDGER = "mad4b.original-host-current-revision.v1"
CHECKPOINT = "mad4b.original-host-current-revision-checkpoint.v1"
FIELDS = ("contract", "company", "environment", "site_uuid",
          "target_host_id", "ssh_principal", "revision", "grant_revision",
          "anchor_nonce", "state", "event_id", "previous_event_sha256",
          "issued_epoch", "expires_epoch")
C_FIELDS = ("contract", "company", "environment", "site_uuid",
            "revision", "grant_revision", "anchor_nonce", "state",
            "event_sha256", "issued_epoch", "expires_epoch")
HEX32 = re.compile(r"[0-9a-f]{32}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")

class RevisionDenied(ValueError):
    pass

def need(valid, reason):
    if not valid:
        raise RevisionDenied(reason)

def body(row, fields, domain):
    return (domain + "\n" + json.dumps(
        {f:row[f] for f in fields}, sort_keys=True,
        separators=(",",":"), ensure_ascii=False)).encode("utf-8")

def sigcheck(row, fields, contract, domain, pubkey, instant, *, historical=False):
    need(type(row) is dict and set(row) == set(fields) | {"signature"},
         "SIGNED_REVISION_ROW_SHAPE_INVALID")
    need(row["contract"] == contract, "SIGNED_REVISION_CONTRACT_WRONG")
    need(type(row["issued_epoch"]) is int
         and type(row["expires_epoch"]) is int
         and row["issued_epoch"] > 0
         and row["issued_epoch"] <= instant+10
         and row["expires_epoch"] > row["issued_epoch"],
         "SIGNED_REVISION_TIME_INVALID")
    if not historical:
        need(instant-60 <= row["issued_epoch"]
             and instant < row["expires_epoch"] <= row["issued_epoch"]+60,
             "FRESH_CURRENT_CHECKPOINT_EXPIRED_OR_REPLAYED")
    # Historic ledger events remain cryptographically verifiable after expiry;
    # only the DIFFERENT signer and fresh independent high-water witness can
    # claim they still represent the most current revision.
    need(type(row["revision"]) is int and row["revision"] > 0
         and type(row["grant_revision"]) is int and row["grant_revision"] >= 0,
         "SIGNED_REVISION_NUMBER_INVALID")
    need(row["state"] in ("ACTIVE","REVOKED")
         and type(row["anchor_nonce"]) is str
         and HEX32.fullmatch(row["anchor_nonce"]) is not None,
         "SIGNED_REVISION_STATE_INVALID")
    need(type(pubkey) is bytes and len(pubkey) == 32,"BROKER_PUBKEY_INVALID")
    try:
        signature = base64.b64decode(row["signature"], validate=True)
        need(len(signature) == 64,"SIGNED_REVISION_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(pubkey).verify(
            signature, body(row,fields,domain))
    except (InvalidSignature,ValueError,TypeError) as exc:
        raise RevisionDenied("SIGNED_REVISION_SIGNATURE_INVALID") from exc

def verify_fresh_current(job, ledger, checkpoint, wp, ledger_pubkey, checkpoint_pubkey,
                         *, reserved=False, now=None):
    """Compare 3 distinct readers; deny stale, revoked, rolled-back states.

    Caller MUST be a pinned first-party worker; fake dictionaries are not
    trusted authority. Always call again AFTER reservation before mutation.
    """
    instant=int(time.time()) if now is None else now
    need(type(job) is dict and type(wp) is dict,"CURRENT_REVISION_EVIDENCE_MISSING")
    need(type(ledger_pubkey) is bytes and type(checkpoint_pubkey) is bytes
         and ledger_pubkey != checkpoint_pubkey,
         "INDEPENDENT_CHECKPOINT_SIGNER_REQUIRED")
    sigcheck(ledger,FIELDS,LEDGER,"MAD4B_ORIGINAL_HOST_LEDGER_V1",ledger_pubkey,instant,historical=True)
    sigcheck(checkpoint,C_FIELDS,CHECKPOINT,
             "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",checkpoint_pubkey,instant)
    for f in ("company","environment","site_uuid","target_host_id","ssh_principal"):
        need(job.get(f)==ledger.get(f),"CURRENT_REVISION_JOB_SCOPE_DRIFT:"+f)
    for f in ("company","environment","site_uuid","revision","grant_revision",
              "anchor_nonce","state"):
        need(checkpoint.get(f)==ledger.get(f),"CURRENT_HIGH_WATER_MISMATCH:"+f)
    need(type(ledger.get("event_id")) is str
         and HEX32.fullmatch(ledger["event_id"]) is not None
         and type(ledger.get("previous_event_sha256")) is str
         and HEX64.fullmatch(ledger["previous_event_sha256"]) is not None,
         "ORIGINAL_REVISION_EVENT_CHAIN_INVALID")
    digest=hashlib.sha256(body(ledger,FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
    need(checkpoint["event_sha256"]==digest,
         "CURRENT_HIGH_WATER_EVENT_DIGEST_CHANGED")
    need(wp.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
         and wp.get("source")=="original318_fresh_wp_readback"
         and wp.get("readback_verified") is True
         and type(wp.get("observed_epoch")) is int
         and instant-30 <= wp["observed_epoch"] <= instant+5
         and all(wp.get(k)==ledger.get(k) for k in (
             "company","environment","site_uuid","revision","anchor_nonce","state")),
         "ORIGINAL318_CURRENT_STATE_NOT_INDEPENDENTLY_VERIFIED")
    need(job.get("anchor_revision")==ledger["revision"]
         and job.get("grant_revision")==ledger["grant_revision"],
         "STALE_ANCHOR_OR_GRANT_REVISION")
    need(ledger["state"]=="ACTIVE"
         and wp.get("current_anchor_signature_verified") is True,
         "CURRENT_AUTHORITY_REVOKED")
    if reserved:
        need(wp.get("original_reserved_job_id")==job.get("job_id")
             and wp.get("reservation_precedes_readback") is True,
             "POST_RESERVATION_REVOCATION_RECHECK_REQUIRED")
    return {"state":"CURRENT_ORIGINAL_REVISION_MATCHED__NONAUTHORIZING",
            "job_id":job.get("job_id"),
            "current_revision":ledger["revision"],
            "grant_revision":ledger["grant_revision"],
            "checked_after_reservation":bool(reserved),
            "execution_authorized":False,
            "original313_certified":False}

def verify_revoked_postcondition(job,ledger,checkpoint,wp,ledger_pubkey,checkpoint_pubkey,*,now=None):
    """Verifier for Revoke outcome; checks actual current revision is newer."""
    instant=int(time.time()) if now is None else now
    need(type(job) is dict and type(wp) is dict,
         "REVOCATION_POSTCONDITION_MISSING")
    need(type(ledger_pubkey) is bytes and type(checkpoint_pubkey) is bytes
         and ledger_pubkey != checkpoint_pubkey,
         "INDEPENDENT_CHECKPOINT_SIGNER_REQUIRED")
    sigcheck(ledger,FIELDS,LEDGER,"MAD4B_ORIGINAL_HOST_LEDGER_V1",ledger_pubkey,instant,historical=True)
    sigcheck(checkpoint,C_FIELDS,CHECKPOINT,
             "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",checkpoint_pubkey,instant)
    for f in ("company","environment","site_uuid","revision","grant_revision",
              "anchor_nonce","state"):
        need(checkpoint.get(f)==ledger.get(f),"REVOKED_HIGH_WATER_MISMATCH:"+f)
    need(checkpoint.get("event_sha256")==hashlib.sha256(
         body(ledger,FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest(),
         "REVOCATION_EVENT_DIGEST_CHANGED")
    need(all(job.get(k)==ledger.get(k) for k in (
         "company","environment","site_uuid","target_host_id","ssh_principal")),
         "REVOCATION_TENANT_DRIFT")
    need(job.get("operation_id")=="company.host-authority.anchor.revoke"
         and job.get("anchor_revision")+1==ledger["revision"]
         and ledger["state"]=="REVOKED"
         and wp.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
         and wp.get("source")=="original318_fresh_wp_readback"
         and wp.get("readback_verified") is True
         and wp.get("current_anchor_eligible") is False
         and wp.get("prior_grants_ineligible") is True
         and wp.get("revision")==ledger["revision"]
         and wp.get("state")=="REVOKED"
         and wp.get("original_reserved_job_id")==job.get("job_id")
         and type(wp.get("observed_epoch")) is int
         and instant-30 <= wp["observed_epoch"] <= instant+5,
         "REVOCATION_NOT_INDEPENDENTLY_EFFECTIVE")
    return {"state":"REVOCATION_POSTCONDITION_MATCHED__313_SIGNATURE_REQUIRED",
            "job_id":job.get("job_id"),"revision":ledger["revision"],
            "execution_authorized":False,"receipt_signed":False,
            "original313_certified":False}
