#!/usr/bin/env python3
"""Site-scoped JS bundle evaluator: source-bound, per-device, five-pair, fail-closed."""
import os,json,sys,time,statistics,hashlib,tempfile,subprocess,re
from pathlib import Path
Q=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1')
POLICY=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/mad4b-js-autonomous-policy.json')
HOST='tours.dev.mad4b.com';UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
def identity():
 cmd=['docker','exec','-u','www-data','-w','/var/www/html','etg-commerce-staging-php','wp','eval',
  'echo wp_json_encode(["site"=>site_url(),"env"=>wp_get_environment_type(),"uuid"=>(get_option("mad4b_scp_brand_context_profile_v1",[])["site_uuid"]??""),"epoch"=>(int)get_option("mad4b_css_ai_epoch_v1",1),"guest"=>(string)get_option("litespeed.conf.guest"),"combine"=>(string)get_option("litespeed.conf.optm-js_comb"),"external_inline"=>(string)get_option("litespeed.conf.optm-js_comb_ext_inl"),"defer"=>(string)get_option("litespeed.conf.optm-js_defer"),"min"=>(string)get_option("litespeed.conf.optm-js_min"),"mobile_cache"=>(string)get_option("litespeed.conf.cache-mobile"),"litespeed"=>LSCWP_V,"elementor"=>ELEMENTOR_VERSION]);','--quiet']
 r=subprocess.run(cmd,capture_output=True,text=True,timeout=29,check=True)
 x=json.loads(r.stdout.strip())
 if(x['site'],x['env'],x['uuid'],x['combine'],x['external_inline'],x['defer'],x['min'],x['mobile_cache'],x['litespeed'],x['elementor'])!=('https://'+HOST,'staging',UUID,'','','0','1','1','7.9.2','4.3.5'):
  raise RuntimeError('Invalid source scope or global LiteSpeed settings: keep native')
 if not isinstance(x['epoch'],int) or x['epoch']<1:raise RuntimeError('Invalid content source epoch')
 return x
def evaluate(data,device,candidate,path='/'):
 issues=[];rows=data.get('observations',[])
 if data.get('path')!=path or data.get('device')!=device or data.get('candidate')!=candidate or data.get('complete') is not True or data.get('planned')!=10 or len(rows)!=10:
  return ['Incomplete or mismatched five-pair evidence'],[]
 native=[];trial=[]
 for n in range(5):
  a=[x for x in rows if x.get('round')==n and x.get('mode')=='native-v1' and x.get('mobile')==(device=='mobile')]
  b=[x for x in rows if x.get('round')==n and x.get('mode')==candidate and x.get('mobile')==(device=='mobile')]
  if len(a)!=1 or len(b)!=1:issues.append('Nonpaired or duplicate observations');continue
  a,b=a[0],b[0];native.append(a);trial.append(b)
  if any(x.get('status')!=200 or x.get('documentReady')!='complete' for x in (a,b)):issues.append('Invalid page response or incomplete DOM')
  if a.get('mainNav')!=b.get('mainNav') or not 1<=int(a.get('mainNav',0))<=2:issues.append('Guest Mode navigation mismatch')
  if int(a.get('vary',0))!=int(b.get('vary',0)):issues.append('Guest Mode vary mismatch')
  if b.get('failedJs') or len(b.get('errors',[]))>len(a.get('errors',[])):issues.append('New JavaScript or network error')
  if a.get('dom')!=b.get('dom'):issues.append('Elementor/JetEngine/listing DOM mismatch')
  if a.get('globals')!=b.get('globals'):issues.append('Critical runtime globals changed')
  for x in (a,b):
   if not isinstance(x.get('lcpMeasured'),(int,float)) or x['lcpMeasured']<1:issues.append('Missing LCP observer')
   if not isinstance(x.get('clsMeasured'),(int,float)):issues.append('Missing CLS observer')
 if len(native)!=5 or len(trial)!=5:return list(set(issues+['Insufficient pairs'])),[]
 def med(rows,field):return statistics.median(float(x['metrics'][field])*1000 for x in rows)
 baseline_js=med(native,'ScriptDuration');candidate_js=med(trial,'ScriptDuration')
 baseline_task=med(native,'TaskDuration');candidate_task=med(trial,'TaskDuration')
 if baseline_js-candidate_js<max(100,baseline_js*.10):issues.append('No material JS evaluation reduction')
 if candidate_task-baseline_task>max(120,baseline_task*.06):issues.append('Main-thread regression')
 a_scripts=statistics.median(len(x.get('scripts',[])) for x in native)
 b_scripts=statistics.median(len(x.get('scripts',[])) for x in trial)
 if b_scripts>=a_scripts*.85:issues.append('No effective JS combining on this device')
 a_ttfb=statistics.median(float(x['ttfb']) for x in native);b_ttfb=statistics.median(float(x['ttfb']) for x in trial)
 if abs(a_ttfb-b_ttfb)>max(450,a_ttfb*.10):issues.append('Unbalanced WordPress/PHP TTFB')
 a_lcp=statistics.median(float(x['lcpMeasured'])-float(x['ttfb']) for x in native)
 b_lcp=statistics.median(float(x['lcpMeasured'])-float(x['ttfb']) for x in trial)
 if b_lcp-a_lcp>max(130,abs(a_lcp)*.08):issues.append('Post-TTFB LCP regression')
 a_cls=statistics.median(x['clsMeasured'] for x in native);b_cls=statistics.median(x['clsMeasured'] for x in trial)
 if b_cls-a_cls>.012:issues.append('CLS regression')
 return sorted(set(issues)),[{'baseline_js_ms':round(baseline_js),'candidate_js_ms':round(candidate_js),
  'baseline_task_ms':round(baseline_task),'candidate_task_ms':round(candidate_task),
  'baseline_script_tags':a_scripts,'candidate_script_tags':b_scripts,'baseline_ttfb_ms':a_ttfb,'candidate_ttfb_ms':b_ttfb,'delta_post_ttfb_lcp_ms':b_lcp-a_lcp}]
def update(path,device,mode,issues,metrics,source):
 p=POLICY;doc=json.loads(p.read_text());assert doc['site_uuid']==UUID and doc['site_host']==HOST and doc['contract']=='mad4b.js.route-policy.v1'
 key=hashlib.sha256(('en|'+path).encode()).hexdigest();route=doc['routes'].get(key,{'path':path,'locale':'en','devices':{}})
 row=route['devices'].get(device,{});certified=not issues
 row.update({'status':'certified' if certified else 'hold','mode':mode if certified else 'native',
  'litespeed':source['litespeed'],'elementor':source['elementor'],'guest_mode':source['guest'],'epoch':source['epoch'],
  'expires':int(time.time()+86400) if certified else 0,'issues':issues,'metrics':metrics,'evaluated_at':int(time.time())})
 route['devices'][device]=row;doc['routes'][key]=route
 fd,name=tempfile.mkstemp(prefix='.mad4b-js-policy-',dir=p.parent)
 try:
  with os.fdopen(fd,'w') as f:json.dump(doc,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
  os.chmod(name,0o640)
  if os.geteuid()==0:os.chown(name,0,33)
  os.replace(name,p)
 finally:
  if os.path.exists(name):os.unlink(name)
def main():
 if len(sys.argv)!=5 or sys.argv[1] not in ('evaluate','apply') or sys.argv[2] not in ('desktop','mobile') or sys.argv[3] not in ('files-v1','full-v1') or sys.argv[4]!='/':raise SystemExit('Usage: js-policy-engine.py evaluate|apply desktop|mobile files-v1|full-v1 /')
 action,device,mode,path=sys.argv[1:];source=identity()
 file=Q/('js-benchmark-'+device+'-'+mode+'.json');data=json.loads(file.read_text())
 issues,metrics=evaluate(data,device,mode,path)
 if action=='apply':update(path,device,'files' if mode=='files-v1' else 'full',issues,metrics,source)
 print(json.dumps({'contract':'mad4b.js.verdict.v1','device':device,'candidate':mode,'status':'HOLD' if issues else 'CERTIFIED' if action=='apply' else 'ELIGIBLE_NOT_APPLIED','issues':issues,'metrics':metrics,'applied':action=='apply'},indent=2))
 return 5 if issues else 0
if __name__=='__main__':sys.exit(main())
