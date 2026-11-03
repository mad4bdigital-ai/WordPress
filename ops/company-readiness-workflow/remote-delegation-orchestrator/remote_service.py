#!/usr/bin/env python3
"""MAD4B root-only Unix socket remote owner delegation service (Staging).

No TCP listener, no implicit owner, no SQL or arbitrary Shell. Original
WordPress REST remains the ONLY owner/manager write authorization issuer.
"""
import json
import os
from pathlib import Path
import socket
import stat
import struct
import subprocess
import signal
import threading
import time
import sys
from typing import Any

from remote_delegation import (Refused, OriginalOwnerRest, Orchestrator,
                               Target, from_source)
from signed_broker import SignedOriginal305Broker

SRC = Path("/opt/mad4b-company-provisioning/source-candidates/full-host-authority-pr723")
sys.path.insert(0,str(SRC))
from live_full_host_authority import live_once

SOCKET = Path("/run/mad4b-company-remote-delegation/service.sock")
CONTRACT = "mad4b.remote-company-delegation-service.v1"
MAX_BYTES = 16384
ALLOWED = {"status", "reconcile305"}
REPORT=Path("/opt/mad4b-company-provisioning/reports/company-full-host-authority-live-current.json")
MAX_CONCURRENT=4
MAX_SCOPE_SNAPSHOT_AGE=900

def _native_status(company):
    # Fixed WP-CLI readback; no --user, password, dynamic executable or eval.
    c=["/usr/bin/docker","exec","-u","33:33","etg-commerce-staging-php",
       "/usr/local/bin/wp","mad4b","company-native-wpcli","status",
       "--company="+company,"--environment=staging",
       "--path=/var/www/html","--skip-themes"]
    r=subprocess.run(c,capture_output=True,text=True,timeout=45,check=False)
    if r.returncode!=0:return {"state":"ORIGINAL_305_READBACK_UNAVAILABLE"}
    try:
        rows=[json.loads(v) for v in r.stdout.splitlines() if v.startswith("{")]
    except ValueError:return {"state":"ORIGINAL_305_RESPONSE_INVALID"}
    if len(rows)!=1:return {"state":"ORIGINAL_305_RESPONSE_INVALID"}
    v=rows[0]
    return {"state":v.get("state"),"reason":v.get("reason"),
            "grant_registered":v.get("state")=="ORIGINAL_305_PERSISTENT_PAIR_ENROLLED",
            "grant_active":v.get("full_wpcli_active") is True and
                v.get("host_authority_verified") is True}

def _manager_plan(company):
    # Non-mutating original WordPress owner assignment plan.
    c=["/usr/bin/docker","exec","-u","33:33","etg-commerce-staging-php",
       "/usr/local/bin/wp","mad4b","environment-manager-plan",company,
       "--path=/var/www/html","--skip-themes"]
    r=subprocess.run(c,capture_output=True,text=True,timeout=45,check=False)
    if r.returncode!=0:return {"eligible":False,"reason":"ORIGINAL_OWNER_PLAN_UNAVAILABLE"}
    try:rows=[json.loads(x) for x in r.stdout.splitlines() if x.startswith("{")]
    except ValueError:return {"eligible":False,"reason":"ORIGINAL_OWNER_PLAN_INVALID"}
    if len(rows)!=1:return {"eligible":False,"reason":"ORIGINAL_OWNER_PLAN_INVALID"}
    d=rows[0]
    return {"eligible":d.get("eligible") is True,
            "reason":d.get("reason"),
            "assignment_state":d.get("assignment_state"),
            "next_action":d.get("next_action"),"revision":d.get("revision")}

def cached_readonly_source():
    # Snapshot is only used to discover targets in status, never to issue
    # grants. Every write uses fresh Original Core verification.
    st=REPORT.lstat()
    if REPORT.is_symlink() or st.st_uid!=0 or stat.S_IMODE(st.st_mode)!=0o600 or st.st_size>262144:
        raise Refused("READONLY_SNAPSHOT_UNTRUSTED")
    source=json.loads(REPORT.read_text())
    source["_snapshot_age_seconds"]=max(0,int(time.time()-st.st_mtime))
    return source

def status(request, source):
    target=from_source(source,request["company"])
    scope=[x for x in source["targets"] if x.get("target")==target.company+":staging"][0]
    snapshot_age=source.get("_snapshot_age_seconds")
    snapshot_fresh=(type(snapshot_age) is int and snapshot_age<=MAX_SCOPE_SNAPSHOT_AGE)
    host_scope=(snapshot_fresh and scope.get("verified_host_scope_id")=="srv2030908"
                and scope.get("full_host_scope_observed") is True)
    return {"contract":CONTRACT,"state":"READ_ONLY_STATUS","target":target.company+":staging",
            "site_uuid":target.site_uuid,"canonical_origin":target.canonical_origin,
            "host_scope_ready":host_scope,"host_scope_snapshot_stale":not snapshot_fresh,
            "host_scope_snapshot_age_seconds":snapshot_age,
            "owner_assignment":_manager_plan(target.company),
            "native305":_native_status(target.company),
            "grant_issued":False,"production_mutated":False}

def handle(data:Any, source_fn=live_once, rest_factory=OriginalOwnerRest,
           broker_factory=SignedOriginal305Broker):
    if not isinstance(data,dict) or "action" not in data or "company" not in data:
        raise Refused("REQUEST_SHAPE_INVALID")
    action=data["action"]
    if action not in ALLOWED or not isinstance(data["company"],str):
        raise Refused("UNKNOWN_OR_UNSUPPORTED_OPERATION")
    if action=="status":
        if set(data)!={"action","company"}:
            raise Refused("STATUS_READONLY_SHAPE_INVALID")
        return status(data,cached_readonly_source() if source_fn is live_once else source_fn())
    if set(data)!={"action","company","manager_id","reason","owner_headers"}:
        raise Refused("RECONCILE305_EXACT_SHAPE_REQUIRED")
    if not isinstance(data["owner_headers"],dict):
        raise Refused("ORIGINAL_OWNER_SESSION_REQUIRED")
    if not isinstance(data["reason"],str) or not 12<=len(data["reason"])<=1200:
        raise Refused("ORIGINAL_AUDIT_REASON_REQUIRED")
    # Only real owner REST credentials accepted; authenticated by ORIGINAL WP.
    owner=rest_factory(data["owner_headers"])
    workflow=Orchestrator(owner,broker_factory())
    result=workflow.reconcile(source_fn(),data["company"],
                              data["manager_id"],data["reason"],apply=True)
    return {"service_contract":CONTRACT, **result}

def process_message(msg:bytes, **kwargs)->dict:
    if len(msg)>MAX_BYTES:raise Refused("REQUEST_TOO_LARGE")
    try:data=json.loads(msg)
    except (ValueError,UnicodeDecodeError) as exc:raise Refused("INVALID_JSON") from exc
    return handle(data,**kwargs)

def _peer_root(conn:socket.socket)->bool:
    # Linux-only peer-credentials. A local socket's user-supplied JSON cannot
    # override this identity check. Remote use must go through authorized SSH.
    raw=conn.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12)
    pid,uid,gid=struct.unpack("3i",raw)
    return uid==0

def serve():
    if os.geteuid()!=0:raise Refused("REGISTERED_ROOT_SERVICE_REQUIRED")
    parent=SOCKET.parent
    st=parent.stat()
    if st.st_uid!=0 or stat.S_IMODE(st.st_mode)!=0o700:
        raise Refused("RUNTIME_DIRECTORY_NOT_ROOT_ONLY")
    if SOCKET.exists() or SOCKET.is_symlink():
        raise Refused("SOCKET_EXISTS_REFUSE_OVERWRITE")
    # Stop cleanly on systemd SIGTERM so a restart never inherits a stale
    # socket path with ambiguous ownership.
    def terminate(signum,frame):
        raise SystemExit(0)
    signal.signal(signal.SIGTERM,terminate)
    sock=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
    sock.bind(str(SOCKET))
    os.chmod(SOCKET,0o600)
    sock.listen(8)
    slots=threading.BoundedSemaphore(MAX_CONCURRENT)
    def serve_connection(conn):
        try:
            with conn:
                conn.settimeout(180)
                if not _peer_root(conn):
                    try:conn.sendall(b'{"state":"DENIED","reason":"UNAUTHORIZED_PEER"}\n')
                    except OSError:pass
                    return
                try:
                    raw=b""
                    while len(raw)<=MAX_BYTES:
                        b=conn.recv(min(4096,MAX_BYTES+1-len(raw)))
                        if not b or b.endswith(b"\n"):
                            raw+=b
                            break
                        raw+=b
                    if len(raw)>MAX_BYTES:raise Refused("REQUEST_TOO_LARGE")
                    value=process_message(raw)
                    value={"service":CONTRACT,**value}
                except Exception as exc:
                    reason=str(exc) if isinstance(exc,Refused) else "ORIGINAL_PROVIDER_UNAVAILABLE"
                    value={"service":CONTRACT,"state":"DENIED","reason":reason,
                           "grant_issued":False,"production_mutated":False}
                try:
                    conn.sendall((json.dumps(value,sort_keys=True,separators=(",",":"))+"\n").encode())
                except (BrokenPipeError, ConnectionResetError, TimeoutError, OSError):
                    # A disconnected client is not a daemon failure.
                    pass
        finally:
            slots.release()
    try:
        while True:
            conn,_=sock.accept()
            if not slots.acquire(blocking=False):
                conn.close()
                continue
            threading.Thread(target=serve_connection,args=(conn,),daemon=True).start()

    finally:
        sock.close()
        try:SOCKET.unlink(missing_ok=True)
        except OSError:pass

if __name__=="__main__":
    serve()

