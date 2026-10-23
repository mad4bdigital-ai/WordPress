#!/usr/bin/env python3
"""Fail-closed Operator admission verifier. It NEVER executes a command or SQL.

Requires separate live session and approval callbacks before admitting any job.
Trust is pinned to the exact ARE Staging WordPress RSA-3072 public key.
"""
import base64
import hashlib
import json
import re
import time
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

CONTRACT = "mad4b.operator-queue-attestation.v1"
PROFILE = "are-staging-unified-v1"
SHA = re.compile(r"^[a-f0-9]{64}$")
UUID = re.compile(r"^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$")


class AdmissionDenied(Exception):
    pass


def require(condition, reason):
    if not condition:
        raise AdmissionDenied(reason)


def canonical_json(record):
    return json.dumps(record, ensure_ascii=False,
                      separators=(",", ":"), allow_nan=False).encode("utf-8")


def wordpress_digest(record):
    # Mirrors MAD4B_SCP_Host_Bridge::digest and nested canonicalize.
    ignored = ("plan_sha256", "repair_plan_sha256", "submission_sha256",
               "authorizing", "mutation_performed", "queued", "replayed")
    rest = {k: v for k, v in record.items() if k not in ignored}
    return hashlib.sha256(json.dumps(rest, ensure_ascii=False, sort_keys=True,
                        separators=(",", ":"), allow_nan=False).encode()).hexdigest()


def verify_job(submission, seal, public_key_pem, expected_fingerprint,
               live_session_check, live_approval_check, now=None):
    now = int(time.time()) if now is None else now
    require(callable(live_session_check) and callable(live_approval_check),
            "trusted_live_verifier_required")
    require(isinstance(submission, dict) and isinstance(seal, dict),
            "malformed_submission_or_seal")
    require(seal.get("contract") == CONTRACT and seal.get("algorithm") == "RSA-SHA256",
            "attestation_contract_mismatch")
    require(isinstance(public_key_pem, bytes) and
            hashlib.sha256(public_key_pem).hexdigest() == seal.get("public_key_sha256"),
            "pinned_public_key_mismatch")
    require(isinstance(expected_fingerprint, str) and SHA.fullmatch(expected_fingerprint),
            "expected_target_binding_invalid")
    plan = submission.get("plan")
    body = seal.get("body")
    require(isinstance(plan, dict) and isinstance(body, dict), "missing_signed_plan")
    require(body.get("contract") == CONTRACT, "signed_body_contract_mismatch")
    require(submission.get("contract") == "mad4b.host-bridge-submission.v1" and
            plan.get("contract") == "mad4b.host-operation-plan.v1",
            "host_bridge_contract_invalid")
    job = submission.get("job_id")
    op = plan.get("operation_id")
    require(isinstance(job, str) and UUID.fullmatch(job), "job_id_invalid")
    require(isinstance(op, str) and op in
            ("operator.shell", "operator.wp-cli", "operator.sql.read",
             "operator.sql.write", "operator.sql.schema"), "operation_not_admitted")
    require(plan.get("runner_profile_id") == PROFILE and
            plan.get("target", {}).get("environment") == "staging" and
            plan.get("production_authorized") is False and
            submission.get("production_authorized") is False,
            "staging_profile_denied")
    require(plan.get("target", {}).get("target_fingerprint") == expected_fingerprint,
            "target_fingerprint_drift")
    require(isinstance(plan.get("plan_sha256"), str) and
            wordpress_digest(plan) == plan.get("plan_sha256") ==
            submission.get("plan_sha256"), "plan_digest_mismatch")
    require(isinstance(submission.get("submission_sha256"), str) and
            wordpress_digest(submission) == submission.get("submission_sha256"),
            "submission_digest_mismatch")
    args = plan.get("arguments")
    require(isinstance(args, dict) and
            op == "operator." + str(args.get("operation", "")) and
            isinstance(args.get("session_id"), str) and
            UUID.fullmatch(args["session_id"]) and
            isinstance(args.get("payload_sha256"), str) and
            SHA.fullmatch(args["payload_sha256"]), "operator_arguments_invalid")

    require(isinstance(args.get("payload"), dict) and
            hashlib.sha256(canonical_json(args["payload"])).hexdigest() ==
            args["payload_sha256"], "signed_semantic_payload_digest_mismatch")

    expected_body = {
        "contract": CONTRACT,
        "job_id": job,
        "plan_sha256": submission["plan_sha256"],
        "submission_sha256": submission["submission_sha256"],
        "operation_id": op,
        "session_id": args["session_id"],
        "session_expires_at_epoch": args.get("session_expires_at_epoch"),
        "target_fingerprint": expected_fingerprint,
        "approval_ticket_id": submission.get("authority", {}).get("approval_ticket_id", ""),
        "policy_decision_sha256": submission.get("authority", {}).get("policy_decision_sha256", ""),
        "environment": "staging",
        "issued_at_epoch": body.get("issued_at_epoch"),
    }
    require(body == expected_body, "signed_job_binding_mismatch")
    issued, expiry = body["issued_at_epoch"], body["session_expires_at_epoch"]
    require(isinstance(issued, int) and isinstance(expiry, int) and
            now - 60 <= issued <= now + 30 and now < expiry <= issued + 900,
            "expired_or_replayed_attestation")
    try:
        signature = base64.b64decode(seal["signature_b64"], validate=True)
        pub = serialization.load_pem_public_key(public_key_pem)
        require(pub.key_size >= 3072, "rsa_key_too_small")
        pub.verify(signature, canonical_json(body), padding.PKCS1v15(), hashes.SHA256())
    except Exception as e:
        raise AdmissionDenied("invalid_asymmetric_signature") from e

    # The host must confirm that the OAuth-issued session is still present,
    # bound to this client, in scope, and not revoked. This MUST query live
    # trusted WordPress state over pinned SSH; a mock is test-only.
    require(live_session_check(body, args) is True, "session_revoked_or_unverified")
    write = op != "operator.sql.read"
    if write:
        require(isinstance(body["approval_ticket_id"], str) and
                UUID.fullmatch(body["approval_ticket_id"]) and
                isinstance(body["policy_decision_sha256"], str) and
                SHA.fullmatch(body["policy_decision_sha256"]),
                "write_approval_attestation_missing")
        require(live_approval_check(body, args) is True,
                "approval_revoked_or_unverified")
    return {
        "contract": "mad4b.operator-admission-verdict.v1",
        "admitted": True,
        "job_id": job,
        "operation_id": op,
        "session_id": args["session_id"],
        "target_fingerprint": expected_fingerprint,
        "requires_independent_execution_sandbox": True,
        "production_authorized": False,
        "mutation_performed": False,
    }
