#!/usr/bin/env python3
"""Signed exact-company host proof, verified by Original Core. Never executes tenant writes.

The only durable effect is a bounded host-proof observation in CENTRAL WordPress.
No grant creation, transport activation, queue claim, WP-CLI mutation or rollback.
"""
import argparse,base64,fcntl,hashlib,json,os,pathlib,re,secrets,shlex,stat,subprocess,sys,time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=pathlib.Path('/opt/mad4b-company-transport')
POLICY=ROOT/'native-operator-v1/host-authority.json'
SIGNER=ROOT/'broker-v1/host-ed25519-private.pem'
BASE=['--path=/var/www/html','--skip-themes']
FIELDS=['contract','action','company','environment','site_uuid','native_profile_uuid',
 'canonical_origin','target_database','target_container','target_wp_actor_id',
 'transport_id','grant_id','host_id','host_policy_sha256','host_key_fingerprint',
 'issued','expires','nonce']
CONTRACT='mad4b.company-native-host-proof.v1'
CNAME=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
def cmd(args,timeout=40):
 p=subprocess.run(args,text=True,capture_output=True,timeout=timeout)
 if p.returncode:raise RuntimeError('HOST_OR_WORDPRESS_READBACK_FAILED:'+p.stderr[-150:])
 return p.stdout
def root_file(path,maxbytes):
 if path.is_symlink():raise RuntimeError('ROOT_KEY_OR_POLICY_SYMLINK_REJECTED')
 s=path.stat()
 if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077 or s.st_size>maxbytes:
  raise RuntimeError('ROOT_ONLY_POLICY_OR_KEY_REQUIRED')
 return path.read_bytes()
def wp(container,command,timeout=40):
 raw=cmd(['docker','exec','-u','33:33',container,'php','-d','memory_limit=512M',
   '/usr/local/bin/wp','eval',command,*BASE],timeout)
 start=raw.lstrip()
 obj,end=json.JSONDecoder().raw_decode(start)
 suffix=start[end:].strip()
 # Elementor occasionally appends a deprecation notice to stdout.
 # Accept that known non-command diagnostic only; reject extra JSON or output.
 if not isinstance(obj,dict) or (suffix and not suffix.startswith('PHP: ')):
  raise RuntimeError('EXACT_WORDPRESS_JSON_READBACK_REQUIRED')
 return obj
CENTRAL_PHP=r"""
if(DB_NAME!=='etg_commerce_staging'||wp_get_environment_type()!=='staging'
 ||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')
 WP_CLI::error('EXACT_CENTRAL_WORDPRESS_REQUIRED');
$c=(string)getenv('MAD4B_HOST_PROOF_COMPANY');$env=(string)getenv('MAD4B_HOST_PROOF_ENV');
$t=MAD4B_Company_Environment_Registry_V2::target($c,$env);
$d=MAD4B_Company_Execution_Resolver_V1::discover()[$c.':'.$env]??[];
$slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$c.'_'.$env,false);
echo wp_json_encode([
 'site_uuid'=>$t['site_uuid']??null,'origin'=>$t['canonical_origin']??null,
 'environment'=>$t['environment']??null,'company_code'=>$t['company_id']??null,
 'profile_uuid'=>$d['profile_uuid']??null,
 'slot_exists'=>is_array($slot),
 'transport_id'=>$slot['transport_id']??null,'grant_id'=>$slot['grant_id']??null,
 'original_persistent_enrollment'=>
   MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($c,$env)['state']??null,
 'original_standing_policy_sha256'=>MAD4B_Native_VPS_Principal_V1::existing()['policy_sha256']??null,
 'original_host_key_fingerprint'=>MAD4B_Native_VPS_Principal_V1::existing()['host_key_fingerprint']??null
]);
"""
TARGET_PHP=r"""
$ident=get_option('mad4b_company_site_identity_v1',[]);
$uidList=MAD4B_SCP_Site_Profile::oauth_user_ids();
$uid=is_array($uidList)&&count($uidList)===1?(int)$uidList[0]:0;
$u=$uid>0?get_user_by('id',$uid):false;
echo wp_json_encode([
 'company'=>$ident['company_code']??null,'environment'=>$ident['environment']??null,
 'site_uuid'=>$ident['site_uuid']??null,'profile_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
 'origin'=>untrailingslashit(home_url('/')),'db'=>DB_NAME,
 'user_count'=>is_array($uidList)?count($uidList):0,'actor_user_id'=>$uid,
 'actor_manage_options'=>(bool)($u&&user_can($u,'manage_options')),
 'actor_install_plugins'=>(bool)($u&&user_can($u,'install_plugins')),
 'site_profile_urls_enrolled'=>MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
]);
"""
# Exact externally registered SSH endpoints. No command or host is taken from
# an untrusted queued job. Root policy must match registry binding AND site root.
INVENTORY=ROOT/'broker-v1/external-site-ssh-inventory.v1.json'
SSH_ROOT=pathlib.Path('/opt/hostinger-shell-mcp/external-targets')
def remote_wordpress_read(scope,company,env,php):
 binding=scope.get('registry_binding')
 if not isinstance(binding,str) or binding.count(':')!=1:
  raise RuntimeError('EXTERNAL_REGISTERED_HOST_SCOPE_REQUIRED')
 registry,scope_env=binding.split(':')
 if not re.fullmatch(r'hosting-[0-9]{1,3}',registry) or scope_env!=env or env!='staging':
  raise RuntimeError('EXTERNAL_SCOPE_ENVIRONMENT_NOT_AUTHORIZED')
 inventory=json.loads(root_file(INVENTORY,32768))
 if inventory.get('contract')!='mad4b.external-company-ssh-inventory.v1':
  raise RuntimeError('ORIGINAL_EXTERNAL_SSH_INVENTORY_INVALID')
 cfg=inventory.get('targets',{}).get(registry)
 if not isinstance(cfg,dict) or cfg.get('company')!=company or cfg.get('environment')!=env \
  or cfg.get('origin')!=scope.get('canonical_origin') or cfg.get('wp_root')!=scope.get('expected_root'):
  raise RuntimeError('EXACT_REGISTRY_COMPANY_SITE_SCOPE_MISMATCH')
 host,user,port=cfg.get('hostname'),cfg.get('username'),cfg.get('port')
 expected=cfg['wp_root']
 if not isinstance(host,str) or not re.fullmatch(r'[0-9.]{7,15}',host) \
  or not isinstance(user,str) or not re.fullmatch(r'u[0-9]{6,12}',user) \
  or type(port) is not int or port<1 or port>65535 \
  or not isinstance(expected,str) \
  or not re.fullmatch(r'/home/u[0-9]{6,12}/domains/[a-z0-9.-]+/public_html/staging',expected) \
  or not expected.startswith('/home/'+user+'/'):
  raise RuntimeError('ROOT_SSH_INVENTORY_TARGET_INVALID')
 base=SSH_ROOT/registry
 key,known=base/'id_ed25519',base/'known_hosts_verified'
 root_file(key,4096);root_file(known,4096)
 remote='cd '+shlex.quote(expected)+' && wp eval '+shlex.quote(php)+' --skip-themes'
 ssh=['/usr/bin/ssh','-F','/dev/null','-T','-o','BatchMode=yes',
  '-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes',
  '-o','UserKnownHostsFile='+str(known),'-o','GlobalKnownHostsFile=/dev/null',
  '-o','ClearAllForwardings=yes','-o','PermitLocalCommand=no',
  '-o','ProxyCommand=none','-o','ConnectTimeout=8','-o','LogLevel=ERROR',
  '-i',str(key),'-p',str(port),user+'@'+host,remote]
 result=subprocess.run(ssh,capture_output=True,text=True,timeout=65,check=False)
 if result.returncode!=0:raise RuntimeError('EXACT_CROSS_SSH_WORDPRESS_READ_FAILED')
 lines=[x for x in result.stdout.splitlines() if x.startswith('{')]
 if len(lines)!=1:raise RuntimeError('CROSS_SSH_JSON_READBACK_UNCERTAIN')
 response=json.loads(lines[0])
 if not isinstance(response,dict):raise RuntimeError('CROSS_SSH_READBACK_WRONG_TYPE')
 # A symbolic external runtime target. Not a Docker container, and must NOT
 # be used for Docker execution by an eventual native executor.
 runtime=registry+'-'+env+'-external-wpcli'
 return response,runtime
def info(company,env):
 if os.geteuid()!=0:raise RuntimeError('ORIGINAL_ROOT_OPERATOR_REQUIRED')
 if not CNAME.fullmatch(company) or env not in ('staging','production') or company=='tours-central':
  raise RuntimeError('COMPANY_ENVIRONMENT_SCOPE_INVALID')
 policy_raw=root_file(POLICY,65536);policy=json.loads(policy_raw)
 scope=policy.get('scopes',{}).get(company+':'+env)
 if (policy.get('contract')!='mad4b.native-vps-operator.v1'
  or policy.get('host_id')!='srv2030908' or policy.get('revoked') is not False
  or not isinstance(scope,dict) or scope.get('allow_operator') is not True
  or scope.get('allow_mutation') is not True
  or (env=='production' and scope.get('allow_production_mutation') is not True)):
  raise RuntimeError('ORIGINAL_EXACT_HOST_POLICY_NOT_GRANTED')
 # Remote Staging sites live on separately pinned Hostinger accounts.
 # Never impersonate their runtime with a nonexistent local Docker container.
 cname=None
 envargs=['-e','MAD4B_HOST_PROOF_COMPANY='+company,'-e','MAD4B_HOST_PROOF_ENV='+env]
 central_php=cmd(['docker','exec','-u','33:33',*envargs,'etg-commerce-staging-php',
    'php','-d','memory_limit=512M','/usr/local/bin/wp','eval',CENTRAL_PHP,*BASE])
 central=json.loads([x for x in central_php.splitlines() if x.startswith('{')][-1])
 target,cname=remote_wordpress_read(scope,company,env,TARGET_PHP)
 if (central.get('company_code')!=company or central.get('environment')!=env
  or central.get('original_persistent_enrollment')!='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
  or central.get('slot_exists') is not True
  or target.get('company')!=company or target.get('environment')!=env
  or target.get('site_uuid')!=central.get('site_uuid')
  or target.get('profile_uuid')!=central.get('profile_uuid')
  or target.get('origin')!=central.get('origin')
  or target.get('user_count')!=1 or type(target.get('actor_user_id')) is not int
  or target['actor_user_id']<1
  or target.get('actor_manage_options') is not True
  or target.get('actor_install_plugins') is not True
  or target.get('site_profile_urls_enrolled') is not True
  or not isinstance(target.get('db'),str)
  or not re.fullmatch(r'[A-Za-z0-9_]{4,90}',target['db'])
  or not all(re.fullmatch(r'[a-f0-9]{32}',central.get(k) or '')
     for k in ('transport_id','grant_id'))):
  raise RuntimeError('COMPANY_SITE_NATIVE_PROFILE_OR_ACTOR_MISMATCH')
 if 'canonical_origin' in scope and scope['canonical_origin']!=central['origin']:
  raise RuntimeError('ORIGINAL_HOST_POLICY_ORIGIN_MISMATCH')
 if 'site_uuid' in scope and scope['site_uuid']!=central['site_uuid']:
  raise RuntimeError('ORIGINAL_HOST_POLICY_SITE_UUID_MISMATCH')
 if 'wordpress_actor_user_id' in scope and scope['wordpress_actor_user_id']!=target['actor_user_id']:
  raise RuntimeError('ORIGINAL_HOST_POLICY_TARGET_ACTOR_MISMATCH')
 policy_hash=hashlib.sha256(policy_raw).hexdigest()
 fp=cmd(['ssh-keygen','-lf','/etc/ssh/ssh_host_ed25519_key.pub','-E','sha256'],timeout=12).split()[1]
 if central.get('original_standing_policy_sha256')!=policy_hash or central.get('original_host_key_fingerprint')!=fp:
  raise RuntimeError('ORIGINAL_SIGNED_HOST_POLICY_DRIFT')
 signer=serialization.load_pem_private_key(root_file(SIGNER,16000),password=None)
 if not isinstance(signer,Ed25519PrivateKey):raise RuntimeError('PINNED_ED25519_SIGNER_REQUIRED')
 pinned=cmd(['docker','exec','-u','33:33','etg-commerce-staging-php','/usr/local/bin/wp',
  'option','get','mad4b_central_broker_signing_public_v1',*BASE],timeout=24).strip()
 if not secrets.compare_digest(signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw),
    base64.b64decode(pinned,validate=True)):
  raise RuntimeError('ORIGINAL_BROKER_PINNED_PUBLIC_KEY_MISMATCH')
 return central,target,cname,policy_hash,fp,signer
def main():
 p=argparse.ArgumentParser()
 p.add_argument('action',choices=['plan','attest'])
 p.add_argument('--company',required=True)
 p.add_argument('--environment',required=True,choices=['staging','production'])
 a=p.parse_args()
 with open('/run/lock/mad4b-company-native-host-proof.lock','a+') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX)
  c,t,container,policy_hash,fp,signer=info(a.company,a.environment)
  if a.action=='plan':
   print(json.dumps({'contract':CONTRACT,'state':'INDEPENDENT_HOST_SITE_PROOF_READY',
    'company':a.company,'environment':a.environment,'company_identity_verified':True,
    'profile_uuid_matches':True,'unique_target_wpcli_actor_verified':True,
    'original_persistent_305_pair_verified':True,'signed_host_policy_matches':True,
    'production_content_mutated':False,'native_executor_activated':False}))
   return
  now=int(time.time())
  proof={'contract':CONTRACT,'action':'verify','company':a.company,'environment':a.environment,
   'site_uuid':c['site_uuid'],'native_profile_uuid':c['profile_uuid'],
   'canonical_origin':c['origin'],'target_database':t['db'],
   'target_container':container,'target_wp_actor_id':t['actor_user_id'],
   'transport_id':c['transport_id'],'grant_id':c['grant_id'],
   'host_id':'srv2030908','host_policy_sha256':policy_hash,
   'host_key_fingerprint':fp,'issued':now,'expires':now+50,'nonce':secrets.token_hex(16)}
  assert list(proof)==FIELDS
  signature=signer.sign('\n'.join(str(proof[k]) for k in FIELDS).encode())
  proof['signature']=base64.b64encode(signature).decode()
  payload=base64.b64encode(json.dumps(proof,separators=(',',':')).encode()).decode()
  response=cmd(['docker','exec','-u','33:33','etg-commerce-staging-php','/usr/local/bin/wp',
   'mad4b','company-native-host-proof','attest','--proof='+payload,*BASE],timeout=48)
  rows=[json.loads(x) for x in response.splitlines() if x.startswith('{')]
  if len(rows)!=1 or rows[0].get('state')!='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING' or rows[0].get('verified') is not True:
   raise RuntimeError('ORIGINAL_SIGNED_HOST_PROOF_READBACK_FAILED')
  print(json.dumps({'state':rows[0]['state'],'company':a.company,'environment':a.environment,
    'signed_host_proof_verified':True,'native_executor_certified':False,
    'production_content_mutated':False}))
if __name__=='__main__':
 try:main()
 except (OSError,ValueError,RuntimeError,subprocess.SubprocessError,IndexError) as e:
  print('ORIGINAL_SIGNED_HOST_PROOF_DENIED:'+str(e)[:250],file=sys.stderr);sys.exit(2)
