#!/usr/bin/env python3
"""External trusted-runner receipt signer. Install separately from untrusted PR checkout.

This process must run AFTER a separately approved isolated build, outside the
source checkout. It never invokes a shell, downloads code, installs plugins or
reads a private key from the untrusted checkout. Signed receipts acknowledge
BUILT_UNVERIFIED only; never constitute release or Staging acceptance.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import time
import zipfile

CONTRACT = "mad4b.standalone-build-control.v1.receipt.v1"
SHA40 = re.compile(r"^[a-f0-9]{40}$")
SHA64 = re.compile(r"^[a-f0-9]{64}$")


class Refusal(Exception):
    pass


def require(value: bool, code: str) -> None:
    if not value:
        raise Refusal(code)


def json_file(path: Path) -> dict:
    require(path.is_file() and not path.is_symlink() and path.stat().st_size < 65536,
            "bounded_json_file_required")
    value = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(value, dict), "json_object_required")
    return value


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def verify_archive(archive: Path, canonical_receipt: dict, report: dict) -> None:
    require(archive.is_file() and not archive.is_symlink() and
            archive.stat().st_size <= 16 * 1024 * 1024, "archive_missing_or_oversized")
    require(canonical_receipt.get("archive_sha256") == report.get("archive_sha256") and
            canonical_receipt.get("archive_sha256") == sha(archive.read_bytes()),
            "archive_digest_mismatch")
    require(canonical_receipt.get("build_fingerprint") == report.get("build_fingerprint") and
            canonical_receipt.get("package_manifest_digest") == report.get("package_manifest_digest"),
            "canonical_report_drift")
    with zipfile.ZipFile(archive) as z:
        members = z.infolist()
        require(members and len(members) == canonical_receipt.get("archive_file_count") and
                z.testzip() is None, "archive_corrupt")
        names = [x.filename for x in members]
        require(len(names) == len(set(x.casefold() for x in names)), "archive_member_duplicate")
        for item in members:
            require(item.filename.startswith("mad4b-site-control-plane/") and
                    not item.filename.endswith("/") and
                    not item.filename.startswith("/") and
                    ".." not in item.filename.split("/") and
                    chr(92) not in item.filename and
                    item.compress_type == zipfile.ZIP_STORED and
                    stat.S_IFMT(item.external_attr >> 16) == stat.S_IFREG,
                    "unsafe_archive_entry")
        p = json.loads(z.read("mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"))
        entries = p.get("package_files")
        require(isinstance(entries, list) and
                len(entries) == canonical_receipt.get("manifest_file_count"),
                "canonical_manifest_invalid")
        manifest = []
        expected = {"mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"}
        for row in entries:
            name = row.get("path") if isinstance(row, dict) else None
            size = row.get("bytes") if isinstance(row, dict) else None
            digest = row.get("sha256") if isinstance(row, dict) else None
            require(isinstance(name, str) and name and
                    not name.startswith("/") and ".." not in name.split("/") and
                    chr(92) not in name and isinstance(size, int) and size >= 0 and
                    isinstance(digest, str) and SHA64.fullmatch(digest) is not None,
                    "manifest_entry_invalid")
            key = "mad4b-site-control-plane/" + name
            require(key in names and key not in expected, "manifest_file_missing_or_duplicate")
            raw = z.read(key)
            require(len(raw) == size and sha(raw) == digest, "manifest_file_hash_mismatch")
            expected.add(key)
            manifest.append(f"{name}\\0{size}\\0{digest}\\n".encode().replace(b"\\0", bytes([0])).replace(b"\\n", bytes([10])))
        require(set(names) == expected, "unmanifested_archive_member")
        require(sha(b"".join(manifest)) == report.get("package_manifest_digest") and
                p.get("build_fingerprint") == report.get("build_fingerprint"),
                "archive_manifest_or_build_drift")


def make_claims(claim: dict, policy: dict, report: dict, now: int | None = None) -> dict:
    now = int(time.time() if now is None else now)
    job = claim.get("job")
    require(isinstance(job, dict) and isinstance(job.get("payload"), dict),
            "leased_job_required")
    p = job["payload"]
    require(policy.get("contract") == "mad4b.standalone-build-runner-enrollment.v1" and
            policy.get("environment") == "staging" and
            policy.get("owner_approval") == "EXACT STAGING SOURCE BUILD" and
            policy.get("executor_id") == job.get("executor_id"),
            "trusted_runner_policy_mismatch")
    require(job.get("operation_id") == "standalone_source_build" and
            job.get("status") == "claimed" and
            job.get("provider_checkpoint") == "provider_returned" and
            not job.get("cancel_requested_at") and
            int(job.get("lease_expires_at_epoch") or 0) >= now,
            "active_finished_lease_required")
    require(p.get("contract") == "mad4b.standalone-build-control.v1.job.v1" and
            isinstance(p.get("expected_head"), str) and SHA40.fullmatch(p["expected_head"]) and
            isinstance(p.get("plan_sha256"), str) and SHA64.fullmatch(p["plan_sha256"]),
            "untrusted_job_payload")
    for key in ("site_uuid", "profile_digest", "origin"):
        require(p.get(key) == policy.get(key) and isinstance(p.get(key), str) and
                bool(p[key]), "site_enrollment_mismatch")
    require(p.get("expected_head") == policy.get("allowed_source_sha") and
            p.get("profile") == policy.get("profile") and
            p.get("profile") in ("build-only", "local-checks"),
            "unapproved_source_or_profile")
    require(report.get("source_commit_sha") == p["expected_head"] and
            report.get("profile") == p["profile"] and
            report.get("build_state") == "BUILT_UNVERIFIED" and
            report.get("production_authorized") is False,
            "build_report_untrusted")
    for key in ("archive_sha256", "build_fingerprint", "package_manifest_digest"):
        require(isinstance(report.get(key), str) and SHA64.fullmatch(report[key]),
                "invalid_build_identity")
    require(isinstance(job.get("claim_generation"), int) and
            job["claim_generation"] >= 1 and
            isinstance(job.get("job_id"), str) and len(job["job_id"]) == 36,
            "claim_generation_invalid")
    return {
        "contract": CONTRACT,
        "job_id": job["job_id"], "executor_id": policy["executor_id"],
        "claim_generation": job["claim_generation"],
        "target_source_sha": p["expected_head"], "plan_sha256": p["plan_sha256"],
        "profile": p["profile"], "site_uuid": p["site_uuid"],
        "profile_digest": p["profile_digest"], "origin": p["origin"],
        "archive_sha256": report["archive_sha256"],
        "build_fingerprint": report["build_fingerprint"],
        "package_manifest_digest": report["package_manifest_digest"],
        "build_state": "BUILT_UNVERIFIED", "issued_at_epoch": now,
        "expires_at_epoch": min(now + 300, int(job["lease_expires_at_epoch"])), "production_authorized": False,
    }


def sign_claims(claims: dict, key_file: Path) -> dict:
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    require(key_file.is_file() and not key_file.is_symlink() and
            key_file.stat().st_size < 8192, "enrolled_private_key_missing")
    if os.name != "nt":
        require((key_file.stat().st_mode & 0o077) == 0, "private_key_permissions_unsafe")
    raw_key = key_file.read_bytes()
    private = serialization.load_pem_private_key(raw_key, password=None)
    require(isinstance(private, Ed25519PrivateKey), "ed25519_key_required")
    raw = json.dumps(claims, sort_keys=True, separators=(",", ":")).encode()
    return {"claims_b64": base64.b64encode(raw).decode(),
            "signature_b64": base64.b64encode(private.sign(raw)).decode()}


def main() -> int:
    cli = argparse.ArgumentParser(description=__doc__)
    for field in ("job-claim", "policy", "build-report", "archive", "canonical-receipt",
                  "enrolled-private-key", "output"):
        cli.add_argument("--" + field, type=Path, required=True)
    args = cli.parse_args()
    try:
        policy = json_file(args.policy)
        claim = json_file(args.job_claim)
        report = json_file(args.build_report)
        receipt = json_file(args.canonical_receipt)
        require(args.output.parent.resolve() != args.archive.parent.resolve(),
                "signed_proof_must_be_separately_stored")
        verify_archive(args.archive, receipt, report)
        claims = make_claims(claim, policy, report)
        signed = sign_claims(claims, args.enrolled_private_key)
        require(not args.output.exists() and not args.output.is_symlink(),
                "signed_output_must_be_new")
        args.output.write_text(json.dumps(signed, sort_keys=True, indent=2) + "\n",
                               encoding="utf-8")
    except (Refusal, ValueError, OSError, KeyError, zipfile.BadZipFile, TypeError) as exc:
        print("BLOCKED: " + str(exc), file=sys.stderr)
        return 2
    print("SIGNED_BUILT_UNVERIFIED_RECEIPT_WRITTEN; publication_authorized=false")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
