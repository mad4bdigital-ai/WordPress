"""Original312 v3 signing contract; never a callable remote mutation endpoint.

Root runtime MUST supply pinned existing Original312, original owner and
independent Original313 public keys. A signed Owner v3 approval and a fresh
independently signed predecessor high-water are mandatory. This function is
NOT a replacement for Original306 claim, owner verification or Original328.
No filesystem/WordPress operations or credential loading here.
"""
from __future__ import annotations

import hashlib
import re
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
from persistent_enrollment import (
    ENROLLMENT_CONTRACT, ENROLLMENT_FIELDS, DOMAINS,
    ConnectionDenied, canonical, digest, keys_independent,
    need, verify_signed,
)
from fleet_policy import SiteBinding

OWNER_CONTRACT="mad4b.original305.host-enrollment-approval.v3"
PREDECESSOR_CONTRACT="mad4b.original313.host-predecessor-witness.v3"
DOMAINS[OWNER_CONTRACT]="MAD4B_HOST_OWNER_APPROVAL_V3"
DOMAINS[PREDECESSOR_CONTRACT]="MAD4B_HOST_PREDECESSOR_WITNESS_V3"
OWNER_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "enrollment_sha256","predecessor_sha256","source_commit_sha",
    "operation_id","request_id","approved_epoch","state",
)
PREDECESSOR_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "prior_revision","prior_state","prior_event_sha256",
    "observed_epoch",
)
ISSUE_OP="company.host-authority.enrollment.v3.issue"
PRIOR_STATES=frozenset(("NONE","ACTIVE","EXPIRED","REVOKED"))
HEX40=re.compile(r"[a-f0-9]{40}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")
HEX32=re.compile(r"[a-f0-9]{32}\Z")

def validate_predecessor(record: dict, binding: SiteBinding,
                         independent313_pub: bytes, now: int) -> None:
    verify_signed(record,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT,
                  independent313_pub)
    need(record["company"]==binding.company
         and record["environment"]==binding.environment
         and record["site_uuid"]==binding.site_uuid
         and record["target_host_id"]==binding.host_id,
         "PREDECESSOR_SITE_BINDING_MISMATCH")
    need(type(record["prior_revision"]) is int and record["prior_revision"]>=0
         and record["prior_state"] in PRIOR_STATES
         and (record["prior_state"]=="NONE")==(record["prior_revision"]==0)
         and type(record["prior_event_sha256"]) is str
         and HEX64.fullmatch(record["prior_event_sha256"]) is not None
         and (record["prior_event_sha256"]=="0"*64)==
             (record["prior_revision"]==0)
         and type(record["observed_epoch"]) is int
         and now-30<=record["observed_epoch"]<=now+5,
         "CURRENT_PREDECESSOR_PROOF_INVALID")

def issue_v3_enrollment(unsigned: dict, binding: SiteBinding,
                        owner_approval: dict, predecessor: dict,
                        original305_pub: bytes, original312_pub: bytes,
                        original313_pub: bytes, signer312: object, *,
                        now: int) -> dict:
    """Sign only an owner-approved fresh revision; no WordPress installation.

    Root original312 caller must itself verify original claim and supply
    independently retrieved current predecessor proof, not a user-provided
    copy. Original313 must independently recheck this just before mutation.
    """
    need(type(now) is int and now>0,"SIGNING_CLOCK_INVALID")
    keys_independent(original305_pub, original312_pub, original313_pub)
    need(callable(getattr(signer312,"sign",None)) and
         callable(getattr(signer312,"public_key",None)),
         "ORIGINAL312_SIGNER_UNAVAILABLE")
    need(signer312.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
         ==original312_pub,"ORIGINAL312_SIGNER_KEY_MISMATCH")
    need(type(unsigned) is dict and set(unsigned)==set(ENROLLMENT_FIELDS)
         and unsigned["contract"]==ENROLLMENT_CONTRACT
         and unsigned["state"]=="ACTIVE"
         and unsigned["expires_epoch"] is None,
         "NEW_V3_ENROLLMENT_EXACT_SHAPE_REQUIRED")
    binding.assert_enrollment_matches(unsigned)
    need(type(unsigned["revision"]) is int
         and type(unsigned["issued_epoch"]) is int
         and now-30<=unsigned["issued_epoch"]<=now+5,
         "NEW_ENROLLMENT_NOT_FRESH")
    need(type(unsigned["nonce"]) is str
         and HEX32.fullmatch(unsigned["nonce"]) is not None,
         "NEW_ENROLLMENT_NONCE_INVALID")
    validate_predecessor(predecessor,binding,original313_pub,now)
    need(unsigned["revision"]==predecessor["prior_revision"]+1,
         "NEW_ENROLLMENT_REVISION_NOT_NEXT")
    verify_signed(owner_approval,OWNER_FIELDS,OWNER_CONTRACT,original305_pub)
    need(owner_approval["state"]=="APPROVED"
         and owner_approval["operation_id"]==ISSUE_OP
         and all(owner_approval[f]==unsigned[f]
                 for f in ("company","environment","site_uuid","target_host_id"))
         and owner_approval["enrollment_sha256"]==
              digest(unsigned,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT)
         and owner_approval["predecessor_sha256"]==
              digest(predecessor,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT)
         and type(owner_approval["request_id"]) is str
         and HEX32.fullmatch(owner_approval["request_id"]) is not None
         and type(owner_approval["source_commit_sha"]) is str
         and HEX40.fullmatch(owner_approval["source_commit_sha"]) is not None
         and type(owner_approval["approved_epoch"]) is int
         and now-600<=owner_approval["approved_epoch"]<=now+5,
         "ORIGINAL305_OWNER_APPROVAL_INVALID")
    signed=dict(unsigned)
    import base64
    signed["signature"]=base64.b64encode(signer312.sign(
        canonical(signed,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT))).decode()
    verify_signed(signed,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT,original312_pub)
    return signed
