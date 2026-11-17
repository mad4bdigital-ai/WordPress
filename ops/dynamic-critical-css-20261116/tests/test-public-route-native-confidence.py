import hashlib,json,pathlib,subprocess,sys,tempfile,time
D=pathlib.Path(sys.argv[1]);SCRIPT=D/'public-route-queue.py'
def write(path,obj):
 path.write_text(json.dumps(obj))
def record(site,cat,st,ledger,job,status,hint=None):
 args=[sys.executable,str(SCRIPT),'record',site,str(cat),str(st),str(ledger),str(job),status]
 if hint:args.append(hint)
 return subprocess.run(args,capture_output=True,text=True)
with tempfile.TemporaryDirectory() as root:
 root=pathlib.Path(root);site='source';now=int(time.time())
 route={'id':95,'type':'page','language':'en','path':'/about-us/','revision':5,
        'route_key':hashlib.sha256(b'about-us').hexdigest()}
 catalog={'contract':'mad4b.public-critical-css.routes.v1','host':'tours.dev.mad4b.com',
          'wpml_languages':['en'],'default_language':'en',
          'global_generation':0,'listing_generation':0,'routes':[route]}
 state={'site_host':catalog['host'],'critical_css':'on','css_epoch':129,'native_breakpoint':767,
        'runtime_sha':'a'*64}
 C=root/'cat.json';S=root/'site.json';L=root/'ledger.json';J=root/'job.json'
 write(C,catalog);write(S,state)
 def src_key(listing):
  deps=[catalog['host'],route['language'],route['path'],route['route_key'],
    str(route['revision']),str(catalog.get('global_generation',0)),
    str(catalog.get('listing_generation',0)) if listing else '',
    str(state['css_epoch']),str(state['native_breakpoint']),
    str(state['critical_css']),str(state['runtime_sha'])]
  return hashlib.sha256('|'.join(deps).encode()).hexdigest()
 def job(key,at):
  return {'contract':'mad4b.public-critical-css.job.v1','site':site,
          'catalog_generation':0,'route':route,'source_key':key,'selected_at':at}
 write(J,job(src_key(False),now))
 result=record(site,C,S,L,J,'native_candidate','listing')
 assert result.returncode==0,result.stderr
 d=json.loads(L.read_text());r=d['routes'][route['route_key']]
 assert r['status']=='native_candidate' and r['depends_on_listings']
 print('PASS first complete non-benefit stores a pending independent sample')
 result=record(site,C,S,L,J,'native_candidate','listing')
 assert result.returncode!=0 and 'stale-catalog-revision' in result.stderr
 assert json.loads(L.read_text())['routes'][route['route_key']]['status']=='native_candidate'
 print('PASS replay of same measurement cannot claim two independent runs')
 d=json.loads(L.read_text());r=d['routes'][route['route_key']]
 r['attempted_at']=now-3602;r['retry_after']=now-2
 r['source_key']=src_key(True)
 write(L,d);write(J,job(src_key(True),now))
 result=record(site,C,S,L,J,'native_candidate','listing')
 assert result.returncode==0,result.stderr
 d=json.loads(L.read_text());r=d['routes'][route['route_key']]
 assert r['status']=='native_preferred' and r['retry_after']==r['attempted_at']
 print('PASS second post-cooldown same-generation negative becomes terminal native-preferred')
 import importlib.util
 spec=importlib.util.spec_from_file_location('q',SCRIPT);q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
 assert q.choose(catalog,state,d,now)[0] is None
 print('PASS unchanged native-preferred skips repetitive Chromium')
 catalog['listing_generation']=1
 assert q.choose(catalog,state,d,now)[0]==route
 print('PASS new listing data automatically requeues native-preferred route')
 print('PASS 5/5 independent negative-QA confidence transitions')
