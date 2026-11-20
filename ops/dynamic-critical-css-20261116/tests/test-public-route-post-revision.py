"""A changed post's certificate must carry its exact WP-CLI revision, never zero."""
import hashlib,importlib.util,pathlib,tempfile,sys
root=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('publisher',root/'public-route-certificate.py')
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
digest=lambda x:hashlib.sha256(x).hexdigest()
with tempfile.TemporaryDirectory() as t:
 t=pathlib.Path(t);(t/'litespeed/css').mkdir(parents=True)
 source=b'.page{display:flex;max-width:768px}'*900
 filename='a'*32+'.css'
 (t/'litespeed/css'/filename).write_bytes(source)
 critical=b'.page{display:block}'*950
 route={'id':93,'type':'page','language':'en','path':'/about-us/',
        'route_key':'b'*64,'revision':17}
 catalog={'contract':p.CATALOG,'host':'tours.dev.mad4b.com',
          'routes':[route],'wpml_languages':['en']}
 row={'lang':'en','route':'/about-us/','page_id':93,'device':'mobile',
      'widths':[320,390,414,768],'css_file':filename,
      'resolved_currency':'EUR','resolved_market':'static',
      'source_bytes':len(source),'source_sha':digest(source),
      'critical_bytes':len(critical),'critical_file':digest(critical)+'.css',
      'critical_sha':digest(critical),'dom_sha':digest(b'93')}
 candidates={'contract':p.CANDIDATE,'host':catalog['host'],'errors':[],
             'candidates':[row]}
 widths=[320,390,414,767,768]
 qa={'contract':p.QA,'tested':5,
     'gates':{'en':{'decision':'passed_evidence','adaptiveMaxWidth':767,
                 'cssPriority':'high','fullCssPreload':True,'widths':widths,
                 'testedAt':'2026-11-17T02:40:00Z'}},
     'results':[{'lang':'en','width':w,'pass':True,'fcpGain':1200,'lcpReg':-80,
                 'variant':{'cls':0.004}} for w in widths]}
 html=('<html><head><link rel="stylesheet" href="https://tours.dev.mad4b.com'
       '/wp-content/litespeed/css/'+filename+'"></head><body class="page-id-93">'
       '<div class="elementor-93">A public page</div></body></html>')
 headers='HTTP/2 200\nx-mad4b-public-ccss-currency: eur\nx-mad4b-public-ccss-market: static\n'
 state={'site_host':'tours.dev.mad4b.com','critical_css':'on',
        'native_breakpoint':767,'css_epoch':129,'currency':'EUR',
        'market_country':'static','runtime_sha':'a'*64,
        'global_generation':4,'listing_generation':9,
        'wpml_languages':['en']}
 checks=[]
 def check(name,result):
  assert result,name;checks.append(name);print('PASS',name)
 x=p.proof(catalog,route,candidates,qa,html,headers,state,t)
 check('published post revision 17 bound to certificate',x['dependencies']['route_generation']==17)
 check('global and listing generation preserved',x['dependencies']['global_generation']==4)
 check('certificate includes measured native PHP consumer SHA',x['runtime_sha']=='a'*64)
 updated=dict(route,revision=18)
 catalog['routes']=[updated]
 y=p.proof(catalog,updated,candidates,qa,html,headers,state,t)
 check('new revision produces revised certificate',y['dependencies']['route_generation']==18)
 for name,changed,st in [
  ('invalid negative revision denied',dict(updated,revision=-1),state),
  ('malformed revision string denied',dict(updated,revision='18'),state),
  ('conflicting route and site revisions denied',updated,dict(state,route_generation=17)),
  ('no measured native runtime SHA denied',updated,dict(state,runtime_sha=''))]:
  catalog['routes']=[changed]
  try:p.proof(catalog,changed,candidates,qa,html,headers,st,t)
  except ValueError:check(name,True)
  else:raise AssertionError(name)
 print(f'PASS {len(checks)}/{len(checks)} route post-revision certification contract')
