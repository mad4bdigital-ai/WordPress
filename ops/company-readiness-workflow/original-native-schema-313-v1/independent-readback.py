#!/usr/bin/env python3
"""Independent, root-owned post-execution WordPress readback and signed 313 proof.
Only processes an already-claimed, exact 305/306/312 read-only Job.
Never creates grants, claims, jobs, or an executor receipt.
"""
from pathlib import Path
import argparse,base64,hashlib,json,os,re,stat,subprocess,sys,time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
ROOT=Path('/opt/mad4b-company-transport/native-313-independent')
SCHEMA_CONTRACT=ROOT/'schema-readback-v1/schema-contract.py'
RECEIPTS=ROOT/'receipts'
KEY=ROOT/'root-only-verifier-ed25519.key'
sys.path.insert(0, '/opt/mad4b-company-transport')
from central_wpcli_runtime import central_wp
WP=central_wp
FIELDS=['contract','job_id','company','environment','site_uuid','origin',
 'target_id','grant_id','transport_id','command_sha256','native_receipt_sha256',
 'output_sha256','expected_readback_sha256','readback_sha256','issued','expires','nonce']
HEX32=re.compile(r'^[a-f0-9]{32}$')

def protected(file,max_bytes=16000):
 st=file.lstat()
 if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077 or st.st_size>max_bytes or file.is_symlink():
  raise RuntimeError('ROOT_ONLY_EVIDENCE_REQUIRED')
 return file.read_bytes()

def main(job):
 if os.geteuid()!=0 or not HEX32.fullmatch(job):raise RuntimeError('ROOT_OR_JOB_INVALID')
 path=RECEIPTS/(job+'.json')
 blob=protected(path)
 r=json.loads(blob)
 must={'contract':'mad4b.native-original-313-execution.v1','job_id':job,'company':'wayfatours',
       'environment':'production','origin':'https://wayfatours.com',
       'target_id':'srv2030908','exit_code':0,'state':'EXECUTED_PENDING_INDEPENDENT_READBACK'}
 for key,expected in must.items():
  if r.get(key)!=expected:raise RuntimeError('NATIVE_RECEIPT_SCOPE_MISMATCH:'+key)
 spec=__import__('importlib.util',fromlist=['spec_from_file_location']).spec_from_file_location('independent_schema_contract',SCHEMA_CONTRACT)
 schema=__import__('importlib.util',fromlist=['module_from_spec']).module_from_spec(spec);spec.loader.exec_module(schema)
 if (r.get('command_sha256')!=schema.DIGEST or
     r.get('schema_readback_type')!='jet_engine_meta_boxes' or
     r.get('schema_readback_sha256')!=r.get('output_sha256')):
  raise RuntimeError('INDEPENDENT_SCHEMA_OPERATION_OR_HASH_MISMATCH')
 if not r.get('command_sha256') or not re.fullmatch('[a-f0-9]{64}',r['command_sha256']):
  raise RuntimeError('COMMAND_HASH_MISSING')
 original=hashlib.sha256(blob).hexdigest()
 # Independent original 312 signed authority call; not a stored boolean.
 sys.path.insert(0,'/opt/mad4b-company-transport/broker-v1')
 import importlib.util
 mod='/opt/mad4b-company-transport/broker-v1/vps-broker-exact-authority.py'
 spec=importlib.util.spec_from_file_location('independent_original_312',mod)
 authority=importlib.util.module_from_spec(spec);spec.loader.exec_module(authority)
 a=authority.verify(job,r['target_id'],r['command_sha256'])
 if not(a.get('verified') is True and a.get('eligible') is True
   and a.get('owner_enrolled_wpcli') is True and a.get('target_scope')=='wordpress'
   and a.get('operation')=='company.remote.wpcli.full'):
  raise RuntimeError('INDEPENDENT_ORIGINAL_312_NOT_ELIGIBLE')
 for key in ('company','environment','site_uuid','origin','target_id','grant_id','transport_id'):
  if a.get(key)!=r.get(key):raise RuntimeError('ORIGINAL_SCOPE_MISMATCH:'+key)
 if int(a.get('expires_epoch') or 0)<time.time()+25:
  raise RuntimeError('ORIGINAL_JOB_EXPIRES_BEFORE_VERIFICATION')
 # A separate PHP process recomputes normalized schema from live WordPress;
 # do NOT treat the executor JSON encoding or its stored receipt as truth.
 probe=('define("WP_USE_THEMES",false);require "/var/www/html/wp-load.php";'
        'if(untrailingslashit(home_url("/"))!=="https://wayfatours.com"||'
        'wp_get_environment_type()!=="production")throw new RuntimeException("SITE_SCOPE_CHANGED");'
        'echo json_encode(["origin"=>untrailingslashit(home_url("/")),'
        '"environment"=>wp_get_environment_type(),'
        '"boxes"=>get_option("jet_engine_meta_boxes",null)],'
        'JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);')
 resolver_path=ROOT/'coolify-php-runtime.py'
 spec=importlib.util.spec_from_file_location('independent_coolify_runtime',resolver_path)
 runtime=importlib.util.module_from_spec(spec);spec.loader.exec_module(runtime)
 target_container=runtime.discover(r['origin'],r['site_uuid'])
 p=subprocess.run(['/usr/bin/docker','exec','-u','33:33',target_container,
   'php','-d','memory_limit=512M','-r',probe],capture_output=True,timeout=38,check=False)
 if p.returncode!=0 or p.stderr or len(p.stdout)>120000:
  raise RuntimeError('FRESH_INDEPENDENT_SCHEMA_PHP_READBACK_INVALID')
 try:
  report=json.loads(p.stdout)
  if report['origin']!=r['origin'] or report['environment']!=r['environment']:
   raise RuntimeError('INDEPENDENT_SCHEMA_SITE_IDENTITY_CHANGED')
  digest=schema.schema_hash(report['boxes'])
 except (KeyError,ValueError,TypeError) as exc:
  raise RuntimeError('FRESH_INDEPENDENT_SCHEMA_JSON_INVALID') from exc
 if digest!=r.get('output_sha256') or digest!=r.get('expected_readback_sha256'):
  raise RuntimeError('INDEPENDENT_SCHEMA_OUTPUT_HASH_MISMATCH')
 now=int(time.time());v={
  'contract':'mad4b.native-313-independent-host-proof.v1','job_id':job,
  'company':r['company'],'environment':r['environment'],'site_uuid':r['site_uuid'],
  'origin':r['origin'],'target_id':r['target_id'],'grant_id':r['grant_id'],
  'transport_id':r['transport_id'],'command_sha256':r['command_sha256'],
  'native_receipt_sha256':original,'output_sha256':r['output_sha256'],
  'expected_readback_sha256':digest,'readback_sha256':digest,
  'issued':now,'expires':now+70,'nonce':os.urandom(16).hex()}
 raw='\n'.join(str(v[k]) for k in FIELDS).encode()
 key=Ed25519PrivateKey.from_private_bytes(protected(KEY,64))
 v['signature']=base64.b64encode(key.sign(raw)).decode()
 # One authenticated WordPress principal, but original 312/independent signature
 # remains the authority; --user alone never creates a Job or a Grant.
 from pathlib import Path as _P
 sys.path.insert(0,'/opt/mad4b-company-transport')
 from wpcli_dynamic_actor import resolve_actor
 actor=resolve_actor()
 payload=base64.b64encode(json.dumps(v,separators=(',',':')).encode()).decode()
 q=subprocess.run(WP()+['--user='+str(actor),'mad4b','native-313-proof',
  'ingest','--payload='+payload],capture_output=True,text=True,timeout=30,check=False)
 if q.returncode!=0:raise RuntimeError('ORIGINAL_313_INDEPENDENT_INGEST_DENIED')
 answer=json.loads(q.stdout.strip().splitlines()[-1])
 if answer.get('verified') is not True or answer.get('job_id')!=job:
  raise RuntimeError('ORIGINAL_313_PROOF_INGEST_NOT_VERIFIED')
 print(json.dumps({'state':'INDEPENDENT_HOST_READBACK_PROOF_STORED',
   'job_id':job,'native_receipt_sha256':original,'readback_sha256':digest,
   'proof_nonce':v['nonce'],'original_313_certified':False,'production_mutation':False}))

if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--job',required=True)
 args=parser.parse_args()
 try:main(args.job)
 except Exception as exc:
  print('INDEPENDENT_313_READBACK_DENIED:'+str(exc),file=sys.stderr);sys.exit(2)
