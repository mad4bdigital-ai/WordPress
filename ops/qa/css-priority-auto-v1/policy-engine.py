#!/usr/bin/env python3
"""Staging-only, fail-closed, source-bound CSS policy staging and certification."""
import os,sys,json,hashlib,re,statistics,time,tempfile,subprocess
from pathlib import Path
ROOT=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging')
SITE=ROOT/'site-copy'
POLICY=SITE/'wp-content/mu-plugins/mad4b-css-autonomous-policy.json'
QA=ROOT/'qa/css-priority-auto-v1'
HOST='tours.dev.mad4b.com'; UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a'; LANGS=('en','ar','fr','de','es','it')
EXPECTED={'contract':'mad4b.dynamic-css.local.v1','site_host':HOST,'site_uuid':UUID}

def read(path):return json.loads(Path(path).read_text())
def save_atomic(path,obj):
 path=Path(path); data=json.dumps(obj,sort_keys=True,indent=2,ensure_ascii=False)+'\n'
 if len(data)>100000:raise ValueError('Manifest exceeds bound')
 fd,name=tempfile.mkstemp(prefix='.css-policy-',dir=path.parent)
 try:
  with os.fdopen(fd,'w') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.chmod(name,0o640)
  if os.geteuid()==0:os.chown(name,0,33)
  os.replace(name,path)
 finally:
  if os.path.exists(name):os.unlink(name)

def scope():
 cmd=['docker','exec','-u','www-data','etg-commerce-staging-php','sh','-lc',"cd /var/www/html; wp eval 'echo site_url().\"|\".wp_get_environment_type().\"|\".(get_option(\"mad4b_scp_brand_context_profile_v1\",[])[\"site_uuid\"]??\"\");' --quiet"]
 r=subprocess.run(cmd,capture_output=True,text=True,timeout=25,check=True)
 if r.stdout.strip()!='https://'+HOST+'|staging|'+UUID:raise RuntimeError('STAGING_IDENTITY_GUARD_FAILED: '+r.stdout[:150])

def epoch():
 cmd=['docker','exec','-u','www-data','etg-commerce-staging-php','sh','-lc',
      "cd /var/www/html; wp eval 'echo (int)get_option(\"mad4b_css_ai_epoch_v1\",1);' --quiet"]
 r=subprocess.run(cmd,capture_output=True,text=True,timeout=22,check=True)
 val=int(r.stdout.strip())
 if val<1:raise RuntimeError('Bad policy epoch')
 return val

def manifest():
 doc=read(POLICY)
 if any(doc.get(k)!=v for k,v in EXPECTED.items()):raise RuntimeError('Manifest identity mismatch')
 if not isinstance(doc.get('routes'),dict):raise RuntimeError('Missing routes')
 return doc

def check_path(p):
 if not isinstance(p,str) or not re.fullmatch(r'/[a-zA-Z0-9/_~.-]*',p) or '//' in p or '..' in p or len(p)>250:raise ValueError('Unsafe path')
 return p

def language(path):
 bits=path.strip('/').split('/');return bits[0] if bits[0] in LANGS[1:] else 'en'

def routekey(path):return hashlib.sha256((language(path)+'|'+path).encode()).hexdigest()

def stage(path):
 check_path(path);scope();doc=manifest();report=read(QA/'scout-result.json')
 if report.get('path')!=path or report.get('success') is not True:raise RuntimeError('Scout mismatched/failed')
 if len(report.get('samples',[]))!=2 or any(x.get('route')!=path or x.get('status')!=200 for x in report['samples']):raise RuntimeError('Unverified browser route')
 safe={};base=(SITE/'wp-content').resolve()
 for handle,asset in report.get('eligible',{}).items():
  if not re.fullmatch(r'[a-z0-9_-]{3,90}',handle) or not isinstance(asset,dict):continue
  rel=asset.get('relpath','')
  if not re.fullmatch(r'/wp-content/(?:plugins|themes|mu-plugins)/[A-Za-z0-9/_-]+\.css',rel):continue
  p=(SITE/rel.lstrip('/')).resolve()
  if not p.is_file() or not p.is_relative_to(base):continue
  if asset.get('tier') not in ('idle','below-fold'):continue
  safe[handle]={'relpath':rel,'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'tier':asset['tier']}
 safe=dict(list(safe.items())[:12]);key=routekey(path)
 now=int(time.time())
 if not safe:
  doc['routes'].pop(key,None);save_atomic(POLICY,doc)
  print(json.dumps({'path':path,'result':'native-only','reason':'no_verified_css_assets'}));return 2
 digest=hashlib.sha256(json.dumps(safe,sort_keys=True).encode()).hexdigest()
 prev=doc['routes'].get(key,{})
 trials=prev.get('trials',[]) if prev.get('source_digest')==digest else []
 # Recover already-observed failure when upgrading from the nonadaptive v1 gate.
 if not trials and prev.get('status')=='hold' and prev.get('assets')==safe:
  trials=[{'handles':sorted(safe),'result':'HOLD','issues':prev.get('issues',[])}]
 failures={tuple(t['handles']) for t in trials if t.get('result')=='HOLD' and isinstance(t.get('handles'),list)}
 keys=list(safe)
 proposals=[tuple(sorted(keys))]
 # Bounded beam search: evaluate smaller independent subsets only when the full set failed.
 if len(keys)>2:
  mid=max(1,len(keys)//2)
  proposals.extend([tuple(sorted(keys[:mid])),tuple(sorted(keys[mid:]))])
 proposals.extend((item,) for item in keys)
 proposals=list(dict.fromkeys(proposals))
 if prev.get('status')=='certified' and prev.get('source_digest')==digest:
  proposals.insert(0,tuple(sorted(prev['assets'])))
 selected=next((subset for subset in proposals if subset not in failures),None)
 if selected is None:
  prev.update({'path':path,'locale':language(path),'status':'hold','expires':now-1,'issues':['all_candidate_subsets_failed'], 'trials':trials,'source_digest':digest})
  doc['routes'][key]=prev;save_atomic(POLICY,doc)
  print(json.dumps({'path':path,'result':'native-only','reason':'all_candidate_subsets_failed'}));return 2
 chosen={name:safe[name] for name in selected}
 doc['routes'][key]={'path':path,'locale':language(path),'status':'candidate','source_version':'4.3.5/7.9.2','assets':chosen,'expires':now+86400,'created':now,'epoch':epoch(),'source_digest':digest,'trials':trials}
 if len(doc['routes'])>120:raise RuntimeError('Manifest full; cannot expand')
 save_atomic(POLICY,doc)
 print(json.dumps({'path':path,'status':'candidate','assets':list(chosen),'tested_subsets':len(trials)}));return 0

def p75(values):
 a=sorted(values);return a[min(len(a)-1,max(0,int((len(a)-1)*0.75+0.5)))] if a else None

def verify(path):
 scope();check_path(path);doc=manifest();key=routekey(path);entry=doc['routes'].get(key)
 if not entry or entry.get('path')!=path or entry.get('status') not in ('candidate','certified'):raise RuntimeError('No staged route')
 data=read(QA/'benchmark-result.json')
 if data.get('path')!=path:raise RuntimeError('A/B path mismatch')
 rows=data.get('observations',[]);issues=[]
 for mobile in [False,True]:
  a=sorted([x for x in rows if x.get('mobile')==mobile and x.get('mode')=='native-v1'],key=lambda x:x.get('round',0))
  b=sorted([x for x in rows if x.get('mobile')==mobile and x.get('mode')=='canary-v1'],key=lambda x:x.get('round',0))
  if len(a)<3 or len(b)<3:issues.append('insufficient_repeats_'+str(mobile));continue
  for x in a+b:
   if x.get('status')!=200 or x.get('path')!=path or x.get('error') or x.get('failures') or not x.get('allReady',False):issues.append('failed_http_or_styles_'+str(mobile))
  if any((x.get('tags') or 0)<1 for x in b):issues.append('missing_candidate_css_'+str(mobile))
  if any((x.get('tags') or 0)>0 for x in a):issues.append('baseline_contaminated_'+str(mobile))
  for k in ('lcp','fcp'):
   if any(not x.get(k) for x in a+b):issues.append('missing_'+k);continue
   delta=statistics.median(x[k] for x in b)-statistics.median(x[k] for x in a)
   if delta>max(250,0.10*statistics.median(x[k] for x in a)):issues.append('regression_'+k+'_'+str(mobile))
  clsA=[x.get('cls',1.0) for x in a];clsB=[x.get('cls',1.0) for x in b]
  if p75(clsB)>0.12 or p75(clsB)>p75(clsA)+0.015:issues.append('cls_gate_'+str(mobile))
  # Stable first-frame geometry and styles, without relying on variable card IDs.
  for na,nb in zip(a,b):
   for j in (0,1,2,3):
    g1=(na.get('top') or [None]*5)[j];g2=(nb.get('top') or [None]*5)[j]
    if g1 is None or g2 is None:continue
    if g1[2:]!=g2[2:] or abs(g1[0]-g2[0])>3 or abs(g1[1]-g2[1])>3:
     issues.append('geometry_'+str(mobile));break
 unique=sorted(set(issues));out={'timestamp':int(time.time()),'path':path,'observations':len(rows),'result':'HOLD' if unique else 'PASS','issues':unique,'css_assets':list(entry['assets'])}
 (QA/'last-verdict.json').write_text(json.dumps(out,indent=2)+'\n')
 # Drift check: don't certify a source that changed during browser run.
 fresh=manifest();existing=fresh['routes'].get(key)
 if existing!=entry:raise RuntimeError('Concurrent policy change; stop')
 if int(existing.get('epoch',0))!=epoch():raise RuntimeError('WordPress content changed during A/B; no certification')
 existing['trials']=(existing.get('trials',[])+[{'handles':sorted(existing['assets']),'result':'HOLD' if unique else 'PASS','issues':unique,'measured':int(time.time())}])[-32:]
 if unique:
  # A previously certified route loses trust and safely returns to native.
  existing['status']='hold';existing['expires']=int(time.time())-1;existing['issues']=unique[:12]
 else:
  existing['status']='certified';existing['expires']=int(time.time())+3*86400;existing['evidence']='three_paired_repeats_both_viewports';existing.pop('issues',None)
 save_atomic(POLICY,fresh)
 print(json.dumps(out));return 0 if not unique else 5

if __name__=='__main__':
 try:
  verb=sys.argv[1];path=sys.argv[2] if len(sys.argv)>2 else '/'
  if verb=='stage':sys.exit(stage(path))
  if verb=='verify':sys.exit(verify(path))
  if verb=='status':scope();d=manifest();print(json.dumps({'routes':len(d['routes']),'status':{k:v.get('status') for k,v in d['routes'].items()}}));sys.exit(0)
  raise ValueError('Expected stage|verify|status')
 except Exception as e:print('CSS_POLICY_FAIL_CLOSED: '+str(e),file=sys.stderr);sys.exit(11)
