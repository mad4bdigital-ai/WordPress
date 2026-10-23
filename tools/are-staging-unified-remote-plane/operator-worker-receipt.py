#!/usr/bin/env python3
"""Sign Operator execution receipts only after admission and fresh worker checks.

No main() and no command execution; imported by the isolated operator worker.
This key belongs to the VPS, never to WordPress or the SSH staging account.
"""
import base64
import hashlib
import json
import time
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

CONTRACT = "mad4b.operator-host-receipt.v1"


class ReceiptDenied(Exception):
    pass


def issue_receipt(admission, seal, worker_result, live_session_verified,
                  live_approval_verified, private_pem, public_pem, now=None):
    if not isinstance(admission, dict) or admission.get("admitted") is not True:
        raise ReceiptDenied("independent_worker_admission_missing")
    if live_session_verified is not True:
        raise ReceiptDenied("live_session_verification_missing")
    if not isinstance(worker_result, dict) or worker_result.get("verdict") not in ("PASS", "FAIL"):
        raise ReceiptDenied("execution_result_invalid")
    if not isinstance(seal, dict) or not isinstance(seal.get("body"), dict):
        raise ReceiptDenied("original_host_seal_missing")
    original = seal["body"]
    if admission.get("job_id") != original.get("job_id") or        admission.get("operation_id") != original.get("operation_id"):
        raise ReceiptDenied("admitted_job_does_not_match_seal")
    operation = admission["operation_id"]
    if operation != "operator.sql.read" and live_approval_verified is not True:
        raise ReceiptDenied("claimed_write_approval_missing")
    if worker_result.get("mutation_performed") is True and operation == "operator.sql.read":
        raise ReceiptDenied("read_operation_reported_mutation")
    if operation == "operator.sql.read" and worker_result.get("execution_mode") != "staging_read_only_sql":
        raise ReceiptDenied("sql_read_executor_mismatch")
    now = int(time.time()) if now is None else now
    if now >= original.get("session_expires_at_epoch", 0):
        raise ReceiptDenied("operator_session_expired")
    if not isinstance(public_pem, bytes) or not isinstance(private_pem, bytes):
        raise ReceiptDenied("worker_key_material_missing")
    try:
        key = serialization.load_pem_private_key(private_pem, password=None)
        public = serialization.load_pem_public_key(public_pem)
        if key.key_size < 3072 or public.key_size < 3072:
            raise ReceiptDenied("worker_key_too_small")
        if key.public_key().public_numbers() != public.public_numbers():
            raise ReceiptDenied("worker_key_identity_mismatch")
    except (ValueError, TypeError) as e:
        raise ReceiptDenied("worker_key_parse_denied") from e
    output = worker_result.get("stdout", "")
    if not isinstance(output, str) or len(output.encode("utf-8")) > 8192:
        raise ReceiptDenied("output_too_large")
    body = {
        "contract": CONTRACT,
        "job_id": admission["job_id"],
        "plan_sha256": original["plan_sha256"],
        "submission_sha256": original["submission_sha256"],
        "operation_id": operation,
        "session_id": original["session_id"],
        "target_fingerprint": original["target_fingerprint"],
        "approval_ticket_id": original["approval_ticket_id"],
        "environment": "staging",
        "verdict": worker_result["verdict"],
        "exit_code": int(worker_result["exit_code"]),
        "output_sha256": hashlib.sha256(output.encode()).hexdigest(),
        "completed_at_epoch": now,
        "worker_live_session_verified": True,
        "worker_live_approval_verified": live_approval_verified is True,
        "mutation_performed": bool(worker_result.get("mutation_performed", False)),
    }
    payload = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode()
    signature = key.sign(payload, padding.PKCS1v15(), hashes.SHA256())
    return {
        "contract": CONTRACT,
        "algorithm": "RSA-SHA256",
        "public_key_sha256": hashlib.sha256(public_pem).hexdigest(),
        "body": body,
        "signature_b64": base64.b64encode(signature).decode("ascii"),
    }
