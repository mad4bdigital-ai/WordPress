#!/usr/bin/env python3
"""Original 312 first-anchor bootstrap proof, purpose-bound and non-authorizing.

Signer is the existing approved Broker; this module never loads private keys,
writes an Anchor, or substitutes a bootstrap proof for a normal 305/328 Grant.
Independent enrollment observations must come from the first-party reader.
"""
from __future__ import annotations
import base64
import re
import time
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

CONTRACT = "mad4b.original312.host-principal.bootstrap-issue.v1"
ISSUE = "company.host-authority.anchor.issue"
FIELDS = (
    "contract", "job_id", "operation_id", "company", "environment",
    "site_uuid", "origin", "target_host_id", "ssh_principal",
    "enrollment_id", "requested_anchor_revision", "command_sha256",
    "plan_sha256", "nonce", "issued_epoch", "expires_epoch",
)
HEX32 = re.compile(r"[0-9a-f]{32}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")
UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z")
COMPANY = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
ENV = re.compile(r"[a-z][a-z0-9_-]{1,39}\Z")
HOST = re.compile(r"hosting-[123]\Z")
SSH = re.compile(r"u[0-9]{9}\Z")

class BootstrapDenied(ValueError):
    pass

def require(ok, reason):
    if not ok:
        raise BootstrapDenied(reason)

def signed_message(proof):
    """Ed25519 message domain cannot be confused with a normal job grant."""
    return ("MAD4B_ORIGINAL312_FIRST_ANCHOR_V1\n"
            + "\n".join(str(proof[k]) for k in FIELDS)).encode("utf-8")

def verify_bootstrap_job(proof, broker_public_key, claimed_job, enrolled, *, now=None):
    """Verify independently issued bootstrap envelope; never grant execution.

    'enrolled' is a first-party reader product, not a field from a Job or plan.
    A positive return is a structural/cryptographic precondition ONLY.
    """
    instant = int(time.time()) if now is None else now
    require(type(proof) is dict and set(proof) == set(FIELDS) | {"signature"},
            "BOOTSTRAP_SIGNED_SHAPE_INVALID")
    require(type(claimed_job) is dict and type(enrolled) is dict,
            "BOOTSTRAP_INDEPENDENT_EVIDENCE_REQUIRED")
    require(proof["contract"] == CONTRACT and proof["operation_id"] == ISSUE,
            "BOOTSTRAP_PURPOSE_INVALID")
    for f in ("job_id", "nonce", "enrollment_id"):
        require(type(proof[f]) is str and HEX32.fullmatch(proof[f]) is not None,
                "BOOTSTRAP_ID_INVALID:" + f)
    for f in ("plan_sha256", "command_sha256"):
        require(type(proof[f]) is str and HEX64.fullmatch(proof[f]) is not None,
                "BOOTSTRAP_DIGEST_INVALID:" + f)
    for f, pattern in (("company", COMPANY), ("environment", ENV),
                       ("site_uuid", UUID), ("target_host_id", HOST),
                       ("ssh_principal", SSH)):
        require(type(proof[f]) is str and pattern.fullmatch(proof[f]) is not None,
                "BOOTSTRAP_SCOPE_INVALID:" + f)
    require(type(proof["origin"]) is str
            and re.fullmatch(r"https://[a-z0-9.-]{4,240}", proof["origin"]) is not None,
            "BOOTSTRAP_ORIGIN_INVALID")
    require(type(proof["requested_anchor_revision"]) is int
            and proof["requested_anchor_revision"] == 1,
            "BOOTSTRAP_FIRST_REVISION_REQUIRED")
    require(type(proof["issued_epoch"]) is int and type(proof["expires_epoch"]) is int
            and instant - 60 <= proof["issued_epoch"] <= instant + 10
            and instant < proof["expires_epoch"] <= proof["issued_epoch"] + 60,
            "BOOTSTRAP_PROOF_EXPIRED")
    require(type(broker_public_key) is bytes and len(broker_public_key) == 32,
            "BOOTSTRAP_BROKER_PUBLIC_KEY_INVALID")
    try:
        signature = base64.b64decode(proof["signature"], validate=True)
        require(len(signature) == 64, "BOOTSTRAP_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(broker_public_key).verify(
            signature, signed_message(proof))
    except (InvalidSignature, ValueError, TypeError) as exc:
        raise BootstrapDenied("BOOTSTRAP_BROKER_SIGNATURE_INVALID") from exc
    mapping = {
        "job_id": "job_id", "operation_id": "operation_id",
        "company": "company", "environment": "environment",
        "site_uuid": "site_uuid", "origin": "origin",
        "target_host_id": "target_host_id",
        "target_ssh_principal": "ssh_principal",
        "command_sha256": "command_sha256",
        "plan_sha256": "plan_sha256",
    }
    require(all(claimed_job.get(job_field) == proof[signed_field]
                for job_field, signed_field in mapping.items())
            and claimed_job.get("job_type") == "signed_remote_hostinger_wpcli_v2"
            and claimed_job.get("principal_type") == "signed_host_principal_v2"
            and claimed_job.get("state") == "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            and claimed_job.get("executed") is False,
            "BOOTSTRAP_ORIGINAL_JOB_CLAIM_DRIFT")
    for f in ("company", "environment", "site_uuid", "origin", "target_host_id",
              "ssh_principal", "enrollment_id"):
        require(enrolled.get(f) == proof[f], "BOOTSTRAP_ENROLLMENT_DRIFT:" + f)
    require(enrolled.get("contract") == "mad4b.original-host-enrollment-authority.v1"
            and enrolled.get("source") == "first_party_independent_enrollment_registry"
            and enrolled.get("signed_site_identity_verified") is True
            and enrolled.get("remote_host_identity_verified") is True
            and enrolled.get("independent_issuer_verified") is True
            and enrolled.get("owner_scope_authorized") is True
            and enrolled.get("enrollment_revoked") is False
            and enrolled.get("current_anchor_revision") == 0
            and enrolled.get("current_anchor_state") == "NOT_ENROLLED"
            and enrolled.get("nonce") == proof["nonce"]
            and enrolled.get("nonce_unspent") is True,
            "BOOTSTRAP_FIRST_PARTY_ENROLLMENT_REQUIRED")
    return {
        "contract": CONTRACT,
        "job_id": proof["job_id"],
        "state": "BOOTSTRAP_SIGNATURE_AND_ENROLLMENT_MATCHED__ISSUER_PENDING",
        "bootstrap_signature_verified": True,
        "regular_305_grant_used": False,
        "anchor_issued": False,
        "execution_authorized": False,
        "original313_certified": False,
    }
