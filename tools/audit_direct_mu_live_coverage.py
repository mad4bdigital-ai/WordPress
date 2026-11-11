#!/usr/bin/env python3
"""Direct regular-file MU source coverage; does not follow live MU symlinks."""
from pathlib import Path
import argparse,json,hashlib
CODE={'.php','.js','.css'}
def files(root):
    base=Path(root)/'wp-content/mu-plugins'
    if not base.is_dir() or base.is_symlink():
        raise ValueError('MU_SOURCE_ROOT_UNAVAILABLE')
    return {p.name:p for p in base.iterdir() if p.is_file() and not p.is_symlink() and p.suffix.lower() in CODE}
def audit(candidate,live):
    a=files(candidate);b=files(live)
    missing=sorted(set(b)-set(a))
    different=sorted(k for k in set(a)&set(b) if hashlib.sha256(a[k].read_bytes()).digest()!=hashlib.sha256(b[k].read_bytes()).digest())
    return {'contract':'mad4b.direct-mu-runtime-code-coverage.v1',
            'state':'SOURCE_PATHS_MISSING__HOLD' if missing else (
                'SOURCE_DIFFERENCES_REMAIN__HOLD' if different else 'EXACT_SOURCE__OTHER_RELEASE_GATES_REQUIRED'),
            'direct_mu_live_code_count':len(b),
            'direct_mu_git_code_count':len(a),
            'missing_paths':missing,'different_paths':different,
            'candidate_only_count':len(set(a)-set(b)),
            'source_only':True,'sites_modified':False,'release_authorized':False,
            'live_symlinks_followed':False}
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--source-root',type=Path,default=Path(__file__).resolve().parents[1])
    parser.add_argument('--live-root',type=Path,required=True)
    parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    try:
        d=audit(args.source_root,args.live_root)
        raw=json.dumps(d,indent=2,sort_keys=True)+'\n'
        if args.output:
            args.output.parent.mkdir(parents=True,exist_ok=True)
            args.output.write_text(raw)
        print(raw)
    except (ValueError,OSError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
