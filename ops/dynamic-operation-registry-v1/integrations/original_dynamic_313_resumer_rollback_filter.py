#!/usr/bin/env python3
"""Independent receipt-only recovery for the existing original 306 Worker.

An already reserved, already applied, first-party dynamic Code Release may be
finished by original Central 313. This never applies or replays target code.
"""
from __future__ import annotations

import argparse
import base64
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
from typing import Any

from original_dynamic_313_finalizer import (
    DYNAMIC, GRANT_STORE, OPERATION, PLAN_STORE, RESERVATIONS, Denied,
    HOST, native_signer, readback_original, send_original_313,
)
import registry
import worker_adapter
import company_code_release as release
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

def original_signed_rollback_verified(job:str,plan:dict)->bool:
    # A failed/rolled-back Host reservation must never be replayed through
    # 313. Skip ONLY an independently signed, exact-scope first-party
    # ROLLED_BACK outcome. Missing/tampered/uncertain evidence stays visible
    # to the original independent 313 for explicit reconciliation.
    try:
        if not re.fullmatch(r"[a-f0-9]{32}",job):
            return False
        grant_file=GRANT_STORE/(job+".json")
        st=grant_file.lstat()
        if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
            or st.st_mode&0o077 or grant_file.is_symlink()
            or not 10<st.st_size<100_000):
            return False
        grant=json.loads(grant_file.read_bytes())
        if not (isinstance(grant,dict)
            and grant.get("contract")==release.AUTH_CONTRACT
            and grant.get("issuer")=="mad4b-company-broker"
            and grant.get("action")=="apply"
            and grant.get("original_job_id")==job
            and grant.get("plan_sha256")==plan.get("plan_sha256")
            and grant.get("company")==plan.get("company")
            and grant.get("environment")==plan.get("environment")
            and grant.get("site_uuid")==plan.get("site_uuid")
            and grant.get("origin")==plan.get("origin")
            and isinstance(grant.get("nonce"),str)
            and re.fullmatch(r"[a-f0-9]{32}",grant["nonce"])):
            return False
        policy=release.trusted_config(release.POLICY_PATH)
        Ed25519PublicKey.from_public_bytes(base64.b64decode(
            policy["broker_public_key_b64"],validate=True)).verify(
                base64.b64decode(grant["signature"],validate=True),
                release.grant_message(grant))
        receipt_root=Path(policy["receipt_dir"])
        if (not receipt_root.is_dir() or receipt_root.is_symlink()
            or receipt_root.stat().st_uid!=0
            or receipt_root.stat().st_mode&0o077):
            return False
        receipt_file=receipt_root/(grant["nonce"]+".json")
        st=receipt_file.lstat()
        if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
            or st.st_mode&0o077 or receipt_file.is_symlink()
            or not 10<st.st_size<100_000):
            return False
        receipt=json.loads(receipt_file.read_bytes())
        return (isinstance(receipt,dict)
            and receipt.get("contract")==release.RECEIPT_CONTRACT
            and receipt.get("state")=="ROLLED_BACK"
            and receipt.get("nonce")==grant["nonce"]
            and receipt.get("plan_sha256")==plan.get("plan_sha256")
            and receipt.get("company")==plan.get("company")
            and receipt.get("environment")==plan.get("environment")
            and receipt.get("site_uuid")==plan.get("site_uuid")
            and receipt.get("git_commit")==plan.get("git_commit")
            and receipt.get("files")==[x["target"] for x in plan["files"]]
            and receipt.get("broker_grant_signature_verified") is True
            and receipt.get("original_313_receipt") is False
            and isinstance(receipt.get("failed_reason"),str)
            and bool(receipt["failed_reason"]))
    except (OSError,ValueError,KeyError,TypeError,AttributeError,
            InvalidSignature,release.Denied):
        return False


DONE=Path("/var/lib/mad4b/dynamic313-completions")
PATTERN=re.compile(r"^[a-f0-9]{32}\.reserved$")
HEX64=re.compile(r"^[a-f0-9]{64}$")


def candidates()->list[tuple[str,str]]:
    if os.geteuid()!=0:
        raise Denied("ORIGINAL_ROOT_SIGNED_313_RECOVERY_REQUIRED")
    if RESERVATIONS.is_symlink() or RESERVATIONS.stat().st_uid!=0 or (
        RESERVATIONS.stat().st_mode & 0o077):
        raise Denied("ORIGINAL_313_ROOT_RESERVATION_STORE_UNTRUSTED")
    index=registry.Registry(DYNAMIC)
    digest_map=worker_adapter.indexed_plans(
        index,Path("/var/lib/mad4b/dynamic-operation-registry/plans"))
    results=[]
    for path in sorted(RESERVATIONS.glob("*.reserved"))[:250]:
        if not PATTERN.fullmatch(path.name) or path.is_symlink():
            continue
        st=path.lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077:
            continue
        marker=path.read_bytes()
        if not marker.startswith(("dynamic:"+OPERATION+" ").encode()):
            continue
        digest=marker.decode("ascii",errors="replace").split(" ",1)[-1].strip()
        if not HEX64.fullmatch(digest):
            continue
        entry=digest_map.get(digest)
        if not isinstance(entry,dict) or entry.get("operation")!=OPERATION:
            continue
        job=path.name[:-len(".reserved")]
        plan=entry["plan"]
        sha=plan["plan_sha256"]
        if not (PLAN_STORE/(sha+".json")).is_file() or not (
              GRANT_STORE/(job+".json")).is_file():
            continue
        if original_signed_rollback_verified(job,plan):
            # The original Host rollback is final for this Job. The
            # independently signed 313 route cannot certify or replay it.
            continue
        results.append((job,sha))
    return results


def root_atomic_json(path:Path,data:dict):
    DONE.mkdir(parents=True,exist_ok=True)
    if DONE.is_symlink() or DONE.stat().st_uid!=0 or (
        DONE.stat().st_mode & 0o077):
        raise Denied("ROOT_313_COMPLETION_STORE_UNTRUSTED")
    fd,name=tempfile.mkstemp(prefix=".receipt-v4-",dir=DONE)
    try:
        with os.fdopen(fd,"w") as out:
            os.fchmod(out.fileno(),0o600)
            json.dump(data,out,sort_keys=True,separators=(",",":"))
            out.write("\n")
            out.flush()
            os.fsync(out.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)


def run(*,execute:bool):
    todo=candidates()
    states=[]
    for job,sha in todo:
        completed=DONE/(job+".json")
        if completed.is_symlink():
            raise Denied("ORIGINAL_313_COMPLETION_SYMLINK_DENIED")
        if completed.exists():
            data=json.loads(completed.read_text())
            if (data.get("job_id")==job and
                data.get("state")=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED"):
                continue
            raise Denied("ORIGINAL_313_EXISTING_COMPLETION_UNKNOWN")
        if not execute:
            states.append({"job_id":job,"state":"ELIGIBLE_FOR_FRESH_313_READBACK_CHECK"})
            continue
        # No direct code release apply, rollback, WordPress content edit or
        # 305/306 issuer in this program. All real authority is re-fetched.
        try:
            done=send_original_313(job,sha)
            if (done["state"]=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED"
                and done.get("original_313_certified") is True):
                root_atomic_json(completed,done)
            states.append({"job_id":job,"state":done["state"],
                           "original_313_certified":done["original_313_certified"]})
        except (RuntimeError,ValueError,OSError,TypeError,KeyError,
                registry.RegistryDenied) as exc:
            msg=str(exc)
            if not re.fullmatch(r"[A-Z][A-Z0-9_]{3,95}",msg):
                msg="ORIGINAL_INDEPENDENT_FINALIZATION_RECONCILE_REQUIRED"
            states.append({"job_id":job,"state":"RECEIPT_ONLY_RECOVERY_REQUIRED",
                           "reason":msg,"execution_replayed":False})
    return {
        "contract":"mad4b.original-dynamic-313-resume.v1",
        "state":"OBSERVED" if not execute else "RECEIPT_ONLY_CYCLE_COMPLETE",
        "jobs_considered":len(todo),"candidates":states,
        "original_jobs_created":False,"grants_created":False,
        "host_code_released":False,"production_mutated_by_resumer":False,
    }


if __name__=="__main__":
    ap=argparse.ArgumentParser()
    ap.add_argument("--observe-only",action="store_true")
    args=ap.parse_args()
    try:
        print(json.dumps(run(execute=not args.observe_only),sort_keys=True))
    except (Denied,RuntimeError,ValueError,OSError,TypeError,KeyError,
            registry.RegistryDenied) as exc:
        code=str(exc)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{3,95}",code):
            code="ORIGINAL_DYNAMIC_313_RECOVERY_UNAVAILABLE"
        print(json.dumps({"state":"HELD","reason":code,
                          "host_code_released":False,
                          "original_313_certified":False}))
        sys.exit(2)
