#!/usr/bin/env python3
"""Bounded local per-device work selector; WordPress event work completes only after both devices."""
import json,sys,time,os,tempfile,subprocess,re
from pathlib import Path
Q=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/css-priority-auto-v1');STATE=Q/'device-turns.json'
DEVICES=('desktop','mobile')
def load():
 if not STATE.exists():return {}
 v=json.loads(STATE.read_text());return v if isinstance(v,dict) else {}
def store(obj):
 if len(obj)>140:obj=dict(sorted(obj.items(),key=lambda pair:pair[1].get('updated',0),reverse=True)[:100])
 fd,n=tempfile.mkstemp(dir=Q,prefix='.device-turns-')
 try:
  with os.fdopen(fd,'w') as f:json.dump(obj,f);f.flush();os.fsync(f.fileno())
  os.chmod(n,0o600);os.replace(n,STATE)
 finally:
  if os.path.exists(n):os.unlink(n)
def current_job(path):
 cmd=['docker','exec','-u','www-data','-w','/var/www/html','etg-commerce-staging-php','wp','eval',
 'echo wp_json_encode(MAD4B_SCP_Adaptive_Asset_Intelligence::queue());','--quiet']
 r=subprocess.run(cmd,capture_output=True,text=True,timeout=30,check=True)
 for j in json.loads(r.stdout):
  if j.get('path')==path:return str(j.get('id',''))+'|'+str(j.get('updated',0))
 return ''
def path_ok(path):return bool(re.fullmatch(r'/[A-Za-z0-9/_~.-]{0,239}',path)) and '..' not in path and '//' not in path
if __name__=='__main__':
 try:
  action,path,mode=sys.argv[1:4]
  if not path_ok(path) or mode not in ('run','event','smoke'):raise RuntimeError('Bad route or mode')
  data=load();row=data.get(path,{})
  job=current_job(path) if mode=='event' else ''
  if action=='choose':
   if mode=='event':
    if not job:raise RuntimeError('Event queue vanished; no job to claim')
    if row.get('job')!=job:row={'job':job,'results':{},'updated':time.time()}
    outstanding=[d for d in DEVICES if d not in row.get('results',{})]
    selected=outstanding[0] if outstanding else 'ack'
   else:
    selected='mobile' if row.get('last_periodic')=='desktop' else 'desktop'
    row['last_periodic']=selected;row['updated']=time.time()
   data[path]=row;store(data);print(selected)
  elif action=='complete':
   device,result=sys.argv[4:6]
   if device not in DEVICES or result not in ('certified','hold','native','failed'):raise RuntimeError('Bad outcome')
   if mode=='event':
    if not job or job!=row.get('job'):raise RuntimeError('Event changed; do not acknowledge stale result')
    row.setdefault('results',{})[device]=result
   else:row.setdefault('last_periodic_result',{})[device]=result
   row['updated']=time.time();data[path]=row;store(data)
   done=bool(mode=='event' and all(x in row.get('results',{}) for x in DEVICES))
   print(json.dumps({'done':done,'results':row.get('results',{}),'current_job':bool(job)}))
  elif action=='ack':
   if mode!='event' or not job or row.get('job')!=job:raise RuntimeError('Ack requires matching job')
   results=row.get('results',{})
   if not all(x in results for x in DEVICES):raise RuntimeError('Cannot ack before both devices')
   status='certified' if all(v=='certified' for v in results.values()) else 'hold'
   detail='desktop='+results['desktop']+' mobile='+results['mobile']
   bridge=subprocess.run(['python3',str(Q/'notification-bridge.py'),path,status,detail],capture_output=True,text=True,timeout=45,check=True)
   print(bridge.stdout.strip())
  else:raise RuntimeError('Unsupported action')
 except Exception as e:
  print('DEVICE_WORK_FAIL_CLOSED: '+str(e),file=sys.stderr);sys.exit(11)
