from pathlib import Path
import hashlib,importlib.util,json,sys
p=Path(sys.argv[1])/'wayfa-home-cache-refresh.py'
spec=importlib.util.spec_from_file_location('wayfa_cc',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
conf='''map $request_uri $wayfa_home_firstpaint_epoch {
    default "";
    ~^/(?:es/)?$ "|pref-firstpaint-v1";
}
location /tours/ { try_files $uri /index.php?$args; }
'''
sha='a'*64;csha='b'*64
manifest={'site':'wayfatours.com','version':1,'entries':{
 sha:{'gate_status':'pass','languages':['en'],'critical_sha':csha},
 'c'*64:{'gate_status':'pass','languages':['es'],'critical_sha':'d'*64}
}}
inventory={'host':'wayfatours.com','contract':'mad4b.mobile-critical.candidates.v1',
 'errors':[],'candidates':[{'lang':'en','source_sha':sha,'critical_sha':csha},
 {'lang':'es','source_sha':'c'*64,'critical_sha':'d'*64}]}
checks=[]
revision=m.derive_manifest_epoch(manifest,inventory)
checks.append(('company proof produces bounded epoch',len(revision)==16))
out,changed=m.rewrite_home_map(conf,revision)
checks.append(('home-only version bump',changed and 'location /tours/' in out and out.count('-cc-')==1))
new,changed=m.rewrite_home_map(out,revision)
checks.append(('idempotent after promotion',not changed and new==out))
different=m.derive_manifest_epoch({**manifest,'entries':{**manifest['entries'],'e'*64:{'gate_status':'hold'}}},inventory)
checks.append(('new manifest changes epoch',revision!=different))
try:m.derive_manifest_epoch({**manifest,'site':'example.com'},inventory)
except ValueError:checks.append(('cross-company certificate denied',True))
try:m.derive_manifest_epoch(manifest,{**inventory,'candidates':inventory['candidates'][:1]})
except ValueError:checks.append(('incomplete locale certificate denied',True))
try:m.rewrite_home_map(conf+'\\n'+conf,revision)
except ValueError:checks.append(('duplicate cache maps refused',True))
for name,ok in checks:
 assert ok,name
 print('PASS',name)
print('PASS %s/%s home-only FastCGI cache version contracts'%(len(checks),len(checks)))
