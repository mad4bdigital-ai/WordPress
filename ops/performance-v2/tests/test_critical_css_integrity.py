"""Native read-only Critical CSS source/tenant pin integrity checks."""
import copy
import importlib.util
import json
import sys
import unittest
from pathlib import Path

root=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(root))
installed=Path("/opt/mad4b-company-provisioning/bin/performance-v2")
if installed.is_dir():
 sys.path.insert(0,str(installed))
else:
 # Minimal import-only fallback for CI fixtures without the owned VPS app.
 # Keep source planner's established private helpers available to other tests.
 from types import ModuleType
 if 'performance_http_readback_v2' not in sys.modules:
  module=ModuleType('performance_http_readback_v2')
  module.audit=lambda *_args,**_kwargs: {}
  sys.modules['performance_http_readback_v2']=module
 if 'cache_coherence_intelligence_v3' not in sys.modules:
  module=ModuleType('cache_coherence_intelligence_v3')
  def unavailable(*_args,**_kwargs):
   raise RuntimeError("NOT_A_LIVE_RUNTIME")
  module.diagnose=unavailable
  module._runtime_owner=unavailable
  module._manifest=unavailable
  module._wp_option=unavailable
  sys.modules['cache_coherence_intelligence_v3']=module
spec=importlib.util.spec_from_file_location('critical_css_monitor_test',root/'performance_monitor_v2.py')
engine=importlib.util.module_from_spec(spec)
spec.loader.exec_module(engine)

class CriticalCSSPinnedRuntime(unittest.TestCase):
 def policy(self,name):
  return json.loads((root/'policies'/f'{name}-http-policy.json').read_text())

 def test_read_only_both_sites(self):
  for site in ('source','wayfatours'):
   p=self.policy(site)
   base=Path(p['local_runtime']['site_root'])
   if not base.is_dir():
    self.skipTest('Production-only mounted source not present')
   result=engine.verified_critical_css_integrity_readback(p)
   self.assertEqual(result['state'],'passed',result)
   self.assertTrue(result['read_only'])
   self.assertFalse(result['runtime_wordpress_options_proven'])
   self.assertFalse(result['live_browser_activation_proven'])
   self.assertFalse(result['original313_signed_code_release_issued'])
   self.assertEqual(set(result['locales']),{'en','es'})

 def test_sha_drift_denies(self):
  for site in ('source','wayfatours'):
   p=self.policy(site)
   if not Path(p['local_runtime']['site_root']).is_dir():
    self.skipTest('Company is not mounted')
   bad=copy.deepcopy(p)
   bad['critical_css_integrity_v1']['consumer_sha256']='0'*64
   result=engine.verified_critical_css_integrity_readback(bad)
   self.assertEqual(result['state'],'hold')
   self.assertIn('critical_css_php_source_drift',result['reason_codes'])
   wrong=copy.deepcopy(p)
   wrong['critical_css_integrity_v1']['locales']['en']['critical_sha256']='f'*64
   result=engine.verified_critical_css_integrity_readback(wrong)
   self.assertEqual(result['state'],'hold')
   self.assertIn('critical_css_critical_sha_drift_en',result['reason_codes'])

 def test_wrong_tenant_manifest_rejected(self):
  p=self.policy('source')
  if not Path(p['local_runtime']['site_root']).is_dir():
   self.skipTest('Company is not mounted')
  p['origin']='https://untrusted.example'
  result=engine.verified_critical_css_integrity_readback(p)
  self.assertEqual(result['state'],'hold')
  self.assertIn('critical_css_manifest_tenant_or_format_mismatch',result['reason_codes'])

 def test_no_non_enrolled_probe(self):
  p=self.policy('source')
  p['critical_css_integrity_v1']['enabled']=False
  result=engine.verified_critical_css_integrity_readback(p)
  self.assertEqual(result['state'],'not_enrolled')

 def test_policy_is_only_readonly_own_sites(self):
  for site in ('source','wayfatours'):
   p=self.policy(site)
   v=p['critical_css_integrity_v1']
   self.assertEqual(set(v['locales']),{'en','es'})
   self.assertIn(v['format'],('adaptive-v2','native-v1'))
   self.assertTrue(v['enabled'])
   self.assertNotIn('release_grant',v)
   self.assertNotIn('stripe',json.dumps(v).lower())
   self.assertLess(len(json.dumps(p)),8192)

if __name__=='__main__':unittest.main()
