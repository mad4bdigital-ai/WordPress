#!/usr/bin/env python3
import hashlib,json,pathlib,shutil,subprocess,unittest
BASE=pathlib.Path(__file__).resolve().parent
SOURCE=BASE/'318-mad4b-native-host-exact-grant.php'
PROPOSAL=BASE/'release-proposal.NOT-AUTHORIZATION.json'
FIXTURE=BASE/'original318_signed_anchor_runtime.php'
class SourceCandidate(unittest.TestCase):
 def test_original_candidate_sha_and_not_authorization(self):
  doc=json.loads(PROPOSAL.read_text())
  self.assertEqual(hashlib.sha256(SOURCE.read_bytes()).hexdigest(),doc['candidate_sha256'])
  self.assertEqual(doc['candidate_sha256'],'56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be')
  self.assertEqual(doc['contract'],'mad4b.original318.v82.governed-release-proposal.v1')
  for key in ['direct_ssh_file_replacement_allowed','publisher_approval_granted','independent_receipt_signed','real_anchor_issued_or_revoked','publish_executed']:
   self.assertIs(doc[key],False)
 def test_canonical_wordpress_deploy_target_matches_reviewed_candidate(self):
  target=BASE.parents[1]/'wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php'
  self.assertTrue(target.is_file(), 'canonical WordPress deploy target must exist')
  self.assertEqual(target.read_bytes(),SOURCE.read_bytes(),
      'deploy target must exactly equal the reviewed Original318 v82 candidate')
 def test_source_requires_distinct_signed_anchor(self):
  s=SOURCE.read_text()
  for t in ["const DYNAMIC_ANCHOR_CONTRACT='mad4b.company-host-authority-anchor.v2'",
    'DYNAMIC_ANCHOR_SIGNED_FIELDS','independent_anchor_live_v2(array $proof)',
    'sodium_crypto_sign_verify_detached($signature,$message,$public)',
    'ACTIVE_INDEPENDENT_HOST_AUTHORITY','REVOKED_INDEPENDENT_HOST_AUTHORITY',
    'original_approved_host_authority_reconcile']:
   self.assertIn(t,s)
  fn=s.split('private static function dynamic_anchor(',1)[1].split('private static function dynamic_anchor_matches(',1)[0]
  self.assertNotIn('add_option(',fn)
  self.assertNotIn('update_option(',fn)
 def test_revocation_non_authorizing(self):
  fn=SOURCE.read_text().split('public static function independent_revoked_anchor_observation_v2(',1)[1].split('private static function dynamic_anchor_matches(',1)[0]
  for k in ["'write_authorized'=>false","'original313_certified'=>false","'independent_highwater_verified'=>false","'grant_created'=>false"]:
   self.assertIn(k,fn)
  self.assertNotIn('update_option(',fn)
  self.assertNotIn('add_option(',fn)
 def test_legacy_canary_unchanged(self):
  s=SOURCE.read_text()
  for q in ['public static function issue(array $x)','public static function complete(array $x)','self::identity()']:
   self.assertIn(q,s)
 def test_php_lint_if_available(self):
  if not shutil.which('php'):self.skipTest('php CLI unavailable')
  p=subprocess.run(['php','-l',str(SOURCE)],capture_output=True,text=True,timeout=12)
  self.assertEqual(p.returncode,0,p.stdout+p.stderr)
 def test_ephemeral_signed_crypto_if_php_available(self):
  if not shutil.which('php'):self.skipTest('php CLI unavailable')
  p=subprocess.run(['php','-d','zend.assertions=1','-d','assert.exception=1',str(FIXTURE)],
      cwd=str(BASE),capture_output=True,text=True,timeout=18)
  self.assertEqual(p.returncode,0,p.stdout+p.stderr)
  self.assertIn('"signed_anchor_verified":true',p.stdout)
  self.assertIn('"write_granted":false',p.stdout)
  self.assertIn('"production_site_mutated":false',p.stdout)
  self.assertIn('"signed_revoke_readback_shape_pass":true',p.stdout)
if __name__=='__main__':unittest.main(verbosity=2)
