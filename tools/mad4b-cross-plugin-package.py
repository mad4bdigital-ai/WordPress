#!/usr/bin/env python3
"""Build deterministic, review-only WordPress plugin ZIP from a reviewed repo source.

No source code is executed, no credentials loaded and nothing is deployed.
"""
import argparse
import hashlib
import json
import os
import pathlib
import re
import stat
import subprocess
import sys
import zipfile

MAX_FILES = 25000
MAX_UNPACKED_BYTES = 512 * 1024 * 1024
MAX_ARCHIVE_BYTES = 64 * 1024 * 1024
MANIFEST = "wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json"
GIT_SHA = re.compile(r"^[a-f0-9]{40}$")
PHP_VERSION = re.compile(r"^\s*\*?\s*Version:\s*(\S[^\r\n]*)", re.I | re.M)


def require(ok, message):
    if not ok:
        raise ValueError(message)


def reviewed_source(manifest, source_id):
    raw = json.loads(pathlib.Path(manifest).read_text(encoding="utf-8"))
    require(raw.get("contract") == "mad4b.cross-plugin-sources.v1", "unreviewed source registry")
    records = [x for x in raw.get("sources", []) if x.get("id") == source_id]
    require(len(records) == 1, "source must have exactly one reviewed registry entry")
    item = records[0]
    require(item.get("review_state") in ("source_reviewed_artifact_pending", "source_reviewed_artifact_certified"),
            "source review state invalid")
    return item


def build(trusted_manifest, checkout, source_id, source_sha, out_dir):
    require(GIT_SHA.fullmatch(source_sha) is not None, "exact Git commit required")
    source = reviewed_source(trusted_manifest, source_id)
    checkout = pathlib.Path(checkout).resolve(strict=True)
    git_head = subprocess.check_output(
        ["git", "-C", str(checkout), "rev-parse", "HEAD"], text=True, timeout=15).strip()
    require(git_head == source_sha, "checked-out SHA mismatch")
    subdir = source["plugin_subdirectory"]
    require(re.fullmatch(r"[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+){0,12}", subdir) is not None
            and ".." not in subdir, "source directory not canonical")
    main = source["plugin_file"]
    slug = pathlib.PurePosixPath(subdir).name
    require(re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.php", main) is not None
            and main.startswith(slug + "/"), "plugin header path differs from registered identity")
    folder = checkout.joinpath(*subdir.split("/"))
    require(folder.is_dir() and not folder.is_symlink() and folder.resolve() == folder, "plugin source missing or symlink")
    header = folder / main.split("/", 1)[1]
    require(header.is_file() and not header.is_symlink(), "plugin entrypoint absent")
    main_text = header.read_text(encoding="utf-8")
    match = PHP_VERSION.search(main_text[:8192])
    require(match is not None, "WordPress plugin Version header missing")
    version = match.group(1).strip()
    require(len(version) <= 80, "invalid WordPress plugin version")
    contents = []
    total = 0
    for parent, dirs, filenames in os.walk(folder, followlinks=False):
        dirs.sort()
        filenames.sort()
        for directory in dirs:
            path = pathlib.Path(parent, directory)
            require(not path.is_symlink(), "symlinked directory rejected")
        for filename in filenames:
            path = pathlib.Path(parent, filename)
            mode = path.lstat().st_mode
            require(stat.S_ISREG(mode), "symlink or non-regular source file rejected")
            relative = path.relative_to(folder).as_posix()
            require(not any(p in ("", ".", "..") for p in pathlib.PurePosixPath(relative).parts)
                    and not relative.startswith("/"), "unsafe archive entry")
            size = path.stat().st_size
            total += size
            require(total <= MAX_UNPACKED_BYTES, "unpacked archive budget exceeded")
            require(len(contents) < MAX_FILES, "file-count budget exceeded")
            contents.append((relative, path))
    contents.sort(key=lambda x: x[0])
    require(contents, "empty plugin archive")
    out_dir = pathlib.Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    zip_path = out_dir / (source_id + "-" + source_sha + ".zip")
    file_entries = []
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zipped:
        for relative, path in contents:
            payload = path.read_bytes()
            name = slug + "/" + relative
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zipped.writestr(info, payload, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            file_entries.append(dict(path=name, sha256=hashlib.sha256(payload).hexdigest(), bytes=len(payload)))
    archive_size = zip_path.stat().st_size
    require(archive_size <= MAX_ARCHIVE_BYTES, "compressed archive budget exceeded")
    entries_digest = hashlib.sha256(json.dumps(
        file_entries, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    result = {
        "contract": "mad4b.cross-plugin-candidate-artifact.v1",
        "status": "BUILT_PENDING_INDEPENDENT_REVIEW",
        "source_id": source_id,
        "repository": source["repository"],
        "source_commit_sha": source_sha,
        "plugin_subdirectory": subdir,
        "plugin_file": main,
        "version": version,
        "archive_sha256": hashlib.sha256(zip_path.read_bytes()).hexdigest(),
        "archive_bytes": archive_size,
        "archive_manifest_sha256": entries_digest,
        "archive_file_count": len(contents),
        "outcome_authorizes_deployment": False,
        "production_promotion_granted": False,
        "wordpress_execution_performed": False,
    }
    manifest_path = out_dir / (source_id + "-" + source_sha + ".json")
    manifest_path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return zip_path, manifest_path, result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--trusted-manifest", required=True)
    parser.add_argument("--checkout", required=True)
    parser.add_argument("--source-id", required=True)
    parser.add_argument("--sha", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    try:
        zip_path, manifest_path, result = build(
            args.trusted_manifest, args.checkout, args.source_id, args.sha, args.out)
    except (ValueError, OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        print("Source-only build rejected: " + str(exc), file=sys.stderr)
        sys.exit(2)
    print(json.dumps({"zip": str(zip_path), "manifest": str(manifest_path),
        "source_sha": result["source_commit_sha"], "archive_sha256": result["archive_sha256"],
        "certified": False}, sort_keys=True))


if __name__ == "__main__":
    main()
