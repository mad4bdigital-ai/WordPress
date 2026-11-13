#!/usr/bin/env python3
"""Declarative, provider-owned capability acceptance for company releases.

The release operator never executes test code or commands supplied by a Git
catalog or request. Original first-party Providers issue separately signed
runtime attestations; only independent 313 readback may certify them.
"""
from __future__ import annotations

import base64
import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import stat
from typing import Callable

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

CATALOG_CONTRACT = "mad4b.company-capability-acceptance-catalog.v1"
REGISTRY_CONTRACT = "mad4b.company-capability-acceptance-registry.v1"
SNAPSHOT_CONTRACT = "mad4b.company-capability-acceptance-snapshot.v1"
EVIDENCE_CONTRACT = "mad4b.company-capability-acceptance-evidence.v1"
ID = re.compile(r"^[a-z][a-z0-9._-]{2,100}$")
NONCE = re.compile(r"^[a-zA-Z0-9_-]{16,96}$")


class AcceptanceDenied(ValueError):
    pass


def deny_if(bad: bool, reason: str) -> None:
    if bad:
        raise AcceptanceDenied(reason)


def canonical(obj: object) -> bytes:
    return json.dumps(obj, sort_keys=True, ensure_ascii=False,
                      separators=(",", ":")).encode("utf-8")


def sha(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def _id(value: object) -> str:
    deny_if(not isinstance(value, str) or ID.fullmatch(value) is None,
            "CAPABILITY_ACCEPTANCE_ID_INVALID")
    return value


def _registry(policy: dict) -> dict | None:
    config = policy.get("capability_acceptance")
    if config is None:
        return None  # Existing plans remain backward-compatible until enrollment.
    deny_if(not isinstance(config, dict) or
            config.get("contract") != REGISTRY_CONTRACT or
            not isinstance(config.get("catalog_path"), str) or
            not config["catalog_path"].startswith(
                "ops/company-code-release-v1/catalog/") or
            not config["catalog_path"].endswith(".json") or
            ".." in Path(config["catalog_path"]).parts or
            not isinstance(config.get("providers"), dict) or
            not isinstance(config.get("require_coverage", True), bool),
            "CAPABILITY_ACCEPTANCE_REGISTRY_INVALID")
    for provider, item in config["providers"].items():
        _id(provider)
        deny_if(not isinstance(item, dict) or
                item.get("enabled") is not True or
                not isinstance(item.get("capabilities"), list) or
                not all(isinstance(c, str) and ID.fullmatch(c)
                        for c in item["capabilities"]) or
                len(item["capabilities"]) != len(set(item["capabilities"])) or
                not isinstance(item.get("public_key_b64"), str),
                "CAPABILITY_ACCEPTANCE_PROVIDER_ENROLLMENT_INVALID")
        try:
            key = base64.b64decode(item["public_key_b64"], validate=True)
        except (ValueError, TypeError) as exc:
            raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_PROVIDER_KEY_INVALID") from exc
        deny_if(len(key) != 32, "CAPABILITY_ACCEPTANCE_PROVIDER_KEY_INVALID")
    return config


def snapshot(plan_files: list[dict], policy: dict, repo: Path,
             commit: str, git_bytes: Callable,
             permitted_target: Callable[[str], bool]) -> dict:
    """Pin source-Git declarations and root-enrolled provider trust.

    IDs, capabilities, and exact file targets are data. No check-specific
    Python, PHP, shell, URL, or eval is accepted from the release catalog.
    """
    config = _registry(policy)
    if config is None:
        return {"contract": SNAPSHOT_CONTRACT, "catalog_sha256": None,
                "catalog_path": None, "require_coverage": False,
                "checks": [], "enrollment_sha256": None}
    raw = git_bytes(repo, commit, config["catalog_path"])
    deny_if(len(raw) > 200_000, "CAPABILITY_ACCEPTANCE_CATALOG_TOO_LARGE")
    try:
        catalog = json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_CATALOG_INVALID") from exc
    deny_if(not isinstance(catalog, dict) or
            set(catalog) != {"contract", "checks"} or
            catalog.get("contract") != CATALOG_CONTRACT or
            not isinstance(catalog["checks"], list) or
            len(catalog["checks"]) > 500,
            "CAPABILITY_ACCEPTANCE_CATALOG_INVALID")
    selected = {row["target"] for row in plan_files}
    records = []
    used = set()
    covered = set()
    for item in catalog["checks"]:
        deny_if(not isinstance(item, dict) or
                set(item) != {"provider", "capability", "id", "targets"} or
                not isinstance(item.get("targets"), list) or
                not 1 <= len(item["targets"]) <= 100,
                "CAPABILITY_ACCEPTANCE_CHECK_INVALID")
        provider = _id(item["provider"])
        capability = _id(item["capability"])
        check_id = _id(item["id"])
        targets = item["targets"]
        deny_if(any(not isinstance(t, str) or not permitted_target(t) or
                    ".." in Path(t).parts for t in targets) or
                len(set(targets)) != len(targets),
                "CAPABILITY_ACCEPTANCE_TARGET_INVALID")
        key = (provider, check_id)
        deny_if(key in used, "CAPABILITY_ACCEPTANCE_CHECK_DUPLICATE")
        used.add(key)
        matched = sorted(selected.intersection(targets))
        if not matched:
            continue
        enrolled = config["providers"].get(provider)
        deny_if(not isinstance(enrolled, dict) or
                capability not in enrolled["capabilities"],
                "CAPABILITY_ACCEPTANCE_PROVIDER_NOT_ENROLLED")
        covered.update(matched)
        records.append({"provider": provider, "capability": capability,
                        "id": check_id, "targets": matched})
    deny_if(config.get("require_coverage", True) and covered != selected,
            "CAPABILITY_ACCEPTANCE_COVERAGE_MISSING")
    # A source-only registration does not grant authority; the trust anchors
    # must already exist in the installed root-owned policy.
    enrollment = {
        provider: {"public_key_sha256": sha(base64.b64decode(
                        config["providers"][provider]["public_key_b64"],
                        validate=True)),
                   "capabilities": sorted(config["providers"][provider]["capabilities"])}
        for provider in sorted({r["provider"] for r in records})
    }
    return {"contract": SNAPSHOT_CONTRACT, "catalog_sha256": sha(raw),
            "catalog_path": config["catalog_path"],
            "require_coverage": config.get("require_coverage", True),
            "checks": sorted(records, key=lambda r: (r["provider"], r["id"])),
            "enrollment_sha256": sha(canonical(enrollment))}


def _private_file(path: Path) -> dict:
    for piece in [path, *path.parents]:
        deny_if(piece.is_symlink(), "CAPABILITY_ACCEPTANCE_SYMLINK_DENIED")
    deny_if(not path.is_file(), "CAPABILITY_ACCEPTANCE_EVIDENCE_MISSING")
    st = path.stat()
    deny_if(not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or
            stat.S_IMODE(st.st_mode) & 0o077 or st.st_size > 100_000,
            "CAPABILITY_ACCEPTANCE_EVIDENCE_UNTRUSTED")
    try:
        return json.loads(path.read_bytes())
    except (ValueError, TypeError) as exc:
        raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_EVIDENCE_INVALID") from exc


def verify_evidence(plan: dict, policy: dict, receipts: Path,
                    apply_nonce: str, apply_started: str) -> dict:
    """Verify signed, exact-plan provider evidence after Apply.

    Independent verification is intentionally separate from the Host's
    file-integrity receipt. Provider-specific checks are not executable input.
    """
    checks = plan.get("capability_acceptance", {}).get("checks", [])
    deny_if(not isinstance(checks, list), "CAPABILITY_ACCEPTANCE_PLAN_INVALID")
    if not checks:
        return {"verified_providers": [], "verified_checks": 0}
    config = _registry(policy)
    deny_if(config is None or not isinstance(apply_nonce, str) or
            NONCE.fullmatch(apply_nonce) is None,
            "CAPABILITY_ACCEPTANCE_NOT_ENROLLED")
    start = dt.datetime.fromisoformat(apply_started)
    deny_if(start.tzinfo is None, "CAPABILITY_ACCEPTANCE_INTENT_TIME_INVALID")
    by_provider: dict[str, list[dict]] = {}
    for item in checks:
        by_provider.setdefault(item["provider"], []).append(item)
    directory = receipts / "capability-acceptance"
    for piece in [directory, *directory.parents]:
        deny_if(piece.is_symlink(), "CAPABILITY_ACCEPTANCE_SYMLINK_DENIED")
    deny_if(not directory.is_dir() or directory.stat().st_uid != 0 or
            stat.S_IMODE(directory.stat().st_mode) & 0o077,
            "CAPABILITY_ACCEPTANCE_RECEIPT_DIRECTORY_UNTRUSTED")
    verified = []
    for provider, declared in sorted(by_provider.items()):
        enrolled = config["providers"].get(provider)
        deny_if(not isinstance(enrolled, dict) or
                enrolled.get("enabled") is not True,
                "CAPABILITY_ACCEPTANCE_PROVIDER_REVOKED")
        evidence = _private_file(directory / (apply_nonce + "." + provider + ".json"))
        deny_if(not isinstance(evidence, dict) or
                set(evidence) != {
                    "contract", "provider", "plan_sha256", "git_commit",
                    "site_uuid", "apply_nonce", "catalog_sha256",
                    "observed_at", "results", "signature"},
                "CAPABILITY_ACCEPTANCE_EVIDENCE_SCHEMA_INVALID")
        deny_if(evidence["contract"] != EVIDENCE_CONTRACT or
                evidence["provider"] != provider or
                evidence["plan_sha256"] != plan["plan_sha256"] or
                evidence["git_commit"] != plan["git_commit"] or
                evidence["site_uuid"] != plan["site_uuid"] or
                evidence["apply_nonce"] != apply_nonce or
                evidence["catalog_sha256"] !=
                    plan["capability_acceptance"]["catalog_sha256"],
                "CAPABILITY_ACCEPTANCE_EVIDENCE_BINDING_INVALID")
        observed = dt.datetime.fromisoformat(evidence["observed_at"])
        deny_if(observed.tzinfo is None or observed < start or
                observed > dt.datetime.now(dt.timezone.utc) +
                           dt.timedelta(seconds=60),
                "CAPABILITY_ACCEPTANCE_EVIDENCE_TIME_INVALID")
        expected = {(c["capability"], c["id"]) for c in declared}
        results = evidence["results"]
        deny_if(not isinstance(results, list) or
                any(not isinstance(r, dict) or
                    set(r) != {"capability", "id", "passed"} or
                    r.get("passed") is not True or
                    not isinstance(r.get("capability"), str) or
                    not isinstance(r.get("id"), str) for r in results) or
                {(r["capability"], r["id"]) for r in results} != expected or
                len(results) != len(expected),
                "CAPABILITY_ACCEPTANCE_CHECKS_NOT_PROVEN")
        payload = {k: v for k, v in evidence.items() if k != "signature"}
        try:
            key = Ed25519PublicKey.from_public_bytes(base64.b64decode(
                enrolled["public_key_b64"], validate=True))
            key.verify(base64.b64decode(evidence["signature"], validate=True),
                       canonical(payload))
        except Exception as exc:
            raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_SIGNATURE_INVALID") from exc
        verified.append(provider)
    return {"verified_providers": verified, "verified_checks": len(checks)}
