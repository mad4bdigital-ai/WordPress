#!/usr/bin/env python3
"""Signed v128 persistent host enrollment and operation-lifecycle verifier.

Pure, fail-closed policy implementation. No WordPress writes, Root signing,
grants, connection opening, or mutation. All snapshots must come from verified
first-party readers; this cannot bootstrap authority from legacy v2/EXPIRED.
"""
from __future__ import annotations

import base64
import hashlib
import json
import re
import time
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

DEFAULT_CONNECTION_MODE = "persistent"
LEGACY_COMPATIBLE_MODE = "operation_scoped"
MODES = frozenset((DEFAULT_CONNECTION_MODE, LEGACY_COMPATIBLE_MODE))
ENROLLMENT_CONTRACT = "mad4b.original318.host-enrollment.v3"
CHECKPOINT_CONTRACT = "mad4b.original313.host-connection-highwater.v3"
GRANT_CONTRACT = "mad4b.original312.operation-grant.v3"
ACTIVITY_CONTRACT = "mad4b.original306.operation-activity.v3"
READBACK_CONTRACT = "mad4b.original318.host-connection-readback.v3"
DOMAINS = {
    ENROLLMENT_CONTRACT: "MAD4B_HOST_ENROLLMENT_V3",
    CHECKPOINT_CONTRACT: "MAD4B_HOST_CONNECTION_HIGHWATER_V3",
    GRANT_CONTRACT: "MAD4B_HOST_OPERATION_GRANT_V3",
    ACTIVITY_CONTRACT: "MAD4B_HOST_OPERATION_ACTIVITY_V3",
}
ENROLLMENT_FIELDS = (
    "contract", "company", "environment", "site_uuid", "target_host_id",
    "ssh_principal", "host_key_sha256", "policy_sha256", "revision",
    "nonce", "issued_epoch", "mode", "expires_epoch", "state",
)
CHECKPOINT_FIELDS = (
    "contract", "company", "environment", "site_uuid", "revision",
    "enrollment_sha256", "state", "operation_id", "activity_sha256",
    "activity_seq", "observed_epoch",
)
GRANT_FIELDS = (
    "contract", "company", "environment", "site_uuid", "revision",
    "operation_id", "action", "grant_nonce", "approved_epoch", "idle_limit_seconds",
)
ACTIVITY_FIELDS = (
    "contract", "operation_id", "grant_sha256", "seq", "state",
    "last_heartbeat_epoch",
)
ACTIVE_STATES = frozenset(("AUTHORIZED", "RUNNING"))
TERMINAL_STATES = frozenset(("COMPLETED", "FAILED", "CANCELLED", "REVOKED"))
HEX32 = re.compile(r"[0-9a-f]{32}\Z")
HEX64 = re.compile(r"[0-9a-f]{64}\Z")
MAX_CHECKPOINT_AGE = 30
CLOCK_SKEW = 5

class ConnectionDenied(ValueError):
    """A stable, non-authorizing failure reason."""

def need(condition: bool, reason: str) -> None:
    if not condition:
        raise ConnectionDenied(reason)

def canonical(row: dict, fields: tuple[str, ...], contract: str) -> bytes:
    # Never sign the signature itself; json is canonical for ASCII identity.
    return (DOMAINS[contract] + "\n" + json.dumps(
        {field: row[field] for field in fields}, sort_keys=True,
        separators=(",", ":"), ensure_ascii=False,
    )).encode("utf-8")

def digest(row: dict, fields: tuple[str, ...], contract: str) -> str:
    return hashlib.sha256(canonical(row, fields, contract)).hexdigest()

def verify_signed(row: dict, fields: tuple[str, ...],
                  contract: str, public_key: bytes) -> None:
    need(type(row) is dict and set(row) == set(fields) | {"signature"},
         "SIGNED_ROW_SHAPE_INVALID")
    need(row.get("contract") == contract, "SIGNED_CONTRACT_INVALID")
    need(type(public_key) is bytes and len(public_key) == 32,
         "SIGNER_PUBLIC_KEY_INVALID")
    try:
        signature = base64.b64decode(row["signature"], validate=True)
        need(len(signature) == 64, "SIGNED_SIGNATURE_SIZE_INVALID")
        Ed25519PublicKey.from_public_bytes(public_key).verify(
            signature, canonical(row, fields, contract))
    except (InvalidSignature, ValueError, TypeError, KeyError) as exc:
        raise ConnectionDenied("SIGNED_SIGNATURE_INVALID") from exc

def keys_independent(*keys: bytes) -> None:
    need(all(type(k) is bytes and len(k) == 32 for k in keys)
         and len(set(keys)) == len(keys), "INDEPENDENT_SIGNERS_REQUIRED")

def prepare_new_enrollment(*, company: str, environment: str,
                           site_uuid: str, target_host_id: str,
                           ssh_principal: str, host_key_sha256: str,
                           policy_sha256: str, revision: int, nonce: str,
                           issued_epoch: int,
                           mode: str = DEFAULT_CONNECTION_MODE) -> dict:
    """Unsigned v3 issuer input; explicit owner approval and Root signature
    are required separately. Does not migrate or renew a v2 Anchor.
    """
    need(mode in MODES, "CONNECTION_MODE_INVALID")
    need(type(revision) is int and revision >= 1
         and type(issued_epoch) is int and issued_epoch > 0
         and type(nonce) is str and HEX32.fullmatch(nonce) is not None
         and all(type(s) is str and bool(s) for s in
                 (company, environment, site_uuid, target_host_id,
                  ssh_principal))
         and all(type(d) is str and HEX64.fullmatch(d) is not None
                 for d in (host_key_sha256, policy_sha256)),
         "NEW_ENROLLMENT_INPUT_INVALID")
    return {
        "contract": ENROLLMENT_CONTRACT,
        "company": company, "environment": environment,
        "site_uuid": site_uuid, "target_host_id": target_host_id,
        "ssh_principal": ssh_principal,
        "host_key_sha256": host_key_sha256,
        "policy_sha256": policy_sha256, "revision": revision,
        "nonce": nonce, "issued_epoch": issued_epoch, "mode": mode,
        "expires_epoch": None, "state": "ACTIVE",
    }

def verify_connection(enrollment: dict, checkpoint: dict, wp: dict,
                      host_pub: bytes, highwater_pub: bytes, *,
                      now: int | None = None) -> dict:
    """Read-only current connectivity, never an executable Host Write grant."""
    instant = int(time.time()) if now is None else now
    need(type(instant) is int and instant > 0, "CLOCK_INVALID")
    keys_independent(host_pub, highwater_pub)
    verify_signed(enrollment, ENROLLMENT_FIELDS,
                  ENROLLMENT_CONTRACT, host_pub)
    verify_signed(checkpoint, CHECKPOINT_FIELDS,
                  CHECKPOINT_CONTRACT, highwater_pub)
    need(type(enrollment["issued_epoch"]) is int
         and 0 < enrollment["issued_epoch"] <= instant + CLOCK_SKEW
         and type(enrollment["revision"]) is int
         and enrollment["revision"] >= 1
         and type(enrollment["nonce"]) is str
         and HEX32.fullmatch(enrollment["nonce"]) is not None
         and all(type(enrollment[k]) is str and enrollment[k]
                 for k in ("company", "environment", "site_uuid",
                           "target_host_id", "ssh_principal"))
         and all(type(enrollment[k]) is str
                 and HEX64.fullmatch(enrollment[k]) is not None
                 for k in ("host_key_sha256", "policy_sha256")),
         "ENROLLMENT_IDENTITY_INVALID")
    need(enrollment["mode"] in MODES, "CONNECTION_MODE_INVALID")
    need(enrollment["state"] == "ACTIVE", "ENROLLMENT_NOT_ACTIVE")
    if enrollment["mode"] == DEFAULT_CONNECTION_MODE:
        need(enrollment["expires_epoch"] is None,
             "PERSISTENT_MODE_MUST_NOT_USE_EXPIRY")
    else:
        # Legacy-compatible mode uses an actual operation, not a fixed TTL.
        need(enrollment["expires_epoch"] is None,
             "SCOPED_MODE_MUST_USE_OPERATION_LIFECYCLE")
    need(type(checkpoint["observed_epoch"]) is int
         and instant - MAX_CHECKPOINT_AGE
         <= checkpoint["observed_epoch"] <= instant + CLOCK_SKEW,
         "HIGHWATER_READBACK_STALE")
    need(checkpoint["state"] == "ACTIVE", "HOST_REVOKED_OR_DISABLED")
    need(checkpoint["revision"] == enrollment["revision"]
         and checkpoint["enrollment_sha256"] ==
             digest(enrollment, ENROLLMENT_FIELDS, ENROLLMENT_CONTRACT)
         and all(checkpoint[k] == enrollment[k]
                 for k in ("company", "environment", "site_uuid")),
         "HIGHWATER_ENROLLMENT_MISMATCH")
    if checkpoint["operation_id"] is None:
        need(checkpoint["activity_sha256"] is None
             and checkpoint["activity_seq"] is None,
             "HIGHWATER_ACTIVITY_SHAPE")
    else:
        need(type(checkpoint["operation_id"]) is str
             and HEX32.fullmatch(checkpoint["operation_id"]) is not None
             and type(checkpoint["activity_sha256"]) is str
             and HEX64.fullmatch(checkpoint["activity_sha256"]) is not None
             and type(checkpoint["activity_seq"]) is int
             and checkpoint["activity_seq"] > 0,
             "HIGHWATER_ACTIVITY_SHAPE")
    need(type(wp) is dict
         and wp.get("contract") == READBACK_CONTRACT
         and wp.get("readback_verified") is True
         and wp.get("signature_verified") is True
         and wp.get("source") == "original318_current_wp_readback"
         and wp.get("host_key_sha256") == enrollment["host_key_sha256"]
         and wp.get("revision") == enrollment["revision"]
         and wp.get("enrollment_sha256") ==
             checkpoint["enrollment_sha256"]
         and wp.get("state") == "ACTIVE"
         and wp.get("operation_id") == checkpoint["operation_id"]
         and wp.get("activity_sha256") == checkpoint["activity_sha256"]
         and wp.get("activity_seq") == checkpoint["activity_seq"]
         and wp.get("write_authorized") is False,
         "ORIGINAL318_READBACK_NOT_VERIFIED_OR_DRIFTED")
    is_persistent = enrollment["mode"] == DEFAULT_CONNECTION_MODE
    # A scoped enrollment is not a live connection until the *signed*
    # operation grant and latest activity are admitted below.
    need(is_persistent or checkpoint["operation_id"] is not None,
         "SCOPED_CONNECTION_REQUIRES_OPERATION")
    return {
        "state": "PERSISTENT_CONNECTED" if is_persistent else
                 "SCOPED_ENROLLMENT_AWAITING_OPERATION_ADMISSION",
        "mode": enrollment["mode"],
        "revision": enrollment["revision"],
        "enrollment_expires_epoch": None,
        "connection_verified": is_persistent,
        "host_write_authorized": False,
        "execution_performed": False,
    }

def verify_operation(enrollment: dict, checkpoint: dict, wp: dict,
                     grant: dict, activity: dict,
                     host_pub: bytes, highwater_pub: bytes,
                     grant_pub: bytes, activity_pub: bytes,
                     requested_action: str, *, now: int | None = None) -> dict:
    """Recheck exact signed job admission each step, never grant broad Host Write.

    Validity follows signed activity, not a wall-clock expiry from start.
    Signer-independent current high-water MUST reflect the latest activity.
    """
    instant = int(time.time()) if now is None else now
    keys_independent(host_pub, highwater_pub, grant_pub, activity_pub)
    conn = verify_connection(enrollment, checkpoint, wp,
                             host_pub, highwater_pub, now=instant)
    verify_signed(grant, GRANT_FIELDS, GRANT_CONTRACT, grant_pub)
    verify_signed(activity, ACTIVITY_FIELDS, ACTIVITY_CONTRACT, activity_pub)
    need(type(requested_action) is str and requested_action,
         "ACTION_REQUIRED")
    need(grant["action"] == requested_action,
         "OPERATION_ACTION_NOT_IN_GRANT")
    need(grant["revision"] == enrollment["revision"]
         and all(grant[k] == enrollment[k]
                 for k in ("company", "environment", "site_uuid")),
         "OPERATION_GRANT_SCOPE_DRIFT")
    need(type(grant["operation_id"]) is str and
         HEX32.fullmatch(grant["operation_id"]) is not None
         and type(grant["grant_nonce"]) is str and
         HEX32.fullmatch(grant["grant_nonce"]) is not None
         and type(grant["approved_epoch"]) is int
         and enrollment["issued_epoch"] <= grant["approved_epoch"] <= instant+CLOCK_SKEW,
         "OPERATION_GRANT_IDENTITY_INVALID")
    # Idle timeout detects lost workers. It is NOT total operation duration.
    need(type(grant["idle_limit_seconds"]) is int
         and 30 <= grant["idle_limit_seconds"] <= 900,
         "OPERATION_IDLE_POLICY_INVALID")
    need(activity["operation_id"] == grant["operation_id"]
         and activity["grant_sha256"] ==
             digest(grant, GRANT_FIELDS, GRANT_CONTRACT)
         and type(activity["seq"]) is int and activity["seq"] >= 1
         and type(activity["last_heartbeat_epoch"]) is int
         and grant["approved_epoch"] <= activity["last_heartbeat_epoch"]
         <= instant + CLOCK_SKEW,
         "OPERATION_ACTIVITY_INVALID")
    need(activity["state"] in ACTIVE_STATES,
         "OPERATION_TERMINAL_OR_NOT_RUNNING")
    need(instant - activity["last_heartbeat_epoch"]
         <= grant["idle_limit_seconds"], "OPERATION_HEARTBEAT_LOST")
    need(checkpoint["operation_id"] == grant["operation_id"]
         and checkpoint["activity_seq"] == activity["seq"]
         and checkpoint["activity_sha256"] ==
            digest(activity, ACTIVITY_FIELDS, ACTIVITY_CONTRACT),
         "LATEST_OPERATION_HIGHWATER_MISMATCH")
    # The signed checkpoint and fresh WordPress readback are consulted again
    # at each step. When a terminal state arrives, older snapshots fail.
    return {
        "state": "EXACT_OPERATION_ADMISSION_VERIFIED",
        "operation_id": grant["operation_id"],
        "action": requested_action,
        "connection_mode": conn["mode"],
        "operation_seq": activity["seq"],
        "connection_verified": True,
        "expires_on": "terminal_state_or_inactivity_or_host_revocation",
        "fixed_end_epoch": None,
        "broad_host_write_authorized": False,
        "execution_performed": False,
    }
