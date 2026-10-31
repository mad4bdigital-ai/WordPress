#!/usr/bin/env python3
"""Execute targeted existing source suites for provider, governance and native integration."""
import os
import subprocess
import sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/plugins/mad4b-site-control-plane"
php=os.environ.get("MAD4B_PHP","php")
suites={
"adaptive-operation-router":"adaptive-operation-router-runtime.php",
"capability-dependency-planner":"unified-capability-dependency-runtime.php",
"catalog-intent-policy":"catalog-intent-plan-qa31-runtime.php",
"context-preflight":"ai-approval-target-context-contract.php",
"developer-authority":"full-staging-cli-access-contract.php",
"mcp-peer-governance":"mcp-peer-admin-rest-lifecycle-runtime.php",
"oauth-subject-user-bridge":"mcp-delegated-oauth-map-hermetic.php",
"operator-grant-convergence":"operator-grant-output-schema-runtime.php",
"provider-connections-admin-ui":"provider-connections-admin-read-hermetic.php",
"reconnect-hardening":"reconnect-hardening-runtime.php",
"research-intelligence":"research-provider-plan-contract.php",
"scheduler-backlog":"scheduler-backlog-contract.php",
"staging-source-selector":"staging-source-selector-runtime.php",
"market-content-exchange":"market-growth-competitor-dmc-runtime.php",
"search-provider-connections":"provider-setup-coverage-runtime.php",
"skills-admin-ui":"dynamic-skills-contract.py",
}
for name,filename in suites.items():
 source=plugin/"includes"/("class-mad4b-scp-"+name+".php")
 if name=="search-provider-connections":source=plugin/"includes/search/class-mad4b-scp-search-provider-connections.php"
 suite=plugin/"tests"/filename
 assert source.is_file() and suite.is_file(),name
 env=dict(os.environ)
 if os.path.sep in php:env["PATH"]=str(Path(php).parent)+os.pathsep+env.get("PATH","")
 cmd=[sys.executable,str(suite)] if filename.endswith(".py") else [php,str(suite)]
 result=subprocess.run(cmd,cwd=root,capture_output=True,text=True,env=env,timeout=120)
 assert result.returncode==0 and "PASS" in result.stdout,(name,result.returncode,result.stdout[-400:],result.stderr[-400:])
 print("PROVIDER_OPERATOR_SOURCE_SUITE_PASS:",name)
print("PROVIDER_OPERATOR_RUNTIME_BATCH: PASS",len(suites))
