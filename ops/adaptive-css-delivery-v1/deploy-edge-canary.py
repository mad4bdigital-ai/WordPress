#!/usr/bin/python3
"""Exact-preimage, rollback-checked Nginx FastCGI cohort partition deployment.

Nginx default.conf is a read-only bind mount in the container: host-side
in-place writes preserve the existing mounted inode. No cache purge.
"""
import pathlib,os,hashlib,subprocess,stat,time,json
R=pathlib.Path('/var/lib/mad4b/company-code-release/preflight/adaptive-css-v1-20261121/edge-canary')
files={
 'source':{'live':pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf'),
  'candidate':R/'source.candidate.conf','container':'nginx-wkgtkzta35wkkgouwt6ruljc',
  'old':'e477977485c27cf2db2fb38a966c897e1f053a0831e866e2688fd224dbd5830e',
  'new':'db6fc0793795e963f7c7bba8264f859f3a3eb75af21dad4322e2315976b0b91d'},
 'wayfa':{'live':pathlib.Path('/opt/wayfa-rebuild-20261102/stack/nginx.conf'),
  'candidate':R/'wayfa.candidate.conf','container':'nginx-guxhp9y6vgh7oouuw8plxrlr',
  'old':'474db15578032774be2d2cb1cb964e9b5511210fcf65ff936a4e58f7e0ae277d',
  'new':'4df4c3c0a00ebf2e13b895472ce599b9dabda6a7cdf8fc609b585afe506880ac'}}
H=lambda b:hashlib.sha256(b).hexdigest()
assert os.geteuid()==0
base=pathlib.Path('/var/lib/mad4b/company-code-release/receipts/css-edge-canary-split-20261121-accepted')
old={}
for site,row in files.items():
 live=row['live'];cand=row['candidate']
 assert not live.is_symlink() and not cand.is_symlink() and live.stat().st_uid==0
 assert stat.S_IMODE(live.stat().st_mode)==0o644
 buf=live.read_bytes();assert H(buf)==row['old'],(site,H(buf))
 assert H(cand.read_bytes())==row['new'],site
 old[site]=buf
assert not base.exists()
base.mkdir(parents=True,mode=0o700,exist_ok=False)
for site,buf in old.items():
 target=base/(site+'-nginx.preimage.conf');target.write_bytes(buf);target.chmod(0o600)
def in_place(file,data):
 # Docker RO bind mount keeps a pinned original inode. Atomic rename would
 # replace only the host filename and NOT the container's mounted file.
 with file.open('r+b') as f:
  f.seek(0);f.write(data);f.truncate();f.flush();os.fsync(f.fileno())
def nginx(row,mode):
 r=subprocess.run(['/usr/bin/docker','exec',row['container'],'nginx',*(['-s','reload'] if mode=='reload' else ['-t'])],
  capture_output=True,text=True,timeout=17)
 if r.returncode:
  raise RuntimeError(f"{row['container']} nginx {mode} failed {r.stderr[-400:]}")
 return r.stderr[-150:]
try:
 for site,row in files.items():
  in_place(row['live'],row['candidate'].read_bytes())
  assert H(row['live'].read_bytes())==row['new']
 for site,row in files.items():
  nginx(row,'-t')
 for site,row in files.items():
  nginx(row,'reload')
 for site,row in files.items():
  assert H(row['live'].read_bytes())==row['new']
  # Nginx reload has started; active config can be verified by a public probe.
 print('NGINX_TWO_SITE_EDGE_CONFIG_RELOADED')
except BaseException:
 for site,row in files.items():
  in_place(row['live'],old[site])
 for site,row in files.items():
  assert H(row['live'].read_bytes())==row['old']
  nginx(row,'-t')
  nginx(row,'reload')
 print('NGINX_COHORT_EDGE_ROLLED_BACK_TO_PREIMAGE')
 raise
state={'contract':'mad4b.css.edge-partition-release.v1',
 'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
 'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com',
 'git_head':'c4b3c0aa44ba484dfdd10f2b5e80b31de313b292',
 'edge_assignment':'1pct_new_request_id_mobile_public_only',
 'fastcgi_canary_bypass':True,'fastcgi_canary_no_cache':True,
 'browser_canary_private_no_store':True,
 'origin_fastcgi_cohort_attested':True,
 'wordpress_certificates_modified':False,'wordpress_php_modified':False,
 'public_css_candidate_automatically_released':False,
 'rollback_preimages_retained':True,
 'nginx_config_sha256':{s:v['new'] for s,v in files.items()}}
p=base/'receipt.json';p.write_text(json.dumps(state,sort_keys=True,indent=2)+'\n');p.chmod(0o600)
print('EDGE_CANARY_PARTITION_PUBLISHED',json.dumps(state,sort_keys=True))
