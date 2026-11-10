#!/usr/bin/env python3
"""Enroll only a privately reviewed exact receipt-only plan, never a Job.

This registration cannot grant 305/306/312 execution authority. Source and
postimage are rechecked through the installed immutable First-Party Registry.
"""
from __future__ import annotations
import argparse,fcntl,importlib.util,json,os,stat,sys,tempfile
from pathlib import Path

INSTALLED=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
STORE=Path('/var/lib/mad4b/dynamic-operation-registry/plans')
REQUEST=Path('/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110/original313-receipt-only-proposed-plan-15e021173057e17be0c2bdbcbc3f60ff7a82f2272b66a0963558d0e87722c4e8.json')
OP='company.code.release.receipt.reconcile'
LOCK=Path('/run/lock/mad4b-original313-receipt-only-plan-enrollment.lock')

class Denied(RuntimeError):pass

def require(ok,code):
    if not ok:raise Denied(code)

def load_module(path,name):
    require(path.is_file() and not path.is_symlink() and path.stat().st_uid==0 and path.stat().st_mode&0o022==0,
            'FIRST_PARTY_MODULE_NOT_TRUSTED')
    spec=importlib.util.spec_from_file_location(name,path)
    require(spec is not None and spec.loader is not None,'FIRST_PARTY_MODULE_UNAVAILABLE')
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def validate(store=STORE,request=REQUEST):
    require(os.geteuid()==0,'ROOT_REQUIRED')
    require(not store.is_symlink() and store.is_dir() and store.stat().st_uid==0 and store.stat().st_mode&0o077==0,
            'FIRST_PARTY_PLAN_STORE_NOT_PRIVATE')
    require(request.is_file() and not request.is_symlink() and request.stat().st_uid==0 and request.stat().st_mode&0o077==0,
            'UNTRUSTED_RECOVERY_PLAN_REQUEST')
    index=load_module(INSTALLED/'registry.py','mad4b_first_party_receipt_enroll_index')
    enrolled=index.Registry(INSTALLED)
    manifest=enrolled.resolve(OP)
    require(manifest['operation_id']==OP and manifest['plan_contract']=='mad4b.company-code-release.receipt-reconcile-plan.v1',
            'RECEIPT_ONLY_PROVIDER_NOT_ENROLLED')
    parent=INSTALLED/'receipt-reconcile-v1'
    if str(parent) not in sys.path:sys.path.insert(0,str(parent))
    plan_module=load_module(parent/'recovery_plan.py','mad4b_enrolled_receipt_recovery_plan')
    original=json.loads(request.read_text())
    require(isinstance(original,dict),'RECOVERY_PLAN_NOT_OBJECT')
    verified=plan_module.require_exact(original)
    require(index.canonical(verified)==index.canonical(original),'RECOVERY_PLAN_DIVERGED')
    digest=original['plan_sha256']
    require(index.SHA.fullmatch(digest) is not None,'RECOVERY_PLAN_HASH_INVALID')
    target=store/(digest+'.json')
    require(not target.is_symlink(),'RECOVERY_PLAN_DESTINATION_SYMLINK')
    raw=index.canonical(verified)+b'\n'
    if target.exists():
        require(index.protected_file(target,store)==raw,'RECOVERY_PLAN_REGISTRY_CONFLICT')
    command_sha=enrolled.command_sha256(OP,digest)
    require(isinstance(command_sha,str) and index.SHA.fullmatch(command_sha),
            'RECOVERY_COMMAND_DIGEST_INVALID')
    return enrolled,original,target,raw,command_sha

def enroll(install=False,store=STORE,request=REQUEST):
    enrolled,plan,target,raw,command=validate(store,request)
    result={'contract':'mad4b.original313.exact-receipt-plan-enrollment.v1',
       'state':'ENROLLMENT_READY' if not target.exists() else 'PLAN_ALREADY_REGISTERED',
       'operation_id':OP,'company':plan['company'],'environment':plan['environment'],
       'site_uuid':plan['site_uuid'],'original_job_id':plan['original_job_id'],
       'plan_sha256':plan['plan_sha256'],'command_sha256':command,
       'job_created':False,'grant_issued':False,'original_313_certified':False,
       'schema_ddl_executed':False,'wordpress_modified':False}
    if not install or target.exists():return result
    require(store==STORE,'PRODUCTION_REGISTRY_PATH_NOT_PINNED')
    fd=os.open(target,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(raw);stream.flush();os.fsync(stream.fileno())
        require(target.read_bytes()==raw,'RECEIPT_PLAN_ATOMIC_READBACK_FAIL')
        # Validate the plan through the *original* installed adapter, not
        # only the new provider's source code.
        if str(INSTALLED) not in sys.path:sys.path.insert(0,str(INSTALLED))
        original=load_module(INSTALLED/'worker_adapter.py','mad4b_first_party_receipt_worker_adapter')
        mapped=original.indexed_plans(enrolled,store)
        require(command in mapped and mapped[command]['operation']==OP and
                mapped[command]['plan']['original_job_id']==plan['original_job_id'],
                'ORIGINAL_DYNAMIC_306_PLAN_NOT_DISCOVERABLE')
    except Exception:
        if target.is_file() and not target.is_symlink() and target.read_bytes()==raw:target.unlink()
        raise
    result['state']='RECEIPT_ONLY_PLAN_REGISTERED_NO_GRANT'
    return result

if __name__=='__main__':
    cli=argparse.ArgumentParser();q=cli.add_mutually_exclusive_group(required=True)
    q.add_argument('--check',action='store_true');q.add_argument('--install',action='store_true')
    args=cli.parse_args()
    try:
        if args.install:
            with open(LOCK,'a+') as stream:
                fcntl.flock(stream,fcntl.LOCK_EX|fcntl.LOCK_NB)
                result=enroll(True)
        else:result=enroll()
        print(json.dumps(result,sort_keys=True))
    except (Denied,OSError,ValueError,TypeError,KeyError,RuntimeError) as e:
        print(json.dumps({'state':'RECEIPT_PLAN_ENROLLMENT_DEFERRED',
            'reason':str(e) if isinstance(e,Denied) else 'ORIGINAL_DYNAMIC_PLAN_VALIDATION_REQUIRED',
            'job_created':False,'grant_issued':False,'original_313_certified':False},sort_keys=True))
        raise SystemExit(2)
