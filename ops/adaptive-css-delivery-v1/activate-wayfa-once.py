#!/usr/bin/python3
"""Exactly-once governed RUM histogram schema + observe-mode admission.

No CSS work or customer content is changed. Host resource guard and original
site/worker locks remain mandatory. Any unexpected code/source drift stops
future attempts rather than weakening admission.
"""
from pathlib import Path
import fcntl,hashlib,json,os,subprocess,time
LOG=Path('/var/lib/mad4b/company-code-release/receipts/adaptive-css-rum-wayfa-once-20261121')
DIR=Path('/opt/mad4b-company-provisioning/operations/adaptive-css-rum-v1')
CRON=Path('/etc/cron.d/mad4b-css-rum-wayfa-once')
CFG=LOG/'config.json'
SHA=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
lockfd=os.open('/run/lock/mad4b-css-rum-wayfa-once.lock',os.O_CREAT|os.O_RDWR,0o600)
try:
 fcntl.flock(lockfd,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError:
 print('RUM_ONCE_ALREADY_RUNNING',flush=True)
 raise SystemExit(0)
def receipt(data,file):
 f=LOG/file
 b=json.dumps(data,sort_keys=True,indent=2).encode()+b'\n'
 fd=os.open(f,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
 with os.fdopen(fd,'wb') as v:
  v.write(b);v.flush();os.fsync(v.fileno())
def deactivate():
 if CRON.is_file() and not CRON.is_symlink():
  os.replace(CRON,LOG/'disabled.cron')
def stop(reason,details=''):
 receipt({'utc':time.strftime('%FT%TZ',time.gmtime()),
  'status':'HOLD','reason':reason,'details':details[:300],
  'css_changed':False},'final.json')
 deactivate()
 print('RUM_ONCE_STOPPED_FAIL_CLOSED',reason,flush=True)
cfg=json.loads(CFG.read_text())
if (cfg.get('contract')!='mad4b.adaptive-css.once.v1'
    or cfg.get('site')!='wayfa'
    or type(cfg.get('expires_epoch')) is not int):
 stop('CONFIG_INVALID');raise SystemExit(1)
if (LOG/'success.json').is_file():
 deactivate()
 print('RUM_ONCE_ALREADY_SUCCEEDED',flush=True)
 raise SystemExit(0)
if time.time()>cfg['expires_epoch']:
 stop('SCHEDULE_EXPIRED_NO_RESOURCE_OVERRIDE')
 raise SystemExit(0)
target=DIR/'activate-wayfa-observe.sh'
php=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/438-mad4b-adaptive-css-rum-v1.php')
js=Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/mad4b-adaptive-css-v1/assets/mad4b-css-rum-client.js')
if (not target.is_file() or target.is_symlink()
    or SHA(target)!='ba61d791b1c169ea868c6b0b7d3d33d4773b05587653526f1d6bfc0e5932f7bd'
    or not php.is_file() or php.is_symlink()
    or SHA(php)!='f5e79f0e6ca887f2cbb62e415f6fd57810f3dec60a66edc6e4cefc12daeb8c77'
    or not js.is_file() or js.is_symlink()
    or SHA(js)!='36833c7f1a6e1fcea186f16db51e06860c8e861cb8ee19d20910d207173b9544'):
 stop('SOURCE_COMPONENT_SHA_MISMATCH')
 raise SystemExit(1)
try:
 r=subprocess.run(['/bin/bash',str(target)],capture_output=True,
  text=True,timeout=52,check=False)
except subprocess.TimeoutExpired:
 stop('ADMISSION_TIMEOUT')
 raise SystemExit(1)
if r.returncode==75:
 print('RUM_ONCE_DEFER_HOST_RESOURCE_OR_WORKER_LOCK',flush=True)
 raise SystemExit(0)
if r.returncode!=0:
 stop('ADMISSION_FAILED_UNEXPECTEDLY',r.stderr[-230:])
 raise SystemExit(1)
try:
 jsonline=[line for line in r.stdout.splitlines() if line.strip().startswith('{')][-1]
 data=json.loads(jsonline)
 if not(data['site']=='wayfa' and data['mode']=='observe' and
    data['sample_bps']==100 and data['schema_present'] is True and
    data['css_certificates_changed'] is False):
  raise ValueError('activation readback contract drift')
except (ValueError,IndexError,KeyError,TypeError) as e:
 stop('ACTIVATION_ATTESTATION_INVALID',str(e))
 raise SystemExit(1)
receipt({'contract':'mad4b.adaptive-css.once-success.v1',
 'utc':time.strftime('%FT%TZ',time.gmtime()),
 'site':'wayfa','mode':'observe','sample_bps':100,
 'schema_present':True,'css_modified':False,
 'receipt':data},'success.json')
deactivate()
print('RUM_WAYFA_OBSERVE_ONCE_VERIFIED_AND_CRON_DISABLED',flush=True)
