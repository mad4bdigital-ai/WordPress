#!/usr/bin/env python3
"""Original313 independent first-Anchor Enrollment, exact-owner only.

No CLI, HTTP, or automatic Worker hook. The root-only first-party caller
must obtain *both* immutable original305 queued Job & request/Grant/328
and genuine original306 claimed Job/Claim/row; this source verifies them
against pinned v95 and v86 before its enrolled independent313 key signs.

Signing an Enrollment is a prerequisite, NOT an Anchor issuance, execution
approval, signed 313 write admission, revocation, or general host grant.
"""
from __future__ import annotations
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import stat
import sys
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
V95=REGISTRY/"host-anchor-v95/original306_claim_only.py"
V95_SHA="ebb3fb1efe58a1506516b7d8b76f2ff4281b3f2b0c0ee8361b606dcfa3240b64"
V86=REGISTRY/"host-anchor-v86/original306_typed_claim.py"
V86_SHA="84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f"
V89=ROOT/"host-authority-v89/first_anchor_original312.py"
V89_SHA="78d435b833cd91e409d2753a9086064266263eec79e431a3ffacbf7acce299f4"
V99=ROOT/"host-authority-v99/original313_postwrite_transaction.py"
V99_SHA="1b42065db70e224a889c969d28d9b8119e1dceba8003d43e05dc0b0111f0c00d"
V100=ROOT/"host-authority-v100/original312_first_issue_bundle.py"
V100_SHA="1a49a50046b19a3076b63fbaf1757d2f8d19982e86bd7c990b36b65af458277d"
KEY=ROOT/"root-only-verifier-ed25519.key"
SCOPE="all-royal-egypt--staging.json"
OPERATION="company.host-authority.anchor.issue"

class EnrollmentHeld(ValueError):pass

def need(condition,reason):
    if not condition:raise EnrollmentHeld(reason)

def pinned(path:Path,expected:str,name:str):
    need(path.is_file() and not path.is_symlink()
         and hashlib.sha256(path.read_bytes()).hexdigest()==expected,
         "ORIGINAL313_ENROLLMENT_PINNED_VERIFIER_DRIFT")
    spec=importlib.util.spec_from_file_location(name,path)
    need(spec is not None and spec.loader is not None,
         "ORIGINAL313_ENROLLMENT_SOURCE_IMPORT_DENIED")
    source=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(source)
    return source

def signed_enrollment(*,claimed,verified_owner,verified_claim,
                      signer,independent_pub,now,nonce=None,enrollment_id=None):
    """Pure testable final envelope. Never accept this as an authorization:
    first_party_originals() is the exclusive live call path.
    """
    need(type(claimed) is dict
         and type(verified_owner) is dict and type(verified_claim) is dict,
         "ORIGINAL313_FIRST_OWNER_ORIGINAL_EVIDENCE_REQUIRED")
    need(verified_owner.get("state")==
             "VERIFIED_ORIGINAL305_OWNER_BOOTSTRAP__CLAIM_ONLY"
         and verified_owner.get("execution_authorized") is False
         and verified_owner.get("original328_active") is True
         and verified_owner.get("job_id")==claimed.get("job_id")
         and verified_owner.get("plan_sha256")==claimed.get("plan_sha256")
         and verified_claim.get("state")==
             "HOST_V2_CLAIM_MATCHED__SIGNED_ORIGINAL312_ISSUER_PENDING"
         and verified_claim.get("original306_claim_matched") is True
         and verified_claim.get("execution_authorized") is False
         and verified_claim.get("original313_certified") is False
         and verified_claim.get("anchor_issued") is False
         and verified_claim.get("job_id")==claimed.get("job_id")
         and verified_claim.get("plan_sha256")==claimed.get("plan_sha256")
         and verified_claim.get("command_sha256")==claimed.get("command_sha256"),
         "ORIGINAL313_OWNER_AND_CLAIM_DID_NOT_VERIFY")
    need(claimed.get("operation_id")==OPERATION
         and claimed.get("operation")==OPERATION
         and claimed.get("company")=="all-royal-egypt"
         and claimed.get("environment")=="staging"
         and claimed.get("target_host_id")=="hosting-2"
         and claimed.get("state")==
              "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and claimed.get("host_v2_bootstrap_only") is True
         and claimed.get("authority_mode")=="bootstrap"
         and claimed.get("job_type")=="signed_remote_hostinger_wpcli_v2"
         and claimed.get("principal_type")=="signed_host_principal_v2"
         and claimed.get("executed") is False
         and type(claimed.get("expires_epoch")) is int
         and claimed["expires_epoch"]>=now+35,
         "ORIGINAL313_FIRST_OWNER_CLAIMED_JOB_NOT_CURRENT")
    pub=signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    need(type(independent_pub) is bytes and len(independent_pub)==32
         and pub==independent_pub,
         "ORIGINAL313_FIRST_OWNER_SIGNER_NOT_ENROLLED")
    issue=now
    expires=min(now+120,claimed["expires_epoch"]-10)
    need(expires>=now+25,"ORIGINAL313_FIRST_OWNER_ENROLLMENT_WINDOW_TOO_SHORT")
    n=secrets.token_hex(16) if nonce is None else nonce
    enrollment=secrets.token_hex(16) if enrollment_id is None else enrollment_id
    v89=pinned(V89,V89_SHA,"_original313_first_owner_verifier")
    for label,value in (("nonce",n),("enrollment_id",enrollment)):
        need(type(value) is str and v89.HEX32.fullmatch(value) is not None,
             "ORIGINAL313_FIRST_OWNER_NONCE_OR_ID_INVALID:"+label)
    fields={
       "contract":v89.CONTRACT,"source":v89.SOURCE,
       "company":claimed["company"],"environment":claimed["environment"],
       "site_uuid":claimed["site_uuid"],"origin":claimed["origin"],
       "target_host_id":claimed["target_host_id"],
       "ssh_principal":claimed["target_ssh_principal"],
       "enrollment_id":enrollment,"nonce":n,
       "original306_job_id":claimed["job_id"],
       "plan_sha256":claimed["plan_sha256"],
       "command_sha256":claimed["command_sha256"],
       "current_anchor_revision":0,
       "current_anchor_state":"NOT_ENROLLED",
       "nonce_unspent":True,
       "signed_site_identity_verified":True,
       "remote_host_identity_verified":True,
       "independent_issuer_verified":True,
       "owner_scope_authorized":True,
       "enrollment_revoked":False,
       "issued_epoch":issue,"expires_epoch":expires
    }
    need(list(fields)==list(v89.SIGNED_FIELDS),
         "ORIGINAL313_FIRST_OWNER_ENROLLMENT_SIGNED_SCHEMA_DRIFT")
    fields["signature"]=base64.b64encode(
        signer.sign(v89.enrollment_message(fields))).decode()
    verified=v89.verify_enrollment(fields,independent_pub,claimed,now=now)
    need(verified.get("independent_enrollment_signature_verified") is True
         and verified.get("job_id")==claimed["job_id"],
         "ORIGINAL313_FIRST_OWNER_ENROLLMENT_POSTSIGN_FAILED")
    return fields

def first_party_originals(index,queued,pending,claimed,claim,broker_row,
                          plan,request,grant,parent,transport,site,*,store=None,now=None):
    """One fixed, privileged Root path. Original source records ONLY.

    No user-supplied signer/nonce/enrollment path/permissions; never invoke
    this from an unauthenticated REST route or generic command executor.
    """
    instant=int(time.time()) if now is None else now
    need(os.geteuid()==0,"ORIGINAL313_OWNER_ENROLLMENT_ROOT_REQUIRED")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    need(type(index) is Registry and index.root==REGISTRY,
         "ORIGINAL313_ENROLLED_FIRST_PARTY_REGISTRY_REQUIRED")
    need(all(type(x) is dict for x in
          (queued,pending,claimed,claim,broker_row,plan,request,
           grant,parent,transport,site)),
         "ORIGINAL313_OWNER_ENROLLMENT_ORIGINALS_REQUIRED")
    need(store is None and now is None,
         "ORIGINAL313_ROOT_ENROLLMENT_CALLER_STORE_OR_CLOCK_FORBIDDEN")
    need(queued.get("job_id")==claimed.get("job_id")
         and queued.get("operation_id")==claimed.get("operation_id")
         and queued.get("central_principal_id")==claimed.get("central_principal_id")
         and all(queued.get(x)==claimed.get(x) for x in
              ("company","environment","site_uuid","origin",
               "target_host_id","target_ssh_principal","plan_sha256",
               "command_sha256","grant_id","transport_id"))
         and queued.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
         and type(claim.get("claimed_epoch")) is int
         and instant-90<=claim["claimed_epoch"]<=instant+5
         and type(request.get("expires_epoch")) is int
         and request["expires_epoch"]>=instant+25
         and type(site.get("anchor_readback")) is dict
         and type(site["anchor_readback"].get("observed_epoch")) is int
         and instant-15<=site["anchor_readback"]["observed_epoch"]<=instant+5,
         "ORIGINAL313_QUEUED_AND_CLAIMED_JOB_NOT_IDENTICAL")
    # Verify a *new* independent original318 readback from the actual
    # enrolled WPCLI runtime; caller's site dictionary cannot self-attest.
    v100=pinned(V100,V100_SHA,"_original313_first_owner_fresh_wp")
    v89_original=pinned(V89,V89_SHA,"_original313_first_owner_readonly_broker")
    broker_source=v100.pinned(v89_original.ORIGINAL_SIGNER_SOURCE,
        v89_original.ORIGINAL_SIGNER_SHA256,
        "_original313_first_owner_central_wp")
    fresh=v100.fresh_original_wp_snapshot(broker_source)
    observed=fresh.get("anchor_readback")
    need(fresh.get("original328_active") is True
         and type(fresh.get("site")) is dict
         and fresh["site"].get("company")==claimed["company"]
         and fresh["site"].get("environment")==claimed["environment"]
         and fresh["site"].get("site_uuid")==claimed["site_uuid"]
         and fresh["site"].get("canonical_origin")==claimed["origin"]
         and fresh["site"].get("profile_digest")==plan.get("profile_digest")
         and type(observed) is dict
         and observed.get("state")=="MISSING_OR_INVALID"
         and observed.get("revision")==0
         and observed.get("original313_certified") is False
         and observed.get("write_authorized") is False
         and type(observed.get("observed_epoch")) is int
         and instant-15<=observed["observed_epoch"]<=instant+5,
         "ORIGINAL313_FIRST_OWNER_FRESH_CENTRAL_WP_NOT_PRISTINE")
    v95=pinned(V95,V95_SHA,"_original313_original305_owner_admission")
    owner=v95.verify_against_originals(
         index,pending,queued,plan,request,grant,parent,transport,site,
         now=instant)
    v86=pinned(V86,V86_SHA,"_original313_original306_claim_admission")
    verified=v86.inspect_claimed_original(index,claimed,claim,broker_row,plan)
    # Exactly the same root registry's first party key must be used.
    v99=pinned(V99,V99_SHA,"_original313_original_store_pins")
    store=v99.PrivateStore() if store is None else store
    v99.require_enrolled_live_signers(
         store,index.host_anchor_312_public_key,
         index.independent_verifier_public_key)
    need(KEY.is_file() and not KEY.is_symlink(),
         "ORIGINAL313_FIRST_OWNER_ROOT_KEY_MISSING")
    kstat=KEY.lstat()
    need(stat.S_ISREG(kstat.st_mode) and kstat.st_uid==0 and kstat.st_gid==0
         and kstat.st_nlink==1 and stat.S_IMODE(kstat.st_mode)==0o600,
         "ORIGINAL313_FIRST_OWNER_ROOT_KEY_UNTRUSTED")
    key=Ed25519PrivateKey.from_private_bytes(KEY.read_bytes())
    pub=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    need(pub==index.independent_verifier_public_key,
         "ORIGINAL313_ROOT_SIGNER_NOT_ENROLLED")
    # All Original305 / 306 and registry approvals have passed. Avoid
    # installing anything if revision, checkpoint or prior transaction exists.
    scope=store.path("enrollments",SCOPE)
    lock=store.root/".original313-txn.lock"
    fd=os.open(lock,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        need(store.read(scope) is None
             and store.read(store.path("revisions",SCOPE)) is None
             and store.read(store.path("checkpoints",SCOPE)) is None
             and store.read(store.path("transactions",claimed["job_id"]+".json")) is None,
             "ORIGINAL313_FIRST_OWNER_ENROLLMENT_ALREADY_RESERVED")
        # Sign *inside* Root's exclusive lock; no state change before this.
        signed=signed_enrollment(claimed=claimed,verified_owner=owner,
              verified_claim=verified,signer=key,independent_pub=pub,now=instant)
        store.write_new(scope,signed)
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)
    return {"state":"ORIGINAL313_INDEPENDENT_OWNER_ENROLLMENT_SIGNED",
            "job_id":claimed["job_id"],
            "enrollment_id":signed["enrollment_id"],
            "expires_epoch":signed["expires_epoch"],
            "enrollment_signature_verified":True,
            "anchor_issued":False,"execution_authorized":False,
            "original313_final_receipt_signed":False}

def main():
    print(json.dumps({"state":"HOLD",
          "reason":"AUTHENTIC_ORIGINAL305_306_OWNER_JOB_AND_SIGNED_CLAIM_REQUIRED",
          "enrollment_issued":False,"anchor_issued":False,
          "independent_signer_invoked":False},sort_keys=True))
    return 2
if __name__=="__main__":
    raise SystemExit(main())
