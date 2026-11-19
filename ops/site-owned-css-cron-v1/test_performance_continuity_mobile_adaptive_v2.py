#!/usr/bin/env python3
"""Test both attested mobile CSS strategies and reject false readiness."""
import importlib.util,pathlib,unittest
from unittest import mock
PATH=pathlib.Path(__file__).with_name('performance-continuity-mobile-adaptive-v2.py')
spec=importlib.util.spec_from_file_location('performance_continuity_mobile_adaptive',PATH)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class AdaptiveMonitoring(unittest.TestCase):
 def page(self,rest_media='print',critical_media='',fetch='low',host='wayfatours.com',onload=True,inline=True):
  src='\x20'*16384
  return ('<html><head>'
       +'<style id="mad4b-critical-css-v1"'+(' media="'+critical_media+'"' if critical_media else '')+'>'+src+'</style>'
       +f'<link id="mad4b-css-rest-v1" rel="stylesheet" href="https://{host}/wp-content/litespeed/css/'+('a'*32)+'.css" media="'+rest_media+'" fetchpriority="'+fetch+'" '+('onload="this.onload=null;this.media=&#39;all&#39;"' if onload else '')+'/>'
       +( '<script data-mad4b-css-async-lib="inline-v1">var x="mad4b-css-rest-v1"; setTimeout(function(){x.media="all"},6500)</script>' if inline else '')
       +'</head><body>OK</body></html>').encode()
 def test_original_spanish_low_priority_contract(self):self.assertTrue(m.verify_mobile_async_inline(self.page()))
 def test_english_measured_cap767_contract(self):self.assertTrue(m.verify_mobile_async_inline(self.page('(min-width:768px)','(max-width:767px)','auto')))
 def test_wrong_english_cap_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page('(min-width:768px)','(max-width:599px)','auto')))
 def test_wrong_native_breakpoint_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page('(min-width:767px)','(max-width:767px)','auto')))
 def test_blocking_media_all_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page('all','(max-width:767px)','auto')))
 def test_foreign_css_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page(host='different.example')))
 def test_missing_inline_loader_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page(inline=False)))
 def test_missing_async_onload_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page(onload=False)))
 def test_low_variant_wrong_priority_denied(self):self.assertFalse(m.verify_mobile_async_inline(self.page(fetch='high')))
 def test_no_false_healthy_from_header_alone(self):
  x={'en':{'http_status':200,'css_ready':1,'native_complete':0,'delivery_verified':False},'es':{'http_status':200,'css_ready':1,'native_complete':0,'delivery_verified':True}}
  self.assertEqual(m.evaluate({},x,10)['state'],'degraded')
 def test_verified_strategies_recover_priority(self):
  x={'en':{'http_status':200,'css_ready':1,'native_complete':0,'delivery_verified':True},'es':{'http_status':200,'css_ready':1,'native_complete':0,'delivery_verified':True}}
  r=m.evaluate({},x,10)
  self.assertEqual((r['state'],r['recovery_priority'],r['alert']),('healthy','normal',False))
if __name__=='__main__':unittest.main(verbosity=2)
