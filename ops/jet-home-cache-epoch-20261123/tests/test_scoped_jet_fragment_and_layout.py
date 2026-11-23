"""Fail-closed source regression proof for the live signed Wayfa JetEngine AJAX optimization."""
from pathlib import Path
import hashlib,json,unittest
ROOT=Path(__file__).resolve().parents[3]
M=ROOT/'ops/jet-home-cache-epoch-20261123/release.json'
class ScopedQueryAndLayout(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.receipt=json.loads(M.read_text())
        cls.wayfa=ROOT/cls.receipt['code']['wayfa_jet_response_cache']['repository_path']
        cls.source=ROOT/cls.receipt['code']['shared_layout_continuity']['source_path']
        cls.overlay=ROOT/cls.receipt['code']['shared_layout_continuity']['wayfa_path']
    def test_exact_company_source_hashes(self):
        self.assertEqual(hashlib.sha256(self.wayfa.read_bytes()).hexdigest(),self.receipt['code']['wayfa_jet_response_cache']['sha256'])
        self.assertEqual(self.source.read_bytes(),self.overlay.read_bytes())
        self.assertEqual(hashlib.sha256(self.source.read_bytes()).hexdigest(),self.receipt['code']['shared_layout_continuity']['sha256'])
    def test_cache_scope_and_short_ttl(self):
        src=self.wayfa.read_text()
        for token in ("private const TTL=60", "wp_using_ext_object_cache", "is_user_logged_in", "get_query_by_id(291)", "wp_cache_get_last_changed('terms')", "wp_send_json_success($cached)", "wp_cache_set(self::$active_key", "woocommerce_", "wp_woocommerce_session_", "$_REQUEST['widget_settings']", "'en','es'", "doc"):
            if token=='doc':continue
            self.assertIn(token,src)
        self.assertIn("'get_listing'",src)
        self.assertIn("addedPostCSS",src)
        self.assertIn("array_is_list($added)",src)
        self.assertIn("request_sha256",src)
        self.assertIn("'jet_engine_ajax'",src)
        self.assertIn('get_post_meta($document',src)
        self.assertIn("'data-post-id='",src)
    def test_epoch_guard_requires_immutable_source(self):
        src=self.source.read_text()
        for token in ("function proof_digest", "get_post_meta($id,'_elementor_data'", "hash_file('sha256'", "get_option('elementor_active_kit'", "wp_get_custom_css()", "get_theme_mods()", "get_option('astra-settings'", "GET_LOCK", "BINARY option_value", "is_front_page()", "css_dependencies", "elementor_docs"):
            self.assertIn(token,src)
        self.assertIn("if(!self::matches($original,$manifest,$active))return",src)
        self.assertIn('update_option(self::RECEIPT',src)
    def test_receipt_scope(self):
        data=self.receipt
        self.assertEqual(data['ajax']['confirmed_result_items'],{'en':43,'es':41})
        self.assertEqual(data['layout']['tracked_css_and_runtime_assets'],20)
        self.assertTrue(data['layout']['rejects_mutated_styles'])
        self.assertTrue(data['layout']['rejects_cross_site_identity'])
if __name__=='__main__':unittest.main()
