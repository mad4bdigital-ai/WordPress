#!/usr/bin/env python3
"""First-party provider v4 dependency-safe CANDIDATE generator. No enrollment.

Stages exact pinned source bytes in a private isolated Registry copy, re-pins
existing sibling dependencies, validates every registered Provider and preserves
all trust keys. Cannot sign Jobs/Grants or change the installed Registry.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess

OPERATION='company.code.release'

def sha(data): return hashlib.sha256(data).hexdigest()
def canon(obj): return json.dumps(obj,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()
def demand(condition,reason):
    if not condition: raise RuntimeError(reason)
def safe_path(root,rel):
    p=Path(rel)
    demand(not p.is_absolute() and '..' not in p.parts and '\\' not in rel and p.as_posix()==rel,'UNSAFE_MANIFEST_PATH:'+rel)
    return root/p
def regular(path):
    st=path.lstat()
    demand(stat.S_ISREG(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022,'SOURCE_NOT_ROOT_TRUSTED:'+str(path))
    return path.read_bytes()
def components(manifest):
    result=[]
    for key in ('handler','verifier','evidence','preclaim','finalizer'):
        if key in manifest:result.append((key,manifest[key]))
    for x in manifest.get('dependencies',[]):result.append(('dependency',x))
    return result

def generate(installed,source_ops,candidate):
    installed=installed.resolve(strict=True);source_ops=source_ops.resolve(strict=True)
    demand(installed!=candidate and source_ops!=candidate,'CANDIDATE_MUST_BE_SEPARATE')
    root_reg=installed/'registry.py'
    spec=importlib.util.spec_from_file_location('pinned_original_registry_checker',root_reg)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    live=module.Registry(installed)
    index=json.loads(regular(installed/'index.json'))
    before=sha(regular(installed/'index.json'))
    original=live.resolve(OPERATION)
    mpath='dynamic-operation-registry-v1/providers/company.code.release/manifest.json'
    desired_bytes=regular(safe_path(source_ops,mpath));desired=json.loads(desired_bytes)
    demand(original['version']==3 and desired['version']==4,'EXACT_V3_TO_V4_ONLY')
    for key in ('operation_id','provider','source_family','plan_contract','target_scope','resource_kind','job_type','evidence_schema','command'):
        demand(original.get(key)==desired.get(key),'PROVIDER_IDENTITY_CHANGED:'+key)
    indexed={row['manifest']:dict(row) for row in index['providers']}
    demand(mpath in indexed and indexed[mpath]['sha256']==sha(regular(installed/mpath)),'INSTALLED_SOURCE_MANIFEST_DRIFT')
    blobs={};before_blobs={};source_changes=[]
    for kind,row in components(desired):
        path=row['path'];new=regular(safe_path(source_ops,path));d=sha(new)
        demand(d==row['sha256'],'SOURCE_V4_COMPONENT_UNPINNED:'+path)
        oldpath=safe_path(installed,path)
        prev=regular(oldpath) if oldpath.is_file() else None
        if prev!=new:
            blobs[path]=new
            before_blobs[path]=sha(prev) if prev else None
            source_changes.append({'path':path,'sha_before':sha(prev) if prev else None,'sha_after':d,'kind':kind})
    blobs[mpath]=desired_bytes;before_blobs[mpath]=sha(regular(installed/mpath))
    changes={r['path']:r['sha_after'] for r in source_changes}
    touched={OPERATION}
    sibling_changes=[]
    for op,manifest in sorted(live.manifests.items()):
        if op==OPERATION:continue
        otherpath=next((p for p in indexed if p.endswith('/'+op+'/manifest.json')),None)
        demand(otherpath is not None,'SIBLING_MANIFEST_NOT_INDEXED:'+op)
        revised=json.loads(json.dumps(manifest))
        for kind,entry in components(revised):
            path=entry['path']
            if path not in changes:continue
            demand(kind=='dependency','SHARED_NONDEPENDENCY_NEEDS_NEW_PROVIDER_VERSION:'+op+':'+kind)
            old=entry['sha256'];entry['sha256']=changes[path]
            sibling_changes.append({'operation':op,'path':path,'sha_before':old,'sha_after':changes[path]})
            touched.add(op)
        if op in touched:
            blobs[otherpath]=canon(revised)+b'\n';before_blobs[otherpath]=sha(regular(installed/otherpath))
    demand(len(touched)>=2,'SHARED_PROVIDER_CONTRACT_EXPECTED')
    demand(not candidate.exists(),'CANDIDATE_EXISTS_DONT_OVERWRITE')
    demand(candidate.parent.exists() and candidate.parent.stat().st_uid==0 and not candidate.parent.stat().st_mode&0o077,'CANDIDATE_PARENT_NOT_PRIVATE')
    shutil.copytree(installed,candidate,symlinks=True)
    try:
        for rel,data in blobs.items():
            dest=safe_path(candidate,rel);dest.parent.mkdir(parents=True,exist_ok=True)
            demand(not dest.is_symlink(),'CANDIDATE_SYMLINK_UNEXPECTED:'+rel)
            dest.write_bytes(data);dest.chmod(0o600)
            if rel in indexed:indexed[rel]['sha256']=sha(data)
        index['providers']=[indexed[row['manifest']] for row in index['providers']]
        (candidate/'index.json').write_bytes(canon(index)+b'\n')
        (candidate/'index.json').chmod(0o600)
        staged=module.Registry(candidate)
        demand(set(staged.manifests)==set(live.manifests),'PROVIDER_SET_DRIFT')
        demand(staged.resolve(OPERATION)['version']==4,'V4_NOT_ENROLLED_IN_CANDIDATE')
        demand(staged.independent_verifier_public_key==live.independent_verifier_public_key,'ORIGINAL313_KEY_CHANGED')
        demand(staged.host_resource_312_public_key==live.host_resource_312_public_key,'HOST312_KEY_CHANGED')
        for op in live.manifests:
            if op==OPERATION:continue
            original_m=live.manifests[op];new_m=staged.manifests[op]
            if op not in touched: demand(original_m==new_m,'UNRELATED_PROVIDER_DRIFT:'+op)
            else:
                expected=json.loads(json.dumps(original_m))
                for _,row in components(expected):
                    if row['path'] in changes: row['sha256']=changes[row['path']]
                demand(expected==new_m,'RELATED_PROVIDER_SEMANTIC_CHANGED:'+op)
        demand(sha(regular(installed/'index.json'))==before,'INSTALLED_INDEX_CHANGED_DURING_CANDIDATE')
    except BaseException:
        shutil.rmtree(candidate)
        raise
    return {'contract':'mad4b.provider-v4-pinned-dependency-reconciliation.v1',
       'state':'CANDIDATE_REGISTRY_VALIDATED_NOT_ENROLLED','before_index_sha256':before,
       'candidate_index_sha256':sha(regular(candidate/'index.json')),
       'operations_in_candidate':sorted(staged.manifests),
       'operations_changed':sorted(touched), 'source_files':source_changes,
       'sibling_dependency_repin':sibling_changes,
       'candidate_path':str(candidate),
       'original_313_key_unchanged':True,'original_host312_key_unchanged':True,
       'first_party_signed_release_required':True,'grant_issued':False,
       'production_policy_modified':False,'original_registry_modified':False,
       'company_site_modified':False,'approval_issued':False}

if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--installed',type=Path,required=True)
    p.add_argument('--source-ops',type=Path,required=True)
    p.add_argument('--candidate',type=Path,required=True)
    p.add_argument('--report',type=Path,required=True)
    a=p.parse_args()
    try:
        result=generate(a.installed,a.source_ops,a.candidate)
        a.report.write_bytes(json.dumps(result,indent=2,ensure_ascii=False).encode())
        os.chmod(a.report,0o600)
        print(json.dumps({'state':result['state'],'operations_changed':result['operations_changed'],
                          'files_changed':len(result['source_files']),
                          'sibling_repins':len(result['sibling_dependency_repin']),
                          'original_registry_modified':False,'grant_issued':False}))
    except Exception as exc:
        print(json.dumps({'state':'HOLD','reason':str(exc)[:260],'original_registry_modified':False,'grant_issued':False}))
        raise SystemExit(2)
