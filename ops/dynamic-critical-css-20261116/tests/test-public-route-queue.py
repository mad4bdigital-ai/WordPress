import importlib.util,json,pathlib,sys,tempfile,time
d=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('queue',d/'public-route-queue.py')
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
def row(typ,id,route,lang='en',revision=0):
    import hashlib
    return {'type':typ,'id':id,'path':route,'language':lang,'revision':revision,
       'route_key':hashlib.sha256((lang+'|'+route+'|'+typ+'|'+str(id)).encode()).hexdigest()}
a=row('page',51,'/contact-us/')
b=row('page',52,'/about-us/')
c=row('tours-and-activities',53,'/tours-and-activities/nile-tour/')
cat={'contract':q.CATALOG,'host':'tours.dev.mad4b.com',
     'wpml_languages':['en','es'],'default_language':'en',
     'global_generation':0,'listing_generation':0,'generation':0,
     'routes':[a,b,c,row('page',55,'/es/','es')]}
state={'site_host':'tours.dev.mad4b.com','critical_css':'on','css_epoch':129,'native_breakpoint':767}
ledger={'contract':q.QUEUE,'site':'source','routes':{}}
checks=[]
def ok(name,yes):
    assert yes,name;checks.append(name);print('PASS',name)
ok('trusted WPML catalog',len(q.safe_catalog(cat,'source'))==4)
picked,key=q.choose(cat,state,ledger,1000)
ok('new static page selected before expensive public tours',picked==b)
ledger['routes'][b['route_key']]={'source_key':key,'status':'certified','depends_on_listings':False}
picked,key=q.choose(cat,state,ledger,1001)
ok('certified route skipped without repeated QA',picked is not None and picked!=b)
ledger['routes'][a['route_key']]={'source_key':q.source_key(cat,a,state),'status':'hold','retry_after':2000}
picked,key=q.choose(cat,state,ledger,1001)
ok('failed unchanged page cooling off',picked==c)
ledger['routes'][c['route_key']]={'source_key':key,'status':'unsupported'}
picked,key=q.choose(cat,state,ledger,1002)
ok('commerce route not retested continually after independent hold',picked is None)
cat['routes'][0]=row('page',51,'/contact-us/',revision=2)
picked,key=q.choose(cat,state,ledger,1002)
ok('edited content bypasses failure cooldown immediately',picked['id']==51)
cat['routes'][0]=a
cat['global_generation']=3
picked,key=q.choose(cat,state,ledger,1002)
ok('global Elementor update invalidates prior certificates',picked is not None)
ledger2={'contract':q.QUEUE,'site':'source','routes':{
    b['route_key']:{'source_key':q.source_key(cat,b,state,True),'status':'certified','depends_on_listings':True}}}
cat['listing_generation']=1
picked,key=q.choose(cat,state,ledger2,1004)
ok('listing-dependent page re-enters QA after listing change',
   q.choose(dict(cat,routes=[b]),state,ledger2,1004)[0]['route_key']==b['route_key'])
ledger3={'contract':q.QUEUE,'site':'source','routes':{
    b['route_key']:{'source_key':q.source_key(cat,b,state,False),'status':'certified','depends_on_listings':False}}}
picked,key=q.choose(cat,state,ledger3,1004)
ok('static page not retested on unrelated listing-only change',
   q.choose(dict(cat,routes=[b]),state,ledger3,1004)[0] is None)
with tempfile.TemporaryDirectory() as t:
    path=pathlib.Path(t)/'ledger.json'
    q.atom(path,ledger)
    ok('persistent ledger round-trip',q.read_ledger(path,'source')['routes']==ledger['routes'])
try:q.safe_catalog(cat,'wayfa')
except ValueError:ok('cross-tenant source catalog blocked',True)
else:raise AssertionError()
bad=dict(cat,routes=[row('page',59,'/checkout/')])
try:q.safe_catalog(bad,'source')
except ValueError:ok('checkout path refused before any browser',True)
else:raise AssertionError()
# Persist only a fixed system-generated hold reason, never a browser
# exception or input-controlled reason capable of poisoning private reports.
with tempfile.TemporaryDirectory() as t:
    root=pathlib.Path(t)
    clean=dict(cat,routes=[a])
    clean['global_generation']=0;clean['listing_generation']=0
    cfile=root/'catalog.json';cfile.write_text(json.dumps(clean))
    sfile=root/'site.json';sfile.write_text(json.dumps(state))
    lfile=root/'ledger.json'
    jfile=root/'job.json'
    job={'contract':'mad4b.public-critical-css.job.v1','site':'source','route':a,
         'source_key':q.source_key(clean,a,state),'selected_at':int(time.time())}
    jfile.write_text(json.dumps(job))
    original=sys.argv
    try:
        sys.argv=['queue','record','source',str(cfile),str(sfile),
                  str(lfile),str(jfile),'hold','unstable_css_source']
        q.main()
        persisted=q.read_ledger(lfile,'source')['routes'][a['route_key']]
        ok('unstable JetEngine stylesheet reason persisted with safe HOLD',
           persisted['status']=='hold' and persisted['hold_reason']=='unstable_css_source')
        sys.argv[-1]='unsafe_comment_from_browser'
        try:q.main()
        except ValueError:ok('untrusted arbitrary HOLD explanation rejected',True)
        else:raise AssertionError('untrusted reason accepted')
        after=q.read_ledger(lfile,'source')['routes'][a['route_key']]
        ok('rejected HOLD text never alters previous trusted ledger',after==persisted)
        sys.argv[-1]='source_html_drift'
        q.main()
        classified=q.read_ledger(lfile,'source')['routes'][a['route_key']]
        ok('zero-viewport HTML drift is a bounded fail-closed reason',
           classified['status']=='hold' and classified['hold_reason']=='source_html_drift')
    finally:
        sys.argv=original
print('PASS',len(checks),'/',len(checks),'incremental route scheduler invariants')
