#!/usr/bin/env python3
"""No writes to websites. Source parity is a prerequisite, never release proof."""
from pathlib import Path
import argparse,hashlib,json,datetime
COMPONENTS=(
 ('mad4b-site-control-plane','wp-content/plugins/mad4b-site-control-plane'),
 ('mad4b-company-portfolio-manager','wp-content/plugins/mad4b-company-portfolio-manager'),
 ('mad4b-tenant-sync','wp-content/plugins/mad4b-tenant-sync'),
 ('mad4b-lifecycle-orchestrator','wp-content/plugins/mad4b-lifecycle-orchestrator'),
 ('mad4b-content-hub','wp-content/plugins/mad4b-content-hub'),
 ('mad4b-platform','wp-content/plugins/mad4b-platform'),
 ('mad4b-render-query-governor','wp-content/mu-plugins/mad4b-render-query-governor.php'),
)
EXTENSIONS={'.php','.js','.css'}

def source_files(root,relative):
    base=root/relative
    if base.is_symlink():raise ValueError('CODE_ROOT_SYMLINK_UNTRUSTED')
    if base.is_file():
        if base.suffix.lower() not in EXTENSIONS:return {}
        return {str(relative):base}
    if not base.is_dir():return {}
    results={}
    for file in base.rglob('*'):
        if file.is_symlink():raise ValueError('CODE_PATH_SYMLINK_UNTRUSTED')
        if not file.is_file() or file.suffix.lower() not in EXTENSIONS:continue
        if not file.resolve().is_relative_to(root.resolve()):
            raise ValueError('CODE_PATH_OUTSIDE_SOURCE_ROOT')
        results[file.relative_to(root).as_posix()]=file
    return results

def sha(file):
    return hashlib.sha256(file.read_bytes()).hexdigest()

def audit(candidate,live,components=COMPONENTS):
    candidate=Path(candidate);live=Path(live)
    if not candidate.is_dir() or not live.is_dir():raise ValueError('SOURCE_ROOT_MISSING')
    rows=[]
    for name,rel in components:
        location=Path(rel)
        if location.is_absolute() or '..' in location.parts:
            raise ValueError('MODULE_OUTSIDE_SCOPE')
        intended=source_files(candidate,location)
        current=source_files(live,location)
        matches=0;differ=[];missing=[]
        for path,site_file in sorted(current.items()):
            if path not in intended:missing.append(path)
            elif sha(intended[path])==sha(site_file):matches+=1
            else:differ.append(path)
        rows.append({'module':name,'live_code_files':len(current),'git_candidate_code_files':len(intended),
         'matching_sha256':matches,'content_different':len(differ),'live_paths_missing_from_git':len(missing),
         'different_paths':differ,'missing_paths':missing,
         'candidate_only_count':len(set(intended)-set(current))})
    complete_paths=all(not r['live_paths_missing_from_git'] for r in rows)
    exact_parity=complete_paths and all(not r['content_different'] for r in rows)
    return {'contract':'mad4b.git-live-code-coverage-gate.v1',
       'checked_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
       'state':'CODE_PATHS_COMPLETE_WITH_DIVERGENCE__HOLD' if complete_paths and not exact_parity else (
           'EXACT_CODE_SOURCE_PARITY__OTHER_RELEASE_GATES_REQUIRED' if exact_parity else 'CODE_PATHS_MISSING__HOLD'),
       'all_live_code_paths_represented':complete_paths,
       'full_byte_parity':exact_parity,
       'further_semantic_branch_review_required':True,
       'wordpress_runtime_validated':False,
       'signed_original313_receipt_validated':False,
       'release_authorized':False,
       'wordpress_modified':False,
       'modules':rows}
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--source-root',type=Path,default=Path(__file__).resolve().parents[1])
    p.add_argument('--live-root',type=Path,required=True)
    p.add_argument('--output',type=Path)
    args=p.parse_args()
    try:
        data=audit(args.source_root,args.live_root)
        result=json.dumps(data,indent=2,sort_keys=True)+'\n'
        if args.output:
            args.output.parent.mkdir(parents=True,exist_ok=True)
            args.output.write_text(result)
        print(result)
    except (ValueError,OSError) as exc:
        print(json.dumps({'state':'BLOCKED','reason':str(exc),
            'wordpress_modified':False,'release_authorized':False}))
        raise SystemExit(2)
