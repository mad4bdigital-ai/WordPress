#!/usr/bin/env python3
"""Unpack immutable WordPress vendor ZIPs into isolated analysis branches.

Data-only archive handling: never imports/executes vendor code. No site writes,
production deployment, PR merge, mutation grant, or vendor plugin activation.
Refuses traversal, symlinks, oversize members, and unexpected branch drift.
"""
from __future__ import annotations
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import sys
import zipfile

SOURCE = "f9b08155a68e2da01ac81edb0202c0d4471efc0d"
CONTROLLER = "research/vendor-unpack-controller-20261019"
BRANCHES = {
    "research/vendor-jet-smart-filters-20261019": ["jet-smart-filters.zip"],
    "research/vendor-jet-engine-20261019": ["jet-engine.zip"],
    "research/vendor-rank-math-20261019": ["seo-by-rank-math.zip", "seo-by-rank-math-pro.zip"],
    "research/vendor-elementor-20261019": ["elementor.zip", "elementor-pro.zip"],
    "research/vendor-wpml-20261019": ["sitepress-multilingual-cms.zip", "wp-seo-multilingual.zip", "wpml-string-translation.zip"],
    "research/vendor-litespeed-20261019": ["litespeed-cache.zip"],
}
MAX_FILES_PER_ARCHIVE = 32000
MAX_UNCOMPRESSED_ARCHIVE = 1_300_000_000
MAX_SINGLE_FILE = 95_000_000
MAX_RATIO = 1500.0

def git(*args, binary=False):
    p = subprocess.run(["git", *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.stdout if binary else p.stdout.decode().strip()

def validate_name(raw: str) -> tuple[str, ...]:
    if not raw or "\\" in raw or "\x00" in raw or raw.startswith("/"):
        raise ValueError("Malformed vendor archive path")
    path = PurePosixPath(raw)
    parts = path.parts
    if not parts or any(p in ("", ".", "..") for p in parts) or ":" in parts[0]:
        raise ValueError("Traversal or absolute archive path rejected")
    return parts

def is_symlink(member: zipfile.ZipInfo) -> bool:
    return member.create_system == 3 and (member.external_attr >> 16 & 0o170000) == 0o120000

def unpack_archive(package: str, output: Path) -> dict:
    source_path = "wp-content/plugins/" + package
    blob_sha = git("rev-parse", f"{SOURCE}:{source_path}")
    raw = git("show", f"{SOURCE}:{source_path}", binary=True)
    archive_sha = hashlib.sha256(raw).hexdigest()
    if len(raw) > 120_000_000:
        raise ValueError(f"{package}: raw archive exceeds source audit ceiling")
    count = 0
    total = 0
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        members = archive.infolist()
        if len(members) > MAX_FILES_PER_ARCHIVE:
            raise ValueError(f"{package}: unexpectedly many files")
        for item in members:
            parts = validate_name(item.filename)
            if is_symlink(item):
                raise ValueError(f"{package}: symbolic link blocked")
            if item.is_dir():
                continue
            if item.file_size > MAX_SINGLE_FILE:
                raise ValueError(f"{package}: oversized member blocked")
            if item.compress_size and item.file_size / item.compress_size > MAX_RATIO:
                raise ValueError(f"{package}: possible ZIP bomb member blocked")
            count += 1
            total += item.file_size
            if total > MAX_UNCOMPRESSED_ARCHIVE:
                raise ValueError(f"{package}: uncompressed archive size ceiling exceeded")
            target = output.joinpath(*parts)
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(item) as stream, target.open("wb") as out:
                shutil.copyfileobj(stream, out, 1024 * 1024)
            if target.stat().st_size != item.file_size:
                raise ValueError(f"{package}: incomplete extraction")
    return {
        "archive": source_path,
        "source_commit": SOURCE,
        "git_blob_sha1": blob_sha,
        "archive_sha256": archive_sha,
        "archive_bytes": len(raw),
        "files": count,
        "uncompressed_bytes": total,
        "extract_root": output.as_posix(),
        "certification": "source_inspection_only_not_runtime_attestation",
    }

def main():
    if os.environ.get("GITHUB_REPOSITORY") != "mad4bdigital-ai/WordPress":
        raise SystemExit("Wrong repository; refusing to modify source refs")
    if git("cat-file", "-t", SOURCE) != "commit":
        raise SystemExit("Pinned source commit is unavailable")
    git("config", "user.name", "MAD4B Vendor Research Automation")
    git("config", "user.email", "41898282+github-actions[bot]@users.noreply.github.com")
    for branch, packages in BRANCHES.items():
        remote_ref = "refs/remotes/origin/" + branch
        observed = git("rev-parse", remote_ref)
        if observed != SOURCE:
            # A prior run can have completed this isolated branch before a
            # later branch failed; re-verify provenance rather than overwriting.
            try:
                existing = json.loads(git("show", f"{observed}:vendor-unpacked/PROVENANCE.json"))
            except (subprocess.CalledProcessError, json.JSONDecodeError):
                raise SystemExit(f"{branch}: diverged without valid source provenance")
            actual_archives = [x.get("archive") for x in existing.get("archives", [])]
            expected_archives = ["wp-content/plugins/" + p for p in packages]
            if (existing.get("source_commit") != SOURCE or existing.get("branch") != branch
                or actual_archives != expected_archives
                or existing.get("non_authorizing") is not True):
                raise SystemExit(f"{branch}: provenance mismatch; refusing overwrite")
            print(f"SKIP VERIFIED: {branch} already unpacked at {observed}", flush=True)
            continue
        print(f"Unpacking {branch}: {', '.join(packages)}", flush=True)
        git("checkout", "-B", branch, SOURCE)
        manifest = {
            "schema": "mad4b.research-unpacked-vendor-archives.v1",
            "non_authorizing": True,
            "no_runtime_certs": True,
            "source_commit": SOURCE,
            "branch": branch,
            "archives": [],
        }
        destination = Path("vendor-unpacked")
        if destination.exists():
            # Worktree can retain unpacked ignored/untracked files from the
            # previous branch; only remove our ephemeral research directory.
            # The pinned source tree must not contain this directory at all.
            source_files = git("ls-tree", "-r", "--name-only", SOURCE, "--", "vendor-unpacked")
            if source_files:
                raise SystemExit("Pinned source already contains vendor-unpacked; no cleanup allowed")
            shutil.rmtree(destination)
        for package in packages:
            root = destination / package.removesuffix(".zip")
            root.mkdir(parents=True, exist_ok=False)
            manifest["archives"].append(unpack_archive(package, root))
        (destination / "PROVENANCE.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        (destination / "README.md").write_text(
            "# Isolated vendor source inspection\n\n"
            "The sources under this folder are extracted directly from the ZIP archives "
            "tracked at the pinned WordPress repository commit. See PROVENANCE.json "
            "for the exact Git blob and archive SHA-256. No extracted plugin code "
            "is activated or used as authorization/certification evidence. "
            "This branch is for static source inspection only; do not merge the "
            "vendor tree into production or the ETG feature branch.\n",
            encoding="utf-8",
        )
        git("add", "--", "vendor-unpacked")
        git("commit", "-m", "chore(research): extract pinned vendor ZIPs for source comparison")
        print(f"Committing inspected vendor branch {branch}: {git('rev-parse','HEAD')}", flush=True)
        git("push", "origin", f"HEAD:refs/heads/{branch}")
        print(f"SUCCESS: {branch}", flush=True)
    print("ALL_RESEARCH_BRANCHES_EXTRACTED", flush=True)

if __name__ == "__main__":
    main()
