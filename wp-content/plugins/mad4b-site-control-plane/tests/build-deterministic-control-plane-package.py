#!/usr/bin/env python3
"""Build the canonical MAD4B Control Plane package reproducibly.

The package-internal provenance is intentionally producer-neutral. Workflow/run
identity belongs to outer attestation evidence; embedding it inside the plugin
would make two trusted workflows produce different bytes for the same source.

This builder:
- computes the package manifest excluding MAD4B-BUILD-PROVENANCE.json;
- writes deterministic canonical provenance into the package;
- emits build-fingerprint and manifest-digest sidecars;
- creates a deterministic ZIP with fixed ordering/timestamps/permissions;
- rejects symlinks, unsafe paths, oversized trees and duplicate logical paths.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import stat
import zipfile

CONTRACT = "mad4b.deterministic-control-plane-package.v1"
PROVENANCE_CONTRACT = "mad4b.build-provenance.v1"
ARCHIVE_FORMAT_CONTRACT = "mad4b.deterministic-zip.v1"
PROVENANCE_FILE = "MAD4B-BUILD-PROVENANCE.json"
MAX_FILES = 5000
MAX_TOTAL_BYTES = 512 * 1024 * 1024
MAX_FILE_BYTES = 128 * 1024 * 1024
FIXED_ZIP_TIME = (1980, 1, 1, 0, 0, 0)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def validate_hex(value: str, length: int, label: str) -> str:
    value = str(value).strip().lower()
    if len(value) != length or any(ch not in "0123456789abcdef" for ch in value):
        raise SystemExit(f"{label} must be {length} lowercase hex characters")
    return value


def logical_files(root: Path) -> list[Path]:
    root = root.resolve()
    if not root.is_dir():
        raise SystemExit(f"package root is not a directory: {root}")

    files: list[Path] = []
    total = 0
    logical_seen: set[str] = set()
    for path in sorted(root.rglob("*"), key=lambda p: p.relative_to(root).as_posix()):
        if path.is_symlink():
            raise SystemExit(f"symlinks are forbidden in canonical package: {path}")
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        posix = PurePosixPath(rel)
        if posix.is_absolute() or ".." in posix.parts or rel.startswith("/"):
            raise SystemExit(f"unsafe package path: {rel}")
        if rel == PROVENANCE_FILE:
            continue
        if rel in logical_seen:
            raise SystemExit(f"duplicate logical package path: {rel}")
        logical_seen.add(rel)
        size = path.stat().st_size
        if size > MAX_FILE_BYTES:
            raise SystemExit(f"package file exceeds bounded size: {rel}")
        total += size
        if total > MAX_TOTAL_BYTES:
            raise SystemExit("package tree exceeds bounded total size")
        files.append(path)

    if not files:
        raise SystemExit("canonical package tree is empty")
    if len(files) > MAX_FILES:
        raise SystemExit(f"package tree exceeds bounded file count: {len(files)}")
    return files


def manifest(root: Path, files: list[Path]) -> tuple[list[dict[str, object]], str]:
    entries: list[dict[str, object]] = []
    canonical: list[bytes] = []
    for path in files:
        rel = path.relative_to(root).as_posix()
        raw = path.read_bytes()
        digest = sha256_bytes(raw)
        entries.append({"path": rel, "bytes": len(raw), "sha256": digest})
        canonical.append(f"{rel}\0{len(raw)}\0{digest}\n".encode("utf-8"))
    digest = sha256_bytes(b"".join(canonical))
    return entries, digest


def provenance(
    *,
    version: str,
    source_sha: str,
    manifest_digest: str,
    adapter_version: str,
    adapter_sha: str,
    entries: list[dict[str, object]],
) -> dict[str, object]:
    payload = (
        f"mad4b.build-fingerprint.v1\n"
        f"{version}\n{source_sha}\n{manifest_digest}\n"
        f"{adapter_version}\n{adapter_sha}\n"
    ).encode("utf-8")
    build_fingerprint = sha256_bytes(payload)
    canonical_identity = f"mad4b-site-control-plane-{version}-{source_sha}"
    return {
        "contract": PROVENANCE_CONTRACT,
        "control_plane_version": version,
        "source_commit_sha": source_sha,
        "build_fingerprint": build_fingerprint,
        "package_manifest_digest": manifest_digest,
        "build_workflow": "MAD4B Canonical Package Builder",
        "build_run_id": "",
        "artifact_identity": canonical_identity,
        "mcp_adapter_version": adapter_version,
        "mcp_adapter_sha256": adapter_sha,
        "package_files": entries,
        "manifest_excludes_self": True,
        "generated_at_is_identity_neutral": True,
        "package_producer_metadata_external": True,
        "archive_format_contract": ARCHIVE_FORMAT_CONTRACT,
        "archive_compression": "stored",
        "canonical_archive_identity": canonical_identity,
    }


def zip_entry(name: str, raw: bytes) -> tuple[zipfile.ZipInfo, bytes]:
    info = zipfile.ZipInfo(name, FIXED_ZIP_TIME)
    info.compress_type = zipfile.ZIP_STORED
    info.create_system = 3
    info.external_attr = (stat.S_IFREG | 0o644) << 16
    info.extra = b""
    info.comment = b""
    return info, raw


def write_deterministic_zip(root: Path, output: Path, wrapper: str) -> None:
    package_files = logical_files(root)
    all_rows: list[tuple[str, bytes]] = []
    for path in package_files:
        all_rows.append((f"{wrapper}/{path.relative_to(root).as_posix()}", path.read_bytes()))
    prov_path = root / PROVENANCE_FILE
    if not prov_path.is_file():
        raise SystemExit("canonical provenance was not written before ZIP creation")
    all_rows.append((f"{wrapper}/{PROVENANCE_FILE}", prov_path.read_bytes()))
    all_rows.sort(key=lambda row: row[0])

    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(output.suffix + ".tmp")
    if tmp.exists():
        tmp.unlink()
    # ZIP_STORED avoids compressor-version drift. The package is small enough
    # that exact reproducibility across runner/zlib generations is worth the
    # modest size increase.
    with zipfile.ZipFile(tmp, "w", compression=zipfile.ZIP_STORED, strict_timestamps=True) as archive:
        for name, raw in all_rows:
            info, payload = zip_entry(name, raw)
            archive.writestr(info, payload, compress_type=zipfile.ZIP_STORED)
    os.replace(tmp, output)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--output-zip", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--adapter-version", default="0.6.1")
    parser.add_argument("--adapter-sha", required=True)
    parser.add_argument("--wrapper", default="mad4b-site-control-plane")
    parser.add_argument("--provenance-output", type=Path)
    parser.add_argument("--build-fingerprint-output", type=Path)
    parser.add_argument("--manifest-digest-output", type=Path)
    parser.add_argument("--receipt-output", type=Path)
    args = parser.parse_args()

    root = args.root.resolve()
    source = validate_hex(args.source_sha, 40, "source SHA")
    adapter_sha = validate_hex(args.adapter_sha, 64, "adapter SHA")
    if not args.version.strip():
        raise SystemExit("control-plane version is required")
    if not args.adapter_version.strip():
        raise SystemExit("adapter version is required")
    wrapper = args.wrapper.strip().strip("/")
    if not wrapper or "/" in wrapper or wrapper in (".", ".."):
        raise SystemExit("wrapper must be one safe top-level directory name")

    # Never let stale producer-specific provenance influence the canonical manifest.
    prov_path = root / PROVENANCE_FILE
    if prov_path.exists():
        if prov_path.is_symlink() or not prov_path.is_file():
            raise SystemExit("existing provenance path is not a regular file")
        prov_path.unlink()

    files = logical_files(root)
    entries, manifest_digest = manifest(root, files)
    data = provenance(
        version=args.version.strip(),
        source_sha=source,
        manifest_digest=manifest_digest,
        adapter_version=args.adapter_version.strip(),
        adapter_sha=adapter_sha,
        entries=entries,
    )
    encoded = (json.dumps(data, indent=2, sort_keys=True) + "\n").encode("utf-8")
    prov_path.write_bytes(encoded)

    write_deterministic_zip(root, args.output_zip.resolve(), wrapper)

    if args.provenance_output:
        args.provenance_output.parent.mkdir(parents=True, exist_ok=True)
        args.provenance_output.write_bytes(encoded)
    if args.build_fingerprint_output:
        args.build_fingerprint_output.parent.mkdir(parents=True, exist_ok=True)
        args.build_fingerprint_output.write_text(str(data["build_fingerprint"]) + "\n", encoding="utf-8")
    if args.manifest_digest_output:
        args.manifest_digest_output.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_digest_output.write_text(manifest_digest + "\n", encoding="utf-8")

    archive_sha = hashlib.sha256(args.output_zip.resolve().read_bytes()).hexdigest()
    receipt = {
        "contract": CONTRACT,
        "archive_format_contract": ARCHIVE_FORMAT_CONTRACT,
        "archive_compression": "stored",
        "source_commit_sha": source,
        "control_plane_version": args.version.strip(),
        "package_manifest_digest": manifest_digest,
        "build_fingerprint": data["build_fingerprint"],
        "archive_sha256": archive_sha,
        "archive_bytes": args.output_zip.resolve().stat().st_size,
        "package_file_count": len(entries) + 1,
        "producer_metadata_external": True,
        "deterministic_archive": True,
    }
    if args.receipt_output:
        args.receipt_output.parent.mkdir(parents=True, exist_ok=True)
        args.receipt_output.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(receipt, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
