#!/usr/bin/env python3
"""Purpose-bound Original312 *first* Host Anchor signature producer.

No new key, Grant, Job, Authority Anchor, WordPress option or Original313
certificate is created. The only signing identity is the existing original
Broker signer, after an exact Original306 claimed Job and an independently
Ed25519-signed root enrollment *for that exact Job and first Anchor*.

Absent an owner-approved independent enrollment record or original signed Job,
the only result is HOLD. No CLI or REST endpoint is exposed by this module.
"""
from __future__ import annotations

import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import sys
import time

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

ENROLLMENT_ROOT = Path("/var/lib/mad4b/original-host-v2/enrollments")
REVISION_ROOT = Path("/var/lib/mad4b/original-host-v2/revisions")
ROOT_RESERVED = Path(
    "/opt/mad4b-company-transport/native-313-independent/reserved"
)
REGISTRY_ROOT = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
ORIGINAL_SIGNER_SOURCE = Path(
    "/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py"
)
CLASSIFIER_SOURCE = REGISTRY_ROOT/"host-anchor-v86/original306_typed_claim.py"
CLASSIFIER_SHA256 = "84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f"
BOOTSTRAP_SOURCE = REGISTRY_ROOT/"host-anchor-v2/bootstrap_signed_job.py"
BOOTSTRAP_SHA256 = "addef3e2693b5e87b2e8755f23bcc5d7fe8eeab0460640c1bc52e541340c61ed"
ORIGINAL_SIGNER_SHA256 = "ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"
CONTRACT = "mad4b.original313.host-first-enrollment-authorization.v1"
SOURCE = "first_party_independent_enrollment_registry"
PURPOSE = "MAD4B_ORIGINAL313_FIRST_HOST_ENROLLMENT_V1"
OPER = "company.host-authority.anchor.issue"
HEX32 = re.compile(r"[a-f0-9]{32}\Z")
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
SCOPENAME = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
ENVNAME = re.compile(r"[a-z][a-z0-9_-]{1,39}\Z")
UUID = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z")
SIGNED_FIELDS = (
    "contract", "source", "company", "environment", "site_uuid", "origin",
    "target_host_id", "ssh_principal", "enrollment_id", "nonce",
    "original306_job_id", "plan_sha256", "command_sha256",
    "current_anchor_revision", "current_anchor_state", "nonce_unspent",
    "signed_site_identity_verified", "remote_host_identity_verified",
    "independent_issuer_verified", "owner_scope_authorized",
    "enrollment_revoked", "issued_epoch", "expires_epoch",
)


class BootstrapIssuerHold(ValueError):
    pass


def need(value: bool, cause: str) -> None:
    if not value:
        raise BootstrapIssuerHold(cause)


def _canonical(values: dict) -> bytes:
    return json.dumps(
        values, sort_keys=True, separators=(",", ":"),
        ensure_ascii=False
    ).encode("utf-8")


def enrollment_message(document: dict) -> bytes:
    return PURPOSE.encode() + b"\n" + _canonical({
        name: document[name] for name in SIGNED_FIELDS
    })


def _root_file(path: Path, *, max_bytes=32768, mode=0o600) -> bytes:
    need(path.is_absolute() and not path.is_symlink(),
         "FIRST_ENROLLMENT_UNTRUSTED_PATH")
    need(path.parent.is_dir() and not path.parent.is_symlink(),
         "FIRST_ENROLLMENT_ROOT_NOT_ENROLLED")
    for target in (path.parent, path):
        meta = target.stat()
        need(meta.st_uid == 0 and meta.st_gid == 0
             and not meta.st_mode & 0o022,
             "FIRST_ENROLLMENT_OWNER_OR_MODE_INVALID")
    meta = path.stat()
    need(stat.S_ISREG(meta.st_mode) and meta.st_nlink == 1
         and stat.S_IMODE(meta.st_mode) == mode
         and 0 < meta.st_size < max_bytes,
         "FIRST_ENROLLMENT_FILE_INVALID")
    return path.read_bytes()


def read_first_party_enrollment(index, job: dict) -> dict:
    """One fixed root store; never accept caller-selected paths."""
    import registry
    need(type(index) is registry.Registry and index.root == REGISTRY_ROOT
         and os.geteuid() == 0, "FIRST_ENROLLMENT_ROOT_REGISTRY_REQUIRED")
    company, environment = job.get("company"), job.get("environment")
    need(type(company) is str and SCOPENAME.fullmatch(company) is not None
         and type(environment) is str and ENVNAME.fullmatch(environment) is not None,
         "FIRST_ENROLLMENT_SCOPE_INVALID")
    doc = json.loads(_root_file(
        ENROLLMENT_ROOT/(company+"--"+environment+".json")
    ))
    need(type(doc) is dict, "FIRST_ENROLLMENT_DOCUMENT_INVALID")
    return verify_enrollment(doc, index.independent_verifier_public_key, job)


def verify_enrollment(doc: dict, public: bytes, job: dict, *,
                      now: int | None = None) -> dict:
    """Independent Original313-signed owner/bootstrap enrollment precondition."""
    instant = int(time.time()) if now is None else now
    need(type(doc) is dict and set(doc) == set(SIGNED_FIELDS) | {"signature"}
         and type(job) is dict, "FIRST_ENROLLMENT_EXACT_SIGNED_FIELDS_REQUIRED")
    need(type(public) is bytes and len(public) == 32,
         "FIRST_ENROLLMENT_INDEPENDENT_313_KEY_REQUIRED")
    need(doc["contract"] == CONTRACT and doc["source"] == SOURCE
         and doc["current_anchor_revision"] == 0
         and doc["current_anchor_state"] == "NOT_ENROLLED"
         and doc["nonce_unspent"] is True
         and doc["enrollment_revoked"] is False
         and doc["signed_site_identity_verified"] is True
         and doc["remote_host_identity_verified"] is True
         and doc["independent_issuer_verified"] is True
         and doc["owner_scope_authorized"] is True,
         "FIRST_ENROLLMENT_INDEPENDENT_APPROVAL_REQUIRED")
    for key in ("enrollment_id", "nonce", "original306_job_id"):
        need(type(doc[key]) is str and HEX32.fullmatch(doc[key]) is not None,
             "FIRST_ENROLLMENT_ID_INVALID:" + key)
    for key in ("plan_sha256", "command_sha256"):
        need(type(doc[key]) is str and HEX64.fullmatch(doc[key]) is not None,
             "FIRST_ENROLLMENT_DIGEST_INVALID:" + key)
    need(type(doc["issued_epoch"]) is int
         and type(doc["expires_epoch"]) is int
         and instant-120 <= doc["issued_epoch"] <= instant+10
         and instant < doc["expires_epoch"] <= doc["issued_epoch"]+180,
         "FIRST_ENROLLMENT_APPROVAL_EXPIRED")
    for key, reference in (
        ("company", "company"), ("environment", "environment"),
        ("site_uuid", "site_uuid"), ("origin", "origin"),
        ("target_host_id", "target_host_id"),
        ("ssh_principal", "target_ssh_principal"),
        ("original306_job_id", "job_id"),
        ("plan_sha256", "plan_sha256"),
        ("command_sha256", "command_sha256"),
    ):
        need(doc[key] == job.get(reference),
             "FIRST_ENROLLMENT_ORIGINAL_JOB_SCOPE_CHANGED:" + key)
    need(job.get("operation_id") == OPER
         and job.get("job_type") == "signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type") == "signed_host_principal_v2"
         and job.get("state") ==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("executed") is False,
         "FIRST_ENROLLMENT_ORIGINAL306_SIGNED_JOB_REQUIRED")
    try:
        sig = base64.b64decode(doc["signature"], validate=True)
        need(len(sig) == 64, "FIRST_ENROLLMENT_SIGNATURE_LENGTH_INVALID")
        Ed25519PublicKey.from_public_bytes(public).verify(
            sig, enrollment_message(doc))
    except (ValueError, TypeError, InvalidSignature) as exc:
        raise BootstrapIssuerHold(
            "FIRST_ENROLLMENT_INDEPENDENT313_SIGNATURE_INVALID"
        ) from exc
    return {
        **{name: doc[name] for name in SIGNED_FIELDS},
        "job_id": doc["original306_job_id"],
        "independent_enrollment_signature_verified": True,
    }


def _pinned_module(path: Path, expected_sha256: str, *, mode=0o600):
    source = _root_file(path, mode=mode)
    need(hashlib.sha256(source).hexdigest() == expected_sha256,
         "ORIGINAL_SIGNED_SOURCE_DIGEST_DRIFT")
    spec = importlib.util.spec_from_file_location(
        "_host_first_anchor_"+path.stem, path)
    need(spec is not None and spec.loader is not None,
         "ORIGINAL_SIGNED_SOURCE_UNAVAILABLE")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def assemble_signed_bootstrap(
    index, job: dict, plan: dict, enrolled: dict, key, *, now: int
) -> dict:
    """Postverified proof assembly; caller MUST be the fixed root executor."""
    public = key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    need(public == index.host_anchor_312_public_key
         and public != index.independent_verifier_public_key
         and public != index.host_resource_312_public_key,
         "FIRST_ANCHOR_SIGNER_NOT_THE_PINNED_BROKER")
    need(enrolled["independent_enrollment_signature_verified"] is True,
         "FIRST_ANCHOR_INDEPENDENT_ENROLLMENT_REQUIRED")
    need(plan.get("authority_mode") == "bootstrap"
         and plan.get("requested_anchor_revision") == 1
         and plan.get("anchor_revision") == 0
         and plan.get("grant_revision") == 0
         and plan.get("enrollment_id") == enrolled["enrollment_id"]
         and plan.get("enrollment_nonce") == enrolled["nonce"]
         and plan.get("plan_sha256") == job["plan_sha256"],
         "FIRST_ANCHOR_EXACT_BOOTSTRAP_PLAN_REQUIRED")
    expiration = min(now+45, int(job["expires_epoch"])-10,
                     enrolled["expires_epoch"]-10)
    need(expiration >= now+20, "FIRST_ANCHOR_SIGNED_JOB_WINDOW_TOO_SHORT")
    bootstrap = _pinned_module(BOOTSTRAP_SOURCE, BOOTSTRAP_SHA256)
    proof = {
        "contract": bootstrap.CONTRACT,
        "job_id": job["job_id"],
        "operation_id": OPER,
        "company": job["company"],
        "environment": job["environment"],
        "site_uuid": job["site_uuid"],
        "origin": job["origin"],
        "target_host_id": job["target_host_id"],
        "ssh_principal": job["target_ssh_principal"],
        "enrollment_id": enrolled["enrollment_id"],
        "requested_anchor_revision": 1,
        "command_sha256": job["command_sha256"],
        "plan_sha256": job["plan_sha256"],
        "nonce": enrolled["nonce"],
        "issued_epoch": now,
        "expires_epoch": expiration,
    }
    need(set(proof) == set(bootstrap.FIELDS),
         "FIRST_ANCHOR_BOOTSTRAP_SIGNED_FIELDS_CHANGED")
    proof["signature"] = base64.b64encode(
        key.sign(bootstrap.signed_message(proof))
    ).decode()
    # Bridge the exact independently signed Original313 enrollment document
    # into the existing v81 Bootstrap reader contract. Its values were
    # already signature-verified; the reader contract is a projection, not
    # a forged additional signature or a live Anchor enrollment.
    first_party_readback = {
        **enrolled,
        "contract": "mad4b.original-host-enrollment-authority.v1",
    }
    verified = bootstrap.verify_bootstrap_job(
        proof, public, job, first_party_readback, now=now
    )
    need(verified.get("bootstrap_signature_verified") is True
         and verified.get("execution_authorized") is False
         and verified.get("anchor_issued") is False
         and verified.get("original313_certified") is False,
         "FIRST_ANCHOR_SIGNER_POSTSIGN_VERIFICATION_FAILED")
    return proof


def _original_first_anchor_unspent(job: dict) -> None:
    """Read-only anti-replay: never sign after any root revision/reservation."""
    company, env, job_id = (job.get("company"), job.get("environment"),
                            job.get("job_id"))
    need(type(company) is str and SCOPENAME.fullmatch(company) is not None
         and type(env) is str and ENVNAME.fullmatch(env) is not None
         and type(job_id) is str and HEX32.fullmatch(job_id) is not None,
         "FIRST_ANCHOR_ORIGINAL_SCOPE_OR_JOB_INVALID")
    path = REVISION_ROOT/(company+"--"+env+".json")
    if REVISION_ROOT.exists():
        need(REVISION_ROOT.is_dir() and not REVISION_ROOT.is_symlink()
             and REVISION_ROOT.stat().st_uid == 0
             and REVISION_ROOT.stat().st_gid == 0
             and not REVISION_ROOT.stat().st_mode & 0o077,
             "FIRST_ANCHOR_REVISION_ROOT_NOT_INDEPENDENT")
        need(not path.exists() and not path.is_symlink(),
             "FIRST_ANCHOR_ALREADY_HAS_INDEPENDENT_REVISION")
    marker = ROOT_RESERVED/(job_id+".reserved")
    need(not marker.exists() and not marker.is_symlink(),
         "FIRST_ANCHOR_ORIGINAL_JOB_ALREADY_RESERVED")


def sign_original306_first_anchor(index, plan: dict, original_job: dict,
                                  original_claim: dict,
                                  original_broker_row: dict) -> dict:
    """Root-only original signer. No unauthenticated CLI/API or mutation."""
    if str(REGISTRY_ROOT) not in sys.path:
        sys.path.insert(0, str(REGISTRY_ROOT))
    import registry
    need(os.geteuid() == 0 and type(index) is registry.Registry
         and index.root == REGISTRY_ROOT,
         "FIRST_ANCHOR_FIRST_PARTY_ROOT_REQUIRED")
    classification = _pinned_module(CLASSIFIER_SOURCE, CLASSIFIER_SHA256)
    status = classification.inspect_claimed_original(
        index, original_job, original_claim, original_broker_row, plan)
    need(status.get("original306_claim_matched") is True
         and status.get("execution_authorized") is False,
         "FIRST_ANCHOR_ORIGINAL306_CLAIM_NOT_VERIFIED")
    enrolled = read_first_party_enrollment(index, original_job)
    _original_first_anchor_unspent(original_job)
    signer = _pinned_module(
        ORIGINAL_SIGNER_SOURCE, ORIGINAL_SIGNER_SHA256, mode=0o700
    )
    # Original signer is verified against the currently installed Central WP
    # Broker public key by its own first-party verifier, never job supplied.
    key = signer.original_signer()
    signer.check_original_wordpress_signer(key)
    return assemble_signed_bootstrap(
        index, original_job, plan, enrolled, key, now=int(time.time())
    )
