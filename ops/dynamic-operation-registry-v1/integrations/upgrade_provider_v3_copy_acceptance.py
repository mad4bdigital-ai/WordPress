#!/usr/bin/env python3
"""First-party Provider v3 acceptance-contract hotfix, pinned/rollback-safe.

Updates the exact Code Release Python dependency and its root-owned
Manifest + Index pins. No 305/306 Job, Grant, original 313 evidence,
or company content is created/mutated by this installer.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
OPS=ROOT.parent
GIT=OPS.parent
INST=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
BACKUP=Path('/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/provider-v3-copy-acceptance-20261108')
PLAN=Path('/var/lib/mad4b/dynamic-operation-registry/plans/cf3e636de12ac6d10cdc71c3c34c8732bd012c596c863051086ec6502c326787.json')
ENGINE='company-code-release-v1/company_code_release.py'
MANIFEST='dynamic-operation-registry-v1/providers/company.code.release/manifest.json'
OLD_ENGINE='69ce1e888ac2af244aa4289d698fb26714ce97b4b009cf06e02a849465d892b9'
OLD_MANIFEST='9b4239bd848f611779a549d5234e2b62bc33f216602b68325b5278419347ee00'
INDEX='index.json'
sys.path.insert(0,str(ROOT))
import registry
from first_party_install import atomic_private

def required(condition,why):
    if not condition:raise RuntimeError(why)

def digest(blob):
    return hashlib.sha256(blob).hexdigest()

def source_file(relative):
    if relative==ENGINE:return OPS/ENGINE
    if relative==MANIFEST:return OPS/MANIFEST
    raise RuntimeError('UNRECOGNIZED_FIRST_PARTY_SOURCE')

def validate():
    required(os.geteuid()==0,'FIRST_PARTY_ROOT_REQUIRED')
    previous=registry.Registry(INST)
    old=previous.resolve('company.code.release')
    required(old['version']==3,'PINNED_PROVIDER_VERSION_CHANGED')
    src=source_file(ENGINE).read_bytes()
    new_engine=digest(src)
    manifest_source=source_file(MANIFEST).read_bytes()
    manifest_new=json.loads(manifest_source)
    current_engine=(INST/ENGINE).read_bytes()
    current_manifest=(INST/MANIFEST).read_bytes()
    required(digest(current_engine)==OLD_ENGINE,'PINNED_ORIGINAL_RELEASE_ENGINE_CHANGED')
    required(digest(current_manifest)==OLD_MANIFEST,'PINNED_ORIGINAL_PROVIDER_CHANGED')
    required(len(old['dependencies'])==3 and len(manifest_new['dependencies'])==3,
             'PINNED_THREE_DEPENDENCY_CONTRACT_CHANGED')
    required([a['path'] for a in old['dependencies']]==
             [b['path'] for b in manifest_new['dependencies']],
             'PINNED_PROVIDER_DEPENDENCY_ORDER_CHANGED')
    required(old['dependencies'][0]['path']==ENGINE and
             old['dependencies'][0]['sha256']==OLD_ENGINE and
             manifest_new['dependencies'][0]['sha256']==new_engine and
             new_engine!=OLD_ENGINE,'RELEASE_ACCEPTANCE_SOURCE_NOT_PINNED')
    required(old['dependencies'][1:]==manifest_new['dependencies'][1:],
             'UNRELATED_PROVIDER_DEPENDENCY_CHANGED')
    remaining=[k for k in old.keys() if k!='dependencies']
    required(all(old[k]==manifest_new[k] for k in remaining) and
             set(old)==set(manifest_new),
             'PROVIDER_OPERATION_OR_INDEPENDENT_PROOF_CHANGED')
    source_text=src.decode()
    required('[],["post_types"=>["elementor_library"]]);' in source_text and
             'apply_filters("mad4b/content_readiness/external_issues",[]);' not in source_text,
             'TWO_ARGUMENT_CONTENT_READINESS_ACCEPTANCE_REQUIRED')
    idx_path=INST/INDEX
    old_index=json.loads(idx_path.read_text())
    required(len(old_index['providers'])==1 and
             old_index['providers'][0]['manifest']==MANIFEST and
             old_index['providers'][0]['sha256']==OLD_MANIFEST,
             'PINNED_REGISTRY_INDEX_CHANGED')
    # The source provider is v3, with only the release test's API call fixed.
    old_command=previous.command_sha256('company.code.release',
             'cf3e636de12ac6d10cdc71c3c34c8732bd012c596c863051086ec6502c326787')
    commit=subprocess.check_output(['git','-C',str(GIT),'rev-parse','HEAD'],text=True).strip()
    current=subprocess.check_output(['git','-C',str(GIT),'status','--porcelain'])
    required(current.strip()==b'','FIRST_PARTY_HOTFIX_MUST_BE_COMMITTED')
    required(subprocess.check_output(['git','-C',str(GIT),'show','HEAD:ops/'+ENGINE])==src,
             'SOURCE_NOT_MATCHING_IMMUTABLE_GIT_HEAD')
    updated=dict(old_index)
    updated['providers']=[dict(old_index['providers'][0])]
    updated['providers'][0]['sha256']=digest(manifest_source)
    updated['upgraded_source_git_commit']=commit
    blobs={INST/ENGINE:src,INST/MANIFEST:manifest_source,
           INST/INDEX:registry.canonical(updated)+b'\n'}
    for path in blobs:
        statinfo=path.lstat()
        required(statinfo.st_uid==0 and not path.is_symlink() and
                 statinfo.st_mode & 0o077 ==0,'FIRST_PARTY_INSTALLED_FILE_UNTRUSTED')
    return previous,old_command,blobs,new_engine,commit

def run(install):
    previous,command,blobs,new_engine,commit=validate()
    summary={'contract':'mad4b.dynamic-provider-v3-copy-acceptance-hotfix.v1',
             'state':'FIRST_PARTY_SOURCE_PIN_VALIDATED',
             'source_git_commit':commit,
             'engine_sha256':new_engine,
             'command_digest_unchanged':True,
             'original_313_signer_unchanged':True,
             'new_job_created':False,'grant_issued':False,
             'company_production_mutated':False}
    if not install:return summary
    required(not BACKUP.exists(),'PROVIDER_ACCEPTANCE_BACKUP_EXISTS_REFUSE_REPLAY')
    BACKUP.mkdir(parents=True,mode=0o700)
    os.chmod(BACKUP,0o700)
    originals={path:path.read_bytes() for path in blobs}
    for path,raw in originals.items():
        out=BACKUP/path.relative_to(INST)
        out.parent.mkdir(parents=True,exist_ok=True)
        os.chmod(out.parent,0o700)
        atomic_private(out,raw)
    updated=[]
    try:
        for path,blob in blobs.items():
            stage=path.with_name(path.name+'.copy-acceptance-stage')
            atomic_private(stage,blob)
            os.replace(stage,path)
            updated.append(path)
        new=registry.Registry(INST)
        required(new.resolve('company.code.release')['version']==3 and
                 new.resolve('company.code.release')['dependencies'][0]['sha256']==new_engine
                 and new.command_sha256('company.code.release',
                     'cf3e636de12ac6d10cdc71c3c34c8732bd012c596c863051086ec6502c326787')==command and
                 new.independent_verifier_public_key==
                     previous.independent_verifier_public_key,
                 'FIRST_PARTY_PROVIDER_PINNED_READBACK_FAILED')
    except BaseException:
        for path in reversed(updated):
            stage=path.with_name(path.name+'.copy-acceptance-rollback')
            atomic_private(stage,originals[path])
            os.replace(stage,path)
        raise
    summary['state']='FIRST_PARTY_PROVIDER_V3_ACCEPTANCE_CORRECTED'
    summary['backup']=str(BACKUP)
    return summary

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--install',action='store_true')
    args=parser.parse_args()
    print(json.dumps(run(args.install),sort_keys=True))
