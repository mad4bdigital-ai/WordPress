#!/usr/bin/env python3
"""Pinned 306-signed-grant original source guard.

Vetted from independently verified live source, not from outdated v3/306
candidate. Known older baselines are recoverable; any unknown newer version
DEFERs without modification. Never issues a Broker Job, Grant or receipt.
"""
from pathlib import Path
import sys
sys.path.insert(0,"/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts")
import guard_original_328_release as guard

guard.CORE=Path("/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py")
guard.VETTED=Path("/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/candidates/claimed-job-worker-signed-grant-preclaim.py")
guard.REPORT=Path("/opt/mad4b-company-provisioning/reports/original-313-worker-signed-preclaim-source-guard-current.json")
guard.BACKUPS=Path("/opt/mad4b-company-provisioning/backups/original-313-worker-signed-preclaim-source-release")
guard.LOCK=Path("/run/lock/mad4b-original-313-worker-signed-preclaim-source-release.lock")
guard.EXPECTED_OLD="9ec8a84a2ea3a6e4ddea258ca7443ecb041fafd3711af427fd860d17de07750c"
guard.EXPECTED_NEW="f75b5849de8838e67bd89f72375dbb90c9e4b596b6fb8b088828283e4fb8e45c"
guard.KNOWN_OLD=('665c3db7521d429ab9db15a502597f9769225a77fbc21d58330ec7cafcaede76','9ec8a84a2ea3a6e4ddea258ca7443ecb041fafd3711af427fd860d17de07750c',)

if __name__=="__main__":
    raise SystemExit(guard.main())
