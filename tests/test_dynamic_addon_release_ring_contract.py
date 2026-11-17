#!/usr/bin/env python3
"""Read-only source invariants; server environment is never a release grant."""
from pathlib import Path
import json
import unittest

ROOT=Path(__file__).resolve().parents[1]
CP=ROOT/'wp-content/plugins/mad4b-site-control-plane'
class DynamicRingPolicy(unittest.TestCase):
 def test_catalog(self):
  data=json.loads((CP/'config/wordpress-addon-catalog.json').read_text())
  self.assertEqual(data['contract'],'mad4b.wordpress-addon-catalog.v1')
  self.assertFalse(data['defaults']['production_authorized'])
  self.assertEqual(len(data['addons']),1)
  addon=data['addons'][0]
  self.assertEqual(addon['release_ring'],'dynamic')
  self.assertEqual(addon['release_policy'],{
   'mode':'site_bound_certified_pair',
   'requires_enrolled_site_profile':True,
   'unknown_pair_fallback':'shadow',
   'production_authorized':False,
  })
  self.assertEqual(addon['certified_pairs'],[])
  self.assertFalse(addon['authority_impact']['inherits_production_authority'])
  self.assertTrue(addon['certification']['exact_provider_version_required'])
 def test_runtime_exact_binding(self):
  source=(CP/'includes/class-mad4b-scp-addon-registry.php').read_text()
  for item in (
    "'dynamic' === (string) $manifest['release_ring']",
    'site_bound_certified_pair','site_uuid','site_profile_digest',
    'MAD4B_SCP_Site_Profile::origin_enrolled()',
    'MAD4B_SCP_Site_Profile::site_urls_match_enrollment()',
    'hash_equals( $site_uuid, $pair_site )',
    'hash_equals( $site_profile_digest, $pair_profile_digest )',
    'mad4b_addon_release_ring_not_active',
    "'effective_release_ring' => $effective_ring",
    "'production_authorized' => false",
  ):
   self.assertIn(item,source)
 def test_audit_contract(self):
  s=(ROOT/'tools/mad4b_pre_staging_hybrid_audit.py').read_text()
  for item in (
   'ring == "dynamic"','site_bound_certified_pair',
   '"requires_enrolled_site_profile": True',
   '"unknown_pair_fallback": "shadow"',
   '"production_authorized": False',
   'dynamic_release_policy_invalid',
   'dynamic_exact_pair_binding_invalid',
  ):self.assertIn(item,s)
 def test_runtime_negative_coverage(self):
  s=(CP/'tests/wordpress-addon-execution-guard-contract.php').read_text()
  for item in (
   "MAD4B_SCP_Site_Profile::$environment = 'production'",
   "MAD4B_SCP_Site_Profile::$uuid = '11111111-2222-3333-4444-555555555555'",
   'MAD4B_SCP_Site_Profile::$enrolled = false',
   'MAD4B_SCP_Site_Profile::$origin_matches = false',
   "MAD4B_SCP_Site_Profile::$digest = str_repeat( 'b', 64 )",
   'mad4b_addon_pair_fingerprint_drift',
   'mad4b_addon_pair_not_certified',
   'mad4b_addon_dynamic_release_policy_invalid',
   'mad4b_addon_release_ring_invalid',
  ):self.assertIn(item,s)
if __name__=='__main__':unittest.main()
