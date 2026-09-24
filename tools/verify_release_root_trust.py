#!/usr/bin/env python3
"""Verify MAD4B release root trust without loading the WordPress runtime."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import zipfile
from pathlib import Path
from typing import Any

REPOSITORY = "mad4bdigital-ai/WordPress"
SIGNER_WORKFLOW = "mad4bdigital-ai/WordPress/.github/workflows/mad4b-control-plane-package.yml"
INSTALL_CONTRACT = "mad4b.site-control-plane.general-distribution-kit.v1"
PROVENANCE_CONTRACT = "mad4b.build-provenance.v1"
VERIFICATION_CONTRACT = "mad4b.release-root-trust-verification.v1"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def require_digest(value: str, label: str) -> str:
    value = value.strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", value):
        raise ValueError(f"{label} must be a 40- or 64-character hexadecimal digest")
    return value


def load_json(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return data


def build_attestation_command(
    artifact: Path,
    bundle: Path,
    signer_digest: str,
) -> list[str]:
    signer_digest = require_digest(signer_digest, "signer digest")
    return [
        "gh",
        "attestation",
        "verify",
        str(artifact),
        "--repo",
        REPOSITORY,
        "--bundle",
        str(bundle),
        "--signer-workflow",
        SIGNER_WORKFLOW,
        "--signer-digest",
        signer_digest,
        "--deny-self-hosted-runners",
        "--format",
        "json",
    ]


def verify_attestation(
    artifact: Path,
    bundle: Path,
    signer_digest: str,
) -> list[dict[str, Any]]:
    command = build_attestation_command(artifact, bundle, signer_digest)
    completed = subprocess.run(command, check=False, capture_output=True, text=True)
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout or "unknown verification error").strip()
        raise RuntimeError(f"release attestation verification failed: {detail}")
    result = json.loads(completed.stdout)
    if not isinstance(result, list) or not result:
        raise RuntimeError("release attestation verification returned no verified statements")
    return result


def verify_local_identity(
    artifact: Path,
    install_manifest_path: Path,
    expected_source_sha: str,
) -> dict[str, Any]:
    expected_source_sha = require_digest(expected_source_sha, "expected source SHA")
    if len(expected_source_sha) != 40:
        raise ValueError("expected source SHA must be a 40-character Git commit SHA")

    install = load_json(install_manifest_path)
    if install.get("contract") != INSTALL_CONTRACT:
        raise ValueError("install manifest contract mismatch")
    if str(install.get("repository", "")) != REPOSITORY:
        raise ValueError("install manifest repository mismatch")
    if str(install.get("commit", "")).lower() != expected_source_sha:
        raise ValueError("install manifest source commit mismatch")

    control = install.get("control_plane")
    if not isinstance(control, dict):
        raise ValueError("install manifest control_plane block missing")
    expected_archive = str(control.get("archive", ""))
    if artifact.name != expected_archive:
        raise ValueError(f"artifact filename mismatch: expected {expected_archive!r}")
    expected_artifact_sha = str(control.get("sha256", "")).lower()
    if not re.fullmatch(r"[0-9a-f]{64}", expected_artifact_sha):
        raise ValueError("install manifest control-plane SHA256 is invalid")
    actual_artifact_sha = sha256_file(artifact)
    if actual_artifact_sha != expected_artifact_sha:
        raise ValueError("control-plane archive SHA256 mismatch")

    with zipfile.ZipFile(artifact, "r") as archive:
        provenance_name = "mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json"
        try:
            provenance_raw = archive.read(provenance_name)
        except KeyError as exc:
            raise ValueError("build provenance is missing from control-plane archive") from exc
        provenance = json.loads(provenance_raw)
        if not isinstance(provenance, dict) or provenance.get("contract") != PROVENANCE_CONTRACT:
            raise ValueError("build provenance contract mismatch")
        if str(provenance.get("source_commit_sha", "")).lower() != expected_source_sha:
            raise ValueError("build provenance source commit mismatch")

        install_build = str(install.get("build_fingerprint", "")).lower()
        install_package = str(install.get("package_manifest_digest", "")).lower()
        if str(provenance.get("build_fingerprint", "")).lower() != install_build:
            raise ValueError("build fingerprint mismatch between install manifest and provenance")
        if str(provenance.get("package_manifest_digest", "")).lower() != install_package:
            raise ValueError("package manifest digest mismatch between install manifest and provenance")
        if not re.fullmatch(r"[0-9a-f]{64}", install_build):
            raise ValueError("build fingerprint is invalid")
        if not re.fullmatch(r"[0-9a-f]{64}", install_package):
            raise ValueError("package manifest digest is invalid")

        canonical: list[bytes] = []
        package_files = provenance.get("package_files")
        if not isinstance(package_files, list) or not package_files:
            raise ValueError("provenance package_files is missing")
        for row in package_files:
            if not isinstance(row, dict):
                raise ValueError("invalid provenance package row")
            relative = str(row.get("path", ""))
            if not relative or relative.startswith("/") or ".." in Path(relative).parts:
                raise ValueError(f"unsafe provenance package path: {relative!r}")
            expected_size = int(row.get("bytes", -1))
            expected_sha = str(row.get("sha256", "")).lower()
            if not re.fullmatch(r"[0-9a-f]{64}", expected_sha):
                raise ValueError(f"invalid provenance digest for {relative}")
            zip_name = f"mad4b-site-control-plane/{relative}"
            try:
                raw = archive.read(zip_name)
            except KeyError as exc:
                raise ValueError(f"provenance file missing from archive: {relative}") from exc
            actual_sha = hashlib.sha256(raw).hexdigest()
            if len(raw) != expected_size or actual_sha != expected_sha:
                raise ValueError(f"provenance file mismatch: {relative}")
            canonical.append(f"{relative}\0{len(raw)}\0{actual_sha}\n".encode())

    calculated_package_digest = hashlib.sha256(b"".join(sorted(canonical))).hexdigest()
    if calculated_package_digest != install_package:
        raise ValueError("recomputed package manifest digest mismatch")

    return {
        "artifact_sha256": actual_artifact_sha,
        "source_commit_sha": expected_source_sha,
        "build_fingerprint": install_build,
        "package_manifest_digest": install_package,
        "control_plane_version": str(control.get("version", "")),
    }


def verify(
    artifact: Path,
    bundle: Path,
    install_manifest: Path,
    expected_source_sha: str,
    signer_digest: str,
) -> dict[str, Any]:
    local = verify_local_identity(artifact, install_manifest, expected_source_sha)
    verified = verify_attestation(artifact, bundle, signer_digest)
    return {
        "contract": VERIFICATION_CONTRACT,
        "verified": True,
        "attestation_verified": True,
        "runtime_self_attestation_authoritative": False,
        "verification_boundary": "external_release_verifier",
        "repository": REPOSITORY,
        "signer_workflow": SIGNER_WORKFLOW,
        "signer_digest": require_digest(signer_digest, "signer digest"),
        "verified_attestation_count": len(verified),
        **local,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact", required=True, type=Path)
    parser.add_argument("--bundle", required=True, type=Path)
    parser.add_argument("--install-manifest", required=True, type=Path)
    parser.add_argument("--expected-source-sha", required=True)
    parser.add_argument("--trusted-signer-digest", required=True)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    for path in (args.artifact, args.bundle, args.install_manifest):
        if not path.is_file():
            parser.error(f"file not found: {path}")

    try:
        receipt = verify(
            args.artifact,
            args.bundle,
            args.install_manifest,
            args.expected_source_sha,
            args.trusted_signer_digest,
        )
    except (ValueError, RuntimeError, OSError, json.JSONDecodeError, zipfile.BadZipFile) as exc:
        print(f"ROOT_TRUST_VERIFY: FAIL: {exc}", file=sys.stderr)
        return 1

    encoded = json.dumps(receipt, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.write_text(encoded, encoding="utf-8")
    sys.stdout.write(encoded)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
