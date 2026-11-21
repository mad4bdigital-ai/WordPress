#!/usr/bin/env python3
"""A complete V2 performance-only failure is a *candidate*, never a grant."""
import copy,importlib.util,math,pathlib,sys
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('v2negative',root/'public-v2-negative-performance.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sha='a'*64
a={'contract':m.ADMISSION,'decision':'hold_performance_or_interaction',
   'source_manifest_sha256':sha,'host':'tours.dev.mad4b.com',
   'path':'/en/tours-and-activities/demo/',
   'performance_source':'real-original-css-urls'}
q={'contract':m.QA,'host':a['host'],'path':a['path'],'manifest_sha256':sha,
   'pass':False,'smoke_only':False,'public_certificate_issued':False,
   'fastcgi_purged':False,'viewport_results':[]}
for width in m.WIDTHS:
 src={'fcp':3220,'lcp':5464,'cls':.001,'popup':None}
 crit={'fcp':980,'lcp':9476 if width==320 else 5550,
       'cls':.002,'popup':None}
 q['viewport_results'].append({
  'width':width,'pass':width!=320,'fcp_gain_ms':2240 if width<768 else 0,
  'lcp_reg_ms':4012 if width==320 else 86,
  'layout_pass':True,'unequal_late_lcp':False,
  'peer_order_confirmation':None,'failed_css':[],'errors':[],
  'real_css_links_loaded':True,'stylesheet_count':99,
  'source':src,'critical':crit})
def check(label,allowed,alter=None):
  data=copy.deepcopy(q);ad=copy.deepcopy(a)
  if alter:alter(data,ad)
  got=m.is_v2_lcp_negative_candidate(ad,data)
  assert got is allowed,(label,got,allowed)
  print('PASS',label)
check('only performance LCP failure offers independent native recheck',True)
check('real positive certified QA cannot be negative',False,lambda v,a:(v.update({"pass":True}),a.update(decision='qualified_for_delivery_review')))
check('partial QA cannot suppress requalification',False,lambda v,a:v['viewport_results'].pop())
check('smoke-only QA cannot qualify terminal native',False,lambda v,a:v.update(smoke_only=True))
check('route or company identity mismatch denied',False,lambda v,a:v.update(path='/different/'))
check('source CSS manifest identity mismatch denied',False,lambda v,a:v.update(manifest_sha256='b'*64))
check('failed layout never negative',False,lambda v,a:v['viewport_results'][0].update(layout_pass=False))
check('broken JavaScript never negative',False,lambda v,a:v['viewport_results'][0].update(errors=['JS failed']))
check('failed CSS never negative',False,lambda v,a:v['viewport_results'][0].update(failed_css=['stylesheet']))
check('large CLS never negative',False,lambda v,a:v['viewport_results'][0]['critical'].update(cls=.18))
check('weaker FCP never called LCP-only',False,lambda v,a:v['viewport_results'][0].update(fcp_gain_ms=40))
check('LCP is better, test only failed for other reason',False,
      lambda v,a:v['viewport_results'][0].update(lcp_reg_ms=-200))
check('no proof of restored original stylesheets denied',False,
      lambda v,a:v['viewport_results'][0].update(real_css_links_loaded=False))
check('unequal late observation window is inconclusive',False,
      lambda v,a:v['viewport_results'][0].update(unequal_late_lcp=True))
check('opposite-order failure not robust nonbenefit',False,
      lambda v,a:v['viewport_results'][0].update(peer_order_confirmation={'pass':False}))
check('popup regression cannot be native negative',False,
      lambda v,a:(v['viewport_results'][0]['source'].update(popup=True),
                  v['viewport_results'][0]['critical'].update(popup=False)))
check('NaN performance metrics fail closed',False,
      lambda v,a:v['viewport_results'][0].update(lcp_reg_ms=float('nan')))
check('desktop failure cannot be grouped into mobile nonbenefit',False,
      lambda v,a:v['viewport_results'][-1].update(**{'pass':False}))
check('public certificate cannot be retroactively negative',False,
      lambda v,a:v.update(public_certificate_issued=True))
check('any release that purged FastCGI has separate cleanup',False,
      lambda v,a:v.update(fastcgi_purged=True))
print('PASS 20/20 V2 post-release safe-negative assertions')
