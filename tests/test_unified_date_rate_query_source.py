"""Date Intent must narrow native JetEngine, JetSearch and archive filters."""
import hashlib,json,re,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PATH='wp-content/plugins/mad4b-commerce/modules/presentation/unified-date-presentation-v1.php'
M=json.loads((ROOT/'docs/release-coverage/unified-date-rate-and-reconcile-20261111.json').read_text())
class UnifiedDate(unittest.TestCase):
 def test_exact_source_preimage_and_reviewed_candidate(self):
  initial=subprocess.check_output(['git','-C',str(ROOT),'show',M['original_git_head']+':'+PATH],timeout=15)
  self.assertEqual(hashlib.sha256(initial).hexdigest(),M['prior_candidate_sha256'])
  self.assertEqual(hashlib.sha256((ROOT/PATH).read_bytes()).hexdigest(),M['reconciled_candidate_sha256'])
 def test_all_three_date_lanes_use_single_and_intersection(self):
  s=(ROOT/PATH).read_text()
  self.assertEqual(s.count('function mad4b_unified_date_intersect_post_ids_v1('),1)
  self.assertEqual(s.count("=mad4b_unified_date_intersect_post_ids_v1("),3)
  self.assertIn("if(!$existing)return [0];",s)
  self.assertIn("$_POST['query']['mad4b_date_clear']",s)
  self.assertIn("return ['status'=>'absent'];",s)
  self.assertNotIn('MAD4B_DATE_QA_ONLY_BEGIN',s)
  self.assertNotIn('mad4b-date-query-qa-20261111.jsonl',s)
  self.assertNotIn('file_put_contents(',s)
  self.assertIn('mad4b_unified_date_currency_context_ajax_v1',s)
  self.assertIn("set_client_currency($currency)",s)
  self.assertIn("['USD','EUR','EGP','GBP']",s)
  self.assertIn("wp_ajax_nopriv_jet_smart_filters",s)
  self.assertNotIn('wc_create_order(',s)


  self.assertIn("if($raw){",s)
  self.assertIn("if((int)($query->id??0)!==310",s)
  self.assertIn("'tour_rates_accordion'",s)
  self.assertIn("shared_product_verified_presentation_tour_id()",s)
  self.assertIn("mad4b_unified_tour_rates_for_date_v1($tourId,$request['intent'])",s)
  self.assertIn("MAD4B_Unified_Date_Intent_V1::matching_tour_ids($intent)",s)
  self.assertIn("MAD4B_Unified_Date_Intent_V1::matching_tour_ids($parsed)",s)
 def test_does_not_grant_checkout_or_mutate_rate_prices(self):
  s=(ROOT/PATH).read_text()
  self.assertNotIn('wc_create_order(',s)
  self.assertNotIn('update_post_meta(',s)
  self.assertNotIn('payment_intent',s)
  self.assertIn("get_post_status($rateId)!=='publish'",s)
  self.assertIn("shared_product_eligible_tour_rate_ids($tourId)",s)
  self.assertIn("get_post_type($rateId)!=='tour-rates'",s)
if __name__=='__main__':unittest.main()
