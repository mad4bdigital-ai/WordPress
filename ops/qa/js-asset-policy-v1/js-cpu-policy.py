#!/usr/bin/env python3
"""MAD4B Staging: CPU evidence -> bounded per-device JS experiments (never auto-publish)."""
import hashlib,json,sys,statistics,re,time,os,tempfile
from pathlib import Path
Q=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1')
HOST='tours.dev.mad4b.com';UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
CATEGORIES={
 'clarity':('clarity.ms','clarity.js'),
 'youtube':('youtube.com','youtube-nocookie.com','ytimg.com'),
 'google_analytics':('googletagmanager.com','google-analytics.com'),
 'meta_pixel':('connect.facebook.net','fbevents.js'),
 'jet_widgets':('/jet-blog/','/jet-woo-builder/','/jet-elements/','getcurrentdevicemode'),
 'jquery':('/jquery.min.js','jquery-core'),
}
THRESHOLD={'clarity':55,'youtube':55,'jet_widgets':70,'google_analytics':55,'meta_pixel':55,'jquery':60}
EXPERIMENT={'clarity':'test_post_first_paint_telemetry','youtube':'test_interaction_preview','jet_widgets':'test_visible_widget_only_initialization','google_analytics':'test_noncritical_tag_scheduling','meta_pixel':'test_noncritical_pixel_scheduling','jquery':'diagnose_jquery_callers_not_defer'}
def clean_url(value):
 if not isinstance(value,str) or len(value)>900:return ''
 if value.startswith(('(anonymous','(','internal')):return ''
 return value.split('?')[0].lower()
def bucket(uri):
 for kind,markers in CATEGORIES.items():
  if any(m in uri for m in markers):return kind
 return 'first_party_other' if uri.startswith(('/','https://'+HOST)) else 'other'
def validate(identity,device,path='/'):
 if not isinstance(identity,dict) or identity.get('site')!='https://'+HOST or identity.get('env')!='staging' or identity.get('uuid')!=UUID:raise ValueError('site guard')
 if device not in ('desktop','mobile') or not re.fullmatch(r'/[A-Za-z0-9/_~.\-]{0,239}',path) or '..' in path:raise ValueError('device/path guard')
 if re.search(r'/(?:cart|checkout|booking|account|my-account|wp-admin|wp-json)(?:/|$)',path,re.I):raise ValueError('sensitive route')
 if str(identity.get('defer'))!='0' or str(identity.get('mobile_cache'))!='1':raise ValueError('LiteSpeed guard')
 if identity.get('guest') not in ('0','1') or not isinstance(identity.get('epoch'),int):raise ValueError('source binding guard')
def plan(identity,observations,history=None,path='/'):
 out={'contract':'mad4b.js.cpu-plan.v1','site_uuid':UUID,'path':path,'created_at':int(time.time()),'devices':{},'runtime_mode':'native','auto_apply':False}
 for device in ('desktop','mobile'):
  validate(identity,device,path)
  matches=[o for o in observations if o.get('mobile')==(device=='mobile') and o.get('status')==200 and o.get('path',path)==path and not o.get('errors') and isinstance(o.get('samples'),int) and o['samples']>=100]
  fingerprint=hashlib.sha256(json.dumps({'site':UUID,'path':path,'device':device,'epoch':identity['epoch'],'guest':identity['guest'],'options':identity.get('js_options'),'asset_digest':identity.get('asset_digest')},sort_keys=True).encode()).hexdigest()
  denied={(v.get('fingerprint'),v.get('experiment')) for v in (history or []) if v.get('result')=='HOLD'}
  cat={k:[] for k in CATEGORIES};details=[]
  for o in matches:
   seen={k:0. for k in CATEGORIES}
   for node in o.get('top',[]):
    uri=clean_url(node.get('url'));ms=node.get('ms',0)
    if not uri or type(ms) not in (int,float) or not 0<=ms<=20000:continue
    kind=bucket(uri)
    if kind in seen:seen[kind]+=float(ms)
    if kind!='other' and ms>=10:details.append({'source':uri[:160],'category':kind,'self_cpu_ms':round(ms,1)})
   for kind in CATEGORIES:cat[kind].append(seen[kind])
  ordered=[]
  for kind,values in cat.items():
   if not values:continue
   median=statistics.median(values)
   if median<THRESHOLD[kind]:continue
   name=EXPERIMENT[kind]
   if (fingerprint,name) in denied:continue
   ordered.append({'experiment':name,'category':kind,'median_self_cpu_ms':round(median),'state':'DIAGNOSTIC_ONLY' if kind=='jquery' else 'CANDIDATE_REQUIRES_AB',
    'constraints':['no global defer','native LiteSpeed ownership','5 paired trials','UX and conversion event parity']})
  ordered.sort(key=lambda x:(x['state']=='DIAGNOSTIC_ONLY',-x['median_self_cpu_ms']))
  out['devices'][device]={'source_fingerprint':fingerprint,'samples':len(matches),'status':'MEASURED' if matches else 'NO_VALID_EVIDENCE',
   'hotspots':sorted(details,key=lambda x:-x['self_cpu_ms'])[:14], 'experiments':ordered[:6],
   'recommended_action':ordered[0]['experiment'] if ordered else 'retain_native_wait_for_evidence'}
 return out
def atomic(path,doc):
 fd,n=tempfile.mkstemp(prefix='.js-cpu-plan-',dir=path.parent)
 try:
  with os.fdopen(fd,'w') as f:json.dump(doc,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
  os.chmod(n,0o640)
  if os.geteuid()==0:os.chown(n,0,33)
  os.replace(n,path)
 finally:
  if os.path.exists(n):os.unlink(n)
if __name__=='__main__':
 if len(sys.argv)!=4 or sys.argv[1] not in ('check','publish'):raise SystemExit('usage: js-cpu-policy.py check|publish identity.json profile.json')
 identity=json.load(open(sys.argv[2]));profile=json.load(open(sys.argv[3]));decision=plan(identity,profile['observations'],path=profile.get('path','/'))
 if sys.argv[1]=='publish':
  target=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/mad4b-js-cpu-intelligence.json')
  old={}
  if target.is_file():
   try:old=json.loads(target.read_text())
   except (ValueError,OSError):raise RuntimeError('Existing plan invalid; fail closed')
   if old.get('contract')!='mad4b.js.cpu-plans.v1' or old.get('site_uuid')!=UUID:raise RuntimeError('Existing plan out of scope')
  rows=old.get('pages',{})
  if not isinstance(rows,dict):raise RuntimeError('Invalid stored pages')
  key=hashlib.sha256(decision['path'].encode()).hexdigest()
  rows[key]=decision
  rows=dict(sorted(rows.items(),key=lambda item:item[1].get('created_at',0),reverse=True)[:40])
  atomic(target,{'contract':'mad4b.js.cpu-plans.v1','site_uuid':UUID,'updated_at':int(time.time()),'pages':rows})
 print(json.dumps({'published':sys.argv[1]=='publish','device_results':{d:{'samples':v['samples'],'next':v['recommended_action']} for d,v in decision['devices'].items()}},sort_keys=True))
