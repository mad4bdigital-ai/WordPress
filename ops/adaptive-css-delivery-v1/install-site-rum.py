#!/usr/bin/python3
"""Additive, rollback-capable site-owned RUM install. Does not change CSS 421/422."""
from pathlib import Path
import hashlib,os,json,sys,time,tempfile,stat,shutil
site=sys.argv[1]
roots={'source':'/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy',
       'wayfa':'/opt/wayfa-rebuild-20261102/site-copy'}
if site not in roots:raise SystemExit('site not enrolled')
root=Path(roots[site])/'wp-content'
src=Path('/var/lib/mad4b/company-code-release/preflight/adaptive-css-v1-20261121')
assets=root/'mad4b-adaptive-css-v1/assets'
plugin=root/'mu-plugins/438-mad4b-adaptive-css-rum-v1.php'
receipt=Path('/var/lib/mad4b/company-code-release/receipts')/('adaptive-css-rum-'+site+'-20261121')
assert root.is_dir() and (root/'mu-plugins/429-mad4b-css-wpcron-dispatch-v1.php').is_file()
assert not plugin.exists() and not plugin.is_symlink()
assert not assets.parent.exists() and not receipt.exists()
H=lambda x:hashlib.sha256(x).hexdigest()
sources={
 '438-mad4b-adaptive-css-rum-v1.php':('8532bcfa545aba2f473d560c99b2dcf30667d0e8de669df3ef3ba55ccaf033a0',src/'438-mad4b-adaptive-css-rum-v1.php'),
 'mad4b-css-rum-client.js':('36833c7f1a6e1fcea186f16db51e06860c8e861cb8ee19d20910d207173b9544',src/'assets/mad4b-css-rum-client.js'),
 'web-vitals-6.2.3.iife.js':('1e5e9b9af6b8d71cfef508e5a869c53b4cf2bbccad8c9b5ac664c34e93f6151a',src/'assets/web-vitals-6.2.3.iife.js'),
 'web-vitals-LICENSE.txt':(None,src/'assets/web-vitals-LICENSE.txt')}
for name,(h,p) in sources.items():
 b=p.read_bytes()
 if h is not None:assert H(b)==h,(name,H(b))
 assert p.stat().st_uid==0 and not p.is_symlink()
assert os.geteuid()==0
receipt.mkdir(mode=0o700,parents=True,exist_ok=False)
created=[]
try:
 assets.parent.mkdir(mode=0o755)
 created.append(assets.parent)
 assets.mkdir(mode=0o755)
 created.append(assets)
 for name,(h,source) in sources.items():
  target=plugin if name.endswith('.php') else assets/name
  assert not target.exists()
  fd,tmp=tempfile.mkstemp(dir=target.parent,prefix='.'+name+'.')
  with os.fdopen(fd,'wb') as f:
   os.fchmod(f.fileno(),0o644)
   f.write(source.read_bytes());f.flush();os.fsync(f.fileno())
  os.replace(tmp,target)
  created.append(target)
  assert target.stat().st_uid==0 and stat.S_IMODE(target.stat().st_mode)==0o644
  assert H(target.read_bytes())==H(source.read_bytes())
 out={'site':site,'installed_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
      'mode':'off (not yet enabled)','sampled_rum':False,'css_v1_modified':False,
      'css_v2_modified':False,'fastcgi_purged':False,'wp_schema_modified':False,
      'files_sha256':{name:H(p.read_bytes()) for name,(_,p) in sources.items()},
      'github_branch':'feat/css-postrelease-certificates-20261120'}
 f=receipt/'receipt.json';f.write_text(json.dumps(out,indent=2,sort_keys=True)+'\n');f.chmod(0o600)
 print('SITE_RUM_OBSERVE_OFF_INSTALLED',json.dumps(out,sort_keys=True))
except Exception:
 for p in reversed(created):
  if p.is_dir():p.rmdir()
  else:p.unlink()
 if receipt.exists():shutil.rmtree(receipt)
 raise
