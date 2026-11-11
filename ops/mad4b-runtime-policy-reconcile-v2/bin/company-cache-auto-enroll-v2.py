#!/usr/bin/env python3
"""Auto-enroll provisioned company in the existing MAD4B cache policy.

Never self-enroll from a hostname alone. Existing provisioner cache-warm
manifest + exact project-scoped Docker mounts + canonical MU fingerprints,
site-local php HTML contract, matching public Nginx edge policy and owner-bound
origin are prerequisites. New policy writes are atomic and never overwrite
manual per-company changes.
"""
import argparse,datetime,hashlib,json,os,re,subprocess,sys,tempfile
from pathlib import Path
from urllib.parse import urlsplit
ROOT=Path('/opt/mad4b-company-provisioning')
DEFAULT=ROOT/'policies/company-cache-coherence-v1.json'
MANIFESTS=Path('/etc/mad4b/cache-warm')
def fail(reason):raise ValueError(reason)
def sha(data):return hashlib.sha256(data).hexdigest()
def inspect(name):
 r=subprocess.run(['docker','inspect',name],capture_output=True,text=True,timeout=11)
 if r.returncode:fail('CONTAINER_NOT_FOUND_'+name)
 d=json.loads(r.stdout)
 if len(d)!=1 or not d[0].get('State',{}).get('Running'):fail('CONTAINER_NOT_RUNNING_'+name)
 return d[0]
def mount(item,dest):
 matches=[x['Source'] for x in item.get('Mounts',[]) if x.get('Destination')==dest and x.get('Type')=='bind']
 if len(matches)!=1:fail('MOUNT_NOT_UNIQUE_'+dest)
 root=Path(matches[0])
 if root.is_symlink() or not root.exists():fail('MOUNT_UNTRUSTED_'+dest)
 return root.resolve()
def edge_digest(cfg):
 blocks=[]
 for label in ('mad4b_tracked_cookie_safe_v2','mad4b_tracking_public_path_v2','mad4b_effective_cookie_unsafe_v2'):
  matches=re.findall(r'(?ms)^map [^\n]* \$'+re.escape(label)+r' \{\n.*?^\}',cfg)
  if len(matches)!=1:fail('UNSUPPORTED_EDGE_'+label)
  blocks.extend(matches)
 return sha('\n'.join(blocks).encode())
def discover(path,policy):
 if path.is_symlink() or not path.is_file():fail('MANIFEST_NOT_REGULAR')
 name=path.stem
 if not re.fullmatch(r'[a-z][a-z0-9-]{2,47}',name):fail('COMPANY_SLUG_INVALID')
 data=json.loads(path.read_text())
 project=data.get('project','')
 if not re.fullmatch(r'[a-z0-9]{15,40}',project):fail('PROJECT_ID_INVALID')
 url=data.get('expected_home')
 if not isinstance(url,str):fail('HOME_UNKNOWN')
 u=urlsplit(url)
 if u.scheme!='https' or u.port or u.username or u.password or u.path not in ('','/') or u.query or u.fragment:fail('HOME_NOT_CANONICAL')
 if not u.hostname or not re.fullmatch(r'[a-z0-9][a-z0-9.-]{2,150}',u.hostname):fail('HOSTNAME_INVALID')
 nginx=inspect('nginx-'+project);php=inspect('php-'+project);redis=inspect('redis-'+project)
 root=mount(nginx,'/var/www/html');phproot=mount(php,'/var/www/html')
 if phproot!=root:fail('SOURCE_PATH_NOT_SAME_AS_PHP')
 conf=mount(nginx,'/etc/nginx/conf.d/default.conf')
 if not root.is_dir() or not (root/'wp-config.php').is_file():fail('WORDPRESS_NOT_READY')
 if str(root) in [str(x.get('root')) for x in policy['company_sites'].values()]:fail('ALREADY_LINKED_ROOT')
 if url.rstrip('/') in [str(x.get('origin','')).rstrip('/') for x in policy['company_sites'].values()]:fail('ALREADY_LINKED_ORIGIN')
 if not (root/'wp-content/mu-plugins').is_dir():fail('MU_MODULES_MISSING')
 c=conf.read_text()
 if 'fastcgi_cache_valid 200 1800s;' not in c or 'max-age=1800, must-revalidate' not in c:fail('HTML_1800_POLICY_MISSING')
 h=edge_digest(c)
 mu=root/'wp-content/mu-plugins'
 for filename,digest in policy['modules_sha256'].items():
  f=mu/filename
  if f.is_symlink() or not f.is_file() or sha(f.read_bytes())!=digest:fail('MODULE_PIN_MISMATCH_'+filename)
 candidates=[
  ('mad4b-company-browser-html-policy.php','private const MAX_TTL=1800;'),
  ('mad4b-wayfa-public-html-cache.php','X-Accel-Expires: 1800')]
 matched=[(p,expected) for p,expected in candidates if (mu/p).is_file() and expected in (mu/p).read_text()]
 if len(matched)!=1:fail('PHP_PUBLIC_CONTRACT_AMBIGUOUS')
 # Never infer a missing Elementor file ID; check the exact deployed asset.
 cssdir=root/'wp-content/uploads/elementor/css'
 css=cssdir/'post-24780.css'
 if not css.is_file():
  alternatives=sorted(cssdir.glob('post-[0-9]*.css')) if cssdir.is_dir() else []
  if not alternatives:fail('CSS_PROBE_NOT_READY')
  css=alternatives[0]
 css_probe='/wp-content/uploads/elementor/css/'+css.name
 return {'name':name,'spec':{
  'root':str(root),'origin':url.rstrip('/'),
  'nginx_container':'nginx-'+project,'php_container':'php-'+project,'redis_container':'redis-'+project,
  'nginx_config':str(conf),'tour_path':'/','css_probe_path':css_probe,'edge_maps_sha256':h,
  'edge_model_scope':'home-and-published-tour-only',
  'enrollment_provenance':{'project':project,'manifest':str(path),'host':u.hostname,
    'mu_release_fingerprint':sha(json.dumps(policy['modules_sha256'],sort_keys=True).encode()),
    'utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}},
  'php_contract':{'filename':matched[0][0],'expected':matched[0][1]},
  'locales':['en']}
def atomic(path,body):
 fd,t=tempfile.mkstemp(prefix='.mad4b-cache-auto-enroll-',dir=path.parent)
 with os.fdopen(fd,'w') as f:
  json.dump(body,f,indent=2,sort_keys=True,ensure_ascii=False);f.write('\n');f.flush();os.fsync(f.fileno())
 os.chmod(t,0o600);os.replace(t,path)
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('--apply',action='store_true')
 ap.add_argument('--policy',default=str(DEFAULT))
 ap.add_argument('--manifests',default=str(MANIFESTS))
 ap.add_argument('--report',default=str(ROOT/'reports/cache-auto-enrollment-current.json'))
 a=ap.parse_args()
 p=Path(a.policy);policy=json.loads(p.read_text())
 assert policy.get('version')=='2.4.0' and policy.get('release_contract')=='mad4b.runtime-policy-release.v2'
 rows=[]
 directory=Path(a.manifests)
 for file in sorted(directory.glob('*.json')):
  if file.stem in policy['company_sites']:
   rows.append({'name':file.stem,'state':'ALREADY_ENROLLED'})
   continue
  try:
   candidate=discover(file,policy)
   rows.append({'name':file.stem,'state':'READY','candidate':candidate})
  except Exception as exc:
   rows.append({'name':file.stem,'state':'NEEDS_ENROLLMENT','reason':str(exc)[:130]})
 if a.apply:
  for row in rows:
   if row['state']!='READY':continue
   candidate=row.pop('candidate')
   name=candidate['name']
   policy['company_sites'][name]=candidate['spec']
   policy['site_php_public_cache_readbacks'][name]=candidate['php_contract']
   policy['cookie_decision_model']['sites'][name]=candidate['locales']
   row['state']='AUTO_ENROLLED'
  if any(x['state']=='AUTO_ENROLLED' for x in rows):atomic(p,policy)
 else:
  for row in rows:
   if row['state']=='READY':row.pop('candidate',None)
 summary={k:sum(x['state']==k for x in rows) for k in ('ALREADY_ENROLLED','AUTO_ENROLLED','READY','NEEDS_ENROLLMENT')}
 result={'contract':'mad4b.company-cache-auto-enrollment.v2','mode':'apply' if a.apply else 'audit','utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'summary':summary,'companies':rows}
 atomic(Path(a.report),result)
 print(json.dumps(result,ensure_ascii=False))
 return 2 if summary['NEEDS_ENROLLMENT'] else 0
if __name__=='__main__':sys.exit(main())
