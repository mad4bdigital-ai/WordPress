#!/usr/bin/env python3
"""Fail-closed contracts for private, signed-country Top Cities HTML fragments."""
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/'wp-content/mu-plugins/429-mad4b-signed-home-destination-card-cache-v1.php'
class SignedHomeCardCacheContract(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.code=FILE.read_text()
 def test_private_route_scope_and_default_off(self):
  for token in ["get_option(self::OPTION,'off')","$mode!=='canary' && $mode!=='on'",
    "'HTTP_X_MAD4B_PRIVATE_CARDS'","'v1'","'wayfatours.com'",
    "['/','/es/']","is_front_page()","is_user_logged_in()",
    "wp_doing_ajax()","is_preview()","is_admin()",
    "wp_using_ext_object_cache()","'HTTP_AUTHORIZATION'"]:
   self.assertIn(token,self.code)
 def test_only_signed_country_plus_marketing_cookie(self):
  for token in ["mad4b_country_selected_v1","$marketCount!==1",
    "MAD4B_Public_Cookie_Cache_Contract_V1::safe_marketing_cookie",
    "MAD4B_Country_Switcher::saved_country()", "preg_match('/^[A-Z]{2}$/D'"]:
   self.assertIn(token,self.code)
  self.assertIn("WC()->session->has_session()",self.code)
 def test_explicit_market_and_geo_guard(self):
  for name in ("HTTP_X_MAD4B_CURRENCY","HTTP_X_MAD4B_MARKET_CONTEXT",
   "HTTP_X_MAD4B_PASSPORT","HTTP_X_COUNTRY_CODE",
   "HTTP_X_GEOIP_COUNTRY","HTTP_X_FORWARDED_GEO_COUNTRY",
   "HTTP_X_MAD4B_GEO_COUNTRY","HTTP_X_MAD4B_BYPASS_CACHE",
   "HTTP_X_MAD4B_DYNAMIC_CSS","HTTP_X_MAD4B_CSS_PRIORITY",
   "HTTP_X_REQUESTED_WITH","HTTP_PHP_AUTH_USER","HTTP_X_WP_NONCE"):
   self.assertIn(name,self.code)
 def test_wpml_exact_graph_and_term_fragments(self):
  for token in ("'en' => ['doc'=>25909,'widget'=>'b25dbff','listing'=>45927]",
   "'es' => ['doc'=>34605,'widget'=>'b25dbff','listing'=>68823]",
   "mad4b_register_public_term_cards","'destinations'"):
   self.assertIn(token,self.code)
  self.assertNotIn("wp_cache_flush(",self.code)
  self.assertNotIn("remove_all_filters(",self.code)
 def test_isolated_key_and_short_ttl(self):
  self.assertIn("hash('sha256',$marketKey.'|'.$lang.'|'.$country)",self.code)
  self.assertIn("hash('sha256',$scope.'|'.$key)",self.code)
  self.assertIn("self::GROUP,45",self.code)
 def test_canary_only_count_logging(self):
  for term in ("'hits'=>$stats['hit']","'reads'=>$stats['read']",
   "'writes'=>$stats['write']","filesize($path)>131072"):
   self.assertIn(term,self.code)
  self.assertNotIn("serialize($_COOKIE",self.code)
  self.assertNotIn("echo $marketKey",self.code)
if __name__=='__main__': unittest.main()
