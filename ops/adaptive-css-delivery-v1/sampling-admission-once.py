#!/usr/bin/python3
"""One-shot resource-governed RUM sampling enrollment for both enrolled sites."""
import pathlib,hashlib,os,subprocess,fcntl,time,json
R=pathlib.Path('/var/lib/mad4b/company-code-release/receipts/css-rum-consented-10pct-20261121')
CRON=pathlib.Path('/etc/cron.d/mad4b-css-rum-sample-once')
CANARY='294edc43f250d2fcd130dfabb093895165e76ebe0904a1a606a01b094f06adf3'
SITE={
 'source':('php-wkgtkzta35wkkgouwt6ruljc','tours.dev.mad4b.com','staging',
  pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/438-mad4b-adaptive-css-rum-v1.php')),
 'wayfa':('php-guxhp9y6vgh7oouuw8plxrlr','wayfatours.com','production',
  pathlib.Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/438-mad4b-adaptive-css-rum-v1.php'))
}
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(name,data):
 p=R/name
 with p.open('w') as f:json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
 p.chmod(0o600)
def disable():
 if CRON.exists() and not CRON.is_symlink():
  CRON.replace(R/'disabled.cron')
def fail(reason,more=''):
 write('hold.json',{'utc':time.strftime('%FT%TZ',time.gmtime()),
   'status':'HOLD','reason':reason,'detail':more[:220]})
 disable()
 print('RUM_SAMPLE_ENROLLMENT_HELD',reason,flush=True)
lock=os.open('/run/lock/mad4b-css-rum-sample-once.lock',os.O_RDWR|os.O_CREAT,0o600)
try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError:
 print('RUM_SAMPLE_ANOTHER_RUN_ACTIVE',flush=True);raise SystemExit(0)
config=json.loads((R/'config.json').read_text())
if config.get('site_ids')!=['source','wayfa'] or config.get('contract')!='mad4b.css.rum.sample.once.v1':
 fail('CONFIG_INVALID');raise SystemExit(1)
if (R/'success.json').exists():
 disable();print('RUM_SAMPLE_BOTH_SITES_ALREADY_ENROLLED',flush=True);raise SystemExit(0)
if time.time()>config.get('expires_epoch',0):
 fail('EXPIRED_WITHOUT_RESOURCE_BYPASS');raise SystemExit(0)
for site,row in SITE.items():
 php,host,env,p=row
 if p.is_symlink() or not p.is_file() or sha(p)!=CANARY:
  fail('SOURCE_RELEASE_PIN_DRIFT',site);raise SystemExit(1)
guard=subprocess.run(['python3','/opt/wayfa-rebuild-20261102/operations/css-cron-v1/renderer/public-route-cpu-guard.py'],
 capture_output=True,text=True,timeout=7)
if guard.returncode!=0:
 print('RUM_SAMPLE_DEFER_CPU',guard.stdout.strip()[-130:],flush=True);raise SystemExit(0)
shared=os.open('/run/lock/mad4b-css-wpcron-worker.lock',os.O_RDWR|os.O_CREAT,0o600)
try:fcntl.flock(shared,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError:
 print('RUM_SAMPLE_DEFER_CSS_WORKER',flush=True);raise SystemExit(0)
php_src=r'''$c="MAD4B_Adaptive_CSS_RUM_V1";
$host=(string)wp_parse_url(home_url("/"),PHP_URL_HOST);
$site=getenv("MAD4B_EXPECTED_SITE");
if($c::site()!==$site||$host!==getenv("MAD4B_EXPECTED_HOST")
  ||wp_get_environment_type()!==getenv("MAD4B_EXPECTED_ENV"))
  throw new Exception("SITE_OR_ENVIRONMENT_MISMATCH");
$old=$c::policy();
if($old!==["mode"=>"observe","sample_bps"=>100]
 &&$old!==["mode"=>"observe","sample_bps"=>1000])
  throw new Exception("SAMPLING_OPTION_CONFLICT");
if($old["sample_bps"]===100)
  update_option($c::OPTION,["mode"=>"observe","sample_bps"=>1000],false);
if($c::policy()!==["mode"=>"observe","sample_bps"=>1000])
  throw new Exception("RUM_POLICY_WRITE_READBACK_FAILED");
echo wp_json_encode(["site"=>$site,"mode"=>"observe",
 "old_bps"=>$old["sample_bps"],"sample_bps"=>1000,
 "css_modified"=>false],JSON_UNESCAPED_SLASHES),"\n";'''
for site,(php,host,env,p) in SITE.items():
 if (R/(site+'.result.json')).exists():continue
 cmd=['docker','exec','-e','MAD4B_EXPECTED_SITE='+site,
      '-e','MAD4B_EXPECTED_HOST='+host,
      '-e','MAD4B_EXPECTED_ENV='+env,php,
      'wp','--allow-root','--skip-themes','eval',php_src]
 try:run=subprocess.run(cmd,capture_output=True,text=True,timeout=38)
 except subprocess.TimeoutExpired:
  fail('WPCLI_TIMEOUT',site);raise SystemExit(1)
 if run.returncode:
  fail('WPCLI_SOURCE_OR_POLICY_ERROR',site+':'+run.stderr[-170:]);raise SystemExit(1)
 try:
  j=json.loads([x for x in run.stdout.splitlines() if x.strip().startswith('{')][-1])
  assert j['site']==site and j['sample_bps']==1000 and j['css_modified'] is False
 except (IndexError,TypeError,ValueError,KeyError,AssertionError):
  fail('ENROLLED_STATE_NOT_CONFIRMED',site);raise SystemExit(1)
 write(site+'.result.json',j)
 print('RUM_SAMPLE_CONFIRMED',site,flush=True)
done={site:json.loads((R/(site+'.result.json')).read_text()) for site in SITE}
write('success.json',{'contract':'mad4b.css.rum.sample.once.success.v1',
 'utc':time.strftime('%FT%TZ',time.gmtime()),'sites':done,'sample_percent':10,
 'analytics_consent_required':True,'css_modified':False,'fastcgi_modified':False})
disable()
print('RUM_SAMPLE_ENROLLMENT_TWO_COMPANIES_COMPLETE',flush=True)
