#!/usr/bin/env python3
"""Strict additive live Git source parity, never an activation or release grant."""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'docs/release-coverage/mad4b-first-party-plugin-additive-source-20261111.json'
class Blocked(ValueError):pass

def verify(data,root=ROOT,live_root=None):
    if (not isinstance(data,dict)
        or data.get('contract')!='mad4b.first-party-plugin-additive-source-only.v1'
        or data.get('plugin_roots_examined')!=32
        or data.get('source_only') is not True
        or data.get('plugin_activation_performed') is not False
        or data.get('wp_cli_or_db_mutation_performed') is not False
        or data.get('company_deployment_authorized') is not False
        or data.get('global_release_certified') is not False
        or data.get('company_specific_content_copied') is not False):
        raise Blocked('INVALID_RELEASE_PROVENANCE_CLAIM')
    files=data.get('files')
    if not isinstance(files,list) or len(files)!=238:raise Blocked('FILE_COUNT_MISMATCH')
    conflicts=data.get('preexisting_semantic_conflicts')
    if (not isinstance(conflicts,dict)
        or data.get('preexisting_semantic_conflict_count')!=69
        or sum(len(x) for x in conflicts.values())!=69):
        raise Blocked('CONFLICT_REGISTER_CHANGED')
    expected_extensions={'.php','.js','.css'}
    seen=set()
    by_type={x:0 for x in expected_extensions}
    for item in files:
        if not isinstance(item,dict) or set(item)!={'path','bytes','sha256','git_blob_sha1','extension','plugin'}:
            raise Blocked('INVALID_RECORD')
        rel=item['path']
        if (not isinstance(rel,str) or not rel.startswith('wp-content/plugins/mad4b-')
            or rel.startswith('/') or '\\' in rel
            or any(t in ('','..','.') for t in rel.split('/'))
            or len(rel.split('/'))<4 or rel in seen):
            raise Blocked('UNTRUSTED_SOURCE_PATH')
        seen.add(rel)
        ext=Path(rel).suffix.lower()
        if ext not in expected_extensions or item.get('extension')!=ext or item.get('plugin')!=Path(rel).parts[2]:
            raise Blocked('PLUGIN_SOURCE_TYPE_DRIFT')
        by_type[ext]+=1
        if (not isinstance(item['bytes'],int) or not 0<item['bytes']<1000000
            or not isinstance(item['sha256'],str)
            or not re.fullmatch('[a-f0-9]{64}',item['sha256'])
            or not isinstance(item['git_blob_sha1'],str)
            or not re.fullmatch('[a-f0-9]{40}',item['git_blob_sha1'])):
            raise Blocked('INVALID_DIGEST_OR_SIZE')
        if rel in {p for group in conflicts.values() for p in group}:
            raise Blocked('PREEXISTING_CONFLICT_WAS_OVERWRITTEN')
        for source_root in ((root,live_root) if live_root is not None else (root,)):
            path=Path(source_root)/rel
            if not path.is_file() or path.is_symlink() or path.stat().st_size!=item['bytes']:
                raise Blocked('ADDITIVE_PLUGIN_FILE_MISSING_OR_CHANGED')
            raw=path.read_bytes()
            sha=hashlib.sha256(raw).hexdigest()
            blob=hashlib.sha1((f'blob {len(raw)}\0').encode()+raw).hexdigest()
            if sha!=item['sha256'] or blob!=item['git_blob_sha1']:
                raise Blocked('ADDITIVE_PLUGIN_SHA_DRIFT')
    if by_type!={'.php':172,'.js':31,'.css':35}:
        raise Blocked('ADDITIVE_PLUGIN_FILE_TYPES_CHANGED')
    return {'state':'238_FIRST_PARTY_PLUGIN_CODE_FILES_SOURCE_MATCH__HOLD',
      'file_count':len(files),'type_counts':by_type,
      'preexisting_semantic_conflicts_kept':69,
      'live_readback':live_root is not None,
      'plugin_activation':False,'release_authorized':False}
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--live-root',type=Path)
    args=parser.parse_args()
    try:
        print(json.dumps(verify(json.loads(MANIFEST.read_text()),live_root=args.live_root),sort_keys=True,indent=2))
    except (OSError,ValueError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
