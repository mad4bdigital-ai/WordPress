#!/usr/bin/env python3
"""Historical exact-scope code release audit; never an Original 313 certificate.

Checks immutable root-owned first-party plan, signed Broker grant, Host apply
receipt, original signed reservation, original signed finish *attempt*, and
present exact Git postimage. Signature freshness is checked at historical
issue/apply times. It NEVER treats an expired Job as currently eligible,
mutates WordPress, sends an Original 313 proof, or replays a release.
"""
from __future__ import annotations
import argparse,base64,datetime as dt,hashlib,importlib.util,json,os,re,stat,sys,tempfile
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

HANDLER=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/company-code-release-v1/company_code_release.py')
REGISTRY_ROOT=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
POLICY=Path('/etc/mad4b/company-code-release-policy.json')
PLAN_DIR=Path('/var/lib/mad4b/company-code-release/plans')
GRANT_DIR=Path('/var/lib/mad4b/company-code-release/grants')
RESERVATIONS=Path('/opt/mad4b-company-transport/native-313-independent/reserved')
FINISH_DIR=Path('/opt/mad4b-company-transport/native-313-independent/receipts')
REPORT_DIR=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110')
HEX32=re.compile(r'^[a-f0-9]{32}$')
HEX64=re.compile(r'^[a-f0-9]{64}$')
FINISH_FIELDS=(
    'contract','host_id','job_id','target_id','grant_id','transport_id',
    'site_uuid','origin','environment','command_sha256','native_receipt_sha256',
    'expected_readback_sha256','readback_sha256','output_sha256','exit_code',
    'issued','expires','nonce'
)

class Denied(RuntimeError):pass

def need(condition:bool,code:str):
    if not condition:raise Denied(code)

def trusted_read(path:Path,*,private:bool=True,max_bytes:int=100000)->bytes:
    need(path.is_absolute() and not path.is_symlink(),'HISTORICAL_FILE_PATH_UNTRUSTED')
    st=path.lstat()
    need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022
         and (not private or not st.st_mode&0o077)
         and 0<st.st_size<max_bytes,'HISTORICAL_FILE_ROOT_TRUST_REQUIRED')
    return path.read_bytes()

def file_json(path:Path,*,private:bool=True)->dict:
    data=json.loads(trusted_read(path,private=private))
    need(isinstance(data,dict),'HISTORICAL_JSON_INVALID')
    return data

def load_module(path:Path,label:str):
    trusted_read(path,private=False,max_bytes=200000)
    spec=importlib.util.spec_from_file_location(label,path)
    need(spec is not None and spec.loader is not None,'FIRST_PARTY_MODULE_MISSING')
    m=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m

def utc(value:str)->dt.datetime:
    x=dt.datetime.fromisoformat(value.replace('Z','+00:00'))
    need(x.tzinfo is not None,'HISTORICAL_TIME_MISSING_TZ')
    return x.astimezone(dt.timezone.utc)

def verify_signature(key:Ed25519PublicKey,signature:str,message:bytes):
    try:key.verify(base64.b64decode(signature,validate=True),message)
    except (ValueError,InvalidSignature,TypeError) as e:
        raise Denied('HISTORICAL_BROKER_SIGNATURE_INVALID') from e

def evaluate(*,job:str,plan_sha:str,plan_file:Path,grant_file:Path,
             receipt_file:Path,reservation_file:Path,finish_file:Path,
             policy_file:Path=POLICY)->dict:
    need(os.geteuid()==0,'ROOT_READ_ONLY_AUDITOR_REQUIRED')
    need(bool(HEX32.fullmatch(job)) and bool(HEX64.fullmatch(plan_sha)),
         'EXACT_JOB_OR_PLAN_ID_INVALID')
    module=load_module(HANDLER,'mad4b_audit_original_signed_code_release')
    policy=module.trusted_config(policy_file)
    plan=file_json(plan_file)
    need(plan.get('contract')==module.PLAN_CONTRACT and
         plan.get('plan_sha256')==plan_sha,'EXACT_RELEASE_PLAN_NOT_MATCHED')
    module.confirm_plan(plan,policy,expect_preimage=False)
    need(plan['company']=='wayfatours' and plan['environment']=='production'
         and plan['site_uuid']=='a3a7cc36-db7e-4a14-8456-b41857def05c',
         'COMPANY_RELEASE_SCOPE_MISMATCH')
    need(len(plan['files'])==2 and
         {r['target'].rsplit('/',1)[-1] for r in plan['files']}=={
            'class-mad4b-tenant-workflow.php','class-mad4b-tenant-sparse-store.php'},
         'COMPANY_RELEASE_FILE_SCOPE_MISMATCH')
    grant=file_json(grant_file)
    need(grant_file.stem==job and grant.get('original_job_id')==job and
         grant.get('contract')==module.AUTH_CONTRACT and
         grant.get('action')=='apply' and grant.get('issuer')=='mad4b-company-broker',
         'HISTORICAL_SIGNED_GRANT_IDENTITY_INVALID')
    for field in ('company','environment','site_uuid','origin','plan_sha256'):
        need(grant.get(field)==plan.get(field),'HISTORICAL_GRANT_SCOPE_CHANGED_'+field.upper())
    key=Ed25519PublicKey.from_public_bytes(base64.b64decode(policy['broker_public_key_b64'],validate=True))
    verify_signature(key,grant.get('signature',''),module.grant_message(grant))
    issued=utc(grant['issued_at']);expires=utc(grant['expires_at'])
    need(issued<expires and (expires-issued)<=dt.timedelta(minutes=30),
         'HISTORICAL_GRANT_ISSUANCE_WINDOW_INVALID')
    need(expires<=dt.datetime.now(dt.timezone.utc),
         'ACTIVE_GRANT_USE_NORMAL_ORIGINAL_313_NOT_HISTORICAL_AUDIT')
    # This is deliberately an *old* grant; no claim of contemporary authorization.
    receipt_raw=trusted_read(receipt_file)
    receipt=json.loads(receipt_raw)
    need(receipt_file.stem==grant.get('nonce') and
         receipt.get('contract')==module.RECEIPT_CONTRACT and
         receipt.get('state')=='APPLIED_VERIFIED' and
         receipt.get('broker_grant_signature_verified') is True and
         receipt.get('original_313_receipt') is False and
         receipt.get('nonce')==grant['nonce'] and
         receipt.get('plan_sha256')==plan_sha and
         receipt.get('company')==plan['company'] and
         receipt.get('environment')==plan['environment'] and
         receipt.get('site_uuid')==plan['site_uuid'] and
         receipt.get('git_commit')==plan['git_commit'] and
         receipt.get('files')==[row['target'] for row in plan['files']],
         'HISTORICAL_HOST_APPLY_RECEIPT_INVALID')
    applied=utc(receipt['at'])
    need(issued<=applied<=expires,'HISTORICAL_APPLY_OUTSIDE_SIGNED_WINDOW')
    # Exact Original 306 reservation permanently prevents replay of this job.
    import sys
    if str(REGISTRY_ROOT) not in sys.path:sys.path.insert(0,str(REGISTRY_ROOT))
    reg=load_module(REGISTRY_ROOT/'registry.py','mad4b_exact_dynamic_registry_historical')
    digest=reg.Registry(REGISTRY_ROOT).command_sha256('company.code.release',plan_sha)
    need(trusted_read(reservation_file,max_bytes=500)==
         ('dynamic:company.code.release '+digest+'\n').encode() and
         reservation_file.name==job+'.reserved',
         'ORIGINAL_306_EXACT_RESERVATION_NOT_VERIFIED')
    finish=json.loads(trusted_read(finish_file,max_bytes=10000))
    need(finish_file.name==job+'-dynamic313-finish.json' and
         tuple(k for k in finish if k!='signature')==FINISH_FIELDS and
         finish.get('contract')=='mad4b.broker-wpcli-native-finish.v1' and
         finish.get('job_id')==job and
         finish.get('host_id')=='srv2030908' and
         finish.get('target_id')=='srv2030908' and
         finish.get('grant_id')==grant['original_305_grant_id'] and
         finish.get('transport_id')==grant['original_305_transport_id'] and
         finish.get('site_uuid')==plan['site_uuid'] and
         finish.get('origin')==plan['origin'] and
         finish.get('environment')==plan['environment'] and
         finish.get('command_sha256')==digest and
         finish.get('native_receipt_sha256')==hashlib.sha256(receipt_raw).hexdigest() and
         all(finish.get(field)==finish.get('readback_sha256') for field in
             ('output_sha256','expected_readback_sha256')) and
         finish.get('exit_code')==0,
         'HISTORICAL_FINISH_ATTEMPT_SCOPE_CHANGED')
    signed='\n'.join(str(finish[f]) for f in FINISH_FIELDS).encode()
    verify_signature(key,finish.get('signature',''),signed)
    need(type(finish.get('issued')) is int and type(finish.get('expires')) is int and
         0<finish['expires']-finish['issued']<=120 and
         applied.timestamp()<=finish['issued']<=expires.timestamp(),
         'HISTORICAL_FINISH_ATTEMPT_TIME_INVALID')
    return {
      'contract':'mad4b.historical-original306-code-release-audit.v1',
      'state':'HISTORICAL_SIGNED_APPLY_POSTIMAGE_VERIFIED__313_UNCERTIFIED',
      'company':plan['company'],'environment':plan['environment'],
      'site_uuid':plan['site_uuid'],'job_id':job,'plan_sha256':plan_sha,
      'git_commit':plan['git_commit'],'files_verified':len(plan['files']),
      'broker_grant_historical_signature_verified':True,
      'host_apply_within_original_signed_window':True,
      'signed_original306_reservation_verified':True,
      'signed_original_finish_attempt_verified':True,
      'exact_live_git_postimage_verified':True,
      'original_313_certified':False,
      'current_job_execution_eligible':False,
      'broker_grant_extended':False,'job_replayed':False,
      'wordpress_mutated_by_audit':False,'schema_ddl_performed':False,
      'next_authority':'REQUIRES_FIRST_PARTY_RECEIPT_ONLY_RECOVERY_CONTRACT',
      'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat()
    }

def audit_exact(job:str,plan_sha:str):
    grant_file=GRANT_DIR/(job+'.json')
    grant=file_json(grant_file)
    return evaluate(job=job,plan_sha=plan_sha,
      plan_file=PLAN_DIR/(plan_sha+'.json'),grant_file=grant_file,
      receipt_file=Path('/var/lib/mad4b/company-code-release/receipts')/(grant['nonce']+'.json'),
      reservation_file=RESERVATIONS/(job+'.reserved'),
      finish_file=FINISH_DIR/(job+'-dynamic313-finish.json'))

def publish(data:dict,job:str)->Path:
    need(os.geteuid()==0,'ROOT_REQUIRED')
    need(REPORT_DIR.stat().st_uid==0 and not REPORT_DIR.is_symlink() and
         not REPORT_DIR.stat().st_mode&0o022,'REPORT_DESTINATION_UNTRUSTED')
    output=REPORT_DIR/('historical-signed-original313-gap-'+job+'.json')
    if output.is_symlink():raise Denied('HISTORICAL_REPORT_SYMLINK_DENIED')
    fd,name=tempfile.mkstemp(prefix='.historical313-audit-',dir=REPORT_DIR)
    try:
        with os.fdopen(fd,'w') as fp:
            os.fchmod(fp.fileno(),0o600)
            json.dump(data,fp,sort_keys=True,indent=2);fp.write('\n')
            fp.flush();os.fsync(fp.fileno())
        os.replace(name,output)
    finally:
        if os.path.lexists(name):os.unlink(name)
    return output

if __name__=='__main__':
    ap=argparse.ArgumentParser()
    ap.add_argument('--job',required=True)
    ap.add_argument('--plan-sha256',required=True)
    ap.add_argument('--write-private-report',action='store_true')
    args=ap.parse_args()
    try:
        result=audit_exact(args.job,args.plan_sha256)
        if args.write_private_report:
            result['private_report_path']=str(publish(result,args.job))
        print(json.dumps(result,sort_keys=True))
    except (Denied,ValueError,TypeError,KeyError,RuntimeError,OSError,InvalidSignature) as exc:
        label=str(exc)
        if not re.fullmatch(r'[A-Z][A-Z0-9_]{3,110}',label):label='HISTORICAL_AUDIT_UNVERIFIED'
        print(json.dumps({'state':'HISTORICAL_AUDIT_DENIED','reason':label,
                          'original_313_certified':False,
                          'job_replayed':False,'wordpress_mutated_by_audit':False},sort_keys=True))
        raise SystemExit(2)
