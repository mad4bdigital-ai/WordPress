#!/usr/bin/env python3
"""Source/Wayfa edge cohort and FastCGI non-leak static invariants."""
import unittest,re,pathlib
P=pathlib.Path(__file__).resolve().parents[1]/'edge-canary'
class TestCanaryIsolation(unittest.TestCase):
 def test_both_sites_have_no_client_header_or_cookie_selector(self):
  for site in ('source','wayfa'):
   with self.subTest(site=site):
    s=(P/(site+'.candidate.conf')).read_text()
    self.assertEqual(s.count('split_clients "$request_id|mad4b-css-v2-canary-20261121"'),1)
    self.assertIn('1% canary;',s)
    c=s[s.index('split_clients "'):s.index('# END MAD4B EDGE CANARY CACHE SEPARATION v1')]
    self.assertNotIn('$http_',c)
    self.assertNotIn('$cookie_',c)
    self.assertIn('MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort',s)
    self.assertIn('MAD4B_CSS_ROLLOUT_ORIGIN_ATTESTED $mad4b_css_rollout_origin_attested_v1',s)
    self.assertIn('canary "private, no-store, max-age=0";',s)
    self.assertIn('add_header Cache-Control $mad4b_css_browser_policy always;',s)
 def test_source_all_fastcgi_html_paths_are_isolated(self):
  s=(P/'source.candidate.conf').read_text()
  self.assertEqual(s.count('fastcgi_cache_bypass $company_perf_skip $mad4b_css_rollout_cohort;'),5)
  self.assertEqual(s.count('fastcgi_no_cache $company_perf_skip $mad4b_css_rollout_cohort'),5)
  self.assertEqual(s.count('add_header Cache-Control $mad4b_css_browser_policy always;'),4)
  self.assertEqual(s.count('fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'),8)
 def test_wayfa_all_fastcgi_html_paths_are_isolated(self):
  s=(P/'wayfa.candidate.conf').read_text()
  self.assertEqual(s.count('fastcgi_cache_bypass $wayfa_html_no_cache_path $wayfa_html_no_cache_request $mad4b_css_rollout_cohort;'),1)
  self.assertEqual(s.count('fastcgi_no_cache $wayfa_html_no_cache_path $wayfa_html_no_cache_request $mad4b_css_rollout_cohort'),1)
  self.assertEqual(s.count('fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'),1)
 def test_no_public_css_certificate_or_api_authority_granted(self):
  for site in ('source','wayfa'):
   s=(P/(site+'.candidate.conf')).read_text()
   self.assertNotIn('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED',s)
   self.assertNotIn('WP_CONTENT_DIR',s)
   self.assertNotIn('released_pending_qa',s)
 def test_source_readonly_paths_preserved(self):
  s=(P/'source.candidate.conf').read_text()
  self.assertIn('location @mad4b_general_public_html {',s)
  self.assertIn('fastcgi_param MAD4B_IP_MARKET_CACHE_GATE "v1";',s)
 def test_wayfa_auth_cookie_market_eligibility_preserved(self):
  s=(P/'wayfa.candidate.conf').read_text()
  self.assertIn('canary|mobile|0|0',s)
  self.assertIn('wayfa_html_no_cache_request',s)
  self.assertIn('wayfa_html_no_cache_path',s)
if __name__=='__main__':unittest.main(verbosity=2)
