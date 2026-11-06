#!/usr/bin/env python3
"""True original 305/306/312 -> signed 313 exact read-only WordPress executor.
Requires a PREEXISTING original claimed Job. Never issues Grants, Commands or Jobs.
A one-time local journal prevents reexecution after an uncertain outcome.
"""
from pathlib import Path
import argparse,base64,hashlib,importlib.util,json,os,re,stat,subprocess,sys,time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
ROOT=Path('/opt/mad4b-company-transport/native-313-independent')
RECEIPTS=ROOT/'receipts';RESERVED=ROOT/'reserved'
HOST='srv2030908'
ARG=['option','get','jet_engine_meta_boxes','--format=json']
DIGEST=hashlib.sha256(json.dumps(ARG,separators=(',',':')).encode()).hexdigest()
SCHEMA_CONTRACT=ROOT/'schema-readback-v1/schema-contract.py'
sys.path.insert(0, '/opt/mad4b-company-transport')
from central_wpcli_runtime import central_wp
WP=central_wp
FIELDS=['contract','host_id','job_id','target_id','grant_id','transport_id',
 'site_uuid','origin','environment','command_sha256','native_receipt_sha256',
 'expected_readback_sha256','readback_sha256','output_sha256','exit_code',
 'issued','expires','nonce']


def original_module(path,name):
 spec=importlib.util.spec_from_file_location(name,path)
 mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
 return mod

def pinned_target(a):
 p=Path('/opt/mad4b-company-transport/native-operator-v1/target-runtime-bindings.v1.json')
 st=p.lstat()
 if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077 or p.is_symlink():
  raise RuntimeError('ROOT_HOST_TARGET_BINDING_MISSING')
 policy=json.loads(p.read_text())
 r=policy['targets'].get(a['company']+':'+a['environment'],{})
 if not (policy.get('host_id')==HOST and r.get('canonical_origin')==a['origin']
    and r.get('site_uuid')==a['site_uuid']
    and r.get('target_database')=='wayfa_rebuild_wp'
    and a['company']=='wayfatours' and a['environment']=='production'):
  raise RuntimeError('EXACT_ENROLLED_TENANT_MISMATCH')
 # The legacy container name is observational data, not an authority.
 # Resolve the current Coolify PHP service by compose labels, live mount,
 # and an independent WordPress site identity check.
 resolver=original_module(str(ROOT/'coolify-php-runtime.py'),'coolify_exact_runtime')
 return {**r,'runtime_container':resolver.discover(a['origin'],a['site_uuid'])}

def wp_json(args,timeout=30):
 p=subprocess.run(WP()+args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=timeout)
 if p.returncode!=0:
  # Capacity is retryable. Never mistake a full two-slot WordPress pool for
  # a revoked signed 305/312 authority or expose signed arguments in logs.
  if p.returncode==75 and 'STAGING_WPCLI_BUSY' in (p.stderr or ''):
   raise RuntimeError('CENTRAL_WPCLI_SLOT_BUSY_DEFERRED')
  for code in ('WPCLI_RECEIPT_ALREADY_EXISTS','OWNER_WPCLI_GRANT_OR_CLAIM_NOT_ELIGIBLE',
               'WPCLI_RECEIPT_SCOPE_INVALID','INVALID_WPCLI_BROKER_SIGNATURE'):
   if code in (p.stderr or ''):raise RuntimeError(code)
  raise RuntimeError('ORIGINAL_WPCLI_CONTRACT_REJECTED')
 try:
  return json.loads(p.stdout.strip().splitlines()[-1])
 except (ValueError,IndexError) as exc:
  raise RuntimeError('ORIGINAL_WPCLI_RESPONSE_INVALID') from exc

def signed_payload(fields,broker):
 now=int(time.time());row=dict(fields)
 row['issued']=now;row['expires']=now+65;row['nonce']=os.urandom(16).hex()
 if list(row)!=FIELDS:raise RuntimeError('ORIGINAL_313_FIELD_ORDER_CHANGED')
 raw='\n'.join(str(row[k]) for k in FIELDS).encode()
 row['signature']=base64.b64encode(broker.sign(raw)).decode()
 return row

def run(job):
 if os.geteuid()!=0 or not re.fullmatch('[a-f0-9]{32}',job):
  raise RuntimeError('ROOT_AND_EXACT_JOB_REQUIRED')
 if RECEIPTS.is_symlink() or RESERVED.is_symlink():raise RuntimeError('UNSAFE_JOURNAL')
 for p in (RECEIPTS,RESERVED):
  p.mkdir(mode=0o700,exist_ok=True)
  st=p.stat()
  if st.st_uid!=0 or st.st_mode&0o077:raise RuntimeError('ROOT_ONLY_JOURNAL_REQUIRED')
 v=original_module('/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py','original312')
 a=v.verify(job,HOST,DIGEST)
 if not(a.get('verified') is True and a.get('eligible') is True
   and a.get('owner_enrolled_wpcli') is True and a.get('target_scope')=='wordpress'
   and a.get('operation')=='company.remote.wpcli.full'
   and a.get('command_sha256')==DIGEST):
  raise RuntimeError('ORIGINAL_305_306_312_NOT_ELIGIBLE')
 binding=pinned_target(a)
 if int(a.get('expires_epoch') or 0)<time.time()+75:
  raise RuntimeError('ORIGINAL_JOB_WINDOW_TOO_SHORT')
 broker=original_module('/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py','original_signer')
 signer=broker.original_signer();broker.check_original_wordpress_signer(signer)
 # Check availability of the *same* registered central 313 receipt endpoint
 # before reserving the one-use target command. A busy slot defers without
 # creating an uncertain host execution; final authority still checks 312.
 lane=wp_json(['mad4b','broker-remote-ssh','status'],25)
 if lane.get('registered') is not True:
  raise RuntimeError('ORIGINAL_313_RECEIPT_LANE_NOT_READY')
 lock=RESERVED/(job+'.reserved')
 fd=os.open(lock,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
 with os.fdopen(fd,'w') as f:
  f.write('reserved-for-one-exact-original-312-job\n');f.flush();os.fsync(f.fileno())
 # All authority checked before native execution. Command is literal, bounded,
 # and cannot accept user-provided arguments, shell, eval, target, or mutations.
 task=subprocess.run(['/usr/bin/docker','exec','-u','33:33',binding['runtime_container'],
    '/usr/local/bin/wp','option','get','jet_engine_meta_boxes','--format=json',
    '--path=/var/www/html','--skip-themes'],
    stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=25,check=False)
 if task.returncode!=0 or task.stderr or len(task.stdout)>120000:
  raise RuntimeError('NATIVE_SCHEMA_WPCLI_READ_INVALID')
 schema=original_module(SCHEMA_CONTRACT,'original_native_schema_contract')
 if a['origin']!='https://wayfatours.com' or a['environment']!='production':
  raise RuntimeError('NATIVE_SCHEMA_TARGET_SCOPE_DRIFT')
 try:
  data=json.loads(task.stdout)
  sha=schema.schema_hash(data)
 except (ValueError,TypeError,RuntimeError) as e:
  raise RuntimeError('NATIVE_SCHEMA_JSON_OR_DEFINITIONS_INVALID') from e
 record={'contract':'mad4b.native-original-313-execution.v1',
  'job_id':job,'company':a['company'],'environment':a['environment'],
  'site_uuid':a['site_uuid'],'origin':a['origin'],'target_id':HOST,
  'grant_id':a['grant_id'],'transport_id':a['transport_id'],
  'command_sha256':DIGEST,'output_sha256':sha,
  'expected_readback_sha256':sha,'exit_code':0,
  'state':'EXECUTED_PENDING_INDEPENDENT_READBACK',
  'executed_epoch':int(time.time()),'job_mutating':False,
  'schema_readback_sha256':sha,'schema_readback_type':'jet_engine_meta_boxes'}
 rp=RECEIPTS/(job+'.json');fd=os.open(rp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
 with os.fdopen(fd,'w') as f:
  f.write(json.dumps(record,sort_keys=True)+'\n');f.flush();os.fsync(f.fileno())
 receipt_sha=hashlib.sha256(rp.read_bytes()).hexdigest()
 now=int(time.time());nonce=os.urandom(16).hex();exp=now+50
 contract='mad4b.broker-remote-wpcli-receipt.v1'
 values=[contract,'receipt',HOST,job,HOST,nonce,str(now),str(exp),DIGEST,sha,sha,'0']
 signature=base64.b64encode(signer.sign('\n'.join(values).encode())).decode()
 from pathlib import Path as _P
 sys.path.insert(0,'/opt/mad4b-company-transport')
 from wpcli_dynamic_actor import resolve_actor
 actor=resolve_actor()
 signed=wp_json(['--user='+str(actor),'mad4b','broker-remote-ssh','wpcli-receipt',
  '--job='+job,'--target='+HOST,'--command_sha256='+DIGEST,
  '--output_sha256='+sha,'--readback_sha256='+sha,
  '--nonce='+nonce,'--issued='+str(now),'--expires='+str(exp),
  '--exit_code=0','--signature='+signature],30)
 if signed.get('state')!='PENDING_INDEPENDENT_NATIVE_READBACK' or signed.get('job_id')!=job:
  raise RuntimeError('ORIGINAL_313_PENDING_RECEIPT_MISSING')
 independent=subprocess.run([sys.executable,str(ROOT/'schema-readback-v1/independent-readback.py'),'--job',job],
    capture_output=True,text=True,timeout=70,check=False)
 if independent.returncode!=0:raise RuntimeError('INDEPENDENT_HOST_READBACK_NOT_ACCEPTED')
 result=json.loads(independent.stdout.strip().splitlines()[-1])
 if(result.get('state')!='INDEPENDENT_HOST_READBACK_PROOF_STORED'
  or result.get('job_id')!=job or result.get('native_receipt_sha256')!=receipt_sha
  or result.get('readback_sha256')!=sha):
  raise RuntimeError('INDEPENDENT_HOST_RECEIPT_CHANGED')
 values={'contract':'mad4b.broker-wpcli-native-finish.v1','host_id':HOST,
   'job_id':job,'target_id':HOST,'grant_id':a['grant_id'],'transport_id':a['transport_id'],
   'site_uuid':a['site_uuid'],'origin':a['origin'],'environment':a['environment'],
   'command_sha256':DIGEST,'native_receipt_sha256':receipt_sha,
   'expected_readback_sha256':sha,'readback_sha256':sha,'output_sha256':sha,
   'exit_code':0,'issued':int(time.time()),'expires':int(time.time())+65,
   'nonce':os.urandom(16).hex()}
 if list(values)!=FIELDS:raise RuntimeError('ORIGINAL_313_FINISH_FIELDS_CHANGED')
 values['signature']=base64.b64encode(signer.sign('\n'.join(str(values[k]) for k in FIELDS).encode())).decode()
 payload=base64.b64encode(json.dumps(values,separators=(',',':')).encode()).decode()
 finished=wp_json(['--user='+str(actor),'mad4b','broker-remote-ssh',
  'wpcli-finish','--payload='+payload],35)
 if finished.get('state')!='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED' or finished.get('verified') is not True:
  raise RuntimeError('ORIGINAL_313_FINAL_CERTIFICATION_NOT_PERSISTED')
 print(json.dumps({'state':'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED',
   'job_id':job,'native_receipt_sha256':receipt_sha,'readback_sha256':sha,
   'original_313_certified':True,'production_mutation':False}))

if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--job',required=True)
 args=parser.parse_args()
 try:run(args.job)
 except Exception as exc:
  print('ORIGINAL_NATIVE_313_STOPPED:'+str(exc),file=sys.stderr);sys.exit(2)
