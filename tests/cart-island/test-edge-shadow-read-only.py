#!/usr/bin/env python3
"""Hermetic source regression for local-only Nginx Woo session cache canary."""
import pathlib,json,importlib.util,re,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
HERE=ROOT/'ops/cart-island-v1/edge-shadow'
DOC=json.loads((HERE/'wayfa-shadow-edits-v1.json').read_text())
spec=importlib.util.spec_from_file_location('mad4b_nginx_shadow_generator', HERE/'build-loopback-config.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
def fixture():
 text=DOC['marker']
 for edit in DOC['replacements']:
  text+=edit['before']+'\n'
 text+='    fastcgi_no_cache $wayfa_html_no_cache_path $wayfa_html_no_cache_request $wayfa_not_public_response;\n'
 return text
class ShadowPolicy(unittest.TestCase):
 def test_exact_source_sha_bound(self):
  self.assertRegex(DOC['original_sha256'],r'^[0-9a-f]{64}$')
  self.assertRegex(DOC['proposed_sha256'],r'^[0-9a-f]{64}$')
  self.assertNotEqual(DOC['original_sha256'],DOC['proposed_sha256'])
 def test_loopback_and_header_are_required(self):
  text=mod.build(fixture(),DOC)
  self.assertIn('listen 127.0.0.1:18081',text)
  self.assertIn('http_x_mad4b_cart_cache_test',text)
  self.assertIn('18081',DOC['insert_before_marker'])
  self.assertNotIn('listen 0.0.0.0:18081',text)
  self.assertIn('  listen 8080;',text)
 def test_strict_cookie_scope_mobile_get(self):
  src=DOC['insert_before_marker']
  for element in ['http_cookie','wp_woocommerce_session_','GET','mobile',
                  'wayfa_sensitive_request_context','http_authorization',
                  'query_string','http_x_mad4b_cart_cache_test']:
   self.assertIn(element,src)
  self.assertIn('/(?:es/)?',src)
  self.assertNotIn('/tours-and-activities/',src)
 def test_cache_bypass_only_not_cache_write(self):
  text=mod.build(fixture(),DOC)
  self.assertIn('fastcgi_cache_bypass $wayfa_html_no_cache_path $mad4b_cart_shell_probe_bypass;',text)
  self.assertIn('fastcgi_no_cache $wayfa_html_no_cache_path $wayfa_html_no_cache_request',text)
  self.assertEqual(text.count('fastcgi_no_cache '),1)
 def test_original_defaults_under_public_port(self):
  src=DOC['insert_before_marker']
  self.assertIn('default $wayfa_html_no_cache_request;',src)
  self.assertIn('default $wayfa_cookie_cache_suffix;',src)
  self.assertIn('wayfa_html_device',src)
 def test_fails_duplicate_or_missing_marker(self):
  with self.assertRaises(ValueError):mod.build(fixture()+DOC['marker'],DOC)
  with self.assertRaises(ValueError):mod.build(fixture().replace(DOC['marker'],'not-a-marker'),DOC)
 def test_fails_when_write_veto_missing(self):
  with self.assertRaises(ValueError):
   mod.build(fixture().replace('fastcgi_no_cache ','not-the-write-veto '),DOC)
 def test_disallows_repatching_existing_candidate(self):
  built=mod.build(fixture(),DOC)
  with self.assertRaises(ValueError):mod.build(built,DOC)
if __name__=='__main__': unittest.main(verbosity=2)
