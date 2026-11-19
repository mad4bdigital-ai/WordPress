"""Original EN/ES home cache cannot mix stale menu activation generations."""
import hashlib,importlib.util,json,os,pathlib,socket,sys,tempfile,threading
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('cache',D/'wayfa-home-preflight.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=[]
def test(label,condition):
 assert condition,label
 checks.append(label);print('PASS',label)
with tempfile.TemporaryDirectory() as t:
 root=pathlib.Path(t)
 m.BASE=root
 (root/'mad4b-critical-css-v1').mkdir(parents=True)
 (root/'mu-plugins').mkdir()
 (root/'.mad4b-purge').mkdir()
 state={
  'contract':'mad4b.critical-css.site-source.v1',
  'site_host':'wayfatours.com','environment':'production',
  'critical_css':'on','native_breakpoint':767,
  'wpml_languages':['en','es'],'default_language':'en',
  'css_epoch':39,'critical_css_dirty_generation':9,'last_home_impact_at':0,
  'runtime_versions':{'elementor':'3.32'}
 }
 manifest={'version':1,'site':'wayfatours.com','entries':{}}
 (root/'mad4b-critical-css-v1/manifest.json').write_text(json.dumps(manifest))
 consumer=root/'mu-plugins/415-mad4b-critical-css-orchestrator.php'
 consumer.write_text('<?php // original sha admission')
 statefile=root/'state.json';statefile.write_text(json.dumps(state))
 ledger=root/'private/wayfa-home-freshness.json'
 receipt=root/'private/receipt.json'
 key=m.validate(state,manifest,hashlib.sha256(consumer.read_bytes()).hexdigest())
 test('tenant source and WPML source hash trusted',len(key)==64)
 try:m.validate(dict(state,site_host='tours.dev.mad4b.com'),manifest,'a'*64)
 except ValueError:test('cross-company activation denied',True)
 else:raise AssertionError
 try:m.validate(dict(state,wpml_languages=['en','es','de']),manifest,'a'*64)
 except ValueError:test('unactivated language never invalidates market cache',True)
 else:raise AssertionError
 endpoint=root/'.mad4b-purge/origin.sock'
 requests=[]
 ready=threading.Event()
 def server():
  with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sk:
   sk.bind(str(endpoint));sk.listen(1);ready.set()
   connection,_=sk.accept()
   with connection:
    requests.append(json.loads(connection.makefile('rb').readline()))
    connection.sendall(b'{"status":"ok","paths":2,"versioned":2}\n')
 thread=threading.Thread(target=server,daemon=True);thread.start();ready.wait(5)
 args=['wayfa-home-preflight.py',str(statefile),
       str(root/'mad4b-critical-css-v1/manifest.json'),
       str(consumer),str(ledger),str(receipt)]
 oldargs=sys.argv;sys.argv=args
 try:m.main()
 finally:sys.argv=oldargs
 thread.join(5)
 test('native versioning restricted to EN and ES home pages',requests==[['/','/es/']])
 data=json.loads(ledger.read_text())
 test('durable company state fingerprint stored after successful versioning',data['key']==key and data['action']=='refresh')
 # Missing socket on a repeated invocation must still work: no needless
 # repeated invalidation when dependencies are unchanged.
 endpoint.unlink()
 oldargs=sys.argv;sys.argv=args
 try:m.main()
 finally:sys.argv=oldargs
 test('same source identity skips new origin refresh',json.loads(receipt.read_text())['action']=='skip')
 update=dict(state,css_epoch=40)
 test('real CSS epoch changes freshness fingerprint',m.validate(update,manifest,hashlib.sha256(consumer.read_bytes()).hexdigest())!=key)
 try:m.version_exact_paths(root)
 except (ValueError,OSError):test('no native socket fails closed',True)
 else:raise AssertionError
 test('freshness receipts private',ledger.stat().st_mode&0o777==0o600)
print(f'PASS {len(checks)}/{len(checks)} exact Wayfa EN/ES home freshness assertions')
