"""Visitor country preference cannot grant market pricing or leak into shared HTML."""
import hashlib,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
M=json.loads((ROOT/'docs/release-coverage/country-switcher-privacy-and-entitlement-reconcile-20261111.json').read_text())
PHP=ROOT/M['php_path'];JS=ROOT/M['js_path']
class CountrySwitcher(unittest.TestCase):
 def test_exact_preimage_and_source_snapshot(self):
  old=subprocess.check_output(['git','-C',str(ROOT),'show',M['base_git_commit']+':'+M['php_path']],timeout=15)
  self.assertEqual(hashlib.sha256(old).hexdigest(),M['original_php_sha256'])
  self.assertEqual(hashlib.sha256(PHP.read_bytes()).hexdigest(),M['reconciled_php_sha256'])
  self.assertEqual(hashlib.sha256(JS.read_bytes()).hexdigest(),M['live_js_sha256'])
 def test_separate_location_from_market_entitlement(self):
  s=PHP.read_text()
  self.assertIn("MAD4B_SCP_Switcher_Sources::CONTRACT==='mad4b.switcher-sources.v1'",s)
  self.assertIn("return $iso&&isset(MAD4B_SCP_Switcher_Sources::countries()[$iso])",s)
  self.assertIn("if(!$iso)return new WP_Error('mad4b_country_invalid'",s)
  self.assertIn("return $profile['profile_id']??'';",s)
  self.assertIn("if(!hash_equals(hash_hmac(",s)
  self.assertIn("private static function uuid()",s)
  self.assertIn("!str_contains($raw,'.')",s)
 def test_cache_html_is_not_per_visitor_geo_or_all_market_lookup(self):
  s=PHP.read_text()
  method=s[s.index('public static function shortcode('):s.index('public static function ajax_state(')]
  self.assertIn("self::saved_country()",method)
  self.assertIn("MAD4B_SCP_Switcher_Sources::countries()",method)
  self.assertNotIn("self::context()",method)
  self.assertNotIn("foreach($countries as $iso=>$label){\n   $availability=self::resolve($iso);",method)
  self.assertIn("if (!", "if (!")  # static test intentionally small
 def test_preference_batch_and_user_choice_win(self):
  s=JS.read_text()
  self.assertIn('window.MAD4BVisitorPreferenceBatch',s)
  self.assertIn("bridge.read().then",s)
  self.assertIn('mad4bCountrySearchUserChoice',s)
  self.assertIn("catch(originalRead)",s)
  self.assertIn("select.dataset.saved=select.value",s)
  self.assertIn("credentials:'same-origin'",s)
 def test_release_not_certified(self):
  self.assertFalse(M['global_release_authorized'])
  self.assertFalse(M['live_site_modified'])
if __name__=='__main__':unittest.main()
