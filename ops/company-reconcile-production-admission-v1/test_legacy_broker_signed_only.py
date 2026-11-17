#!/usr/bin/env python3
"""Hermetic fail-closed old Broker: only Original signed native may mutate.
Runs the real CLI directly; no Host-only import or mock required.
"""
from __future__ import annotations
import ast,hashlib,pathlib,subprocess,sys,unittest
SCRIPT=pathlib.Path(__file__).with_name('company-system-appearance-broker-reconcile.py')
class Tests(unittest.TestCase):
 def invoke(self,*args):
  return subprocess.run([sys.executable,str(SCRIPT),*args],
                        capture_output=True,text=True,timeout=8)
 def test_apply_production_denied_before_host_or_site_read(self):
  r=self.invoke('--company','wayfatours','--environment','production','--mode','apply','--allow-production')
  self.assertEqual(r.returncode,3);self.assertIn('DIRECT_APPEARANCE_APPLY_DISABLED',r.stdout)
  self.assertIn('"production_mutated": false',r.stdout)
 def test_untrusted_host_dependency_not_imported_on_apply(self):
  r=self.invoke('--company','wayfatours','--environment','production','--mode=apply')
  self.assertEqual(r.returncode,3)
  self.assertNotIn('ModuleNotFoundError',r.stderr)
  self.assertIn('DIRECT_APPEARANCE_APPLY_DISABLED',r.stdout)
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
