#!/usr/bin/env python3
"""Resume ONLY the host-signed read-only Schema receipt phase of a live 305/312 Job.

No target operation, new Job, Grant, Claim or re-execution is possible here.
An expired Job remains unfinishable; only the original owner can create a new one.
"""
import argparse,base64,hashlib,json,os,re,stat,subprocess,sys,time
from pathlib import Path
ROOT=Path('/opt/mad4b-company-transport/native-313-independent')
BASE=ROOT/'schema-readback-v1'
HEX32=re.compile(r'^[a-f0-9]{32}$')

def module(path,name):
 import importlib.util
 spec=importlib.util.spec_from_file_location(name,path)
 loaded=importlib.util.module_from_spec(spec);spec.loader.exec_module(loaded)
 return loaded

def root_bytes(p,max_bytes=16000):
 s=p.lstat()
 if (not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077
     or s.st_size<1 or s.st_size>max_bytes or p.is_symlink()):
  raise RuntimeError('ROOT_ONLY_IMMUTABLE_JOB_EVIDENCE_REQUIRED')
 return p.read_bytes()

def existing_central(job,engine):
 # Read-only original WordPress option lookup. No WP user is impersonated.
 script=('$p=get_option("mad4b_broker_remote_wpcli_receipt_v1_'+   job+'",false);$v=get_option("mad4b_native_313_independent_proof_v1_'+   job+'",false);echo wp_json_encode(["pending"=>$p,"proof"=>$v]);')
 proc=subprocess.run(engine.WP()+['eval',script],capture_output=True,text=True,timeout=30,check=False)
 if proc.returncode:
  if 'STAGING_WPCLI_BUSY' in proc.stderr:
   raise RuntimeError('CENTRAL_WPCLI_SLOT_BUSY_DEFERRED')
  raise RuntimeError('CENTRAL_RECEIPT_RECORD_READBACK_UNAVAILABLE')
 try:return json.loads(proc.stdout.strip().splitlines()[-1])
 except (ValueError,IndexError,TypeError) as exc:
  raise RuntimeError('CENTRAL_RECEIPT_RECORD_FORMAT_INVALID') from exc

def matching(row,record,keys):
 return isinstance(row,dict) and all(row.get(k)==record.get(k) for k in keys)

def resume(job):
 if os.geteuid()!=0 or not HEX32.fullmatch(job):
  raise RuntimeError('ROOT_AND_EXACT_JOB_REQUIRED')
 engine=module(str(BASE/'original-wpcli-executor.py'),'schema_original_execution_contract')
 lock=ROOT/'reserved'/(job+'.reserved')
 receipt=ROOT/'receipts'/(job+'.json')
 if root_bytes(lock,256).strip()!=b'reserved-for-one-exact-original-312-job':
  raise RuntimeError('ORIGINAL_HOST_RESERVATION_MISMATCH')
 blob=root_bytes(receipt)
 r=json.loads(blob)
 required={'contract':'mad4b.native-original-313-execution.v1','job_id':job,
  'company':'wayfatours','environment':'production','origin':'https://wayfatours.com',
  'target_id':engine.HOST,'command_sha256':engine.DIGEST,'exit_code':0,
  'job_mutating':False,'state':'EXECUTED_PENDING_INDEPENDENT_READBACK',
  'schema_readback_type':'jet_engine_meta_boxes'}
 if not matching(r,required,list(required)):
  raise RuntimeError('ORIGINAL_HOST_RECEIPT_SCOPE_MISMATCH')
 sha=r.get('output_sha256')
 if (not isinstance(sha,str) or not re.fullmatch(r'[a-f0-9]{64}',sha)
     or r.get('expected_readback_sha256')!=sha
     or r.get('schema_readback_sha256')!=sha):
  raise RuntimeError('ORIGINAL_HOST_SCHEMA_DIGEST_MISMATCH')
 auth=engine.original_module('/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py','resume_original_312')
 a=auth.verify(job,engine.HOST,engine.DIGEST)
 if not (a.get('verified') is True and a.get('eligible') is True
    and a.get('owner_enrolled_wpcli') is True
    and a.get('operation')=='company.remote.wpcli.full'
    and a.get('target_scope')=='wordpress'):
  raise RuntimeError('EXACT_ORIGINAL_312_NOT_ELIGIBLE_FOR_RECEIPT_DELIVERY')
 # A complete signed 312 -> independent 313 requires its ORIGINAL live window.
 if int(a.get('expires_epoch') or 0)<time.time()+180:
  raise RuntimeError('EXPIRED_OR_INSUFFICIENT_ORIGINAL_312_WINDOW_NO_REPLAY')
 if (not isinstance(r.get('executed_epoch'),int)
     or r['executed_epoch']>time.time()
     or r['executed_epoch']>a['expires_epoch']):
  raise RuntimeError('ORIGINAL_HOST_EXECUTION_TIME_INVALID')
 for k in ('company','environment','site_uuid','origin','grant_id','transport_id'):
  if a.get(k)!=r.get(k):
   raise RuntimeError('ORIGINAL_312_HOST_RECEIPT_SCOPE_DRIFT:'+k)
 engine.pinned_target(a)  # Independent live target binding check, not execution.
 actor_module=module('/opt/mad4b-company-transport/wpcli_dynamic_actor.py','resume_central_actor')
 actor=actor_module.resolve_actor()
 broker=engine.original_module('/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py','resume_original_signer')
 signer=broker.original_signer();broker.check_original_wordpress_signer(signer)
 central=existing_central(job,engine)
 pending=central.get('pending')
 if pending is False or pending is None:
  now=int(time.time());nonce=os.urandom(16).hex();expires=now+50
  values=['mad4b.broker-remote-wpcli-receipt.v1','receipt',engine.HOST,job,
   engine.HOST,nonce,str(now),str(expires),engine.DIGEST,sha,sha,'0']
  signature=base64.b64encode(signer.sign('\n'.join(values).encode())).decode()
  signed=engine.wp_json(['--user='+str(actor),'mad4b','broker-remote-ssh','wpcli-receipt',
   '--job='+job,'--target='+engine.HOST,'--command_sha256='+engine.DIGEST,
   '--output_sha256='+sha,'--readback_sha256='+sha,'--nonce='+nonce,
   '--issued='+str(now),'--expires='+str(expires),'--exit_code=0','--signature='+signature],30)
  if signed.get('state')!='PENDING_INDEPENDENT_NATIVE_READBACK' or signed.get('job_id')!=job:
   raise RuntimeError('ORIGINAL_313_PENDING_RECEIPT_MISSING')
 else:
  expected={'contract':'mad4b.broker-remote-wpcli-receipt.v1','job_id':job,
    'command_sha256':engine.DIGEST,'output_sha256':sha,'readback_sha256':sha,
    'site_uuid':r['site_uuid'],'origin':r['origin'],'exit_code':0,
    'state':'PENDING_INDEPENDENT_NATIVE_READBACK'}
  if not matching(pending,expected,list(expected)) or pending.get('host_signed_attestation_received') is not True:
   raise RuntimeError('EXISTING_CENTRAL_313_PENDING_SCOPE_MISMATCH')
 proof=central.get('proof')
 receipt_hash=hashlib.sha256(blob).hexdigest()
 if proof is False or proof is None:
  independent=subprocess.run([sys.executable,str(BASE/'independent-readback.py'),'--job',job],
      capture_output=True,text=True,timeout=70,check=False)
  if independent.returncode:
   raise RuntimeError('INDEPENDENT_HOST_READBACK_DELIVERY_DEFERRED_OR_DENIED')
  result=json.loads(independent.stdout.strip().splitlines()[-1])
  if not (result.get('state')=='INDEPENDENT_HOST_READBACK_PROOF_STORED'
      and result.get('job_id')==job and result.get('native_receipt_sha256')==receipt_hash
      and result.get('readback_sha256')==sha):
   raise RuntimeError('ORIGINAL_313_INDEPENDENT_PROOF_NOT_STORED')
 else:
  expected={'contract':'mad4b.native-313-independent-host-proof.v1','job_id':job,
     'native_receipt_sha256':receipt_hash,'readback_sha256':sha,
     'command_sha256':engine.DIGEST,'origin':r['origin'],'site_uuid':r['site_uuid']}
  if not matching(proof,expected,list(expected)):
   raise RuntimeError('EXISTING_SIGNED_INDEPENDENT_PROOF_SCOPE_MISMATCH')
 fields={'contract':'mad4b.broker-wpcli-native-finish.v1','host_id':engine.HOST,
   'job_id':job,'target_id':engine.HOST,'grant_id':a['grant_id'],
   'transport_id':a['transport_id'],'site_uuid':a['site_uuid'],'origin':a['origin'],
   'environment':a['environment'],'command_sha256':engine.DIGEST,
   'native_receipt_sha256':receipt_hash,'expected_readback_sha256':sha,
   'readback_sha256':sha,'output_sha256':sha,'exit_code':0,
   'issued':int(time.time()),'expires':int(time.time())+65,'nonce':os.urandom(16).hex()}
 if list(fields)!=engine.FIELDS:
  raise RuntimeError('ORIGINAL_313_FINISH_FIELDS_CHANGED')
 fields['signature']=base64.b64encode(signer.sign('\n'.join(str(fields[k]) for k in engine.FIELDS).encode())).decode()
 payload=base64.b64encode(json.dumps(fields,separators=(',',':')).encode()).decode()
 finish=engine.wp_json(['--user='+str(actor),'mad4b','broker-remote-ssh','wpcli-finish',
    '--payload='+payload],35)
 if finish.get('state')!='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED' or finish.get('verified') is not True:
  raise RuntimeError('ORIGINAL_313_FINAL_CERTIFICATION_NOT_PERSISTED')
 print(json.dumps({'job_id':job,'state':'WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED',
  'native_receipt_sha256':receipt_hash,'readback_sha256':sha,
  'original_313_certified':True,'native_command_reexecuted':False,'production_mutation':False}))

if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--job',required=True)
 args=parser.parse_args()
 try:resume(args.job)
 except Exception as exc:
  print('ORIGINAL_SCHEMA_RECEIPT_ONLY_STOPPED:'+str(exc),file=sys.stderr);sys.exit(2)
