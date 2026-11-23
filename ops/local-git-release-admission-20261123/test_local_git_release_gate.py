import tempfile
import unittest
from pathlib import Path
from local_git_release_gate import inspect

class AdmissionTests(unittest.TestCase):
 def fixture(self,root:Path,site:str,missing:bool=False):
  p=root/site
  plugin=p/'wp-content/plugins/mad4b-site-control-plane' if site=='candidate' else p
  (plugin/'includes').mkdir(parents=True)
  (plugin/'includes/adapters').mkdir(parents=True)
  (plugin/'mad4b-site-control-plane.php').write_text('/*\n * Version: 0.4.0-rc.129\n */\n' if site=='candidate' else '/*\n * Version: 0.4.0-rc.128\n */\n')
  guarded={'includes/class-mad4b-scp-self-update.php':['reviewed_archive_no_regression','trusted_master_release_candidate'],
           'includes/class-mad4b-scp-authorization.php':['execution_attempt_sha256','execution_attempt_state','execution_boundary_verified','local_approval_planner_execution']}
  for f,methods in guarded.items():
   (plugin/f).write_text('<?php class Test { '+''.join('function '+x+'() {}' for x in (methods[:-1] if missing and site=='candidate' else methods))+' }')
  for f in ['includes/adapters/class-mad4b-scp-elementor-adapter.php','includes/class-mad4b-scp-staging-write-authority.php','includes/class-mad4b-scp-runtime-release-set.php']:
   (plugin/f).write_text('<?php test')
  if site=='live':
   (plugin/'MAD4B-BUILD-PROVENANCE.json').write_text('{"artifact_identity":"baseline","source_commit_sha":"sha","manifest_file_count":20,"control_plane_version":"0.4.0-rc.128"}')
  return p
 def test_accepts_identical_guarded_code_with_newer_version(self):
  with tempfile.TemporaryDirectory() as d:
   root=Path(d);r=inspect(self.fixture(root,'candidate'),self.fixture(root,'live'))
   self.assertTrue(r['preflight_pass']);self.assertFalse(r['release_authorized'])
 def test_rejects_missing_production_guard(self):
  with tempfile.TemporaryDirectory() as d:
   root=Path(d);r=inspect(self.fixture(root,'candidate',True),self.fixture(root,'live'))
   self.assertFalse(r['preflight_pass']);self.assertTrue(any('REGRESSION_FUNCTION_MISSING' in s for s in r['errors']))
 def test_final_package_requires_issuer_and_build_receipt(self):
  with tempfile.TemporaryDirectory() as d:
   root=Path(d);r=inspect(self.fixture(root,'candidate'),self.fixture(root,'live'),True)
   self.assertFalse(r['preflight_pass']);self.assertIn('SIGNED_RELEASE_ISSUER_PUBLIC_KEY_NOT_PACKAGED',r['errors'])
if __name__=='__main__':unittest.main()
