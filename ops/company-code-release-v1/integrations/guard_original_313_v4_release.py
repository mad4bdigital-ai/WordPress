#!/usr/bin/env python3
"""Pinned 313-v4 original source guard.

Vetted from independently verified live source, not from outdated v3/306
candidate. Known older baselines are recoverable; any unknown newer version
DEFERs without modification. Never issues a Broker Job, Grant or receipt.
"""
from pathlib import Path
import sys
sys.path.insert(0,"/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts")
import guard_original_328_release as guard

guard.CORE=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules/services/313b-mad4b-native-independent-verifier.php")
guard.VETTED=Path("/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/candidates/313b-mad4b-native-independent-verifier-dynamic-v4.php")
guard.REPORT=Path("/opt/mad4b-company-provisioning/reports/original-313-v4-source-guard-current.json")
guard.BACKUPS=Path("/opt/mad4b-company-provisioning/backups/original-313-v4-source-release")
guard.LOCK=Path("/run/lock/mad4b-original-313-v4-source-release.lock")
guard.EXPECTED_OLD="dba652c7d01c24167806a9316d75d66e8b58c49b7733467be2f2e7d55f5aac28"
guard.EXPECTED_NEW="8cb58178a3cc16d0cfd10cd27ac30b797a5041692deb835cb264e4cb2565329a"
guard.KNOWN_OLD=('90ce694fce42be42e6389b6f67c720f55ae27000b929c0ff33ff8c052521fc4e','3530fdfed01152c3c2490ed6b5832804bdafccf091d6e601f31176c8c15e885a','dba652c7d01c24167806a9316d75d66e8b58c49b7733467be2f2e7d55f5aac28',)

if __name__=="__main__":
    raise SystemExit(guard.main())
