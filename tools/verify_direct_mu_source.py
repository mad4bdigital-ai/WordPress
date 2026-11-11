#!/usr/bin/env python3
"""Read-only source provenance; direct MU PHP is executable if deployed, so HOLD."""
from pathlib import Path
import hashlib,json,re,argparse
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/direct-mu-additive-source-20261111.json'
PREFIX='wp-content/mu-plugins/'
EXISTING_DIFFERENT={
    'mad4b-media-composite-integrity.php',
    'mad4b-tour-media-reconcile.php',
    'mad4b-unified-media-apply.php',
}
class Blocked(ValueError):
    pass

def hashblob(content):
    return hashlib.sha1((f'blob {len(content)}\0').encode()+content).hexdigest()

def verify(data,root=ROOT,live_root=None):
    if (not isinstance(data,dict) or data.get('contract')!='mad4b.local-direct-mu-sources-source-only.v1'
        or data.get('source_only') is not True
        or data.get('automatic_mu_activation_authorized') is not False
        or data.get('site_modified') is not False
        or data.get('company_deployment_authorized') is not False
        or data.get('global_release_approved') is not False
        or data.get('source_relative_root')!='wp-content/mu-plugins'):
        raise Blocked('INVALID_RELEASE_AUTHORITY_CLAIM')
    if set(data.get('preexisting_different_files') or [])!=EXISTING_DIFFERENT:
        raise Blocked('SOURCE_CONFLICT_SCOPE_CHANGED')
    overlays=data.get('approved_candidate_overlays')
    chosen=PREFIX+'mad4b-tour-optional-sections-lazy.php'
    if not isinstance(overlays,list) or len(overlays)!=1 or not isinstance(overlays[0],dict):
        raise Blocked('NATIVE_MU_SEMANTIC_OVERLAY_MISSING')
    o=overlays[0]
    expected={'path','live_sha256','candidate_sha256','candidate_blob_sha1',
              'policy','offline_php_cases_passed','financial_authority_granted','deploy_authorized'}
    if (set(o)!=expected or o.get('path')!=chosen
       or o.get('policy')!='native_signed_jetengine_query_verified_before_optional_transport_presentation'
       or o.get('offline_php_cases_passed')!=25
       or o.get('financial_authority_granted') is not False
       or o.get('deploy_authorized') is not False):
        raise Blocked('UNAPPROVED_NATIVE_MU_SEMANTIC_OVERLAY')
    for name,n in (('live_sha256',64),('candidate_sha256',64),('candidate_blob_sha1',40)):
        if not isinstance(o.get(name),str) or not re.fullmatch('[a-f0-9]{'+str(n)+'}',o[name]):
            raise Blocked('UNVERIFIED_NATIVE_MU_SEMANTIC_DIGEST')
    rows=data.get('files')
    if not isinstance(rows,list) or len(rows)!=162:
        raise Blocked('UNREVIEWED_DIRECT_MU_COUNT')
    seen=set()
    for row in rows:
        if not isinstance(row,dict) or set(row)!={'path','sha256','git_blob_sha1','bytes','file_type'}:
            raise Blocked('INVALID_SOURCE_RECORD')
        path=row['path']
        if (not isinstance(path,str) or not path.startswith(PREFIX)
            or '/' in path[len(PREFIX):] or path.startswith('/')
            or '\\' in path or '..' in path.split('/')
            or path in seen or row.get('file_type')!='.php'):
            raise Blocked('UNTRUSTED_DIRECT_MU_PATH')
        seen.add(path)
        if (not isinstance(row['bytes'],int) or not 0<row['bytes']<200000
            or not isinstance(row['sha256'],str) or not re.fullmatch('[a-f0-9]{64}',row['sha256'])
            or not isinstance(row['git_blob_sha1'],str) or not re.fullmatch('[a-f0-9]{40}',row['git_blob_sha1'])):
            raise Blocked('INVALID_DIGEST')
        for site in ((root,live_root) if live_root is not None else (root,)):
            file=Path(site)/path
            candidate_override=path==chosen and Path(site)==Path(root)
            if (not file.is_file() or file.is_symlink()
                or (candidate_override and not 0<file.stat().st_size<200000)
                or (not candidate_override and file.stat().st_size!=row['bytes'])):
                raise Blocked('DIRECT_MU_SOURCE_CHANGED_OR_MISSING')
            content=file.read_bytes()
            if path==chosen and Path(site)==Path(root):
                if (row['sha256']!=o['live_sha256']
                  or hashlib.sha256(content).hexdigest()!=o['candidate_sha256']
                  or hashblob(content)!=o['candidate_blob_sha1']):
                    raise Blocked('NATIVE_MU_PATCH_OUTSIDE_REVIEWED_SOURCE')
            elif hashlib.sha256(content).hexdigest()!=row['sha256'] or hashblob(content)!=row['git_blob_sha1']:
                raise Blocked('DIRECT_MU_SHA_DRIFT')
    return {'state':'DIRECT_MU_EXACT_EXCEPT_ONE_NATIVE_SIGNATURE_GUARD__HOLD',
            'source_php_files_verified':len(seen),
            'semantic_overlays_verified':1,
            'existing_conflicts_held':len(EXISTING_DIFFERENT),
            'source_only':True,'automatic_mu_activation_authorized':False,
            'release_authorized':False}
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--live-root',type=Path)
    args=parser.parse_args()
    try:
        print(json.dumps(verify(json.loads(DOC.read_text()),live_root=args.live_root),indent=2,sort_keys=True))
    except (OSError,ValueError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
