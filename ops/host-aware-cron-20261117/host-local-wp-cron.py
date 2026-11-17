#!/usr/bin/env python3
"""Per-host WordPress native cron scheduling, isolated from visitor traffic.

Cron runs only inside the exact local site PHP container, and WordPress's own
canonical cron event loop and doing_cron transient remain authoritative.
"""
import argparse,datetime,fcntl,json,os,pathlib,re,subprocess,sys,time
SITES={
 'source':('php-wkgtkzta35wkkgouwt6ruljc','tours.dev.mad4b.com','staging'),
 'wayfa':('php-guxhp9y6vgh7oouuw8plxrlr','wayfatours.com','production')}
LOCK=pathlib.Path('/run/lock/mad4b-wp-cron-host-global.lock')
STATE=pathlib.Path('/opt/mad4b-company-provisioning/reports/host-aware-wp-cron-v1')
CMD_BASE=['/usr/bin/docker','exec','--user','33:33']
# Cron owns the full Wordpress event loop and hence Action Scheduler queue.
# It is never driven via an HTTP endpoint or triggered by a visitor.
PHP=['nice','-n','15','timeout','125','php','-d','max_execution_time=115','-f','/var/www/html/wp-cron.php']

def emit(site,status,**data):
 print('MAD4B_HOST_LOCAL_WP_CRON '+json.dumps({'site':site,'status':status,**data},sort_keys=True),flush=True)

def check_binding(site):
 c,host,env=SITES[site]
 x=subprocess.run(['/usr/bin/docker','inspect','--format',
  '{{.State.Status}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "coolify.environmentName"}}',c],
  capture_output=True,text=True,timeout=7)
 parts=x.stdout.strip().split('|')
 if x.returncode or len(parts)!=3 or parts[0]!='running' or parts[1]!='php' or parts[2]!=env:
  raise RuntimeError('container_identity_or_environment_not_verified')
 # Isolate execution from WP front-end; original WordPress Core still owns
 # due event dispatch, signatures and race control.
 probe=subprocess.run(CMD_BASE+[c,'php','-d','display_errors=0','-r',
  'define("WP_USE_THEMES",false);require "/var/www/html/wp-load.php";'
  'echo "CRON_SITE_PROOF ",wp_json_encode(["host"=>wp_parse_url(home_url(),PHP_URL_HOST),'
  '"env"=>wp_get_environment_type(),"disabled"=>defined("DISABLE_WP_CRON") && DISABLE_WP_CRON===true,'
  '"as"=>has_action("action_scheduler_run_queue")>0]),"\\n";'],
  capture_output=True,text=True,timeout=32)
 row=[line.split('CRON_SITE_PROOF ',1)[1] for line in probe.stdout.splitlines()
      if line.startswith('CRON_SITE_PROOF ')]
 if probe.returncode or len(row)!=1:
  raise RuntimeError('wordpress_identity_probe_unavailable')
 payload=json.loads(row[0]);
 if payload!={'host':host,'env':env,'disabled':True,'as':True}:
  raise RuntimeError('wordpress_cron_or_commerce_binding_not_ready')
 return c

def exec_cycle(site,only_check=False):
 if site not in SITES:raise ValueError('unregistered_site')
 lock=LOCK.open('a+')
 try:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:
   emit(site,'deferred',reason='another_local_wp_cron_active');return 0
  container=check_binding(site)
  if only_check:
   emit(site,'ready',container=container,mode='verify_only');return 0
  start=time.monotonic()
  proc=subprocess.run(CMD_BASE+[container,*PHP],capture_output=True,text=True,timeout=143)
  duration=round(time.monotonic()-start,2)
  result={'contract':'mad4b.host-local-wp-cron.v1','site':site,
   'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
   'container':container,'duration_s':duration,'exit_code':proc.returncode,
   'stderr_sha256':__import__('hashlib').sha256(proc.stderr.encode()).hexdigest(),
   'stdout_sha256':__import__('hashlib').sha256(proc.stdout.encode()).hexdigest(),
   'used_native_wp_cron':True,'action_scheduler_hook_enabled':True,
   'auto_visitor_spawns_disabled':True}
  STATE.mkdir(mode=0o700,parents=True,exist_ok=True)
  out=STATE/(site+'.json')
  tmp=out.with_suffix('.tmp');tmp.write_text(json.dumps(result,sort_keys=True)+'\n');os.chmod(tmp,0o600);os.replace(tmp,out)
  emit(site,'success' if proc.returncode==0 else 'failed',duration_s=duration,exit_code=proc.returncode)
  return 0 if proc.returncode==0 else 75
 finally:lock.close()

def main():
 p=argparse.ArgumentParser();p.add_argument('site',choices=tuple(SITES));p.add_argument('--check',action='store_true');a=p.parse_args()
 try:return exec_cycle(a.site,a.check)
 except (OSError,ValueError,KeyError,subprocess.TimeoutExpired,RuntimeError) as e:
  emit(a.site,'hold',reason=str(e)[:105]);return 75
if __name__=='__main__':sys.exit(main())
