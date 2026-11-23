#!/usr/bin/env python3
"""Build a read-only, NONAUTHORIZING file manifest from a pinned local Git commit.

This file is NOT the signed packaged MAD4B-BUILD-PROVENANCE.json. A host
original signer, post-build review, and site-specific release admission remain
mandatory. Do not write any derived manifest into the input Git tree.
"""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile

SHA = re.compile(r'^[a-f0-9]{40}$')
PREFIX = 'wp-content/plugins/mad4b-site-control-plane/'
EXCLUDED_DIRS = ('tests/', 'docs/', 'tools/', '.github/', 'node_modules/')
EXCLUDED_SUFFIXES = ('.md', '.rst', '.txt', '.pyc', '.log')

def run(*args, timeout=60):
    result = subprocess.run(args, check=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout,
                            env={**os.environ, 'GIT_NO_LAZY_FETCH':'1', 'GIT_TERMINAL_PROMPT':'0'})
    return result.stdout

def build(repo: Path, commit: str) -> dict:
    if not SHA.fullmatch(commit): raise ValueError('exact_sha40_required')
    repo = repo.resolve(strict=True)
    if not repo.is_dir(): raise ValueError('repo_not_directory')
    actual = run('git', '-C', str(repo), 'rev-parse', '--verify', commit+'^{commit}', timeout=10).decode().strip()
    if actual != commit: raise ValueError('source_sha_changed')
    raw = run('git', '-C', str(repo), 'archive', '--format=tar', commit,
              'wp-content/plugins/mad4b-site-control-plane', timeout=60)
    if len(raw) > 268435456 + 33554432:raise ValueError('archive_budget_exceeded')
    entries=[];bytes_total=0;seen=set()
    with tarfile.open(fileobj=io.BytesIO(raw), mode='r:') as tar:
        for info in tar:
            if info.isdir(): continue
            if not info.isfile():raise ValueError('nonregular_source_file')
            if not info.name.startswith(PREFIX):raise ValueError('plugin_path_escape')
            rel=info.name[len(PREFIX):]
            if not rel or rel in seen or '..' in PurePosixPath(rel).parts:raise ValueError('duplicate_or_invalid_path')
            seen.add(rel)
            if rel == 'MAD4B-BUILD-PROVENANCE.json' or rel.startswith(EXCLUDED_DIRS) or rel.endswith(EXCLUDED_SUFFIXES):continue
            if info.size<0 or info.size>268435456:raise ValueError('file_budget_exceeded')
            body=tar.extractfile(info).read()
            if len(body)!=info.size:raise ValueError('file_truncated')
            bytes_total+=len(body)
            if bytes_total>268435456 or len(entries)>=6000:raise ValueError('package_budget_exceeded')
            entries.append({'path':rel,'bytes':len(body),'sha256':hashlib.sha256(body).hexdigest()})
    entries.sort(key=lambda e:e['path'])
    if not entries or not any(e['path']=='mad4b-site-control-plane.php' for e in entries):raise ValueError('plugin_entrypoint_missing')
    canonical=''.join(f"{e['path']}\0{e['bytes']}\0{e['sha256']}\n" for e in entries).encode()
    return {'contract':'mad4b.local-git-candidate-provenance.v1',
            'source_commit_sha':commit, 'package_files':entries,
            'package_manifest_digest':hashlib.sha256(canonical).hexdigest(),
            'package_file_count':len(entries),'package_bytes_total':bytes_total,
            'certified_release':False,'release_verdict_success':False,
            'release_authorized':False,'authorizing':False,
            'requires_original_signed_post_build_receipt':True}

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--repo',type=Path,required=True)
    ap.add_argument('--sha',required=True);ap.add_argument('--output',type=Path,required=True)
    args=ap.parse_args()
    data=build(args.repo,args.sha)
    output=args.output.resolve(strict=False)
    if output.is_symlink() or output.exists():raise ValueError('output_must_not_preexist')
    if not output.parent.is_dir():raise ValueError('output_parent_missing')
    fd,tmp=tempfile.mkstemp(prefix='.candidate-manifest-',dir=output.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w') as f:json.dump(data,f,ensure_ascii=False,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,output)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    print(json.dumps({'candidate_source_sha':data['source_commit_sha'],
          'files':data['package_file_count'],'manifest_digest':data['package_manifest_digest'],
          'certified_release':False,'source_code_mutated':False,'output':str(output)}))
if __name__=='__main__':main()
