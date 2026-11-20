import importlib.util,pathlib,sys,tempfile,json,time,os
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('health',D/'health-audit.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
checks=[]
def test(name,result):
 assert result,name
 checks.append(name);print('PASS',name)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t);wp=root/'wp-content';wp.mkdir();reports=root/'reports';folder=reports/'critical-css-health-v1'/'contact-hero-wayfa-safe-demo';proof=folder/'readback';proof.mkdir(parents=True)
 route={'route_key':'a'*64,'path':'/es/contacto/','id':200,'language':'es','type':'page'}
 (folder/'route.json').write_text(json.dumps(route));(proof/'es.headers').write_text('HTTP/2 200\nx-test: ok\n');(proof/'es.html').write_text('safe-source'+('x'*3000))
 oldreport=h.REPORT;oldsite=h.SITES['wayfa']['root'];oldcheck=h.route_resume.ok_cert
 h.REPORT=reports/'critical-css-health-v1';h.SITES['wayfa']['root']=wp
 try:
  calls=[]
  def original_verify(site,r,c,rt,st,headers,html):
   calls.append((site,rt.get('path')))
   return {'market':'base','currency':'USD','css_sha':'b'*64} if html.startswith('safe-source') else None
  h.route_resume.ok_cert=original_verify
  def run(market='base',currency='USD',chosen=route):
   return h.current_chromium_route_evidence('wayfa',wp,{'host':'wayfatours.com'},chosen,{},market,currency)
  test('exact current Chrome proof recognized without claiming HTTP Ready:1',run()['decision']=='current_original_chromium_certificate')
  test('source market divergence never inherited',run(market='DE') is None)
  test('WCML currency mismatch never inherited',run(currency='EUR') is None)
  test('different page may not inherit source browser proof',run(chosen={**route,'route_key':'c'*64}) is None)
  test('source check uses original full certificate verifier',calls==[('wayfa','/es/contacto/')]*3)
  os.utime(proof/'es.html',(time.time()-8*86400,time.time()-8*86400))
  test('expired Chrome evidence cannot silence warning',run() is None)
  os.utime(proof/'es.html',None)
  (proof/'es.html').unlink();(proof/'es.html').symlink_to('/etc/passwd')
  test('symlinked browser evidence rejected',run() is None)
  (proof/'es.html').unlink();(proof/'es.html').write_text('safe-source'+('x'*3000))
  (folder/'route.json').write_text('not-json')
  test('untrusted route marker does not silence warning',run() is None)
 finally:
  h.route_resume.ok_cert=oldcheck;h.REPORT=oldreport;h.SITES['wayfa']['root']=oldsite
print(f'PASS {len(checks)}/{len(checks)} verified-browser-profile fallback invariants')
