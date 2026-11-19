#!/usr/bin/env python3
"""v120 Broker-signed Original306 QUEUED preclaim witness — SOURCE ONLY.

The Broker attests to a fresh original305/306 QUEUED snapshot BEFORE
Original306's signed Claim mutates the WordPress Job. A witness has a distinct
Ed25519 Broker signature, Root-only immutable storage and exact site/plan/
transport binding. It is NOT signed original312, original313 approval, a
Host Anchor, or an alternative authorization path.

This module is not connected to the live Worker and has no signing CLI.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import stat
import sys
import time

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
STORE=Path("/var/lib/mad4b/original-host-v2/broker-row-witness")
V118=ROOT/"host-authority-v118/queued_original_capture.py"
V118_SHA="b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2"
BROKER=ROOT.parent/"broker-v1/native-vps-client-principal.py"
BROKER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"
CONTRACT="mad4b.original306.broker-signed-queued-row.v1"
DOMAIN=b"MAD4B_ORIGINAL306_BROKER_QUEUED_ROW_V1\n"
ISSUE="company.host-authority.anchor.issue"
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")
FIELDS=(
 "contract","job_id","company","environment","site_uuid","target_id",
 "broker_host_id","command_sha256","plan_sha256","grant_id","transport_id",
 "queued_preimage_sha256","issued_epoch","expires_epoch","nonce"
)
ROW_FIELDS=(
 "job_id","company","environment","site_uuid","target_id","broker_host_id",
 "command_sha256"
)

class BrokerWitnessHeld(ValueError):
    pass

def need(ok, reason):
    if not ok:raise BrokerWitnessHeld(reason)

def canonical(obj):
    return json.dumps(obj,sort_keys=True,separators=(",",":"),
        ensure_ascii=False).encode()

def pinned(path,sha,name):
    need(path.is_file() and not path.is_symlink(),
        "V120_PINNED_SOURCE_MISSING")
    st=path.lstat()
    need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_gid==0
         and st.st_nlink==1 and not st.st_mode&0o077
         and hashlib.sha256(path.read_bytes()).hexdigest()==sha,
         "V120_FIRST_PARTY_SOURCE_DRIFT")
    sp=importlib.util.spec_from_file_location(name,path)
    need(sp is not None and sp.loader is not None,
        "V120_PINNED_SOURCE_IMPORT_FAILED")
    m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
    return m

def queued_original(preimage,*,now):
    need(type(preimage) is dict
        and preimage.get("contract")==
            "mad4b.original306.first-anchor-queued-preimage.v1"
        and preimage.get("issuer_execution_authorized") is False
        and preimage.get("anchor_issued") is False
        and type(preimage.get("originals")) is dict,
        "V120_ORIGINAL306_QUEUED_PREIMAGE_REQUIRED")
    original=preimage["originals"]
    q=original.get("queued")
    request=original.get("request")
    need(type(q) is dict and type(request) is dict
        and preimage.get("job_id")==q.get("job_id")
        and preimage.get("plan_sha256")==q.get("plan_sha256")
        and preimage.get("company")=="all-royal-egypt"
        and preimage.get("environment")=="staging"
        and q.get("company")=="all-royal-egypt"
        and q.get("environment")=="staging"
        and q.get("operation_id")==ISSUE
        and q.get("operation")==ISSUE
        and q.get("target_host_id")=="hosting-2"
        and q.get("broker_host_id")=="srv2030908"
        and q.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
        and q.get("executed") is False
        and q.get("host_v2_bootstrap_only") is True
        and q.get("authority_mode")=="bootstrap"
        and q.get("job_type")=="signed_remote_hostinger_wpcli_v2"
        and q.get("principal_type")=="signed_host_principal_v2"
        and request.get("request_id")==q["job_id"]
        and request.get("status")==
          "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        and request.get("execution_performed") is False
        and request.get("grant_id")==q.get("grant_id")
        and request.get("transport_id")==q.get("transport_id")
        and isinstance(q.get("job_id"),str)
        and HEX32.fullmatch(q["job_id"])
        and isinstance(q.get("command_sha256"),str)
        and HEX64.fullmatch(q["command_sha256"])
        and isinstance(q.get("plan_sha256"),str)
        and HEX64.fullmatch(q["plan_sha256"])
        and type(q.get("expires_epoch")) is int
        and type(request.get("expires_epoch")) is int
        and q["expires_epoch"]>=now+155
        and request["expires_epoch"]>=now+155
        and type(preimage.get("captured_epoch")) is int
        and now-30<=preimage["captured_epoch"]<=now+3,
        "V120_EXACT_FRESH_ORIGINAL305_306_PRECLAIM_REQUIRED")
    need(type(original.get("pending")) is list
        and len(original["pending"])==1
        and original["pending"][0].get("job_id")==q["job_id"]
        and original["pending"][0].get("broker_host_id")=="srv2030908"
        and original["pending"][0].get("target_host_id")=="hosting-2",
        "V120_ORIGINAL306_PENDING_BROKER_QUEUE_REQUIRED")
    return q

def row_from_preclaim(preimage,signer,public,*,now,nonce):
    """Pure signed original Broker witness. Tests pass only ephemeral keys.

    A trusted runtime must *not* accept a user-supplied preimage or signer.
    It loads root-owned v118 evidence and the original pinned Broker key.
    """
    need(os.geteuid()==0,"V120_ROOT_ONLY_BROKER_WITNESS")
    q=queued_original(preimage,now=now)
    need(isinstance(public,bytes) and len(public)==32
        and callable(getattr(signer,"sign",None))
        and signer.public_key().public_bytes(
          Encoding.Raw,PublicFormat.Raw)==public,
        "V120_ORIGINAL_BROKER_SIGNER_MISMATCH")
    need(isinstance(nonce,str) and HEX32.fullmatch(nonce),
        "V120_BROKER_WITNESS_NONCE_INVALID")
    expire=min(now+90,q["expires_epoch"]-15)
    need(expire>=now+45,"V120_BROKER_ATTESTATION_WINDOW_TOO_SHORT")
    fields={
      "contract":CONTRACT,
      "job_id":q["job_id"],
      "company":q["company"],
      "environment":q["environment"],
      "site_uuid":q["site_uuid"],
      "target_id":q["target_host_id"],
      "broker_host_id":q["broker_host_id"],
      "command_sha256":q["command_sha256"],
      "plan_sha256":q["plan_sha256"],
      "grant_id":q["grant_id"],
      "transport_id":q["transport_id"],
      "queued_preimage_sha256":hashlib.sha256(canonical(preimage)).hexdigest(),
      "issued_epoch":now,
      "expires_epoch":expire,
      "nonce":nonce,
    }
    need(tuple(fields)==FIELDS,"V120_SIGNED_BROKER_ROW_SCHEMA_DRIFT")
    fields["signature"]=base64.b64encode(
        signer.sign(DOMAIN+canonical(fields))).decode()
    verified_broker_row(fields,preimage,public,now=now)
    return fields

def verified_broker_row(witness,preimage,original_broker_pub,*,now=None):
    instant=int(time.time()) if now is None else now
    need(type(witness) is dict and
        tuple(witness)==FIELDS+("signature",),
        "V120_BROKER_SIGNED_ROW_EXACT_FIELDS_REQUIRED")
    q=queued_original(preimage,now=instant)
    need(witness.get("contract")==CONTRACT
        and witness.get("job_id")==q["job_id"]
        and witness.get("company")==q["company"]
        and witness.get("environment")==q["environment"]
        and witness.get("site_uuid")==q["site_uuid"]
        and witness.get("target_id")==q["target_host_id"]
        and witness.get("broker_host_id")==q["broker_host_id"]
        and witness.get("command_sha256")==q["command_sha256"]
        and witness.get("plan_sha256")==q["plan_sha256"]
        and witness.get("grant_id")==q["grant_id"]
        and witness.get("transport_id")==q["transport_id"]
        and witness.get("queued_preimage_sha256")==
          hashlib.sha256(canonical(preimage)).hexdigest()
        and type(witness.get("issued_epoch")) is int
        and instant-30<=witness["issued_epoch"]<=instant+3
        and type(witness.get("expires_epoch")) is int
        and instant+10<=witness["expires_epoch"]<=witness["issued_epoch"]+90
        and witness["expires_epoch"]<=q["expires_epoch"]-15
        and isinstance(witness.get("nonce"),str)
        and HEX32.fullmatch(witness["nonce"]),
        "V120_BROKER_ROW_SCOPE_OR_FRESHNESS_CHANGED")
    need(isinstance(original_broker_pub,bytes)
        and len(original_broker_pub)==32,
        "V120_BROKER_PUBLIC_KEY_NOT_PINNED")
    msg=DOMAIN+canonical({f:witness[f] for f in FIELDS})
    try:
        sig=base64.b64decode(witness["signature"],validate=True)
        need(len(sig)==64,"V120_SIGNED_BROKER_ROW_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(original_broker_pub).verify(sig,msg)
    except (ValueError,TypeError,InvalidSignature) as exc:
        raise BrokerWitnessHeld("V120_BROKER_SIGNED_ROW_SIGNATURE_INVALID") from exc
    return {k:witness[k] for k in ROW_FIELDS}

def check_store(root,*,create=False):
    need(os.geteuid()==0,"V120_ROOT_ONLY_BROKER_WITNESS")
    need(root.is_absolute() and not root.is_symlink()
        and root.parent.is_dir() and not root.parent.is_symlink(),
        "V120_BROKER_ROW_STORE_PARENT_INVALID")
    pst=root.parent.stat()
    need(pst.st_uid==0 and pst.st_gid==0 and
        stat.S_IMODE(pst.st_mode)==0o700,
        "V120_BROKER_ROW_STORE_PARENT_UNTRUSTED")
    if not root.exists() and create: root.mkdir(mode=0o700)
    need(root.is_dir() and not root.is_symlink()
        and root.stat().st_uid==0 and root.stat().st_gid==0 and
        stat.S_IMODE(root.stat().st_mode)==0o700,
        "V120_BROKER_ROW_STORE_NOT_ROOT_ONLY")

def read_witness(jid,root=STORE):
    need(isinstance(jid,str) and HEX32.fullmatch(jid),
        "V120_BROKER_ROW_JOB_ID_INVALID")
    check_store(root)
    path=root/(jid+".json")
    need(path.is_file() and not path.is_symlink(),
        "V120_BROKER_ROW_WITNESS_MISSING")
    st=path.stat()
    need(st.st_uid==0 and st.st_gid==0 and st.st_nlink==1
        and stat.S_IMODE(st.st_mode)==0o600
        and 300<st.st_size<5000,
        "V120_SIGNED_BROKER_WITNESS_FILE_NOT_TRUSTED")
    try:row=json.loads(path.read_bytes())
    except (ValueError,TypeError,UnicodeError) as exc:
        raise BrokerWitnessHeld("V120_SIGNED_BROKER_WITNESS_INVALID") from exc
    need(type(row) is dict and
        tuple(row)==FIELDS+("signature",) and row.get("job_id")==jid,
        "V120_SIGNED_BROKER_WITNESS_FORMAT_CHANGED")
    return row

def store_witness(witness,root=STORE):
    need(type(witness) is dict and
        tuple(witness)==FIELDS+("signature",),
        "V120_EXACT_SIGNED_BROKER_WITNESS_REQUIRED")
    jid=witness.get("job_id")
    need(isinstance(jid,str) and HEX32.fullmatch(jid),
        "V120_BROKER_ROW_JOB_ID_INVALID")
    check_store(root,create=True)
    p=root/(jid+".json")
    raw=json.dumps(witness,ensure_ascii=False,separators=(",",":")).encode()+b"\n"
    need(300<len(raw)<5000,"V120_BROKER_WITNESS_FILE_SIZE_INVALID")
    try:fd=os.open(p,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    except FileExistsError:
        need(read_witness(jid,root)==witness,
            "V120_BROKER_SIGNED_WITNESS_REPLAY_CHANGED")
        return "ORIGINAL_BROKER_QUEUED_WITNESS_ALREADY_STORED"
    try:
        with os.fdopen(fd,"wb") as stream:
            stream.write(raw);stream.flush();os.fsync(stream.fileno())
        d=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    except BaseException:
        p.unlink(missing_ok=True)
        raise
    return "ORIGINAL_BROKER_QUEUED_WITNESS_SIGNED_AND_STORED"

def capture_live_before_claim(jid):
    """Use only from an explicitly commissioned, pinned Root claim hook."""
    need(os.geteuid()==0,"V120_ROOT_ONLY_BROKER_WITNESS")
    need(isinstance(jid,str) and HEX32.fullmatch(jid),
        "V120_BROKER_ROW_JOB_ID_INVALID")
    v118=pinned(V118,V118_SHA,"_host_v120_original306_queued")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry,checked_json
    index=Registry(REGISTRY)
    require_pub=index.host_anchor_312_public_key
    need(require_pub!=index.independent_verifier_public_key,
        "V120_ORIGINAL_BROKER_AND_313_NOT_DISTINCT")
    preimage=v118.read_preimage(jid)
    now=int(time.time())
    q=queued_original(preimage,now=now)
    # Re-read immutable live QUEUED originals *before* touching the signer.
    actual=v118.owner_state(jid)
    plan=checked_json(v118.PLAN_ROOT/(q["plan_sha256"]+".json"),
                      v118.PLAN_ROOT)
    v118.validate_queued(index,actual,plan,jid,now=now)
    # Original305/328 identifiers and exact QUEUED Job must not change
    # between v118 capture and the first-party Broker signature.
    for k in ("queued","pending","request","grant","parent","transport"):
        need(actual.get(k)==preimage["originals"].get(k),
             "V120_OWNER_ORIGINALS_CHANGED_BEFORE_BROKER_SIGNATURE")
    if (STORE/(jid+".json")).exists():
        previous=read_witness(jid)
        verified_broker_row(previous,preimage,require_pub,now=now)
        return "ORIGINAL_BROKER_QUEUED_WITNESS_ALREADY_STORED"
    broker=pinned(BROKER,BROKER_SHA,"_host_v120_original_broker_signer")
    key=broker.original_signer()
    broker.check_original_wordpress_signer(key)
    need(key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)==
         require_pub,"V120_ORIGINAL_BROKER_KEY_NOT_IN_FIRST_PARTY_INDEX")
    signed=row_from_preclaim(preimage,key,require_pub,now=int(time.time()),
                            nonce=secrets.token_hex(16))
    return store_witness(signed)

def verified_live_row_for_first_anchor(jid,*,now=None):
    """Return v86 expected row ONLY after verifying original Broker signature."""
    need(os.geteuid()==0,"V120_ROOT_ONLY_BROKER_WITNESS")
    v118=pinned(V118,V118_SHA,"_host_v120_original306_readback")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    idx=Registry(REGISTRY)
    pre=v118.read_preimage(jid)
    witness=read_witness(jid)
    return verified_broker_row(witness,pre,
        idx.host_anchor_312_public_key,now=now)

def main():
    # Deliberately no signing API, CLI capture flag, arbitrary paths or
    # acceptance of user-supplied JSON/signer. This module is uncommissioned.
    print(json.dumps({"state":"HOLD_SOURCE_ONLY_BROKER_WITNESS_NOT_COMMISSIONED",
      "broker_signer_invoked":False,"owner_job_created":False,
      "anchor_issued":False,"wp_write_performed":False},sort_keys=True))
    return 2

if __name__=="__main__":
    raise SystemExit(main())
