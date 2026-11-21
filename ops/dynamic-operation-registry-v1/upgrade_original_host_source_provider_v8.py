#!/usr/bin/env python3
"""First-party CAS migration from the enrolled Original Host V6 to V7.

V7 adds source-only diagnostics to the enrolled root Registry; it does NOT
create a Grant, WordPress option, Host312 proof, Host313 receipt or renewal timer.
All original operations, public keys and current file bytes remain protected.
"""
from __future__ import annotations
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile

import registry

REPO=Path(__file__).resolve().parents[2]
OPS=REPO/'ops'
ROOT=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
INDEX=ROOT/'index.json'
SOURCE='dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.CANDIDATE.json'
OLD='dynamic-operation-registry-v1/providers/host.source.release/manifest.v7.json'
NEW='dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.json'
BASE_SHA='f37b01faf36381a4e01b1220c006545e65cd7cebda85038b63c757bc9811dc08'
REPORT=Path('/opt/mad4b-company-provisioning/reports/host-original-provider-v8-firstparty-20261116')
LOCK=ROOT/'.original-host-v8-install.lock'
OP='host.source.release'
ORIGINAL_OPS={'host.source.release','company.code.release',
              'plugin.license.activate','company.code.release.receipt.reconcile'}
CORE='company-code-release-v1/company_code_release.py'

class V6InstallDenied(ValueError):pass

def require(ok,why):
    if not ok:raise V6InstallDenied(why)

def sha(raw):return hashlib.sha256(raw).hexdigest()

def committed(head,rel):
    registry.bounded_relative(rel)
    path=OPS/rel
    require(path.is_file() and not path.is_symlink() and path.stat().st_uid==0
            and not path.stat().st_mode&0o022,'SOURCE_NOT_ROOT_OWNED')
    r=subprocess.run(['git','-C',str(REPO),'show',head+':ops/'+rel],
                     capture_output=True,timeout=25)
    require(r.returncode==0 and r.stdout==path.read_bytes(),'GIT_SOURCE_NOT_COMMITTED_EXACT')
    return r.stdout

def atomic(path,data):
    fd,name=tempfile.mkstemp(prefix='.original-host-v6-',dir=path.parent)
    try:
        os.fchmod(fd,0o600);os.fchown(fd,0,0)
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def plan():
    require(os.geteuid()==0,'ORIGINAL_HOST_ROOT_REQUIRED')
    require(ROOT.is_dir() and not ROOT.is_symlink() and
            ROOT.stat().st_uid==0 and ROOT.stat().st_gid==0 and
            stat.S_IMODE(ROOT.stat().st_mode)==0o700,'ROOT_REGISTRY_UNTRUSTED')
    before=registry.protected_file(INDEX,ROOT)
    require(sha(before)==BASE_SHA,'ORIGINAL_V7_REGISTRY_INDEX_CAS_DRIFT')
    require(not subprocess.check_output(['git','-C',str(REPO),'status','--porcelain']).strip(),
            'SOURCE_WORKTREE_NOT_CLEAN')
    head=subprocess.check_output(['git','-C',str(REPO),'rev-parse','HEAD'],text=True).strip()
    active=registry.Registry(ROOT)
    require(set(active.manifests)==ORIGINAL_OPS,'ORIGINAL_PROVIDER_SET_DRIFT')
    old=active.resolve(OP)
    require(old['version']==7,'ORIGINAL_HOST_NOT_V7')
    fresh=committed(head,SOURCE)
    new=json.loads(fresh)
    require(new.get('version')==8 and new.get('operation_id')==OP,
            'HOST_V6_MANIFEST_IDENTITY_CHANGED')
    stable=('contract','operation_id','provider','source_family','plan_contract',
            'target_scope','resource_kind','job_type','handler','verifier',
            'finalizer','evidence_schema','evidence','command')
    require(all(new.get(k)==old.get(k) for k in stable),
            'HOST_V6_EXECUTOR_OR_IDENTITY_CHANGED')
    old_deps={v['path']:v['sha256'] for v in old['dependencies']}
    new_deps={v['path']:v['sha256'] for v in new['dependencies']}
    require(len(new_deps)==len(new['dependencies']) and
            all(new_deps.get(k)==v for k,v in old_deps.items()) and
            len(new_deps)==len(old_deps)+1 and
            new_deps.get('native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py'),
            'HOST_V8_NOT_ADDITIVE')
    files={NEW:fresh}
    for spec in (new['handler'],new['verifier'],new['finalizer'],new['evidence'],*new['dependencies']):
        rel=registry.bounded_relative(spec['path'])
        if rel in files:
            require(sha(files[rel])==spec['sha256'],'REPEATED_PIN_MISMATCH')
            continue
        installed=ROOT/rel
        if installed.exists() or installed.is_symlink():
            data=registry.protected_file(installed,ROOT)
        else:
            require(rel!=CORE,'ORIGINAL_CODE_RELEASE_CORE_MISSING')
            data=committed(head,rel)
        require(sha(data)==spec['sha256'],'PINNED_COMPONENT_DRIFT:'+rel)
        files[rel]=data
    idx=json.loads(before)
    rows=[i for i,x in enumerate(idx['providers']) if x.get('manifest')==OLD]
    require(len(rows)==1 and idx['providers'][rows[0]]['sha256']==sha((ROOT/OLD).read_bytes()),
            'ORIGINAL_V2_MANIFEST_INDEX_DRIFT')
    result=dict(idx);result['providers']=[dict(x) for x in idx['providers']]
    result['providers'][rows[0]]={'manifest':NEW,'sha256':sha(fresh)}
    result['host_v8_additive_upgrade_commit']=head
    after=registry.canonical(result)+b'\n'
    with tempfile.TemporaryDirectory(prefix='mad4b-firstparty-v6-') as t:
        dest=Path(t)/'enrolled';shutil.copytree(ROOT,dest)
        for rel,data in files.items():
            file=dest/rel
            if file.exists():require(file.read_bytes()==data,'TEMP_COMPONENT_CONFLICT')
            else:
                file.parent.mkdir(parents=True,exist_ok=True)
                file.write_bytes(data);file.chmod(0o600)
        (dest/'index.json').write_bytes(after);(dest/'index.json').chmod(0o600)
        nxt=registry.Registry(dest)
        require(set(nxt.manifests)==ORIGINAL_OPS and nxt.resolve(OP)==new and
                all(nxt.resolve(k)==active.resolve(k) for k in ORIGINAL_OPS-{OP}) and
                nxt.host_resource_312_public_key==active.host_resource_312_public_key and
                nxt.independent_verifier_public_key==active.independent_verifier_public_key,
                'HOST_V6_ISOLATED_REGISTRY_REJECTED')
    return head,active,before,after,files

def run(*,install=False):
    head,active,before,after,files=plan()
    result={'state':'ORIGINAL_HOST_V8_ISOLATED_REGISTRY_VERIFIED_NOT_INSTALLED',
            'source_commit':head,'registry_preimage_sha256':sha(before),
            'registry_candidate_sha256':sha(after),
            'manifest_v8_sha256':sha(files[NEW]),
            'original_ops_preserved':sorted(active.manifests),
            'new_file_count':sum(not (ROOT/f).exists() for f in files),
            'signing_timer_enabled':False,'signed_readiness_issued':False,
            'master_approval_issued':False,'host312_proof_issued':False,
            'original313_receipt_issued':False,'host_release_authorized':False}
    if not install:return result
    require(not REPORT.exists(),'FIRSTPARTY_V8_REPORT_ALREADY_EXISTS')
    require(not LOCK.is_symlink(),'FIRSTPARTY_V8_LOCK_SYMLINK_DENIED')
    fd=os.open(LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        meta=os.fstat(fd)
        require(stat.S_ISREG(meta.st_mode) and meta.st_uid==0 and meta.st_gid==0
                and meta.st_nlink==1 and stat.S_IMODE(meta.st_mode)==0o600,
                'FIRSTPARTY_V8_LOCK_UNTRUSTED')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        require(sha(INDEX.read_bytes())==sha(before),'INDEX_CONCURRENT_DRIFT')
        REPORT.mkdir(mode=0o700)
        backup=REPORT/'ORIGINAL-REGISTRY-INDEX-PRE-V8.json'
        f=os.open(backup,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(f,'wb') as out:
            out.write(before);out.flush();os.fsync(out.fileno())
        created=[];changed=False
        try:
            for rel,data in files.items():
                path=ROOT/rel
                if path.exists() or path.is_symlink():
                    require(not path.is_symlink() and path.read_bytes()==data,
                            'FIRSTPARTY_V8_COMPONENT_CHANGED:'+rel)
                    continue
                path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
                require(path.parent.stat().st_uid==0 and not path.parent.is_symlink(),
                        'FIRSTPARTY_V8_PARENT_UNTRUSTED')
                f=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
                with os.fdopen(f,'wb') as out:
                    out.write(data);out.flush();os.fsync(out.fileno())
                created.append(rel)
            require(sha(INDEX.read_bytes())==sha(before),'FIRSTPARTY_V8_INDEX_CAS_FAILED')
            atomic(INDEX,after);changed=True
            enrolled=registry.Registry(ROOT)
            require(enrolled.resolve(OP)['version']==8 and
                    set(enrolled.manifests)==ORIGINAL_OPS and
                    all(enrolled.resolve(k)==active.resolve(k) for k in ORIGINAL_OPS-{OP}) and
                    enrolled.host_resource_312_public_key==active.host_resource_312_public_key and
                    enrolled.independent_verifier_public_key==active.independent_verifier_public_key,
                    'FIRSTPARTY_V8_POSTINSTALL_REGRESSION')
        except BaseException:
            if changed and INDEX.read_bytes()==after:atomic(INDEX,before)
            for rel in reversed(created):
                path=ROOT/rel
                if path.is_file() and path.read_bytes()==files[rel]:path.unlink()
            raise
        result.update({'state':'ORIGINAL_HOST_V8_ADDITIVE_PROVIDER_ENROLLED',
                       'registry_postimage_sha256':sha(after),'original_index_backup':str(backup),
                       'new_files_installed':created})
        receipt=REPORT/'FINAL-ORIGINAL-HOST-V8-ROOT-READBACK.json'
        f=os.open(receipt,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(f,'w') as out:
            json.dump(result,out,sort_keys=True,indent=2);out.write('\n');out.flush();os.fsync(out.fileno())
        return result
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN);os.close(fd)

if __name__=='__main__':
    p=argparse.ArgumentParser()
    choice=p.add_mutually_exclusive_group(required=True)
    choice.add_argument('--check',action='store_true')
    choice.add_argument('--install',action='store_true')
    a=p.parse_args()
    print(json.dumps(run(install=a.install),sort_keys=True))
