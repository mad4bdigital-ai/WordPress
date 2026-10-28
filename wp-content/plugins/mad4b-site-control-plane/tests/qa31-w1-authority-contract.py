#!/usr/bin/env python3
"""Reviewed bounded W1 authority contracts. No WordPress DB/HTTP or Production authority."""
from pathlib import Path
import shutil,subprocess,sys
root=Path(__file__).resolve().parent
cases={
    'qa31-w1-authority-owner-boundary-runtime.php':'QA31_W1_OWNER_BROWSER_GATE: PASS',
    'qa31-w1-agent-route-boundary-runtime.php':'QA31_W1_AGENT_REST_ROUTING: PASS',
    'replay-policy-runtime.php':'mad4b.replay-policy.runtime.v1: PASS',
    'context-authority-runtime.php':'mad4b.site-control-plane.context-authority-runtime.v5: PASS',
    'context-recreate-registry-rollback-runtime.php':'mad4b.site-control-plane.context-recreate-registry-rollback.runtime.v3: PASS',
    'site-profile-lifecycle-matrix-runtime.php':'"result":"PASS"',
    'site-profile-write-enablement-runtime.php':'mad4b.site-profile-write-enablement.runtime.v2: PASS',
    'site-profile-host-environment-reconcile-runtime.php':'PASS: host environment reconciliation decision matrix (11/11)',
    'staging-write-grant-reviewed-inventory-runtime.php':'PASS: 38 Feature 007 plus 3 native Staging pairs',
    'staging-write-authority-postcondition-runtime.php':'STAGING_WRITE_SELF_CONFIRMATION: PASS',
    'impact-approval-receipt-routing-runtime.php':'mad4b.impact-approval-receipt-routing.runtime.v1: PASS',
    'approval-plan-reconciliation-runtime.php':'mad4b.approval-plan-reconciliation.runtime.v1: PASS',
    'context-legacy-owner-provider-proof-runtime.php':'mad4b.site-control-plane.context-legacy-owner-provider-proof.runtime.v1: PASS',
    'oauth-resource-signature-hermetic.php':'OAUTH_RS256_ORIGINAL: PASS 23',
}
php=shutil.which('php')
if not php:raise SystemExit('QA31_W1_CONTRACT: FAIL PHP interpreter unavailable')
for filename,marker in cases.items():
    path=root/filename
    if not path.is_file() or path.is_symlink():raise SystemExit('QA31_W1_CONTRACT: FAIL missing fixture '+filename)
    try:
        out=subprocess.run([php,str(path)],cwd=root,capture_output=True,text=True,timeout=22,check=False)
    except (OSError,subprocess.TimeoutExpired) as exc:
        raise SystemExit('QA31_W1_CONTRACT: FAIL '+filename+' '+str(exc))
    if out.returncode or marker not in out.stdout or any(line.startswith('FAIL') for line in out.stdout.splitlines()):
        print(out.stdout[-1600:],file=sys.stderr)
        print(out.stderr[-1600:],file=sys.stderr)
        raise SystemExit('QA31_W1_CONTRACT: FAIL '+filename)
    print('PASS '+filename)
print('QA31_W1_AUTHORITY_CONTRACT: PASS '+str(len(cases))+' executed hermetic PHP tests')
