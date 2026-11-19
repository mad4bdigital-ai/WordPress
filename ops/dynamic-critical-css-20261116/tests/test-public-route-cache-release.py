import hashlib,importlib.util,json,os,pathlib,socket,stat,tempfile,threading,sys
dir=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location("cache",dir/"public-route-cache-release.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
h=lambda b:hashlib.sha256(b).hexdigest()
checks=[]
def ok(label,cond):
 assert cond,label;checks.append(label);print("PASS",label)
with tempfile.TemporaryDirectory() as base:
 root=pathlib.Path(base)/"wp-content"
 (root/"litespeed/css").mkdir(parents=True)
 (root/"mu-plugins").mkdir()
 (root/"mu-plugins/422-mad4b-public-critical-css-runtime.php").write_bytes(b'<?php // measured runtime')
 (root/"mad4b-public-critical-css-v1/certs").mkdir(parents=True)
 (root/"mad4b-public-critical-css-v1/assets").mkdir(parents=True)
 (root/".mad4b-purge").mkdir(parents=True)
 m.ROOTS["source"]=(str(root),"tours.dev.mad4b.com")
 route={"type":"page","id":93,"path":"/about-us/","language":"en","revision":17,
   "route_key":"a"*64}
 catalog={"contract":m.queue.CATALOG,"host":"tours.dev.mad4b.com",
 "wpml_languages":["en","es"],"default_language":"en","routes":[route]}
 state={"currency":"EUR","market_country":"static","css_epoch":129,
         "runtime_sha":h((root/"mu-plugins/422-mad4b-public-critical-css-runtime.php").read_bytes())}
 ok("fresh certified page preflight requires only authenticated exact route",
   m.validate("source",root,catalog,route,"before_capture")=="/about-us/")
 try:m.validate("source",root,catalog,dict(route,path="/checkout/"),"before_capture")
 except ValueError:ok("checkout cannot version public cache",True)
 else:raise AssertionError
 try:m.validate("wayfa",root,catalog,route,"before_capture")
 except ValueError:ok("cross-company cache path denied",True)
 else:raise AssertionError
 css=b"a{font-size:14px}"*1000;critical=b"a{font-size:12px}"*1100
 cssfile="b"*32+".css";criticalfile=h(critical)+".css"
 (root/"litespeed/css"/cssfile).write_bytes(css)
 (root/"mad4b-public-critical-css-v1/assets"/criticalfile).write_bytes(critical)
 cert={"contract":"mad4b.public-critical-css.route-certificate.v1",
 "status":"certified","host":"tours.dev.mad4b.com",
 "path":"/about-us/","language":"en","type":"page","object_id":93,
 "market_country":"static","currency":"EUR","css_epoch":129,
 "runtime_sha":state["runtime_sha"],
 "critical_cap_px":767,"evidence":{"decision":"passed_evidence"},
 "dependencies":{"route_generation":17},
 "css_file":cssfile,"critical_file":criticalfile,
 "css_sha":h(css),"critical_sha":h(critical)}
 key=h(b"tours.dev.mad4b.com|en|/about-us/|static|EUR")
 file=root/"mad4b-public-critical-css-v1/certs"/(key+".json")
 try:m.validate("source",root,catalog,route,"after_promotion",state)
 except ValueError:ok("route without actual published certificate held",True)
 else:raise AssertionError
 file.write_text(json.dumps(cert))
 ok("published exact CSS source and market allows only its own cache key",
  m.validate("source",root,catalog,route,"after_promotion",state)=="/about-us/")
 try:m.validate("source",root,catalog,route,"after_promotion",dict(state,runtime_sha="f"*64))
 except ValueError:ok("old PHP runtime cannot inherit current CSS certificate",True)
 else:raise AssertionError
 try:m.validate("source",root,catalog,route,"after_promotion",dict(state,currency="USD"))
 except ValueError:ok("cross-currency cache release denied",True)
 else:raise AssertionError
 changed=dict(route,revision=18);cat=dict(catalog,routes=[changed])
 try:m.validate("source",root,cat,changed,"after_promotion",state)
 except ValueError:ok("stale route generation cannot trigger promotion release",True)
 else:raise AssertionError
 (root/"mad4b-public-critical-css-v1/assets"/criticalfile).write_bytes(b"tampered")
 try:m.validate("source",root,catalog,route,"after_promotion",state)
 except ValueError:ok("tampered critical stylesheet cannot version cache",True)
 else:raise AssertionError
 (root/"mad4b-public-critical-css-v1/assets"/criticalfile).write_bytes(critical)
 for typ,id,path in [
  ('tour-types',625,'/tour-types/combined-tours/'),
  ('archive:tours-and-activities',0,'/tours-and-activities/')]:
  typed=dict(route,type=typ,id=id,path=path,revision=0)
  typedcat=dict(catalog,routes=[typed])
  ok('typed '+typ+' accepts exact WPML source before capture',
     m.validate('source',root,typedcat,typed,'before_capture')==path)
  market_state=dict(state,market_country='DE')
  typedcert=dict(cert,type=typ,object_id=id,path=path,market_country='DE',
                 dependencies=dict(cert['dependencies'],route_generation=0))
  typedkey=h(('tours.dev.mad4b.com|en|'+path+'|DE|EUR').encode())
  typedfile=root/'mad4b-public-critical-css-v1/certs'/(typedkey+'.json')
  typedfile.write_text(json.dumps(typedcert))
  ok('typed '+typ+' requires exact certified route, market, CSS and revision',
     m.validate('source',root,typedcat,typed,'after_promotion',market_state)==path)
 endpoint=root/".mad4b-purge/origin.sock"
 requests=[]
 ready=threading.Event()
 def server():
  with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as listener:
   listener.bind(str(endpoint));listener.listen(1);ready.set()
   conn,_=listener.accept()
   with conn:
    line=conn.makefile("rb").readline()
    requests.append(json.loads(line))
    conn.sendall(b'{"status":"ok","paths":1,"versioned":1}\n')
 thread=threading.Thread(target=server,daemon=True)
 thread.start();ready.wait(5)
 receipt=m.rotate(root,"/about-us/")
 thread.join(5)
 ok("native per-path generation socket acknowledges exactly one URL",
    requests==[["/about-us/"]] and receipt["versioned"]==1)
 print(f"PASS {len(checks)}/{len(checks)} per-route safe FastCGI refresh invariants")
