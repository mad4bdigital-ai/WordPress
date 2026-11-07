#!/usr/bin/env python3
"""Portable offline regression checks for MAD4B runtime package."""
import hashlib,json,re,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
class ReleaseTests(unittest.TestCase):
 def test_manifest_and_source_sha(self):
  m=json.loads((ROOT/'RELEASE-MANIFEST.json').read_text())
  self.assertEqual(m['required_runtime_map_entries'],377)
  self.assertEqual(m['ttl_seconds'],1800)
  self.assertEqual(m['public_control_deferred_module_count'],14)
  self.assertEqual(hashlib.sha256((ROOT/'overlays/mu-plugins/00-0-mad4b-runtime-router.php').read_bytes()).hexdigest(),m['runtime_router_sha256'])
  for name,digest in m['public_modules_sha256'].items():
   p=ROOT/'overlays/mu-plugins'/name
   self.assertTrue(p.is_file())
   self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),digest)
  for name,digest in m['runtime_control_sha256'].items():
   p=ROOT/'overlays/mad4b-platform/modules/compat'/name
   self.assertTrue(p.is_file())
   self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),digest)
 def test_source_private_and_cookie_contract(self):
  s=(ROOT/'overlays/mu-plugins/417-mad4b-public-cookie-contract.php').read_text()
  self.assertIn('safe_marketing_cookie',s)
  self.assertIn('wp-wpml_current_language',s)
  self.assertIn("self::telemetry_only($raw) || self::safe_marketing_cookie($raw)",s)
  s=(ROOT/'overlays/mu-plugins/421-mad4b-favourites-element-cache-isolation.php').read_text()
  self.assertIn("is_page()",s)
  self.assertIn("'pre_option_elementor_element_cache_ttl'",s)
 def test_control_lane_preserves_authority(self):
  for name in ('305-mad4b-central-ssh-command-intake.php','306-mad4b-central-vps-broker.php'):
   s=(ROOT/'overlays/mad4b-platform/modules/compat'/name).read_text()
   self.assertIn('MAD4B_MU_MODULAR_LOADER_BOOTED',s)
   self.assertIn('MAD4B_MU_File_Resolver_V1::path',s)
  b=(ROOT/'overlays/mu-plugins/00-0-mad4b-runtime-router.php').read_text()
  self.assertIn("MAD4B_MU_Map_Contract_V1::current",b)
  self.assertIn('$mad4b_public_control_slice',b)
  self.assertEqual(len(re.findall(r"^\s*'[0-9]{3}-[^']+\.php'=>true,$",b,re.M)),14)
 def test_no_sensitive_fixtures(self):
  for f in ROOT.rglob('*'):
   if not f.is_file() or f.suffix not in ('.php','.py','.json','.md','.service','.timer','.path'):continue
   s=f.read_text(errors='replace')
   self.assertNotRegex(s,'-----BEGIN (?:OPENSSH|RSA|PRIVATE) KEY-----')
 def test_auto_enroll_deny_by_default(self):
  s=(ROOT/'bin/company-cache-auto-enroll-v2.py').read_text()
  for check in ('MOUNT_UNTRUSTED','PROJECT_ID_INVALID','ALREADY_LINKED_ROOT','MODULE_PIN_MISMATCH','CONTAINER_NOT_FOUND'):
   self.assertIn(check,s)
if __name__=='__main__':unittest.main()
