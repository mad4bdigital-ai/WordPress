#!/usr/bin/env python3
"""Original Git-blob native CSS source trust: no temporary-worktree dependency.

Run inside checkout, or provide [run-public-route.py] [repository root].
"""
import ast,hashlib,pathlib,sys,tempfile
if len(sys.argv)>=3:
 source=pathlib.Path(sys.argv[1]);repo=pathlib.Path(sys.argv[2])
else:
 source=pathlib.Path(__file__).resolve().parents[1]/'run-public-route.py'
 repo=pathlib.Path(__file__).resolve().parents[3]
ast_body=ast.parse(source.read_text()).body
method=next(n for n in ast_body if isinstance(n,ast.FunctionDef)
            and n.name=='approved_native_runtime_sha')
# Trust no runtime source tree because the agent can safely prune worktrees.
assert 'worktrees/pr814' not in ast.dump(method)
mu=repo/'wp-content/mu-plugins'
n421='421-mad4b-public-critical-css-coverage.php'
n422='422-mad4b-public-critical-css-runtime.php'
actual_421=(mu/n421).read_bytes()
actual_422=(mu/n422).read_bytes()
assert hashlib.sha256(actual_421).hexdigest()=='ae7936c7ad09b059375e1960d4c59144af0b4325933db34bcfa63c26e49233bb'
assert hashlib.sha256(actual_422).hexdigest()=='b5ed9a9629e0a547bf42803b4810b492f73a748eaaad66b5dde6e1961c7713f4'
with tempfile.TemporaryDirectory(prefix='mad4b-original-git-pin-') as tmp:
 home=pathlib.Path(tmp)/'wp-content'
 (home/'mu-plugins').mkdir(parents=True)
 for n,source_bytes in ((n421,actual_421),(n422,actual_422)):
  (home/'mu-plugins'/n).write_bytes(source_bytes)
 env={'hashlib':hashlib,'CONFIG':{'wayfa':('example.org','fixture',home,'production')},
      'ROOT':pathlib.Path(tmp)/'deleted-pr814-worktree'}
 exec(compile(ast.Module(body=[method],type_ignores=[]),str(source),'exec'),env)
 verify=env['approved_native_runtime_sha']
 assert verify('wayfa',home)==hashlib.sha256(actual_422).hexdigest()
 print('PASS original Git 421/422 authorize only an exact known source release')
 for n in (n421,n422):
  f=home/'mu-plugins'/n;b=f.read_bytes();f.write_bytes(b+b'\n# tampered')
  try:verify('wayfa',home)
  except ValueError as err:assert 'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT' in str(err)
  else:raise AssertionError('unsafe modified native component admitted')
  f.write_bytes(b)
 print('PASS both original Git components deny modified bytes')
 path=home/'mu-plugins'/n421
 path.unlink();path.symlink_to(mu/n421)
 try:verify('wayfa',home)
 except ValueError as err:assert 'SOURCE_LIVE_NATIVE_COMPONENT_DRIFT' in str(err)
 else:raise AssertionError('symlinked original Git plugin admitted')
 print('PASS symlink cannot grant a source release')
 path.unlink();path.write_bytes(actual_421)
 try:verify('wayfa',home.parent)
 except ValueError as err:assert 'SOURCE_NATIVE_COMPONENT_COMPANY_ROOT_MISMATCH' in str(err)
 else:raise AssertionError('other-site component root admitted')
 print('PASS root-scoped company identity still required')
 assert not env['ROOT'].exists()
 print('PASS stable Git source pin needs no deleted local checkout')
print('PASS 5/5 immutable source-ref regression groups')
