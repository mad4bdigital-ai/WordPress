#!/usr/bin/env python3
"""Hermetic negative regression of the production-only cache privacy verifier."""
import importlib.util,json,pathlib,tempfile,unittest
from unittest import mock
P=pathlib.Path(__file__).resolve().parents[2]/'ops/cart-island-v1/probe.py'
spec=importlib.util.spec_from_file_location('cart_boundary_test_owner',P)
probe=importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)
class CacheBoundaryTests(unittest.TestCase):
 def response(self,origin='BYPASS',cc='private, no-store, max-age=0',body='<main>Hello</main>',content_type='text/html'):
  return dict(code=200,cc=cc,origin=origin,body=body,set_cookie=False,type=content_type)
 def test_only_anonymous_may_hit(self):
  with mock.patch.object(probe,'fetch',return_value=self.response(origin='HIT',cc='public, max-age=1800')):
   result=probe.verify_html('/','anonymous','','payment_only')
   self.assertEqual(result['cache'],'HIT')
 def test_sensitive_cart_cookie_bypass(self):
  for name,_ in probe.CASES[1:]:
   with self.subTest(name=name):
    with mock.patch.object(probe,'fetch',return_value=self.response()):
     res=probe.verify_html('/es/',name,'fake','payment_only')
     self.assertTrue(res['private_no_store'])
 def test_public_html_for_cart_cookie_rejected(self):
  for wrong in [
   self.response(origin='HIT'),
   self.response(origin='MISS'),
   self.response(origin=''),
   self.response(cc='public, max-age=1800'),
   self.response(cc='private, max-age=0'),
  ]:
   with self.subTest(wrong=wrong):
    with mock.patch.object(probe,'fetch',return_value=wrong):
     with self.assertRaises(AssertionError):
      probe.verify_html('/','woocommerce_guest_session','dummy','payment_only')
 def test_payment_only_cart_never_embedded(self):
  b='<html><div data-mad4b-cart-island="v1"></div></html>'
  with mock.patch.object(probe,'fetch',return_value=self.response(body=b)):
   with self.assertRaises(AssertionError):
    probe.verify_html('/','anonymous','','payment_only')
 def test_standard_shell_holds_no_personalized_attrs(self):
  b='<div data-mad4b-cart-island="v1" data-cart-token="private"></div>'
  with mock.patch.object(probe,'fetch',return_value=self.response(body=b)):
   with self.assertRaises(AssertionError):
    probe.verify_html('/','woocommerce_guest_session','dummy','standard')
 def test_live_wpml_route_inventory_requires_six_safe_paths(self):
  doc={'contract':'mad4b.cart-cache-boundary.wpml-routes.v1','site_host':'wayfatours.com',
       'routes':['/','/es/','/tours-and-activities/','/es/tours-and-activities/',
                 '/tours-and-activities/a-tour/','/es/tours-and-activities/un-viaje/']}
  with tempfile.TemporaryDirectory() as temp:
   file=pathlib.Path(temp)/'routes.json'
   file.write_text(json.dumps(doc))
   self.assertEqual(len(probe.site_routes(str(file))),6)
   for field,value in [('site_host','other.com'),('routes',['/','/es/'])]:
    wrong={**doc,field:value}
    file.write_text(json.dumps(wrong))
    with self.assertRaises(AssertionError):
     probe.site_routes(str(file))
   doc['routes'][5]='/wp-admin/'
   file.write_text(json.dumps(doc))
   with self.assertRaises(AssertionError):
    probe.site_routes(str(file))
 def test_store_must_be_private_and_has_cart_shape(self):
  sample=self.response(cc='private, no-store',origin='BYPASS',
     body=json.dumps({'items':[],'items_count':0}),content_type='application/json')
  with mock.patch.object(probe,'fetch',return_value=sample) as fetch:
   probe.verify_store()
   self.assertEqual(fetch.call_count,4)
   self.assertTrue(any('/es/wp-json/' in args[0][0] for args in fetch.call_args_list))
  for cc in ['public, max-age=3600','private, max-age=0']:
   with mock.patch.object(probe,'fetch',return_value={**sample,'cc':cc}):
    with self.assertRaises(AssertionError):
     probe.verify_store()
if __name__=='__main__':
 unittest.main(verbosity=2)
