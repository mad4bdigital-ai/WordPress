#!/usr/bin/env python3
"""Git-tree-digest fidelity for opt-in, source-only historical branch archives."""
import hashlib,json,re,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'docs/release-coverage/four-local-branch-source-overlay-20261111.json'
class Blocked(ValueError):pass
def git(*args):
    p=subprocess.run(['git','-C',str(ROOT),*args],capture_output=True,text=True,timeout=20)
    if p.returncode:raise Blocked('GIT_PROVENANCE_UNAVAILABLE')
    return p.stdout.strip()
def verify(data):
    if data.get('contract')!='mad4b.local-four-branch-source-overlay.v1':
        raise Blocked('UNKNOWN_BUNDLE')
    if (data.get('source_only') is not True or data.get('release_authorized') is not False
        or data.get('systemd_installed') is not False or data.get('production_updated') is not False
        or data.get('original313_certified') is not False):
        raise Blocked('FALSE_EXECUTION_PROOF')
    pins=data.get('source_pins')
    if not isinstance(pins,dict) or len(pins)!=4:raise Blocked('UNBOUNDED_BRANCH_PINS')
    for branch,sha in pins.items():
        if not isinstance(branch,str) or not isinstance(sha,str) or not re.fullmatch('[a-f0-9]{40}',sha):
            raise Blocked('INVALID_BRANCH_SHA')
        if git('rev-parse',branch)!=sha:raise Blocked('BRANCH_MOVED')
    entries=data.get('files')
    if not isinstance(entries,list) or len(entries)!=103:raise Blocked('FILE_COUNT_DRIFT')
    seen=set();count=0;duplicated=0
    for item in entries:
        if not isinstance(item,dict) or set(item)!={'path','source_branches','git_blob_oid','sha256','bytes','git_mode'}:
            raise Blocked('INVALID_SOURCE_ENTRY')
        rel=item['path']
        if (not isinstance(rel,str) or not rel or rel.startswith(('/','-')) or chr(92) in rel
            or any(part in ('.','..','') for part in rel.split('/')) or
            not(rel.startswith('ops/mad4b-') or rel.startswith('.github/workflows/mad4b-'))):
            raise Blocked('SOURCE_PATH_NOT_ALLOWED')
        if rel in seen:raise Blocked('DUPLICATED_SOURCE_PATH')
        seen.add(rel)
        refs=item['source_branches']
        if not isinstance(refs,list) or not 1<=len(refs)<=2 or len(set(refs))!=len(refs) or any(b not in pins for b in refs):
            raise Blocked('INVALID_BRANCH_REFERENCES')
        if not re.fullmatch('[a-f0-9]{40}',item['git_blob_oid']) or not re.fullmatch('[a-f0-9]{64}',item['sha256']):
            raise Blocked('INVALID_SOURCE_DIGEST')
        if not isinstance(item['bytes'],int) or not 0<item['bytes']<=1000000 or item['git_mode'] not in ('100644','100755'):
            raise Blocked('INVALID_SOURCE_METADATA')
        file=ROOT/rel
        if not file.is_file() or file.is_symlink() or file.stat().st_size!=item['bytes']:
            raise Blocked('SOURCE_FILE_ABSENT_OR_UNTRUSTED')
        raw=file.read_bytes()
        if hashlib.sha256(raw).hexdigest()!=item['sha256']:
            raise Blocked('SOURCE_BYTES_DRIFT')
        oid=hashlib.sha1((f"blob {len(raw)}\0").encode()+raw).hexdigest()
        if oid!=item['git_blob_oid']:raise Blocked('SOURCE_OBJECT_DRIFT')
        for branch in refs:
            if git('rev-parse',pins[branch]+':'+rel)!=oid:
                raise Blocked('BRANCH_SOURCE_CHANGED')
        count+=len(refs)
        duplicated+=len(refs)==2
    if count!=122 or duplicated!=19:
        raise Blocked('SHARED_BLOB_COVERAGE_CHANGED')
    return {'contract':data['contract'],'state':'SOURCE_BLOBS_MATCH_FOUR_PINNED_BRANCH_HEADS',
      'unique_files_verified':len(entries),'branch_file_references_verified':count,
      'duplicate_shared_blobs_verified':duplicated,
      'runtime_mutated':False,'release_approved':False,'original313_receipt_verified':False}
if __name__=='__main__':
    try:
        print(json.dumps(verify(json.loads(MANIFEST.read_text())),sort_keys=True))
    except (ValueError,OSError,subprocess.TimeoutExpired) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_approved':False}))
        raise SystemExit(2)
