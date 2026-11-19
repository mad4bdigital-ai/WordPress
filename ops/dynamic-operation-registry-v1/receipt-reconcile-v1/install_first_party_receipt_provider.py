#!/usr/bin/env python3
"""First-party add-only upgrade of existing Original 313 provider registry.

Installs five pinned provider artifacts and a single index entry last.
No 305/306/312 Grant, no Job, no Central 313 receipt, no code apply, no DDL.
The new provider's *execution* remains denied without distinct signed authority.
"""
from __future__ import annotations
import argparse,fcntl,hashlib,importlib.util,json,os,stat,subprocess,tempfile
from pathlib import Path

SOURCE=Path('/opt/mad4b-company-provisioning/worktrees/original313-receipt-provider-source-pinned-20261111')
COMMIT='db5b89d82b86e94124f6d307adec6f5f37202e0e'
INSTALLED=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
NEW_OP='company.code.release.receipt.reconcile'
MANIFEST='dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/manifest.json'
FILES={
 'receipt-reconcile-v1/host_handler.py':'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/host_handler.py',
 'receipt-reconcile-v1/independent_readback.py':'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/independent_readback.py',
 'receipt-reconcile-v1/recovery_plan.py':'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/recovery_plan.py',
 'receipt-reconcile-v1/historical_audit.py':'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/historical_audit.py',
 MANIFEST:'ops/dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/manifest.json',
 'dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/evidence-schema.json':'ops/dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/evidence-schema.json'
}
LOCK=Path('/run/lock/mad4b-first-party-original313-provider-upgrade.lock')
BACKUP=Path('/opt/mad4b-company-provisioning/backups/original313-provider-index-before-receipt-reconcile-20261111.json')

class Denied(RuntimeError):pass

def require(ok,code):
    if not ok:raise Denied(code)

def sha(raw):return hashlib.sha256(raw).hexdigest()

def protected(path,maxlen=100000):
    require(path.is_file() and not path.is_symlink(),'FIRST_PARTY_ARTIFACT_NOT_FILE')
    s=path.stat()
    require(s.st_uid==0 and not s.st_mode&0o022 and 1<s.st_size<maxlen,
            'FIRST_PARTY_ARTIFACT_NOT_TRUSTED')
    return path.read_bytes()

def registry_module(root):
    path=root/'registry.py'
    sp=importlib.util.spec_from_file_location('original_registry_reviewed_provider_upgrade',path)
    require(sp and sp.loader,'INSTALLED_REGISTRY_IMPORT_MISSING')
    mod=importlib.util.module_from_spec(sp);sp.loader.exec_module(mod)
    return mod

def candidate(root=INSTALLED):
    require(os.geteuid()==0,'FIRST_PARTY_ROOT_REQUIRED')
    require(SOURCE.is_dir() and not SOURCE.is_symlink() and SOURCE.stat().st_uid==0,
            'SOURCE_WORKTREE_UNTRUSTED')
    head=subprocess.check_output(['git','-C',str(SOURCE),'rev-parse','HEAD'],text=True).strip()
    require(head==COMMIT and not subprocess.check_output(['git','-C',str(SOURCE),'status','--porcelain'],text=True).strip(),
            'SOURCE_REVIEWED_COMMIT_NOT_CLEAN')
    actual={}
    for rel,src in FILES.items():
        raw=protected(SOURCE/src)
        tracked=subprocess.check_output(['git','-C',str(SOURCE),'show',COMMIT+':'+src])
        require(raw==tracked,'SOURCE_REVIEWED_GIT_BYTES_CHANGED')
        actual[rel]=raw
    active_path=root/'index.json'
    active_bytes=protected(active_path)
    old=json.loads(active_bytes)
    mod=registry_module(root)
    current=mod.Registry(root)
    require('company.code.release' in current.manifests and
            'plugin.license.activate' in current.manifests,
            'ORIGINAL_REGISTERED_PROVIDERS_NOT_PRESERVED')
    before=set(current.manifests)
    require(before in ({'company.code.release','plugin.license.activate'},
                       {'company.code.release','plugin.license.activate',NEW_OP}),
            'UNKNOWN_CONCURRENT_PROVIDER_MUST_BE_REVIEWED')
    expected_entry={'manifest':MANIFEST,'sha256':sha(actual[MANIFEST])}
    present=NEW_OP in before
    if present:
        require(any(r==expected_entry for r in old.get('providers',[])),
                'EXISTING_RECOVERY_PROVIDER_DIFFERS')
    else:
        require(all(r.get('manifest')!=MANIFEST for r in old['providers']),
                'CONFLICTING_PROVIDER_ENTRY')
    result=json.loads(active_bytes)
    if not present:result['providers'].append(expected_entry)
    return mod,actual,active_bytes,result,present

def atomic(path,data,mode=0o600):
    fd,tmp=tempfile.mkstemp(prefix='.firstparty-recovery-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),mode)
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.lexists(tmp):os.unlink(tmp)

def install(root=INSTALLED,apply=False):
    mod,files,before,proposed,present=candidate(root)
    result={'contract':'mad4b.original313.first-party-receipt-provider-upgrade.v1',
            'state':'ALREADY_INSTALLED' if present else 'FIRST_PARTY_PROVIDER_READY',
            'operation_id':NEW_OP,'source_git_commit':COMMIT,
            'provider_manifest_sha256':sha(files[MANIFEST]),
            'index_before_sha256':sha(before),
            'job_created':False,'grant_issued':False,
            'wordpress_modified':False,'production_ddl_executed':False,
            'original_313_certified':False}
    if not apply or present:return result
    require(root==INSTALLED,'LIVE_INSTALL_ROOT_MISMATCH')
    parent=root.parent
    require(root.is_dir() and not root.is_symlink() and root.stat().st_uid==0 and root.stat().st_mode&0o077==0,
            'INSTALLED_REGISTRY_ROOT_UNTRUSTED')
    inserted=[]
    try:
        # Both old and new artifacts are verified before index publication.
        for rel,data in files.items():
            path=root/rel
            require(path.is_relative_to(root),'NEW_ARTIFACT_OUTSIDE_REGISTRY')
            for p in path.parents:
                if p==root.parent:break
                require(not p.is_symlink(),'REGISTRY_ANCESTOR_SYMLINK')
            if not path.parent.exists():path.parent.mkdir(parents=True,mode=0o700)
            require(path.parent.stat().st_uid==0 and path.parent.stat().st_mode&0o077==0,
                    'REGISTRY_TARGET_DIRECTORY_UNTRUSTED')
            require(not path.exists() and not path.is_symlink(),'NEW_REGISTRY_ARTIFACT_ALREADY_EXISTS')
            fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
            inserted.append((path,data))
        require(protected(root/'index.json')==before,'REGISTRY_INDEX_CONCURRENTLY_CHANGED')
        # Run original unmodified registry validator against a disposable copy
        # of the index; never publish bytes that it would reject.
        testing=root/'.receipt-upgrade-candidate-index.json'
        require(not testing.exists(),'REGISTRY_TEST_CANDIDATE_COLLISION')
        fd=os.open(testing,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as f:f.write(mod.canonical(proposed)+b'\n')
        try:
            original_module=mod.Registry.__init__
            # Copy temporary index into an otherwise identical root would be
            # unnecessary; validate new provider directly using original rules.
            manifest=json.loads(files[MANIFEST])
            mod.Registry(root).validate_manifest(manifest)
            require(mod.sha(files[MANIFEST])==proposed['providers'][-1]['sha256'],
                    'RECEIPT_PROVIDER_MANIFEST_DIGEST_CHANGED')
        finally:testing.unlink()
        if not BACKUP.exists():
            fd=os.open(BACKUP,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            with os.fdopen(fd,'wb') as f:f.write(before);f.flush();os.fsync(f.fileno())
        else:require(protected(BACKUP)==before,'REGISTRY_BACKUP_CONFLICT')
        require(protected(root/'index.json')==before,'REGISTRY_INDEX_SECOND_RACE')
        atomic(root/'index.json',mod.canonical(proposed)+b'\n')
        enrolled=mod.Registry(root)
        require(NEW_OP in enrolled.manifests and before==protected(BACKUP),
                'FIRST_PARTY_RECOVERY_ENROLLMENT_READBACK_MISMATCH')
    except Exception:
        # Don't overwrite an index changed by someone else: fail closed.
        if (root/'index.json').exists() and sha(protected(root/'index.json'))==sha(mod.canonical(proposed)+b'\n'):
            atomic(root/'index.json',before)
        for path,data in reversed(inserted):
            if path.is_file() and not path.is_symlink() and path.read_bytes()==data:path.unlink()
        raise
    result.update(state='FIRST_PARTY_RECEIPT_PROVIDER_SOURCE_ENROLLED',
                  index_after_sha256=sha(protected(root/'index.json')),
                  files_added=len(inserted))
    return result

if __name__=='__main__':
    ap=argparse.ArgumentParser();group=ap.add_mutually_exclusive_group(required=True)
    group.add_argument('--check',action='store_true');group.add_argument('--install',action='store_true')
    args=ap.parse_args()
    try:
        if args.install:
            with open(LOCK,'a+') as fp:
                fcntl.flock(fp,fcntl.LOCK_EX|fcntl.LOCK_NB)
                value=install(apply=True)
        else:value=install()
        print(json.dumps(value,sort_keys=True))
    except (Denied,ValueError,TypeError,KeyError,OSError,RuntimeError,subprocess.SubprocessError) as e:
        print(json.dumps({'state':'DEFER','reason':str(e) if isinstance(e,Denied) else 'FIRST_PARTY_INSTALL_RECONCILE_REQUIRED',
                          'job_created':False,'grant_issued':False,'original_313_certified':False},sort_keys=True))
        raise SystemExit(2)
