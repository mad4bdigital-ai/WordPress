#!/usr/bin/env python3
"""Original 305 issuer candidate for a NEW, separately scoped read-only recovery Job.

The issuer deliberately cannot enroll anything, relax Code Release overlap
checks, replay the old Job or claim a valid Original 313 certificate.
First-party Registry, original signed 305/306/312 and independent Central
recovery Finalizer must be LIVE before any --execute is eligible.
"""
from __future__ import annotations
import argparse,base64,datetime as dt,fcntl,hashlib,importlib.util,json,os,secrets,stat,sys,time
from pathlib import Path
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

HERE=Path(__file__).resolve().parent
if str(HERE) not in sys.path:sys.path.insert(0,str(HERE))
INDEX=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
PLANS=Path('/var/lib/mad4b/dynamic-operation-registry/plans')
GRANTS=Path('/var/lib/mad4b/company-code-receipt-recovery/grants')
ACTIVE_WORKER=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')
ACTIVE_ADAPTER=INDEX/'worker_adapter.py'
LEGACY_ISSUER=Path('/opt/mad4b-company-transport/worktrees/company-code-release-20261108/ops/company-code-release-v1/integrations/issue_original_305_code_release.py')
LOCK=Path('/run/lock/mad4b-first-party-original305-receipt-only-issue.lock')
OP='company.code.release.receipt.reconcile'
PLAN_SHA='15e021173057e17be0c2bdbcbc3f60ff7a82f2272b66a0963558d0e87722c4e8'
# A separate Central independent receipt verifier capability must be
# registered and externally read back; old Code Release Finalizer is not one.
CENTRAL_READY_KEY='original_313_receipt_recovery_finalizer_connected'

class Denied(RuntimeError):pass

def require(ok,code):
    if not ok:raise Denied(code)

def root_source(path,maxbytes=200000):
    require(path.is_file() and not path.is_symlink() and
            path.stat().st_uid==0 and path.stat().st_mode&0o022==0
            and 0<path.stat().st_size<maxbytes,'ORIGINAL_ISSUER_SOURCE_NOT_TRUSTED')
    return path.read_bytes()

def module(path,label):
    root_source(path)
    sp=importlib.util.spec_from_file_location(label,path)
    require(sp is not None and sp.loader is not None,'ORIGINAL_ISSUER_SOURCE_NOT_IMPORTABLE')
    x=importlib.util.module_from_spec(sp);sp.loader.exec_module(x)
    return x

def all_sources_ready(index,plan):
    """Only the *active* read-only implementation, not pending PR source."""
    manifest=index.resolve(OP)
    deps={row['path']:row['sha256'] for row in manifest.get('dependencies',[])}
    dep='receipt-reconcile-v1/signed_recovery_admission.py'
    require(dep in deps,'RECOVERY_SIGNED_GRANT_PROVIDER_NOT_INSTALLED')
    require(hashlib.sha256(root_source(index.root/dep)).hexdigest()==deps[dep],
            'RECOVERY_SIGNED_GRANT_PROVIDER_HASH_CHANGED')
    candidate=module(ACTIVE_WORKER,'current_original_worker_recovery_preclaim')
    digest=index.command_sha256(OP,plan['plan_sha256'])
    require(candidate.operation_digests().get(digest)==OP,
            'RECOVERY_WORKER_OPERATION_NOT_ACTIVE')
    require('receipt_only_evidence' in root_source(ACTIVE_ADAPTER).decode(),
            'ORIGINAL_306_RECEIPT_ONLY_ADAPTER_NOT_ACTIVE')
    return digest

def central_recovery_ready(legacy,actor:int,job:str|None=None):
    # Standard owner-authorized Central readback; never a caller-provided bool.
    php=('echo wp_json_encode(["connected"=>'
         '(class_exists("MAD4B_Original_313_Receipt_Recovery_Finalizer_V1",false)'
         ' && method_exists("MAD4B_Original_313_Receipt_Recovery_Finalizer_V1","status")'
         ' ? MAD4B_Original_313_Receipt_Recovery_Finalizer_V1::status() : null)]);')
    row=legacy.cli_json(php)
    actual=row.get('connected')
    require(isinstance(actual,dict) and
            actual.get('contract')=='mad4b.original313.receipt-only-central-finalizer.v1'
            and actual.get('operational_ready') is True
            and actual.get('independent_readback_ready') is True
            and actual.get('append_only_certificate_ready') is True,
            'CENTRAL_RECEIPT_ONLY_FINALIZER_NOT_ENROLLED')
    return actual

def reviewed_live_plan(index):
    require(os.geteuid()==0,'ROOT_ISSUER_REQUIRED')
    import registry
    import recovery_plan
    path=PLANS/(PLAN_SHA+'.json')
    plan=registry.checked_json(path,PLANS)
    require(plan.get('plan_sha256')==PLAN_SHA and plan.get('operation_id')==OP
            and plan.get('company')=='wayfatours'
            and plan.get('environment')=='production'
            and plan.get('site_uuid')=='a3a7cc36-db7e-4a14-8456-b41857def05c',
            'ORIGINAL_RECOVERY_PLAN_SCOPE_MISMATCH')
    require(recovery_plan.require_exact(plan)==plan,
            'ORIGINAL_APPLY_EVIDENCE_NOT_CURRENT')
    require(index.resolve(OP)['plan_contract']==plan['contract'],
            'ORIGINAL_RECOVERY_MANIFEST_SCOPE_NOT_MATCHED')
    return plan

def run_preflight():
    """Read current first-party original authorities, do not create any Job."""
    require(os.geteuid()==0,'FIRST_PARTY_ROOT_REQUIRED')
    require(HERE.is_dir() and HERE.stat().st_uid==0,'RECOVERY_ISSUER_SOURCE_UNTRUSTED')
    if str(HERE) not in sys.path:sys.path.insert(0,str(HERE))
    if str(INDEX) not in sys.path:sys.path.insert(0,str(INDEX))
    # This candidate cannot borrow the earlier Code Release authentication
    # adapter from a caller, only the existing reviewed first-party path.
    legacy=module(LEGACY_ISSUER,'first_party_original305_signed_transport')
    require(callable(getattr(legacy,'send',None)) and
            callable(getattr(legacy,'verify_original_job',None)),
            'ORIGINAL_305_306_ISSUER_BRIDGE_NOT_AVAILABLE')
    import registry
    index=registry.Registry(INDEX)
    plan=reviewed_live_plan(index)
    digest=all_sources_ready(index,plan)
    signer=legacy.signer()
    original_scope=legacy.admission(index,plan,signer)
    require(original_scope['company']==plan['company'] and
            original_scope['site_uuid']==plan['site_uuid'] and
            original_scope['environment']==plan['environment'] and
            original_scope['origin']==plan['origin'],
            'ORIGINAL_305_SIGNED_COMPANY_SCOPE_CHANGED')
    scope={**original_scope,'command_sha256':digest}
    central_recovery_ready(legacy,scope['original_actor_id'])
    require(GRANTS.is_dir() and not GRANTS.is_symlink() and GRANTS.stat().st_uid==0
            and GRANTS.stat().st_mode&0o077==0,
            'RECOVERY_SIGNED_GRANT_STORE_NOT_ENROLLED')
    return index,plan,signer,scope,legacy

def make_grant(job:dict,plan:dict,scope:dict,signer,*,now=None):
    """Pure builder; only the original Broker key can make a live signature."""
    import signed_recovery_admission as admission
    require(job['job_id']!=plan['original_job_id']
            and job['company']==plan['company']
            and job['environment']==plan['environment']
            and job['site_uuid']==plan['site_uuid']
            and job['command_sha256']==scope['command_sha256']
            and job['grant_id']==scope['original_305_grant_id']
            and job['transport_id']==scope['original_305_transport_id']
            and job['central_principal_id']==scope['original_actor_id']
            and job['executed'] is False
            and job['state']=='QUEUED_FOR_VPS_BROKER_CLAIM',
            'ORIGINAL_305_306_RECEIPT_ONLY_JOB_NOT_VERIFIED')
    now=now or dt.datetime.now(dt.timezone.utc)
    remain=job['expires_epoch']-int(now.timestamp())
    require(remain>720,'NEW_RECOVERY_JOB_WINDOW_INSUFFICIENT')
    expiry=now+dt.timedelta(minutes=10)
    require(expiry.timestamp()<job['expires_epoch']-120,
            'NEW_RECOVERY_GRANT_EXCEEDS_ORIGINAL_JOB_WINDOW')
    doc={
      'contract':admission.CONTRACT,'action':'receipt_only_readback',
      'issuer':'mad4b-company-broker','job_id':job['job_id'],
      'operation_id':OP,'company':plan['company'],
      'environment':plan['environment'],'site_uuid':plan['site_uuid'],
      'origin':plan['origin'],'plan_sha256':plan['plan_sha256'],
      'command_sha256':scope['command_sha256'],
      'original_job_id':plan['original_job_id'],
      'original_plan_sha256':plan['original_plan_sha256'],
      'historical_grant_sha256':plan['original_signed_artifact_hashes']['original_grant_sha256'],
      'historical_host_receipt_sha256':plan['original_signed_artifact_hashes']['original_host_receipt_sha256'],
      'original_305_grant_id':job['grant_id'],
      'original_305_transport_id':job['transport_id'],
      'production_mutation_allowed':False,'schema_ddl_allowed':False,
      'old_grant_reauthorized':False,'original_313_certified':False,
      'nonce':secrets.token_hex(16),'issued_at':now.isoformat(),
      'expires_at':expiry.isoformat()
    }
    doc['signature']=base64.b64encode(signer.sign(admission.signed_message(doc))).decode()
    check=admission.verify_document(plan,job['job_id'],scope['command_sha256'],doc,
          signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw),now=now)
    require(check['new_grant_signed_by_existing_broker'] is True,
            'ORIGINAL_NEW_RECEIPT_ONLY_BROKER_SIGNATURE_REJECTED')
    return doc

def issue():
    index,plan,key,scope,legacy=run_preflight()
    import registry
    import signed_recovery_admission as admission
    # Original 305/306 issue is performed by the SAME source-reviewed
    # Central signed transport and identity, not a new REST endpoint.
    body={'grant_id':scope['original_305_grant_id'],
          'argv':index.command_argv(OP,plan['plan_sha256']),
          'summary':'Signed first-party read-only Original 313 recovery verification',
          'correlation_id':'mad4b-recovery-'+secrets.token_hex(12),
          'target_scope':'wordpress'}
    request=legacy.send('intake',body,scope,key)
    require(request.get('company')==plan['company'] and
            request.get('command_sha256')==scope['command_sha256'] and
            request.get('status')=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
            'ORIGINAL_305_RECEIPT_ONLY_INTAKE_DENIED')
    jid=request['request_id']
    dispatched=legacy.send('dispatch',{'request_id':jid},scope,key)
    require(dispatched.get('job_id')==jid,'ORIGINAL_306_RECOVERY_JOB_MISMATCH')
    job=legacy.verify_original_job(jid,plan,scope)
    grant=make_grant(job,plan,scope,key)
    destination=GRANTS/(jid+'.json')
    require(destination.parent==GRANTS and not destination.exists() and
            not destination.is_symlink(),'ORIGINAL_RECOVERY_JOB_GRANT_ALREADY_EXISTS')
    fd=os.open(destination,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as out:
        out.write(registry.canonical(grant)+b'\n');out.flush();os.fsync(out.fileno())
    check=json.loads(admission.trusted(destination,GRANTS))
    require(check==grant,'NEW_RECOVERY_SIGNED_GRANT_READBACK_CHANGED')
    return {'state':'ORIGINAL_306_NEW_READONLY_RECOVERY_JOB_WITH_SIGNED_GRANT',
            'new_job_id':jid,'plan_sha256':plan['plan_sha256'],
            'current_grant_issued':True,'original_313_certified':False,
            'production_mutation':False,'ddl_performed':False}

if __name__=='__main__':
    ap=argparse.ArgumentParser()
    mode=ap.add_mutually_exclusive_group(required=True)
    mode.add_argument('--preflight',action='store_true')
    mode.add_argument('--execute',action='store_true')
    args=ap.parse_args()
    try:
        with open(LOCK,'a+') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            if args.preflight:
                index,plan,key,scope,legacy=run_preflight()
                print(json.dumps({'state':'ORIGINAL_305_NEW_RECOVERY_JOB_PREFLIGHT_VERIFIED',
                    'plan_sha256':plan['plan_sha256'],
                    'command_sha256':scope['command_sha256'],
                    'original_313_certified':False,'job_issued':False,
                    'grant_issued':False,'production_mutation':False},sort_keys=True))
            else:print(json.dumps(issue(),sort_keys=True))
    except Exception as exc:
        label=str(exc)
        if not label.isascii() or not 3<len(label)<128 or not all(c.isupper() or c.isdigit() or c in '_:' for c in label):
            label='FIRST_PARTY_RECEIPT_ONLY_ISSUER_PRECHECK_DENIED'
        # Execution may have reached Original 305 even if a later readback
        # failed. Unknown is not False: operators must reconcile Central jobs.
        print(json.dumps({'state':'RECEIPT_ONLY_ISSUER_NOT_READY','reason':label,
             'original_313_certified':False,
             'job_issued':None if args.execute else False,
             'grant_issued':None if args.execute else False,
             'production_mutation':False},sort_keys=True))
        raise SystemExit(2)
