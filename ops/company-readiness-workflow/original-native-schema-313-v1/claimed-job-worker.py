#!/usr/bin/env python3
"""One-shot claimed original 306 Job consumer for exact read-only 313 canaries.
No Jobs, Claims, Grants or target commands may be created by this worker.
"""
from pathlib import Path
import base64,hashlib,importlib.util,json,os,re,secrets,subprocess,sys,time
ROOT=Path('/opt/mad4b-company-transport/native-313-independent')
sys.path.insert(0, '/opt/mad4b-company-transport')
from central_wpcli_runtime import central_wp
WP=central_wp
HEX32=re.compile('^[a-f0-9]{32}$')
DIGEST=hashlib.sha256(b'["option","get","home"]').hexdigest()

CANDIDATE=Path('/opt/mad4b-company-transport/native-313-independent/plugin-install-v1')
SCHEMA_CANDIDATE=ROOT/'schema-readback-v1'
MU_CANDIDATE=Path('/opt/mad4b-company-transport/native-313-independent/mu-deploy-v1')
def operation_digests():
 options={DIGEST:'read'}
 # Exact read-only schema command; only an enrolled owner 305 Job is accepted.
 if (SCHEMA_CANDIDATE/'schema-contract.py').is_file() and (SCHEMA_CANDIDATE/'original-wpcli-executor.py').is_file() and (SCHEMA_CANDIDATE/'independent-readback.py').is_file():
  spec=importlib.util.spec_from_file_location('original_schema_313_readback',SCHEMA_CANDIDATE/'schema-contract.py')
  schema=importlib.util.module_from_spec(spec);spec.loader.exec_module(schema)
  options[schema.DIGEST]='schema.readback'
 try:
  spec=importlib.util.spec_from_file_location('candidate_plugin_install',CANDIDATE/'native_plugin_install_runtime.py')
  plugin=importlib.util.module_from_spec(spec);spec.loader.exec_module(plugin)
  options[plugin.command_hash()]='plugin.install'
 except Exception:
  pass # Optional operation cannot break the existing read-only worker.
 try:
  spec=importlib.util.spec_from_file_location('original_mu_deploy',
      MU_CANDIDATE/'native_mu_deploy_runtime.py')
  mu=importlib.util.module_from_spec(spec);spec.loader.exec_module(mu)
  options[mu.command_hash()]='mu-plugin.deploy'
 except Exception:
  pass # Not enrolled without a verified exact MU artifact + plan.
 return options

class TransientCentralReadBusy(RuntimeError):pass

def safe_capability_error(exc):
    # Preserve bounded machine status only; never log signed payloads or secrets.
    code=str(exc)
    return code if re.fullmatch(r'[A-Z][A-Z0-9_]{3,95}',code) else type(exc).__name__

def safe_executor_failure(returncode, stderr):
 """Expose only a bounded machine error code, never a signed payload."""
 match=re.search(r'(ORIGINAL_NATIVE_313_STOPPED|ORIGINAL_SCHEMA_RECEIPT_ONLY_STOPPED|INDEPENDENT_313_READBACK_DENIED):([A-Z][A-Z0-9_]{3,96})',str(stderr or ''))
 reason=match.group(2) if match else 'NO_MACHINE_REASON_REPORTED'
 return 'ORIGINAL_EXECUTOR_EXIT_'+str(int(returncode))+'_RECEIPT_RECONCILIATION_REQUIRED:'+reason

def native_receipt_inventory_count():
 """Observational only: immutable host receipts, not 313 certificates."""
 path=ROOT/'receipts'
 if not path.is_dir() or path.is_symlink():return 0
 count=0
 for f in sorted(path.glob('*.json'))[:200]:
  try:
   if f.is_symlink() or f.stat().st_uid!=0 or f.stat().st_mode&0o077 or f.stat().st_size>16000:continue
   item=json.loads(f.read_text())
   if item.get('state')=='EXECUTED_PENDING_INDEPENDENT_READBACK' and item.get('job_id')==f.stem:
    count+=1
  except (OSError,ValueError,TypeError):continue
 return count

def cli_json(args,timeout=30):
 for attempt in range(3):
  try:
   p=subprocess.run(WP()+args,capture_output=True,text=True,timeout=timeout,check=False)
  except subprocess.TimeoutExpired as exc:
   # A read of Broker inventory timed out before the worker could claim.
   # Do not mark the Job failed or replay uncertain remote execution.
   raise TransientCentralReadBusy('ORIGINAL_CENTRAL_WPCLI_READ_TIMEOUT') from exc
  if p.returncode==0:
   return json.loads(p.stdout.strip().splitlines()[-1])
  if 'STAGING_WPCLI_BUSY' not in (p.stderr or ''):
   raise RuntimeError('ORIGINAL_BROKER_WPCLI_DENIED')
  time.sleep(attempt+1)
 raise TransientCentralReadBusy('ORIGINAL_CENTRAL_WPCLI_READ_SLOT_BUSY')

def claim_authorized_job(actor):
 # Original 305/306 has to exist already through an authenticated site owner.
 queued=cli_json(['--user='+str(actor),'mad4b','broker','pending','--limit=10'])
 if queued.get('contract')!='mad4b.central-vps-broker.v1':
  raise RuntimeError('ORIGINAL_306_PENDING_CONTRACT_MISMATCH')
 binding=json.loads((Path('/opt/mad4b-company-transport/native-operator-v1/target-runtime-bindings.v1.json')).read_text())
 target=binding['targets'].get('wayfatours:production',{})
 allowed=operation_digests()
 for entry in queued.get('pending',[]):
  if not isinstance(entry,dict) or entry.get('company')!='wayfatours' or entry.get('environment')!='production':continue
  if entry.get('target_host_id')!='srv2030908' or entry.get('broker_host_id')!='srv2030908':continue
  if entry.get('site_uuid')!=target.get('site_uuid') or int(entry.get('expires_epoch') or 0)<time.time()+90:continue
  job=entry.get('job_id')
  if not isinstance(job,str) or not HEX32.fullmatch(job):continue
  # Read-only inspection of the original Job's command type and SHA.
  code=('$id="'+job+'";$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
    'echo wp_json_encode(is_array($j)?["operation"=>$j["operation"]??null,'
    '"target_scope"=>$j["target_scope"]??null,"command_format"=>$j["command_format"]??null,'
    '"job_type"=>$j["job_type"]??null,"command_sha256"=>$j["command_sha256"]??null,'
    '"site_uuid"=>$j["site_uuid"]??null,"state"=>$j["state"]??null]:[]);')
  j=cli_json(['--user='+str(actor),'eval',code])
  if not (j.get('operation')=='company.remote.wpcli.full'
    and j.get('target_scope')=='wordpress'
    and j.get('command_format')=='wpcli.argv.json.v1'
    and j.get('job_type')=='signed_vps_wpcli'
    and j.get('command_sha256') in allowed
    and j.get('site_uuid')==entry['site_uuid']
    and j.get('state')=='QUEUED_FOR_VPS_BROKER_CLAIM'):
   continue
  # Sign the original broker claim; this does NOT create a grant or a job.
  f='/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py'
  spec=importlib.util.spec_from_file_location('original_broker_signer_for_claim',f)
  m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
  signer=m.original_signer();m.check_original_wordpress_signer(signer)
  now=int(time.time());exp=now+40;nonce=secrets.token_hex(16)
  fields=['mad4b.central-vps-broker.v1','claim',job,entry['company'],
          entry['site_uuid'],entry['environment'],entry['broker_host_id'],nonce,str(now),str(exp)]
  signature=base64.b64encode(signer.sign('\n'.join(fields).encode())).decode()
  row=cli_json(['mad4b','broker','claim','--job='+job,'--nonce='+nonce,
    '--issued='+str(now),'--expires='+str(exp),'--signature='+signature])
  if row.get('job_id')!=job or row.get('state')!='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY':
   raise RuntimeError('ORIGINAL_SIGNED_306_CLAIM_NOT_ACCEPTED')
  return job
 return None

def main():
 if os.geteuid()!=0:raise RuntimeError('ROOT_ONLY_NATIVE_WORKER')
 sys.path.insert(0,'/opt/mad4b-company-transport')
 from wpcli_dynamic_actor import resolve_actor
 try:
  actor=resolve_actor()
 except RuntimeError as exc:
  if str(exc) in ('CENTRAL_WORDPRESS_BUSY','WPCLI_DYNAMIC_ACTOR_BUSY'):
   raise TransientCentralReadBusy('CENTRAL_SITE_PROFILE_READ_SLOT_BUSY') from exc
  raise
 newly_claimed=claim_authorized_job(actor)
 d=cli_json(['--user='+str(actor),'mad4b','broker-authority','claimed','--limit=10'],35)
 if d.get('contract')!='mad4b.broker-exact-ssh-authority.v1' or not isinstance(d.get('claimed'),list):
  raise RuntimeError('ORIGINAL_312_CLAIMED_RESPONSE_INVALID')
 work=[]
 allowed=operation_digests()
 for row in d['claimed']:
  if not isinstance(row,dict):continue
  if row.get('target_id')!='srv2030908' or row.get('command_sha256') not in allowed:continue
  job=row.get('job_id')
  if not isinstance(job,str) or not HEX32.fullmatch(job):continue
  # A previously executed Schema read has an immutable root-only receipt.
  # Delivery can resume, but the target WP-CLI operation must never replay.
  operation=allowed[row['command_sha256']]
  reserved=(ROOT/'reserved'/(job+'.reserved')).exists()
  if reserved:
   if (operation=='schema.readback'
       and (ROOT/'receipts'/(job+'.json')).is_file()
       and int(row.get('expires_epoch') or 0)>time.time()+190):
    work.insert(0,(job,'schema.readback.resume'))
   continue
  # Starting a new target operation needs a longer original authority window.
  if int(row.get('expires_epoch') or 0)<time.time()+360:continue
  work.append((job,operation))
 # A previously executed schema read is important, but cannot starve a
 # currently authorized one-shot operation. Only receipt delivery is retried;
 # never reexecute a reserved target command.
 work.sort(key=lambda item: item[1]=='schema.readback.resume')
 if not work:
  # The signed plugin.install capability heartbeat is owned by the
  # independent root-only capability timer, NOT the original Job worker.
  # Keeping one owner avoids duplicate signatures/WordPress REST writes,
  # and a busy WP-CLI read never makes provider availability depend on 313.
  # The MU provider is separate; inability to attest it never disables
  # existing read-only or regular plugin.install operations.
  try:
   spec=importlib.util.spec_from_file_location('original_mu_capability',
       str(MU_CANDIDATE/'capability-heartbeat.py'))
   mu_heartbeat=importlib.util.module_from_spec(spec);spec.loader.exec_module(mu_heartbeat)
   mu_heartbeat.publish(actor)
  except Exception as exc:
   print('MU_DEPLOY_CAPABILITY_HEARTBEAT_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
  print(json.dumps({'state':'NO_CURRENT_EXACT_CLAIMED_WPCLI_JOB',
   'claimed_jobs_inspected':len(d['claimed']),'jobs_claimed':1 if newly_claimed else 0,'jobs_executed':0,
   'grants_created':0,'production_mutated':False,
   'immutable_host_execution_receipts_seen':native_receipt_inventory_count(),
   'host_receipts_are_not_original_313_certificates':True}));return
 job,operation=work[0]
 executor=(ROOT/'original-wpcli-executor.py' if operation=='read'
   else (SCHEMA_CANDIDATE/'original-wpcli-executor.py' if operation=='schema.readback'
     else (SCHEMA_CANDIDATE/'schema-receipt-resume.py' if operation=='schema.readback.resume'
     else (MU_CANDIDATE/'original-wpcli-executor.py' if operation=='mu-plugin.deploy'
      else CANDIDATE/'original-wpcli-executor.py'))))
 try:
  result=subprocess.run([sys.executable,str(executor),'--job',job,
    '--operation',operation] if operation not in ('read','schema.readback','schema.readback.resume') else [sys.executable,str(executor),'--job',job],
    capture_output=True,text=True,timeout=320,check=False)
 except subprocess.TimeoutExpired as exc:
  # The exact 312 command may have succeeded. Never restart an uncertain Job.
  receipt_seen=(ROOT/'receipts'/(job+'.json')).is_file()
  raise RuntimeError('ORIGINAL_EXECUTOR_TIMEOUT_320S_RECEIPT_RECONCILIATION_REQUIRED:'
    +('HOST_312_RECEIPT_EXISTS' if receipt_seen else 'HOST_RECEIPT_NOT_OBSERVED')) from exc
 if result.returncode:
  raise RuntimeError(safe_executor_failure(result.returncode,result.stderr))
 r=json.loads(result.stdout.strip().splitlines()[-1])
 if not(r.get('state')=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED' and
   r.get('job_id')==job and r.get('original_313_certified') is True):
  raise RuntimeError('ORIGINAL_313_RESULT_NOT_CERTIFIED')
 print(json.dumps({'state':r['state'],'job_id':job,'processed':1,
  'original_313_certified':True,'production_mutated':operation=='plugin.install'}))

if __name__=='__main__':
 try:main()
 except TransientCentralReadBusy:
  print(json.dumps({'state':'CENTRAL_WPCLI_BUSY_DEFERRED',
   'jobs_claimed':0,'jobs_executed':0,'grants_created':0,
   'production_mutated':False}))
 except Exception as exc:
  print('CLAIMED_NATIVE_313_WORKER_DENIED:'+str(exc),file=sys.stderr);sys.exit(2)
