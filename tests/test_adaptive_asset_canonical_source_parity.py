"""Read-only canonical source ownership and tenant drift contract."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT=Path(__file__).resolve().parents[1]
META=ROOT/'ops/site-company-overlays/wayfatours/adaptive-runtime/source-parity-20261116.json'

class AdaptiveSourceOwnership(unittest.TestCase):
 def test_canonical_master_source_and_wayfa_drift_not_silently_promoted(self):
  m=json.loads(META.read_text())
  self.assertEqual(m['contract'],'mad4b.adaptive-asset-site-source-reconciliation-readonly.v1')
  self.assertTrue(m['source_files_match_live_master'])
  self.assertEqual(m['observed_target_drift_count'],2)
  self.assertFalse(m['target_company_runtime_replaced'])
  self.assertFalse(m['target_company_release_authorized'])
  self.assertFalse(m['company_override_accepted_without_review'])
  self.assertTrue(m['legacy_shim_kept'])
  for row in m['files']:
   path=ROOT/row['relative_path']
   self.assertTrue(path.is_file())
   self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),row['canonical_source_sha256'])
   self.assertEqual(row['tours_dev_observed_sha256'],row['canonical_source_sha256'])
   self.assertFalse(row['auto_promote_to_wayfatours'])
  self.assertEqual(sum(row['wayfatours_parity']=='exact' for row in m['files']),2)

 def test_adaptive_regression_retains_home_dependency_and_source_gates(self):
  src=(ROOT/'wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php').read_text()
  for needle in ('epochBumpedThisRequest','home_impact_preview','page_on_front',
                 'elementor_library','queue_refresh','source_package_updated',
                 'site_uuid','brand_id','return [\'queued\'=>true,'):
   self.assertIn(needle,src)
  for blocked in ('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-asset-intelligence.php',):
   bridge=(ROOT/blocked).read_text()
   self.assertIn('Backwards compatibility bridge',bridge)
   self.assertIn('require_once $real',bridge)
   self.assertNotIn('final class MAD4B_SCP_Adaptive_Asset_Intelligence',bridge)

if __name__=='__main__':unittest.main()
