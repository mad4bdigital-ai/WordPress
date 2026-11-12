"""Source-contract tests for the read-only production CAS postimage forecast."""
from __future__ import annotations
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'ops/dynamic-operation-registry-v1/executor-time-cas-v1/forecast_wordpress_postimage.php'
OBSERVER=ROOT/'ops/dynamic-operation-registry-v1/integrations/reviewed-source/verify-current-release-noop-v2.py'

class ForecastSecurity(unittest.TestCase):
 def test_readonly_forecast_rejects_non_admin(self):
  source=SCRIPT.read_text()
  self.assertIn("current_user_can('manage_options')",source)
  self.assertIn("defined('WP_CLI')",source)
  self.assertIn("SOURCE_OWNED_NONCOMMERCIAL_RESOURCE_SCOPE_DENIED",source)

 def test_forecast_reuses_canonical_evidence(self):
  source=SCRIPT.read_text()
  self.assertIn("MAD4B_Company_Exact_Resource_Evidence_V1::inspect(",source)
  self.assertIn("PREDICTOR_DOES_NOT_MATCH_CANONICAL_SOURCE_HASH",source)
  self.assertIn("'metadata_and_terms_sha256'=>$hash([$meta,$terms])",source)
  self.assertIn("'taxonomy_term_ids'=>$terms",source)

 def test_forecast_never_persists(self):
  source=SCRIPT.read_text()
  forbidden=('update_post_meta(', 'update_option(', 'add_option(',
             'INSERT INTO ', 'DELETE FROM ', 'UPDATE wp_', 'START TRANSACTION',
             'wp_insert_post(', 'wp_update_post(', 'wpdb->query(',
             'sodium_crypto_sign_detached(', 'wp_remote_post(')
  for token in forbidden:
   with self.subTest(token=token):
    self.assertNotIn(token,source)
  self.assertIn("'mutation_performed'=>false",source)
  self.assertIn("'original_305_job_issued'=>false",source)
  self.assertIn("'original_313_signed'=>false",source)

 def test_exact_meta_row_and_digest_requirements(self):
  source=SCRIPT.read_text()
  self.assertIn("count($metaRows)!==1",source)
  self.assertIn("count($raw[$key])!==1",source)
  self.assertIn("'old_meta_value_sha256'=>hash('sha256'",source)
  self.assertIn("'expected_after_sha256'=>$forecast",source)
  self.assertIn("'expected_company_preservation_sha256'=>$before['company_preservation_sha256']",source)

 def test_source_observer_drift_is_review_not_signoff(self):
  source=OBSERVER.read_text()
  self.assertIn("postimage_confirmed=plan['changed_count']==0",source)
  self.assertIn("if postimage_confirmed:",source)
  self.assertIn("m.confirm_plan(plan,policy,expect_preimage=False)",source)
  self.assertIn("RELEASE_REVIEW_REQUIRED",source)
  self.assertIn("'candidate_registered_or_signed':False",source)
  self.assertIn("'original_plan_confirmed_as_safe':False",source)

if __name__=='__main__':
 unittest.main()
