#!/usr/bin/env python3
"""Bounded site-local WP cron on registered Hostinger sites, via pinned SSH.

This is a fallback when hosting account SSH has no crontab capability and
Hostinger's hPanel Cron management is not authorized via a connected API.
Visitor-triggered WP Cron is deliberately retained as independent fallback.
"""
import argparse,datetime,fcntl,importlib.util,json,os,pathlib,re,shlex,subprocess,sys,tempfile,time
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
POLICY=ROOT/'policies/company-hostinger-site-binding-map-v1.json'
BINDINGS=ROOT/'reports/company-hostinger-two-route-bindings-current.json'
HELPER=ROOT/'bin/company-hostinger-two-route-binding-v1.py'
REPORTS=ROOT/'reports/host-aware-remote-wp-cron-v1'
SITES={
 'are-production':('all-royal-egypt','production','hosting-2'),
 'are-staging':('all-royal-egypt','staging','hosting-2'),
 'etg-production':('egypt-tour-gates','production','hosting-3'),
 'etg-staging':('egypt-tour-gates','staging','hosting-3'),
 'almallah-production':('tourism-almallahgroup','production','hosting-1'),
}
CONTRACT='mad4b.host-aware-remote-wp-cron.v1'

def site_binding(site):
 company,env,target=SITES[site]
 policy=json.loads(POLICY.read_text())
 report=json.loads(BINDINGS.read_text())
 assert policy.get('contract')=='mad4b.company-hostinger-site-binding-map.v1'
 assert report.get('contract')=='mad4b.company-hostinger-two-route-bindings.v1'
 records=[r for r in policy['sites'] if r.get('environment')==env and r.get('ssh_target')==target]
 assert len(records)>0
 match=report['sites'].get(company+':'+env)
 assert isinstance(match,dict) and match.get('ssh_target')==target and match.get('ssh_verified') is True
 assert match.get('ssh_readback')=='PASS' and match.get('central_company_identity_matched') is True
 exact=[r for r in records if r.get('origin')==match.get('canonical_origin')]
 assert len(exact)==1
 item=exact[0]
 user=item['ssh_username'];root=item['ssh_root'];origin=item['origin']
 assert re.fullmatch(r'u[0-9]{5,15}',user)
 assert root.startswith('/home/'+user+'/domains/') and '/..' not in root and '\n' not in root
 assert root.endswith('public_html') or '/public_html/' in root
 assert re.fullmatch(r'https://[a-z0-9.-]+',origin) and '..' not in origin
 return target,root,origin

def ssh_command(target,remote_command,timeout):
 spec=importlib.util.spec_from_file_location('mad4b_bound_host_ssh',HELPER)
 mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
 x=subprocess.run(mod.ssh_cmd(target)+[remote_command],capture_output=True,text=True,timeout=timeout)
 return x

def atomic_write(path,data):
 REPORTS.mkdir(parents=True,exist_ok=True,mode=0o700)
 assert not path.is_symlink() and not REPORTS.is_symlink()
 fd,tmp=tempfile.mkstemp(prefix='.cron-receipt-',dir=REPORTS)
 try:
  with os.fdopen(fd,'w') as f:
   os.fchmod(f.fileno(),0o600)
   json.dump(data,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
  os.replace(tmp,path)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)

def run(site,check=False):
 target,root,origin=site_binding(site)
 # Scope one SSH call at a time per remote hosting account, including both
 # Production and Staging where they share a single Hostinger Cloud Startup.
 local_lock=pathlib.Path('/run/lock/mad4b-host-aware-remote-'+target+'.lock')
 with local_lock.open('a+') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:
   print(json.dumps({'contract':CONTRACT,'site':site,'state':'DEFER_HOST_BUSY'}));return 0
  # This local, exact-host readback *precedes* the execution, regardless of
  # catalog admission age, preventing command invocation against moved sites.
  identity='cd '+shlex.quote(root)+' && /usr/local/bin/wp option get home --skip-plugins --skip-themes 2>/dev/null'
  p=ssh_command(target,identity,24)
  if p.returncode!=0 or p.stdout.strip()!=origin:
   raise RuntimeError('REMOTE_WORDPRESS_HOST_BINDING_CHANGED_OR_UNAVAILABLE')
  if check:
   print(json.dumps({'contract':CONTRACT,'site':site,'state':'READY','origin':origin,'target':target}));return 0
  remote_lock='/tmp/mad4b-host-aware-cron-'+target+'.lock'
  # Run the *original* WordPress implementation; no WP-CLI arbitrary eval,
  # no manual replay, no cron event deletion, no payments mutation here.
  # 73 indicates a lock held by a sibling site on this account.
  run_command=('cd '+shlex.quote(root)+' && /usr/bin/flock -E 73 -n '+shlex.quote(remote_lock)
       +' /usr/bin/nice -n 15 /usr/bin/timeout 65 /usr/bin/php -d max_execution_time=58 -f '
       +shlex.quote(root+'/wp-cron.php')+' >/dev/null 2>/dev/null; r=$?; echo MAD4B_CRON_EXIT=$r; exit $r')
  started=time.monotonic()
  p=ssh_command(target,run_command,83)
  elapsed=round(time.monotonic()-started,2)
  if p.returncode==0 and 'MAD4B_CRON_EXIT=0' in p.stdout:
   state='NATIVE_CRON_EXITED_ZERO'
  elif p.returncode==73:
   state='DEFER_REMOTE_HOST_BUSY'
  else:
   state='NATIVE_CRON_FAILED'
  data={'contract':CONTRACT,'site':site,'environment':SITES[site][1],
    'origin':origin,'target':target,'state':state,
    'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'duration_s':elapsed,'exit_code':p.returncode,
    'entry_point':'registered_vps_ssh_site_local_php',
    'visitor_wp_cron_fallback_unchanged':True,
    'wp_event_coverage':'native_wp_cron_runtime_not_separately_proven',
    'hpanel_cron_configured':False}
  atomic_write(REPORTS/(site+'.json'),data)
  print(json.dumps(data,sort_keys=True),flush=True)
  return 0 if state!='NATIVE_CRON_FAILED' else 75

def main():
 parser=argparse.ArgumentParser()
 parser.add_argument('site',choices=tuple(SITES))
 parser.add_argument('--check',action='store_true')
 args=parser.parse_args()
 try:return run(args.site,args.check)
 except (OSError,subprocess.TimeoutExpired,AssertionError,ValueError,KeyError,RuntimeError) as error:
  # Stable failure names only: don't disclose SSH diagnostics or private data.
  kind=('HOST_IDENTITY_GUARD' if isinstance(error,(AssertionError,KeyError,ValueError)) else
        'SSH_TIMEOUT' if isinstance(error,subprocess.TimeoutExpired) else
        'HOST_UNAVAILABLE_OR_RUNTIME_ERROR')
  print(json.dumps({'contract':CONTRACT,'site':args.site,'state':'FAIL_CLOSED','error_kind':kind}),flush=True)
  return 75
if __name__=='__main__':sys.exit(main())
