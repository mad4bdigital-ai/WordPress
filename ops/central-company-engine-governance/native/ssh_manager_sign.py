#!/usr/bin/env python3
"""MAD4B SSH-authenticated Staging manager workflow via Original Core.

The SSH transport authenticates the VPS operator. A root-owned policy chooses
exact company, WP actor and host. The existing Original Core Ed25519 signer
signs one short-lived and one-time manager transition. It is NOT the SSH host
private key, and no WordPress admin identity is taken from caller input.
"""
import argparse
import base64
import json
import os
import pathlib
import re
import secrets
import stat
import subprocess
import sys
import time

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

POLICY=pathlib.Path('/opt/mad4b-company-transport/native-operator-v1/host-authority.json')
SIGNER=pathlib.Path('/opt/mad4b-company-transport/broker-v1/host-ed25519-private.pem')
FIELDS=('contract','action','company_code','environment','manager_id','actor_user_id',
        'site_uuid','canonical_origin','company_binding_sha256','host_id',
        'expected_revision','expected_proposal_sha256','rights_csv','reason',
        'issued_epoch','expires_epoch','nonce')
WP=['docker','exec','-u','33:33','etg-commerce-staging-php','php','-d','memory_limit=512M',
    '/usr/local/bin/wp']
WPFLAGS=['--path=/var/www/html','--skip-themes']
COMPANY_PATTERN=re.compile(r'[a-z][a-z0-9-]{2,47}\Z')
SHA_PATTERN=re.compile(r'[a-f0-9]{64}\Z')

class Refusal(Exception):
    pass

def root_protected(path):
    if path.is_symlink() or not path.is_file():
        raise Refusal('EXPECTED_ROOT_PROTECTED_REGULAR_FILE')
    s=path.stat()
    if s.st_uid!=0 or not stat.S_ISREG(s.st_mode) or (s.st_mode&0o077):
        raise Refusal('ROOT_PROTECTION_REQUIRED')
    if s.st_size>65536:
        raise Refusal('ROOT_PROTECTED_FILE_OVERSIZE')
    return path.read_bytes()

def wp(args, timeout=30):
    p=subprocess.run([*WP,*args,*WPFLAGS],text=True,capture_output=True,timeout=timeout)
    if p.returncode:
        raise Refusal('ORIGINAL_WPCLI_READ_OR_WRITE_REJECTED')
    return p.stdout

def select(company, environment):
    if os.geteuid()!=0:
        raise Refusal('ENROLLED_SSH_ROOT_SIGNER_REQUIRED')
    if not COMPANY_PATTERN.fullmatch(company) or environment!='staging':
        raise Refusal('STAGING_COMPANY_SCOPE_REQUIRED')
    policy=json.loads(root_protected(POLICY))
    scope=policy.get('scopes',{}).get(company+':'+environment)
    if (policy.get('contract')!='mad4b.native-vps-operator.v1' or
        policy.get('revoked') is not False or not isinstance(scope,dict) or
        scope.get('allow_operator') is not True or
        scope.get('allow_mutation') is not True or
        scope.get('site_environment',environment)!=environment or
        not isinstance(scope.get('wordpress_actor_user_id'),int)):
        raise Refusal('ROOT_HOST_POLICY_DOES_NOT_AUTHORIZE_COMPANY_SCOPE')
    actor=scope['wordpress_actor_user_id']
    read_php=r"""
if(DB_NAME!=='etg_commerce_staging'||wp_get_environment_type()!=='staging')
 WP_CLI::error('CENTRAL_STAGING_MISMATCH');
$c=(string)getenv('MAD4B_MANAGER_COMPANY');
$m=MAD4B_Company_Operation_Admission_V1::manager_id();
$b=MAD4B_Company_Portfolio_Manager_V1::company_binding($c);
$u=(int)getenv('MAD4B_MANAGER_ACTOR');
$members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
$managers=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
echo wp_json_encode(['company'=>$c,'manager_id'=>$m,
 'site_uuid'=>is_array($b)?($b['company_site_uuid']??null):null,
 'binding_sha256'=>is_array($b)?($b['binding_sha256']??null):null,
 'origin'=>untrailingslashit(home_url('/')),
 'manager_registered'=>($managers[$m]['state']??'')==='registered',
 'member_active'=>($members[$m.'|'.$u]['state']??'')==='active',
 'actor_caps'=>($u>0&&user_can($u,'manage_options')&&
 user_can($u,'activate_plugins')&&user_can($u,'install_plugins')),
 'native_ledger'=>($n=MAD4B_Native_VPS_Principal_V1::existing())&&
 MAD4B_Native_VPS_Principal_V1::ledger($n,MAD4B_Native_VPS_Principal_V1::scope())]);
"""
    args=['docker','exec','-u','33:33','-e','MAD4B_MANAGER_COMPANY='+company,
          '-e','MAD4B_MANAGER_ACTOR='+str(actor),'etg-commerce-staging-php',
          'php','-d','memory_limit=512M','/usr/local/bin/wp',
          'eval',read_php,*WPFLAGS]
    p=subprocess.run(args,text=True,capture_output=True,timeout=35)
    if p.returncode:raise Refusal('ORIGINAL_WORDPRESS_SCOPE_UNAVAILABLE')
    rows=[]
    for line in p.stdout.splitlines():
        if line.startswith('{'):
            try:rows.append(json.loads(line))
            except json.JSONDecodeError:raise Refusal('INVALID_WPCLI_READBACK')
    if len(rows)!=1:raise Refusal('ORIGINAL_WPCLI_READBACK_COUNT_INVALID')
    r=rows[0]
    if (r.get('company')!=company or not r.get('manager_registered') or
        not r.get('member_active') or not r.get('actor_caps') or
        not r.get('native_ledger') or not isinstance(r.get('site_uuid'),str) or
        not SHA_PATTERN.fullmatch(str(r.get('binding_sha256',''))) or
        r.get('site_uuid')!=scope.get('site_uuid') or
        r.get('origin')!=scope.get('canonical_origin') or
        not re.fullmatch(r'[a-f0-9-]{36}',str(r.get('manager_id','')))):
        raise Refusal('SIGNED_COMPANY_MANAGER_BINDING_NOT_ELIGIBLE')
    return policy,scope,r,actor

def signer():
    key=serialization.load_pem_private_key(root_protected(SIGNER),password=None)
    if not isinstance(key,Ed25519PrivateKey):
        raise Refusal('ENROLLED_CORE_ED25519_SIGNER_NOT_FOUND')
    pinned=base64.b64decode(wp(['option','get','mad4b_central_broker_signing_public_v1']).strip(),validate=True)
    pub=key.public_key().public_bytes(encoding=serialization.Encoding.Raw,
                                      format=serialization.PublicFormat.Raw)
    if pub!=pinned:raise Refusal('SIGNER_NOT_PINNED_BY_ORIGINAL_WORDPRESS_CORE')
    return key

def make_envelope(a,root_scope,wp_scope,actor,key):
    rights=','.join(a.rights) if a.action=='propose' else ''
    if a.action=='propose':
        if not a.reason or len(a.reason)<12 or len(a.reason)>1200 or not rights:
            raise Refusal('PROPOSAL_DETAILS_REQUIRED')
        if a.proposal_sha256 or a.expected_revision!=0:
            raise Refusal('FIRST_ASSIGNMENT_PROPOSAL_EXPECTS_REVISION_ZERO')
    else:
        if a.reason or a.rights:
            raise Refusal('TRANSITIONS_MUST_NOT_ADD_RIGHTS_OR_REASON')
        if a.action=='approve' and not SHA_PATTERN.fullmatch(a.proposal_sha256 or ''):
            raise Refusal('APPROVAL_REQUIRES_CURRENT_EXACT_PROPOSAL_SHA')
        if a.action=='activate' and a.proposal_sha256:
            raise Refusal('ACTIVATE_USES_ALREADY_APPROVED_ORIGINAL_RECORD')
        if a.expected_revision<1:
            raise Refusal('REVISION_MISSING')
    epoch=int(time.time())
    item={'contract':'mad4b.company-manager.ssh-signed-action.v1',
          'action':a.action,'company_code':a.company,'environment':a.environment,
          'manager_id':wp_scope['manager_id'],'actor_user_id':actor,
          'site_uuid':wp_scope['site_uuid'],'canonical_origin':wp_scope['origin'],
          'company_binding_sha256':wp_scope['binding_sha256'],
          'host_id':root_scope['host_id'],'expected_revision':a.expected_revision,
          'expected_proposal_sha256':a.proposal_sha256 or '',
          'rights_csv':rights,'reason':a.reason or '',
          'issued_epoch':epoch,'expires_epoch':epoch+40,'nonce':secrets.token_hex(16)}
    if root_scope['host_id']!=a.host_id:
        raise Refusal('HOST_PIN_MISMATCH')
    values=[str(item[f]) for f in FIELDS]
    if any('\r' in x or '\n' in x for x in values):
        raise Refusal('UNSAFE_SIGNED_FIELD')
    sig=key.sign('\n'.join(values).encode('utf-8'))
    item['signature']=base64.b64encode(sig).decode('ascii')
    return item

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    parser.add_argument('--company',default='tours-central')
    parser.add_argument('--environment',choices=['staging'],default='staging')
    parser.add_argument('--action',choices=['propose','approve','activate'],required=True)
    parser.add_argument('--host-id',default='srv2030908')
    parser.add_argument('--expected-revision',type=int,required=True)
    parser.add_argument('--proposal-sha256',default='')
    parser.add_argument('--rights',nargs='*',default=[])
    parser.add_argument('--reason',default='')
    a=parser.parse_args()
    policy,scope,r,actor=select(a.company,a.environment)
    key=signer()
    if a.mode=='plan':
        print(json.dumps({'contract':'mad4b.company-manager.ssh-plan.v1',
          'state':'SIGNED_SSH_STAGING_MANAGER_ACTION_ADMISSIBLE',
          'action':a.action,'company':a.company,'environment':a.environment,
          'actor_verified':True,'original_ledger_verified':True,
          'manager_assignment_created':False,'production_mutated':False}))
        return
    payload=make_envelope(a,{'host_id':policy['host_id']},r,actor,key)
    envelope=base64.b64encode(json.dumps(payload,separators=(',',':')).encode()).decode()
    response=wp(['mad4b','manager-ssh','apply','--envelope='+envelope],timeout=40)
    print(response.strip())

if __name__=='__main__':
    try: main()
    except (Refusal,OSError,ValueError,subprocess.TimeoutExpired) as e:
        print('SSH_MANAGER_ACTION_DENIED:'+str(e)[:180],file=sys.stderr)
        sys.exit(2)
