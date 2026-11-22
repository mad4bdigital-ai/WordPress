#!/usr/bin/env python3
"""Wayfatours-only LiteSpeed bundle retention + public-tour FastCGI coherence.

This host service never touches WP DB, commerce, credentials, source releases or
Company grants. It stores copies of already-public hashed CSS/JS for the lifetime
of browser-cached HTML and evicts only a FastCGI object that still points to an
unavailable bundle. Existing Nginx cache gates remain authoritative.
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import time

PUBLIC = Path("/opt/wayfa-rebuild-20261102/site-copy/wp-content/litespeed")
STORE = Path("/opt/mad4b-company-provisioning/runtime/wayfa-litespeed-bundle-retention")
REPORT = Path("/opt/mad4b-company-provisioning/reports/wayfa-tour-blank-20261121/asset-coherence-current.json")
CONTAINER = "nginx-guxhp9y6vgh7oouuw8plxrlr"
TTL_SECONDS = 7200
MIN_STABLE_SECONDS = 5
MAX_ASSET_BYTES = 12 * 1024 * 1024
MAX_ASSETS = 1500
MAX_CACHE_OBJECTS = 500
MAX_CACHE_OBJECT_BYTES = 8 * 1024 * 1024
MAX_CACHE_EVICTIONS = 75
NAME = re.compile(r"^[a-f0-9]{32}\.(?:css|js)(?:\.gz)?$")
CACHE_NAME = re.compile(r"^[0-9a-f]/[0-9a-f]{2}/[0-9a-f]{32}$")
REF = re.compile(rb"/wp-content/litespeed/(css|js)/([a-f0-9]{32})\.(css|js)")

def secure_directory(p: Path, owner: int | None = None) -> None:
    if p.is_symlink() or not p.is_dir():
        raise RuntimeError("UNTRUSTED_DIRECTORY")
    st = p.stat()
    if owner is not None and st.st_uid != owner:
        raise RuntimeError("WRONG_DIRECTORY_OWNER")
    if st.st_mode & 0o022:
        raise RuntimeError("GROUP_OR_WORLD_WRITABLE_DIRECTORY")

def valid_path(kind: str, name: str) -> bool:
    return kind in ("js", "css") and NAME.fullmatch(name) is not None and name.split(".")[1] == kind

def public_paths():
    secure_directory(PUBLIC)
    for kind in ("css", "js"):
        directory = PUBLIC / kind
        secure_directory(directory, owner=33)
        yield kind, directory

def atomic_private_copy(source: Path, dest: Path, expected_stat: os.stat_result) -> bool:
    dest.parent.mkdir(parents=True, exist_ok=True)
    secure_directory(dest.parent, owner=0)
    fd, tmpname = tempfile.mkstemp(prefix=".new-hash-", dir=str(dest.parent))
    tmp = Path(tmpname)
    try:
        with os.fdopen(fd, "wb") as out, source.open("rb") as src:
            shutil.copyfileobj(src, out, 1024 * 1024)
            out.flush()
            os.fsync(out.fileno())
        after = source.stat()
        if (expected_stat.st_size, expected_stat.st_mtime_ns, expected_stat.st_ino) != (
            after.st_size, after.st_mtime_ns, after.st_ino
        ):
            return False
        os.chmod(tmp, 0o600)
        os.utime(tmp, ns=(after.st_atime_ns, after.st_mtime_ns))
        os.replace(tmp, dest)
        return True
    finally:
        tmp.unlink(missing_ok=True)

def restore_hashed_bundle(source: Path, target: Path) -> bool:
    fd, t = tempfile.mkstemp(prefix=".mad4b-hash-restore-", dir=str(target.parent))
    temp = Path(t)
    try:
        with os.fdopen(fd, "wb") as out, source.open("rb") as inp:
            shutil.copyfileobj(inp, out, 1024 * 1024)
            out.flush()
            os.fsync(out.fileno())
        os.chown(temp, 33, 33)
        os.chmod(temp, 0o644)
        shutil.copystat(source, temp)
        os.chmod(temp, 0o644)
        try:
            os.link(temp, target)  # Never replace a concurrently regenerated asset.
        except FileExistsError:
            return False
        return True
    finally:
        temp.unlink(missing_ok=True)

def nginx_cache_dir() -> Path:
    p = subprocess.run(
        ["docker", "inspect", "--format", "{{.State.Pid}}", CONTAINER],
        text=True, capture_output=True, check=True, timeout=8,
    )
    pid = int(p.stdout.strip())
    if pid < 2:
        raise RuntimeError("NGINX_CONTAINER_NOT_RUNNING")
    root = Path(f"/proc/{pid}/root/var/cache/nginx/wayfa")
    secure_directory(root, owner=33)
    return root

def execute(repair: bool) -> dict:
    now = time.time()
    total = 0
    copied = restored = expired = refresh = 0
    errors: list[str] = []
    live: dict[str, Path] = {}
    secure_directory(STORE, owner=0)
    for kind, directory in public_paths():
        archive = STORE / kind
        secure_directory(archive, owner=0)
        for p in directory.iterdir():
            if not p.is_file() or p.is_symlink() or not valid_path(kind, p.name):
                continue
            st = p.stat()
            if st.st_size < 1 or st.st_size > MAX_ASSET_BYTES:
                errors.append("SKIP_INVALID_SIZE:" + kind + "/" + p.name)
                continue
            total += 1
            if total > MAX_ASSETS:
                raise RuntimeError("ASSET_SCAN_BOUND_EXCEEDED")
            live[kind + "/" + p.name] = p
            if now - st.st_mtime < MIN_STABLE_SECONDS or now - st.st_mtime > TTL_SECONDS:
                continue
            dest = archive / p.name
            if dest.exists():
                if dest.is_symlink() or not dest.is_file():
                    raise RuntimeError("UNTRUSTED_ARCHIVED_BUNDLE")
                ds = dest.stat()
                if st.st_size == ds.st_size and st.st_mtime_ns == ds.st_mtime_ns:
                    continue
                if not repair:
                    refresh += 1
                    continue
                if hashlib.sha256(p.read_bytes()).digest() == hashlib.sha256(dest.read_bytes()).digest():
                    os.utime(dest, ns=(st.st_atime_ns, st.st_mtime_ns))
                    continue
                refresh += 1
            else:
                if not repair:
                    copied += 1
                    continue
            if repair and atomic_private_copy(p, dest, st):
                copied += 1

    for kind, directory in public_paths():
        archive = STORE / kind
        for f in archive.iterdir():
            if f.is_symlink() or not f.is_file() or not valid_path(kind, f.name):
                raise RuntimeError("UNTRUSTED_ARCHIVE_FILE")
            age = now - f.stat().st_mtime
            if age > TTL_SECONDS:
                expired += 1
                if repair:
                    f.unlink()
                continue
            target = directory / f.name
            if not target.exists():
                if repair:
                    if restore_hashed_bundle(f, target):
                        restored += 1
                else:
                    restored += 1
    cache_count = misses = invalidated = 0
    cache_root = nginx_cache_dir()
    for p in cache_root.rglob("*"):
        if not p.is_file():
            continue
        if p.is_symlink() or not CACHE_NAME.fullmatch(p.relative_to(cache_root).as_posix()):
            raise RuntimeError("UNTRUSTED_FASTCGI_CACHE_PATH")
        cache_count += 1
        if cache_count > MAX_CACHE_OBJECTS:
            raise RuntimeError("FASTCGI_OBJECT_SCAN_BOUND_EXCEEDED")
        stat_before = p.stat()
        if stat_before.st_size > MAX_CACHE_OBJECT_BYTES:
            errors.append("CACHE_OBJECT_SIZE_EXCEEDED")
            continue
        body = p.read_bytes()
        if b"tours-and-activities" not in body:
            continue
        links = set(f"{a.decode()}/{b.decode()}.{c.decode()}" for a, b, c in REF.findall(body))
        missing = [q for q in links if not (PUBLIC / q).is_file()]
        if not missing:
            continue
        misses += 1
        if not repair:
            continue
        if invalidated >= MAX_CACHE_EVICTIONS:
            raise RuntimeError("CACHE_EVICTION_BOUND_EXCEEDED")
        try:
            stat_after = p.stat()
            if (stat_before.st_ino, stat_before.st_size, stat_before.st_mtime_ns) != (
                stat_after.st_ino, stat_after.st_size, stat_after.st_mtime_ns
            ):
                continue
            p.unlink()
            invalidated += 1
        except FileNotFoundError:
            continue
    report = {
        "contract": "mad4b.wayfa.asset-coherence-guard.v1",
        "site": "wayfatours.com",
        "observed_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "repair_enabled": repair,
        "public_assets_seen": total,
        "archived_or_updated": copied,
        "new_bundle_versions_seen": refresh,
        "restored_missing_public_bundles": restored,
        "expired_archive_files": expired,
        "fastcgi_cache_objects_scanned": cache_count,
        "fastcgi_invalid_objects_found": misses,
        "fastcgi_invalid_objects_evicted": invalidated,
        "errors": errors[:30],
        "wp_db_write": False,
        "production_grants_changed": False,
        "site_code_changed": False,
        "global_cache_purge": False,
    }
    if repair:
        REPORT.parent.mkdir(parents=True, exist_ok=True)
        fd, name = tempfile.mkstemp(prefix=".asset-coherence-", dir=str(REPORT.parent))
        try:
            with os.fdopen(fd, "w") as output:
                json.dump(report, output, indent=2)
                output.write("\n")
            os.chmod(name, 0o600)
            os.replace(name, REPORT)
        finally:
            Path(name).unlink(missing_ok=True)
    return report

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--repair", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(execute(args.repair), sort_keys=True))
    except Exception as exc:
        print(json.dumps({"contract": "mad4b.wayfa.asset-coherence-guard.v1",
                          "state": "FAIL_CLOSED", "reason": str(exc), "repair_enabled": args.repair}))
        raise SystemExit(2)
