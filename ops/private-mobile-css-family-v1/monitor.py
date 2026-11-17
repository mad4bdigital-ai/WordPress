#!/usr/bin/env python3
"""Event-scoped unattended Critical CSS proof refresh for private mobile visitor preferences.

- No visitor cookies, values, or real personal data are read or logged.
- Only isolated synthetic country+passport combinations are used for browser QA.
- Checks SHA of currently *public certified* source CSS and policy.
- Rebuild only on changed CSS epoch / homepage graph / certified family source.
- Fail closed if real browser evidence, bundle integrity or site identity differs.
- NEVER grants shared HTML cache admission.
"""
import argparse,datetime,hashlib,json,os,pathlib,re,subprocess,sys,tempfile,urllib.parse,urllib.request
BASE=pathlib.Path('/opt/mad4b-company-provisioning/ops/private-mobile-css-family-v1')
CONTENT=pathlib.Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content')
MU=CONTENT/'mu-plugins'
OUTPUT=BASE/'evidence'
OUTPUT.mkdir(exist_ok=True)
POLICY=MU/'mad4b-private-mobile-css-family-v1.json'
QA_ROOT='/usr/src/app/mad4b-private-css-qa'
BROWSER='playwright-service-8ckuu8cyb4axyzkl3zqcn1z9'
PHP='php-guxhp9y6vgh7oouuw8plxrlr'
HOST='wayfatours.com'
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
PREFIX=2500000

def run(args, timeout=40, check=True):
 return subprocess.run(args,capture_output=True,text=True,timeout=timeout,check=check)

def sha(data):return hashlib.sha256(data).hexdigest()

def source_state():
 result={}
 for key in ['mad4b_css_ai_epoch_v1','mad4b_critical_css_home_dirty_v1']:
  x=run(['docker','exec',PHP,'sh','-lc',f'cd /var/www/html && wp --allow-root option get {key} 2>/dev/null'],timeout=32)
  v=x.stdout.strip()
  if not v.isdigit():raise RuntimeError('Invalid WordPress CSS dependency '+key)
  result[key]=int(v)
 return result

def source_bundle(language):
 uri='/' if language=='en' else '/es/'
 req=urllib.request.Request('https://'+HOST+uri,headers={
  'User-Agent':UA,'Accept':'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'
 })
 with urllib.request.urlopen(req,timeout=45) as res:
  if res.status!=200 or res.headers.get('X-MAD4B-Mobile-CSS-Ready')!='1':
   raise RuntimeError('Unsigned or uncertified public baseline '+language)
  if 'public' not in res.headers.get('Cache-Control','').lower():
   raise RuntimeError('Baseline is not anonymous public HTML')
  html=res.read(5000000).decode('utf-8','replace')
 tags=re.findall(r'<link[^>]*>',html,flags=re.I)
 candidates=[]
 for tag in tags:
  if 'id="mad4b-css-rest-v1"' not in tag:continue
  m=re.search(r'href="([^"]+)"',tag,flags=re.I)
  if not m:continue
  path=urllib.parse.urlparse(m.group(1)).path
  found=re.fullmatch(r'/wp-content/litespeed/css/([a-f0-9]{32}[.]css)',path)
  if found:candidates.append(found.group(1))
 if len(candidates)!=1:raise RuntimeError('Expected one public certified source bundle for '+language)
 return candidates[0]

def source_file(name):
 p=CONTENT/'litespeed/css'/name
 if not p.is_file() or p.is_symlink():
  p=CONTENT/'litespeed/.mad4b-asset-retention/css'/name
 if not p.is_file() or p.is_symlink():raise RuntimeError('Verified source CSS file absent: '+name)
 return p

def trusted_sources():
 manifest=CONTENT/'mad4b-critical-css-v1/manifest.json'
 if not manifest.is_file() or manifest.is_symlink() or manifest.stat().st_size>32768:
  raise RuntimeError('Original CSS provenance manifest unavailable')
 obj=json.loads(manifest.read_text())
 if obj.get('version')!=1 or obj.get('site')!=HOST:raise RuntimeError('Unbound CSS manifest')
 result={}
 for lang in ['en','es']:
  file=source_bundle(lang)
  data=source_file(file).read_bytes()
  src=sha(data)
  row=obj['entries'].get(src,{})
  if (row.get('gate_status')!='pass' or lang not in row.get('languages',[])
     or 'mobile' not in row.get('devices',[])):
   raise RuntimeError('No original qualified CSS proof for '+lang+' / '+src[:14])
  critical_name=row['file']
  if not re.fullmatch(r'[a-f0-9]{64}\.css',critical_name):
   raise RuntimeError('Invalid original Critical CSS path')
  asset=CONTENT/'mad4b-critical-css-v1'/critical_name
  if not asset.is_file() or asset.is_symlink() or sha(asset.read_bytes())!=row.get('critical_sha'):
   raise RuntimeError('Original Critical CSS hash mismatch '+lang)
  result[lang]=dict(source_sha=src,public_css_file=file,prefix_sha=sha(data[:PREFIX]),
     critical_sha=row['critical_sha'],critical_file=critical_name,asset=str(asset))
 return result

def existing_matches(deps,families):
 if not POLICY.is_file() or POLICY.is_symlink():return False
 try:p=json.loads(POLICY.read_text())
 except (OSError,ValueError):return False
 if p.get('contract')!='mad4b.private-mobile-css-family.v1' or p.get('site_host')!=HOST or p.get('status')!='certified':return False
 if p.get('css_epoch')!=deps['mad4b_css_ai_epoch_v1'] or p.get('home_dirty_generation')!=deps['mad4b_critical_css_home_dirty_v1']:return False
 for lang in ['en','es']:
  for key in ['source_sha','public_css_file','prefix_sha','critical_sha','critical_file']:
   if p.get('families',{}).get(lang,{}).get(key)!=families[lang][key]:return False
 return True

def verify_qa(qa,deps,families):
 assert qa.get('contract')=='mad4b.private-css-family.browser-candidate.v1'
 rows=qa['results']
 expected={(country,passport,locale,width) for country,passport in [('AE','GB'),('EG','auto'),('US','auto')]
           for locale in ['en','es'] for width in [320,390,414,767]}
 observed={(r['country'],r['passport'],r['lang'],r['width']) for r in rows}
 assert len(rows)==len(expected) and observed==expected
 for r in rows:
  assert r['pass'] and r['status']==200 and r['transformed']
  assert r['critical'] and r['async'] and r['inline']
  assert r['baseline']>=5 and r['candidate']>=5
  assert not r['pageErrors'] and not r['baselineErrors'] and not r['visualErrors']
  name=r['fullURL']
  assert re.fullmatch(r'[a-f0-9]{32}\.css',name)
  css=source_file(name).read_bytes()
  assert sha(css[:PREFIX])==families[r['lang']]['prefix_sha'],(name,r['lang'])
 state=source_state()
 assert state==deps,'CSS dependencies changed during browser proof'
 final=trusted_sources()
 assert all(final[x]['source_sha']==families[x]['source_sha']
        and final[x]['critical_sha']==families[x]['critical_sha'] for x in ['en','es']),\
    'Original CSS baseline changed during browser proof'

def qualify(deps,families):
 # Only this host may refresh the test-only browser container. No client cookies.
 for lang in ['en','es']:
  run(['docker','cp',families[lang]['asset'],f'{BROWSER}:{QA_ROOT}/{lang}-critical.css'],timeout=30)
 run(['docker','cp',str(BASE/'qa-family-candidate.cjs'),f'{BROWSER}:{QA_ROOT}/qa-family-candidate.cjs'],timeout=30)
 # Do not trust an earlier success if new browser proof fails.
 run(['docker','exec',BROWSER,'rm','-f',f'{QA_ROOT}/qa-report.json'],timeout=15)
 job=run(['docker','exec','-w','/usr/src/app',BROWSER,'timeout','780s','node',
          'mad4b-private-css-qa/qa-family-candidate.cjs'],timeout=790,check=False)
 (OUTPUT/'last-browser-run.log').write_text(job.stdout[-50000:]+ '\n'+job.stderr[-4000:])
 if job.returncode!=0:raise RuntimeError('Exact private CSS Chromium QA failed, old policy retained')
 run(['docker','cp',f'{BROWSER}:{QA_ROOT}/qa-report.json',str(OUTPUT/'last-browser-report.json')],timeout=30)
 qa=json.loads((OUTPUT/'last-browser-report.json').read_text())
 verify_qa(qa,deps,families)
 p={
  'contract':'mad4b.private-mobile-css-family.v1','site_host':HOST,
  'status':'certified','prefix_bytes':PREFIX,
  'css_epoch':deps['mad4b_css_ai_epoch_v1'],
  'home_dirty_generation':deps['mad4b_critical_css_home_dirty_v1'],
  'qa_viewports':[320,390,414,767],
  'qa_scenarios':['AE:GB','EG:auto','US:auto'],
  'qa_verified_cases':len(qa['results']),
  'qa_report_sha256':sha((OUTPUT/'last-browser-report.json').read_bytes()),
  'qa_evidence_verified_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'require_private_no_store':True,
  'families':{
   k:{x:v for x,v in row.items() if x!='asset'} for k,row in families.items()
  }
 }
 if p['site_host']!=HOST:raise RuntimeError('Cross-company policy refused')
 tmp=MU/('.mad4b-private-mobile-css-family-v1.json.next-'+str(os.getpid()))
 tmp.write_text(json.dumps(p,sort_keys=True,indent=2)+'\n')
 os.chmod(tmp,0o644)
 if POLICY.exists():
  original=OUTPUT/('before-policy-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'.json')
  original.write_bytes(POLICY.read_bytes())
 os.replace(tmp,POLICY)
 print(json.dumps({'status':'REQUALIFIED','cases':p['qa_verified_cases'],
       'epoch':p['css_epoch'],'dirty_generation':p['home_dirty_generation'],
       'languages':list(p['families'])}))

def daily_browser_health():
 receipt=OUTPUT/'daily-health.json'
 current=json.loads(POLICY.read_text())
 last={}
 if receipt.is_file():
  try:last=json.loads(receipt.read_text())
  except ValueError:pass
 now=datetime.datetime.now(datetime.timezone.utc)
 if (last.get('policy_sha')==sha(POLICY.read_bytes())
     and isinstance(last.get('verified_at'),str)):
  try:
   tested=datetime.datetime.fromisoformat(last['verified_at'])
   if (now-tested).total_seconds()<86400:return
  except ValueError:pass
 run(['docker','cp',str(BASE/'verify-live-chromium.cjs'),
      f'{BROWSER}:{QA_ROOT}/verify-live-chromium.cjs'],timeout=30)
 job=run(['docker','exec','-w','/usr/src/app',BROWSER,'timeout','180s','node',
          'mad4b-private-css-qa/verify-live-chromium.cjs'],timeout=195,check=False)
 (OUTPUT/'last-daily-health.log').write_text(job.stdout[-12000:]+ '\\n'+job.stderr[-3000:])
 if job.returncode!=0 or job.stdout.count('LIVE_CHROMIUM_PRIVATE ')!=4:
  raise RuntimeError('Daily private CSS live browser integrity failed, source retained for review')
 entries=[json.loads(line.split(' ',1)[1]) for line in job.stdout.splitlines()
          if line.startswith('LIVE_CHROMIUM_PRIVATE ')]
 if len(entries)!=4 or not all(r.get('pass') is True for r in entries):
  raise RuntimeError('Daily private CSS HTML/load proof incomplete')
 receipt.write_text(json.dumps({'policy_sha':sha(POLICY.read_bytes()),
                                 'verified_at':now.isoformat(),'checks':len(entries),
                                 'status':'passed'},indent=2)+'\\n')
 print(json.dumps({'status':'LIVE_PRIVATE_CSS_INTEGRITY_OK','checks':len(entries)}))

def main():
 parser=argparse.ArgumentParser()
 parser.add_argument('--check',action='store_true',help='only check, never qualify')
 a=parser.parse_args()
 # Safety: only original enrolled Wayfatours host, fixed WP content path.
 if not CONTENT.is_dir() or not (MU/'423-mad4b-private-mobile-css-family.php').is_file():
  raise RuntimeError('Original private CSS runtime not enrolled')
 deps=source_state()
 families=trusted_sources()
 if existing_matches(deps,families):
  if not a.check:daily_browser_health()
  print(json.dumps({'status':'UNCHANGED_CERTIFIED','site':HOST,'epoch':deps['mad4b_css_ai_epoch_v1']}));return
 if a.check:
  print(json.dumps({'status':'REQUALIFICATION_REQUIRED','site':HOST,'epoch':deps['mad4b_css_ai_epoch_v1']}));return
 load=os.getloadavg()[0];limit=max(4.0,1.3*(os.cpu_count() or 2))
 if load>limit:raise RuntimeError('CPU admission postponed: '+str(round(load,2)))
 qualify(deps,families)

if __name__=='__main__':
 try:main()
 except Exception as e:
  print(json.dumps({'status':'HOLD_NATIVE_FALLBACK','reason':str(e)[:200]}),file=sys.stderr)
  sys.exit(1)
