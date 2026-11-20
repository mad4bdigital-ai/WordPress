#!/usr/bin/env python3
"""Reconcile historic Original306 legacy classifier warning, never delete it.

One-shot audited *status* annotation after independently verifying the three
Original Host signed records and current Original318 Revision 1. No existing
journal line, signed artifact, Worker path or WordPress state is changed.
"""
from __future__ import annotations
from pathlib import Path
import hashlib
import importlib.util
import json
import os
import re
import subprocess
import time

SRC=Path("/opt/mad4b-company-transport/native-313-independent/host-authority-v123/publish_final_certificate.py")
SHA="1f82f05637decb571b3b7ddc5c34f580311fa961b1a2649c80f7a181fd3bcf45"
JID="5b21b7aae2de8e3ef9342ae2ceaa2802"
REPORT=Path("/opt/mad4b-company-provisioning/reports/host-v123-original306-historical-reconciliation-20261120.json")
NOTE="ORIGINAL306_LEGACY_HOST_CLASSIFIER_HOLD_RECONCILED_BY_SIGNED_FINAL_RECEIPT"
class AuditHeld(ValueError):pass
def verify():
    if (os.geteuid()!=0 or not SRC.is_file() or SRC.is_symlink()
        or hashlib.sha256(SRC.read_bytes()).hexdigest()!=SHA):
        raise AuditHeld("V123_HISTORICAL_AUDIT_SOURCE_NOT_PINNED")
    sp=importlib.util.spec_from_file_location("_v123_audit_originals",SRC)
    m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
    b,_=m.read_verified_originals()
    if b["job_id"]!=JID or b["receipt"]["revision"]!=1:
        raise AuditHeld("V123_SIGNED_RECEIPT_NOT_COMPLETE")
    out=subprocess.run(["journalctl","-u",
        "mad4b-company-original-313-worker.service",
        "--since","2026-11-20 04:04:15 UTC",
        "--until","2026-11-20 04:06:20 UTC",
        "-o","short-iso-precise","--no-pager"],
        capture_output=True,text=True,timeout=10,check=True).stdout
    warning=[line for line in out.splitlines() if
        "ORIGINAL306_HOST_V2_CLAIM_HELD:ORIGINAL306_HOST_PRINCIPAL_BINDING_MISMATCH" in line]
    success=[line for line in out.splitlines() if
        '"state": "FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED"' in line
        and JID in line]
    if len(warning)!=1 or len(success)!=1 or not (
        out.index(warning[0])<out.index(success[0])):
        raise AuditHeld("V123_LEGACY_HOLD_AND_SUCCESS_NOT_IN_AUDIT_ORDER")
    return {
      "contract":"mad4b.host-v123.historical-original306-claim-reconciliation.v1",
      "state":NOTE,"job_id":JID,
      "scope":"all-royal-egypt/staging",
      "anchor_revision":1,
      "original313_receipt_sha256":hashlib.sha256(
          m.canonical(b["receipt"])).hexdigest(),
      "original_legacy_warning_journal_line_sha256":
          hashlib.sha256(warning[0].encode()).hexdigest(),
      "original_signed_success_journal_line_sha256":
          hashlib.sha256(success[0].encode()).hexdigest(),
      "original_warning_kept":True,
      "generic_host_execution_enabled":False,
      "original306_worker_modified":False,
      "wordpress_mutated":False,
    }
def main():
    rec=verify()
    if REPORT.exists():
        if REPORT.is_symlink() or json.loads(REPORT.read_text())!=rec:
            raise AuditHeld("V123_IMMUTABLE_HISTORICAL_AUDIT_DRIFT")
        print(json.dumps({"state":"EXISTING_AUDIT_RECONCILIATION_VERIFIED",
                          "job_id":JID}))
        return 0
    fd=os.open(REPORT,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,"wb") as f:
        f.write(json.dumps(rec,sort_keys=True,indent=2).encode()+b"\n")
        f.flush();os.fsync(f.fileno())
    # Append a separate resolution event: NEVER rewrite or remove the
    # historical held Claim warning. One-time only after persisted proof.
    subprocess.run(["logger","-t","mad4b-host-authority",
        "-p","daemon.notice",NOTE+" job_id="+JID+
        " original313_final_receipt_revision=1 legacy_warning_preserved=true"],
        check=True,timeout=5)
    print(json.dumps({"state":NOTE,"job_id":JID,
        "old_warning_preserved":True,"signed_receipt_verified":True}))
    return 0
if __name__=="__main__":
    raise SystemExit(main())
