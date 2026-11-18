#!/usr/bin/env python3
"""Signed market Query Builder cache: trusted dimensions, safe fallback, native TTL."""
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'wp-content/mu-plugins/mad4b-render-query-governor.php'
class SignedMarketQueryGovernorContract(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.src=SRC.read_text()
  cls.scope=cls.src.split('function mad4b_render_query_signed_market_scope(): string {',1)[1].split('function mad4b_render_query_private_context() {',1)[0]
  cls.policy=cls.src.split('function mad4b_render_query_policy( $query ) {',1)[1].split("add_action( 'jet-engine/query-builder/query/after-query-setup'",1)[0]
  cls.governor=cls.src.split("add_action( 'jet-engine/query-builder/query/after-query-setup'",1)[1].split('}, 100, 1 );',1)[0]
 def test_explicit_rollout_and_default_off(self):
  for marker in ("get_option('mad4b_render_query_signed_market_v1','off')",
                 "['canary','on']","HTTP_X_MAD4B_SIGNED_QUERY_CANARY",
                 "!=='GET'","['/','/es/']","!is_front_page()",
                 "'wayfatours.com'","is_user_logged_in()","is_preview()",
                 "wp_doing_ajax()","REST_REQUEST","WP_CLI","wp_using_ext_object_cache()"):
   self.assertIn(marker,self.scope)
 def test_hmac_native_owner_and_strict_cookie_gate(self):
  for marker in ('MAD4B_Country_Switcher::saved_country()',
                 'MAD4B_Country_Switcher::resolve($country,$language)',
                 'MAD4B_Public_Cookie_Cache_Contract_V1::safe_marketing_cookie',
                 'mad4b_country_selected_v1','$countryCount!==1','$seen[$name]',
                 "hash_equals((string)$_COOKIE[$name],rawurldecode($val))",
                 "WC()->session->has_session()"):
   self.assertIn(marker,self.scope)
 def test_header_override_and_geo_denials(self):
  for marker in ('mad4b_render_query_has_untrusted_market_header()',
                 'HTTP_X_MAD4B_CURRENCY','HTTP_X_MAD4B_MARKET_CONTEXT',
                 'HTTP_X_MAD4B_BYPASS_CACHE','HTTP_AUTHORIZATION',
                 'HTTP_X_WP_NONCE','HTTP_X_REQUESTED_WITH','HTTP_CF_IPCOUNTRY',
                 'HTTP_X_GEO_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY'):
   self.assertIn(marker,self.scope)
  self.assertIn("str_starts_with((string)$header,'HTTP_X_MAD4B_')",self.scope)
 def test_market_profile_currency_wpml_device_and_site_bucket(self):
  for part in ("'country'=>$country","'profile'=>$profile",
               "'wcml_country'=>$market['country']","'currency'=>$market['currency']",
               "'language'=>$language","'device'=>wp_is_mobile()",
               "'site'=>(int)get_current_blog_id()","hash('sha256',$encoded)"):
   self.assertIn(part,self.scope)
  self.assertNotIn("'cookie'=>$",self.scope)
  self.assertNotIn("$_SERVER['REMOTE_ADDR']",self.scope)
  self.assertIn('mad4b_render_query_market_identity()',self.scope)
 def test_public_policy_kept_signed_fail_closed(self):
  self.assertIn('!$signedScope && mad4b_render_query_private_context()',self.policy)
  self.assertIn("!in_array((string)$query->query_type,['posts','terms'],true)",self.policy)
  self.assertIn("'signed_market_query_type_veto'",self.policy)
  self.assertIn('!$signedScope && !mad4b_render_query_market_partition_ready()',self.policy)
  self.assertIn('mad4b_render_query_sensitive_semantics($query)',self.policy)
  self.assertIn('unverified_market_identity',self.policy)
 def test_generation_partition_and_short_ttl(self):
  self.assertIn("$signedScope=mad4b_render_query_signed_market_scope();",self.governor)
  self.assertIn("min(90,(int)$decision['ttl'])",self.governor)
  self.assertIn("($signedScope ? '-' . $signedScope : '')",self.governor)
  self.assertIn("$marketSegment=strtolower($market['country']).'-'.strtolower($market['currency'])",self.governor)
  self.assertIn('mad4b_render_query_effective_generation($query)',self.governor)
  self.assertIn('mad4b/render_query/query_version',self.governor)
  self.assertIn('(int) $query->id',self.governor)
 def test_source_scoped_invalidation_and_unknown_hold(self):
  self.assertIn('function mad4b_render_query_effective_generation',self.src)
  self.assertIn('mad4b_render_query_scope_bump_term',self.src)
  self.assertIn('function mad4b_render_query_market_partition_ready()',self.src)
  self.assertIn('function mad4b_render_query_changed_post(',self.src)
 def test_no_cache_api_bypass(self):
  for forbidden in ('fastcgi_cache_bypass','wp_cache_flush(','wp_dequeue_script('):
   self.assertNotIn(forbidden,self.scope)
if __name__=='__main__': unittest.main()
