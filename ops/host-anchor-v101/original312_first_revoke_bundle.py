#!/usr/bin/env python3
"""First-Anchor revoke when grant_revision is still zero, source-only.

Allows no WordPress, Root registry, high-water, or signing writes. Current
Broker ledger and independent Original313 checkpoint must cryptographically
certify the ACTIVE first Anchor, even with zero created grants.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
import re
import time
import secrets
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
from cryptography.exceptions import InvalidSignature

REGISTRY=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
PLAN=REGISTRY/"host-anchor-v2/operation_plan_contract.py"
PLAN_SHA="8438e3874905d75568bfc1c5c78bf5769a982834cae06a68d6aa111d080f6130"
GATE=Path("/opt/mad4b-company-transport/native-313-independent/host-authority-v83/v81/current_revision_gate.py")
GATE_SHA="8d45990df6a1cdc75c2f3399f4490eea2c775466cc5de104f73c97d6903b2dd6"
ISSUE="company.host-authority.anchor.issue"
REVOKE="company.host-authority.anchor.revoke"
ANCHOR_FIELDS=("contract","company","environment","site_uuid",
 "canonical_origin","profile_digest","host_id","ssh_target","ssh_principal",
 "ssh_root_sha256","host_key_sha256","registry_sha256","policy_sha256",
 "revision","issued","expires","nonce","state","revoked","source")

class RevokeHeld(ValueError): pass
def need(v,reason):
    if not v:raise RevokeHeld(reason)
def module(path,expected,name):
    need(path.is_file() and not path.is_symlink()
         and hashlib.sha256(path.read_bytes()).hexdigest()==expected,
         "ORIGINAL312_REVOKE_FIRST_PARTY_PIN_CHANGED")
    sp=importlib.util.spec_from_file_location(name,path)
    need(sp is not None and sp.loader is not None,"ORIGINAL312_REVOKE_SOURCE_IMPORT_FAILED")
    obj=importlib.util.module_from_spec(sp);sp.loader.exec_module(obj)
    return obj
def _php(v):
    if v is False:return ""
    if v is True:return "1"
    need(type(v) in (str,int),"ORIGINAL312_ANCHOR_SCALAR_INVALID")
    return str(v)
def anchor_message(row):
    need(type(row) is dict and list(row)==list(ANCHOR_FIELDS)+["signature"],
         "ORIGINAL312_ANCHOR_ORDER_INVALID")
    return "\n".join(_php(row[f]) for f in ANCHOR_FIELDS).encode("utf-8")

def verify_first_revoke(plan,job,ledger,checkpoint,wp,broker_pub,ind_pub,*,now=None):
    """Revoke a VERIFIED current ACTIVE first Anchor, never fake grant revision 1.

    The existing registry plan verifier has an operational grant_revision>0
    predicate. Its revoke-zero exception must be rooted in a separate
    verified first-Anchor current high-water, not in a client-provided flag.
    """
    instant=int(time.time()) if now is None else now
    base=module(PLAN,PLAN_SHA,"_original_v101_plan")
    gate=module(GATE,GATE_SHA,"_original_v101_signed_highwater")
    need(all(type(x) is dict for x in (plan,job,ledger,checkpoint,wp))
         and type(broker_pub) is bytes and len(broker_pub)==32
         and type(ind_pub) is bytes and len(ind_pub)==32
         and broker_pub!=ind_pub,"ORIGINAL312_REAL_REVOKE_PROOFS_REQUIRED")
    allowed={"contract","operation_id","authority_mode","company","environment",
             "site_uuid","origin","target_host_id","ssh_principal",
             "policy_sha256","profile_digest","host_key_sha256",
             "ssh_root_sha256","registry_sha256","grant_revision",
             "anchor_revision","target_anchor_nonce","plan_sha256"}
    need(set(plan)==allowed
         and plan["contract"]==base.CONTRACTS[REVOKE]
         and plan["operation_id"]==REVOKE
         and plan["authority_mode"]=="operational"
         and plan["company"]=="all-royal-egypt"
         and plan["environment"]=="staging"
         and plan["target_host_id"]=="hosting-2"
         and type(plan["grant_revision"]) is int
         and plan["grant_revision"]==0
         and type(plan["anchor_revision"]) is int
         and plan["anchor_revision"]==1,
         "ORIGINAL312_FIRST_REVOKE_ZERO_GRANT_ONLY")
    for f,pat in (("company",base.COMPANY),("environment",base.ENV),
                  ("site_uuid",base.UUID),("target_host_id",base.HOST),
                  ("ssh_principal",base.SSH)):
        need(type(plan[f]) is str and pat.fullmatch(plan[f]) is not None,
             "ORIGINAL312_REVOKE_PLAN_SCOPE_INVALID:"+f)
    need(type(plan["origin"]) is str
         and re.fullmatch(r"https://[a-z0-9.-]{4,240}",plan["origin"]) is not None,
         "ORIGINAL312_REVOKE_ORIGIN_INVALID")
    for f in ("policy_sha256","profile_digest","host_key_sha256",
              "ssh_root_sha256","registry_sha256","plan_sha256"):
        need(type(plan[f]) is str and base.HEX64.fullmatch(plan[f]) is not None,
             "ORIGINAL312_REVOKE_PLAN_DIGEST_INVALID:"+f)
    need(type(plan["target_anchor_nonce"]) is str
         and base.HEX32.fullmatch(plan["target_anchor_nonce"]) is not None
         and hashlib.sha256(base.canonical(
             {k:v for k,v in plan.items() if k!="plan_sha256"})).hexdigest()
               ==plan["plan_sha256"],
         "ORIGINAL312_FIRST_REVOKE_EXACT_PLAN_HASH_REQUIRED")
    need(job.get("job_id")!=ledger.get("event_id")
         and type(job.get("job_id")) is str
         and base.HEX32.fullmatch(job["job_id"]) is not None
         and job.get("operation_id")==REVOKE and job.get("operation")==REVOKE
         and job.get("authority_mode")=="operational"
         and job.get("host_v2_bootstrap_only") is False
         and job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type")=="signed_host_principal_v2"
         and job.get("state")=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("executed") is False
         and type(job.get("expires_epoch")) is int
         and job["expires_epoch"]>=instant+20
         and all(job.get(x)==plan.get(y) for x,y in (
             ("company","company"),("environment","environment"),
             ("site_uuid","site_uuid"),("origin","origin"),
             ("target_host_id","target_host_id"),
             ("target_ssh_principal","ssh_principal"),
             ("plan_sha256","plan_sha256"))),
         "ORIGINAL312_REVOKE_EXACT_CLAIM_REQUIRED")
    projected={**job,"anchor_revision":1,"grant_revision":0,
               "ssh_principal":plan["ssh_principal"]}
    checked=gate.verify_fresh_current(projected,ledger,checkpoint,wp,
                                      broker_pub,ind_pub,now=instant)
    need(checked.get("state")=="CURRENT_ORIGINAL_REVISION_MATCHED__NONAUTHORIZING"
         and ledger["revision"]==1 and ledger["grant_revision"]==0
         and ledger["state"]=="ACTIVE"
         and ledger["anchor_nonce"]==plan["target_anchor_nonce"]
         and plan["target_anchor_nonce"]==wp.get("anchor_nonce")
         and wp.get("signed_anchor_active_in_option") is True
         and wp.get("current_anchor_signature_verified") is True
         and wp.get("current_anchor_eligible") is False
         and wp.get("original313_certified") is False
         and wp.get("write_authorized") is False
         and type(wp.get("signed_anchor_message_sha256")) is str
         and base.HEX64.fullmatch(wp["signed_anchor_message_sha256"]) is not None,
         "ORIGINAL312_FIRST_ANCHOR_CURRENT_HIGHWATER_MISSING")
    return {"contract":"mad4b.original312.first-revoke-zero-grant.v1",
            "job_id":job["job_id"],"plan_sha256":plan["plan_sha256"],
            "previous_revision":1,"previous_grant_revision":0,
            "previous_anchor_nonce":ledger["anchor_nonce"],
            "previous_event_sha256":hashlib.sha256(
                gate.body(ledger,gate.FIELDS,
                          "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest(),
            "current_signed_highwater_verified":True,
            "execution_authorized":False,"anchor_revoked":False}

def signed_revoke_documents(plan,job,ledger,checkpoint,wp,prior_anchor,
                            broker_signer,broker_pub,ind_pub,*,now=None,nonce=None):
    """Broker-sign exact revocation artifacts, no WordPress mutation."""
    instant=int(time.time()) if now is None else now
    proof=verify_first_revoke(plan,job,ledger,checkpoint,wp,broker_pub,ind_pub,now=instant)
    need(broker_signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)==broker_pub,
         "ORIGINAL312_REVOKE_BROKER_SIGNER_DRIFT")
    need(type(prior_anchor) is dict
         and list(prior_anchor)==list(ANCHOR_FIELDS)+["signature"],
         "ORIGINAL312_PREVIOUS_SIGNED_ANCHOR_REQUIRED")
    try:
        Ed25519PublicKey.from_public_bytes(broker_pub).verify(
            base64.b64decode(prior_anchor["signature"],validate=True),
            anchor_message(prior_anchor))
    except (InvalidSignature,ValueError,TypeError) as e:
        raise RevokeHeld("ORIGINAL312_PREVIOUS_BROKER_ANCHOR_SIGNATURE_INVALID") from e
    need(prior_anchor["contract"]=="mad4b.company-host-authority-anchor.v2"
         and prior_anchor["state"]=="ACTIVE_INDEPENDENT_HOST_AUTHORITY"
         and prior_anchor["revoked"] is False
         and prior_anchor["revision"]==1
         and prior_anchor["nonce"]==ledger["anchor_nonce"]
         and prior_anchor["company"]==job["company"]
         and prior_anchor["environment"]==job["environment"]
         and prior_anchor["site_uuid"]==job["site_uuid"]
         and prior_anchor["canonical_origin"]==job["origin"]
         and prior_anchor["host_id"]=="srv2030908"
         and prior_anchor["ssh_target"]=="hosting-2"
         and prior_anchor["ssh_principal"]==job["target_ssh_principal"]
         and all(prior_anchor.get(x)==plan.get(x) for x in (
             "profile_digest","policy_sha256","registry_sha256",
             "ssh_root_sha256","host_key_sha256"))
         and hashlib.sha256(anchor_message(prior_anchor)).hexdigest()==
             wp["signed_anchor_message_sha256"],
         "ORIGINAL312_PREVIOUS_SIGNED_WORDPRESS_OPTION_DRIFT")
    new_nonce=secrets.token_hex(16) if nonce is None else nonce
    need(type(new_nonce) is str and re.fullmatch(r"[a-f0-9]{32}",new_nonce)
         and new_nonce!=ledger["anchor_nonce"],
         "ORIGINAL312_REVOKE_NONCE_REPLAY_OR_INVALID")
    new={k:prior_anchor[k] for k in ANCHOR_FIELDS}
    new.update(revision=2,issued=instant,expires=instant+900,nonce=new_nonce,
       state="REVOKED_INDEPENDENT_HOST_AUTHORITY",revoked=True)
    new["signature"]=base64.b64encode(
        broker_signer.sign(anchor_message({**new,"signature":""}))).decode()
    gate=module(GATE,GATE_SHA,"_original_v101_revoked_ledger")
    event={"contract":gate.LEDGER,"company":job["company"],
       "environment":job["environment"],"site_uuid":job["site_uuid"],
       "target_host_id":"hosting-2",
       "ssh_principal":job["target_ssh_principal"],"revision":2,
       "grant_revision":0,"anchor_nonce":new_nonce,
       "state":"REVOKED","event_id":job["job_id"],
       "previous_event_sha256":proof["previous_event_sha256"],
       "issued_epoch":instant,"expires_epoch":instant+45}
    event["signature"]=base64.b64encode(broker_signer.sign(
        gate.body(event,gate.FIELDS,"MAD4B_ORIGINAL_HOST_LEDGER_V1"))).decode()
    gate.sigcheck(event,gate.FIELDS,gate.LEDGER,
                  "MAD4B_ORIGINAL_HOST_LEDGER_V1",broker_pub,instant)
    return {"contract":"mad4b.original312.first-revoke-documents.v1",
       "job_id":job["job_id"],"operation_id":REVOKE,
       "anchor":new,"revision_event":event,
       "anchor_message_sha256":hashlib.sha256(anchor_message(new)).hexdigest(),
       "previous_event_sha256":proof["previous_event_sha256"],
       "original312_signatures_verified":True,
       "original318_write_performed":False,
       "original313_prewrite_approved":False,
       "original313_final_receipt_signed":False,
       "anchor_revoked":False,"execution_authorized":False}

if __name__=="__main__":
    print(json.dumps({"state":"HOLD","reason":"SIGNED_CURRENT_FIRST_ANCHOR_AND_OWNER_REVOKE_JOB_REQUIRED",
                      "original312_signing_performed":False,
                      "anchor_revoked":False},sort_keys=True))
    raise SystemExit(2)
