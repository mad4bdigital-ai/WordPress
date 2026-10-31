#!/usr/bin/env python3
"""Execute bounded production-denial source contracts for Brand/Dependency/Recovery."""
from pathlib import Path
import subprocess,os,sys
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/plugins/mad4b-site-control-plane"
php=os.getenv("MAD4B_PHP","php")
mapping={
"brand-context-builder":("brand-context-builder-contract.py", "mad4b.brand-context-builder.v5: PASS"),
"dependency-manager":("dependency-mcp-adapter-integrity-runtime.php","mad4b.mcp-adapter-installed-integrity.v1: PASS"),
"recovery-attempt-budget":("brand-append-retry-budget-qa18.php","QA18_RETRY_BUDGET_PASS"),
"reversible-adapter-mutations":("provider-bounded-reversible-contract.py","mad4b.provider-bounded-reversible.v1: PASS"),
}
for name,(test_name,marker) in mapping.items():
    source=plugin/"includes"/("class-mad4b-scp-"+name+".php")
    test=plugin/"tests"/test_name
    assert source.is_file() and test.is_file()
    env=dict(os.environ)
    if "/" in php:env["PATH"]=str(Path(php).parent)+os.pathsep+env.get("PATH","")
    cmd=[sys.executable,str(test)] if test_name.endswith(".py") else [php,str(test)]
    p=subprocess.run(cmd,cwd=root,env=env,capture_output=True,text=True,timeout=120)
    assert p.returncode==0 and marker in p.stdout,(name,p.returncode,p.stdout[-300:],p.stderr[-300:])
    print("BRAND_DEPENDENCY_RECOVERY_PASS",name)
print("BRAND_DEPENDENCY_RECOVERY_BATCH PASS",len(mapping))
