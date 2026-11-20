"""Exact company file ownership and Original305 observer *source* evidence.

Does not connect to production or test issuance of a signed release.
"""
from pathlib import Path
import hashlib,json,unittest

ROOT=Path(__file__).resolve().parents[1]
SNAP=ROOT/'ops/site-company-overlays/wayfatours/live-source-mirror-20261116.json'
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()

class SiteOwnedSourceParity(unittest.TestCase):
 def test_exact_site_owned_source_mirrors_have_independent_hashes(self):
  doc=json.loads(SNAP.read_text())
  self.assertEqual(doc['contract'],'mad4b.company-owned-source-and-original305-diagnostics-parity.v1')
  self.assertFalse(doc['site_write_performed'])
  self.assertFalse(doc['site_release_authorized'])
  self.assertFalse(doc['signed_master_incident_review_received'])
  self.assertFalse(doc['origin_305_signer_invoked'])
  self.assertFalse(doc['origin_305_hold_deleted'])
  for name,row in doc['sources'].items():
   self.assertEqual(sha(ROOT/row['relative_path']),row['sha256'],name)
   self.assertTrue(row['matched_runtime_source_readback'])
  self.assertNotEqual(doc['sources']['Tours.dev/current-media-owner']['sha256'],
                      doc['sources']['Wayfatours/media-owner-overlay']['sha256'])

 def test_observer_is_only_diagnostic_not_signed_host_lease(self):
  doc=json.loads(SNAP.read_text())
  source=(ROOT/doc['sources']['Original305/read-only-observer']['relative_path']).read_text()
  self.assertIn("OPTION='mad4b_original305_runtime_observation_v1'",source)
  self.assertIn("'host_release_authorized':False",source)
  self.assertIn("'approval_created':False",source)
  self.assertIn("'incident_closed':False",source)
  self.assertNotIn('original305_signed_refresh_called\':True',source)

 def test_wayfa_smtp_receipt_is_not_inbox_delivery(self):
  doc=json.loads(SNAP.read_text())
  source=(ROOT/doc['sources']['Wayfatours/notice-provider-overlay']['relative_path']).read_text()
  self.assertIn('does_not_prove_inbox_delivery',source)
  self.assertIn('last_accepted_utc',source)
  self.assertIn("wp_mail_failed",source)
  self.assertIn('independent_live_option_readback',source)

if __name__=='__main__':unittest.main()
