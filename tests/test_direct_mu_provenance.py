import copy,json,importlib.util,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
MODULE=ROOT/'tools/verify_direct_mu_source.py'
s=importlib.util.spec_from_file_location('direct_mu_proof',MODULE)
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
M=json.loads(m.DOC.read_text())
class MUProvenance(unittest.TestCase):
 def test_exact_direct_mu_source_and_no_auto_activation(self):
  r=m.verify(M)
  self.assertEqual(r['source_php_files_verified'],162)
  self.assertEqual(r['existing_conflicts_held'],3)
  self.assertFalse(r['release_authorized'])
 def test_untrusted_grant_claim_fails(self):
  d=copy.deepcopy(M);d['automatic_mu_activation_authorized']=True
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_digest_change_fails(self):
  d=copy.deepcopy(M);d['files'][0]['sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_nested_file_cannot_be_launched_as_direct(self):
  d=copy.deepcopy(M);d['files'][0]['path']='wp-content/mu-plugins/untrusted/loader.php'
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_site_media_differences_are_not_overwritten(self):
  expected={'mad4b-media-composite-integrity.php','mad4b-tour-media-reconcile.php','mad4b-unified-media-apply.php'}
  self.assertEqual(set(M['preexisting_different_files']),expected)
if __name__=='__main__':unittest.main()
