#!/usr/bin/env python3
"""Read-only first-party plan -> operation association contract candidate.

Original CAS plans remain BYTE-FOR-BYTE unchanged. An independently signed
association selects exactly one SHA-pinned Original306/313 operation for a
given plan. Verification only; no signing, issuing, claiming or executing.

NOT integrated with the installed worker: future first-party worker admission
must require this verified association BEFORE command indexing/dispatch.
"""
from __future__ import annotations

import base64
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import stat
from typing import Any

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

CONTRACT = "mad4b.original306.source-plan-association.v1"
SIGN_DOMAIN = b"MAD4B_SOURCE_PLAN_OPERATION_ASSOCIATION_V1\n"
HEX_SHA = re.compile(r"^[0-9a-f]{64}$")
OP = re.compile(r"^[a-z][a-z0-9_.-]{2,90}$")
MAX_LIFETIME_SECONDS = 600
FIELDS = frozenset((
    "contract", "issuer", "nonce", "plan_sha256", "operation_id",
    "manifest_sha256", "provider", "approval_ability", "company",
    "environment", "site_uuid", "origin", "issued_epoch", "expires_epoch",
    "signature",
))


class AssociationDenied(ValueError):
    pass


def canonical(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")


def digest(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def signed_payload(record: dict) -> bytes:
    if not isinstance(record, dict):
        raise AssociationDenied("ASSOCIATION_RECORD_INVALID")
    return SIGN_DOMAIN + canonical({
        key: value for key, value in record.items() if key != "signature"
    })


def verify_association(
    plan: dict,
    manifest: dict,
    record: dict,
    issuer_public_key_b64: str,
    *,
    now: int | None = None,
) -> dict:
    """Validate trusted Ed25519 association, without granting authority.

    Manifest must originate from verified root-owned Registry.resolve.
    Plan must be the original CAS document, not a modified envelope.
    The separate original 306/312 signed claim is still mandatory.
    """
    if not isinstance(plan, dict) or not isinstance(manifest, dict):
        raise AssociationDenied("ORIGINAL_CAS_PLAN_AND_MANIFEST_REQUIRED")
    if "source_binding" in plan:
        raise AssociationDenied("ASSOCIATION_MUST_NOT_MUTATE_ORIGINAL_PLAN")
    if not isinstance(record, dict) or set(record) != FIELDS:
        raise AssociationDenied("ASSOCIATION_FIELDS_NOT_EXACT")
    plan_id = plan.get("plan_sha256")
    if not isinstance(plan_id, str) or not HEX_SHA.fullmatch(plan_id):
        raise AssociationDenied("ASSOCIATION_PLAN_ID_INVALID")
    payload = {k: v for k, v in plan.items()
               if k not in ("plan_sha256", "changed_count")}
    if digest(canonical(payload)) != plan_id:
        raise AssociationDenied("ASSOCIATION_CORE_PLAN_DRIFT")
    manifest_hash = digest(canonical(manifest))
    if not isinstance(record["manifest_sha256"], str) or not HEX_SHA.fullmatch(record["manifest_sha256"]):
        raise AssociationDenied("ASSOCIATION_MANIFEST_DIGEST_INVALID")
    expected = {
        "contract": CONTRACT,
        "issuer": "mad4b-first-party-plan-association",
        "plan_sha256": plan_id,
        "operation_id": manifest.get("operation_id"),
        "manifest_sha256": manifest_hash,
        "provider": manifest.get("provider"),
        "approval_ability": manifest.get("approval_ability"),
        "company": plan.get("company"),
        "environment": plan.get("environment"),
        "site_uuid": plan.get("site_uuid"),
        "origin": plan.get("origin"),
    }
    if any(record.get(key) != value for key, value in expected.items()):
        raise AssociationDenied("ASSOCIATION_OPERATION_MANIFEST_SCOPE_MISMATCH")
    if not isinstance(record["operation_id"], str) or not OP.fullmatch(record["operation_id"]):
        raise AssociationDenied("ASSOCIATION_OPERATION_ID_INVALID")
    if not isinstance(record["nonce"], str) or not HEX_SHA.fullmatch(record["nonce"]):
        raise AssociationDenied("ASSOCIATION_NONCE_INVALID")
    issued, expires = record["issued_epoch"], record["expires_epoch"]
    clock = int(datetime.now(timezone.utc).timestamp()) if now is None else now
    if (type(issued) is not int or type(expires) is not int or
        type(clock) is not int or issued > clock + 10 or
        expires - issued > MAX_LIFETIME_SECONDS or expires <= issued):
        raise AssociationDenied("ASSOCIATION_TIME_INVALID")
    try:
        raw_key = base64.b64decode(issuer_public_key_b64, validate=True)
        raw_signature = base64.b64decode(record["signature"], validate=True)
        if len(raw_key) != 32 or len(raw_signature) != 64:
            raise ValueError("invalid Ed25519 key/signature length")
        Ed25519PublicKey.from_public_bytes(raw_key).verify(
            raw_signature, signed_payload(record)
        )
    except (ValueError, TypeError) as exc:
        raise AssociationDenied("ASSOCIATION_TRUST_ANCHOR_INVALID") from exc
    except Exception as exc:
        raise AssociationDenied("ASSOCIATION_INDEPENDENT_SIGNATURE_INVALID") from exc
    # Only an authentic, structurally valid signed record can be classified
    # as expired and omitted from the candidate command index. A modified
    # expiry on an unsigned/tampered record must never conceal forgery.
    if clock >= expires:
        raise AssociationDenied("ASSOCIATION_EXPIRED")
    return {
        "contract": CONTRACT,
        "state": "SIGNED_PLAN_ASSOCIATION_VERIFIED_ONLY",
        "plan_sha256": plan_id,
        "operation_id": record["operation_id"],
        "manifest_sha256": manifest_hash,
        "site_uuid": plan["site_uuid"],
        "issuer": record["issuer"],
        "job_claimed": False,
        "authority_issued": False,
        "original313_signed_receipt_issued": False,
    }


def protected_association(root: Path, plan_sha256: str) -> dict:
    """Root-private readback. Never creates or changes association records."""
    directory = Path(root)
    if (not directory.is_absolute() or directory.is_symlink() or
        not directory.is_dir() or directory.stat().st_uid != 0 or
        stat.S_IMODE(directory.stat().st_mode) & 0o077):
        raise AssociationDenied("ASSOCIATION_FIRST_PARTY_STORE_UNTRUSTED")
    if not isinstance(plan_sha256, str) or not HEX_SHA.fullmatch(plan_sha256):
        raise AssociationDenied("ASSOCIATION_PLAN_ID_INVALID")
    path = directory / (plan_sha256 + ".json")
    if path.is_symlink() or not path.is_file():
        raise AssociationDenied("ASSOCIATION_FILE_NOT_ENROLLED")
    st = path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or
        stat.S_IMODE(st.st_mode) & 0o077 or not 1 <= st.st_size <= 16_384):
        raise AssociationDenied("ASSOCIATION_FILE_NOT_ROOT_PRIVATE")
    try:
        return json.loads(path.read_bytes())
    except (ValueError, UnicodeError) as exc:
        raise AssociationDenied("ASSOCIATION_PAYLOAD_INVALID_JSON") from exc


def exclusive_operation(
    plan: dict,
    registry: Any,
    association_store: Path,
    issuer_public_key_b64: str,
    *,
    now: int | None = None,
) -> dict:
    """Resolve one operation ONLY from the independently verified association.

    Caller must pass a first-party root-owned SHA-validated Registry.
    Caller-provided Job.operation or operation_id is ignored.
    """
    if not isinstance(plan, dict):
        raise AssociationDenied("ASSOCIATION_PLAN_INVALID")
    record = protected_association(association_store, plan.get("plan_sha256"))
    operation = record.get("operation_id")
    if not isinstance(operation, str) or not OP.fullmatch(operation):
        raise AssociationDenied("ASSOCIATION_OPERATION_ID_INVALID")
    try:
        manifest = registry.resolve(operation)
    except (ValueError, KeyError, TypeError) as exc:
        raise AssociationDenied("ASSOCIATION_OPERATION_NOT_ENROLLED") from exc
    return verify_association(
        plan, manifest, record, issuer_public_key_b64, now=now
    )
