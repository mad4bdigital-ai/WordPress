#!/usr/bin/env python3
"""Test the REAL exact-source acceptance function with positive/negative fixtures."""
import ast
import hashlib
import json
import os
import tempfile
from pathlib import Path

root=Path(__file__).resolve().parents[4]
original=root/'wp-content/plugins/mad4b-site-control-plane/tests/integration-pr415-pr416-pr420-pr421-contract.py'
source=ast.parse(original.read_text())
function=next(n for n in source.body if isinstance(n,ast.FunctionDef) and n.name=='reviewed_source_supersedes_snapshot')
module=ast.fix_missing_locations(ast.Module(body=[function],type_ignores=[]))
admin='wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php'
direct='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-direct-profile.php'
skills='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skills-admin-ui.php'
contents={path:(root/path).read_bytes() for path in (admin,direct,skills)}
def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode('ascii')+b'\0'+data).hexdigest()
# A historical SHA remains immutable, but each later Admin smoke extension must
# be present as an append-only exact-source review with real WordPress regressions.
ledger=json.loads((root/'governance/production-readiness/admin-pages-ci-source-reviews.json').read_text())
rows=ledger['successors']
assert len(rows)>=3 and ledger['production_authorized'] is False
assert rows[0]['prior_source_blob_sha']=='0c0c2052254a5c893a79d45aa9d985b57d8361fd'
assert all(a['source_blob_sha']==b['prior_source_blob_sha'] for a,b in zip(rows,rows[1:]))
assert rows[-1]['source_blob_sha']==blob(contents[admin])
assert all(x['status']=='reviewed_for_ci_only' and x['production_authorized'] is False for x in rows)

reviews={(direct,blob(contents[direct])),(skills,blob(contents[skills]))}
namespace={'Path':Path,'hashlib':hashlib,'json':json,'reviewed_exact_blobs':set(reviews),'workflow_path':'.github/workflows/feature-007-pre-staging-hybrid-audit.yml','reviewed_workflow_exact_blobs':set()}
exec(compile(module,str(original),'exec'),namespace)
accept=namespace['reviewed_source_supersedes_snapshot']
with tempfile.TemporaryDirectory(prefix='mad4b-admin-succ-') as td:
 t=Path(td)
 for path,data in contents.items():
  f=t/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
 ledger_path=t/'governance/production-readiness/admin-pages-ci-source-reviews.json'
 ledger_path.parent.mkdir(parents=True,exist_ok=True)
 ledger_path.write_text(json.dumps(ledger))
 cwd=Path.cwd();os.chdir(t)
 try:
  assert accept(admin),'Exact reviewed current successor with independent Direct + Skills runtime sources passes'
  namespace['reviewed_exact_blobs']={(direct,blob(contents[direct]))}
  assert not accept(admin),'Missing independent Skills runtime source review fails closed'
  namespace['reviewed_exact_blobs']={(skills,blob(contents[skills]))}
  assert not accept(admin),'Missing reviewed Direct Profile source fails closed'
  namespace['reviewed_exact_blobs']=set(reviews)
  (t/admin).write_bytes(contents[admin]+b'\n/* changed after review */\n')
  assert not accept(admin),'Any unreviewed successor byte change fails closed'
  (t/admin).write_bytes(contents[admin])
  (t/skills).write_bytes(contents[skills]+b'\n/* altered runtime source */\n')
  assert not accept(admin),'Unreviewed runtime Skills implementation fails closed'
  (t/skills).write_bytes(contents[skills])
  assert accept(admin),'Restore exact source identity and approval evidence'
  forged=json.loads(ledger_path.read_text())
  forged['successors'][-1]['source_blob_sha']='a'*40
  ledger_path.write_text(json.dumps(forged))
  assert not accept(admin),'Incorrect newest CI-only Admin source review must be denied'
  ledger_path.write_text(json.dumps(ledger))
  forged=json.loads(ledger_path.read_text())
  forged['successors'][-1]['production_authorized']=True
  ledger_path.write_text(json.dumps(forged))
  assert not accept(admin),'CI-only review cannot assert Production authorization'
  ledger_path.write_text(json.dumps(ledger))
  assert accept(admin),'Restored exact append-only review and source identities'
 finally:os.chdir(cwd)
print('PASS: exact successor Admin smoke accepted only with reviewed runtime sources; drift, removed permission proof, and forged successor denied')
