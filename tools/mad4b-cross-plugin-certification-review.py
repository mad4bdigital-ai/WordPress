#!/usr/bin/env python3
"""Independent, bounded ZIP+provenance candidate verification (NOT a certificate).

Inputs are an exact candidate archive and its builder-generated receipt.
This review validates integrity and prepares review evidence. It never
modifies WordPress, signs/approves a release, or publishes an authority.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile

HEX40 = re.compile(r"^[0-9a-f]{40}$")
HEX64 = re.compile(r"^[0-9a-f]{64}$")
MAX_ENTRIES = 25000
MAX_UNCOMPRESSED = 512 * 1024 * 1024
MAX_COMPRESSED = 64 * 1024 * 1024


def verify(registry_path, source_id, archive_path, receipt_path):
    registry = json.loads(Path(registry_path).read_text(encoding="utf8"))
    if registry.get("contract") != "mad4b.cross-plugin-sources.v1":
        raise ValueError("unreviewed registry")
    entries = [x for x in registry.get("sources", []) if x.get("id") == source_id]
    if len(entries) != 1:
        raise ValueError("ambiguous/unreviewed source")
    source = entries[0]
    if source.get("review_state") not in {"source_reviewed_artifact_pending", "source_reviewed_artifact_certified"}:
        raise ValueError("source not reviewed")
    receipt = json.loads(Path(receipt_path).read_text(encoding="utf8"))
    sha = source.get("candidate_source_commit_sha") or source.get("source_commit_sha") or ""
    version = source.get("candidate_artifact_version") or source.get("artifact_version") or ""
    if (not isinstance(sha, str) or not HEX40.fullmatch(sha)
        or receipt.get("source_id") != source_id
        or receipt.get("repository") != source.get("repository")
        or receipt.get("source_commit_sha") != sha
        or receipt.get("version") != version
        or receipt.get("plugin_file") != source.get("plugin_file")
        or receipt.get("plugin_subdirectory") != source.get("plugin_subdirectory")
        or receipt.get("contract") != "mad4b.cross-plugin-candidate-artifact.v1"
        or receipt.get("outcome_authorizes_deployment") is not False
        or receipt.get("production_promotion_granted") is not False):
        raise ValueError("candidate receipt does not match exact source")
    archive = Path(archive_path)
    size = archive.stat().st_size
    if not 0 < size <= MAX_COMPRESSED or size != receipt.get("archive_bytes"):
        raise ValueError("invalid archive byte budget or receipt size")
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if not HEX64.fullmatch(str(receipt.get("archive_sha256", ""))) or digest != receipt["archive_sha256"]:
        raise ValueError("ZIP SHA256 changed since builder receipt")
    slug = PurePosixPath(source["plugin_subdirectory"]).name
    files = []
    seen = set()
    total = 0
    with zipfile.ZipFile(archive) as zipped:
        if len(zipped.infolist()) > MAX_ENTRIES:
            raise ValueError("too many archive entries")
        for info in zipped.infolist():
            path = PurePosixPath(info.filename)
            if (info.is_dir() or info.filename in seen or path.is_absolute()
                or len(path.parts) < 2 or path.parts[0] != slug
                or any(p in {"", ".", ".."} for p in path.parts)
                or info.file_size < 0):
                raise ValueError("unsafe/duplicated ZIP entry")
            seen.add(info.filename)
            mode = (info.external_attr >> 16) & 0xffff
            if mode and not stat.S_ISREG(mode):
                raise ValueError("ZIP contains non-regular or symbolic-link entry")
            total += info.file_size
            if total > MAX_UNCOMPRESSED:
                raise ValueError("decompressed archive exceeds budget")
            contents = zipped.read(info)
            files.append({"path": info.filename, "sha256": hashlib.sha256(contents).hexdigest(),
                          "bytes": len(contents)})
    files.sort(key=lambda x: x["path"])
    if not files or slug + "/" + source["plugin_file"].split("/", 1)[1] not in seen:
        raise ValueError("plugin header/entrypoint missing")
    if receipt.get("archive_file_count") != len(files):
        raise ValueError("archive file-count mismatch")
    manifest_sha = hashlib.sha256(json.dumps(
        files, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    if manifest_sha != receipt.get("archive_manifest_sha256"):
        raise ValueError("internal file manifest does not match receipt")
    return {"contract": "mad4b.cross-plugin-certification-evidence.v1",
            "phase": "certification", "state": "VERIFIED_CANDIDATE_NEEDS_INDEPENDENT_REVIEW",
            "source_id": source_id, "repository": source["repository"], "source_commit_sha": sha,
            "version": version, "archive_sha256": digest, "archive_bytes": size,
            "archive_file_count": len(files), "archive_manifest_sha256": manifest_sha,
            "independent_review_required": True,
            "source_registry_certified": False,
            "staging_apply_authorized": False,
            "production_promotion_authorized": False,
            "mutation_performed": False}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", required=True)
    ap.add_argument("--source-id", required=True)
    ap.add_argument("--zip", required=True)
    ap.add_argument("--receipt", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    try:
        result = verify(args.manifest, args.source_id, args.zip, args.receipt)
        Path(args.out).write_text(json.dumps(result, sort_keys=True, indent=2) + "\n", encoding="utf8")
    except (ValueError, OSError, KeyError, IndexError, zipfile.BadZipFile, json.JSONDecodeError) as exc:
        print("CROSS_PLUGIN_CERTIFICATION_PREFLIGHT_REJECTED: " + str(exc), file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
