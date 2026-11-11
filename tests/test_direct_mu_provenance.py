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
  self.assertEqual(r['source_php_files_verified'],164)
  self.assertEqual(r['existing_conflicts_held'],3)
  self.assertFalse(r['release_authorized'])
 def test_untrusted_grant_claim_fails(self):
  d=copy.deepcopy(M);d['automatic_mu_activation_authorized']=True
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_exact_three_reviewed_semantic_patches_only(self):
  d=copy.deepcopy(M)
  self.assertEqual(m.verify(d)['semantic_overlays_verified'],3)
  o=d['approved_candidate_overlays'][1]
  self.assertEqual(o['path'],'wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php')
  d['approved_candidate_overlays'].append(copy.deepcopy(o))
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_form6_overlay_unauthorized_payment_claim_is_blocked(self):
  d=copy.deepcopy(M)
  overlay=next(o for o in d['approved_candidate_overlays']
               if o['path'].endswith('/mad4b-tour-form6-submission-mode-v1.php'))
  overlay['payment_authority_granted']=True
  with self.assertRaises(m.Blocked):m.verify(d)
  d=copy.deepcopy(M)
  overlay=next(o for o in d['approved_candidate_overlays']
               if o['path'].endswith('/mad4b-tour-form6-submission-mode-v1.php'))
  overlay['candidate_sha256']='0'*64
  with self.assertRaises(m.Blocked):m.verify(d)
 def test_email_overlay_authority_or_digest_cannot_be_modified(self):
  d=copy.deepcopy(M)
  d['approved_candidate_overlays'][1]['payment_authority_granted']=True
  with self.assertRaises(m.Blocked):m.verify(d)
  d=copy.deepcopy(M)
  d['approved_candidate_overlays'][1]['candidate_sha256']='0'*64
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
