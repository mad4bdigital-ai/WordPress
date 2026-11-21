#!/usr/bin/python3
"""Transactional two-company, source-pinned first-party CSS runner release.

Must run as root holding shared/css site/browser locks. Does NOT run Chromium,
WP-CLI, purge cache, change public CSS/PHP or apply certificates.
"""
import os,stat,ast,runpy,hashlib,json,tempfile,time
from pathlib import Path
BASE=Path('/var/lib/mad4b/company-code-release/preflight/adaptive-css-v1-20261121')
FILES={
 'source':{
  'base':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/operations/css-cron-v1'),
  'candidate':BASE/'renderer-pin-reconcile/source-canonical.candidate.py',
  'old':'20a422b06b2343b57a8383037fbd73d70860eb5f222644d6e02826e2a05c68cc',
  'new':'7769a7d5980b61adef1d37716bedb6cafd9f29d091406c1d0d985a3c4607ae8a',
  'digest':'5d6afea94da77784ffb79a28f2786e85e25e646d22d31e6641df86c845ccb2da',
  'wp':Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content')},
 'wayfa':{
  'base':Path('/opt/wayfa-rebuild-20261102/operations/css-cron-v1'),
  'candidate':BASE/'renderer-pin-reconcile/wayfa.candidate.py',
  'old':'8e9f4b0f3507d0050c0168737e4a376275f5ab4d5a2719ab0e35399e1545a7cc',
  'new':'4d0e00b35764d8a700f0d6220fbf9e04ca07c64360e7522bba96e0fb879462f6',
  'digest':'e11091ce61d055771b449d402db93933e356c3cc4303079f1edf8bc196622964',
  'wp':Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')}}
PHP={
 '421-mad4b-public-critical-css-coverage.php':'ae7936c7ad09b059375e1960d4c59144af0b4325933db34bcfa63c26e49233bb',
 '422-mad4b-public-critical-css-runtime.php':'13e98efebaef87918d38c1d5be30bd6cf45c9f40c0e3264a31beca79b4663a36',
 '422-mad4b-public-critical-css-v2-consumer.php':'249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1'}
H=lambda b:hashlib.sha256(b).hexdigest()
def digest_fn(worker):
 tree=ast.parse(worker.read_text())
 funcs=[node for node in tree.body if isinstance(node,ast.FunctionDef)
        and node.name=='renderer_digest']
 assert len(funcs)==1
 env={'hashlib':hashlib,'Path':Path,'stat':stat}
 exec(compile(ast.Module(body=funcs,type_ignores=[]),str(worker),'exec'),env)
 return env['renderer_digest']
def verified_runtime_pins(candidate,site,wp):
 tree=ast.parse(candidate.read_text())
 funcs=[node for node in tree.body if isinstance(node,ast.FunctionDef)
        and node.name in ('approved_native_runtime_sha','approved_v2_consumer_sha')]
 assert len(funcs)==2
 env={'hashlib':hashlib,'Path':Path,'CONFIG':{site:('host','container',wp,'env')}}
 exec(compile(ast.Module(body=funcs,type_ignores=[]),str(candidate),'exec'),env)
 actual=env['approved_native_runtime_sha'](site,wp)
 assert actual==PHP['422-mad4b-public-critical-css-runtime.php']
 consumer=env['approved_v2_consumer_sha'](site,wp,actual)
 assert consumer==PHP['422-mad4b-public-critical-css-v2-consumer.php']
 return actual
def atom(path,b,mode):
 fd,tmp=tempfile.mkstemp(prefix='.'+path.name+'.',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as f:
   os.fchmod(f.fileno(),mode)
   f.write(b);f.flush();os.fsync(f.fileno())
  os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
assert os.geteuid()==0
receipt=Path('/var/lib/mad4b/company-code-release/receipts/css-git-native-pair-reconcile-20261121')
before={}
for site,info in FILES.items():
 root=info['base'];runner=root/'renderer/run-public-route.py'
 worker=root/'worker.py';profile=root/'site-profile.json'
 assert not any(x.is_symlink() for x in (root,runner,profile,worker,info['candidate']))
 assert worker.stat().st_uid==0 and H(worker.read_bytes())=='a9c7c885c955a9fe4c16c509abab12abb86e0bb708a01b7e7a8b8914ff3bbeec'
 assert H(runner.read_bytes())==info['old']
 assert H(info['candidate'].read_bytes())==info['new']
 assert runner.stat().st_uid==0 and stat.S_IMODE(runner.stat().st_mode)==0o644
 assert profile.stat().st_uid==0 and stat.S_IMODE(profile.stat().st_mode)==0o600
 data=json.loads(profile.read_text())
 assert data['renderer_sha256']==info['digest'] and data['site_id']==site
 assert digest_fn(worker)(root/'renderer')==info['digest']
 for name,sha in PHP.items():
  target=info['wp']/'mu-plugins'/name
  assert target.is_file() and not target.is_symlink() and H(target.read_bytes())==sha
 verified_runtime_pins(info['candidate'],site,info['wp'])
 before[site]={'runner':runner.read_bytes(),'profile':profile.read_bytes(),
  'profile_data':data,'worker':worker}
print('TWO_SITES_PREIMAGE_SOURCE_GIT_PHP_AND_V2_COMPANION_VERIFIED',flush=True)
assert not receipt.exists()
receipt.mkdir(parents=True,mode=0o700,exist_ok=False)
for site,data in before.items():
 for part in ('runner','profile'):
  backup=receipt/(site+'-'+part+'.preimage')
  backup.write_bytes(data[part]);backup.chmod(0o600)
done=[]
try:
 for site,info in FILES.items():
  runner=info['base']/'renderer/run-public-route.py'
  atom(runner,info['candidate'].read_bytes(),0o644)
  done.append((site,'runner'))
  assert H(runner.read_bytes())==info['new']
  new_digest=digest_fn(before[site]['worker'])(info['base']/'renderer')
  p=before[site]['profile_data'].copy()
  p['renderer_sha256']=new_digest
  profile=info['base']/'site-profile.json'
  atom(profile,(json.dumps(p,indent=2,ensure_ascii=False)+'\n').encode(),0o600)
  done.append((site,'profile'))
  verified_runtime_pins(runner,site,info['wp'])
  module=runpy.run_path(str(before[site]['worker']),run_name='mad4b_css_release_admission')
  assert module['SITE']==site and module['PROFILE']['renderer_sha256']==new_digest
  assert module['renderer_digest'](info['base']/'renderer')==new_digest
  print('ADMITTED',site,'renderer_digest',new_digest,flush=True)
 for site,info in FILES.items():
  assert H((info['base']/'renderer/run-public-route.py').read_bytes())==info['new']
 state={'contract':'mad4b.css.original-git-source-pair-reconcile.v1',
   'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
   'git_head':'7db69c11fac585cb23b05d482bae26d173f3cbd4',
   'php_files_changed':False,'public_css_changed':False,
   'fastcgi_changed':False,'css_certificates_changed':False,
   'original_421_422_companion_verified':True,
   'workers':{site:{'run_public_route_sha':i['new'],
      'renderer_digest':json.loads((i['base']/'site-profile.json').read_text())['renderer_sha256']}
      for site,i in FILES.items()},
   'rollback_preimages_present':True}
 f=receipt/'receipt.json';f.write_text(json.dumps(state,sort_keys=True,indent=2)+'\n');f.chmod(0o600)
 print('CSS_GIT_NATIVE_PAIR_TRANSACTION_COMMITTED',json.dumps(state,sort_keys=True),flush=True)
except BaseException:
 for site,part in reversed(done):
  dst=FILES[site]['base']/('renderer/run-public-route.py' if part=='runner' else 'site-profile.json')
  atom(dst,before[site][part],0o644 if part=='runner' else 0o600)
 for site,info in FILES.items():
  assert H((info['base']/'renderer/run-public-route.py').read_bytes())==info['old']
  assert digest_fn(before[site]['worker'])(info['base']/'renderer')==info['digest']
 print('CSS_GIT_NATIVE_PAIR_ROLLED_BACK_BOTH_SITES',flush=True)
 raise
