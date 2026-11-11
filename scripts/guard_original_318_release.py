#!/usr/bin/env python3
"""Observe-only Original318 v68 exact-source release gate.

Not a publisher and intentionally has no --apply path. Earlier production
safety denial cannot be replaced by root shell copy or old 328 recovery guard.
"""
from __future__ import annotations
import hashlib
import json
import os
import stat
import subprocess
import shutil
from pathlib import Path

LIVE=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/"
          "wp-content/plugins/mad4b-platform/modules/authority/"
          "318-mad4b-native-host-exact-grant.php")
VETTED=(Path(__file__).resolve().parents[1]/"deploy/host-authority/v68/"
        "318-mad4b-native-host-exact-grant.php")
EXPECTED_OLD="ecfefbee1a60f52431c4688f7b5858ea1df3633812483c9794f36515c418af55"
EXPECTED_NEW="17895c0c66381b2acaab1ef022d89a88eb79199847ebb022921da1296671ed7d"

def read_checked(path:Path, *, require_root:bool):
    meta=path.lstat()
    if not stat.S_ISREG(meta.st_mode) or path.is_symlink() or meta.st_size>150000:
        raise ValueError("ORIGINAL318_UNTRUSTED_SOURCE")
    # Live WordPress files are owned by the enrolled www-data UID 33;
    # vetted source is root-owned. Neither may be group/world writable.
    if (require_root and meta.st_uid != 0) or (
        not require_root and meta.st_uid not in (0,33)
    ) or meta.st_mode & 0o022:
        raise ValueError("ORIGINAL318_UNTRUSTED_OWNERSHIP")
    raw=path.read_bytes()
    if len(raw)!=meta.st_size:raise ValueError("ORIGINAL318_READ_RACE")
    return hashlib.sha256(raw).hexdigest()

def inspect(*,live:Path=LIVE,vetted:Path=VETTED,php_lint:bool=True)->dict:
    try:
        current=read_checked(live,require_root=False)
        candidate=read_checked(vetted,require_root=True)
        if candidate!=EXPECTED_NEW:
            state="BLOCKED_CANDIDATE_DIGEST_CHANGED"
        elif php_lint and shutil.which("php") is None:
            state="BLOCKED_PHP_LINT_RUNTIME_UNAVAILABLE"
        elif php_lint and subprocess.run(["php","-l",str(vetted)],
                                         stdout=subprocess.DEVNULL,
                                         stderr=subprocess.DEVNULL,
                                         timeout=15,check=False).returncode:
            state="BLOCKED_CANDIDATE_PHP_LINT"
        elif current==EXPECTED_NEW:
            state="PASS_SOURCE_CURRENT__RUNTIME_READBACK_REQUIRED"
        elif current==EXPECTED_OLD:
            state="BLOCKED_AUTHORIZED_ORIGINAL318_PUBLISHER_REQUIRED"
        else:
            state="BLOCKED_UNKNOWN_OR_NEWER_SOURCE_DO_NOT_OVERWRITE"
    except (OSError,ValueError,subprocess.TimeoutExpired):
        current="UNKNOWN";candidate="UNKNOWN"
        state="BLOCKED_SOURCE_PREIMAGE_OR_ACCESS_NOT_VERIFIED"
    return {
        "contract":"mad4b.original318.source-release-readonly-observer.v1",
        "state":state,
        "installed_sha256":current,
        "candidate_sha256":candidate,
        "expected_preimage_sha256":EXPECTED_OLD,
        "expected_postimage_sha256":EXPECTED_NEW,
        "release_approved":False,
        "release_performed":False,
        "original318_runtime_certified":False,
        "original313_receipt_created":False,
        "production_mutated":False,
    }
if __name__=="__main__":
    print(json.dumps(inspect(),sort_keys=True))
