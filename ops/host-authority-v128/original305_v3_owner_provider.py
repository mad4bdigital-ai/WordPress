"""Root-only Original305 v3 owner approval provider.

An Original305 V3 approval is never inferred from a ChatGPT instruction,
a WordPress admin role, existing Original305 host-readiness lease or a legacy
v2 Anchor. It requires a fresh, independently signed exact-plan human Owner
step-up and an independently signed Original313 predecessor witness.

The REAL first-party Root adapter must load pinned public keys and private
signer handle from already trusted enrollment, never from the request. No
private key loading, provider registration, WordPress writes, or network here.
"""
from __future__ import annotations

import base64
import fcntl
import json
import os
from pathlib import Path
import re
import stat

from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
from fleet_policy import SiteBinding
from original312_v3_issuer import (
    OWNER_CONTRACT, OWNER_FIELDS, ISSUE_OP,
    PREDECESSOR_CONTRACT, PREDECESSOR_FIELDS, validate_predecessor,
)
from persistent_enrollment import (
    ENROLLMENT_CONTRACT, ENROLLMENT_FIELDS, DOMAINS, ConnectionDenied,
    canonical, digest, keys_independent, need, verify_signed,
)

INTENT_CONTRACT = "mad4b.original305.owner-v3-exact-stepup-consent.v1"
DOMAINS[INTENT_CONTRACT] = "MAD4B_ORIGINAL305_OWNER_V3_EXACT_STEPUP"
INTENT_FIELDS = (
    "contract", "company", "environment", "site_uuid",
    "target_host_id", "enrollment_sha256", "predecessor_sha256",
    "source_commit_sha", "operation_id", "request_id",
    "actor_digest_sha256", "intent_nonce", "decision",
    "issued_epoch", "expires_epoch",
)
SOURCE_HEX = re.compile(r"[0-9a-f]{40}\Z")
HEX32 = re.compile(r"[0-9a-f]{32}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")
LEDGER_CONTRACT = "mad4b.original305.owner-v3-approved-exact-request.v1"

def verify_inputs(unsigned:dict, predecessor:dict,
                  binding:SiteBinding, pinned313:bytes, *,now:int):
    need(type(now) is int and now>0, "OWNER305_SIGNING_CLOCK_INVALID")
    need(type(unsigned) is dict and set(unsigned)==set(ENROLLMENT_FIELDS)
         and unsigned.get("contract")==ENROLLMENT_CONTRACT
         and unsigned.get("mode")=="persistent"
         and unsigned.get("expires_epoch") is None
         and unsigned.get("state")=="ACTIVE",
         "OWNER305_EXACT_PERSISTENT_ENROLLMENT_REQUIRED")
    binding.assert_enrollment_matches(unsigned)
    validate_predecessor(predecessor,binding,pinned313,now)
    need(type(unsigned["revision"]) is int
         and unsigned["revision"]==predecessor["prior_revision"]+1
         and type(unsigned["issued_epoch"]) is int
         and now-30<=unsigned["issued_epoch"]<=now+5
         and type(unsigned["nonce"]) is str
         and HEX32.fullmatch(unsigned["nonce"]) is not None,
         "OWNER305_EXACT_REVISION_OR_FRESHNESS_REQUIRED")

def create_unsigned_owner_intent(unsigned:dict, predecessor:dict,
                                 binding:SiteBinding, *,
                                 pinned313:bytes,
                                 source_commit_sha:str,
                                 request_id:str, actor_digest_sha256:str,
                                 intent_nonce:str, now:int) ->dict:
    """A request for HUMAN owner review, no approval and no signature."""
    verify_inputs(unsigned,predecessor,binding,pinned313,now=now)
    need(type(source_commit_sha) is str and SOURCE_HEX.fullmatch(source_commit_sha)
         and type(request_id) is str and HEX32.fullmatch(request_id)
         and type(actor_digest_sha256) is str and HEX64.fullmatch(actor_digest_sha256)
         and type(intent_nonce) is str and HEX32.fullmatch(intent_nonce),
         "OWNER305_REVIEW_REQUEST_INVALID")
    return {
      "contract": INTENT_CONTRACT,
      "company":binding.company,"environment":binding.environment,
      "site_uuid":binding.site_uuid,"target_host_id":binding.host_id,
      "enrollment_sha256":digest(unsigned,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT),
      "predecessor_sha256":digest(
          predecessor,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT),
      "source_commit_sha":source_commit_sha,
      "operation_id":ISSUE_OP,"request_id":request_id,
      "actor_digest_sha256":actor_digest_sha256,
      "intent_nonce":intent_nonce,"decision":"APPROVE",
      "issued_epoch":now,"expires_epoch":now+180,
    }

def verify_exact_owner_stepup(intent:dict,unsigned:dict,
                              predecessor:dict,binding:SiteBinding,
                              pinned_owner_stepup_public:bytes,
                              original313_public:bytes, *,
                              expected_source_commit:str,now:int)->None:
    """Exact signed Owner step-up, not just existing WordPress authentication."""
    verify_inputs(unsigned,predecessor,binding,original313_public,now=now)
    verify_signed(intent,INTENT_FIELDS,INTENT_CONTRACT,
                  pinned_owner_stepup_public)
    expected=(
       ("company",binding.company),("environment",binding.environment),
       ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
       ("enrollment_sha256",digest(unsigned,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT)),
       ("predecessor_sha256",digest(
           predecessor,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT)),
       ("operation_id",ISSUE_OP),("source_commit_sha",expected_source_commit),
       ("decision","APPROVE"),
    )
    need(all(intent[k]==value for k,value in expected)
         and type(intent["source_commit_sha"]) is str
         and SOURCE_HEX.fullmatch(intent["source_commit_sha"]) is not None
         and type(intent["request_id"]) is str
         and HEX32.fullmatch(intent["request_id"]) is not None
         and type(intent["intent_nonce"]) is str
         and HEX32.fullmatch(intent["intent_nonce"]) is not None
         and type(intent["actor_digest_sha256"]) is str
         and HEX64.fullmatch(intent["actor_digest_sha256"]) is not None
         and type(intent["issued_epoch"]) is int
         and type(intent["expires_epoch"]) is int
         and now-180<=intent["issued_epoch"]<=now+5
         and intent["issued_epoch"]<intent["expires_epoch"]
         and now<intent["expires_epoch"]<=intent["issued_epoch"]+180,
         "OWNER305_EXACT_HUMAN_APPROVAL_INVALID")

def issue_approved_original305(*,unsigned:dict,predecessor:dict,
    binding:SiteBinding,signed_owner_stepup:dict,
    pinned_stepup_public:bytes,pinned_owner305_public:bytes,
    pinned_original312_public:bytes,pinned_original313_public:bytes,
    owner305_signer:object,approved_source_head:str,now:int)->dict:
    """Sign the exact Original305 OWNER_FIELDS. No ability to grant Write."""
    keys_independent(pinned_stepup_public,pinned_owner305_public,
                     pinned_original312_public,pinned_original313_public)
    need(callable(getattr(owner305_signer,"sign",None))
         and callable(getattr(owner305_signer,"public_key",None))
         and owner305_signer.public_key().public_bytes(
             Encoding.Raw,PublicFormat.Raw)==pinned_owner305_public,
         "ORIGINAL305_REGISTERED_SIGNER_REQUIRED")
    verify_exact_owner_stepup(
         signed_owner_stepup,unsigned,predecessor,binding,
         pinned_stepup_public,pinned_original313_public,
         expected_source_commit=approved_source_head,now=now)
    approval={
       "contract":OWNER_CONTRACT,
       "company":binding.company,"environment":binding.environment,
       "site_uuid":binding.site_uuid,"target_host_id":binding.host_id,
       "enrollment_sha256":digest(unsigned,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT),
       "predecessor_sha256":digest(
           predecessor,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT),
       "source_commit_sha":approved_source_head,
       "operation_id":ISSUE_OP,
       "request_id":signed_owner_stepup["request_id"],
       "approved_epoch":now,
       "state":"APPROVED",
    }
    approval["signature"]=base64.b64encode(owner305_signer.sign(
        canonical(approval,OWNER_FIELDS,OWNER_CONTRACT))).decode()
    verify_signed(approval,OWNER_FIELDS,OWNER_CONTRACT,pinned_owner305_public)
    return approval

def persist_approved_owner_request(root:Path, *,
                                   issue_callback,request_id:str,
                                   signed_owner_stepup:dict,
                                   pinned_owner_stepup_public:bytes,
                                   pinned_owner305_public:bytes):
    """Optional Root-only transaction journal with exclusive replay fencing.

    The trusted Root adapter must hold the independent signer and invoke
    issue_callback under this exclusive lock. A crash after the signed file
    write is fail-closed: the same request_id cannot be approved a second time.
    """
    need(os.geteuid()==0 and root.is_absolute() and root.is_dir()
         and not root.is_symlink(),
         "ORIGINAL305_PRIVATE_LEDGER_ROOT_REQUIRED")
    for part in (root,*root.parents):
        need(not part.is_symlink(),"ORIGINAL305_LEDGER_SYMLINK_ANCESTOR")
    meta=root.stat()
    need(meta.st_uid==0 and meta.st_gid==0
         and stat.S_IMODE(meta.st_mode)==0o700,
         "ORIGINAL305_PRIVATE_LEDGER_OWNER_INVALID")
    need(type(request_id) is str and HEX32.fullmatch(request_id) is not None
         and callable(issue_callback),"ORIGINAL305_REPLAY_ID_INVALID")
    lock_fd=os.open(root/".original305-v3.lock",
                   os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        fcntl.flock(lock_fd,fcntl.LOCK_EX)
        destination=root/(request_id+".json")
        need(not destination.exists() and not destination.is_symlink(),
             "ORIGINAL305_OWNER_REQUEST_ALREADY_CONSUMED")
        approval=issue_callback()
        need(type(approval) is dict
             and approval.get("request_id")==request_id
             and approval.get("contract")==OWNER_CONTRACT,
             "ORIGINAL305_ISSUER_RECEIPT_INVALID")
        # Never seal a claimed approval without verifying Original305's
        # independently pinned public key; an arbitrary callback is no grant.
        verify_signed(approval,OWNER_FIELDS,OWNER_CONTRACT,pinned_owner305_public)
        verify_signed(signed_owner_stepup,INTENT_FIELDS,INTENT_CONTRACT,
                      pinned_owner_stepup_public)
        need(signed_owner_stepup["request_id"]==request_id
             and signed_owner_stepup["decision"]=="APPROVE"
             and all(signed_owner_stepup[key]==approval[key]
                    for key in ("company","environment","site_uuid",
                                "target_host_id","enrollment_sha256",
                                "predecessor_sha256","source_commit_sha",
                                "operation_id")),
             "ORIGINAL305_OWNER_EVIDENCE_NOT_SAME_REQUEST")
        data=json.dumps(
            {"contract":LEDGER_CONTRACT,"request_id":request_id,
             "owner_stepup":signed_owner_stepup,"approval":approval},
             sort_keys=True,separators=(",",":"),
            ensure_ascii=False).encode()+b"\n"
        fd=os.open(destination,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,"wb") as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        directory=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
        try:os.fsync(directory)
        finally:os.close(directory)
        return approval
    finally:
        fcntl.flock(lock_fd,fcntl.LOCK_UN)
        os.close(lock_fd)
