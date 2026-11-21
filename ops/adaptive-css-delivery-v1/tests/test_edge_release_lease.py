"""Fail-closed source-bound edge release admission.

A request split is not a release license: if no first-party candidate is
explicitly authorized, effective canary is 0% even when split_clients 1%.
"""
import unittest,pathlib,re,sys
P=pathlib.Path(__file__).resolve().parents[1]/'edge-canary'
import importlib.util
spec=importlib.util.spec_from_file_location('edge',str(P.parent/'ops/verify_cache_cohort.py'))
edge=importlib.util.module_from_spec(spec);spec.loader.exec_module(edge)
class TestEdgeReleaseLease(unittest.TestCase):
 def test_production_lease_is_disabled(self):
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    s=(P/(site+'.lease0.candidate.conf')).read_text()
    self.assertEqual(s.count('map $host $mad4b_css_candidate_release_lease_v1 {'),1)
    self.assertEqual(s.count('default 0;\n}\nsplit_clients'),1)
    self.assertIn('$mad4b_css_candidate_release_lease_v1|$mad4b_css_split_seed',s)
    self.assertNotIn('default 1;\n}\nsplit_clients',s)
    self.assertIn('1% canary;',s)
    self.assertIn('private, no-store, max-age=0',s)
    self.assertIn('fastcgi_cache_bypass ',s)
    self.assertIn('fastcgi_no_cache ',s)
 def test_only_deliberately_modified_test_clone_can_select(self):
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    live=(P/(site+'.lease0.candidate.conf')).read_text()
    test=(P/(site+'.lease1.test-only.conf')).read_text()
    self.assertEqual(live.count('default 0;\n}\nsplit_clients'),1)
    self.assertEqual(test.count('default 1;\n}\nsplit_clients'),1)
    self.assertEqual(live.replace('default 0;\n}\nsplit_clients',
                                  'default 1;\n}\nsplit_clients'),test)
 def test_read_only_scanner_distinguishes_isolation_from_release(self):
  for site,host in [('source','tours.dev.mad4b.com'),('wayfa','wayfatours.com')]:
   with self.subTest(site=site):
    live=edge.verify(site,(P/(site+'.lease0.candidate.conf')).read_text(),host)
    self.assertTrue(live['canary_public_delivery_admitted'])
    self.assertIs(live['source_candidate_release_lease_active'],False)
    self.assertIs(live['source_candidate_edge_exposure_enabled'],False)
    test=edge.verify(site,(P/(site+'.lease1.test-only.conf')).read_text(),host)
    self.assertIs(test['source_candidate_release_lease_active'],True)
    self.assertIs(test['source_candidate_edge_exposure_enabled'],True)
    self.assertIs(test['source_candidate_css_published_proven'],False)
 def test_default_nginx_release_never_uses_http_control(self):
  for site in ('source','wayfa'):
   s=(P/(site+'.lease0.candidate.conf')).read_text()
   a=s.index('# Fail-closed release lease')
   b=s.index('# END MAD4B EDGE CANARY CACHE SEPARATION v1')
   cohort=s[a:b]
   self.assertNotIn('$http_',cohort)
   self.assertNotIn('$cookie_',cohort)
   self.assertNotIn('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED',s)
if __name__=='__main__':unittest.main(verbosity=2)
