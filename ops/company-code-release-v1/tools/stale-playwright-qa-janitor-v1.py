#!/usr/bin/env python3
"""Reclaim abandoned MAD4B QA browsers, never managed Playwright service.

Non-destructive: stop only verified stale one-shot test containers; do not rm,
prune, alter Docker daemon or interrupt WordPress or production browser API.
"""
import argparse,datetime,fcntl,json,os,pathlib,re,subprocess,sys,time,signal,collections
LOCK='/run/lock/mad4b-playwright-qa-janitor-v1.lock'
IMAGE_RE=re.compile(r'^mad4b-firecrawl-playwright:[A-Za-z0-9_.-]+$')
SCRIPT_RE=re.compile(r'^/(?:tmp|work|out|tools)/[^\s]+\.js$|^/usr/src/app/test\.js$')
RECEIPT=pathlib.Path('/opt/mad4b-company-provisioning/reports/vps-cpu-remediation-20261115/janitor-last.json')

def ctime(raw):return datetime.datetime.fromisoformat(raw.replace('Z','+00:00')).timestamp()
def eligible(x,now,min_age):
 h=x.get('HostConfig') or {}; conf=x.get('Config') or {}; state=x.get('State') or {};
 image=conf.get('Image','');cmd=conf.get('Cmd') or [];created=x.get('Created','')
 if not isinstance(image,str) or not IMAGE_RE.fullmatch(image):return False,'foreign_image'
 if not state.get('Running',False):return False,'not_running'
 if h.get('RestartPolicy',{}).get('Name')!='no':return False,'persistent_restart_policy'
 if h.get('PortBindings'):return False,'ports_exposed'
 labels=conf.get('Labels') or {}
 if labels.get('coolify.managed')=='true' or labels.get('com.docker.compose.oneoff')=='False' and labels.get('coolify.type')=='service':return False,'managed_service'
 if not cmd or not isinstance(cmd,list):return False,'no_command'
 script=(cmd[1] if cmd[0] in ('node','nodejs','/usr/local/bin/node') and len(cmd)>1 else cmd[0])
 if not isinstance(script,str) or not SCRIPT_RE.fullmatch(script):return False,'not_qa_script'
 try:age=(now-ctime(created))/60
 except (ValueError,TypeError):return False,'missing_creation_timestamp'
 if age<min_age or age>60*24*120:return False,'fresh_or_timestamp_abnormal'
 return True,'abandoned_qa_script'

def run(command,timeout=25):return subprocess.run(command,text=True,capture_output=True,timeout=timeout,check=False)
def current_image_tags():
 r=run(['docker','images','--format','{{.Repository}}:{{.Tag}}'],timeout=15)
 if r.returncode:raise RuntimeError('docker_images_failed')
 return sorted(set(x for x in r.stdout.splitlines() if IMAGE_RE.fullmatch(x)))
def get_one(cid):
 r=run(['docker','inspect',cid],timeout=9)
 if r.returncode:return None
 j=json.loads(r.stdout)
 return j[0] if len(j)==1 else None

# The managed Playwright API can also retain abandoned `docker exec` browser
# trees *inside* its long-lived container. Distinguish those from API children
# by their parent (containerd-shim), QA script path, and age. Never restart API.
SERVICE_QA=re.compile(r'^node /tmp/mad4b-[a-zA-Z0-9_.-]*(?:smoke|mobile|qa|test|probe|coverage|trace|audit|perf|lighthouse)[a-zA-Z0-9_.-]*\.js$')

def service_roots(rows,shim,api_pid,min_age_minutes):
    selected=[]
    for row in rows:
        if (row['ppid']==shim and row['comm']=='node'
                and row['age_seconds']>=min_age_minutes*60
                and SERVICE_QA.fullmatch(row['args'])):selected.append(row)
    return selected

def process_signature(pid):
    try:return int((pathlib.Path('/proc')/str(pid)/'stat').read_text().rsplit(')',1)[1].split()[19])
    except (OSError,ValueError,IndexError):return None

def cleanup_service_exec(dry_run, min_age_minutes=90, max_roots=5):
    # Service ownership is pinned to the existing Coolify managed API container.
    # Discover the single Coolify-managed browser API by semantic labels,
    # not its deployment-specific container ID/name (redeploy-safe).
    services=run(['docker','ps','-q','--filter','label=coolify.managed=true',
                  '--filter','label=com.docker.compose.service=playwright-service'],timeout=16)
    if services.returncode:raise RuntimeError('service_discovery_failed')
    candidates=services.stdout.splitlines()
    if not candidates:return {'state':'service_absent','roots_found':0,'stopped_processes':0}
    if len(candidates)!=1:raise RuntimeError('multiple_managed_browser_services')
    service=candidates[0]
    inspect=run(['docker','inspect',service],timeout=14)
    if inspect.returncode:raise RuntimeError('service_inspection_failed')
    records=json.loads(inspect.stdout)
    if len(records)!=1:raise RuntimeError('managed_service_ambiguous')
    info=records[0];h=info.get('HostConfig') or {};conf=info.get('Config') or {};state=info.get('State') or {}
    labels=conf.get('Labels') or {}
    if (not state.get('Running') or (state.get('Health') or {}).get('Status')!='healthy'
            or h.get('RestartPolicy',{}).get('Name')!='unless-stopped'
            or conf.get('Cmd')!=['node','dist/api.js']
            or labels.get('coolify.managed')!='true'
            or labels.get('com.docker.compose.service')!='playwright-service'
            or not IMAGE_RE.fullmatch(conf.get('Image',''))):
        return {'state':'service_identity_hold','roots_found':0,'stopped_processes':0}
    init_pid=int(state.get('Pid',0))
    parent=run(['ps','-p',str(init_pid),'-o','ppid='],timeout=5)
    if parent.returncode or not parent.stdout.strip().isdigit():raise RuntimeError('service_shim_missing')
    shim=int(parent.stdout.strip());top=run(['docker','top',service,'-eo','pid,ppid,etimes,%cpu,rss,comm,args'],timeout=18)
    if top.returncode:raise RuntimeError('service_process_inspection_failed')
    rows=[]
    for line in top.stdout.splitlines()[1:]:
        tokens=line.split(None,6)
        if len(tokens)!=7:continue
        pid,ppid,seconds,cpu,rss,comm,args=tokens
        rows.append({'pid':int(pid),'ppid':int(ppid),'age_seconds':int(seconds),'comm':comm,'args':args})
    apis=[x for x in rows if x['args']=='node dist/api.js' and x['ppid']==init_pid]
    if len(apis)!=1:raise RuntimeError('service_api_parent_check_failed')
    roots=service_roots(rows,shim,apis[0]['pid'],min_age_minutes)
    by_parent=collections.defaultdict(list)
    for x in rows:by_parent[x['ppid']].append(x)
    selected=[]
    def walk(pid):
        for child in by_parent.get(pid,[]):
            selected.append(child);walk(child['pid'])
    for root in roots[:max_roots]:selected.append(root);walk(root['pid'])
    ids={x['pid'] for x in selected}
    if len(ids)!=len(selected) or apis[0]['pid'] in ids or init_pid in ids or shim in ids or len(selected)>100:
        raise RuntimeError('service_process_boundary_check_failed')
    receipt={'state':'dry_run' if dry_run else 'completed','roots_found':len(roots),'selected_root_count':min(len(roots),max_roots),'selected_processes':len(selected),'stopped_processes':0,'script_roots':[r['args'].split('/')[-1] for r in roots[:max_roots]]}
    if dry_run or not selected:return receipt
    signatures={pid:process_signature(pid) for pid in ids}
    if any(v is None for v in signatures.values()):raise RuntimeError('service_proc_signature_missing')
    same=lambda pid:process_signature(pid)==signatures[pid]
    # Children then parent to preserve the native API's own process group.
    root_ids={x['pid'] for x in roots[:max_roots]}
    for x in sorted(selected,key=lambda a:(a['pid'] in root_ids,a['pid'])):
        if same(x['pid']):
            try:os.kill(x['pid'],signal.SIGTERM)
            except ProcessLookupError:pass
    time.sleep(2)
    remaining=[pid for pid in signatures if same(pid)]
    for pid in remaining:
        try:os.kill(pid,signal.SIGKILL)
        except ProcessLookupError:pass
    time.sleep(0.3)
    leftover=[pid for pid in signatures if same(pid)]
    after=run(['docker','inspect',service],timeout=12)
    if after.returncode:raise RuntimeError('managed_service_readback_failed')
    latest=json.loads(after.stdout)[0]
    if not latest['State']['Running'] or latest['State']['Pid']!=init_pid or latest['State'].get('Health',{}).get('Status')!='healthy':
        raise RuntimeError('managed_service_health_changed')
    receipt.update({'stopped_processes':len(signatures)-len(leftover),'kill_escalations':len(remaining),'remaining_processes':len(leftover),'api_still_healthy':True})
    return receipt

def main():
 p=argparse.ArgumentParser();p.add_argument('--dry-run',action='store_true');p.add_argument('--min-age-minutes',type=int,default=45);p.add_argument('--max-stop',type=int,default=20);args=p.parse_args()
 if not (30<=args.min_age_minutes<=1440 and 1<=args.max_stop<=30):raise SystemExit('unsafe admission bounds')
 with open(LOCK,'a+') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return print(json.dumps({'state':'concurrent_janitor_skipped'}))
  now=time.time();ids=[]
  for tag in current_image_tags():
   r=run(['docker','ps','-q','--filter','ancestor='+tag],timeout=16)
   if r.returncode:raise RuntimeError('docker_ps_failed')
   ids.extend(x for x in r.stdout.splitlines() if re.fullmatch('[a-f0-9]{12,64}',x))
  ids=sorted(set(ids));found=[];blocked={};errors=[];stopped=[]
  for cid in ids:
   v=get_one(cid)
   if not v:continue
   good,reason=eligible(v,now,args.min_age_minutes)
   if good:found.append({'id':cid,'name':v.get('Name','').lstrip('/'),'age_minutes':round((now-ctime(v['Created']))/60,1),'script':v['Config']['Cmd']})
   else:blocked[reason]=blocked.get(reason,0)+1
  if not args.dry_run:
   for x in sorted(found,key=lambda a:-a['age_minutes'])[:args.max_stop]:
    v=get_one(x['id']);good,reason=eligible(v or {},time.time(),args.min_age_minutes)
    if not good:errors.append({'id':x['id'],'reason':'recheck_'+reason});continue
    r=run(['docker','stop','--time','3',x['id']],timeout=10)
    if r.returncode:errors.append({'id':x['id'],'reason':'stop_failed_'+r.stderr[-120:]})
    else:stopped.append(x['id'])
  service_cleanup=cleanup_service_exec(args.dry_run)
  data={'contract':'mad4b.stale-qa-container-janitor.v1','time_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'state':'dry_run' if args.dry_run else 'completed','scanned':len(ids),'candidate_count':len(found),'stopped_count':len(stopped),'stopped_ids':stopped,'retained':blocked,'errors':errors,'min_age_minutes':args.min_age_minutes,'max_stop':args.max_stop,'no_docker_rm_or_prune':True,'managed_service_qa':service_cleanup}
  if not args.dry_run:
   RECEIPT.parent.mkdir(parents=True,exist_ok=True);temp=RECEIPT.with_suffix('.tmp');temp.write_text(json.dumps(data,indent=2)+'\n');temp.chmod(0o600);temp.replace(RECEIPT)
  print(json.dumps(data));return 0 if not errors else 1
if __name__=='__main__':
 try:sys.exit(main())
 except Exception as e:print(json.dumps({'state':'error','reason':str(e)[:220]}));sys.exit(2)
