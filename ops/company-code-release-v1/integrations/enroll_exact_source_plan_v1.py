#!/usr/bin/env python3
"""Generic, guarded Original 313 immutable plan registration, never execution.

Enrollment is a registration of reviewed source metadata, not a Broker grant.
This utility has no WordPress write path, no OAuth requirement, and accepts
any enrolled company/environment subject to existing native host policy.
"""
from __future__ import annotations
import argparse,fcntl,hashlib,importlib.util,json,os,stat,sys
from pathlib import Path

ENGINE=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/company-code-release-v1/company_code_release.py')
REG=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
POLICY=Path('/etc/mad4b/company-code-release-policy.json')
STORES=(Path('/var/lib/mad4b/dynamic-operation-registry/plans'),
        Path('/var/lib/mad4b/company-code-release/plans'))
LOCK=Path('/run/lock/mad4b-company-release-catalog.lock')

class Denied(Exception):pass

def file_bytes(p:Path,max_size=100000)->bytes:
    if p.is_symlink():raise Denied('PLAN_SOURCE_SYMLINK_DENIED')
    s=p.stat()
    if (not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077 or
        not 5<s.st_size<max_size):
        raise Denied('PLAN_SOURCE_ROOT_PRIVATE_REQUIRED')
    return p.read_bytes()

def trusted_store(p:Path):
    if p.is_symlink() or not p.is_dir():raise Denied('PLAN_STORE_NOT_DIRECTORY')
    s=p.stat()
    if s.st_uid!=0 or s.st_mode&0o077:raise Denied('PLAN_STORE_NOT_ROOT_PRIVATE')

def first_party(path:Path,name:str):
    if path.is_symlink():raise Denied('ENGINE_SYMLINK_DENIED')
    s=path.stat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022:
        raise Denied('ENGINE_NOT_ROOT_TRUSTED')
    spec=importlib.util.spec_from_file_location(name,path)
    if spec is None or spec.loader is None:raise Denied('ENGINE_NOT_LOADABLE')
    instance=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(instance)
    return instance

def validate(plan_path:Path,stores=STORES):
    if os.geteuid()!=0:raise Denied('ROOT_REQUIRED')
    engine=first_party(ENGINE,'mad4b_enrolled_release_plan_validator')
    policy=engine.trusted_config(POLICY)
    plan=json.loads(file_bytes(plan_path))
    if not isinstance(plan,dict):raise Denied('PLAN_JSON_NOT_OBJECT')
    try:
        engine.confirm_plan(plan,policy,expect_preimage=True)
    except engine.Denied as error:
        raise Denied('ORIGINAL_RELEASE_PLAN_REJECTED:'+str(error)) from error
    if plan.get('changed_count',0)<1:raise Denied('NO_CODE_CHANGES_IN_PLAN')
    reg=first_party(REG/'registry.py','mad4b_enrolled_dynamic_registry_validator')
    provider=reg.Registry(REG).resolve('company.code.release')
    if provider.get('operation_id')!='company.code.release':
        raise Denied('OPERATION_PROVIDER_NOT_INSTALLED')
    # Confirm dynamic signed command digest is resolvable using the
    # same immutable plan ID; no input determines a shell command.
    expected=reg.Registry(REG).command_sha256('company.code.release',plan['plan_sha256'])
    if not isinstance(expected,str) or len(expected)!=64:
        raise Denied('OPERATION_COMMAND_DIGEST_INVALID')
    for target in stores:trusted_store(target)
    body=engine.canonical(plan)+b'\n'
    targets=[p/(plan['plan_sha256']+'.json') for p in stores]
    for target in targets:
        if target.is_symlink():raise Denied('ENROLLED_PLAN_SYMLINK_DENIED')
        if target.exists() and file_bytes(target)!=body:
            raise Denied('ENROLLED_PLAN_HAS_DIFFERENT_BYTES')
    return plan,body,targets

def enroll(plan_path:Path,install:bool,stores=STORES):
    plan,body,targets=validate(plan_path,stores)
    output={'contract':'mad4b.company-exact-code-plan-enrollment.v1',
            'state':'EXACT_PLAN_ELIGIBLE_FOR_ENROLLMENT',
            'company':plan['company'],'environment':plan['environment'],
            'site_uuid':plan['site_uuid'],'operation_id':'company.code.release',
            'plan_sha256':plan['plan_sha256'],'changed_count':plan['changed_count'],
            'root_policy_unchanged':True,'wp_files_modified':False,
            'database_modified':False,'jobs_created':False,
            'grants_created':False,'original_313_certified':False}
    if not install:return output
    inserted=[]
    try:
        for target in targets:
            if target.exists():continue
            fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as stream:
                stream.write(body);stream.flush();os.fsync(stream.fileno())
            inserted.append(target)
        # Re-read complete expected plan, not just the requested digest.
        for target in targets:
            if file_bytes(target)!=body:raise Denied('REGISTERED_PLAN_READBACK_MISMATCH')
    except Exception:
        for target in inserted:
            if target.is_file() and not target.is_symlink() and target.read_bytes()==body:
                target.unlink()
        raise
    output.update(state='EXACT_PLAN_REGISTERED_PENDING_SIGNED_BROKER',
                  enrolled_store_count=len(targets),files_inserted=len(inserted))
    return output

if __name__=='__main__':
    cli=argparse.ArgumentParser()
    cli.add_argument('--plan',type=Path,required=True)
    actions=cli.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check',action='store_true')
    actions.add_argument('--install',action='store_true')
    args=cli.parse_args()
    try:
        if args.install:
            with open(LOCK,'a+') as file:
                fcntl.flock(file,fcntl.LOCK_EX)
                out=enroll(args.plan,True)
        else:out=enroll(args.plan,False)
        print(json.dumps(out,sort_keys=True))
    except (Denied,ValueError,TypeError,KeyError,OSError,RuntimeError) as error:
        print(json.dumps({'state':'PLAN_ENROLLMENT_DENIED','reason':str(error),
                          'jobs_created':False,'grants_created':False,
                          'wp_files_modified':False},sort_keys=True))
        raise SystemExit(2)
