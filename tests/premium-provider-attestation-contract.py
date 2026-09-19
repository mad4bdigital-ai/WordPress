#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
CONTROL = ROOT / "wp-content/plugins/mad4b-site-control-plane"
BASE = json.loads((CONTROL / "config/certified-providers.json").read_text(encoding="utf-8"))
PROFILES = json.loads((CONTROL / "config/certified-provider-profiles.json").read_text(encoding="utf-8"))
ATTEST = json.loads((CONTROL / "config/premium-provider-attestations.json").read_text(encoding="utf-8"))

EXPECTED_ENTRIES = {
    "jetengine": "jet-engine/jet-engine.php",
    "jetsmartfilters": "jet-smart-filters/jet-smart-filters.php",
}


def fail(message: str) -> None:
    raise SystemExit(message)


def manifest_digest(files: dict[str, str]) -> str:
    payload = "\n".join(f"{path}={files[path].lower()}" for path in sorted(files)) + "\n"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


if ATTEST.get("contract") != "mad4b.site-control-plane.premium-provider-attestation.v1":
    fail("premium provider attestation contract id drifted")

policy = ATTEST.get("policy", {})
required = policy.get("required_providers", [])
if sorted(required) != sorted(EXPECTED_ENTRIES):
    fail("premium attestation required-provider set drifted")
allowed = set(policy.get("allowed_source_kinds", []))
forbidden = set(policy.get("forbidden_source_kinds", []))
if not {"owner_supplied_exact_package", "vendor_exact_package"}.issubset(allowed):
    fail("trusted exact-package source kinds are missing")
if allowed & forbidden:
    fail("allowed and forbidden source kinds overlap")
if not {"live_runtime", "staging_runtime", "runtime_hash_capture"}.issubset(forbidden):
    fail("runtime-self-attestation source kinds must remain forbidden")
if policy.get("semantic_review_required") is not True:
    fail("semantic review must remain mandatory")
if policy.get("independent_from_runtime_required") is not True:
    fail("independent package evidence must remain mandatory")

attestations = ATTEST.get("attestations", {})
provider_profiles = PROFILES.get("providers", {})
base_providers = BASE.get("providers", {})

for provider in required:
    provider_attest = attestations.get(provider, {})
    if not isinstance(provider_attest, dict):
        fail(f"{provider}: attestation map must be an object")
    profiles = provider_profiles.get(provider, {})
    if not isinstance(profiles, dict):
        fail(f"{provider}: profile map must be an object")

    expected_critical = set(base_providers.get(provider, {}).get("critical_files", {}))
    if not expected_critical:
        fail(f"{provider}: governed critical-file contract is empty")

    for version, record in provider_attest.items():
        if not isinstance(record, dict):
            fail(f"{provider}/{version}: attestation must be an object")
        if record.get("provider") != provider or record.get("version") != version:
            fail(f"{provider}/{version}: provider/version binding mismatch")
        if record.get("source_kind") not in allowed or record.get("source_kind") in forbidden:
            fail(f"{provider}/{version}: source kind is not trusted")
        if record.get("independent_from_runtime") is not True:
            fail(f"{provider}/{version}: live runtime cannot be its own authority")
        if not re.fullmatch(r"[a-f0-9]{64}", str(record.get("archive_sha256", ""))):
            fail(f"{provider}/{version}: invalid archive SHA256")
        if not isinstance(record.get("archive_bytes"), int) or record["archive_bytes"] <= 0:
            fail(f"{provider}/{version}: invalid archive byte count")
        if record.get("plugin_entry") != EXPECTED_ENTRIES[provider]:
            fail(f"{provider}/{version}: plugin entry mismatch")
        critical = record.get("critical_files", {})
        if not isinstance(critical, dict) or set(critical) != expected_critical:
            fail(f"{provider}/{version}: critical-file manifest must be complete and exact")
        if any(not re.fullmatch(r"[a-f0-9]{64}", str(value)) for value in critical.values()):
            fail(f"{provider}/{version}: invalid critical-file SHA256")
        if record.get("critical_manifest_sha256") != manifest_digest(critical):
            fail(f"{provider}/{version}: critical manifest digest mismatch")
        if not str(record.get("source_locator", "")).strip():
            fail(f"{provider}/{version}: immutable/non-secret source locator is required")
        if not str(record.get("attestation_id", "")).strip():
            fail(f"{provider}/{version}: attestation id is required")

    for version, profile in profiles.items():
        record = provider_attest.get(version)
        if not isinstance(record, dict):
            fail(f"{provider}/{version}: premium profile exists without exact-package attestation")
        review = record.get("semantic_review", {})
        if review.get("status") != "approved" or review.get("approved") is not True:
            fail(f"{provider}/{version}: premium profile exists before semantic review approval")
        attestation_id = record.get("attestation_id")
        if profile.get("attestation_id") != attestation_id:
            fail(f"{provider}/{version}: profile attestation id mismatch")
        if profile.get("version") != version:
            fail(f"{provider}/{version}: profile version mismatch")
        if profile.get("archive_sha256") != record.get("archive_sha256"):
            fail(f"{provider}/{version}: profile archive SHA is not attestation-bound")
        if int(profile.get("archive_bytes", -1)) != int(record.get("archive_bytes", -2)):
            fail(f"{provider}/{version}: profile archive bytes are not attestation-bound")
        if profile.get("critical_files") != record.get("critical_files"):
            fail(f"{provider}/{version}: profile critical files are not attestation-bound")
        expected_authority = f"premium-attestation:{attestation_id}"
        if profile.get("certification_authority") != expected_authority:
            fail(f"{provider}/{version}: certification authority is not the approved attestation")

print("premium provider attestation contract: PASS")
