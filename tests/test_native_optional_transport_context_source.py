"""Source-only native transport AJAX settings/signature and WPML taxonomy contract."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
DOC=json.loads((ROOT/'docs/release-coverage/native_optional_transport_verified_context_20261111.json').read_text())
C='wp-content/plugins/mad4b-commerce/modules/commerce/096-verified-tour-render-context.php'
T='wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/adapters/presentation-transport-context-v1.php'
MU='wp-content/mu-plugins/mad4b-tour-optional-sections-lazy.php'
class TransportSource(unittest.TestCase):
 def test_exact_source_and_canonical_old_git_blobs(self):
  records=DOC['files']
  self.assertEqual(len(records),2)
  for r in records:
   original=subprocess.check_output(['git','-C',str(ROOT),'show',
    DOC['previous_git_head']+':'+r['path']],timeout=20)
   candidate=(ROOT/r['path']).read_bytes()
   self.assertEqual(hashlib.sha256(original).hexdigest(),r['base_sha256'])
   self.assertEqual(hashlib.sha256(candidate).hexdigest(),r['candidate_sha256'])
  extra=DOC['wpml_translated_transport_category_source']
  self.assertEqual(hashlib.sha256((ROOT/T).read_bytes()).hexdigest(),extra['candidate_sha256'])
 def test_native_jetengine_handler_query_validation_precedes_render_context(self):
  s=(ROOT/MU).read_text()
  v=s[s.index('public static function verify_native_transport_ajax('):
      s.index('private static function context()')]
  self.assertIn('Jet_Engine_Listings_Ajax_Handlers',v)
  self.assertIn('$handler->query_has_valid_signature(',v)
  self.assertIn('$handler->query_matches_widget_settings(',v)
  self.assertIn('$handler->query_has_valid_mutable_signature(',v)
  self.assertLess(v.index('query_has_valid_mutable_signature('),
      v.index('begin_native_optional_transport('))
  self.assertIn("get_permalink($tour)",v)
  self.assertIn("$_SERVER['HTTP_REFERER']",v)
  self.assertIn("['MAD4B_Tour_Optional_Sections_Lazy_V1','verify_native_transport_ajax'],20,1",s)
  self.assertNotIn('REQUEST_URI',v)
  self.assertNotIn("is_singular('tours-and-activities')",v)
 def test_binding_is_noncommercial_and_does_not_authorize_all_tours(self):
  s=(ROOT/C).read_text()
  self.assertIn('begin_native_optional_transport(int $tour,string $category)',s)
  self.assertIn("get_post_status($tour)!=='publish'",s)
  self.assertIn("['vehicles','train','air']",s)
  self.assertIn("'booking_authorized'=>false",s)
  self.assertIn("'purpose'=>'signed_optional_transport_display'",s)
  self.assertIn("self::$stack[]=$context",s)
 def test_taxonomy_translation_or_fallback_remains_scoped(self):
  s=(ROOT/T).read_text()
  self.assertIn("'wpml_object_id'",s)
  self.assertIn('$localized_mode?:$mode',s)
  self.assertIn('TRANSPORT_TAXONOMY_MODE_MISMATCH',s)
  self.assertIn('TRANSPORT_SUBTYPE_MISMATCH',s)
  self.assertIn("'booking_authorized'=>false",s)
  self.assertIn('mode_term_id',s)
 def test_no_release_or_payout_authority(self):
  self.assertFalse(DOC['new_booking_authority'])
  self.assertFalse(DOC['new_payment_authority'])
  self.assertFalse(DOC['release_authorized'])
  self.assertFalse(DOC['live_browser_verified'])
if __name__=='__main__':unittest.main()
