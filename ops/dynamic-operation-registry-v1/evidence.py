#!/usr/bin/env python3
"""Generic, independent 313 proof verification for an enrolled operation.

No executor receives signing permission from this module. Every signature is
verified against a separately pinned verifier key and original 312 job scope.
A verified proof is evidence, NOT a Central WordPress 313 receipt.
"""
from __future__ import annotations
import base64
import json
import re
import time
from typing import Any

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
import registry

CONTRACT = "mad4b.dynamic-313-independent-proof.v1"
FIELDS = ("contract","job_id","operation_id","provider_version","evidence_schema",
          "company","environment","site_uuid","origin","target_id",
          "command_sha256","plan_sha256","evidence_sha256","payload",
          "issued_epoch","expires_epoch","nonce","signature")
UUID = re.compile(r"^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$")
JOB = re.compile(r"^[a-f0-9]{32}$")


def validate_payload(payload: dict, spec: dict[str, str]) -> None:
    registry.require(isinstance(payload, dict) and
                     set(payload) == set(spec), "EVIDENCE_FIELDS_MISMATCH")
    for name, kind in spec.items():
        value = payload[name]
        if kind == "integer":
            valid = type(value) is int and value >= 0
        elif kind == "boolean":
            valid = type(value) is bool
        elif kind == "sha256":
            valid = isinstance(value, str) and registry.SHA.fullmatch(value)
        else:
            valid = isinstance(value, str) and 0 < len(value) <= 200
        registry.require(valid, "EVIDENCE_FIELD_TYPE_INVALID:" + name)


def verify_signed(
    registry_index: registry.Registry,
    proof: dict,
    job: dict,
    original312: dict,
    *,
    now: int | None = None,
) -> dict:
    registry.require(isinstance(proof, dict) and set(proof) == set(FIELDS),
                     "DYNAMIC_313_ENVELOPE_INVALID")
    registry.require(isinstance(job, dict) and isinstance(original312, dict),
                     "ORIGINAL_BROKER_EVIDENCE_REQUIRED")
    op = proof.get("operation_id")
    provider = registry_index.resolve(op)
    registry.require(proof["contract"] == CONTRACT and
                     type(proof["provider_version"]) is int and
                     proof["provider_version"] == provider["version"] and
                     proof["evidence_schema"] == provider["evidence_schema"],
                     "PROVIDER_VERSION_OR_SCHEMA_CHANGED")
    registry.require(isinstance(proof["job_id"], str) and
                     JOB.fullmatch(proof["job_id"]) and
                     isinstance(proof["plan_sha256"], str) and
                     registry.SHA.fullmatch(proof["plan_sha256"]) and
                     isinstance(proof["nonce"], str) and
                     JOB.fullmatch(proof["nonce"]),
                     "DYNAMIC_313_IDENTIFIERS_INVALID")
    expected_digest=registry_index.command_sha256(op, proof["plan_sha256"])
    registry.require(proof["command_sha256"] == expected_digest and
                     isinstance(proof["evidence_sha256"], str) and
                     registry.SHA.fullmatch(proof["evidence_sha256"]) and
                     isinstance(proof["payload"], dict) and
                     proof["evidence_sha256"] == registry.sha(
                         registry.canonical(proof["payload"])),
                     "DYNAMIC_313_HASH_MISMATCH")
    validate_payload(proof["payload"],registry_index.evidence_fields(op))
    evidence=proof["payload"]
    registry.require(
        evidence.get("expected_readback_sha256")==evidence.get("readback_sha256") and
        evidence.get("grant_id")==original312.get("grant_id") and
        evidence.get("transport_id")==original312.get("transport_id") and
        evidence.get("production_mutation") is (proof["environment"]=="production"),
        "DYNAMIC_313_ORIGINAL_RECEIPT_BINDING_MISMATCH")
    instant = int(time.time()) if now is None else int(now)
    registry.require(type(proof["issued_epoch"]) is int and
                     type(proof["expires_epoch"]) is int and
                     instant-120 <= proof["issued_epoch"] <= instant+10 and
                     instant < proof["expires_epoch"] <= proof["issued_epoch"]+120,
                     "DYNAMIC_313_PROOF_EXPIRED")
    registry.require(isinstance(proof["origin"], str) and
                     proof["origin"].startswith("https://") and
                     isinstance(proof["site_uuid"], str) and
                     UUID.fullmatch(proof["site_uuid"]),
                     "DYNAMIC_313_TARGET_INVALID")
    schema = registry_index.evidence_definition(op)
    registry.require(proof["payload"].get("result_status") ==
                     schema["success_state"],
                     "DYNAMIC_313_RESULT_UNVERIFIED")

    # Readback proof cannot select its own company or authority. Compare to
    # original Job plus the independent signed original WordPress 312 result.
    scope = ("company", "environment", "site_uuid", "origin")
    registry.require(all(proof[key] == job.get(key) == original312.get(key)
                         for key in scope) and
                     proof["job_id"] == job.get("job_id") ==
                     original312.get("job_id") and
                     proof["command_sha256"] == job.get("command_sha256") ==
                     original312.get("command_sha256") and
                     proof["target_id"] == original312.get("target_id") ==
                     job.get("target_host_id") and
                     job.get("operation") == "company.remote.wpcli.full" and
                     job.get("job_type") == "signed_vps_wpcli" and
                     job.get("target_scope") == "wordpress" and
                     original312.get("contract") ==
                     "mad4b.broker-exact-ssh-authority.v1" and
                     original312.get("verified") is True and
                     original312.get("eligible") is True and
                     original312.get("owner_enrolled_wpcli") is True and
                     original312.get("central_scope_only") is True and
                     original312.get("shell_executed") is False and
                     original312.get("expires_epoch",0) >= instant+20,
                     "ORIGINAL_312_JOB_SCOPE_NOT_VERIFIED")
    try:
        key = Ed25519PublicKey.from_public_bytes(
            registry_index.independent_verifier_public_key)
        message = registry.canonical({key:value for key,value in proof.items()
                                      if key != "signature"})
        key.verify(base64.b64decode(proof["signature"],validate=True),message)
    except Exception as exc:
        raise registry.RegistryDenied("INDEPENDENT_313_SIGNATURE_INVALID") from exc
    return {
        "contract": CONTRACT, "state":"SIGNED_INDEPENDENT_EVIDENCE_VERIFIED",
        "operation_id":op, "job_id":proof["job_id"],
        "command_sha256":expected_digest,
        "evidence_sha256":proof["evidence_sha256"],
        "original_313_certified":False,
        "central_receipt_created":False, "grant_issued":False,
    }
