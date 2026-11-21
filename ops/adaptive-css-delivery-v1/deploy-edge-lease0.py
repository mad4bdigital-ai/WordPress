#!/usr/bin/python3
"""Atomic-in-effect production Nginx rollout gate: 0% unless source release lease.

Host and Nginx RO bind file must be the same inode. Locks must be held externally.
No CSS/WordPress changes. On failure rollback both inodes and reload.
"""
import pathlib,os,hashlib,subprocess,json,time,stat
R=pathlib.Path('/var/lib/mad4b/company-code-release/preflight/adaptive-css-v1-20261121/edge-canary')
DATA={
'source':{'live':pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/stack/nginx.conf'),
'next':R/'source.lease0.candidate.conf','nginx':'nginx-wkgtkzta35wkkgouwt6ruljc',
'old':'db6fc0793795e963f7c7bba8264f859f3a3eb75af21dad4322e2315976b0b91d',
'new':'accb96666fe3817749134bcea42d63e694c4dda88a53744cbf1d4dc06acb24dd'},
'wayfa':{'live':pathlib.Path('/opt/wayfa-rebuild-20261102/stack/nginx.conf'),
'next':R/'wayfa.lease0.candidate.conf','nginx':'nginx-guxhp9y6vgh7oouuw8plxrlr',
'old':'4df4c3c0a00ebf2e13b895472ce599b9dabda6a7cdf8fc609b585afe506880ac',
'new':'32a511554d198db91f574b9e4a85b3fdd8fd3930bfba4f85a8102725c6b40885'}}
H=lambda b:hashlib.sha256(b).hexdigest()
BACK=pathlib.Path('/var/lib/mad4b/company-code-release/receipts/css-edge-release-lease0-20261121')
assert os.geteuid()==0 and not BACK.exists()
original={}
for site,x in DATA.items():
 f=x['live']
 assert f.is_file() and f.stat().st_uid==0 and not f.is_symlink() and stat.S_IMODE(f.stat().st_mode)==0o644
 original[site]=f.read_bytes()
 assert H(original[site])==x['old'],(site,H(original[site]))
 assert x['next'].is_file() and not x['next'].is_symlink() and H(x['next'].read_bytes())==x['new']
 # The current Nginx container must see the precise host preimage.
 out=subprocess.run(['docker','exec',x['nginx'],'sha256sum','/etc/nginx/conf.d/default.conf'],
     capture_output=True,text=True,timeout=10,check=True).stdout.split()[0]
 assert out==x['old'],(site,'stale bind inode',out)
BACK.mkdir(parents=True,mode=0o700,exist_ok=False)
for site,preimage in original.items():
 p=BACK/(site+'.before.conf');p.write_bytes(preimage);p.chmod(0o600)
def put(p,b):
 # In-place edit is essential for an existing Docker RO bind mount.
 with p.open('r+b') as out:
  out.write(b);out.truncate();out.flush();os.fsync(out.fileno())
def nginx(site,command):
 args=['docker','exec',DATA[site]['nginx'],'nginx']+(
      ['-s','reload'] if command=='reload' else ['-t'])
 r=subprocess.run(args,capture_output=True,text=True,timeout=16)
 if r.returncode:raise RuntimeError((site,command,r.stderr[-330:]))
try:
 for site,x in DATA.items():
  put(x['live'],x['next'].read_bytes())
  assert H(x['live'].read_bytes())==x['new']
 for site in DATA:nginx(site,'test')
 for site in DATA:nginx(site,'reload')
 for site,x in DATA.items():
  live=subprocess.run(['docker','exec',x['nginx'],'sha256sum','/etc/nginx/conf.d/default.conf'],
       capture_output=True,text=True,timeout=10,check=True).stdout.split()[0]
  assert live==x['new']
except BaseException:
 for site,x in DATA.items():put(x['live'],original[site])
 for site in DATA:
  nginx(site,'test');nginx(site,'reload')
 print('NGINX_SOURCE_LEASE0_ROLLED_BACK_TO_TWO_PREIMAGES',flush=True)
 raise
receipt={'contract':'mad4b.css.edge-source-release-gate.v1',
 'at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),
 'source_and_wayfa':True,'css_exposure_without_lease_percent':0,
 'conditional_split_percent':1,'selector':'nginx_request_id',
 'candidate_release_authority':'none_issued_yet',
 'fastcgi_cohort_isolation_retained':True,
 'current_css_certificates_unchanged':True,'wp_files_changed':False,
 'nginx_sha256':{s:d['new'] for s,d in DATA.items()},
 'root_only_preimages':True}
p=BACK/'receipt.json';p.write_text(json.dumps(receipt,indent=2,sort_keys=True)+'\n');p.chmod(0o600)
print('CSS_SOURCE_LEASE0_RELEASE_SUCCESS',json.dumps(receipt,sort_keys=True),flush=True)
