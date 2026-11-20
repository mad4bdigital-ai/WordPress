#!/usr/bin/env python3
"""Source-pinned, rollbackable Original 313 provider release parity.

Only replaces historic inactive MU and plugin-install *release copies*
with the reviewed active first-party worker when all original digest tables
are preserved. Never modifies the active worker, any WordPress code, company
registry, authorization, signed claim, grant, plan, or executed job.
"""
from __future__ import annotations
import argparse,ast,datetime,fcntl,hashlib,importlib.util,json,os,stat,subprocess,tempfile
from pathlib import Path

BASE=Path('/opt/mad4b-company-transport/native-313-independent')
ACTIVE=BASE/'claimed-job-worker.py'
SOURCE=Path('/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/candidates/claimed-job-worker-postmeta-v6-code-release-merged.py')
TARGETS={
 'mu':(BASE/'mu-deploy-v1/claimed-job-worker.release.py','886b399be7905fd083e3df167f62769a1fe99cbc679a1943e51214e130086b6b'),
 'plugin':(BASE/'plugin-install-v1/claimed-job-worker.release.py','1741014be1d88c322218770d64818ecd62c56757d096af8d89b6b1ebef981b00'),
}
NEW='df7394fd2d9ecdae81fcf689af21a10016f91abe99450a454a90e1b09af162a1'
BK=Path('/opt/mad4b-company-provisioning/backups/consolidated-313-worker-20261110')
LOG=Path('/opt/mad4b-company-provisioning/reports/consolidated-313-worker-20261110.json')
LOCK=Path('/run/lock/mad4b-original313-consolidated-worker-release.lock')

class Denied(RuntimeError): pass

def dig(path): return hashlib.sha256(path.read_bytes()).hexdigest()

def exact(path,maxbytes=180000):
    if path.is_symlink():raise Denied('SOURCE_SYMLINK_UNTRUSTED')
    st=path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077
        or not 1000<st.st_size<maxbytes):
        raise Denied('SOURCE_NOT_ROOT_PRIVATE')
    return dig(path)

def source_verified(path):
    if path.is_symlink():raise Denied('REVIEWED_SOURCE_SYMLINK_UNTRUSTED')
    st=path.stat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o022:
        raise Denied('REVIEWED_SOURCE_UNTRUSTED')
    return dig(path)

def module(path):
    spec=importlib.util.spec_from_file_location('original_313_release_parity_'+path.parent.name,path)
    if not spec or not spec.loader:raise Denied('SOURCE_NOT_IMPORTABLE')
    out=importlib.util.module_from_spec(spec);spec.loader.exec_module(out)
    return out

def inventory(path):
    tree=ast.parse(path.read_text())
    funcs={x.name for x in tree.body if isinstance(x,(ast.FunctionDef,ast.AsyncFunctionDef))}
    assignments=set(); imports=set()
    for node in tree.body:
        if isinstance(node,ast.Assign):
            for target in node.targets:
                assignments.update(x.id for x in ast.walk(target) if isinstance(x,ast.Name))
        elif isinstance(node,ast.Import):
            imports.update(x.name for x in node.names)
        elif isinstance(node,ast.ImportFrom): imports.add(node.module or '')
    return funcs,assignments,imports

def readiness():
    if exact(ACTIVE)!=NEW or source_verified(SOURCE)!=NEW:raise Denied('REVIEWED_SOURCE_NOT_ACTIVE')
    guard_path=Path('/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts/guard_original_313_worker_preclaim_release.py')
    spec=importlib.util.spec_from_file_location('verified_original313_source_guard',guard_path)
    g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)
    if g.guard.EXPECTED_NEW!=NEW or g.guard.VETTED!=SOURCE:
        raise Denied('SOURCE_GUARD_NOT_PINNED')
    src_inventory=inventory(SOURCE)
    current=module(SOURCE).operation_digests()
    if not isinstance(current,dict) or 'dynamic:company.code.release' not in current.values():
        raise Denied('DYNAMIC_REGISTRY_SOURCE_NOT_ENROLLED')
    states={}
    for label,(path,previous) in TARGETS.items():
        digest=exact(path)
        if digest not in (NEW,previous):raise Denied('UNKNOWN_'+label.upper()+'_RELEASE_HASH')
        previous_inventory=inventory(path)
        if not all(old.issubset(new) for old,new in zip(previous_inventory,src_inventory)):
            raise Denied('CURRENT_SOURCE_MISSING_LEGACY_OPERATION')
        ops=module(path).operation_digests()
        if not isinstance(ops,dict) or any(current.get(k)!=v for k,v in ops.items()):
            raise Denied('LEGACY_SIGNED_DIGEST_TABLE_NOT_PRESERVED')
        states[label]={'before_sha256':digest,'target':str(path),
                       'needs_promotion':digest!=NEW,
                       'original_operations':sorted(set(ops.values()))}
    return {'state':'ALREADY_CONSOLIDATED' if all(not row['needs_promotion'] for row in states.values())
                    else 'REVIEWED_CONSOLIDATED_RELEASE_READY',
            'contract':'mad4b.original313.consolidated-provider-release.v1',
            'source_sha256':NEW,'source_owner_guard_pinned':True,
            'active_worker_modified':False,'production_mutated':False,
            'claims_created':False,'grants_created':False,'jobs_created':False,
            'read_only':True,'providers':states}

def atomic_bytes(dest,raw,mode):
    fd,temp=tempfile.mkstemp(dir=dest.parent,prefix='.original313-release-')
    try:
        with os.fdopen(fd,'wb') as out:
            os.fchmod(out.fileno(),mode)
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.replace(temp,dest)
    finally:
        if os.path.lexists(temp):os.unlink(temp)

def atomic_json(dest,row):
    raw=(json.dumps(row,sort_keys=True,indent=2)+'\n').encode()
    atomic_bytes(dest,raw,0o600)

def commit():
    if os.geteuid()!=0:raise Denied('ROOT_ONLY')
    running=subprocess.run(['systemctl','is-active','--quiet','mad4b-company-original-313-worker.service'],check=False)
    if running.returncode==0:raise Denied('ORIGINAL_313_WORKER_CURRENTLY_RUNNING')
    pre=readiness()
    if pre['state']=='ALREADY_CONSOLIDATED':return pre
    if BK.exists() and (BK.is_symlink() or BK.stat().st_uid!=0 or BK.stat().st_mode&0o077):
        raise Denied('BACKUP_PATH_UNTRUSTED')
    BK.mkdir(parents=True,exist_ok=True,mode=0o700)
    placed=[]
    try:
        for label,(path,previous) in TARGETS.items():
            old=pre['providers'][label]['before_sha256']
            if old==NEW:continue
            if exact(ACTIVE)!=NEW or source_verified(SOURCE)!=NEW or exact(path)!=old:
                raise Denied('ORIGINAL_313_SOURCE_CONCURRENTLY_CHANGED')
            snap=BK/(label+'-'+previous+'.original.py')
            if snap.exists():
                if exact(snap)!=old:raise Denied('ORIGINAL_313_BACKUP_SHA_MISMATCH')
            else:
                fd=os.open(snap,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                with os.fdopen(fd,'wb') as out:
                    out.write(path.read_bytes());out.flush();os.fsync(out.fileno())
            atomic_bytes(path,SOURCE.read_bytes(),stat.S_IMODE(path.stat().st_mode))
            placed.append((path,snap,old))
            if exact(path)!=NEW:raise Denied('ORIGINAL_313_POST_WRITE_SHA_MISMATCH')
        accepted=readiness()
        if accepted['state']!='ALREADY_CONSOLIDATED':raise Denied('RELEASE_SOURCE_READBACK_NOT_CONSOLIDATED')
    except Exception:
        for path,snapshot,old in reversed(placed):
            if exact(path)==NEW:
                atomic_bytes(path,snapshot.read_bytes(),stat.S_IMODE(path.stat().st_mode))
        raise
    accepted.update(state='VERIFIED_CONSOLIDATED_RELEASE_INSTALLED',read_only=False,
                    observed_utc=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                    release_copies_promoted=len(placed),
                    original_313_certified_by_this_check=False)
    atomic_json(LOG,accepted)
    return accepted

if __name__=='__main__':
    cli=argparse.ArgumentParser();cli.add_argument('--apply',action='store_true');args=cli.parse_args()
    try:
        if args.apply:
            fd=os.open(LOCK,os.O_WRONLY|os.O_CREAT,0o600)
            with os.fdopen(fd,'w') as lock:
                fcntl.flock(lock,fcntl.LOCK_EX)
                result=commit()
        else:result=readiness()
        print(json.dumps(result,sort_keys=True))
    except (Denied,OSError,ValueError,TypeError,RuntimeError) as err:
        print(json.dumps({'state':'DEFER','reason':str(err),'production_mutated':False,
                          'new_grant_issued':False,'new_job_issued':False}))
        raise SystemExit(2)
