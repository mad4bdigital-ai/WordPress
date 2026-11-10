#!/usr/bin/env python3
"""Authorized VPS host-policy rotation with original pinned Ed25519 key.

One signed rotation, no remote job execution. The old central 305 pair is retained
and revoked, with a verified new standing pair. Never retries an uncertain write.
"""
import argparse,base64,fcntl,hashlib,json,os,pathlib,re,secrets,stat,subprocess,sys,time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
ROOT=pathlib.Path('/opt/mad4b-company-transport')
POLICY=ROOT/'native-operator-v1/host-authority.json'
PRIVATE=ROOT/'broker-v1/host-ed25519-private.pem'
sys.path.insert(0,str(ROOT))
from central_wpcli_runtime import central_wp
# Reuse the original enrolled Central Site Profile, not the former Compose name.
WP=central_wp()
BASE=[]
FIELDS=['contract','action','host_id','company','site_uuid','canonical_origin',
 'environment','old_policy_sha256','new_policy_sha256','host_key_fingerprint',
 'issued','expires','nonce']
EXPR=r"""
$r=MAD4B_Native_VPS_Principal_V1::existing();
$s=MAD4B_Native_VPS_Principal_V1::scope();
echo wp_json_encode([
 'contract'=>'mad4b.native-policy-old-slot.v1',
 'ledger_verified'=>is_array($r)&&MAD4B_Native_VPS_Principal_V1::ledger($r,$s),
 'policy_sha256'=>$r['policy_sha256']??null,
 'site_uuid'=>$s['site_uuid']??null,
 'host_key_fingerprint'=>$r['host_key_fingerprint']??null
]);
"""
def rootfile(p,maximum):
 if p.is_symlink():raise RuntimeError('ROOT_FILE_SYMLINK_REJECTED')
 s=p.stat()
 if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode & 0o077 or s.st_size>maximum:
  raise RuntimeError('ROOT_ONLY_FILE_REQUIRED')
 return p.read_bytes()
def run(args,timeout=35):
 r=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
 if r.returncode:raise RuntimeError('SIGNED_ORIGINAL_OPERATION_DENIED:'+r.stderr[-190:])
 return r.stdout
def current():
 if os.geteuid()!=0:raise RuntimeError('ROOT_NATIVE_SIGNER_REQUIRED')
 raw=rootfile(POLICY,65536);policy=json.loads(raw)
 scope=policy.get('scopes',{}).get('tours-central:staging',{})
 if (policy.get('contract')!='mad4b.native-vps-operator.v1' or
     policy.get('host_id')!='srv2030908' or policy.get('revoked') is not False
     or policy.get('allow_general_shell') is not True
     or scope.get('allow_operator') is not True):
  raise RuntimeError('ORIGINAL_ROOT_OPERATOR_POLICY_NOT_AUTHORIZED')
 k=serialization.load_pem_private_key(rootfile(PRIVATE,16000),password=None)
 if not isinstance(k,Ed25519PrivateKey):raise RuntimeError('ORIGINAL_SIGNER_INVALID')
 pinned=run([*WP,'option','get','mad4b_central_broker_signing_public_v1',*BASE]).strip()
 if not secrets.compare_digest(base64.b64decode(pinned,validate=True),
     k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)):
  raise RuntimeError('ORIGINAL_PINNED_SIGNER_MISMATCH')
 host=run(['ssh-keygen','-lf','/etc/ssh/ssh_host_ed25519_key.pub','-E','sha256'],12).split()[1]
 r=json.loads(run([*WP,'eval',EXPR,*BASE]).strip().splitlines()[-1])
 if (r.get('contract')!='mad4b.native-policy-old-slot.v1'
  or r.get('ledger_verified') is not True
  or not isinstance(r.get('site_uuid'),str)
  or not isinstance(r.get('policy_sha256'),str)
  or r.get('host_key_fingerprint')!=host):
  raise RuntimeError('ORIGINAL_PRINCIPAL_SLOT_OR_HOST_PROOF_INVALID')
 new=hashlib.sha256(raw).hexdigest()
 if new==r['policy_sha256']:return {'already_current':True,'new_hash':new}
 return {'already_current':False,'old_hash':r['policy_sha256'],
    'new_hash':new,'site_uuid':r['site_uuid'],'host_fingerprint':host,'signer':k}
def rotate(p):
 now=int(time.time())
 a={'contract':'mad4b.native-vps-policy-rotation.v1','action':'rotate',
  'host_id':'srv2030908','company':'tours-central','site_uuid':p['site_uuid'],
  'canonical_origin':'https://tours.dev.mad4b.com','environment':'staging',
  'old_policy_sha256':p['old_hash'],'new_policy_sha256':p['new_hash'],
  'host_key_fingerprint':p['host_fingerprint'],
  'issued':now,'expires':now+40,'nonce':secrets.token_hex(16)}
 assert list(a)==FIELDS
 signed='\n'.join(str(a[k]) for k in FIELDS).encode()
 a['signature']=base64.b64encode(p['signer'].sign(signed)).decode()
 encoded=base64.b64encode(json.dumps(a,separators=(',',':')).encode()).decode()
 output=run([*WP,'mad4b','native-vps-policy','rotate',
  '--proof='+encoded,*BASE],timeout=45)
 rows=[json.loads(x) for x in output.splitlines() if x.startswith('{')]
 if len(rows)!=1 or rows[0].get('state')!='ORIGINAL_PRINCIPAL_POLICY_ROTATED':
  raise RuntimeError('ROTATION_READBACK_NOT_VERIFIED')
 if (rows[0].get('old_pair_revoked') is not True
   or rows[0].get('original_principal_ledger_verified') is not True
   or rows[0].get('execution_performed') is not False):
  raise RuntimeError('ROTATION_RECEIPT_NOT_CERTIFIED')
 return rows[0]
def main():
 a=argparse.ArgumentParser()
 a.add_argument('action',choices=['plan','rotate'])
 args=a.parse_args()
 with open('/run/lock/mad4b-native-vps-policy-rotate.lock','a+') as f:
  fcntl.flock(f,fcntl.LOCK_EX)
  p=current()
  if args.action=='plan':
   print(json.dumps({'state':'ORIGINAL_POLICY_ROTATION_NOT_REQUIRED' if p['already_current'] else 'ORIGINAL_SIGNED_POLICY_ROTATION_READY',
      'original_pinned_signer_verified':True,
      'original_principal_ledger_verified':True,
      'host_key_verified':True,
      'grant_issued':False,'production_mutated':False}))
   return
  if p['already_current']:
   print(json.dumps({'state':'ORIGINAL_HOST_POLICY_CURRENT','grant_issued':False,
     'execution_performed':False,'production_mutated':False}));return
  response=rotate(p)
  after=current()
  if not after['already_current']:
   raise RuntimeError('NEW_CENTRAL_POLICY_READBACK_MISMATCH')
  print(json.dumps({'state':response['state'],'old_pair_revoked':response['old_pair_revoked'],
    'ledger_verified':True,'execution_performed':False,'production_mutated':False}))
if __name__=='__main__':
 try:main()
 except (OSError,RuntimeError,ValueError,subprocess.SubprocessError) as e:
  print('ORIGINAL_NATIVE_POLICY_ROTATION_DENIED:'+str(e)[:280],file=sys.stderr);sys.exit(2)
