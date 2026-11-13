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
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
from pathlib import PurePosixPath
from typing import Callable

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

# Only the first-party, root-owned Original 313 registry may certify a
# dynamic producer as installed. Operations themselves remain data-driven.
ORIGINAL_REGISTRY_ROOT = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
CATALOG_CONTRACT = "mad4b.company-capability-acceptance-catalog.v1"
REGISTRY_CONTRACT = "mad4b.company-capability-acceptance-registry.v1"
SNAPSHOT_CONTRACT = "mad4b.company-capability-acceptance-snapshot.v1"
EVIDENCE_CONTRACT = "mad4b.company-capability-acceptance-evidence.v1"
EVIDENCE_V2_CONTRACT = "mad4b.company-capability-acceptance-evidence.v2"
WITNESS_CONTRACT = "mad4b.company-capability-observation-witness.v1"
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
            len(config["providers"]) > 256 or
            not isinstance(config.get("require_coverage", True), bool) or
            not isinstance(config.get("require_impact_coverage", False), bool),
            "CAPABILITY_ACCEPTANCE_REGISTRY_INVALID")
    for provider, item in config["providers"].items():
        _id(provider)
        deny_if(not isinstance(item, dict) or
                not isinstance(item.get("enabled"), bool) or
                not isinstance(item.get("capabilities"), list) or
                not all(isinstance(c, str) and ID.fullmatch(c)
                        for c in item["capabilities"]) or
                len(item["capabilities"]) != len(set(item["capabilities"])) or
                not isinstance(item.get("public_key_b64"), str) or
                not isinstance(item.get("producer_operation_id"), str) or
                ID.fullmatch(item["producer_operation_id"]) is None or
                not isinstance(item.get("producer_manifest_sha256"), str) or
                re.fullmatch(r"[a-f0-9]{64}",
                             item["producer_manifest_sha256"]) is None,
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
                "require_impact_coverage": False, "impact_requirements": [],
                "checks": [], "enrollment_sha256": None}
    raw = git_bytes(repo, commit, config["catalog_path"])
    deny_if(len(raw) > 200_000, "CAPABILITY_ACCEPTANCE_CATALOG_TOO_LARGE")
    try:
        catalog = json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_CATALOG_INVALID") from exc
    deny_if(not isinstance(catalog, dict) or
            set(catalog) not in ({"contract", "checks"},
                                 {"contract", "checks", "requirements"}) or
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
                    PurePosixPath(t).as_posix() != t or
                    ".." in PurePosixPath(t).parts or
                    chr(92) in t or "//" in t or chr(0) in t
                    for t in targets) or
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
                enrolled.get("enabled") is not True or
                capability not in enrolled["capabilities"],
                "CAPABILITY_ACCEPTANCE_PROVIDER_NOT_ENROLLED")
        covered.update(matched)
        records.append({"provider": provider, "capability": capability,
                        "id": check_id, "targets": matched})
    deny_if(config.get("require_coverage", True) and covered != selected,
            "CAPABILITY_ACCEPTANCE_COVERAGE_MISSING")
    # A full-file check does not prove that all affected functional
    # capabilities were observed. Require a declared, provider-owned impact
    # graph and a matching check for every (target, provider, capability).
    reqs = catalog.get("requirements", [])
    deny_if(not isinstance(reqs, list) or len(reqs) > 500,
            "CAPABILITY_ACCEPTANCE_IMPACT_GRAPH_INVALID")
    required, impact_covered = set(), set()
    for relation in reqs:
        deny_if(not isinstance(relation, dict) or
                set(relation) != {"target", "provider", "capabilities"} or
                not isinstance(relation.get("target"), str) or
                not permitted_target(relation["target"]) or
                not isinstance(relation.get("capabilities"), list) or
                not 1 <= len(relation["capabilities"]) <= 100,
                "CAPABILITY_ACCEPTANCE_IMPACT_RELATION_INVALID")
        target = relation["target"]
        provider = _id(relation["provider"])
        caps = [_id(c) for c in relation["capabilities"]]
        deny_if(len(set(caps)) != len(caps),
                "CAPABILITY_ACCEPTANCE_IMPACT_DUPLICATE")
        if target not in selected:
            continue
        entry = config["providers"].get(provider)
        deny_if(not isinstance(entry, dict) or
                entry.get("enabled") is not True or
                not set(caps).issubset(set(entry["capabilities"])),
                "CAPABILITY_ACCEPTANCE_IMPACT_OWNER_UNENROLLED")
        for capability in caps:
            key = (target, provider, capability)
            deny_if(key in required,
                    "CAPABILITY_ACCEPTANCE_IMPACT_DUPLICATE")
            required.add(key)
        impact_covered.add(target)
    actual = {(target, row["provider"], row["capability"])
              for row in records for target in row["targets"]}
    deny_if(not required.issubset(actual),
            "CAPABILITY_ACCEPTANCE_IMPACT_CHECK_MISSING")
    deny_if(config.get("require_impact_coverage", False) and
            impact_covered != selected,
            "CAPABILITY_ACCEPTANCE_IMPACT_GRAPH_INCOMPLETE")
    # A source-only registration does not grant authority; the trust anchors
    # must already exist in the installed root-owned policy.
    enrollment = {
        provider: {"public_key_sha256": sha(base64.b64decode(
                        config["providers"][provider]["public_key_b64"],
                        validate=True)),
                   "capabilities": sorted(config["providers"][provider]["capabilities"]),
                   "producer_operation_id": config["providers"][provider][
                       "producer_operation_id"],
                   "producer_manifest_sha256": config["providers"][provider][
                       "producer_manifest_sha256"]}
        for provider in sorted({r["provider"] for r in records})
    }
    return {"contract": SNAPSHOT_CONTRACT, "catalog_sha256": sha(raw),
            "catalog_path": config["catalog_path"],
            "require_coverage": config.get("require_coverage", True),
            "require_impact_coverage": config.get("require_impact_coverage", False),
            "impact_requirements": [list(x) for x in sorted(required)],
            "checks": sorted(records, key=lambda r: (r["provider"], r["id"])),
            "enrollment_sha256": sha(canonical(enrollment))}


def verify_producer_enrollment(
    plan: dict, policy: dict,
    registry_factory: Callable | None = None,
) -> dict:
    """Fail BEFORE any code write when a signed-proof producer is not installed.

    A root-owned key entry is not an executable capability. The first-party
    Dynamic Registry must actually have the exact independently pinned
    producer operation. Never import or run a Git-catalog callback.
    """
    selected = plan.get("capability_acceptance", {}).get("checks", [])
    deny_if(not isinstance(selected, list),
            "CAPABILITY_ACCEPTANCE_PLAN_INVALID")
    if not selected:
        return {"producer_operations": []}
    config = _registry(policy)
    deny_if(config is None, "CAPABILITY_ACCEPTANCE_NOT_ENROLLED")
    if registry_factory is None:
        root = ORIGINAL_REGISTRY_ROOT
        for current in (root, root / "registry.py"):
            deny_if(current.is_symlink(),
                    "CAPABILITY_ACCEPTANCE_REGISTRY_SYMLINK_DENIED")
        deny_if(not root.is_dir() or not (root / "registry.py").is_file(),
                "CAPABILITY_ACCEPTANCE_ORIGINAL_REGISTRY_MISSING")
        for current in (root, root / "registry.py"):
            st = current.stat()
            deny_if(st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022,
                    "CAPABILITY_ACCEPTANCE_ORIGINAL_REGISTRY_UNTRUSTED")
        spec = importlib.util.spec_from_file_location(
            "_mad4b_acceptance_original_registry", root / "registry.py")
        deny_if(spec is None or spec.loader is None,
                "CAPABILITY_ACCEPTANCE_ORIGINAL_REGISTRY_UNAVAILABLE")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        registry_factory = lambda: module.Registry(root)
    registry = registry_factory()
    observed = []
    for provider in sorted({row["provider"] for row in selected}):
        entry = config["providers"].get(provider)
        deny_if(not isinstance(entry, dict),
                "CAPABILITY_ACCEPTANCE_PROVIDER_NOT_ENROLLED")
        operation_id = entry["producer_operation_id"]
        try:
            manifest = registry.resolve(operation_id)
        except Exception as exc:
            raise AcceptanceDenied(
                "CAPABILITY_ACCEPTANCE_PRODUCER_NOT_REGISTERED") from exc
        deny_if(not isinstance(manifest, dict) or
                manifest.get("operation_id") != operation_id or
                manifest.get("provider") != provider or
                not callable(getattr(registry, "manifest_sha256", None)) or
                registry.manifest_sha256(operation_id) !=
                    entry["producer_manifest_sha256"],
                "CAPABILITY_ACCEPTANCE_PRODUCER_MANIFEST_DRIFT")
        observed.append(operation_id)
    return {"producer_operations": observed}


def postimage_fingerprint(plan: dict, observed: dict) -> str:
    """Digest one verified postimage without trusting a Provider's summary."""
    expected = {row["target"]: row["source_sha256"] for row in plan["files"]}
    deny_if(observed != expected, "CAPABILITY_ACCEPTANCE_POSTIMAGE_MISMATCH")
    return sha(canonical({"plan_sha256": plan["plan_sha256"],
                          "site_uuid": plan["site_uuid"],
                          "observed": observed}))


def _private_file(path: Path) -> tuple[dict, str]:
    for piece in [path, *path.parents]:
        deny_if(piece.is_symlink(), "CAPABILITY_ACCEPTANCE_SYMLINK_DENIED")
    parent = path.parent.stat()
    deny_if(not stat.S_ISDIR(parent.st_mode) or parent.st_uid != 0 or
            stat.S_IMODE(parent.st_mode) & 0o077,
            "CAPABILITY_ACCEPTANCE_EVIDENCE_PARENT_UNTRUSTED")
    try:
        fd = os.open(str(path), os.O_RDONLY |
                     getattr(os, "O_NOFOLLOW", 0) |
                     getattr(os, "O_CLOEXEC", 0))
    except FileNotFoundError as exc:
        raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_EVIDENCE_MISSING") from exc
    try:
        st = os.fstat(fd)
        deny_if(not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or
                st.st_nlink != 1 or
                stat.S_IMODE(st.st_mode) & 0o077 or st.st_size > 100_000,
                "CAPABILITY_ACCEPTANCE_EVIDENCE_UNTRUSTED")
        with os.fdopen(fd, "rb") as stream:
            fd = -1
            raw = stream.read(100_001)
        deny_if(len(raw) > 100_000,
                "CAPABILITY_ACCEPTANCE_EVIDENCE_TOO_LARGE")
        try:
            return json.loads(raw), sha(raw)
        except (ValueError, TypeError) as exc:
            raise AcceptanceDenied(
                "CAPABILITY_ACCEPTANCE_EVIDENCE_INVALID") from exc
    finally:
        if fd >= 0:
            os.close(fd)


def _witness_schema(enrolled: dict, check_id: str) -> dict:
    schemas = enrolled.get("witness_schemas")
    deny_if(not isinstance(schemas, dict) or
            not isinstance(schemas.get(check_id), dict) or
            not 1 <= len(schemas[check_id]) <= 50,
            "CAPABILITY_ACCEPTANCE_WITNESS_SCHEMA_MISSING")
    for field, definition in schemas[check_id].items():
        _id(field)
        deny_if(not isinstance(definition, dict) or
                set(definition) not in ({"type"}, {"type", "equals"}) or
                definition.get("type") not in
                    ("integer", "boolean", "string", "sha256"),
                "CAPABILITY_ACCEPTANCE_WITNESS_SCHEMA_INVALID")
    return schemas[check_id]


def validate_witness(witness: dict, *, plan: dict, enrolled: dict,
                     provider: str, result: dict, apply_nonce: str,
                     postimage_sha256: str, challenge: str,
                     postimage_verified_at: dt.datetime,
                     proof_observed_at: dt.datetime) -> None:
    """Typed, policy-owned measurements, not an ungrounded success label."""
    fields = _witness_schema(enrolled, result["id"])
    deny_if(not isinstance(witness, dict) or set(witness) != {
                "contract", "provider", "producer_operation_id", "site_uuid",
                "apply_nonce", "postimage_sha256", "challenge",
                "capability", "id", "observed_at", "readings"} or
            witness["contract"] != WITNESS_CONTRACT or
            witness["provider"] != provider or
            witness["producer_operation_id"] !=
                enrolled["producer_operation_id"] or
            witness["site_uuid"] != plan["site_uuid"] or
            witness["apply_nonce"] != apply_nonce or
            witness["postimage_sha256"] != postimage_sha256 or
            witness["challenge"] != challenge or
            witness["capability"] != result["capability"] or
            witness["id"] != result["id"] or
            not isinstance(witness["readings"], dict) or
            set(witness["readings"]) != set(fields),
            "CAPABILITY_ACCEPTANCE_WITNESS_BINDING_INVALID")
    try:
        observed_at = dt.datetime.fromisoformat(witness["observed_at"])
    except (TypeError, ValueError) as exc:
        raise AcceptanceDenied("CAPABILITY_ACCEPTANCE_WITNESS_TIME_INVALID") from exc
    deny_if(observed_at.tzinfo is None or
            observed_at < postimage_verified_at or
            observed_at > proof_observed_at,
            "CAPABILITY_ACCEPTANCE_WITNESS_TIME_INVALID")
    for name, definition in fields.items():
        value = witness["readings"][name]
        kind = definition["type"]
        valid = (
            (kind == "integer" and type(value) is int) or
            (kind == "boolean" and type(value) is bool) or
            (kind == "string" and isinstance(value, str) and
             0 < len(value) <= 1024) or
            (kind == "sha256" and isinstance(value, str) and
             re.fullmatch(r"[a-f0-9]{64}", value) is not None)
        )
        deny_if(not valid or
                ("equals" in definition and value != definition["equals"]),
                "CAPABILITY_ACCEPTANCE_WITNESS_READING_INVALID:" + name)


def verify_provider_payload(
    evidence: dict, *, plan: dict, enrolled: dict, provider: str,
    declared: list[dict], apply_nonce: str, postimage_verified_at: dt.datetime,
    challenge: str, postimage_sha256: str,
) -> None:
    """Verify signed provider semantics independently of receipt file I/O."""
    deny_if(not isinstance(evidence, dict) or
            set(evidence) != {
                "contract", "provider", "plan_sha256", "git_commit",
                "site_uuid", "apply_nonce", "catalog_sha256",
                "observed_at", "postimage_sha256", "challenge",
                "producer_operation_id", "results", "signature"},
            "CAPABILITY_ACCEPTANCE_EVIDENCE_SCHEMA_INVALID")
    requiring_witnesses = enrolled.get("require_witnesses") is True
    expected_contract = (
        EVIDENCE_V2_CONTRACT if requiring_witnesses else EVIDENCE_CONTRACT)
    deny_if(evidence["contract"] != expected_contract or
            evidence["provider"] != provider or
            evidence["producer_operation_id"] !=
                enrolled["producer_operation_id"] or
            evidence["plan_sha256"] != plan["plan_sha256"] or
            evidence["git_commit"] != plan["git_commit"] or
            evidence["site_uuid"] != plan["site_uuid"] or
            evidence["apply_nonce"] != apply_nonce or
            evidence["challenge"] != challenge or
            evidence["postimage_sha256"] != postimage_sha256 or
            evidence["catalog_sha256"] !=
                plan["capability_acceptance"]["catalog_sha256"],
            "CAPABILITY_ACCEPTANCE_EVIDENCE_BINDING_INVALID")
    observed = dt.datetime.fromisoformat(evidence["observed_at"])
    deny_if(observed.tzinfo is None or observed < postimage_verified_at or
            observed > dt.datetime.now(dt.timezone.utc) +
                       dt.timedelta(seconds=60),
            "CAPABILITY_ACCEPTANCE_EVIDENCE_TIME_INVALID")
    expected = {(c["capability"], c["id"]) for c in declared}
    results = evidence["results"]
    fields = ({"capability", "id", "passed", "witness_sha256"}
              if requiring_witnesses else {"capability", "id", "passed"})
    deny_if(not isinstance(results, list) or
            any(not isinstance(r, dict) or
                set(r) != fields or
                r.get("passed") is not True or
                (requiring_witnesses and (
                    not isinstance(r.get("witness_sha256"), str) or
                    re.fullmatch(r"[a-f0-9]{64}",
                                 r["witness_sha256"]) is None)) or
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


def verify_evidence(plan: dict, policy: dict, receipts: Path,
                    apply_nonce: str, postimage_verified_at: str,
                    challenge: str, postimage_sha256: str) -> dict:
    """Verify signed, exact-plan provider evidence after Apply.

    Independent verification is intentionally separate from the Host's
    file-integrity receipt. Provider-specific checks are not executable input.
    """
    checks = plan.get("capability_acceptance", {}).get("checks", [])
    deny_if(not isinstance(checks, list), "CAPABILITY_ACCEPTANCE_PLAN_INVALID")
    deny_if(not isinstance(challenge, str) or
            re.fullmatch(r"[a-f0-9]{64}", challenge) is None or
            not isinstance(postimage_sha256, str) or
            re.fullmatch(r"[a-f0-9]{64}", postimage_sha256) is None,
            "CAPABILITY_ACCEPTANCE_POSTIMAGE_CHALLENGE_INVALID")
    if not checks:
        return {"verified_providers": [], "verified_checks": 0,
                "evidence_sha256": {}}
    config = _registry(policy)
    deny_if(config is None or not isinstance(apply_nonce, str) or
            NONCE.fullmatch(apply_nonce) is None,
            "CAPABILITY_ACCEPTANCE_NOT_ENROLLED")
    start = dt.datetime.fromisoformat(postimage_verified_at)
    deny_if(start.tzinfo is None, "CAPABILITY_ACCEPTANCE_POSTIMAGE_TIME_INVALID")
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
    evidence_digests = {}
    for provider, declared in sorted(by_provider.items()):
        enrolled = config["providers"].get(provider)
        deny_if(not isinstance(enrolled, dict) or
                enrolled.get("enabled") is not True,
                "CAPABILITY_ACCEPTANCE_PROVIDER_REVOKED")
        evidence, evidence_digest = _private_file(
            directory / (apply_nonce + "." + provider + ".json"))
        verify_provider_payload(
            evidence, plan=plan, enrolled=enrolled, provider=provider,
            declared=declared, apply_nonce=apply_nonce,
            postimage_verified_at=start, challenge=challenge,
            postimage_sha256=postimage_sha256)
        if enrolled.get("require_witnesses") is True:
            for observation in evidence["results"]:
                check_id = _id(observation["id"])
                witness_path = (directory / "witnesses" /
                                (apply_nonce + "." + provider + "." +
                                 check_id + ".json"))
                doc, witness_sha = _private_file(witness_path)
                deny_if(witness_sha != observation["witness_sha256"],
                        "CAPABILITY_ACCEPTANCE_WITNESS_DIGEST_DRIFT")
                validate_witness(
                    doc, plan=plan, enrolled=enrolled, provider=provider,
                    result=observation, apply_nonce=apply_nonce,
                    postimage_sha256=postimage_sha256, challenge=challenge,
                    postimage_verified_at=start,
                    proof_observed_at=dt.datetime.fromisoformat(
                        evidence["observed_at"]))
        verified.append(provider)
        evidence_digests[provider] = evidence_digest
    return {"verified_providers": verified, "verified_checks": len(checks),
            "evidence_sha256": evidence_digests}
