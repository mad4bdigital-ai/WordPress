#!/usr/bin/env python3
"""First-party same-version registry repin, never a Broker Grant or code Apply.

Only the company.code.release executor dependency changes. Safeguarded by an
exact installed registry preimage, Git-object provenance, private backup,
atomic index-last publication, and independent readback after install.
"""
from __future__ import annotations
import argparse,fcntl,hashlib,json,os,stat,subprocess,sys,tempfile
from pathlib import Path
OPS=Path(__file__).resolve().parents[2]
REG=OPS/'dynamic-operation-registry-v1'
sys.path.insert(0,str(REG))
import registry
from first_party_install import atomic_private
LIVE=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
LOCK=Path('/run/lock/mad4b-company-release-provider-repin.lock')
REL='company-code-release-v1/company_code_release.py'
MANIFEST='dynamic-operation-registry-v1/providers/company.code.release/manifest.json'


def checked_source():
    gitroot=OPS.parent
    commit=subprocess.check_output(['git','-C',str(gitroot),'rev-parse','HEAD'],text=True).strip()
    if subprocess.check_output(['git','-C',str(gitroot),'status','--porcelain']).strip():
        raise RuntimeError('SOURCE_NOT_COMMITTED')
    for name,path in [(REL,OPS/REL),(MANIFEST,OPS/MANIFEST)]:
        blob=subprocess.check_output(['git','-C',str(gitroot),'show',commit+':ops/'+name])
        if blob!=path.read_bytes():raise RuntimeError('SOURCE_GIT_BLOB_MISMATCH:'+name)
    return commit


def check():
    if os.geteuid()!=0:raise RuntimeError('FIRST_PARTY_ROOT_REQUIRED')
    commit=checked_source()
    active=registry.Registry(LIVE)
    prior=active.resolve('company.code.release')
    wanted=json.loads((OPS/MANIFEST).read_text())
    if prior.get('version')!=wanted.get('version') or prior.get('operation_id')!='company.code.release':
        raise RuntimeError('PROVIDER_IDENTITY_OR_VERSION_CHANGED')
    for key in ('contract','operation_id','provider','version','plan_contract','handler','verifier','evidence_schema','evidence','command'):
        if prior.get(key)!=wanted.get(key):raise RuntimeError('PROVIDER_CONTRACT_CHANGED:'+key)
    priorDeps={x['path']:x['sha256'] for x in prior.get('dependencies',[])}
    newDeps={x['path']:x['sha256'] for x in wanted.get('dependencies',[])}
    if set(priorDeps)!=set(newDeps) or set(x for x in priorDeps if priorDeps[x]!=newDeps[x])!={REL}:
        raise RuntimeError('UNEXPECTED_PROVIDER_DEPENDENCY_DELTA')
    idxFile=LIVE/'index.json'
    oldIdx=registry.checked_json(idxFile,LIVE)
    if len(oldIdx['providers'])!=1 or oldIdx['providers'][0]['manifest']!=MANIFEST:
        raise RuntimeError('REGISTRY_INDEX_SCOPE_CHANGED')
    targetManifest=LIVE/MANIFEST
    oldM=registry.protected_file(targetManifest,LIVE)
    oldCode=registry.protected_file(LIVE/REL,LIVE)
    if hashlib.sha256(oldCode).hexdigest()!=priorDeps[REL]:raise RuntimeError('REGISTERED_CODE_DRIFT')
    newCode=(OPS/REL).read_bytes()
    newManifest=(OPS/MANIFEST).read_bytes()
    if hashlib.sha256(newCode).hexdigest()!=newDeps[REL]:raise RuntimeError('NEW_CODE_NOT_HASH_PINNED')
    nextIdx=json.loads(json.dumps(oldIdx))
    nextIdx['providers'][0]['sha256']=hashlib.sha256(newManifest).hexdigest()
    nextIdx['updated_source_git_commit']=commit
    writes={LIVE/REL:newCode,targetManifest:newManifest,idxFile:registry.canonical(nextIdx)+b'\n'}
    previous={p:registry.protected_file(p,LIVE) for p in writes}
    return commit,prior,writes,previous


def run(install:bool):
    commit,prior,writes,previous=check()
    report={'contract':'mad4b.provider-executor-repin.v1','state':'CHECK_VALID',
            'git_commit':commit,'version_unchanged':prior['version'],
            'operation_id':'company.code.release','files_changed':len(writes),
            'grant_created':False,'wordpress_modified':False,'apply_executed':False}
    if not install:return report
    backup=Path('/opt/mad4b-company-provisioning/reports/media-universal-20261110')/('registry-repin-backup-'+commit[:16])
    if backup.exists():raise RuntimeError('BACKUP_ALREADY_EXISTS')
    backup.mkdir(mode=0o700)
    applied=[]
    try:
        for path,blob in previous.items():
            dest=backup/path.relative_to(LIVE)
            dest.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
            atomic_private(dest,blob)
        for path,data in writes.items():
            if path.name=='index.json':continue
            atomic_private(path,data)
            applied.append(path)
        index=LIVE/'index.json'
        atomic_private(index,writes[index]);applied.append(index)
        reread=registry.Registry(LIVE).resolve('company.code.release')
        if reread!=json.loads((OPS/MANIFEST).read_text()):raise RuntimeError('REGISTRY_READBACK_FAILED')
    except BaseException:
        for path in reversed(applied):atomic_private(path,previous[path])
        raise
    report.update(state='FIRST_PARTY_DEPENDENCY_REPIN_VERIFIED',backup=str(backup))
    return report

if __name__=='__main__':
    p=argparse.ArgumentParser();g=p.add_mutually_exclusive_group(required=True)
    g.add_argument('--check',action='store_true');g.add_argument('--install',action='store_true')
    args=p.parse_args()
    with open(LOCK,'a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print(json.dumps(run(args.install),sort_keys=True))
