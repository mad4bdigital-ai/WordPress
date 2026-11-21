"""Independent Original313 v3 high-water signatures, root-only source layer.

Real signed evidence is prepared only from pinned first-party root readers.
No signatures/keys are installed into WordPress, no network, no CLI entry,
no owner auto-approval, and no production write authority.
"""
from __future__ import annotations

import base64
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

from fleet_policy import SiteBinding
from persistent_enrollment import (
    CHECKPOINT_CONTRACT, CHECKPOINT_FIELDS, ENROLLMENT_CONTRACT,
    ENROLLMENT_FIELDS, DOMAINS, canonical, digest, keys_independent,
    need, verify_signed,
)
from original312_v3_issuer import (
    OWNER_CONTRACT, OWNER_FIELDS, PREDECESSOR_CONTRACT,
    PREDECESSOR_FIELDS, validate_predecessor,
)

ROOT_INSTALL_SNAPSHOT = "original318_verified_installed_v3_enrollment"
ROOT_LEDGER_SNAPSHOT = "original313_independent_current_ledger"
ROOT_PREDECESSOR_SNAPSHOT = "original313_verified_predecessor_ledger"

def check_signer(signer, expected_pub: bytes, code: str):
    need(callable(getattr(signer,"sign",None))
         and callable(getattr(signer,"public_key",None))
         and signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
             == expected_pub,code)

def verify_callback(reader, binding: SiteBinding, source: str, now: int) -> dict:
    """Call the exact root-installed reader. Caller MUST pin its source SHA.

    This code never accepts a caller-supplied snapshot argument; the fresh
    reader callback is invoked during the signing transaction.
    """
    need(callable(reader), "ROOT_FIRST_PARTY_READER_REQUIRED")
    row=reader(binding)
    need(type(row) is dict
         and row.get("source")==source
         and row.get("company")==binding.company
         and row.get("environment")==binding.environment
         and row.get("site_uuid")==binding.site_uuid
         and row.get("target_host_id")==binding.host_id
         and row.get("read_only") is True
         and row.get("original_signatures_verified") is True
         and type(row.get("observed_epoch")) is int
         and now-15<=row["observed_epoch"]<=now+5,
         "ROOT_CURRENT_READER_INVALID")
    return row

def sign_current_predecessor(binding: SiteBinding, reader313,
                             independent313_signer,
                             independent313_pub: bytes, *, now: int) -> dict:
    """Provide a fresh, signer-independent Root predecessor witness.

    The Root reader, not a WordPress option or supplied untrusted JSON,
    must establish highest revision (including historical expired v2).
    """
    check_signer(independent313_signer, independent313_pub,
                 "ORIGINAL313_SIGNER_KEY_MISMATCH")
    current=verify_callback(reader313,binding,ROOT_PREDECESSOR_SNAPSHOT,now)
    revision=current.get("revision")
    state=current.get("state")
    event=current.get("event_sha256")
    need(type(revision) is int and revision>=0
         and state in ("NONE","ACTIVE","EXPIRED","REVOKED")
         and (state=="NONE")==(revision==0)
         and type(event) is str and len(event)==64
         and all(c in "0123456789abcdef" for c in event)
         and (event=="0"*64)==(revision==0)
         and current.get("monotonic_chain_verified") is True,
         "ORIGINAL313_PREDECESSOR_ROOT_NOT_CURRENT")
    row={
        "contract":PREDECESSOR_CONTRACT,
        "company":binding.company,
        "environment":binding.environment,
        "site_uuid":binding.site_uuid,
        "target_host_id":binding.host_id,
        "prior_revision":revision,
        "prior_state":state,
        "prior_event_sha256":event,
        "observed_epoch":current["observed_epoch"],
    }
    row["signature"]=base64.b64encode(independent313_signer.sign(
        canonical(row,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT))).decode()
    validate_predecessor(row,binding,independent313_pub,now)
    return row

def sign_installed_checkpoint(enrollment: dict,
                              binding: SiteBinding,
                              owner_approval: dict,
                              predecessor: dict,
                              installed_reader318,
                              current_root_reader313,
                              signer313,
                              original305_pub: bytes,
                              original312_pub: bytes,
                              original313_pub: bytes, *,
                              now: int) -> dict:
    """Sign an independent, fresh ACTIVE no-operation current checkpoint.

    For an active job a distinct signed operation/activity transition is
    needed (this method cannot authorize or silently inherit a running job).
    """
    keys_independent(original305_pub,original312_pub,original313_pub)
    check_signer(signer313,original313_pub,"ORIGINAL313_SIGNER_KEY_MISMATCH")
    verify_signed(enrollment,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT,
                  original312_pub)
    binding.assert_enrollment_matches(enrollment)
    need(enrollment["state"]=="ACTIVE" and enrollment["expires_epoch"] is None
         and type(enrollment["revision"]) is int
         and type(enrollment["issued_epoch"]) is int
         and 0<enrollment["issued_epoch"]<=now+5,
         "INSTALLED_ENROLLMENT_NOT_CURRENT")
    validate_predecessor(predecessor,binding,original313_pub,now)
    need(enrollment["revision"]==predecessor["prior_revision"]+1,
         "ORIGINAL313_MONOTONIC_REVISION_FAILED")
    verify_signed(owner_approval,OWNER_FIELDS,OWNER_CONTRACT,original305_pub)
    from original312_v3_issuer import ISSUE_OP, HEX32, HEX40
    need(owner_approval.get("state")=="APPROVED"
         and owner_approval.get("operation_id")==ISSUE_OP
         and type(owner_approval.get("request_id")) is str
         and HEX32.fullmatch(owner_approval["request_id"]) is not None
         and type(owner_approval.get("source_commit_sha")) is str
         and HEX40.fullmatch(owner_approval["source_commit_sha"]) is not None
         and owner_approval.get("enrollment_sha256")==
             digest(enrollment,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT)
         and owner_approval.get("predecessor_sha256")==
             digest(predecessor,PREDECESSOR_FIELDS,PREDECESSOR_CONTRACT)
         and all(owner_approval.get(x)==enrollment[x]
                 for x in ("company","environment","site_uuid","target_host_id"))
         and type(owner_approval.get("approved_epoch")) is int
         and now-600 <= owner_approval["approved_epoch"] <= now+5,
         "ORIGINAL313_OWNER_APPROVAL_CHANGED")
    installed=verify_callback(installed_reader318,binding,
                              ROOT_INSTALL_SNAPSHOT,now)
    ledger=verify_callback(current_root_reader313,binding,
                           ROOT_LEDGER_SNAPSHOT,now)
    enrollment_sha=digest(enrollment,ENROLLMENT_FIELDS,ENROLLMENT_CONTRACT)
    need(installed.get("enrollment_sha256")==enrollment_sha
         and installed.get("revision")==enrollment["revision"]
         and installed.get("original312_signature_verified") is True
         and installed.get("write_authorized") is False,
         "ORIGINAL318_INSTALLED_V3_READBACK_INVALID")
    need(ledger.get("revision")==enrollment["revision"]
         and ledger.get("enrollment_sha256")==enrollment_sha
         and ledger.get("state")=="ACTIVE"
         and ledger.get("monotonic_chain_verified") is True,
         "ORIGINAL313_HIGHWATER_NOT_CURRENT")
    row={
        "contract":CHECKPOINT_CONTRACT,
        "company":binding.company,
        "environment":binding.environment,
        "site_uuid":binding.site_uuid,
        "revision":enrollment["revision"],
        "enrollment_sha256":enrollment_sha,
        "state":"ACTIVE",
        "operation_id":None,
        "activity_sha256":None,
        "activity_seq":None,
        "observed_epoch":now,
    }
    row["signature"]=base64.b64encode(signer313.sign(
        canonical(row,CHECKPOINT_FIELDS,CHECKPOINT_CONTRACT))).decode()
    verify_signed(row,CHECKPOINT_FIELDS,CHECKPOINT_CONTRACT,
                  original313_pub)
    return row
