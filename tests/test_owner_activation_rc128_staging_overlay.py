"""Verified narrow Staging compatibility; retained owner gate evidence."""
from pathlib import Path
import hashlib
import json
import unittest
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/owner-activation-rc128-staging-v1'
class OwnerActivationStaging(unittest.TestCase):
 def test_exact_allowlist_and_no_authority(self):
  m=json.loads((BASE/'manifest.json').read_text())
  self.assertEqual(m['contract'],'mad4b.company-owner-release-compatibility-source-review.v1')
  self.assertEqual(m['site'],'tours.dev.mad4b.com')
  self.assertEqual(m['environment'],'staging')
  self.assertEqual(m['owner_plugin'],'mad4b-company-portfolio-manager')
  self.assertEqual(m['allowed_releases'],['0.4.0-rc.109','0.4.0-rc.128'])
  self.assertTrue(m['browser_owner_nonce_required'])
  self.assertFalse(m['role_migration_executed'])
  self.assertFalse(m['production_authorized'])
  self.assertRegex(m['preimage_sha256'],r'^[a-f0-9]{64}$')
  self.assertRegex(m['patched_sha256'],r'^[a-f0-9]{64}$')
  self.assertNotEqual(m['preimage_sha256'],m['patched_sha256'])
 def test_canonical_schema_exact(self):
  p=ROOT/'wp-content/plugins/mad4b-site-control-plane/data/company-communication-page-schema.json'
  self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),
                   '6fc45de0212aa7a2e4caf6624e578ff1491e1149d44c476a66ced6971011c38b')
  d=json.loads(p.read_text())
  self.assertEqual(len(d['fields']),24)
  self.assertEqual(d['general_settings']['slug'],'company-communication-operations')
 def test_patch_preserves_browser_approvals(self):
  text=(BASE/'owner-release-compatibility.patch').read_text()
  self.assertEqual(text.count('@@'),2)
  self.assertIn('0.4.0-rc.109',text)
  self.assertIn('0.4.0-rc.128',text)
  self.assertIn('unexpected_release_version',text)
  for forbidden in ('browserOwnerAuthorized()', 'wp_verify_nonce(', 'get_current_user_id()',
                    'applyOwnerApprovedMigration()', 'update_option('):
   self.assertNotIn(forbidden,text)
if __name__=='__main__':unittest.main()
