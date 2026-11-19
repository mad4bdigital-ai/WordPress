#!/usr/bin/env python3
"""Independent Original313 prewrite reservation for first ACTIVE Anchor revoke.

Does not sign anything, does not write WordPress and does not revoke.
Requires original306 claimed revoke Job, active signed Broker ledger,
independent signed current checkpoint, fresh Original318 signed readback,
Broker signed revoke bundle, independently signed exact prewrite admission.
Uses the existing v99 Root transaction store; no fake grant_revision=1.
"""
from __future__ import annotations
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
import time
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
V99=ROOT/"host-authority-v99/original313_postwrite_transaction.py"
V99_SHA="1b42065db70e224a889c969d28d9b8119e1dceba8003d43e05dc0b0111f0c00d"
V101=ROOT/"host-authority-v101/original312_first_revoke_bundle.py"
V101_SHA="d45aec46d57d92f0a25061c29849403d017ddfcb2bc23bf4dfe98c5853b42f1a"
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
GATE=ROOT/"host-authority-v83/v81/current_revision_gate.py"
GATE_SHA="8d45990df6a1cdc75c2f3399f4490eea2c775466cc5de104f73c97d6903b2dd6"

class RevokePrewriteHeld(ValueError):pass

def need(v,why):
    if not v:raise RevokePrewriteHeld(why)

def load(path,sha,name):
    need(path.is_file() and not path.is_symlink()
         and hashlib.sha256(path.read_bytes()).hexdigest()==sha,
         "ORIGINAL313_REVOKE_SOURCE_PIN_CHANGED")
    sp=importlib.util.spec_from_file_location(name,path)
    need(sp is not None and sp.loader is not None,
         "ORIGINAL313_REVOKE_SOURCE_IMPORT_INVALID")
    result=importlib.util.module_from_spec(sp);sp.loader.exec_module(result)
    return result

def reserve_revoke(job,plan,claim,broker_row,ledger,checkpoint,wp,bundle,
                   approval,broker_pub,ind_pub,*,store=None,now=None):
    """No signing, no execution, only a signed exact immutable prewrite intent.

    All input documents must be read from first-party original306 / root
    ledger / WordPress by a commissioned Root worker. No user-supplied path.
    """
    instant=int(time.time()) if now is None else now
    need(all(type(x) is dict for x in
             (job,plan,claim,broker_row,ledger,checkpoint,wp,bundle,approval)),
         "ORIGINAL313_REVOKE_ALL_ORIGINALS_REQUIRED")
    need(type(broker_pub) is bytes and len(broker_pub)==32
         and type(ind_pub) is bytes and len(ind_pub)==32
         and broker_pub!=ind_pub,"ORIGINAL313_REVOKE_INDEPENDENT_SIGNERS_REQUIRED")
    v99=load(V99,V99_SHA,"_original313_v99_revoke_store")
    v101=load(V101,V101_SHA,"_original313_v101_revoke_plan")
    gate=load(GATE,GATE_SHA,"_original313_v101_revoke_ledger")
    store=v99.PrivateStore() if store is None else store
    v99.require_enrolled_live_signers(store,broker_pub,ind_pub)
    need(set(claim)=={"contract","job_id","host_id","nonce","claimed_epoch",
                      "state","production_mutation_performed"}
         and claim["contract"]=="mad4b.central-vps-broker.v1"
         and claim["job_id"]==job.get("job_id")
         and claim["host_id"]==job.get("broker_host_id")=="srv2030908"
         and claim["state"]==job.get("state")
         and type(claim["claimed_epoch"]) is int
         and instant-300<=claim["claimed_epoch"]<=instant+5
         and claim["claimed_epoch"]==job.get("claimed_epoch")
         and claim["production_mutation_performed"] is False
         and type(claim["nonce"]) is str
         and re.fullmatch(r"[a-f0-9]{32}",claim["nonce"]) is not None
         and broker_row.get("job_id")==job.get("job_id")
         and broker_row.get("broker_host_id")=="srv2030908"
         and broker_row.get("target_id")==job.get("target_host_id")
         and broker_row.get("command_sha256")==job.get("command_sha256")
         and type(job.get("command_sha256")) is str
         and re.fullmatch(r"[a-f0-9]{64}",job["command_sha256"]) is not None
         and job.get("target_scope")=="host"
         and job.get("command_format")=="host.anchor.ref.v2",
         "ORIGINAL313_REVOKE_EXACT_CLAIM_AND_BROKER_ROW_REQUIRED")
    verified=v101.verify_first_revoke(
        plan,job,ledger,checkpoint,wp,broker_pub,ind_pub,now=instant)
    need(verified.get("current_signed_highwater_verified") is True
         and verified.get("execution_authorized") is False,
         "ORIGINAL313_REVOKE_CURRENT_FIRST_ANCHOR_REQUIRED")
    allowed={"contract","job_id","operation_id","anchor","revision_event",
             "anchor_message_sha256","previous_event_sha256",
             "original312_signatures_verified","original318_write_performed",
             "original313_prewrite_approved","original313_final_receipt_signed",
             "anchor_revoked","execution_authorized"}
    need(set(bundle)==allowed
         and bundle["contract"]=="mad4b.original312.first-revoke-documents.v1"
         and bundle["job_id"]==job["job_id"]
         and bundle["operation_id"]==v101.REVOKE
         and bundle["previous_event_sha256"]==
             verified["previous_event_sha256"]
         and bundle["original312_signatures_verified"] is True
         and bundle["original318_write_performed"] is False
         and bundle["original313_prewrite_approved"] is False
         and bundle["original313_final_receipt_signed"] is False
         and bundle["anchor_revoked"] is False
         and bundle["execution_authorized"] is False,
         "ORIGINAL313_REVOKE_SIGNED_DOCUMENT_ENVELOPE_INVALID")
    anchor=bundle["anchor"];event=bundle["revision_event"]
    need(type(anchor) is dict and type(event) is dict
         and list(anchor)==list(v101.ANCHOR_FIELDS)+["signature"],
         "ORIGINAL313_REVOKE_EXACT_SIGNED_ANCHOR_REQUIRED")
    try:
        sig=base64.b64decode(anchor["signature"],validate=True)
        need(len(sig)==64,"ORIGINAL313_REVOKE_ANCHOR_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(broker_pub).verify(
            sig,v101.anchor_message(anchor))
    except (InvalidSignature,TypeError,ValueError) as ex:
        raise RevokePrewriteHeld("ORIGINAL313_REVOKE_ANCHOR_SIGNATURE_INVALID") from ex
    gate.sigcheck(event,gate.FIELDS,gate.LEDGER,
                 "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,instant)
    digest=hashlib.sha256(v101.anchor_message(anchor)).hexdigest()
    need(bundle["anchor_message_sha256"]==digest
         and anchor["contract"]=="mad4b.company-host-authority-anchor.v2"
         and anchor["company"]==job["company"]
         and anchor["environment"]==job["environment"]
         and anchor["site_uuid"]==job["site_uuid"]
         and anchor["canonical_origin"]==job["origin"]
         and anchor["revision"]==2
         and anchor["nonce"]!=ledger["anchor_nonce"]
         and anchor["state"]=="REVOKED_INDEPENDENT_HOST_AUTHORITY"
         and anchor["revoked"] is True
         and type(anchor["issued"]) is int
         and instant-60<=anchor["issued"]<=instant+10
         and type(anchor["expires"]) is int
         and instant+30<=anchor["expires"]<=anchor["issued"]+3600
         and event["company"]==job["company"]
         and event["environment"]==job["environment"]
         and event["site_uuid"]==job["site_uuid"]
         and event["event_id"]==job["job_id"]
         and event["state"]=="REVOKED"
         and event["revision"]==2
         and event["grant_revision"]==0
         and event["anchor_nonce"]==anchor["nonce"]
         and event["previous_event_sha256"]==verified["previous_event_sha256"],
         "ORIGINAL313_REVOKE_SIGNED_ANCHOR_EVENT_MISMATCH")
    need(set(approval)==set(v99.PREWRITE_FIELDS)|{"signature"}
         and approval["contract"]==v99.PREWRITE_CONTRACT
         and approval["job_id"]==job["job_id"]
         and approval["operation_id"]==v101.REVOKE
         and all(approval.get(x)==job.get(x) for x in (
             "company","environment","site_uuid","plan_sha256",
             "command_sha256","grant_id"))
         and approval["anchor_revision_before"]==1
         and approval["anchor_revision_after"]==2
         and approval["anchor_nonce"]==anchor["nonce"]
         and approval["anchor_message_sha256"]==digest
         and type(approval["issued_epoch"]) is int
         and instant-60<=approval["issued_epoch"]<=instant+10
         and type(approval["expires_epoch"]) is int
         and instant<approval["expires_epoch"]<=approval["issued_epoch"]+120,
         "ORIGINAL313_REVOKE_APPROVAL_SCOPE_INVALID")
    try:
        signature=base64.b64decode(approval["signature"],validate=True)
        need(len(signature)==64,"ORIGINAL313_REVOKE_APPROVAL_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(ind_pub).verify(
            signature,b"MAD4B_ORIGINAL313_HOST_ANCHOR_WRITE_V1\n"+
            v99.canonical({x:approval[x] for x in v99.PREWRITE_FIELDS}))
    except (InvalidSignature,ValueError,TypeError) as ex:
        raise RevokePrewriteHeld("ORIGINAL313_REVOKE_INDEPENDENT_APPROVAL_INVALID") from ex
    scope="all-royal-egypt--staging.json"
    path=store.path("transactions",job["job_id"]+".json")
    immutable={
        "contract":v99.PREWRITE_CONTRACT,
        "job_id":job["job_id"],"operation_id":v101.REVOKE,
        "company":job["company"],"environment":job["environment"],
        "site_uuid":job["site_uuid"],"anchor_revision_after":2,
        "anchor_nonce":anchor["nonce"],"anchor_message_sha256":digest,
        "plan_sha256":job["plan_sha256"],
        "command_sha256":job["command_sha256"],
        "grant_id":job["grant_id"],
        "signed_approval":approval,
        "approval_sha256":hashlib.sha256(v99.canonical(approval)).hexdigest(),
        "previous_event_sha256":verified["previous_event_sha256"],
        "next_event_sha256":hashlib.sha256(gate.body(
            event,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest(),
        "reserved_epoch":instant}
    fd=os.open(store.root/".original313-txn.lock",
               os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        need(store.read(store.path("revisions",scope))==ledger
             and store.read(store.path("checkpoints",scope))==checkpoint,
             "ORIGINAL313_REVOKE_SIGNED_HIGHWATER_CHANGED_DURING_RESERVATION")
        others=list((store.root/"transactions").glob("*.json"))
        need(len(others)<=256,"ORIGINAL313_REVOKE_TRANSACTION_STORE_UNBOUNDED")
        for item in others:
            if item != path:
                other=store.read(item)
                # Original issue's prior transaction is expected.
                need(other.get("operation_id")!=v101.REVOKE
                     or other.get("company")!=job["company"]
                     or other.get("environment")!=job["environment"],
                     "ORIGINAL313_CONCURRENT_REVOKE_RESERVATION_DENIED")
        old=store.read(path)
        need(old is None or old==immutable,
             "ORIGINAL313_REVOKE_PREWRITE_RESERVATION_REPLAY_CHANGED")
        if old is None:store.write_new(path,immutable)
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)
    return {"state":"ORIGINAL313_SIGNED_FIRST_REVOKE_PREWRITE_RESERVED",
            "job_id":job["job_id"],"anchor_revoked":False,
            "execution_authorized":False,"original313_certified":False}

if __name__=="__main__":
    print(json.dumps({"state":"HOLD",
          "reason":"CURRENT_INDEPENDENT_ANCHOR_AND_OWNER_REVOKE_JOB_REQUIRED",
          "anchor_revoked":False,"original313_receipt_signed":False},
          sort_keys=True))
    raise SystemExit(2)
