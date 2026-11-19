import importlib.util,sys,unittest,http.client,gzip
from email.message import Message
from pathlib import Path
from types import ModuleType
from unittest.mock import patch
root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('perf_http_candidate',root/'performance_http_readback_v2.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
# Contract dependency stub for hermetic audit tests; no HTTP or WP runtime.
release=ModuleType('performance_release_gate_v2')
release.canonical_origin=lambda value: value.rstrip('/')
sys.modules.setdefault('performance_release_gate_v2',release)
origin='https://example.test'
url=origin+'/'

def markup(css='native'):
 link='<link rel="stylesheet" href="https://example.test/wp-content/litespeed/css/'+'a'*32+'.css">'
 if css=='print':
  link=link.replace('rel="stylesheet"','rel="stylesheet" media="print" onload="this.media=all"')
 if css=='duplicate':link+=link
 if css=='critical':
  return ('<html><head>'+link+'<style id="mad4b-critical-css-v1">abcd</style>'
      +'<link id="mad4b-css-rest-v1" href="/x.css">'
      +'<script data-mad4b-css-async-lib="inline-v1"></script>'
      +'</head><body>hero</body></html>').encode()
 return ('<html><head>'+link+'</head><body>hero</body></html>').encode()

class Resp:
 def __init__(self, headers=None, body=None,status=200,target=url):
  self.headers=Message()
  for key,val in (headers or {}).items():self.headers[key]=val
  self.body=markup() if body is None else body
  self.status=status;self.target=target
 def __enter__(self):return self
 def __exit__(self,*args):return False
 def geturl(self):return self.target
 def getcode(self):return self.status
 def read(self,n):return self.body

class HttpAuditNative(unittest.TestCase):
 def policy(self, cache='private_market_veto'):
  return {'contract':'mad4b.performance-http-policy.v2','enabled':True,
   'company':'source','origin':origin,'routes':[{'path':'/','locale':'en','expect_cache':cache}]}
 def test_mobile_browser_webp_accept_matches_css_capture(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0',
                     'X-MAD4B-Mobile-CSS-Native-Complete':'1',
                     'X-MAD4B-Preset-Cache':'BYPASS',
                     'Cache-Control':'private'})
  with patch.object(m.urllib.request,'urlopen',return_value=resp) as request:
   outcome=m.audit(self.policy(),rounds=1)
  sent=request.call_args.args[0]
  self.assertEqual(sent.get_header('Accept'),m.HTML_ACCEPT)
  self.assertIn('image/webp',sent.get_header('Accept'))
  self.assertEqual(outcome['observations'][0]['css_negotiation_profile'],
                   'chromium-mobile-webp-v1')
  self.assertTrue(outcome['observations'][0]['passed'])

 def test_gzip_complete_document_validates_crc(self):
  plain=markup()
  packed=gzip.compress(plain)
  resp=Resp(headers={'Content-Encoding':'gzip',
                     'X-MAD4B-Mobile-CSS-Ready':'0',
                     'X-MAD4B-Mobile-CSS-Native-Complete':'1',
                     'X-MAD4B-Preset-Cache':'BYPASS',
                     'Cache-Control':'private'},
            body=packed)
  with patch.object(m.urllib.request,'urlopen',return_value=resp) as req:
   result=m.audit(self.policy(),rounds=1)
  self.assertEqual(req.call_args.args[0].get_header('Accept-encoding'),'gzip')
  self.assertEqual(result['state'],'passed')
  self.assertEqual(result['observations'][0]['html_transport_encoding'],'gzip')
  self.assertEqual(result['observations'][0]['html_bytes'],len(plain))

 def test_truncated_gzip_fails_closed_then_retries(self):
  valid=gzip.compress(markup())
  headers={'Content-Encoding':'gzip','X-MAD4B-Mobile-CSS-Ready':'0',
           'X-MAD4B-Mobile-CSS-Native-Complete':'1',
           'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'}
  with patch.object(m.urllib.request,'urlopen',side_effect=[
      Resp(headers=headers,body=valid[:-12]),
      Resp(headers=headers,body=valid)]) as get, patch.object(m.time,'sleep'):
   result=m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,2)
  self.assertEqual(result['state'],'passed')
  self.assertEqual(result['observations'][0]['transport_attempts'],2)

 def test_all_corrupt_gzip_responses_never_pass(self):
  with patch.object(m.urllib.request,'urlopen',return_value=Resp(
      headers={'Content-Encoding':'gzip'},body=b'not a gzip stream')) as get,patch.object(m.time,'sleep'):
   with self.assertRaises(m.gzip.BadGzipFile):
    m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,3)

 def test_litespeed_comments_after_html_are_valid(self):
  html=markup()+b'\n<!-- Page optimized by LiteSpeed Cache @2026-11-16 -->\n'
  response=Resp(headers={'Content-Encoding':'gzip',
      'X-MAD4B-Mobile-CSS-Ready':'0',
      'X-MAD4B-Mobile-CSS-Native-Complete':'1',
      'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'},
      body=gzip.compress(html))
  with patch.object(m.urllib.request,'urlopen',return_value=response):
   read=m.audit(self.policy(),rounds=1)
  self.assertEqual(read['state'],'passed')

 def test_script_after_html_closure_is_denied(self):
  response=Resp(body=markup()+b'<script>bad()</script>')
  with patch.object(m.urllib.request,'urlopen',return_value=response) as get,patch.object(m.time,'sleep'):
   with self.assertRaisesRegex(EOFError,'incomplete_html_document'):
    m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,3)

 def test_incomplete_identity_html_fails_closed(self):
  with patch.object(m.urllib.request,'urlopen',return_value=Resp(
      headers={'Content-Encoding':'identity'},body=b'<html><body>incomplete')) as get,patch.object(m.time,'sleep'):
   with self.assertRaisesRegex(EOFError,'incomplete_html_document'):
    m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,3)

 def test_unknown_content_encoding_is_denied(self):
  with patch.object(m.urllib.request,'urlopen',return_value=Resp(
      headers={'Content-Encoding':'br'})) as get:
   with self.assertRaisesRegex(ValueError,'unsupported_content_encoding'):
    m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,1)

 def test_native_capture_profile_matches_http_monitor_source(self):
  capture=(root.parent/'mobile-critical-css-v1/capture.js').read_text()
  self.assertIn("const HTML_ACCEPT = '"+m.HTML_ACCEPT+"';",capture)
  self.assertIn("const CSS_NEGOTIATION_PROFILE = '"+m.CSS_NEGOTIATION_PROFILE+"';",capture)
  self.assertIn("extraHTTPHeaders:{'Accept':HTML_ACCEPT",capture)
  self.assertIn('origin_cache_bypassed:DIAG_BYPASS',capture)
 def test_native_complete_safe_private(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0','X-MAD4B-Mobile-CSS-Native-Complete':'1',
                     'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private, no-store'})
  with patch.object(m.urllib.request,'urlopen',return_value=resp):
   r=m.audit(self.policy(),rounds=1)
  self.assertEqual(r['state'],'passed')
  self.assertEqual(r['observations'][0]['css_delivery_mode'],'native_complete')
  self.assertFalse(r['observations'][0]['critical_css_contract_ok'])
 def test_native_claim_without_server_proof_denied(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0','X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'})
  with patch.object(m.urllib.request,'urlopen',return_value=resp):
   self.assertEqual(m.audit(self.policy(),rounds=1)['state'],'hold')
 def test_native_incomplete_css_markups_denied(self):
  for mode in ('print','duplicate'):
   resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0','X-MAD4B-Mobile-CSS-Native-Complete':'1',
                      'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'},body=markup(mode))
   with self.subTest(mode=mode),patch.object(m.urllib.request,'urlopen',return_value=resp):
    self.assertEqual(m.audit(self.policy(),rounds=1)['state'],'hold')
 def test_public_expected_but_private_denied(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0','X-MAD4B-Mobile-CSS-Native-Complete':'1',
                      'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'})
  with patch.object(m.urllib.request,'urlopen',return_value=resp):
   self.assertEqual(m.audit(self.policy('shared_public'),rounds=1)['state'],'hold')
 def test_retries_full_response_not_partial(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'0','X-MAD4B-Mobile-CSS-Native-Complete':'1',
                      'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'})
  with patch.object(m.urllib.request,'urlopen',side_effect=[http.client.IncompleteRead(b'partial',100),resp]) as get,\
       patch.object(m.time,'sleep'):
   v=m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,2)
  self.assertEqual(v['observations'][0]['transport_attempts'],2)
  self.assertTrue(v['observations'][0]['passed'])
 def test_three_incomplete_reads_never_pass(self):
  with patch.object(m.urllib.request,'urlopen',side_effect=http.client.IncompleteRead(b'partial',100)) as get,\
       patch.object(m.time,'sleep'):
   with self.assertRaises(http.client.IncompleteRead):m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,3)
 def test_unexpected_redirect_is_not_retried(self):
  resp=Resp(target='https://other.invalid/')
  with patch.object(m.urllib.request,'urlopen',return_value=resp) as get:
   with self.assertRaisesRegex(ValueError,'unexpected_redirect'):m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,1)
 def test_error_html_is_not_success(self):
  resp=Resp(status=503)
  with patch.object(m.urllib.request,'urlopen',return_value=resp) as get:
   with self.assertRaisesRegex(ValueError,'unexpected_http_status'):m.audit(self.policy(),rounds=1)
  self.assertEqual(get.call_count,1)
 def test_critical_css_header_is_separate_contract(self):
  resp=Resp(headers={'X-MAD4B-Mobile-CSS-Ready':'1','X-MAD4B-Mobile-CSS-Native-Complete':'0',
                     'X-MAD4B-Preset-Cache':'BYPASS','Cache-Control':'private'},
            body=markup('critical'))
  with patch.object(m.urllib.request,'urlopen',return_value=resp):
   result=m.audit(self.policy(),rounds=1)
  self.assertEqual(result['state'],'passed')
  self.assertEqual(result['observations'][0]['css_delivery_mode'],'critical_certified')
  self.assertFalse(result['observations'][0]['native_css_contract_ok'])

if __name__=='__main__':unittest.main()
