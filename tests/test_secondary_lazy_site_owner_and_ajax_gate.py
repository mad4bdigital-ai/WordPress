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
   self.assertEqual(hashlib.sha256((ROOT/value['relative_path']).read_bytes()).hexdigest(),value['sha256'])
  self.assertNotEqual(m['sites']['tours_dev']['sha256'],m['sites']['wayfatours']['sha256'])

 def test_current_source_fail_closed_on_content_css_and_market_drift(self):
  m=json.loads(META.read_text())
  source=(ROOT/m['sites']['tours_dev']['relative_path']).read_text()
  for marker in (
    'function mad4b_secondary_grid_lazy_ajax_parity_proven(',
    'signed_ajax_verified',
    'semantic_output_equivalent',
    "'ssr_items'",
    "'ajax_items'",
    'max_cls',
    'ajax_parity_by_market',
    "mad4b_render_query_data_generation",
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
