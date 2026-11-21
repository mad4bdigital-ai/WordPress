import unittest,pathlib,sys
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'ops'))
from verify_cache_cohort import verify
class TestCacheCohort(unittest.TestCase):
 def test_no_cohort_is_hold(self):
  x='fastcgi_cache_key "$request_uri"; fastcgi_cache_bypass $private; fastcgi_no_cache $private;'
  self.assertFalse(verify('wayfa',x,'wayfatours.com')['canary_public_delivery_admitted'])
 def test_key_and_attestation(self):
  x='''map $request_method $mad4b_css_rollout_origin_attested_v1 {default 0;}
    fastcgi_cache_key "$mad4b_css_rollout_cohort|$request_uri";
    fastcgi_cache_bypass $private; fastcgi_no_cache $private;
    fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'''
  self.assertTrue(verify('source',x,'tours.dev.mad4b.com')['canary_public_delivery_admitted'])
 def test_bypass_both_and_attestation(self):
  x='''map $request_method $mad4b_css_rollout_origin_attested_v1 {default 0;}
    fastcgi_cache_key "$request_uri";
    fastcgi_cache_bypass $mad4b_css_rollout_cohort;
    fastcgi_no_cache $mad4b_css_rollout_cohort;
    fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'''
  self.assertTrue(verify('wayfa',x,'wayfatours.com')['canary_public_delivery_admitted'])
 def test_missing_no_cache_is_hold(self):
  x='''map $request_method $mad4b_css_rollout_origin_attested_v1 {default 0;}
    fastcgi_cache_key "$request_uri";
    fastcgi_cache_bypass $mad4b_css_rollout_cohort;
    fastcgi_no_cache $private;
    fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'''
  self.assertFalse(verify('wayfa',x,'wayfatours.com')['canary_public_delivery_admitted'])
 def test_no_attestation_is_hold(self):
  x='''fastcgi_cache_key "$mad4b_css_rollout_cohort|$request_uri";
    fastcgi_cache_bypass $private; fastcgi_no_cache $private;
    fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;'''
  self.assertFalse(verify('source',x,'tours.dev.mad4b.com')['canary_public_delivery_admitted'])
 def test_comment_cannot_grant(self):
  x='''# fastcgi_param MAD4B_CSS_ROLLOUT_COHORT $mad4b_css_rollout_cohort;
    fastcgi_cache_key "$request_uri";
    fastcgi_cache_bypass $private; fastcgi_no_cache $private;'''
  self.assertFalse(verify('source',x,'tours.dev.mad4b.com')['canary_public_delivery_admitted'])
if __name__=='__main__':unittest.main(verbosity=2)
