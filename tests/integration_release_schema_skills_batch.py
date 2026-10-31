#!/usr/bin/env python3
"""Exercise actual runtime contracts for release, schema, profile and local skill authority."""
import os,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/plugins/mad4b-site-control-plane"
php=os.environ.get("MAD4B_PHP","php")
mapping={
"dynamic-content-adapter":("includes/adapters/class-mad4b-scp-dynamic-content-adapter.php","dynamic-content-orchestration-contract.py"),
"full-staging-authority":("includes/class-mad4b-scp-full-staging-authority.php","full-staging-cli-access-contract.php"),
"runtime-release-set":("includes/class-mad4b-scp-runtime-release-set.php","runtime-release-set-admin-preflight-runtime.php"),
"schema":("includes/class-mad4b-scp-schema.php","schema-migration-runtime-contract.php"),
"site-profile-admin":("includes/class-mad4b-scp-site-profile-admin.php","deployment-binding-profile-save-bootstrap-runtime.php"),
"skill-abilities":("includes/class-mad4b-scp-skill-abilities.php","skill-task-abilities-runtime.php"),
"google-drive-context":("includes/class-mad4b-scp-google-drive-context.php","context-oauth-lifecycle-runtime.php"),
"skill-seeder":("includes/class-mad4b-scp-skill-seeder.php","native-plugin-management-zero-touch-runtime.php"),
"provider-compatibility-certification":("includes/class-mad4b-scp-provider-compatibility-certification.php","certification-pack-registry-contract.php"),
"staging-write-authority":("includes/class-mad4b-scp-staging-write-authority.php","staging-write-authority-postcondition-runtime.php"),
"policy":("includes/class-mad4b-scp-policy.php","production-readiness-evaluator-runtime.php"),
}
for name,(source,test_name) in mapping.items():
 path=plugin/source
 suite=plugin/"tests"/test_name
 assert path.is_file() and suite.is_file(),name
 env=dict(os.environ)
 if os.path.sep in php:env["PATH"]=str(Path(php).parent)+os.pathsep+env.get("PATH","")
 cmd=[sys.executable,str(suite)] if test_name.endswith(".py") else [php,str(suite)]
 res=subprocess.run(cmd,capture_output=True,text=True,cwd=root,env=env,timeout=120)
 assert res.returncode==0 and "PASS" in res.stdout,(name,res.returncode,res.stdout[-300:],res.stderr[-300:])
 print("RELEASE_SCHEMA_SKILLS_SUITE_PASS",name)
print("RELEASE_SCHEMA_SKILLS_BATCH: PASS",len(mapping))
