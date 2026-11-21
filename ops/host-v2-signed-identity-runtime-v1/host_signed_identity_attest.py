#!/usr/bin/env python3
"""Dynamic exact environment read-only attestation through existing Host Broker.

No user identity or OAuth. Creates a CENTRAL identity-readback record only;
never issues 305/318 grants, signs WP-CLI commands, or mutates the target.
"""
import argparse,base64,hashlib,importlib.util,json,os,pathlib,secrets,shlex,subprocess,sys,time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
HERE=pathlib.Path('/opt/mad4b-company-transport/unified-execution-router-v1')
REGISTRY=pathlib.Path('/opt/mad4b-company-provisioning/policies/company-hostinger-site-binding-map-v1.json')
AUTHORITY=pathlib.Path('/opt/mad4b-company-transport/native-operator-v1/host-authority.json')
HOST_SIGNER=pathlib.Path('/opt/mad4b-company-transport/broker-v1/host-ed25519-private.pem')
ORIGINAL_POLICY_SIGNER=pathlib.Path('/opt/mad4b-company-transport/broker-v1/native-vps-policy-rotate.py')
CENTRAL_CONTAINER='php-wkgtkzta35wkkgouwt6ruljc'
CONTRACT='mad4b.company.native-host-signed-identity.v2'
FIELDS=['contract','company','environment','site_uuid','canonical_origin','profile_digest',
        'host_id','ssh_target','ssh_principal','ssh_root_sha256','host_key_sha256',
        'registry_sha256','policy_sha256','issued','expires','nonce']
def importfile(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
def run(argv,timeout=60):
    p=subprocess.run(argv,capture_output=True,text=True,stdin=subprocess.DEVNULL,timeout=timeout)
    if p.returncode:raise RuntimeError('INDEPENDENT_READBACK_COMMAND_FAILED')
    return p.stdout.strip()
def central_eval(php):
    return run(['docker','exec','-u','www-data',CENTRAL_CONTAINER,'wp','eval',php,
                '--path=/var/www/html','--skip-themes'],55)
def original_policy_signed_and_current():
    # Broker signature on an individual identity is not a substitute for the
    # separately signed, current Host policy ledger.
    r=run(['/usr/bin/python3',str(ORIGINAL_POLICY_SIGNER),'plan'],55)
    rows=[json.loads(x) for x in r.splitlines() if x.startswith('{')]
    if (len(rows)!=1 or rows[0].get('state')!='ORIGINAL_POLICY_ROTATION_NOT_REQUIRED'
        or rows[0].get('original_pinned_signer_verified') is not True
        or rows[0].get('original_principal_ledger_verified') is not True
        or rows[0].get('host_key_verified') is not True
        or rows[0].get('grant_issued') is not False
        or rows[0].get('production_mutated') is not False):
        raise RuntimeError('ORIGINAL_SIGNED_HOST_POLICY_NOT_CURRENT')
    return True

def attest(target,dry_run=False):
    if os.geteuid()!=0 or run(['/usr/bin/hostname','-s'],8)!='srv2030908':
        raise RuntimeError('ORIGINAL_CENTRAL_ROOT_HOST_REQUIRED')
    if (HOST_SIGNER.is_symlink() or HOST_SIGNER.stat().st_uid!=0
        or HOST_SIGNER.stat().st_mode&0o077):
        raise RuntimeError('ORIGINAL_BROKER_SIGNER_NOT_ROOT_TRUSTED')
    original_policy_signed_and_current()
    sys.path.insert(0,str(HERE));import router
    config=router.load();scope=config['targets'].get(target)
    if not scope or ':' not in target or not router.registered_target(target,scope):
        raise RuntimeError('COMPANY_ENVIRONMENT_NOT_ENROLLED')
    if router.authority(target,scope)!='VERIFIED':raise RuntimeError('ORIGINAL_HOST_POLICY_NOT_AUTHORIZED')
    # Only independent pinned read scope; a general operator grant is not
    # necessary or acceptable for identity attestation.
    host_policy=router.secure_json(AUTHORITY)
    assigned=host_policy.get('scopes',{}).get(target,{})
    if (scope.get('read_only_hostinger_binding') is not True
        or assigned.get('allow_pinned_wpcli_read') is not True
        or any(assigned.get(key) is not False for key in (
            'allow_operator','allow_mutation','allow_production_mutation',
            'allow_remote_shell','allow_sql'))):
        raise RuntimeError('IDENTITY_ONLY_PINNED_HOST_SCOPE_REQUIRED')
    plan=router.plan(target,config)
    if plan.get('state')!='READY' or plan['selected_lane'] not in ('hostinger_pinned_read','cross_ssh_wpcli'):
        raise RuntimeError('EXACT_PINNED_READ_ROUTE_NOT_READY')
    company,environment=target.rsplit(':',1)
    registry=router.secure_json(REGISTRY)
    rows=[x for x in registry.get('sites',[]) if
          x.get('company_id')==company and x.get('environment')==environment]
    if len(rows)!=1 or registry.get('contract')!='mad4b.company-hostinger-site-binding-map.v1':
        raise RuntimeError('HOSTINGER_TARGET_REGISTRY_UNAVAILABLE')
    row=rows[0]
    host=importfile('v55_existing_hostinger','/opt/mad4b-company-provisioning/bin/company-hostinger-two-route-binding-v1.py')
    provider=scope.get('providers',{}).get('hostinger_pinned_read',{})
    target_host=row.get('ssh_target')
    target_user=row.get('ssh_username')
    if (row.get('origin')!=scope.get('canonical_origin')
        or not isinstance(provider,dict)
        or target_host not in host.HOSTS
        or target_user!=host.HOSTS[target_host][0]
        or target_host!=provider.get('ssh_target')
        or row.get('ssh_root')!=provider.get('expected_root')
        or assigned.get('expected_root')!=row.get('ssh_root')
        or assigned.get('registry_binding')!=target_host+':'+environment
        or assigned.get('site_uuid')!=scope.get('site_uuid')
        or assigned.get('canonical_origin')!=scope.get('canonical_origin')):
        raise RuntimeError('HOSTINGER_INDEPENDENT_PINNED_BINDING_DRIFT')
    php='$s=MAD4B_SCP_Site_Profile::status();echo wp_json_encode(["uuid"=>$s["site_uuid"]??null,"origin"=>$s["canonical_origin"]??null,"environment"=>$s["environment"]??null,"digest"=>$s["profile_digest"]??null,"origin_match"=>$s["origin_match"]??false,"environment_match"=>$s["environment_match"]??false],JSON_UNESCAPED_SLASHES);'
    command='cd '+shlex.quote(row['ssh_root'])+' && wp eval '+shlex.quote(php)+' --skip-themes'
    observed=json.loads(run(host.ssh_cmd(row['ssh_target'])+[command],75).splitlines()[-1])
    expected=json.loads(central_eval('$x=MAD4B_Company_Environment_Registry_V2::target('+json.dumps(company)+','+json.dumps(environment)+');echo wp_json_encode(["site_uuid"=>$x["site_uuid"]??null,"origin"=>$x["canonical_origin"]??null,"digest"=>$x["identity_evidence"]["profile_digest"]??null,"environment"=>$x["environment"]??null],JSON_UNESCAPED_SLASHES);').splitlines()[-1])
    if not observed['origin_match'] or not observed['environment_match'] or any([
       observed['uuid']!=scope['site_uuid'],observed['uuid']!=expected['site_uuid'],
       observed['origin']!=scope['canonical_origin'],observed['origin']!=expected['origin'],
       observed['environment']!=environment,observed['environment']!=expected['environment'],
       observed['digest']!=expected['digest']]):
        raise RuntimeError('LIVE_PINNED_SITE_PROFILE_OR_CENTRAL_DIGEST_MISMATCH')
    known=pathlib.Path('/opt/hostinger-shell-mcp/external-targets')/row['ssh_target']/'known_hosts_verified'
    hostkey=hashlib.sha256(known.read_bytes()).hexdigest()
    issued=int(time.time());expiry=issued+45
    doc=dict(zip(FIELDS,[CONTRACT,company,environment,scope['site_uuid'],scope['canonical_origin'],
         observed['digest'],'srv2030908',row['ssh_target'],row['ssh_username'],
         hashlib.sha256(row['ssh_root'].encode()).hexdigest(),hostkey,
         hashlib.sha256(REGISTRY.read_bytes()).hexdigest(),hashlib.sha256(AUTHORITY.read_bytes()).hexdigest(),
         issued,expiry,secrets.token_hex(16)]))
    if dry_run:
        return {'contract':CONTRACT,'state':'DYNAMIC_HOST_IDENTITY_READBACK_READY',
                'target':target,'site_uuid':scope['site_uuid'],
                'remote_profile_digest_matched':True,'signed_proof_submitted':False,
                'production_mutation':False,'grant_created':False}
    private=serialization.load_pem_private_key(HOST_SIGNER.read_bytes(),password=None)
    if not isinstance(private,Ed25519PrivateKey):raise RuntimeError('BROKER_PRIVATE_KEY_INVALID')
    message='\n'.join(str(doc[k]) for k in FIELDS).encode()
    doc['signature']=base64.b64encode(private.sign(message)).decode()
    payload=base64.b64encode(json.dumps(doc,separators=(',',':')).encode()).decode()
    response=run(['docker','exec','-u','www-data',CENTRAL_CONTAINER,'wp','mad4b','wpcli-host-grant',
        'attest-identity','--proof='+payload,'--path=/var/www/html','--skip-themes'],65)
    out=json.loads(response.splitlines()[-1]);
    if out.get('state')!='SIGNED_HOST_IDENTITY_READBACK_RECENT' or not out.get('identity_readback_verified') or out.get('remote_write_authorized') is not False or out.get('grant_created') is not False or out.get('site_uuid')!=scope['site_uuid']:
        raise RuntimeError('CENTRAL_SIGNED_HOST_IDENTITY_PERSISTENCE_UNVERIFIED')
    return {'contract':CONTRACT,'state':out['state'],'target':target,
       'site_uuid':scope['site_uuid'],'independent_readback_verified':True,
       'host_broker_signature_verified':True,'identity_readback_verified':True,
       'signed_proof_submitted':True,'word_press_user_required':False,
       'production_mutation':False,'grant_created':False}
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--target',required=True)
    parser.add_argument('--dry-run',action='store_true');args=parser.parse_args()
    try:
        print(json.dumps(attest(args.target,args.dry_run),sort_keys=True))
    except Exception as e:
        print(json.dumps({'contract':CONTRACT,'state':'DENIED','reason':str(e)[:130],
            'grant_created':False,'production_mutation':False},sort_keys=True));sys.exit(2)
if __name__=='__main__':main()
