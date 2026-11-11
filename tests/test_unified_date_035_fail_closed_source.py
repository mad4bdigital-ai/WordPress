"""Active v0.3.5 uses exact-source assets plus bounded deny-all PHP review."""
from pathlib import Path
import hashlib,json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/unified-date-v035-postin-security-20261111.json').read_text())
C=json.loads((ROOT/'docs/release-coverage/mad4b-commerce-live-source-snapshot-20261111.json').read_text())
BASE='wp-content/plugins/mad4b-commerce/modules/presentation/'
PHP=BASE+'unified-date-presentation-v1.php'
JS=BASE+'assets/unified-date-v0.3.5.js'
class FailClosedDate(unittest.TestCase):
 def test_source_versions_and_reviewed_patches_are_bounded(self):
  self.assertEqual(M['contract'],'mad4b.unified-date-v0.3.5-postin-guard.v1')
  self.assertEqual(M['source_php_sha256'],
                   next(r['sha256'] for r in C['files'] if r['path']==PHP))
  self.assertEqual(M['candidate_php_sha256'],
                   hashlib.sha256((ROOT/PHP).read_bytes()).hexdigest())
  self.assertEqual(M['live_035_js_sha256'],
                   next(r['sha256'] for r in C['files'] if r['path']==JS))
  self.assertEqual(M['candidate_035_js_sha256'],
                   hashlib.sha256((ROOT/JS).read_bytes()).hexdigest())
  self.assertEqual(len(C['files']),286)
  self.assertEqual(len(C['approved_candidate_overlays']),3)
  self.assertTrue(M['explicit_prior_post_in_zero_is_deny_all'])
  self.assertFalse(M['release_authorized'])
  self.assertFalse(M['live_source_patched_by_this_action'])
 def test_all_three_date_lanes_use_native_post_in_intersection(self):
  src=(ROOT/PHP).read_text()
  self.assertEqual(src.count('function mad4b_unified_date_intersect_post_ids_v1('),1)
  self.assertEqual(src.count("=mad4b_unified_date_intersect_post_ids_v1("),3)
  self.assertIn("if(array_key_exists('post__in',$args))",src)
  self.assertIn("if($raw){",src)
  self.assertIn("if(!is_scalar($value) || !is_numeric($value)",src)
  self.assertIn("|| (int)$value<=0) return [0];",src)
  self.assertIn("if(!$existing)return [0];",src)
  self.assertIn("return $ids?:[0];",src)
  self.assertIn("'tours_query_archive'",src)
  self.assertIn("'jet-search/ajax-search/query-args'",src)
  self.assertIn("(int)($query->id??0)!==310",src)
  self.assertIn("'tour_rates_accordion'",src)
  self.assertIn('unified-date-v0.3.5.js',src)
  self.assertIn('unified-date-v0.3.5.css',src)
 def test_archive_and_rates_keep_source_identity_and_no_payment_authority(self):
  src=(ROOT/PHP).read_text()
  for required in ("shared_product_verified_presentation_tour_id()",
                   "shared_product_eligible_tour_rate_ids($tourId)",
                   "get_post_type($rateId)!=='tour-rates'",
                   "get_post_status($rateId)!=='publish'",
                   "get_post_meta($rateId,'tour_rate_start_date',true)",
                   "get_post_meta($rateId,'tour_rate_end_date',true)",
                   "mad4b_unified_date_currency_context_ajax_v1()"):
   self.assertIn(required,src)
  for absent in ('wc_create_order(', 'update_post_meta(', 'payment_intent',
                 'wp_remote_post(', 'wp_mail('):
   self.assertNotIn(absent,src)
 def test_source_exact_new_assets_and_preserved_032_and_035_clear(self):
  for p,original_sha in M['verified_source_assets'].items():
   row=next(r for r in C['files'] if r['path']==p)
   self.assertEqual(row['sha256'],original_sha)
  active=(ROOT/JS).read_text()
  old=(ROOT/(BASE+'assets/unified-date-v0.3.2.js')).read_text()
  for text in ("function rewriteContextLinks(){", "function fluentFormat(isoDate) {",
               "if(!isoDate)return '';", "syncQueryCount", "clearInvalidRateSelection",
               "stagedRateIntent", "connectFluentReverse"):
   self.assertIn(text,active)
  self.assertNotIn("function rewriteContextLinks(){\n    if(!valid())return;",active)
  self.assertNotIn("function rewriteContextLinks(){\n    if(!valid())return;",old)
 def test_new_source_browser_suites_are_evidence_not_acceptance(self):
  self.assertFalse(M['browser_acceptance_passed'])
  for name in ('unified-date-cross-page.test.js','unified-date-regression.test.js'):
   rel='wp-content/plugins/mad4b-commerce/tests/browser/date-intent/'+name
   self.assertTrue((ROOT/rel).is_file())
   self.assertIn(rel,M['verified_source_assets'])
if __name__=='__main__':unittest.main()
