#!/usr/bin/env python3
"""Known-hash original Original 305 signed encrypted command intake release guard; no broad authority creation."""
from pathlib import Path
import sys
sys.path.insert(0,"/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts")
import guard_original_328_release as guard

guard.CORE=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-platform/modules/compat/305-mad4b-central-ssh-command-intake.php")
guard.VETTED=Path("/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/candidates/305-mad4b-central-ssh-command-intake-with-sealed-argv.php")
guard.REPORT=Path("/opt/mad4b-company-provisioning/reports/original-305-intake-source-guard-current.json")
guard.BACKUPS=Path("/opt/mad4b-company-provisioning/backups/original-305-intake-source-release")
guard.LOCK=Path("/run/lock/mad4b-original-305-intake-source-release.lock")
guard.EXPECTED_OLD="f9ad49d9aee65eeca37f7448e3f1913cb871a4025ca6ea82858881a047d5a302"
guard.EXPECTED_NEW="55a305f2b216f06344f8686e44b2d41b3b2c50a4accc8e1c9eb50fe66efec1e3"
guard.KNOWN_OLD=("55c757f0c499df3f1a332cd2986520bca9d0a4087d6841eaa49b0f03bc50d7b6",guard.EXPECTED_OLD)

if __name__=="__main__":
    raise SystemExit(guard.main())
