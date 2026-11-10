import base64, hashlib, json, re, secrets, subprocess, time
from pathlib import Path
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
CONTRACT='mad4b.wpcli-exact-central-grant.v1'
OPERATION='mad4b.wpcli-exact-operation.v1'
HOST='srv2030908'
KEY=Path('/opt/mad4b-company-transport/broker-v1/host-ed25519-private.pem')
import sys
sys.path.insert(0,'/opt/mad4b-company-transport')
from central_wpcli_runtime import central_wp
# Original signed grant verifier unchanged; locate the enrolled Coolify runtime.
WP=central_wp()+['--user=1','mad4b','wpcli-grant']
TARGETS={'tours-central:staging':'srv2030908','all-royal-egypt:staging':'hosting-2'}
HEX32=re.compile('^[a-f0-9]{32}$')
HEX64=re.compile('^[a-f0-9]{64}$')
def canonical(payload):
 if not isinstance(payload,dict) or payload.get('contract')!=OPERATION: raise ValueError('CONTRACT_INVALID')
 a,b=payload.get('args'),payload.get('readback_args')
 for args in (a,b):
  if not isinstance(args,list) or not 1<=len(args)<=64: raise ValueError('ARGS_INVALID')
  for part in args:
   if not isinstance(part,str) or not part or len(part)>2048 or any(c in part for c in ('\x00','\n','\r')):
    raise ValueError('ARG_INVALID')
   if any(part==f or part.startswith(f+'=') for f in ('--ssh','--path','--url','--http')):
    raise ValueError('TARGET_OVERRIDE_DENIED')
 digest=payload.get('expected_readback_sha256')
 if not isinstance(digest,str) or not HEX64.fullmatch(digest): raise ValueError('READBACK_HASH_INVALID')
 canonical=json.dumps({'contract':OPERATION,'args':a,'readback_args':b,
  'expected_readback_sha256':digest},sort_keys=True,separators=(',',':'),ensure_ascii=False)
 if len(canonical.encode())>3900: raise ValueError('SOURCE_COMMAND_OVERSIZED')
 return canonical,hashlib.sha256(canonical.encode()).hexdigest()
def verify(request,target,digest):
 if not isinstance(request,str) or not HEX32.fullmatch(request):raise ValueError('REQUEST_INVALID')
 if target not in TARGETS.values() or not isinstance(digest,str) or not HEX64.fullmatch(digest):
  raise ValueError('TARGET_OR_COMMAND_INVALID')
 now=int(time.time());expiry=now+40;nonce=secrets.token_hex(16)
 fields=[CONTRACT,'verify',HOST,request,target,digest,nonce,str(now),str(expiry)]
 key=serialization.load_pem_private_key(KEY.read_bytes(),password=None)
 if not isinstance(key,Ed25519PrivateKey):raise RuntimeError('HOST_SIGNER_INVALID')
 signature=base64.b64encode(key.sign('\n'.join(fields).encode())).decode()
 cmd=WP+['verify','--request='+request,'--target='+target,'--command_sha256='+digest,
  '--nonce='+nonce,'--issued='+str(now),'--expires='+str(expiry),'--signature='+signature]
 proc=subprocess.run(cmd,capture_output=True,text=True,timeout=42,check=False)
 if proc.returncode:raise RuntimeError('SIGNED_CENTRAL_VERIFICATION_FAILED')
 result=json.loads(proc.stdout.strip().splitlines()[-1])
 if result.get('contract')!=CONTRACT or not isinstance(result.get('eligible'),bool):
  raise RuntimeError('CENTRAL_CONTRACT_INVALID')
 if result['eligible'] and not (result.get('verified') is True and
  result.get('request_id')==request and result.get('target_id')==target and
  result.get('command_sha256')==digest and result.get('central_scope_only') is True and
  result.get('shell_executed') is False and result.get('grant_created') is False and
  isinstance(result.get('expires_epoch'),int) and result['expires_epoch']>time.time()):
  raise RuntimeError('CENTRAL_VERIFIER_IDENTITY_DRIFT')
 return result
