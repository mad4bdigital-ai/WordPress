import importlib.util,json,hashlib,pathlib,tempfile,sys,time
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('source_families',D/'public-source-families.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
count=0
def test(name,p):
 global count
 assert p,name
 count+=1;print('PASS',name)
sha=lambda b:hashlib.sha256(b).hexdigest()
with tempfile.TemporaryDirectory() as tmp:
 root=pathlib.Path(tmp)/'wp-content';(root/'litespeed/css').mkdir(parents=True);(root/'mad4b-public-critical-css-v1/certs').mkdir(parents=True);(root/'mad4b-public-critical-css-v1/assets').mkdir(parents=True)
 m.ROOTS['wayfa']=(str(root),'wayfatours.com')
 routes=[]
 for id in (11,12,13):
  route={'language':'en','path':f'/tours/{id}/','type':'tours-and-activities',
       'id':id,'revision':0,'source_profile':'shared-template:tours-and-activities:default'}
  route['route_key']=sha((route['language']+'|'+route['path']+'|'+route['type']+'|'+str(id)).encode());routes.append(route)
 cat={'contract':'mad4b.public-critical-css.routes.v1','host':'wayfatours.com','wpml_languages':['en'],'default_language':'en','global_generation':0,'listing_generation':3,'routes':routes}
 state={'site_host':'wayfatours.com','critical_css':'on','css_epoch':39,'native_breakpoint':767,'runtime_sha':'a'*64}
 ledger={'site':'wayfa','routes':{}}
 def certify(route,doc='b'*64,market='base',currency='USD'):
  css=('c'*32) if route['id']==11 else ('d'*32) if route['id']==12 else ('e'*32)
  data=(f'body{{color:#{route["id"]:06x}}}'.encode())*1700
  (root/'litespeed/css'/(css+'.css')).write_bytes(data)
  critical=(f'hero{{color:#{route["id"]:06x}}}'.encode())*1200;digest=sha(critical)
  (root/'mad4b-public-critical-css-v1/assets'/(digest+'.css')).write_bytes(critical)
  cert={'contract':m.CERT,'status':'certified','host':'wayfatours.com',
        'language':'en','path':route['path'],'type':route['type'],'object_id':route['id'],
        'market_country':market,'currency':currency,'css_epoch':39,'runtime_sha':'a'*64,
        'critical_cap_px':767,'native_mobile_breakpoint':767,'critical_file':digest+'.css',
        'critical_sha':digest,'critical_bytes':len(critical),
        'css_file':css+'.css','css_sha':sha(data),'elementor_doc_fingerprint':doc,
        'dependencies':{'global_generation':0,'route_generation':0,'listing_generation':3,'contains_dynamic_listing':True},
        'evidence':{'decision':'passed_evidence','tested':5,'viewports':[320,390,414,767,768]}}
  key=sha(('wayfatours.com|en|'+route['path']+'|'+market+'|'+currency).encode())
  (root/'mad4b-public-critical-css-v1/certs'/(key+'.json')).write_text(json.dumps(cert))
  ledger['routes'][route['route_key']]={'status':'certified','source_key':m.queue.source_key(cat,route,state,True),'depends_on_listings':True,'attempted_at':1,'retry_after':1}
  return cert
 r=m.build('wayfa',cat,state,ledger,root)
 test('one shared source family collects 3 tours',r['source_groups_total']==1 and r['routes_discovered']==3)
 test('zero unmeasured canaries never certify shared source',r['source_canary_qualified_groups']==0)
 test('first missing tour picked as source QA canary',r['next_canary_route_key']==routes[0]['route_key'])
 certify(routes[0]);r=m.build('wayfa',cat,state,ledger,root)
 test('one exemplar insufficient for shared template source',r['source_canary_qualified_groups']==0 and r['routes_with_exact_current_certificate']==1)
 certify(routes[1]);r=m.build('wayfa',cat,state,ledger,root)
 test('two independent matching Elementor graphs prove shared source canary',r['source_canary_qualified_groups']==1)
 test('shared source does not certify third unseen route CSS',r['routes_on_native_or_unproven_css']==1 and r['source_families'][0]['source_routes_not_individually_certified']==1)
 test('future source canary automatically avoids duplicate QA',r['next_canary_route_key'] is None)
 test('two CSS hashes may differ without copying per-route assets',r['source_families'][0]['canary_count']==2)
 cert=certify(routes[1],doc='c'*64);r=m.build('wayfa',cat,state,ledger,root)
 test('changed Elementor graph refuses shared source promotion',r['source_families'][0]['status']=='source_variants_need_split')
 certify(routes[1]);cat['listing_generation']=4;r=m.build('wayfa',cat,state,ledger,root)
 test('tour source listing update invalidates all stale canaries',r['routes_with_exact_current_certificate']==0)
 cat['listing_generation']=3;state['runtime_sha']='f'*64;r=m.build('wayfa',cat,state,ledger,root)
 test('new original PHP runtime SHA invalidates all source canaries',r['routes_with_exact_current_certificate']==0)
 state['runtime_sha']='a'*64;cat['routes'][2]['source_profile']='elementor-document:13';r=m.build('wayfa',cat,state,ledger,root)
 test('personal Elementor document forms an independent source family',r['source_groups_total']==2)
 test('new source family remains uncategorically pending',r['source_groups_by_status']['source_canary_pending']>=1)
 test('malformed source profile fails route-level grouping',m.source_profile({**routes[0],'source_profile':'../../admin'}).startswith('legacy-route:'))
 try:m.build('source',cat,state,ledger,root)
 except ValueError:test('no cross-company source lineage',True)
 else:raise AssertionError('cross-company accepted')
 # A pathological 597-page shared source must never spend 597 Chrome runs.
 routes[2]['source_profile']='shared-template:tours-and-activities:default'
 cat['routes']=routes[:]
 for id in (14,15,16):
  r={'language':'en','path':f'/tours/{id}/','type':'tours-and-activities',
     'id':id,'revision':0,'source_profile':'shared-template:tours-and-activities:default'}
  r['route_key']=sha(('en|'+r['path']+'|tours-and-activities|'+str(id)).encode());cat['routes'].append(r)
 import time
 future=int(time.time())+3600
 ledger['routes']={r['route_key']:{'status':'hold','source_key':m.queue.source_key(cat,r,state),
       'depends_on_listings':False,'attempted_at':10,'retry_after':future,'failures':1}
       for r in cat['routes'][:4]}
 hold=m.build('wayfa',cat,state,ledger,root)
 test('four distinct negative canaries bound the whole 597-route source family',
     hold['source_groups_by_status']['source_canary_hold']==1 and hold['next_canary_route_key'] is None)
 for rec in ledger['routes'].values():rec['retry_after']=0
 retry=m.build('wayfa',cat,state,ledger,root)
 test('bounded retry reuses a failed canary rather than new unseen routes',
      retry['next_canary_route_key'] in ledger['routes'])
 # Live Elementor source-graph contract: static content ignores unrelated
 # tour/listing changes, dynamic/unknown graphs never bypass invalidation.
 source=dict(routes[0],source_graph='a'*64,source_graph_status='configured',source_listing_dependency=False,type='page',source_profile='shared-template:page:default')
 prior=m.family_key(cat,state,source)
 bumped=m.family_key({**cat,'listing_generation':cat['listing_generation']+1},state,source)
 test('proven plain source does not requalify for unrelated tour update',prior==bumped)
 source['source_listing_dependency']=True
 test('listing dependent source requalifies after tour update',m.family_key(cat,state,source)!=m.family_key({**cat,'listing_generation':cat['listing_generation']+1},state,source))
 source.pop('source_listing_dependency')
 test('missing dependency metadata fails closed',m.family_key(cat,state,source)!=m.family_key({**cat,'listing_generation':cat['listing_generation']+1},state,source))
 source['source_listing_dependency']=False
 test('different actual Theme Builder document gives distinct source generation',m.family_key(cat,state,source)!=m.family_key(cat,state,{**source,'source_graph':'b'*64}))
 source['source_graph_status']='ambiguous'
 test('ambiguous Theme Builder proof may not bypass global listing invalidation',m.family_key(cat,state,source)!=m.family_key({**cat,'listing_generation':cat['listing_generation']+1},state,source))
 source['source_graph_status']='configured'
 test('different WCML activation configuration reopens a source generation',m.family_key(cat,state,source)!=m.family_key(cat,{**state,'wcml_multi_currency':True},source))
 # A source proved only in USD must not be declared production qualified
 # for EUR, EGP and GBP markets with unobserved country rules.
 cat['routes']=routes[:2];cat['listing_generation']=3
 state['runtime_sha']='a'*64
 ledger['routes']={}
 certify(routes[0]);certify(routes[1])
 state['enabled_currencies']=['USD','EUR','EGP','GBP'];state['base_currency']='USD';state['wcml_multi_currency']=True
 partial=m.build('wayfa',cat,state,ledger,root)
 test('USD source canaries do not certify multi-currency geo market matrix',partial['source_canary_qualified_groups']==0 and partial['source_groups_by_status'].get('source_canary_market_partial')==1)
 test('market report explicitly lists missing source currencies',partial['source_families'][0]['currencies_pending']==['EGP','EUR','GBP'] and partial['country_market_scope']=='dynamic_not_enumerated')
 # Native-only eligibility is a SOURCE decision after independent
 # original-browser evidence that this template has no reusable CSS bundle.
 state['wcml_multi_currency']=False;state['enabled_currencies']=['USD']
 state['base_currency']='USD';ledger['routes']={}
 for route in routes[:2]:
  ledger['routes'][route['route_key']]={'status':'hold',
     'source_key':m.queue.source_key(cat,route,state),
     'hold_reason':'source_css_bundle_unavailable','attempted_at':99,
     'retry_after':0,'depends_on_listings':False}
 native=m.build('wayfa',cat,state,ledger,root)
 test('two actual zero-bundle Chrome canaries close source as native-only',native['source_groups_by_status'].get('source_native_optimizer_unavailable')==1)
 test('native-only source stops futile unbounded Chromium attempts',native['next_canary_route_key'] is None and native['source_families'][0]['native_no_bundle_canaries']==2)
 test('native-only source never reports source CSS qualification',native['source_canary_qualified_groups']==0)
 cat['listing_generation']+=1
 renewed=m.build('wayfa',cat,state,ledger,root)
 test('new source dependency generation can reopen native-only source admission',renewed['next_canary_route_key'] is not None)
 # A native-only SOURCE is defined by two independent original-browser
 # zero-bundle captures under the same Elementor+optimizer contract. Changing
 # only the tour/listing source generation does not invalidate this negative
 # optimizer evidence (nor mint an asset for a visitor).
 for route in routes[:2]:
  route['source_graph']='c'*64
  route['source_graph_status']='configured'
  route['source_listing_dependency']=True
 state['source_optimizer_sha']='e'*64
 cat['listing_generation']=6
 ledger['routes']={}
 for route in routes[:2]:
  ledger['routes'][route['route_key']]={'status':'hold',
    'source_key':m.queue.source_key(cat,route,state),
    'optimizer_source_key':m.queue.optimizer_source_key(cat,route,state),
    'hold_reason':'source_css_bundle_unavailable','attempted_at':1234,
    'retry_after':0,'depends_on_listings':False}
 reference=m.build('wayfa',cat,state,ledger,root)
 test('two source optimizer SHA-bound Chrome failures close native-only cohort',
      reference['source_groups_by_status'].get('source_native_optimizer_unavailable')==1)
 cat['listing_generation']=7
 after_tour_update=m.build('wayfa',cat,state,ledger,root)
 test('tour content/listing generation does not retry the same proven absent optimizer',
      after_tour_update['source_groups_by_status'].get('source_native_optimizer_unavailable')==1)
 test('native-only contract still never grants a CSS or route certificate',
      after_tour_update['source_canary_qualified_groups']==0 and after_tour_update['routes_with_exact_current_certificate']==0)
 test('negative SOURCE key is invariant across route changes, not CSS changes',
      m.queue.optimizer_source_key(cat,routes[0],state)==ledger['routes'][routes[0]['route_key']]['optimizer_source_key'])
 state['source_optimizer_sha']='f'*64
 invalid_opt=m.build('wayfa',cat,state,ledger,root)
 test('changed LiteSpeed settings or 406 source reopens native-only proof',
      invalid_opt['source_groups_by_status'].get('source_canary_pending')==1 and invalid_opt['next_canary_route_key'] is not None)
 # Even when a legacy route source_key remains equal, the explicit optimizer
 # signature is authoritative and cannot be bypassed after plugin changes.
 test('legacy route key cannot waive changed CSS optimizer proof',
      invalid_opt['source_families'][0]['native_no_bundle_canaries']==0)
 state['source_optimizer_sha']='e'*64
 routes[0]['source_graph']='f'*64
 invalid_graph=m.build('wayfa',cat,state,ledger,root)
 test('changed Elementor Theme Builder graph reopens affected native-only proof',
      invalid_graph['source_groups_by_status'].get('source_canary_pending')>=1 and invalid_graph['next_canary_route_key'] is not None)
 routes[0]['source_graph']='c'*64
 state['css_epoch']=40
 invalid_epoch=m.build('wayfa',cat,state,ledger,root)
 test('new CSS epoch invalidates prior negative optimizer source ABI',
      invalid_epoch['source_groups_by_status'].get('source_canary_pending')==1)
 state['css_epoch']=39
 state['runtime_sha']='d'*64
 invalid_runtime=m.build('wayfa',cat,state,ledger,root)
 test('native runtime changes reopen browser capture',invalid_runtime['source_groups_by_status'].get('source_canary_pending')==1)
 print(f'PASS {count}/{count} source-owned group certification invariants')
