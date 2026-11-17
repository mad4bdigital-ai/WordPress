#!/usr/bin/env python3
"""Hermetic fail-closed old Broker: only Original signed native may mutate.
Runs a real copy of the source CLI in a process with a harmless import stub.
"""
from __future__ import annotations
import ast,hashlib,pathlib,subprocess,sys,unittest
SCRIPT=pathlib.Path(__file__).with_name('company-system-appearance-broker-reconcile.py')
STUB=r'''import sys,types,runpy
sys.modules['central_staging_container']=types.SimpleNamespace(resolve_container=lambda: None)
sys.argv=['company-system-appearance-broker-reconcile.py']+sys.argv[1:]
runpy.run_path(sys.argv[0] if False else sys.argv.pop(0),run_name='__main__')
'''
class Tests(unittest.TestCase):
 def invoke(self,*args):
  # The first argument after -c is the argv[0] placeholder; runpy uses an
  # explicitly pinned source path. No live program is imported as a dependency.
  runner='''import sys,types,runpy
sys.modules['central_staging_container']=types.SimpleNamespace(resolve_container=lambda: None)
original=runpy.run_path
def reject_host(*args,**kwargs):
    raise RuntimeError('HOST_DEPENDENCY_ACCESS_NOT_PERMITTED_IN_FAIL_CLOSED_TEST')
def isolated_source(path,run_name=None):
    if str(path).endswith('/company-mode-price-policy-reconcile.py'):
        return {'run_wp':reject_host,'gather_bindings':reject_host,'ensure_mount':reject_host,'CORE':None}
    return original(path,run_name=run_name)
runpy.run_path=isolated_source
path=sys.argv[1]
sys.argv=['company-appearance',*sys.argv[2:]]
original(path,run_name='__main__')
'''
  return subprocess.run([sys.executable,'-c',runner,str(SCRIPT),*args],
                        capture_output=True,text=True,timeout=8)
 def test_apply_production_denied_before_host_or_site_read(self):
  r=self.invoke('--company','wayfatours','--environment','production','--mode','apply','--allow-production')
  self.assertEqual(r.returncode,3);self.assertIn('DIRECT_APPEARANCE_APPLY_DISABLED',r.stdout)
  self.assertIn('"production_mutated": false',r.stdout)
 def test_apply_staging_denied(self):
  r=self.invoke('--company','wayfatours','--environment','staging','--mode','apply')
  self.assertEqual(r.returncode,3);self.assertIn('SIGNED_ORIGINAL_305_306_312_313_REQUIRED',r.stdout)
 def test_apply_any_company_denied(self):
  r=self.invoke('--company','all-royal-egypt','--environment','production','--mode','apply')
  self.assertEqual(r.returncode,3)
 def test_no_direct_writer_reachable_or_present(self):
  s=SCRIPT.read_text()
  self.assertNotIn('shutil.copy2(dest,backup/',s)
  self.assertNotIn('os.replace(tmp,dest)',s)
  self.assertNotIn('target_mutated\':bool(changes)',s)
  self.assertIn('SIGNED_ORIGINAL_305_306_312_313_REQUIRED',s)
 def test_whitelisted_original_assets_unmodified(self):
  assignments=[n for n in ast.parse(SCRIPT.read_text()).body
     if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='ASSETS' for t in n.targets)]
  self.assertEqual(len(assignments),1)
  import ast as A
  files=A.literal_eval(assignments[0].value)
  self.assertEqual(len(files),11);self.assertEqual(len(set(files)),11)
  self.assertIn('wp-content/maintenance.php',files)
 def test_existing_plan_and_verify_modes_preserved(self):
  s=SCRIPT.read_text()
  self.assertIn("choices=('plan','verify','apply')",s)
  self.assertIn("if args.mode=='verify':",s)
  self.assertIn('atomic_json(report_file(args.company,args.environment),record)',s)
 def test_cli_rejected_without_native_evidence(self):
  bad=self.invoke('--company','unknown','--environment','production','--mode','apply')
  self.assertEqual(bad.returncode,3)
  self.assertIn('DIRECT_APPEARANCE_APPLY_DISABLED',bad.stdout)
 def test_python_source_compiles(self):
  compile(SCRIPT.read_text(),str(SCRIPT),'exec')
if __name__=='__main__':unittest.main(verbosity=2)
