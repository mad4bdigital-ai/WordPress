#!/usr/bin/env python3
"""Run exact readiness/admin boundary tests including the QA31 source-invariant checker."""
import os
import subprocess
import sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=os.environ.get("MAD4B_PHP","php")
plugin=root/"wp-content/plugins/mad4b-site-control-plane/tests"
suites=[
    ([sys.executable,str(plugin/"qa31-remaining-source-security-contract.py")],"QA31_REMAINING_SOURCE_SECURITY_CONTRACT: PASS"),
    ([php,str(plugin/"content-readiness/event-automation-contract.php")],"READINESS_EVENT_AUTOMATION_CONTRACT: PASS"),
    ([php,str(plugin/"content-readiness/preactivation-team-contract.php")],"TEAM_PREACTIVATION_DRAFT_CONTRACT: PASS"),
    ([php,str(plugin/"content-readiness/isolated-contract.php")],"ALL READINESS LEDGER CONTRACTS PASSED"),
]
for command,marker in suites:
    env=dict(os.environ)
    if os.path.sep in php:
        env["PATH"]=str(Path(php).parent)+os.pathsep+env.get("PATH","")
    result=subprocess.run(command,cwd=root,capture_output=True,text=True,timeout=120,env=env)
    assert result.returncode==0 and marker in result.stdout,(marker,result.returncode,result.stdout[-400:],result.stderr[-400:])
    print("READINESS_COMPANY_SUITE_PASS",marker)
print("READINESS_COMPANY_INTEGRATIONS_BATCH: PASS",len(suites))
