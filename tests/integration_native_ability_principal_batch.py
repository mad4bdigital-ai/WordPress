#!/usr/bin/env python3
"""Run direct native Ability, DFSB adapter, mutation principal and trust contracts."""
import os
import subprocess
import sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/plugins/mad4b-site-control-plane"
php=os.environ.get("MAD4B_PHP","php")
suites={
"abilities":("includes/class-mad4b-scp-abilities.php","abilities-category-schema-status-contract.php"),
"dfsb-adapter":("includes/adapters/class-mad4b-scp-dfsb-adapter.php","etg-dfsb-adapter-contract.py"),
"mutation-principal":("includes/class-mad4b-scp-mutation-principal.php","dual-principal-runtime.php"),
"native-ability-trust":("includes/class-mad4b-scp-native-ability-trust.php","native-adapter-remote-grant-source-contract.py"),
}
for name,(source_name,test_name) in suites.items():
 source=plugin/source_name
 test=plugin/"tests"/test_name
 assert source.is_file() and test.is_file(),name
 env=dict(os.environ)
 if os.path.sep in php:env["PATH"]=str(Path(php).parent)+os.pathsep+env.get("PATH","")
 cmd=[sys.executable,str(test)] if test_name.endswith(".py") else [php,str(test)]
 proc=subprocess.run(cmd,cwd=root,capture_output=True,text=True,timeout=120,env=env)
 assert proc.returncode==0 and "PASS" in proc.stdout,(name,proc.returncode,proc.stdout[-350:],proc.stderr[-350:])
 print("NATIVE_ABILITY_PRINCIPAL_PASS",name)
print("NATIVE_ABILITY_PRINCIPAL_BATCH PASS",len(suites))
