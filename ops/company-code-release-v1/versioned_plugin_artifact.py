#!/usr/bin/env python3
"""First-party immutable Git plugin artifact *preparation*, NOT deployment.

Never writes WordPress. Produces a deterministic root-owned proposed package
and a verified preimage report for admission by a separately signed Publisher.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tarfile
import tempfile

CONTRACT = "mad4b.original-publisher.plugin-source-package.v1"
COMMIT = re.compile(r"^[a-f0-9]{40}$")
SLUG = re.compile(r"^[a-z][a-z0-9-]{2,90}$")
SEGMENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,200}$")
MAX_FILES = 1024
MAX_FILE_BYTES = 4_000_000
MAX_TOTAL_BYTES = 48_000_000


class PackageDenied(RuntimeError):
    pass


def reject_if(condition: bool, reason: str):
    if condition:
        raise PackageDenied(reason)


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def canonical(value: dict) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(",", ":")).encode("utf-8")


def git(repo: Path, *argv: str, input_data: bytes | None = None) -> bytes:
    p = subprocess.run(["git", "-C", str(repo), *argv], input=input_data,
                       capture_output=True, check=False, timeout=35)
    reject_if(p.returncode != 0, "IMMUTABLE_SOURCE_GIT_READ_FAILED")
    return p.stdout


def relative_path(name: str) -> tuple[str, ...]:
    reject_if(not isinstance(name, str) or "\\" in name or "\x00" in name,
              "SOURCE_PATH_INVALID")
    p = PurePosixPath(name)
    reject_if(p.is_absolute() or len(p.parts) < 1 or
              not all(SEGMENT.fullmatch(part) and part not in (".", "..")
                      for part in p.parts) or p.as_posix() != name,
              "SOURCE_PATH_INVALID")
    return p.parts


def inventory_commit(repo: Path, commit: str, plugin: str,
                     exclude_dirs: tuple[str, ...] = ("tests",)) -> tuple[list, list]:
    reject_if(not COMMIT.fullmatch(commit), "IMMUTABLE_COMMIT_INVALID")
    reject_if(not SLUG.fullmatch(plugin), "PLUGIN_SLUG_INVALID")
    reject_if(not isinstance(exclude_dirs, tuple) or
              not all(isinstance(x, str) and SEGMENT.fullmatch(x)
                      for x in exclude_dirs) or
              len(set(exclude_dirs)) != len(exclude_dirs),
              "RUNTIME_EXCLUSIONS_INVALID")
    prefix = "wp-content/plugins/" + plugin + "/"
    out, excluded = [], []
    tree = git(repo, "ls-tree", "-rz", commit, "--", prefix)
    reject_if(not tree, "SOURCE_PLUGIN_NOT_IN_IMMUTABLE_COMMIT")
    for item in tree.split(b"\x00"):
        if not item:
            continue
        try:
            head, rawpath = item.split(b"\t", 1)
            mode, kind, oid = head.decode("ascii").split()
            full = rawpath.decode("utf-8")
        except (ValueError, UnicodeDecodeError) as e:
            raise PackageDenied("SOURCE_GIT_TREE_INVALID") from e
        reject_if(not full.startswith(prefix), "SOURCE_GIT_TREE_SCOPE_CHANGED")
        relative = full[len(prefix):]
        parts = relative_path(relative)
        reject_if(kind != "blob" or mode not in ("100644", "100755"),
                  "SOURCE_NONREGULAR_OR_SYMLINK_DENIED")
        if any(name in exclude_dirs for name in parts[:-1]):
            excluded.append(relative)
            continue
        size_text = git(repo, "cat-file", "-s", oid).strip()
        reject_if(not size_text.isdigit() or int(size_text) > MAX_FILE_BYTES,
                  "SOURCE_FILE_SIZE_INVALID")
        blob = git(repo, "cat-file", "blob", oid)
        reject_if(len(blob) != int(size_text), "IMMUTABLE_SOURCE_SIZE_DRIFT")
        out.append({"path": relative, "size": len(blob),
                    "sha256": digest(blob), "source_git_blob": oid,
                    "body": blob})
    reject_if(not out or len(out) > MAX_FILES or
              sum(row["size"] for row in out) > MAX_TOTAL_BYTES,
              "SOURCE_PACKAGE_CAPACITY_EXCEEDED")
    reject_if(len({x["path"] for x in out}) != len(out),
              "SOURCE_DUPLICATE_PLUGIN_PATH")
    return sorted(out, key=lambda x: x["path"]), sorted(excluded)


def inventory_live(target_root: Path, plugin: str) -> tuple[list, bool]:
    directory = target_root / "wp-content/plugins" / plugin
    reject_if(directory.is_symlink() or
              (directory.exists() and not directory.is_dir()),
              "LIVE_PLUGIN_ROOT_UNAVAILABLE")
    # A newly proposed plugin may be staged as a source-only artifact too.
    # New installation still requires separate original signed authority.
    if not directory.exists():
        return [], False
    live = []
    dirs = [directory]
    for path in sorted(directory.rglob("*")):
        reject_if(path.is_symlink(), "LIVE_PLUGIN_SYMLINK_DENIED")
        if path.is_dir():
            dirs.append(path)
            continue
        reject_if(not path.is_file(), "LIVE_PLUGIN_NONREGULAR_DENIED")
        relative = path.relative_to(directory).as_posix()
        relative_path(relative)
        info = path.stat()
        reject_if(info.st_size > MAX_FILE_BYTES, "LIVE_PLUGIN_FILE_TOO_LARGE")
        live.append({"path": relative,
                     "sha256": digest(path.read_bytes()), "bytes": info.st_size,
                     "uid": info.st_uid, "gid": info.st_gid,
                     "mode": stat.S_IMODE(info.st_mode)})
    root_protected = all(d.stat().st_uid == 0 and
                         not stat.S_IMODE(d.stat().st_mode) & 0o022
                         for d in dirs)
    root_protected = root_protected and all(
        row["uid"] == 0 and not row["mode"] & 0o022 for row in live)
    return live, root_protected


def plan(repo: Path, commit: str, plugin: str, target_root: Path,
         exclude_dirs: tuple[str, ...] = ("tests",)) -> tuple[dict, list[dict]]:
    files, excluded = inventory_commit(repo, commit, plugin, exclude_dirs)
    live, live_root_protected = inventory_live(target_root, plugin)
    before = {row["path"]: row for row in live}
    after = {row["path"]: row for row in files}
    missing = sorted(set(before) - set(after))
    reject_if(bool(missing), "LIVE_PLUGIN_FILE_REMOVAL_UNRECONCILED")
    manifest = {
        "contract": CONTRACT, "immutable_source_commit": commit,
        "plugin": plugin,
        "package_path": "wp-content/plugins/" + plugin,
        "runtime_exclude_dirs": list(exclude_dirs),
        "source_runtime_files": [
            {key: row[key] for key in ("path", "size", "sha256", "source_git_blob")}
            for row in files],
        "source_excluded_paths": excluded,
        "live_preimage": live,
        "live_root_provenance_ready": live_root_protected,
        "new_install_requires_original_authority": len(live) == 0,
        "review": {"preserved": sum(before[r["path"]]["sha256"] == r["sha256"]
                                    for r in files if r["path"] in before),
                   "revised": sum(before[r["path"]]["sha256"] != r["sha256"]
                                  for r in files if r["path"] in before),
                   "new": sum(r["path"] not in before for r in files),
                   "removed": 0},
        "production_writes": False,
        "source_publisher_enrolled_by_this_artifact": False,
        "original312_grant_issued": False,
        "original313_certified": False,
        "coherent_activation_proven": False,
        "state": "SOURCE_ARTIFACT_REVIEW_ONLY_NOT_DEPLOYABLE",
    }
    manifest["manifest_sha256"] = digest(canonical(manifest))
    return manifest, files


def archive(plugin: str, files: list[dict]) -> bytes:
    """Deterministic, root-metadata tar; never extracts or changes ownership."""
    output = io.BytesIO()
    directories = {plugin}
    for f in files:
        parts = (plugin, *relative_path(f["path"]))
        directories.update("/".join(parts[:i]) for i in range(1, len(parts)))
    with tarfile.open(fileobj=output, mode="w", format=tarfile.USTAR_FORMAT) as tar:
        for folder in sorted(directories):
            info = tarfile.TarInfo(folder + "/")
            info.type = tarfile.DIRTYPE
            info.uid = 0
            info.gid = 0
            info.mode = 0o755
            info.mtime = 0
            tar.addfile(info)
        for f in files:
            info = tarfile.TarInfo(plugin + "/" + f["path"])
            info.size = f["size"]
            info.type = tarfile.REGTYPE
            info.uid = 0
            info.gid = 0
            info.mode = 0o644
            info.mtime = 0
            tar.addfile(info, io.BytesIO(f["body"]))
    result = output.getvalue()
    reject_if(len(result) > 2 * MAX_TOTAL_BYTES,
              "ARTIFACT_TAR_CAPACITY_EXCEEDED")
    return result


def write_private_new(path: Path, contents: bytes):
    parent = path.parent
    reject_if(parent.is_symlink() or not parent.is_dir(),
              "ARTIFACT_ROOT_PRIVATE_DIRECTORY_REQUIRED")
    mode = parent.stat()
    reject_if(mode.st_uid != 0 or stat.S_IMODE(mode.st_mode) & 0o077,
              "ARTIFACT_ROOT_PRIVATE_DIRECTORY_REQUIRED")
    reject_if(path.exists() or path.is_symlink(),
              "ARTIFACT_ALREADY_EXISTS")
    fd, temporary = tempfile.mkstemp(prefix=".mad4b-source-artifact-", dir=parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as handle:
            handle.write(contents)
            handle.flush()
            os.fsync(handle.fileno())
        os.link(temporary, path, follow_symlinks=False)
        parentfd = os.open(parent, os.O_DIRECTORY)
        try:
            os.fsync(parentfd)
        finally:
            os.close(parentfd)
    finally:
        if os.path.lexists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", required=True, type=Path)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--plugin", required=True)
    parser.add_argument("--target-root", required=True, type=Path)
    parser.add_argument("--output-directory", required=True, type=Path)
    parser.add_argument("--exclude-dir", action="append")
    args = parser.parse_args()
    excludes = tuple(args.exclude_dir) if args.exclude_dir else ("tests",)
    document, files = plan(args.repo, args.commit, args.plugin,
                           args.target_root, excludes)
    raw = archive(args.plugin, files)
    document["artifact_sha256"] = digest(raw)
    document["artifact_bytes"] = len(raw)
    name = args.plugin + "-" + document["manifest_sha256"][:24]
    # Content is root-private, non-authoritative staging material only.
    write_private_new(args.output_directory / (name + ".tar"), raw)
    write_private_new(args.output_directory / (name + ".manifest.json"),
                      canonical(document) + b"\n")
    print(json.dumps({
        "state": document["state"],
        "manifest_sha256": document["manifest_sha256"],
        "artifact_sha256": document["artifact_sha256"],
        "artifact_bytes": document["artifact_bytes"],
        "manifest_file": name + ".manifest.json",
        "source_files": len(document["source_runtime_files"]),
        "excluded_from_runtime": len(document["source_excluded_paths"]),
        "review": document["review"],
        "source_publisher_enrolled": False, "production_mutated": False
    }, sort_keys=True))


if __name__ == "__main__":
    main()
