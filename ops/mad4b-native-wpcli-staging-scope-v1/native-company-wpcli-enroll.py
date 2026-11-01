#!/usr/bin/env python3
"""Original 305 company WP-CLI standing enrollment using independent signed VPS principal.

No WordPress --user impersonation and no OAuth requirement.  Refuses enrollment
unless existing root-owned host policy and current ORIGINAL WordPress company
manager delegation agree on company, exact tenant UUID, origin and environment.
Registration writes the ORIGINAL 305 transport/grant records, leaves host execution
and WP-CLI activation disabled pending independent operator certification.
"""
import argparse,base64,fcntl,hashlib,json,os,pathlib,re,secrets,stat,subprocess,sys,time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

ROOT=pathlib.Path('/opt/mad4b-company-transport')
POLICY=ROOT/'native-operator-v1/host-authority.json'
PRIVATE=ROOT/'broker-v1/host-ed25519-private.pem'
PUBKEYOPT='mad4b_central_broker_signing_public_v1'
FINGERPRINT=pathlib.Path('/etc/ssh/ssh_host_ed25519_key.pub')
HOST='srv2030908'
WP=['docker','exec','-u','33:33','etg-commerce-staging-php',
 'php','-d','memory_limit=512M','/usr/local/bin/wp']
BASE=['--path=/var/www/html','--skip-themes']
FIELDS=['contract','action','company','environment','site_uuid',
'canonical_origin','host_id','host_principal','actor_user_id','assignment_revision',
'host_policy_sha256','host_key_fingerprint','issued','expires','nonce']
CONTRACT='mad4b.company-native-wpcli-enrollment.v1'
RX=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
SCOPE_PHP=r"""
if(DB_NAME!=='etg_commerce_staging'||wp_get_environment_type()!=='staging'
||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')
 WP_CLI::error('CENTRAL_SITE_IDENTITY_MISMATCH');
$c=(string)getenv('MAD4B_SIGNED_WPCLI_COMPANY');$env=(string)getenv('MAD4B_SIGNED_WPCLI_ENV');
if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$c)
||!in_array($env,['production','staging'],true))WP_CLI::error('COMPANY_ENV_INVALID');
$t=MAD4B_Company_Environment_Registry_V2::target($c,$env);
$m=MAD4B_Company_Operation_Admission_V1::manager_id();
// Preserve the ORIGINAL Production Manager contract. An independently signed
// Staging company must only use the owner-approved environment namespace.
if($env==='staging') {
 if(!class_exists('MAD4B_Staging_Manager_Admission_Bridge_V1',false)
   || !class_exists('MAD4B_Company_Environment_Portfolio_Evidence_V1',false))
  WP_CLI::error('EXACT_STAGING_MANAGER_BRIDGE_REQUIRED');
 $assignments=get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[]);
 $a=is_array($assignments)?($assignments[$m.'|'.$c.'|staging']??null):null;
 $assignment_site_uuid=$a['site_uuid']??null;
 $approval_current=is_array($a) && is_string($a['proposal_sha256']??null)
  && preg_match('/^[a-f0-9]{64}$/D',$a['proposal_sha256'])===1
  && is_string($a['approval_sha256']??null)
  && hash_equals($a['proposal_sha256'],$a['approval_sha256']);
} else {
 $assignments=get_option(MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS,[]);
 $a=is_array($assignments)?($assignments[$m.'|'.$c]??null):null;
 $assignment_site_uuid=$a['company_site_uuid']??null;
 $approval_current=true; // Production retains existing original authority checks.
}
$members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
$eligible=[];
foreach((array)$members as $member){
 if(!is_array($member)||($member['manager_id']??null)!==$m
  ||($member['state']??null)!=='active')continue;
 $uid=(int)($member['user_id']??0);$u=get_user_by('id',$uid);
 if($uid>0&&$u&&user_can($u,'manage_options')&&user_can($u,'install_plugins')
  &&($env==='staging'
    ? MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation(
        $m,$c,'operations_manage',$uid)
    : MAD4B_Company_Portfolio_Manager_V1::has_delegation(
        $m,$c,'operations_manage',$uid)))
  $eligible[]=$uid;
}
$eligible=array_values(array_unique($eligible));
echo wp_json_encode(['contract'=>'mad4b.company-native-wpcli-actor-readback.v1',
 'company'=>$c,'environment'=>$env,
 'site_uuid'=>$t['site_uuid']??null,'canonical_origin'=>$t['canonical_origin']??null,
 'assignment_revision'=>$a['revision']??null,
 'assignment_site_uuid'=>$assignment_site_uuid,
 'assignment_active'=>($a['state']??'')==='active' && $approval_current,
 'eligible_actor_count'=>count($eligible),
 'eligible_actor_id'=>count($eligible)===1?$eligible[0]:null,
 'current_original_central_principal_verified'=>($z=MAD4B_Native_VPS_Principal_V1::existing())
  &&MAD4B_Native_VPS_Principal_V1::ledger($z,MAD4B_Native_VPS_Principal_V1::scope())
 ]);
"""
def rootfile(path,maximum):
    if path.is_symlink():raise RuntimeError('SYMLINK_NOT_ALLOWED')
    s=path.stat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode & 0o077 or s.st_size>maximum:
        raise RuntimeError('ROOT_OWNED_PROTECTED_FILE_REQUIRED')
    return path.read_bytes()
def run(command,env=None,timeout=35):
    r=subprocess.run(command,capture_output=True,text=True,timeout=timeout,env=env)
    if r.returncode:raise RuntimeError('ORIGINAL_CENTRAL_COMMAND_FAILED:'+r.stderr[-200:])
    return r.stdout
def plan(company,environment):
    if os.geteuid()!=0:raise RuntimeError('ENROLLED_ROOT_SIGNER_REQUIRED')
    if not RX.fullmatch(company) or environment not in ('staging','production'):
        raise RuntimeError('COMPANY_SCOPE_INVALID')
    raw=rootfile(POLICY,65536);p=json.loads(raw)
    scope=p.get('scopes',{}).get(company+':'+environment)
    if (p.get('host_id')!=HOST or p.get('revoked') is not False
     or p.get('contract')!='mad4b.native-vps-operator.v1'
     or not isinstance(scope,dict) or scope.get('allow_operator') is not True
     or scope.get('allow_mutation') is not True or
     (environment=='production' and scope.get('allow_production_mutation') is not True)):
        raise RuntimeError('EXACT_COMPANY_HOST_SCOPE_NOT_AUTHORIZED')
    e=os.environ.copy()
    e['MAD4B_SIGNED_WPCLI_COMPANY']=company
    e['MAD4B_SIGNED_WPCLI_ENV']=environment
    cmd=['docker','exec','-u','33:33',
     '-e','MAD4B_SIGNED_WPCLI_COMPANY='+company,
     '-e','MAD4B_SIGNED_WPCLI_ENV='+environment,
     'etg-commerce-staging-php','php','-d','memory_limit=512M','/usr/local/bin/wp',
     'eval',SCOPE_PHP,*BASE]
    lines=run(cmd,timeout=45).splitlines()
    rows=[json.loads(x) for x in lines if x.startswith('{')]
    if len(rows)!=1:raise RuntimeError('WORDPRESS_ENROLLMENT_ACTOR_READBACK_INVALID')
    r=rows[0]
    if (r.get('contract')!='mad4b.company-native-wpcli-actor-readback.v1'
     or r.get('company')!=company or r.get('environment')!=environment
     or not isinstance(r.get('site_uuid'),str)
     or not re.fullmatch(r'[a-f0-9-]{36}',r['site_uuid'])
     or r.get('assignment_active') is not True
     or r.get('site_uuid')!=r.get('assignment_site_uuid')
     or type(r.get('eligible_actor_id')) is not int or r.get('eligible_actor_count')!=1
     or type(r.get('assignment_revision')) is not int
     or r.get('current_original_central_principal_verified') is not True):
        raise RuntimeError('ENROLLED_WORDPRESS_COMPANY_MANAGER_NOT_VERIFIED')
    if 'canonical_origin' in scope and scope['canonical_origin']!=r['canonical_origin']:
        raise RuntimeError('HOST_POLICY_SITE_ORIGIN_MISMATCH')
    if 'site_uuid' in scope and scope['site_uuid']!=r['site_uuid']:
        raise RuntimeError('HOST_POLICY_SITE_UUID_MISMATCH')
    if 'wordpress_actor_user_id' in scope and scope['wordpress_actor_user_id']!=r['eligible_actor_id']:
        raise RuntimeError('HOST_POLICY_ACTOR_ID_MISMATCH')
    k=serialization.load_pem_private_key(rootfile(PRIVATE,16000),password=None)
    if not isinstance(k,Ed25519PrivateKey):raise RuntimeError('ORIGINAL_ED25519_SIGNER_MISSING')
    pub=run([*WP,'option','get',PUBKEYOPT,*BASE],timeout=25).strip()
    if not secrets.compare_digest(base64.b64decode(pub,validate=True),
       k.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)):
        raise RuntimeError('SIGNER_NOT_PINNED_BY_WORDPRESS')
    hostline=FINGERPRINT.read_text()
    if not hostline.startswith('ssh-ed25519 '):raise RuntimeError('HOST_KEY_NOT_ED25519')
    fp=run(['ssh-keygen','-lf',str(FINGERPRINT),'-E','sha256'],timeout=12).split()[1]
    if not re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}=?',fp):
        raise RuntimeError('HOST_FINGERPRINT_INVALID')
    return {'company':company,'environment':environment,'site_uuid':r['site_uuid'],
     'origin':r['canonical_origin'],'wp_actor':r['eligible_actor_id'],
     'assignment_revision':r['assignment_revision'],
     'policy_sha256':hashlib.sha256(raw).hexdigest(),'host_fingerprint':fp,
     'signer':k}
def enroll(p):
    now=int(time.time())
    proof={'contract':CONTRACT,'action':'enroll','company':p['company'],
      'environment':p['environment'],'site_uuid':p['site_uuid'],
      'canonical_origin':p['origin'],'host_id':HOST,'host_principal':'root',
      'actor_user_id':p['wp_actor'],'assignment_revision':p['assignment_revision'],
      'host_policy_sha256':p['policy_sha256'],'host_key_fingerprint':p['host_fingerprint'],
      'issued':now,'expires':now+40,'nonce':secrets.token_hex(16)}
    assert list(proof)==FIELDS
    proof['signature']=base64.b64encode(p['signer'].sign(
     '\n'.join(str(proof[n]) for n in FIELDS).encode())).decode()
    payload=base64.b64encode(json.dumps(proof,separators=(',',':')).encode()).decode()
    x=run([*WP,'mad4b','company-native-wpcli','enroll','--proof='+payload,*BASE],timeout=40)
    rows=[json.loads(line) for line in x.splitlines() if line.startswith('{')]
    if len(rows)!=1 or rows[0].get('state')!='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED':
        raise RuntimeError('SIGNED_ORIGINAL_ENROLLMENT_READBACK_FAILED')
    return rows[0]
def main():
    a=argparse.ArgumentParser()
    a.add_argument('action',choices=['plan','enroll'])
    a.add_argument('--company',required=True)
    a.add_argument('--environment',required=True,choices=['staging','production'])
    args=a.parse_args()
    fd=os.open('/run/lock/mad4b-company-native-wpcli-enroll.lock',os.O_RDWR|os.O_CREAT,0o600)
    with os.fdopen(fd,'r+') as lock:
      fcntl.flock(lock,fcntl.LOCK_EX)
      p=plan(args.company,args.environment)
      if args.action=='plan':
          print(json.dumps({'state':'ORIGINAL_NATIVE_SIGNED_ENROLLMENT_ADMISSIBLE',
             'company':p['company'],'environment':p['environment'],
             'site_identity_verified':True,'original_manager_delegation_verified':True,
             'dynamic_wp_actor_verified':True,'pinned_ed25519_signer_verified':True,
             'original_grant_created':False,'production_mutated':False}))
      else:
          r=enroll(p)
          print(json.dumps({'state':r['state'],
             'company':args.company,'environment':args.environment,
             'grant_issued':r.get('grant_issued',False),
             'host_authority_verified':r.get('host_authority_verified',False),
             'full_wpcli_active':r.get('full_wpcli_active',False),
             'execution_performed':False}))
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as exc:
      print('ORIGINAL_COMPANY_NATIVE_ENROLLMENT_DENIED:'+str(exc)[:200],file=sys.stderr)
      sys.exit(2)
