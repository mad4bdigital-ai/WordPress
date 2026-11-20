import hashlib,importlib.util,json,pathlib,re,sys,tempfile
d=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('resume',d/'public-route-resume.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sha=lambda x:hashlib.sha256(x).hexdigest()
with tempfile.TemporaryDirectory() as tmp:
 root=pathlib.Path(tmp)/'wp-content'
 (root/'mu-plugins').mkdir(parents=True)
 consumer_code=b'<?php /* native runtime unchanged */'
 (root/'mu-plugins/422-mad4b-public-critical-css-runtime.php').write_bytes(consumer_code)
 consumer_sha=sha(consumer_code)
 cssdir=root/'litespeed/css';assetdir=root/'mad4b-public-critical-css-v1/assets'
 certdir=root/'mad4b-public-critical-css-v1/certs'
 for f in (cssdir,assetdir,certdir):f.mkdir(parents=True,exist_ok=True)
 m.HOSTS['source']=('tours.dev.mad4b.com',str(root))
 route={'id':93,'path':'/about-us/','type':'page','revision':7,'language':'en','route_key':'b'*64}
 cat={'contract':m.queue.CATALOG,'host':'tours.dev.mad4b.com','wpml_languages':['en','es'],
      'default_language':'en','global_generation':4,'listing_generation':8,
      'routes':[route]}
 state={'site_host':'tours.dev.mad4b.com','critical_css':'on','all_public_css':'on',
        'css_epoch':129,'global_generation':4,'listing_generation':8,'runtime_sha':consumer_sha}
 css=b'.a{display:block;line-height:1.4}'*1200
 crit=b'.a{display:block}'*1200
 cssname='a'*32+'.css';criticalname=sha(crit)+'.css'
 (cssdir/cssname).write_bytes(css);(assetdir/criticalname).write_bytes(crit)
 docs=sha(b'93')
 cert={'contract':'mad4b.public-critical-css.route-certificate.v1','status':'certified',
       'host':'tours.dev.mad4b.com','path':'/about-us/','language':'en','type':'page','object_id':93,
       'market_country':'DE','currency':'USD','css_epoch':129,'runtime_sha':consumer_sha,
       'critical_cap_px':767,'native_mobile_breakpoint':767,
       'css_file':cssname,'critical_file':criticalname,
       'css_sha':sha(css),'critical_sha':sha(crit),'critical_bytes':len(crit),
       'elementor_doc_fingerprint':docs,
       'dependencies':{'route_generation':7,'global_generation':4,
                       'listing_generation':8,'contains_dynamic_listing':True},
       'evidence':{'decision':'passed_evidence','tested':5,
                   'viewports':[320,390,414,767,768]}}
 key=sha(b'tours.dev.mad4b.com|en|/about-us/|DE|USD')
 (certdir/(key+'.json')).write_text(json.dumps(cert))
 headers='HTTP/2 200\nx-mad4b-public-ccss-ready: 1\nx-mad4b-public-ccss-market: DE\nx-mad4b-public-ccss-currency: USD\n'
 html='<html><head><style id="mad4b-public-critical-css-v1" media="(max-width:767px)">'+crit.decode()+'</style><link id="mad4b-public-css-rest-v1" rel="stylesheet" href="https://tours.dev.mad4b.com/wp-content/litespeed/css/'+cssname+'" media="(min-width:768px)"></head><body class="page-id-93 elementor-93">'+('content '*400)+'</body></html>'
 checks=[]
 def check(name,yes):
  assert yes,name;checks.append(name);print('PASS',name)
 get=lambda **kw:m.ok_cert('source',root,kw.get('catalog',cat),kw.get('route',route),kw.get('state',state),kw.get('headers',headers),kw.get('html',html))
 real=get()
 check('exact live certificate and QA proof reused',real is not None and real['scope']=='one_browser_resolved_market_currency')
 check('dynamic listing dependency retained',real['depends_on_listings'] is True)
 check('public readiness header required',get(headers=headers.replace('ccss-ready: 1','ccss-ready: 0')) is None)
 check('cross market certificate cannot be inherited',get(headers=headers.replace('market: DE','market: FR')) is None)
 check('cross currency certificate cannot be inherited',get(headers=headers.replace('currency: USD','currency: EUR')) is None)
 check('new content generation invalidates existing QA',get(route=dict(route,revision=8),catalog=dict(cat,routes=[dict(route,revision=8)])) is None)
 check('global Elementor changes invalidate',get(state=dict(state,global_generation=5)) is None)
 check('dynamic listing changes invalidate',get(state=dict(state,listing_generation=9)) is None)
 check('critical style bytes cannot be silently changed',get(html=html.replace('display:block','display:flex',1)) is None)
 check('changed Elementor document graph fails',get(html=html.replace('elementor-93','elementor-94')) is None)
 check('missing original full CSS fallback fails',get(html=html.replace('media="(min-width:768px)"','media="print"')) is None)
 check('new CSS SHA fails',get(html=html.replace(cssname,'b'*32+'.css')) is None)
 check('stale epoch fails',get(state=dict(state,css_epoch=130)) is None)
 check('changed native PHP runtime SHA prevents stale QA reuse',
       get(state=dict(state,runtime_sha='f'*64)) is None)
 saved=cert.pop('runtime_sha')
 (certdir/(key+'.json')).write_text(json.dumps(cert))
 check('old pre-runtime-SHA certificate cannot be reused',get() is None)
 cert['runtime_sha']=saved
 (certdir/(key+'.json')).write_text(json.dumps(cert))
 try:m.ok_cert('wayfa',root,cat,route,state,headers,html)
 except ValueError:check('cross-site reuse impossible',True)
 else:raise AssertionError('cross-site accepted')
 print('PASS %d/%d independently verified public CSS certificate reuse invariants'%(len(checks),len(checks)))
