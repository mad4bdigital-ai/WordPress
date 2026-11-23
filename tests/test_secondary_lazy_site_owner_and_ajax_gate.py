"""Bound browser-evidenced lazy rendering to exact company-owned runtime source."""
import hashlib,json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
META=ROOT/'ops/site-company-overlays/wayfatours/performance-runtime/secondary-native-lazy-parity-manifest-20261116.json'

class NativeLazyOwner(unittest.TestCase):
 def test_company_source_sha_isolated(self):
  m=json.loads(META.read_text())
  self.assertEqual(m['contract'],'mad4b.site-owned-secondary-listing-lazy-runtime-sha.v1')
  self.assertFalse(m['auto_promote_from_master'])
  self.assertFalse(m['site_write_performed'])
  self.assertFalse(m['signed_release_authorized'])
  self.assertTrue(m['public_lazy_missing_ajax_proof_defaults_to_full_ssr'])
  for value in m['sites'].values():
   self.assertTrue(value['matched_live_source'])
  # Preserve historical live observations: subsequent source revisions must
  # have an independent candidate record, never silently replace a receipt.
  newer=json.loads((META.parent/'secondary-native-lazy-source-forward-port-20261116.json').read_text())
  self.assertEqual(newer['contract'],'mad4b.secondary-lazy-source-forward-port.v1')
  self.assertEqual(newer['historical_tours_dev_sha256'],m['sites']['tours_dev']['sha256'])
  for guard in ('production_authorized','live_source_reverified_after_delta','auto_promote_to_wayfatours','target_company_release_authorized'):
   self.assertFalse(newer[guard])
  self.assertEqual(newer['candidate_relative_path'],m['sites']['tours_dev']['relative_path'])
  # The forward-port receipt recorded an earlier source candidate. A later
  # reviewed lazy source revision is not allowed to rewrite that receipt.
  # Pin the original bytes in a non-executable historical fixture, verify both
  # SHA-256 and native Git blob SHA-1, then check the new source separately.
  successor=json.loads((META.parent/'secondary-native-lazy-source-successor-20261117.json').read_text())
  self.assertEqual(successor['contract'],'mad4b.secondary-lazy-source-successor.v1')
  historical=(ROOT/successor['original_candidate_fixture']).read_bytes()
  self.assertEqual(hashlib.sha256(historical).hexdigest(),newer['candidate_sha256'])
  blob=b'blob '+str(len(historical)).encode()+b'\0'+historical
  self.assertEqual(hashlib.sha1(blob).hexdigest(),newer['candidate_git_blob_sha1'])
  self.assertEqual(successor['original_candidate_sha256'],newer['candidate_sha256'])
  self.assertEqual(successor['original_candidate_git_blob_sha1'],newer['candidate_git_blob_sha1'])
  self.assertEqual(successor['current_candidate_relative_path'],newer['candidate_relative_path'])
  self.assertTrue(successor['site_source_readiness_only'])
  for guard in ('company_release_authorized','wayfatours_live_reverified','automatic_promotion_enabled'):
   self.assertFalse(successor[guard])
  self.assertEqual(hashlib.sha256((ROOT/newer['candidate_relative_path']).read_bytes()).hexdigest(),
                   successor['current_candidate_sha256'])
  self.assertNotEqual(successor['current_candidate_sha256'],newer['candidate_sha256'])
  self.assertNotEqual(newer['candidate_sha256'],m['sites']['tours_dev']['sha256'])
  company=m['sites']['wayfatours']
  # Preserve the immutable November 16 receipt in a non-executable fixture;
  # a new production release has its OWN checksum and approval workflow.
  historical=ROOT/'ops/jet-home-mobile-20261123/fixtures/wayfa-secondary-listing-lazy-20261116.original.php.txt'
  self.assertEqual(hashlib.sha256(historical.read_bytes()).hexdigest(),company['sha256'])
  receipt=json.loads((ROOT/'ops/jet-home-mobile-20261123/release.json').read_text())
  self.assertEqual(receipt['code']['before_sha256'],company['sha256'])
  self.assertEqual(receipt['code']['path'],company['relative_path'])
  self.assertEqual(hashlib.sha256((ROOT/company['relative_path']).read_bytes()).hexdigest(),
                   receipt['code']['live_after_sha256'])
  self.assertNotEqual(newer['candidate_sha256'],company['sha256'])

 def test_current_source_fail_closed_on_content_css_and_market_drift(self):
  m=json.loads(META.read_text())
  newer=json.loads((META.parent/'secondary-native-lazy-source-forward-port-20261116.json').read_text())
  self.assertFalse(newer['production_authorized'])
  self.assertFalse(newer['live_source_reverified_after_delta'])
  source=(ROOT/newer['candidate_relative_path']).read_text()
  for marker in (
    'function mad4b_secondary_grid_lazy_ajax_parity_proven(',
    'signed_ajax_verified',
    'semantic_output_equivalent',
    "'ssr_items'",
    "'ajax_items'",
    'max_cls',
    'ajax_parity_by_market',
    'mad4b_render_query_generation_for_id',
    'document_sha256',
    'tested_widths',
    'elementor',
  ):
   self.assertIn(marker.lower(),source.lower())
  verdict=json.loads((ROOT/'ops/secondary-native-lazy-parity-20261116/qa-verdict.json').read_text())
  self.assertFalse(verdict['production_rollout'])
  self.assertFalse(verdict['authorizing'])
  self.assertEqual(verdict['combined_canary']['decision'],'hold')

if __name__=='__main__':unittest.main()
