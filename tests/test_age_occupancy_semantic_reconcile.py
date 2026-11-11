"""Read-only source acceptance: original Form6 + Age Registry function parity."""
import json,hashlib,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/age-occupancy-live-semantic-reconcile-20261111.json').read_text())
BASE='wp-content/plugins/mad4b-age-occupancy-registry/'
class AgeContract(unittest.TestCase):
 def test_preimage_and_four_exact_live_sources_plus_reviewed_root(self):
  self.assertEqual(len(M['files']),5)
  self.assertTrue(M['original_mad4b_plugin_identity_preserved'])
  for row in M['files']:
   initial=subprocess.check_output(['git','-C',str(ROOT),'show',
      M['candidate_parent_commit']+':'+row['path']],timeout=20)
   self.assertEqual(hashlib.sha256(initial).hexdigest(),row['original_git_sha256'])
   self.assertEqual(hashlib.sha256((ROOT/row['path']).read_bytes()).hexdigest(),row['candidate_sha256'])
   if row['path']!=BASE+'mad4b-age-occupancy-registry.php':
    self.assertEqual(row['candidate_sha256'],row['live_sha256'])
  self.assertEqual(sum(r['candidate_sha256']!=r['live_sha256'] for r in M['files']),1)
 def test_all_dependency_source_code_is_present(self):
  php=(ROOT/BASE/'mad4b-age-occupancy-registry.php').read_text()
  self.assertIn('Plugin Name: MAD4B Age-Aware Occupancy Registry',php)
  self.assertIn("require_once __DIR__ . '/includes/fluent-age-policy.php';",php)
  for dep in M['unchanged_existing_live_dependencies']:
   self.assertTrue((ROOT/dep).is_file())
 def test_noncommercial_decimal_and_tour_context_contract(self):
  main=(ROOT/BASE/'mad4b-age-occupancy-registry.php').read_text()
  forms=(ROOT/BASE/'includes/fluent-forms.php').read_text()
  for token in ('normalize_age','age_cents','booking_evidence','verify_booking_evidence',
                "'pricing_authorized'=>false","age_context_classification_invalid",
                "'context_mode','classification_status'"):
   self.assertIn(token,main)
  for token in ('generic_policy()','current_tour_id()','submission_tour_id(',
                'age_context_mode','MAD4B_Age_Fluent_Policy::from_token',
                "'tour_only','disabled'","'pricing_authorized'=>false"):
   self.assertIn(token,forms)
  self.assertIn("'context_mode'=>$isTour?'tour':'collect_only'",forms)
  self.assertIn("'classification_status'=>$isTour?'tour_policy_verified':'pending_product_selection'",forms)
 def test_context_ownership_age_inputs_and_validation_restrict_financial_access(self):
  policy=(ROOT/BASE/'includes/fluent-age-policy.php').read_text()
  admin=(ROOT/BASE/'includes/admin.php').read_text()
  stay=(ROOT/BASE/'includes/age-aware-stay.php').read_text()
  for x in ("hash_hmac('sha256'",'wp_salt(', 'get_post_meta($rate,','age_rate_context_invalid','age_tour_context_required'):
   self.assertIn(x,policy)
  self.assertIn("wp_nonce_field('mad4b_age_collection_save')",admin)
  self.assertIn('MAD4B_Age_Occupancy_Registry::normalize_age',stay)
  self.assertIn("stay_age_assignment_mismatch",stay)
  self.assertFalse(M['checkout_enabled_by_this_change'])
  self.assertFalse(M['globally_authorized_release'])
 def test_date_free_age_capture_cannot_turn_into_booked_tour(self):
  forms=(ROOT/BASE/'includes/fluent-forms.php').read_text()
  self.assertIn("'classification'=>'pending_product_selection'",forms)
  self.assertIn("'mode'=>'collect_only'",forms)
  self.assertIn("'age_reference'=>$reference",forms)
  self.assertIn("count($value)!==$count",forms)
  js=(ROOT/BASE/'assets/fluent-ages.js').read_text()
  self.assertIn("const allowed=(key,value)=>",js)
  self.assertIn("if(seq!==revision)return",js)
  self.assertIn("field",js)
if __name__=='__main__':unittest.main()
