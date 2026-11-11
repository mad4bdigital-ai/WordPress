#!/usr/bin/env python3
"""Live-first aggregate MAD4B plugin coverage, preserves all diff holds."""
from pathlib import Path
import argparse,json,re
from audit_git_live_code_coverage import audit
def run(candidate,live):
    root=Path(live)/'wp-content/plugins'
    if not root.is_dir():raise ValueError('LIVE_PLUGIN_ROOT_UNAVAILABLE')
    names=sorted(x.name for x in root.iterdir() if x.is_dir()
        and not x.is_symlink() and re.fullmatch(r'mad4b-[a-z0-9-]+',x.name))
    if not 1<=len(names)<=100:raise ValueError('PLUGIN_ROOT_ENUMERATION_BOUNDED')
    base=audit(candidate,live,components=[(name,'wp-content/plugins/'+name) for name in names])
    base['contract']='mad4b.all-first-party-plugin-source-coverage.v1'
    base['first_party_plugin_roots_examined']=len(names)
    base['total_missing_code_paths']=sum(x['live_paths_missing_from_git'] for x in base['modules'])
    base['total_existing_different_code_paths']=sum(x['content_different'] for x in base['modules'])
    base['installed_plugin_status_checked']=False
    base['source_only']=True
    base['release_authorized']=False
    return base
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--source-root',type=Path,default=Path(__file__).resolve().parents[1])
    parser.add_argument('--live-root',type=Path,required=True)
    parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    try:
        result=run(args.source_root,args.live_root)
        raw=json.dumps(result,indent=2,sort_keys=True)+'\n'
        if args.output:
            args.output.parent.mkdir(parents=True,exist_ok=True)
            args.output.write_text(raw)
        print(raw)
    except (OSError,ValueError) as e:
        print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
        raise SystemExit(2)
