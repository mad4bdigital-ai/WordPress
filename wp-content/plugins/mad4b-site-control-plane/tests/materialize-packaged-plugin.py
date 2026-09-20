#!/usr/bin/env python3
"""Safely materialize a packaged WordPress plugin into an exact plugin slug.

Supports both wrapped archives (slug/...) and rootless provider archives
(main.php, includes/..., ...). All payload is written under plugins_dir/slug,
so archives cannot collide through shared top-level files such as index.php.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath

MAX_FILES = 50000
MAX_UNCOMPRESSED_BYTES = 768 * 1024 * 1024
SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,99}$")
NOISE_NAMES = {"__MACOSX", ".DS_Store"}

def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

def safe_parts(name: str) -> tuple[str, ...]:
    normalized = name.replace("\\", "/")
    if not normalized or normalized.startswith("/"):
        raise RuntimeError(f"unsafe archive member: {name}")
    parts = PurePosixPath(normalized).parts
    if not parts or any(part in ("", ".", "..") for part in parts):
        raise RuntimeError(f"unsafe archive member: {name}")
    return tuple(parts)

def is_symlink(info: zipfile.ZipInfo) -> bool:
    mode = (info.external_attr >> 16) & 0xFFFF
    return stat.S_ISLNK(mode)

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--archive", required=True)
    p.add_argument("--plugins-dir", required=True)
    p.add_argument("--slug", required=True)
    p.add_argument("--entry-file", default="")
    args = p.parse_args()

    archive = Path(args.archive).resolve()
    plugins_dir = Path(args.plugins_dir).resolve()
    slug = args.slug.strip().lower()
    if not SLUG_RE.fullmatch(slug):
        raise SystemExit("invalid plugin slug")
    if not archive.is_file():
        raise SystemExit(f"archive not found: {archive}")

    with zipfile.ZipFile(archive) as zf:
        infos = zf.infolist()
        if len(infos) > MAX_FILES:
            raise SystemExit("archive file-count limit exceeded")
        total = sum(max(0, int(info.file_size)) for info in infos)
        if total > MAX_UNCOMPRESSED_BYTES:
            raise SystemExit("archive uncompressed-size limit exceeded")

        parsed = []
        for info in infos:
            parts = safe_parts(info.filename)
            if is_symlink(info):
                raise SystemExit(f"symlink archive member is forbidden: {info.filename}")
            parsed.append((info, parts))

        meaningful = [
            parts for info, parts in parsed
            if not info.is_dir()
            and parts[0] not in NOISE_NAMES
            and not (len(parts) == 1 and parts[0].lower() in {"index.php", ".ds_store"})
        ]
        wrapped = bool(meaningful) and all(parts[0] == slug for parts in meaningful)

        dest = (plugins_dir / slug).resolve()
        try:
            dest.relative_to(plugins_dir)
        except ValueError:
            raise SystemExit("destination escaped plugins directory")

        if dest.exists():
            shutil.rmtree(dest)
        dest.mkdir(parents=True, exist_ok=True)

        written = 0
        for info, parts in parsed:
            if parts[0] in NOISE_NAMES:
                continue
            rel_parts = parts[1:] if wrapped and parts[0] == slug else parts
            if not rel_parts:
                continue
            target = dest.joinpath(*rel_parts).resolve()
            try:
                target.relative_to(dest)
            except ValueError:
                raise SystemExit(f"archive member escaped destination: {info.filename}")
            if info.is_dir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            target.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(info, "r") as src, target.open("wb") as dst:
                shutil.copyfileobj(src, dst)
            written += 1

    entry = args.entry_file.strip() or f"{slug}.php"
    entry_path = (dest / entry).resolve()
    try:
        entry_path.relative_to(dest)
    except ValueError:
        raise SystemExit("entry file escaped destination")
    if not entry_path.is_file():
        raise SystemExit(f"plugin entry file missing after materialization: {entry_path}")

    report = {
        "contract": "mad4b.packaged-plugin-materialization.v1",
        "archive": archive.name,
        "archive_sha256": sha256(archive),
        "slug": slug,
        "layout": "wrapped" if wrapped else "rootless",
        "destination": str(dest),
        "entry_file": entry,
        "files_written": written,
        "status": "passed",
    }
    print(json.dumps(report, sort_keys=True))
    return 0

if __name__ == "__main__":
    sys.exit(main())
