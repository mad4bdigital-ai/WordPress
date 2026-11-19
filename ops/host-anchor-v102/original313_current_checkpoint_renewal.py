#!/usr/bin/env python3
"""Independent Original313 *current* rev1 high-water renewal.

Purpose-limited: retain signed ACTIVE first-Anchor state while the existing
45-second checkpoint expires. This code never issues Anchor, Grant, or Job;
it cannot approve a revoke or manufacture a historical receipt. It demands
a signed Original312 revision event, original313 issued receipt, historic
checkpoint chain, and fresh independently verified Original318 WP readback.
Source-only until commissioned through first-party Root worker.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import time
import fcntl
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
V99=ROOT/"host-authority-v99/original313_postwrite_transaction.py"
V99_SHA="1b42065db70e224a889c969d28d9b8119e1dceba8003d43e05dc0b0111f0c00d"
GATE=ROOT/"host-authority-v83/v81/current_revision_gate.py"
GATE_SHA="8d45990df6a1cdc75c2f3399f4490eea2c775466cc5de104f73c97d6903b2dd6"
SCOPE="all-royal-egypt--staging.json"
ISSUE="company.host-authority.anchor.issue"
RECEIPT_DOMAIN=b"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1\n"

class RenewalHeld(ValueError):pass

def need(ok,reason):
    if not ok:raise RenewalHeld(reason)

def pinned(path,expected,name):
    need(path.is_file() and not path.is_symlink()
         and hashlib.sha256(path.read_bytes()).hexdigest()==expected,
         "ORIGINAL313_RENEWAL_FIRST_PARTY_SOURCE_PIN_CHANGED")
    sp=importlib.util.spec_from_file_location(name,path)
    need(sp is not None and sp.loader is not None,
         "ORIGINAL313_RENEWAL_PIN_IMPORT_INVALID")
    obj=importlib.util.module_from_spec(sp);sp.loader.exec_module(obj)
    return obj

def checkpoint_digest(gate,row):
    return hashlib.sha256(gate.body(
        row,gate.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1")).hexdigest()

def renewal_archive(store,body_sha):
    need(len(body_sha)==64 and all(x in "0123456789abcdef" for x in body_sha),
         "ORIGINAL313_RENEWAL_ARCHIVE_DIGEST_INVALID")
    folder=store.root/"checkpoint-history"
    if not folder.exists():
        need(not folder.is_symlink(),"ORIGINAL313_RENEWAL_ARCHIVE_LINK_FORBIDDEN")
        folder.mkdir(mode=0o700)
    st=folder.lstat()
    need(folder.is_dir() and not folder.is_symlink()
         and st.st_uid==0 and st.st_gid==0 and stat.S_IMODE(st.st_mode)==0o700,
         "ORIGINAL313_RENEWAL_ARCHIVE_NOT_ROOT_PROTECTED")
    return folder/("checkpoint-"+body_sha+".json")

def renew_current(ledger,wp,receipt,broker_pub,independent_private,*,store=None,now=None):
    """Called only from first-party Root after fresh WPCLI readback.

    For real Root, public signer pins must match enrolled first-party Root
    sources. For tests, synthetic keys are allowed in isolated temp stores.
    """
    instant=int(time.time()) if now is None else now
    need(type(ledger) is dict and type(wp) is dict and type(receipt) is dict
         and type(broker_pub) is bytes and len(broker_pub)==32,
         "ORIGINAL313_RENEWAL_READONLY_EVIDENCE_REQUIRED")
    independent_pub=independent_private.public_key().public_bytes(
        Encoding.Raw,PublicFormat.Raw)
    need(independent_pub!=broker_pub,
         "ORIGINAL313_RENEWAL_INDEPENDENT_SIGNER_REQUIRED")
    v99=pinned(V99,V99_SHA,"_host_v102_v99_private_storage")
    gate=pinned(GATE,GATE_SHA,"_host_v102_signed_gate")
    store=v99.PrivateStore() if store is None else store
    v99.require_enrolled_live_signers(store,broker_pub,independent_pub)
    # Never accept a missing, revoked, or later revision as the first ACTIVE.
    gate.sigcheck(ledger,gate.FIELDS,gate.LEDGER,
          "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,instant,historical=True)
    event_digest=hashlib.sha256(gate.body(
          ledger,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
    need(ledger["company"]=="all-royal-egypt"
         and ledger["environment"]=="staging"
         and ledger["target_host_id"]=="hosting-2"
         and ledger["revision"]==1 and ledger["grant_revision"]==0
         and ledger["state"]=="ACTIVE"
         and ledger["previous_event_sha256"]=="0"*64,
         "ORIGINAL313_RENEWAL_FIRST_ACTIVE_REVISION_ONLY")
    need(wp.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
         and wp.get("source")=="original318_fresh_wp_readback"
         and wp.get("readback_verified") is True
         and wp.get("current_anchor_signature_verified") is True
         and ((wp.get("state")=="ACTIVE"
               and wp.get("signed_anchor_active_in_option") is True)
              or (wp.get("state")=="EXPIRED"
                  and wp.get("signed_anchor_active_in_option") is False
                  and wp.get("prior_grants_ineligible") is True))
         and wp.get("original313_certified") is False
         and wp.get("write_authorized") is False
         and wp.get("current_anchor_eligible") is False
         and type(wp.get("observed_epoch")) is int
         and instant-15<=wp["observed_epoch"]<=instant+5
         and all(wp.get(k)==ledger[k] for k in
              ("company","environment","site_uuid","revision","anchor_nonce"))
         and ledger["state"]=="ACTIVE"
         and type(wp.get("signed_anchor_message_sha256")) is str
         and len(wp["signed_anchor_message_sha256"])==64,
         "ORIGINAL313_RENEWAL_WORDPRESS_NOT_FRESH_ACTIVE")
    need(set(receipt)==set(v99.RECEIPT_FIELDS)|{"signature"}
         and receipt["contract"]==v99.RECEIPT
         and receipt["job_id"]==ledger["event_id"]
         and receipt["operation_id"]==ISSUE
         and receipt["event_sha256"]==event_digest
         and receipt["company"]==ledger["company"]
         and receipt["environment"]==ledger["environment"]
         and receipt["site_uuid"]==ledger["site_uuid"]
         and receipt["revision"]==1
         and receipt["state"]=="ACTIVE"
         and receipt["anchor_nonce"]==ledger["anchor_nonce"]
         and receipt["wp_signed_message_sha256"]==
             wp["signed_anchor_message_sha256"]
         and type(receipt["issued_epoch"]) is int
         and 0<receipt["issued_epoch"]<=instant,
         "ORIGINAL313_RENEWAL_SIGNED_ISSUE_RECEIPT_MISMATCH")
    try:
        signature=base64.b64decode(receipt["signature"],validate=True)
        need(len(signature)==64,"ORIGINAL313_RENEWAL_RECEIPT_SIGNATURE_SIZE")
        Ed25519PublicKey.from_public_bytes(independent_pub).verify(
            signature,RECEIPT_DOMAIN+
            v99.canonical({f:receipt[f] for f in v99.RECEIPT_FIELDS}))
    except (InvalidSignature,ValueError,TypeError) as exc:
        raise RenewalHeld("ORIGINAL313_RENEWAL_RECEIPT_SIGNATURE_INVALID") from exc
    fd=os.open(store.root/".original313-txn.lock",
               os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        current=store.read(store.path("revisions",SCOPE))
        checkpoint_path=store.path("checkpoints",SCOPE)
        checkpoint=store.read(checkpoint_path)
        receipt_on_disk=store.read(store.path("receipts",ledger["event_id"]+".json"))
        need(current==ledger and receipt_on_disk==receipt
             and type(checkpoint) is dict,
             "ORIGINAL313_RENEWAL_HIGHWATER_OR_RECEIPT_CHANGED")
        gate.sigcheck(checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
            "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,instant,
            historical=True)
        need(checkpoint["event_sha256"]==event_digest
             and all(checkpoint[k]==ledger[k] for k in
               ("company","environment","site_uuid","revision","grant_revision",
                "anchor_nonce","state")),
             "ORIGINAL313_RENEWAL_CURRENT_CHECKPOINT_MISMATCH")
        original_sha=receipt["checkpoint_sha256"]
        live_sha=checkpoint_digest(gate,checkpoint)
        original_archive=renewal_archive(store,original_sha)
        if live_sha==original_sha:
            original=checkpoint
        else:
            original=store.read(original_archive)
            need(original is not None
                 and checkpoint_digest(gate,original)==original_sha,
                 "ORIGINAL313_RENEWAL_ORIGINAL_RECEIPT_CHECKPOINT_LOST")
        gate.sigcheck(original,gate.C_FIELDS,gate.CHECKPOINT,
            "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,instant,
            historical=True)
        need(original["event_sha256"]==event_digest
             and original["revision"]==1 and original["state"]=="ACTIVE",
             "ORIGINAL313_RENEWAL_ORIGINAL_RECEIPT_CHECKPOINT_DRIFT")
        if checkpoint["expires_epoch"]>=instant+20:
            gate.sigcheck(checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
                 "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,instant)
            return {"state":"ORIGINAL313_CURRENT_CHECKPOINT_ALREADY_FRESH",
               "revision":1,"new_checkpoint_signed":False,
               "anchor_issued":False,"grant_issued":False}
        # The independent witness can attest the same historic event again,
        # but cannot change its revision, nonce, digest, or grant count.
        proposed={k:ledger[k] for k in
           ("company","environment","site_uuid","revision","grant_revision",
            "anchor_nonce","state")}
        proposed={"contract":gate.CHECKPOINT,**proposed,
          "event_sha256":event_digest,
          "issued_epoch":instant,"expires_epoch":instant+45}
        proposed["signature"]=base64.b64encode(
            independent_private.sign(gate.body(
                proposed,gate.C_FIELDS,"MAD4B_ORIGINAL_HOST_CHECKPOINT_V1"))
        ).decode()
        gate.sigcheck(proposed,gate.C_FIELDS,gate.CHECKPOINT,
             "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",independent_pub,instant)
        cp_archive=renewal_archive(store,live_sha)
        old=store.read(cp_archive)
        need(old is None or old==checkpoint,
             "ORIGINAL313_RENEWAL_CHECKPOINT_ARCHIVE_CONFLICT")
        # Append-only historic checkpoint precedes the high-water swap.
        if old is None:store.write_new(cp_archive,checkpoint)
        store.replace_verified(checkpoint_path,proposed,checkpoint)
        return {"state":"ORIGINAL313_CURRENT_CHECKPOINT_RENEWED",
           "revision":1,"grant_revision":0,
           "event_sha256":event_digest,
           "checkpoint_sha256":checkpoint_digest(gate,proposed),
           "new_checkpoint_signed":True,
           "anchor_issued":False,"grant_issued":False,
           "original_receipt_unchanged":True}
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)

def fresh_original318_from_first_party():
    """Read actual Central WordPress Anchor/site identity; no owner spoof.

    Caller must separately fetch Broker/313 signed Root artifacts. This
    method never reads a private signing key and never changes WordPress.
    """
    source=ROOT/"host-authority-v100/original312_first_issue_bundle.py"
    first=pinned(source,
        "1a49a50046b19a3076b63fbaf1757d2f8d19982e86bd7c990b36b65af458277d",
        "_host_v102_original312_readonly_source")
    first_verify=first.pinned(first.FIRST,first.FIRST_SHA,
                            "_host_v102_original_first_anchor_reader")
    wp_source=first.pinned(first_verify.ORIGINAL_SIGNER_SOURCE,
                          first_verify.ORIGINAL_SIGNER_SHA256,
                          "_host_v102_original_wpcli_readonly")
    snapshot=first.fresh_original_wp_snapshot(wp_source)
    site=snapshot.get("site")
    need(type(site) is dict and site.get("company")=="all-royal-egypt"
         and site.get("environment")=="staging"
         and type(site.get("site_uuid")) is str
         and type(site.get("profile_digest")) is str
         and snapshot.get("original328_active") is True,
         "ORIGINAL313_RENEWAL_ORIGINAL328_SITE_NOT_VERIFIED")
    readback=snapshot.get("anchor_readback")
    need(type(readback) is dict
         and readback.get("contract")==
              "mad4b.original318.host-anchor-current-readback.v2",
         "ORIGINAL313_RENEWAL_ORIGINAL318_READBACK_NOT_AVAILABLE")
    return readback


def main():
    print(json.dumps({"state":"HOLD",
          "reason":"REAL_SIGNED_REVISION_FINAL_RECEIPT_AND_FRESH_ORIGINAL318_REQUIRED",
          "checkpoint_renewed":False,"anchor_issued":False},sort_keys=True))
    return 2

if __name__=="__main__":
    raise SystemExit(main())
