"""Indefinite *entitlement* for tightly scoped Host Write, never broad access.

Default is persistent capability for NEW, separately signed site enrollments.
Every mutation still requires a signed exact operation, active heartbeat,
fresh independent 313 high-water, currently installed 318 readback and an
independent verified preimage/rollback from live-first reconciliation.
"""
from __future__ import annotations
import base64
import hashlib
import json
import re
from pathlib import Path
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

from fleet_policy import SiteBinding
from persistent_enrollment import (
    ConnectionDenied, keys_independent, need, verify_operation
)

DEFAULT_WRITE_MODE = "persistent"
WRITE_DISABLED = "disabled"
WRITE_MODES = frozenset((DEFAULT_WRITE_MODE,WRITE_DISABLED))
CAPABILITY_CONTRACT = "mad4b.original305.standing-host-write.v130"
HIGHWATER_CONTRACT = "mad4b.original313.standing-host-write-highwater.v130"
CAPABILITY_DOMAIN = b"MAD4B_SIGNED_STANDING_HOST_WRITE_V130\n"
HIGHWATER_DOMAIN = b"MAD4B_SIGNED_STANDING_WRITE_HIGHWATER_V130\n"
CAPABILITY_FIELDS = (
    "contract","company","environment","site_uuid","target_host_id",
    "host_key_sha256","policy_sha256","revision","write_mode",
    "allowed_actions","enabled_by_default","issued_epoch","nonce",
    "expires_epoch","state","require_exact_operation_grant",
    "require_verified_preimage_backup","require_postwrite_readback",
)
HIGHWATER_FIELDS = (
    "contract","company","environment","site_uuid","target_host_id",
    "revision","capability_sha256","state","observed_epoch","nonce",
)
ACTIONS = frozenset(("site.code.atomic.install","site.code.atomic.rollback"))
PLAN_CONTRACT="mad4b.host-v130.signed-atomic-write-plan.v1"
PLAN_DOMAIN=b"MAD4B_SIGNED_ATOMIC_WRITE_PLAN_V130\n"
PLAN_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "operation_id","action","plan","observed_epoch","nonce",
)
PRODUCTION_CONTRACT="mad4b.original305.production-standing-write-consent.v130"
PRODUCTION_DOMAIN=b"MAD4B_PRODUCTION_STANDING_WRITE_CONSENT_V130\n"
PRODUCTION_FIELDS=(
    "contract","company","environment","site_uuid","target_host_id",
    "revision","capability_sha256","approved_epoch","nonce","state",
)
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
DEFAULTS_PATH = Path(__file__).with_name("standing_write_policy.json")

def canonical(row,fields,domain):
    return domain + json.dumps(
        {k:row[k] for k in fields},sort_keys=True,
        separators=(",",":"),ensure_ascii=False).encode()

def digest(row,fields,domain):
    return hashlib.sha256(canonical(row,fields,domain)).hexdigest()

def _verify(row,fields,contract,domain,public):
    need(type(row) is dict and set(row)==set(fields)|{"signature"}
         and row.get("contract")==contract,
         "STANDING_WRITE_SIGNED_SHAPE_INVALID")
    need(type(public) is bytes and len(public)==32,
         "STANDING_WRITE_PINNED_SIGNER_REQUIRED")
    need(type(row.get("signature")) is str,
         "STANDING_WRITE_SIGNATURE_INVALID")
    try:
        signature=base64.b64decode(row["signature"],validate=True)
        need(len(signature)==64,"STANDING_WRITE_SIGNATURE_INVALID")
        Ed25519PublicKey.from_public_bytes(public).verify(
            signature,canonical(row,fields,domain))
    except (ValueError,TypeError,KeyError,InvalidSignature) as error:
        raise ConnectionDenied("STANDING_WRITE_SIGNATURE_INVALID") from error

def default_policy():
    """Policy is an unsigned *preference*, never an authorization receipt."""
    row=json.loads(DEFAULTS_PATH.read_text())
    expected={"contract","default_connection_mode","default_write_mode",
              "default_write_enabled_after_signed_enrollment",
              "write_entitlement_expires_epoch","supported_actions",
              "independent_highwater_required","exact_operation_grant_required",
              "live_current_preimage_required","verified_private_rollback_required",
              "postwrite_live_readback_required",
              "production_requires_independent_authorization",
              "site_override_can_disable","auto_activate_unsigned_grants",
              "auto_publish_code"}
    need(set(row)==expected
         and row["contract"]=="mad4b.host-v130.standing-write-defaults.v1"
         and row["default_connection_mode"]=="persistent"
         and row["default_write_mode"]==DEFAULT_WRITE_MODE
         and row["default_write_enabled_after_signed_enrollment"] is True
         and row["write_entitlement_expires_epoch"] is None
         and row["supported_actions"]==sorted(ACTIONS)
         and all(row[k] is True for k in
           ("independent_highwater_required","exact_operation_grant_required",
            "live_current_preimage_required","verified_private_rollback_required",
            "postwrite_live_readback_required",
            "production_requires_independent_authorization",
            "site_override_can_disable"))
         and row["auto_activate_unsigned_grants"] is False
         and row["auto_publish_code"] is False,
         "STANDING_WRITE_DEFAULTS_INSECURE")
    return row

def new_capability_proposal(binding:SiteBinding,revision:int,nonce:str,issued_epoch:int):
    """Default ON for new enrolled sites, but this is unsigned source only."""
    default_policy()
    need(type(revision) is int and revision>=1
         and type(issued_epoch) is int and issued_epoch>0
         and type(nonce) is str and HEX32.fullmatch(nonce) is not None,
         "STANDING_WRITE_PROPOSAL_INVALID")
    return {
        "contract":CAPABILITY_CONTRACT,
        "company":binding.company,"environment":binding.environment,
        "site_uuid":binding.site_uuid,"target_host_id":binding.host_id,
        "host_key_sha256":binding.host_key_sha256,
        "policy_sha256":binding.policy_sha256,
        "revision":revision,"write_mode":DEFAULT_WRITE_MODE,
        "allowed_actions":sorted(ACTIONS),"enabled_by_default":True,
        "issued_epoch":issued_epoch,"nonce":nonce,"expires_epoch":None,
        "state":"ACTIVE","require_exact_operation_grant":True,
        "require_verified_preimage_backup":True,
        "require_postwrite_readback":True,
    }

def verify_standing_write(capability:dict,independent_highwater:dict,
                          binding:SiteBinding,
                          pinned_owner305:bytes,pinned_313:bytes, *,
                          now:int, production_receipt:dict|None=None):
    """Signed standing capability without expiry; fresh high-water each use."""
    default_policy()
    keys_independent(pinned_owner305,pinned_313)
    _verify(capability,CAPABILITY_FIELDS,CAPABILITY_CONTRACT,
            CAPABILITY_DOMAIN,pinned_owner305)
    _verify(independent_highwater,HIGHWATER_FIELDS,HIGHWATER_CONTRACT,
            HIGHWATER_DOMAIN,pinned_313)
    need(all(capability[k]==value for k,value in (
        ("company",binding.company),("environment",binding.environment),
        ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
        ("host_key_sha256",binding.host_key_sha256),
        ("policy_sha256",binding.policy_sha256))),
        "STANDING_WRITE_SITE_BINDING_DRIFT")
    need(type(capability["revision"]) is int
         and capability["revision"]>=1
         and type(capability["issued_epoch"]) is int
         and 0<capability["issued_epoch"]<=now+5
         and type(capability["nonce"]) is str
         and HEX32.fullmatch(capability["nonce"]) is not None
         and capability["write_mode"]==DEFAULT_WRITE_MODE
         and capability["expires_epoch"] is None
         and capability["state"]=="ACTIVE"
         and capability["enabled_by_default"] is True
         and capability["allowed_actions"]==sorted(ACTIONS)
         and all(capability[k] is True for k in (
           "require_exact_operation_grant","require_verified_preimage_backup",
           "require_postwrite_readback")),
        "STANDING_WRITE_ENTITLEMENT_INVALID")
    need(all(independent_highwater[k]==value for k,value in (
        ("company",binding.company),("environment",binding.environment),
        ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
        ("revision",capability["revision"]),
        ("capability_sha256",digest(capability,CAPABILITY_FIELDS,CAPABILITY_DOMAIN)))),
        "STANDING_WRITE_HIGHWATER_MISMATCH")
    need(independent_highwater["state"]=="ACTIVE"
         and type(independent_highwater["observed_epoch"]) is int
         and now-30<=independent_highwater["observed_epoch"]<=now+5
         and type(independent_highwater["nonce"]) is str
         and HEX32.fullmatch(independent_highwater["nonce"]) is not None,
         "STANDING_WRITE_REVOKED_OR_STALE")
    if binding.environment=="production":
        need(production_receipt is not None,
             "PRODUCTION_WRITE_REQUIRES_SEPARATE_APPROVAL")
        _verify(production_receipt,PRODUCTION_FIELDS,PRODUCTION_CONTRACT,
                PRODUCTION_DOMAIN,pinned_owner305)
        need(all(production_receipt.get(k)==value for k,value in (
             ("company",binding.company),("environment",binding.environment),
             ("site_uuid",binding.site_uuid),
             ("target_host_id",binding.host_id),
             ("revision",capability["revision"]),
             ("capability_sha256",digest(capability,CAPABILITY_FIELDS,
                                          CAPABILITY_DOMAIN))))
             and production_receipt.get("state")=="APPROVED"
             and type(production_receipt.get("approved_epoch")) is int
             and capability["issued_epoch"]<=production_receipt["approved_epoch"]<=now+5
             and type(production_receipt.get("nonce")) is str
             and HEX32.fullmatch(production_receipt["nonce"]) is not None,
             "PRODUCTION_WRITE_APPROVAL_SCOPE_INVALID")
    return {
       "contract":"mad4b.host-v130.standing-write-readiness.v1",
       "mode":DEFAULT_WRITE_MODE,
       "entitlement_expires_epoch":None,
       "allowed_actions":sorted(ACTIONS),
       "default_enabled_for_signed_enrollments":True,
       "standing_capability_verified":True,
       "specific_write_authorized":False,
       "broad_host_write_authorized":False,
       "mutation_performed":False,
    }

def admit_exact_standing_write(capability,highwater,binding,
                               pinned_owner305,pinned_313,
                               enrollment,connection_highwater,wp,
                               operation_grant,operation_activity,
                               pinned_312,pinned_operation_grant,
                               pinned_activity,
                               signed_plan,pinned_plan_signer,
                               requested_action, *,
                               now,production_receipt=None):
    """Require both standing permission AND a newly validated exact job.

    Source-only authorization check; the first-party executor separately
    verifies signed previous backup, live file CAS and post-write readback.
    """
    standing=verify_standing_write(
        capability,highwater,binding,pinned_owner305,pinned_313,
        now=now,production_receipt=production_receipt)
    need(requested_action in ACTIONS,"STANDING_WRITE_ACTION_NOT_ALLOWED")
    keys_independent(pinned_owner305,pinned_313,pinned_312,
                     pinned_operation_grant,pinned_activity,pinned_plan_signer)
    _verify(signed_plan,PLAN_FIELDS,PLAN_CONTRACT,
            PLAN_DOMAIN,pinned_plan_signer)
    need(all(signed_plan.get(k)==v for k,v in (
         ("company",binding.company),("environment",binding.environment),
         ("site_uuid",binding.site_uuid),("target_host_id",binding.host_id),
         ("operation_id",operation_grant.get("operation_id")),
         ("action",requested_action)))
         and type(signed_plan.get("observed_epoch")) is int
         and now-30<=signed_plan["observed_epoch"]<=now+5
         and type(signed_plan.get("nonce")) is str
         and HEX32.fullmatch(signed_plan["nonce"]) is not None,
         "STANDING_WRITE_SIGNED_PLAN_SCOPE_OR_FRESHNESS_INVALID")
    operation_plan=signed_plan["plan"]
    need(type(operation_plan) is dict
         and operation_plan.get("contract")==
             "mad4b.host-v129.atomic-group-execution-proposal.v1"
         and operation_plan.get("site_uuid")==binding.site_uuid
         and operation_plan.get("company")==binding.company
         and operation_plan.get("environment")==binding.environment
         and operation_plan.get("candidate_exact") is True
         and operation_plan.get("previous",{}).get("verified") is True
         and operation_plan.get("executor_must_recheck_live_preimage_at_commit") is True
         and operation_plan.get("exact_owner_or_operator_signed_grant_required") is True
         and operation_plan.get("operation_executed") is False
         and operation_plan.get("host_write_authorized") is False,
         "STANDING_WRITE_VERIFIED_ATOMIC_PLAN_REQUIRED")
    op=verify_operation(
        enrollment,connection_highwater,wp,
        operation_grant,operation_activity,
        pinned_312,pinned_313,pinned_operation_grant,pinned_activity,
        requested_action,now=now)
    need(enrollment["revision"]==capability["revision"]
         and enrollment["mode"]=="persistent"
         and enrollment["expires_epoch"] is None,
         "STANDING_WRITE_ENROLLMENT_NOT_PERSISTENT")
    return {
       **standing,
       "state":"EXACT_STANDING_WRITE_ADMITTED",
       "operation_id":op["operation_id"],
       "operation_seq":op["operation_seq"],
       "action":requested_action,
       "specific_write_authorized":True,
       "broad_host_write_authorized":False,
       "commit_time_cas_required":True,
       "postwrite_readback_required":True,
       "mutation_performed":False,
    }
