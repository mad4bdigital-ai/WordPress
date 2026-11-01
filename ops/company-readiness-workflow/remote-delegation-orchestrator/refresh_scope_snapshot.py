#!/usr/bin/env python3
"""Periodic atomic root-only Original Core host scope *observation* refresh.

No grants, execution, WordPress user impersonation or host-policy writes.
Source: merged PR #723 live-once bridge, fail-closed on stale/invalid Core.
"""
from __future__ import annotations
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import sys
import tempfile

BRIDGE=Path("/opt/mad4b-company-provisioning/source-candidates/full-host-authority-pr723")
sys.path.insert(0,str(BRIDGE))
from live_full_host_authority import live_once

REPORT=Path("/opt/mad4b-company-provisioning/reports/company-full-host-authority-live-current.json")
LOCK=Path("/run/lock/mad4b-remote-delegation-scope-refresh.lock")
CONTRACT="mad4b.full-host-authority-live-reconciliation.v1"

def refresh():
    if os.geteuid()!=0:raise RuntimeError("ROOT_READBACK_REQUIRED")
    fd=os.open(str(LOCK),os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,"r+") as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        st=os.fstat(lock.fileno())
        if st.st_uid!=0 or st.st_mode & 0o077:
            raise RuntimeError("LOCK_PROTECTION_INVALID")
        x=live_once()
        if (not isinstance(x,dict) or x.get("contract")!=CONTRACT or
            any(x.get(k) is not False for k in (
                "grants_created","operations_executed","production_mutated",
                "wildcard_grants_created")) or
            not isinstance(x.get("targets"),list)):
            raise RuntimeError("ORIGINAL_SCOPE_SOURCE_UNTRUSTED")
        # The original PR #723 live_once() verifies the upstream Core
        # observation is current, but its projection intentionally contains
        # no observed_utc field. Stamp THIS independently verified projection.
        ts=dt.datetime.now(dt.timezone.utc).isoformat()
        x["observed_utc"]=ts
        REPORT.parent.mkdir(parents=True,exist_ok=True)
        current=REPORT.lstat()
        if REPORT.is_symlink() or current.st_uid!=0 or current.st_mode & 0o077:
            raise RuntimeError("ROOT_PROTECTED_REPORT_REQUIRED")
        fd,tmp=tempfile.mkstemp(prefix=".mad4b-host-scope-",dir=REPORT.parent)
        try:
            with os.fdopen(fd,"w") as f:
                os.fchmod(f.fileno(),0o600)
                json.dump(x,f,sort_keys=True,separators=(",",":"))
                f.write("\n")
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp,REPORT)
            dfd=os.open(str(REPORT.parent),os.O_RDONLY|os.O_DIRECTORY)
            try:os.fsync(dfd)
            finally:os.close(dfd)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
        return {"state":"READONLY_SCOPE_REFRESHED",
                "target_count":len(x["targets"]),"observed_utc":ts,
                "grants_created":False,"production_mutated":False}

if __name__=="__main__":
    try:print(json.dumps(refresh(),sort_keys=True))
    except Exception as e:
        print(json.dumps({"state":"DENIED","reason":type(e).__name__,
                          "grants_created":False,"production_mutated":False}),
              file=sys.stderr)
        raise SystemExit(2)

