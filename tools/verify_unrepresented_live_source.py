#!/usr/bin/env python3
"""Read-only source-fidelity check; no website, grant or release side effects."""
from pathlib import Path
import argparse,hashlib,json,re
ROOT=Path(__file__).resolve().parents[1]
SCOPES={
 'mad4b-lifecycle-orchestrator':'wp-content/plugins/mad4b-lifecycle-orchestrator/',
 'mad4b-content-hub':'wp-content/plugins/mad4b-content-hub/',
 'mad4b-platform':'wp-content/plugins/mad4b-platform/',
 'mad4b-render-governor':'wp-content/mu-plugins/mad4b-render-query-governor.php',
}
def verify(manifest,root=ROOT,live_root=None):
    if not isinstance(manifest,dict) or manifest.get('contract')!='mad4b.local-live-source-snapshot-fidelity.v1':
        raise ValueError('INVALID_SOURCE_SNAPSHOT_CONTRACT')
    files=manifest.get('files')
    if not isinstance(files,list) or not files or len(files)>1500:
        raise ValueError('MISSING_OR_BROAD_SOURCE_LIST')
    if manifest.get('allow_release') is not False or manifest.get('source_snapshot_only') is not True:
        raise ValueError('INVALID_RELEASE_CLAIM')
    seen=set(); grouped={}
    for row in files:
        if not isinstance(row,dict) or set(row)!={'scope','path','bytes','sha256','git_blob_sha1'}:
            raise ValueError('INVALID_SOURCE_RECORD')
        scope=row['scope'];path=row['path']
        if not isinstance(scope,str) or scope not in SCOPES or not isinstance(path,str):
            raise ValueError('INVALID_SOURCE_PATH')
        if path in seen or path.startswith(('/','-')) or chr(92) in path or any(x in ('.','..','') for x in path.split('/')):
            raise ValueError('UNSAFE_SOURCE_PATH')
        seen.add(path)
        if not(path.startswith(SCOPES[scope]) if scope!='mad4b-render-governor' else path==SCOPES[scope]):
            raise ValueError('PATH_OUTSIDE_SCOPED_SOURCE')
        if Path(path).suffix.lower() not in ('.php','.js','.css'):
            raise ValueError('UNEXPECTED_CODE_EXTENSION')
        if not isinstance(row['bytes'],int) or not 0<row['bytes']<200000 or not isinstance(row['sha256'],str) or not re.fullmatch(r'[a-f0-9]{64}',row['sha256']) or not isinstance(row['git_blob_sha1'],str) or not re.fullmatch(r'[a-f0-9]{40}',row['git_blob_sha1']):
            raise ValueError('INVALID_SOURCE_DIGEST')
        for where in (root,live_root) if live_root is not None else (root,):
            file=Path(where)/path
            if not file.is_file() or file.is_symlink() or file.stat().st_size!=row['bytes']:
                raise ValueError('MISSING_SOURCE_OR_UNEXPECTED_METADATA')
            content=file.read_bytes()
            if hashlib.sha256(content).hexdigest()!=row['sha256']:
                raise ValueError('SOURCE_SHA256_DRIFT')
            if hashlib.sha1((f"blob {len(content)}\0").encode()+content).hexdigest()!=row['git_blob_sha1']:
                raise ValueError('GIT_BLOB_DRIFT')
        grouped[scope]=grouped.get(scope,0)+1
    if grouped!={'mad4b-lifecycle-orchestrator':12,'mad4b-content-hub':61,'mad4b-platform':459,'mad4b-render-governor':1}:
        raise ValueError('MODULE_GROUP_COUNTS_CHANGED')
    return {'state':'PASS_SOURCE_SNAPSHOT_FIDELITY_ONLY','files_verified':len(seen),
            'by_source':grouped,'checked_current_live':live_root is not None,
            'site_mutated':False,'original318_v82_deployed':False,
            'source_complete_for_global_release':False,
            'company_deployment_authorized':False}
if __name__=='__main__':
    cli=argparse.ArgumentParser()
    cli.add_argument('--live-root',type=Path)
    args=cli.parse_args()
    try:
        print(json.dumps(verify(json.loads((ROOT/'docs/release-coverage/missing-live-code-source-snapshot-20261111.json').read_text()),ROOT,args.live_root),indent=2,sort_keys=True))
    except (ValueError,OSError) as err:
        print(json.dumps({'state':'BLOCKED','reason':str(err),'release_authorized':False}))
        raise SystemExit(2)
