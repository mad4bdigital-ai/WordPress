#!/usr/bin/env python3
"""Deploy only an SHA-pinned exact live-origin package, never overwrite drift."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess

PACKAGE = Path(__file__).resolve().parent
BIN = Path("/opt/mad4b-company-provisioning/bin")
UNITS = Path("/etc/systemd/system")
SITES = {
    "wayfa": {
        "root": "/opt/wayfa-rebuild-20261102/site-copy/wp-content/litespeed",
        "scripts": ["wayfa-litespeed-fastcgi-asset-coherence-v1.py",
                    "wayfa-litespeed-asset-event-watch-v2.py"],
        "units": ["mad4b-wayfa-tour-asset-coherence.service",
                  "mad4b-wayfa-tour-asset-coherence.timer",
                  "mad4b-wayfa-tour-asset-event-watch-v2.service"],
        "start": ["mad4b-wayfa-tour-asset-event-watch-v2.service",
                  "mad4b-wayfa-tour-asset-coherence.timer"],
    },
    "toursdev": {
        "root": "/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/litespeed",
        "scripts": ["toursdev-litespeed-fastcgi-coherence-v1.py",
                    "toursdev-litespeed-asset-event-watch-v2.py"],
        "units": ["mad4b-toursdev-asset-coherence-fallback.service",
                  "mad4b-toursdev-asset-coherence-fallback.timer",
                  "mad4b-toursdev-asset-event-watch-v2.service"],
        "start": ["mad4b-toursdev-asset-event-watch-v2.service",
                  "mad4b-toursdev-asset-coherence-fallback.timer"],
    },
}


def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def verify_package():
    lock = json.loads((PACKAGE / "bundle-lock.json").read_text())
    if lock.get("contract") != "mad4b.cache-coherence-live-release-lock.v1":
        raise RuntimeError("PACKAGE_LOCK_CONTRACT_INVALID")
    actual = {p.relative_to(PACKAGE).as_posix()
              for d in ("bin", "systemd") for p in (PACKAGE / d).iterdir()
              if p.is_file()}
    if actual != set(lock["files"]) or len(actual) != 10:
        raise RuntimeError("PACKAGE_FILE_SET_MISMATCH")
    for rel, digest in lock["files"].items():
        if not rel.startswith(("bin/", "systemd/")) or ".." in rel:
            raise RuntimeError("UNTRUSTED_PACKAGE_PATH")
        p = PACKAGE / rel
        if p.is_symlink() or not p.is_file() or sha(p) != digest:
            raise RuntimeError("PACKAGE_SHA_MISMATCH:" + rel)
    return lock


def plan(site: str):
    chosen = list(SITES) if site == "both" else [site]
    entries = []
    for name in chosen:
        conf = SITES[name]
        root = Path(conf["root"])
        if root.is_symlink() or not root.is_dir():
            raise RuntimeError("SITE_ROOT_NOT_MATCHED:" + name)
        for file in conf["scripts"]:
            entries.append((PACKAGE / "bin" / file, BIN / file))
        for file in conf["units"]:
            entries.append((PACKAGE / "systemd" / file, UNITS / file))
    return chosen, entries


def install(site: str, apply: bool):
    verify_package()
    chosen, entries = plan(site)
    absent = []
    for src, dst in entries:
        if dst.exists():
            if dst.is_symlink() or not dst.is_file() or sha(dst) != sha(src):
                raise RuntimeError("LOCAL_LIVE_DRIFT_REFUSE_OVERWRITE:" + str(dst))
        else:
            absent.append((src, dst))
    if apply:
        if os.geteuid() != 0:
            raise RuntimeError("ROOT_REQUIRED")
        for src, dst in absent:
            dst.parent.mkdir(parents=True, exist_ok=True)
            perm = 0o700 if dst.parent == BIN else 0o644
            fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL, perm)
            with src.open("rb") as reader, os.fdopen(fd, "wb") as writer:
                shutil.copyfileobj(reader, writer)
            os.chmod(dst, perm)
            if sha(dst) != sha(src):
                raise RuntimeError("POST_INSTALL_SHA_MISMATCH")
        subprocess.run(["/usr/bin/systemctl", "daemon-reload"], check=True, timeout=25)
        for name in chosen:
            for unit in SITES[name]["start"]:
                subprocess.run(["/usr/bin/systemctl", "enable", "--now", unit],
                               check=True, timeout=40)
    return {"contract": "mad4b.cache-coherence-installer.v1",
            "version": "1.0.0", "sites": chosen, "mode": "apply" if apply else "check",
            "verified_files": len(entries), "new_files": len(absent),
            "overwritten_live_files": 0, "external_browser_tests": False,
            "wordpress_data_changes": False}


if __name__ == "__main__":
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--site", choices=["both", "wayfa", "toursdev"], default="both")
    action = cli.add_mutually_exclusive_group(required=True)
    action.add_argument("--verify-package", action="store_true")
    action.add_argument("--check", action="store_true")
    action.add_argument("--apply", action="store_true")
    args = cli.parse_args()
    try:
        if args.verify_package:
            result = {"contract": "mad4b.cache-coherence-installer.v1",
                      "locked_files": len(verify_package()["files"]),
                      "sha256_verified": True, "version": "1.0.0"}
        else:
            result = install(args.site, args.apply)
        print(json.dumps(result, sort_keys=True))
    except Exception as exc:
        print(json.dumps({"status": "FAIL_CLOSED", "reason": str(exc)[:240]}))
        raise SystemExit(2)
