#!/usr/bin/env python3
"""Test the REAL exact-source acceptance function with positive/negative fixtures."""
import ast
import hashlib
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
assert blob(contents[admin])=='0b081f2b6a4b1f1144e1ef25590d366856558fb8'
reviews={(direct,blob(contents[direct])),(skills,blob(contents[skills]))}
namespace={'Path':Path,'hashlib':hashlib,'reviewed_exact_blobs':set(reviews),'workflow_path':'.github/workflows/feature-007-pre-staging-hybrid-audit.yml','reviewed_workflow_exact_blobs':set()}
exec(compile(module,str(original),'exec'),namespace)
accept=namespace['reviewed_source_supersedes_snapshot']
with tempfile.TemporaryDirectory(prefix='mad4b-admin-succ-') as td:
 t=Path(td)
 for path,data in contents.items():
  f=t/path;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
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
 finally:os.chdir(cwd)
print('PASS: exact successor Admin smoke accepted only with reviewed runtime sources; drift, removed permission proof, and forged successor denied')
