#!/usr/bin/env python3
"""Tours.dev native exact-cache/hashed asset coherence; no external browser.

Retain ONLY assets referenced by current Nginx FastCGI HTML, not the multi-GB
LiteSpeed catalog. Restore archived assets atomically or evict just mismatched
FastCGI cache records. Never touch DB, release manifests, auth or bookings.
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

PUBLIC = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/litespeed")
STORE = Path("/opt/mad4b-company-provisioning/runtime/toursdev-litespeed-retention")
REPORT = Path("/opt/mad4b-company-provisioning/reports/toursdev-asset-coherence-20261122/latest.json")
CONTAINER = "nginx-wkgtkzta35wkkgouwt6ruljc"
CACHE_REL = "/var/cache/nginx/etg_woo_perf_v1"
TTL = 1800.0
MAX_ARCHIVE_BYTES = 256 * 1024 * 1024
MAX_REFERENCES = 1500
MAX_FILE_BYTES = 12 * 1024 * 1024
MAX_CACHE_FILES = 600
MAX_CACHE_SIZE = 8 * 1024 * 1024
MAX_EVICTIONS = 100
PATTERN = re.compile(rb"/wp-content/litespeed/(css|js)/([a-f0-9]{32})\.(css|js)")
CACHE_FILE = re.compile(r"[0-9a-f]/[0-9a-f]{2}/[0-9a-f]{32}")
FILE = re.compile(r"[0-9a-f]{32}\.(css|js)")
GRACE = 0.75  # Avoid race with LiteSpeed atomic bundle regeneration.

def sha(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()

def safe_public():
    # Source tree is legitimately group-writable by www-data; require
    # the exact known owners and modes rather than relaxing to arbitrary paths.
    expected = [(PUBLIC, 0, 33), (PUBLIC / "css", 33, 33), (PUBLIC / "js", 33, 33)]
    for d, uid, gid in expected:
        if d.is_symlink() or not d.is_dir():
            raise RuntimeError("PUBLIC_DIRECTORY_NOT_EXACT")
        st = d.stat()
        if st.st_uid != uid or st.st_gid != gid or st.st_mode & 0o007:
            raise RuntimeError("UNSAFE_PUBLIC_OWNER_OR_WORLD_MODE:" + str(d))

def safe_archive():
    for d in (STORE, STORE / "css", STORE / "js"):
        if d.is_symlink() or not d.is_dir():
            raise RuntimeError("ARCHIVE_NOT_DIRECTORY")
        st = d.stat()
        if st.st_uid != 0 or st.st_mode & 0o077:
            raise RuntimeError("ARCHIVE_NOT_ROOT_PRIVATE")

def cache_root():
    r = subprocess.run(["/usr/bin/docker", "inspect", "--format", "{{.State.Pid}}",
                        CONTAINER], check=True, timeout=8, text=True, capture_output=True)
    pid = int(r.stdout.strip())
    if pid < 2:
        raise RuntimeError("NGINX_NOT_LIVE")
    p = Path(f"/proc/{pid}/root{CACHE_REL}")
    if p.is_symlink() or not p.is_dir():
        raise RuntimeError("FASTCGI_NOT_EXACT")
    st = p.stat()
    if st.st_uid != 33 or st.st_gid != 33 or st.st_mode & 0o077:
        raise RuntimeError("UNSAFE_FASTCGI_ROOT")
    return p

def referenced_files(body: bytes):
    return {a.decode() + "/" + b.decode() + "." + c.decode()
            for a, b, c in PATTERN.findall(body) if a == c}

def atomic_archive(source: Path, archive: Path) -> bool:
    st = source.stat()
    if st.st_size < 1 or st.st_size > MAX_FILE_BYTES or source.is_symlink():
        return False
    if source.stat().st_uid != 33:
        return False
    fd, name = tempfile.mkstemp(prefix=".source-", dir=str(archive.parent))
    tmp = Path(name)
    try:
        with os.fdopen(fd, "wb") as out, source.open("rb") as inp:
            shutil.copyfileobj(inp, out, 1048576)
            out.flush()
            os.fsync(out.fileno())
        after = source.stat()
        if (st.st_ino, st.st_size, st.st_mtime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns):
            return False
        if sha(tmp.read_bytes()) != sha(source.read_bytes()):
            return False
        os.chmod(tmp, 0o600)
        os.replace(tmp, archive)
        return True
    finally:
        tmp.unlink(missing_ok=True)

def restore(archive: Path, target: Path) -> bool:
    data = archive.read_bytes()
    if not data or len(data) > MAX_FILE_BYTES:
        return False
    fd, name = tempfile.mkstemp(prefix=".mad4b-toursdev-restore-", dir=str(target.parent))
    tmp = Path(name)
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        if sha(tmp.read_bytes()) != sha(data):
            return False
        os.chown(tmp, 33, 33)
        os.chmod(tmp, 0o644)
        try:
            os.link(tmp, target)
            return True
        except FileExistsError:
            return False
    finally:
        tmp.unlink(missing_ok=True)

def run(repair: bool):
    safe_public()
    safe_archive()
    root = cache_root()
    now = time.time()
    scanned = 0
    objects = []
    refs = set()
    # Only read objects Nginx has materialized, not WordPress private HTML.
    for p in root.rglob("*"):
        if not p.is_file():
            continue
        if p.is_symlink() or not CACHE_FILE.fullmatch(p.relative_to(root).as_posix()):
            raise RuntimeError("CACHE_FILE_PATH_NOT_EXACT")
        scanned += 1
        if scanned > MAX_CACHE_FILES:
            raise RuntimeError("CACHE_SCAN_BOUND")
        st = p.stat()
        if st.st_size > MAX_CACHE_SIZE:
            raise RuntimeError("CACHE_OBJECT_SIZE_BOUND")
        data = p.read_bytes()
        files = referenced_files(data)
        refs.update(files)
        if len(refs) > MAX_REFERENCES:
            raise RuntimeError("ASSET_REF_BOUND")
        objects.append((p, st, files))
    archived = restored = evicted = absent = expired = races = 0
    budget = 0
    errors = []
    for ref in sorted(refs):
        kind, name = ref.split("/")
        if kind not in ("js", "css") or not FILE.fullmatch(name) or name.rsplit(".",1)[1] != kind:
            raise RuntimeError("UNTRUSTED_BUNDLE_REF")
        public = PUBLIC / ref
        private = STORE / ref
        if private.is_symlink() or public.is_symlink():
            raise RuntimeError("SYMLINK_BUNDLE")
        exists = public.is_file()
        if exists:
            st = public.stat()
            if st.st_size < 1 or st.st_size > MAX_FILE_BYTES:
                errors.append("OUT_OF_BOUNDS_FILE:" + ref)
                continue
            budget += st.st_size
            if budget > MAX_ARCHIVE_BYTES:
                raise RuntimeError("REFERENCED_BUNDLE_BYTES_BOUND")
            should_refresh = not private.exists()
            if private.is_file() and not should_refresh:
                ps = private.stat()
                should_refresh = (
                    ps.st_size != st.st_size or
                    now - ps.st_mtime > TTL / 2
                )
            if should_refresh and now-st.st_mtime >= GRACE:
                if repair:
                    if atomic_archive(public, private):
                        archived += 1
                else:
                    archived += 1
            continue
        # Bundle missing. First attempt restoring the original retained
        # public asset, never reading untrusted sibling site or Git master.
        if private.is_file() and 0 <= now - private.stat().st_mtime <= TTL:
            if repair:
                if restore(private, public):
                    restored += 1
            else:
                restored += 1
        if not public.is_file():
            absent += 1
    for p, original, files in objects:
        missing = [f for f in files if not (PUBLIC / f).is_file()]
        if not missing:
            continue
        if now - original.st_mtime < GRACE:
            races += 1
            continue
        if not repair:
            evicted += 1
            continue
        if evicted >= MAX_EVICTIONS:
            raise RuntimeError("INVALIDATION_LIMIT")
        try:
            after = p.stat()
            if (original.st_size, original.st_ino, original.st_mtime_ns) != (
                    after.st_size, after.st_ino, after.st_mtime_ns):
                races += 1
                continue
            p.unlink()
            evicted += 1
        except FileNotFoundError:
            races += 1
    for kind in ("css", "js"):
        for p in (STORE / kind).iterdir():
            if p.is_symlink() or not p.is_file() or not FILE.fullmatch(p.name):
                raise RuntimeError("UNTRUSTED_ARCHIVE_ENTRY")
            if now - p.stat().st_mtime > TTL and p.name not in {v.split("/")[1] for v in refs if v.startswith(kind+"/")}:
                expired += 1
                if repair:
                    p.unlink()
    result = {
        "contract": "mad4b.toursdev-fastcgi-litespeed-native-coherence.v1",
        "observed_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "site": "https://tours.dev.mad4b.com",
        "source_site": True,
        "repair_enabled": repair,
        "cached_html_scanned": scanned,
        "unique_optimized_asset_refs": len(refs),
        "source_optimized_asset_catalog_total_bytes": "not_scanned",
        "referenced_asset_budget_bytes": budget,
        "archived": archived,
        "restored": restored,
        "missing_references_after_restore": absent,
        "evicted_cache_objects": evicted,
        "race_skips": races,
        "expired_unreferenced_archives": expired,
        "errors": errors[:12],
        "external_browser_test": False,
        "db_changed": False,
        "production_grants_changed": False,
        "source_code_changed": False,
        "git_master_used": False,
        "entire_html_cache_purge": False,
    }
    if repair:
        REPORT.parent.mkdir(parents=True, exist_ok=True)
        fd, name = tempfile.mkstemp(prefix=".source-coherence-", dir=str(REPORT.parent))
        try:
            with os.fdopen(fd, "w") as output:
                json.dump(result, output, indent=2)
                output.write("\n")
            os.chmod(name, 0o600)
            os.replace(name, REPORT)
        finally:
            Path(name).unlink(missing_ok=True)
    return result

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--repair", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(run(args.repair), sort_keys=True))
    except Exception as exc:
        print(json.dumps({"contract":"mad4b.toursdev-fastcgi-litespeed-native-coherence.v1",
                          "state": "FAIL_CLOSED", "error": str(exc)[:260]}))
        raise SystemExit(2)
