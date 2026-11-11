"""Direct MU optional components: render guards before JetEngine native lazy mode."""
import hashlib,importlib.util,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/direct-mu-additive-source-20261111.json').read_text())
P='wp-content/mu-plugins/mad4b-tour-optional-sections-lazy.php'
class OptionalLazy(unittest.TestCase):
 def test_signed_live_source_sha_and_activation_not_assumed(self):
  item=next(x for x in M['files'] if x['path']==P)
  patch=M['approved_candidate_overlays'][0]
  self.assertEqual(item['sha256'],patch['live_sha256'])
  self.assertEqual(patch['path'],P)
  self.assertEqual(hashlib.sha256((ROOT/P).read_bytes()).hexdigest(),patch['candidate_sha256'])
  self.assertFalse(M['automatic_mu_activation_authorized'])
  self.assertFalse(M['site_modified'])
 def test_condition_gates_before_expensive_render(self):
  s=(ROOT/P).read_text()
  self.assertIn("'tour_extras_status'",s)
  self.assertIn("'tour_vehicles_transport_status'",s)
  self.assertIn("'tour_train_transport_status'",s)
  self.assertIn("'tour_air_transport_status'",s)
  self.assertIn("get_post_status($id)!=='publish'",s)
  self.assertIn("elementor/frontend/widget/should_render",s)
  self.assertIn("elementor/frontend/container/should_render",s)
  self.assertIn("'jet-listing-grid'",s)
  self.assertIn("self::editor()",s)
 def test_native_deferred_only_if_verified_and_opted_in(self):
  s=(ROOT/P).read_text()
  self.assertIn("private static function lazy_eligible",s)
  self.assertIn("jet-engine/listing/grid/is_lazy_load",s)
  self.assertIn("jet-engine/listing/grid/lazy-load/options",s)
  self.assertIn("parent_object_context_required()",s)
  self.assertIn("get_post_status($listing)!=='publish'",s)
  self.assertIn("get_query_by_id",s)
  self.assertIn("query_type==='posts'",s)
  self.assertIn("mad4b_tour_optional_lazy_mode",s)
  self.assertIn("'canary'",s)
  self.assertIn("'650px'",s)
  self.assertIn('Jet_Engine_Listings_Ajax_Handlers',s)
  self.assertIn('$handler->query_has_valid_signature(',s)
  self.assertIn('$handler->query_has_valid_mutable_signature(',s)
  self.assertIn('$handler->query_matches_widget_settings(',s)
  self.assertIn("['MAD4B_Tour_Optional_Sections_Lazy_V1','verify_native_transport_ajax'],20,1",s)

 def test_hidden_template_styles_only_and_visible_nested_listing_preserved(self):
  s=(ROOT/P).read_text()
  self.assertIn("include_nested_styles_from_blocked_template",s)
  self.assertIn("self::$blocked_nested_css[$listing]=true",s)
  self.assertIn("self::$used_listing_css[$id]=true",s)
  self.assertIn("!isset(self::$used_listing_css[$id])",s)
  self.assertIn("'record_visible_listing'",s)
  self.assertIn("'style_loader_tag'",s)
 def test_no_new_remote_booking_or_payment_actions(self):
  s=(ROOT/P).read_text()
  for forbidden in ('update_post_meta(','wp_remote_post(','wc_create_order(','wp_insert_post(','wp_delete_post('):
   self.assertNotIn(forbidden,s)
if __name__=='__main__':unittest.main()
