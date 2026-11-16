#!/usr/bin/env python3
"""Host-source ownership admission for the existing Native Source Publisher.

READ-ONLY. Extends provider discovery with root-owned Host/Native resources.
It neither signs authority nor applies files. The installed Original306/312/313
publisher must separately support these resources before a write is possible.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess

CATALOG = Path('/etc/mad4b/native-source-publisher-host-owners.v1.json')
HOST_OWNER_BASE = Path('/opt/mad4b-company-transport')
CONTRACT = 'mad4b.native-source-host-owners.v1'
REQUEST = 'mad4b.native-host-source-readonly-request.v1'
PLAN = 'mad4b.native-host-source-readonly-plan.v1'
ID = re.compile(r'^[a-z][a-z0-9.-]{2,90}$')
SHA = re.compile(r'^[a-f0-9]{64}$')
COMMIT = re.compile(r'^[a-f0-9]{40}$')
ALLOWED = frozenset(('.py', '.sh', '.service', '.timer'))
MAX_FILES = 16
MAX_BYTES = 2_000_000


class HostSourceDenied(Exception):
    pass


def deny(reason: str):
    raise HostSourceDenied(reason)


def digest(value: bytes):
    return hashlib.sha256(value).hexdigest()


def canon(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':')).encode('utf-8')


def safe_relative(value: str) -> str:
    if not isinstance(value, str) or len(value)>240 or not value:
        deny('HOST_SOURCE_PATH_MISSING')
    p=PurePosixPath(value)
    if (p.is_absolute() or '.' in p.parts or '..' in p.parts or
        p.as_posix()!=value or '\\' in value or '//' in value or '\x00' in value or
        p.suffix.lower() not in ALLOWED):
        deny('HOST_SOURCE_PATH_INVALID')
    return value


def exact_lock(value: str) -> str:
    if (not isinstance(value,str) or not re.fullmatch(
        r'/run/lock/[a-zA-Z0-9][a-zA-Z0-9_.-]{2,126}\.lock',value)):
        deny('HOST_SOURCE_ORIGINAL_LOCK_NOT_PINNED')
    return value


def root_owned(path: Path, *, file: bool) -> None:
    if not path.is_absolute():deny('HOST_SOURCE_ROOT_NOT_ABSOLUTE')
    for component in (path,*path.parents):
        if component.is_symlink():deny('HOST_SOURCE_SYMLINK_DENIED')
    if not path.exists():deny('HOST_SOURCE_PATH_NOT_FOUND')
    meta=path.stat()
    if (meta.st_uid != 0 or meta.st_gid != 0 or
        (meta.st_mode & 0o022) or (file and
        (not stat.S_ISREG(meta.st_mode) or meta.st_nlink!=1)) or
        (not file and not stat.S_ISDIR(meta.st_mode))):
        deny('HOST_SOURCE_ROOT_OWNERSHIP_INVALID')


def catalog_data_validate(catalog: dict) -> None:
    if (not isinstance(catalog,dict) or set(catalog)!={'contract','enabled','git_root','owners'}
        or catalog['contract']!=CONTRACT or catalog['enabled'] is not True
        or not isinstance(catalog['git_root'],str)
        or not isinstance(catalog['owners'],dict)
        or not 1<=len(catalog['owners'])<=100):
        deny('HOST_SOURCE_OWNER_CATALOG_NOT_ENROLLED')
    repo=Path(catalog['git_root'])
    if (not repo.is_absolute() or str(repo)!=catalog['git_root'] or
        '..' in repo.parts or '.' in repo.parts):
        deny('HOST_SOURCE_GIT_ROOT_NOT_PINNED')
    all_targets=set()
    for oid,owner in catalog['owners'].items():
        if not isinstance(oid,str) or not ID.fullmatch(oid) or not isinstance(owner,dict):
            deny('HOST_SOURCE_OWNER_ID_INVALID')
        if set(owner)!={'scope','target_root','file_claims','locks'}:
            deny('HOST_SOURCE_OWNER_FIELDS_INVALID')
        if (not isinstance(owner['scope'],str) or not re.fullmatch(
            r'host:[a-zA-Z0-9][a-zA-Z0-9_.-]{2,80}',owner['scope'])):
            deny('HOST_SOURCE_OWNER_SCOPE_INVALID')
        root=Path(owner['target_root']) if isinstance(owner['target_root'],str) else Path('.')
        if (not root.is_absolute() or str(root)!=owner['target_root'] or
            not root.is_relative_to(HOST_OWNER_BASE) or root==HOST_OWNER_BASE or
            '..' in root.parts or '.' in root.parts):
            deny('HOST_SOURCE_ROOT_OUTSIDE_HOST_OWNER')
        locks=owner['locks']
        if (not isinstance(locks,list) or not 1<=len(locks)<=8 or
            len(set(map(str,locks)))!=len(locks)):
            deny('HOST_SOURCE_ORIGINAL_LOCKS_INVALID')
        for lock in locks: exact_lock(lock)
        records=owner['file_claims']
        if not isinstance(records,list) or not 1<=len(records)<=150:
            deny('HOST_SOURCE_OWNER_CLAIMS_INVALID')
        per_owner=set()
        for claim in records:
            if not isinstance(claim,dict) or set(claim)!={'source','target'}:
                deny('HOST_SOURCE_FILE_CLAIM_INVALID')
            src=safe_relative(claim['source'])
            dst=safe_relative(claim['target'])
            if dst in per_owner:deny('HOST_SOURCE_DUPLICATE_OWNED_FILE')
            per_owner.add(dst)
            # Distinct declared owners cannot claim the same live path.
            canonical=str(root/dst)
            if canonical in all_targets:deny('HOST_SOURCE_CROSS_OWNER_COLLISION')
            all_targets.add(canonical)


def checked_host_catalog(path: Path = CATALOG) -> dict:
    if path!=CATALOG:deny('HOST_SOURCE_CATALOG_PATH_NOT_PINNED')
    if not path.exists():deny('HOST_SOURCE_PROVIDER_NOT_ENROLLED')
    root_owned(path, file=True)
    if (path.stat().st_mode & 0o077 or path.stat().st_size>100_000):
        deny('HOST_SOURCE_CATALOG_NOT_ROOT_PRIVATE')
    try:
        result=json.loads(path.read_text())
    except (OSError,ValueError) as exc:
        raise HostSourceDenied('HOST_SOURCE_CATALOG_INVALID_JSON') from exc
    catalog_data_validate(result)
    root_owned(Path(result['git_root']),file=False)
    for owner in result['owners'].values():
        root_owned(Path(owner['target_root']),file=False)
    return result


def git_blob(repo: Path,commit: str,source: str)->bytes:
    if not isinstance(commit,str) or not COMMIT.fullmatch(commit):
        deny('HOST_SOURCE_COMMIT_INVALID')
    safe_relative(source)
    def run(args):
        p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                         timeout=22,check=False)
        if p.returncode!=0:deny('HOST_SOURCE_GIT_OBJECT_UNAVAILABLE')
        return p.stdout
    run(['git','-C',str(repo),'cat-file','-e',commit+'^{commit}'])
    tree=run(['git','-C',str(repo),'ls-tree',commit,'--',source]).decode()
    if not tree.startswith(('100644 blob ','100755 blob ')):
        deny('HOST_SOURCE_GIT_BLOB_NOT_REGULAR')
    body=run(['git','-C',str(repo),'show',commit+':'+source])
    if len(body)>MAX_BYTES:deny('HOST_SOURCE_GIT_BLOB_TOO_LARGE')
    return body


def create_host_plan(request: dict,catalog: dict) -> dict:
    """Build provenance only. A plan is never a grant or executable intent."""
    catalog_data_validate(catalog)
    if (not isinstance(request,dict) or set(request)!={'contract','owner_id','git_commit','files'}
        or request['contract']!=REQUEST or request['owner_id'] not in catalog['owners']
        or not isinstance(request['git_commit'],str)
        or not COMMIT.fullmatch(request['git_commit'])):
        deny('HOST_SOURCE_REQUEST_INVALID_OR_OWNER_NOT_ENROLLED')
    owner=catalog['owners'][request['owner_id']]
    claims={(v['source'],v['target']) for v in owner['file_claims']}
    files=request['files']
    if (not isinstance(files,list) or not 1<=len(files)<=MAX_FILES):
        deny('HOST_SOURCE_REQUEST_FILE_COUNT_INVALID')
    repo=Path(catalog['git_root']);root=Path(owner['target_root'])
    root_owned(repo,file=False)
    root_owned(root,file=False)
    # Validation is independent of caller-supplied file paths. The signed
    # first-party owner registry must own the exact source/target pair.
    result=[];seen=set()
    for entry in files:
        if not isinstance(entry,dict) or set(entry)!={'source','target','expected_before_sha256'}:
            deny('HOST_SOURCE_FILE_RECORD_INVALID')
        src=safe_relative(entry['source']);dst=safe_relative(entry['target'])
        before=entry['expected_before_sha256']
        if (src,dst) not in claims or dst in seen:
            deny('HOST_SOURCE_FILE_UNCLAIMED_OR_DUPLICATE')
        seen.add(dst)
        if not isinstance(before,str) or not SHA.fullmatch(before):
            deny('HOST_SOURCE_EXPECTED_PREIMAGE_INVALID')
        file=root/dst
        parent=file.parent
        while parent!=root:
            root_owned(parent,file=False)
            parent=parent.parent
        root_owned(file,file=True)
        if file.stat().st_size>MAX_BYTES:deny('HOST_SOURCE_LIVE_FILE_TOO_LARGE')
        current=digest(file.read_bytes())
        if current!=before:deny('HOST_SOURCE_PREIMAGE_CHANGED')
        content=git_blob(repo,request['git_commit'],src)
        new=digest(content)
        result.append({'source':src,'target':dst,'expected_before_sha256':before,
                       'source_sha256':new,'changed':new!=before})
    if not any(v['changed'] for v in result):deny('HOST_SOURCE_NO_CHANGE')
    response={
        'contract':PLAN,
        'owner_id':request['owner_id'],
        'scope':owner['scope'],
        'git_root':catalog['git_root'],
        'git_commit':request['git_commit'],
        'target_root':owner['target_root'],
        'owner_catalog_sha256':digest(canon(catalog)),
        'original_lock_paths':sorted(owner['locks']),
        'files':result,
        'authority':'READ_ONLY_PREFLIGHT',
        'publisher_registered':False,
        'original_312_verified':False,
        'broker_grant_verified':False,
        'master_approval_verified':False,
        'original_313_receipt_signed':False,
        'publication_authorized':False,
    }
    response['plan_sha256']=digest(canon(response))
    return response


def verify_host_plan(plan:dict,catalog:dict)->None:
    if not isinstance(plan,dict) or set(plan) != {'plan_sha256','contract',
            'owner_id','scope','git_root','git_commit','target_root','owner_catalog_sha256',
            'original_lock_paths','files','authority','publisher_registered',
            'original_312_verified','broker_grant_verified',
            'master_approval_verified','original_313_receipt_signed',
            'publication_authorized'}:
        deny('HOST_SOURCE_PLAN_SHAPE_INVALID')
    if not isinstance(plan.get('plan_sha256'),str) or not SHA.fullmatch(plan['plan_sha256']):
        deny('HOST_SOURCE_PLAN_DIGEST_INVALID')
    if plan['plan_sha256']!=digest(canon({k:v for k,v in plan.items() if k!='plan_sha256'})):
        deny('HOST_SOURCE_PLAN_TAMPERED')
    request={'contract':REQUEST,'owner_id':plan['owner_id'],
             'git_commit':plan['git_commit'],
             'files':[{k:v[k] for k in ('source','target','expected_before_sha256')}
                      for v in plan['files']]}
    derived=create_host_plan(request,catalog)
    if plan!=derived:deny('HOST_SOURCE_PLAN_OR_OWNER_DRIFT')


def status()->dict:
    try:
        p=checked_host_catalog()
        return {'state':'ENROLLED_FOR_READ_ONLY_PREFLIGHT',
                'owner_count':len(p['owners']), 'publication_authorized':False}
    except HostSourceDenied as exc:
        return {'state':'HOLD__'+str(exc),'owner_count':0,
                'publication_authorized':False}
