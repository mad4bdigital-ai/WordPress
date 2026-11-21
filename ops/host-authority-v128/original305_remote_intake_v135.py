"""Verify quarantined WordPress remote Owner evidence in independent Root.

Pure source: NO private key, signer, authority publication or WordPress write.
The WordPress REST intake is a courier, NOT approval. The Root first-party
caller must still independently re-read the full Original313 predecessor,
verify enrolled Owner identity and run the already governed Original305/312
issuer before any signed Original318 connection can exist.
"""
from __future__ import annotations
import base64
import hashlib
import json
import re

from persistent_enrollment import (
    DOMAINS, canonical, keys_independent, need, verify_signed,
)
from original305_v3_owner_provider import INTENT_CONTRACT, INTENT_FIELDS

HANDOFF_CONTRACT="mad4b.original313.owner-remote-handoff.v1"
HANDOFF_FIELDS=(
 "contract","company","environment","site_uuid","target_host_id",
 "request_id","plan_sha256","predecessor_sha256","enrollment_sha256",
 "source_commit_sha","owner_stepup_public_b64","owner_stepup_enrollment_sha256",
 "original305_issuer_public_b64","original305_issuer_enrollment_sha256",
 "identity_authority_confirmed","monotonic_predecessor_verified",
 "issued_epoch","expires_epoch","state",
)
DOMAINS[HANDOFF_CONTRACT]="MAD4B_ORIGINAL313_OWNER_REMOTE_HANDOFF_V1"

HEX32=re.compile("[a-f0-9]{32}\\Z")
HEX40=re.compile("[a-f0-9]{40}\\Z")
HEX64=re.compile("[a-f0-9]{64}\\Z")
SITE_UUID="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
COMPANY="mad4b-central"
ENV="staging"
HOST="hostinger-primary"

def pub(value:str)->bytes:
    need(type(value) is str,"OWNER_REMOTE_PUBLIC_KEY_INVALID")
    try:
        result=base64.b64decode(value,validate=True)
    except (ValueError,TypeError) as error:
        from persistent_enrollment import ConnectionDenied
        raise ConnectionDenied("OWNER_REMOTE_PUBLIC_KEY_INVALID") from error
    need(len(result)==32,"OWNER_REMOTE_PUBLIC_KEY_INVALID")
    return result

def verify_owner_remote_transport(*,review:dict,original313_offer:dict,
    signed_owner_intent:dict,firstparty_index:dict,firstparty_master_policy:dict,
    now:int,expected_source_commit:str,expected_plan_sha256:str) ->dict:
    """Root verification ONLY: no grant, signer issue or predecessor invented."""
    need(type(now) is int and now>0 and type(review) is dict
         and type(original313_offer) is dict and type(signed_owner_intent) is dict
         and type(firstparty_index) is dict and type(firstparty_master_policy) is dict,
         "OWNER_REMOTE_INPUT_INVALID")
    need(firstparty_index.get("contract")=="mad4b.dynamic-operation-index.v1"
         and firstparty_index.get("installed_by_first_party") is True
         and firstparty_master_policy.get("contract")==
             "mad4b.native-source-publisher-policy.v2"
         and firstparty_master_policy.get("enabled") is True,
         "OWNER_REMOTE_FIRSTPARTY_PINS_INVALID")
    p312=pub(firstparty_index.get("host_resource_312_public_key_b64"))
    p313=pub(firstparty_index.get("independent_verifier_public_key_b64"))
    p305=pub(firstparty_master_policy.get("master_approval_public_key_b64"))
    stepup=pub(original313_offer.get("owner_stepup_public_b64"))
    keys_independent(p312,p313,p305,stepup)
    verify_signed(original313_offer,HANDOFF_FIELDS,HANDOFF_CONTRACT,p313)
    need(pub(original313_offer["original305_issuer_public_b64"])==p305,
         "OWNER_REMOTE_ORIGINAL305_ROOT_SIGNER_MISMATCH")
    need(type(review.get("request_id")) is str
         and HEX32.fullmatch(review["request_id"]) is not None
         and type(review.get("plan_sha256")) is str
         and HEX64.fullmatch(review["plan_sha256"]) is not None
         and review.get("contract")==
              "mad4b.original305.remote-review-request.v1"
         and review.get("state")=="AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR"
         and review.get("company")==COMPANY and review.get("environment")==ENV
         and review.get("site_uuid")==SITE_UUID and review.get("target_host_id")==HOST
         and type(review.get("created_epoch")) is int
         and type(review.get("expires_epoch")) is int
         and review["created_epoch"]<=now<=review["expires_epoch"],
         "OWNER_REMOTE_REQUEST_NOT_CURRENT")
    need(type(expected_source_commit) is str
         and HEX40.fullmatch(expected_source_commit) is not None
         and type(expected_plan_sha256) is str
         and HEX64.fullmatch(expected_plan_sha256) is not None
         and expected_plan_sha256==review["plan_sha256"]
         and original313_offer["request_id"]==review["request_id"]
         and original313_offer["plan_sha256"]==review["plan_sha256"]
         and original313_offer["company"]==COMPANY
         and original313_offer["environment"]==ENV
         and original313_offer["site_uuid"]==SITE_UUID
         and original313_offer["target_host_id"]==HOST
         and original313_offer["source_commit_sha"]==expected_source_commit
         and original313_offer["identity_authority_confirmed"] is True
         and original313_offer["monotonic_predecessor_verified"] is True
         and original313_offer["state"]=="READY_FOR_OWNER_STEPUP"
         and type(original313_offer["issued_epoch"]) is int
         and type(original313_offer["expires_epoch"]) is int
         and now-600<=original313_offer["issued_epoch"]<=now+5
         and now<=original313_offer["expires_epoch"]
         and original313_offer["expires_epoch"]-
                original313_offer["issued_epoch"]<=600
         and all(type(original313_offer[k]) is str
                 and HEX64.fullmatch(original313_offer[k]) is not None for k in
                 ("predecessor_sha256","enrollment_sha256",
                  "owner_stepup_enrollment_sha256",
                  "original305_issuer_enrollment_sha256")),
         "OWNER_REMOTE_SIGNED_OFFER_NOT_CURRENT")
    verify_signed(signed_owner_intent,INTENT_FIELDS,INTENT_CONTRACT,stepup)
    need(all(signed_owner_intent[k]==original313_offer[k] for k in (
          "company","environment","site_uuid","target_host_id",
          "enrollment_sha256","predecessor_sha256","source_commit_sha","request_id"))
         and signed_owner_intent["operation_id"]==
             "company.host-authority.enrollment.v3.issue"
         and signed_owner_intent["decision"]=="APPROVE"
         and type(signed_owner_intent["actor_digest_sha256"]) is str
         and HEX64.fullmatch(signed_owner_intent["actor_digest_sha256"]) is not None
         and type(signed_owner_intent["intent_nonce"]) is str
         and HEX32.fullmatch(signed_owner_intent["intent_nonce"]) is not None
         and type(signed_owner_intent["issued_epoch"]) is int
         and type(signed_owner_intent["expires_epoch"]) is int
         and now-180<=signed_owner_intent["issued_epoch"]<=now+5
         and now<signed_owner_intent["expires_epoch"]<=
             signed_owner_intent["issued_epoch"]+180,
         "OWNER_REMOTE_SIGNED_INTENT_CHANGED")
    return {
        "contract":"mad4b.original305.remote-owner-transport-root-verification.v1",
        "company":COMPANY,"environment":ENV,"site_uuid":SITE_UUID,
        "request_id":review["request_id"],
        "plan_sha256":review["plan_sha256"],
        "owner_signed_intent_sha256":hashlib.sha256(
            canonical(signed_owner_intent,INTENT_FIELDS,INTENT_CONTRACT)).hexdigest(),
        "original313_signed_offer_sha256":hashlib.sha256(
            canonical(original313_offer,HANDOFF_FIELDS,HANDOFF_CONTRACT)).hexdigest(),
        "state":"VERIFIED_TRANSPORT_ONLY_PENDING_ROOT_SIGNED_PREDECESSOR",
        "independent_firstparty_original313_full_ledger_read_required":True,
        "owner_identity_enrollment_independently_revalidate_required":True,
        "signed_original305_approval_issued":False,
        "signed_original312_v3_enrollment_issued":False,
        "host_write_authorized":False,
    }
