#!/usr/bin/env python3
"""Non-authorizing integrity checks for MAD4B Readiness evidence envelopes.

This module validates syntax and source identity only. Cryptographic signature
verification MUST be supplied by an independently trusted producer in a
separate governed workflow before any receipt can count for release decisions.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re

SHA40 = re.compile(r"^[a-f0-9]{40}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
SITE = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,127}$")
CONTRACT = "mad4b.site-bound-evidence-envelope.v1"
REQUIRED = (
    "contract", "repository", "exact_head_sha", "site_id", "environment",
    "package_sha256", "producer_id", "observed_at", "evidence_digest_sha256",
    "signature_reference", "receipt_id",
)


def canonical_json(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")


def definition_digest(value):
    return hashlib.sha256(canonical_json(value)).hexdigest()


def validate_envelope_format(envelope, expected_repo, expected_sha):
    if not isinstance(envelope, dict) or set(REQUIRED) - set(envelope):
        raise ValueError("Missing immutable evidence fields")
    if envelope["contract"] != CONTRACT or envelope["repository"] != expected_repo:
        raise ValueError("Evidence contract / repository mismatch")
    if not SHA40.fullmatch(str(envelope["exact_head_sha"])) or (
            envelope["exact_head_sha"] != expected_sha):
        raise ValueError("Evidence must be bound to exact release head")
    if not SHA256.fullmatch(str(envelope["package_sha256"])) or (
            not SHA256.fullmatch(str(envelope["evidence_digest_sha256"]))):
        raise ValueError("Exact package and evidence hashes required")
    if envelope["environment"] not in ("staging", "production"):
        raise ValueError("Unknown environment")
    for field in ("site_id", "producer_id", "receipt_id"):
        if not SITE.fullmatch(str(envelope[field])):
            raise ValueError("Invalid source identity: " + field)
    value = str(envelope["observed_at"])
    if not re.fullmatch(r"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z", value):
        raise ValueError("Evidence observation must be UTC timestamp")
    if not isinstance(envelope["signature_reference"], str) or not (
            envelope["signature_reference"].startswith("https://")):
        raise ValueError("Signed evidence reference must be HTTPS")
    return {
        "contract": "mad4b.evidence-format-assessment.v1",
        "receipt_id": envelope["receipt_id"],
        "exact_head_sha": expected_sha,
        "package_sha256": envelope["package_sha256"],
        "format_valid": True,
        "cryptographic_signature_verified": False,
        "site_trust_verified": False,
        "status": "FORMAT_VALID_CRYPTOGRAPHY_UNVERIFIED",
        "production_authorized": False,
    }


def assess_receipts(receipts, repo, head):
    """No external signature checks: only nonauthorizing format assessments."""
    if not isinstance(receipts, list):
        raise ValueError("Evidence list required")
    seen = set()
    results = []
    for receipt in receipts:
        assessment = validate_envelope_format(receipt, repo, head)
        if assessment["receipt_id"] in seen:
            raise ValueError("Duplicate evidence receipt identity")
        seen.add(assessment["receipt_id"])
        results.append(assessment)
    return results


def self_test():
    head = "a" * 40
    r = {
        "contract": CONTRACT, "repository": "mad4bdigital-ai/WordPress",
        "exact_head_sha": head, "site_id": "etg-staging", "environment": "staging",
        "package_sha256": "b" * 64, "producer_id": "mad4b-control-plane",
        "observed_at": "2026-10-19T09:00:00Z",
        "evidence_digest_sha256": "c" * 64,
        "signature_reference": "https://example.org/readonly-proof",
        "receipt_id": "receipt-000001",
    }
    accepted = assess_receipts([r], r["repository"], head)
    assert accepted[0]["status"] == "FORMAT_VALID_CRYPTOGRAPHY_UNVERIFIED"
    assert accepted[0]["cryptographic_signature_verified"] is False
    assert accepted[0]["production_authorized"] is False
    for field, replacement in (
        ("exact_head_sha", "d" * 40),
        ("package_sha256", ""),
        ("signature_reference", "http://unsafe.example.com"),
        ("environment", "local"),
    ):
        tampered = dict(r, **{field: replacement})
        try:
            assess_receipts([tampered], r["repository"], head)
        except ValueError:
            continue
        raise AssertionError("Tampered evidence accepted: " + field)
    try:
        assess_receipts([r, r], r["repository"], head)
    except ValueError:
        pass
    else:
        raise AssertionError("Duplicate receipt must be refused")
    print("EVIDENCE_FORMAT_FAIL_CLOSED_SELF_TEST: PASS")


if __name__ == "__main__":
    self_test()
