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
guard.VETTED=Path(__file__).resolve().with_name("claimed-job-worker-postmeta-v6-code-release-merged.py")
guard.REPORT=Path("/opt/mad4b-company-provisioning/reports/original-313-worker-signed-preclaim-source-guard-current.json")
guard.BACKUPS=Path("/opt/mad4b-company-provisioning/backups/original-313-worker-signed-preclaim-source-release")
guard.LOCK=Path("/run/lock/mad4b-original-313-worker-signed-preclaim-source-release.lock")
guard.EXPECTED_OLD="bb505a2fb0b3e0834ce815b4c17b380e2fdde1193d8cdd6424b785223f315c6f"
guard.EXPECTED_NEW="df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1"
guard.KNOWN_OLD=('c0d93c133f538e8323c999a2512824b965e27568e25aac7a69347942b452e0f9', 'bb505a2fb0b3e0834ce815b4c17b380e2fdde1193d8cdd6424b785223f315c6f', '636068349b49be7906dc419462637988f3bf290e4647501e80415ab1abe1ffb3', '9ec8a84a2ea3a6e4ddea258ca7443ecb041fafd3711af427fd860d17de07750c')

if __name__=="__main__":
    raise SystemExit(guard.main())
