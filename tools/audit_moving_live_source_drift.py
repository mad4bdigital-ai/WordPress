#!/usr/bin/env python3
"""Read-only immutable Git vs moving live code evidence, never release authority."""
from pathlib import Path
import argparse,datetime,hashlib,json,subprocess,sys
sys.path.insert(0,str(Path(__file__).resolve().parent))
from audit_all_first_party_plugin_code import run as fleet_audit
from audit_direct_mu_live_coverage import audit as mu_audit
ROOT=Path(__file__).resolve().parents[1]
SAFE_PREFIX='wp-content/plugins/mad4b-'
MU_PREFIX='wp-content/mu-plugins/'
CODE={'.php','.css','.js'}
def sha(contents):
    return hashlib.sha256(contents).hexdigest()
def source_paths(fleet,mu):
    missing={p for r in fleet['modules'] for p in r['missing_paths']}
    differences={p for r in fleet['modules'] for p in r['different_paths']}
    missing|={MU_PREFIX+p for p in mu['missing_paths']}
    differences|={MU_PREFIX+p for p in mu['different_paths']}
    if missing&differences:raise ValueError('SAME_PATH_MISSING_AND_DIFFERENT')
    return missing,differences
def safe_path(path):
    if (not isinstance(path,str) or path.startswith('/') or '\\' in path
        or any(x in ('','.','..') for x in path.split('/'))
        or Path(path).suffix.lower() not in CODE):
        raise ValueError('UNSAFE_CODE_PATH')
    if path.startswith(MU_PREFIX):
        if '/' in path[len(MU_PREFIX):]:raise ValueError('NESTED_MU_SCOPE')
    elif not path.startswith(SAFE_PREFIX) or len(Path(path).parts)<4:
        raise ValueError('UNREGISTERED_PLUGIN_SCOPE')
    return path
def attestation(candidate,live,missing,different):
    rows=[]
    for relative in sorted(missing|different):
        safe_path(relative)
        source=Path(live)/relative
        target=Path(candidate)/relative
        if not source.is_file() or source.is_symlink():
            raise ValueError('SOURCE_PATH_NOT_REGULAR')
        raw=source.read_bytes()
        if not 0<len(raw)<2000000:raise ValueError('SOURCE_FILE_SIZE_UNBOUNDED')
        target_sha=None
        if relative in different:
            if not target.is_file() or target.is_symlink():
                raise ValueError('DIFFERENT_PATH_NOT_IN_GIT')
            target_sha=sha(target.read_bytes())
        elif target.exists() or target.is_symlink():
            raise ValueError('MISSING_PATH_UNEXPECTEDLY_EXISTS')
        rows.append({'path':relative,
                     'relation':'missing_in_candidate' if relative in missing else 'content_diverges',
                     'live_sha256':sha(raw),
                     'candidate_sha256':target_sha,
                     'live_size_bytes':len(raw)})
    return rows
def run(candidate,live):
    candidate=Path(candidate)
    live=Path(live)
    # Two independent full directory walks make a moving source visible.
    first_fleet=fleet_audit(candidate,live)
    first_mu=mu_audit(candidate,live)
    missing,differ=source_paths(first_fleet,first_mu)
    rows=attestation(candidate,live,missing,differ)
    second_fleet=fleet_audit(candidate,live)
    second_mu=mu_audit(candidate,live)
    missing2,differ2=source_paths(second_fleet,second_mu)
    unstable=(missing!=missing2 or differ!=differ2)
    if not unstable:
        # Check every recorded live source fingerprint once more after the
        # second inventory. This detects edits during the readback window.
        for r in rows:
            f=live/r['path']
            if not f.is_file() or f.is_symlink() or sha(f.read_bytes())!=r['live_sha256']:
                unstable=True
                break
    git=lambda *args:subprocess.check_output(
        ['git','-C',str(candidate),*args],text=True,timeout=20).strip()
    head=git('rev-parse','HEAD')
    if not (len(head)==40 and all(c in '0123456789abcdef' for c in head)):
        raise ValueError('UNTRUSTED_GIT_HEAD')
    by_owner={}
    for r in rows:
        owner='direct-mu' if r['path'].startswith(MU_PREFIX) else r['path'].split('/')[2]
        current=by_owner.setdefault(owner,{'missing':0,'different':0})
        current['missing' if r['relation']=='missing_in_candidate' else 'different']+=1
    if sum(x['missing'] for x in by_owner.values())!=len(missing):
        raise ValueError('INVENTORY_COUNT_UNVERIFIED')
    if sum(x['different'] for x in by_owner.values())!=len(differ):
        raise ValueError('DIVERGENCE_COUNT_UNVERIFIED')
    state=('HOLD_CONCURRENT_LIVE_SOURCE_CHANGE' if unstable
           else ('HOLD_LIVE_SOURCE_NEW_PATHS_OR_CODE_DIFFERENCES'
                  if missing or differ else 'SOURCE_PARITY_ONLY_OTHER_RELEASE_GATES_REQUIRED'))
    return {
        'contract':'mad4b.live-first-code-drift-frozen-candidate.v1',
        'checked_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'state':state,'candidate_git_head':head,
        'candidate_git_tree':git('rev-parse','HEAD^{tree}'),
        'candidate_git_clean':not bool(git('status','--porcelain')),
        'source_site':'tours.dev.mad4b.com',
        'first_party_roots_checked':first_fleet['first_party_plugin_roots_examined'],
        'direct_mu_regular_code_files':first_mu['direct_mu_live_code_count'],
        'missing_live_code_paths':len(missing),
        'differing_live_code_paths':len(differ),
        'live_changed_during_readback':unstable,
        'source_sha_evidence':rows,
        'by_owner':dict(sorted(by_owner.items())),
        'in_scope_live_code_only':True,
        'non_code_runtime_dependencies_audited':False,
        'all_local_git_branches_semantically_merged':False,
        'real_browser_ajax_accepted':False,
        'original318_v82_published_by_audit':False,
        'original313_signed_receipt_created':False,
        'production_release_authorized':False,
        'company_or_source_website_modified':False,
        'source_only':True,
    }
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--source-root',type=Path,default=ROOT)
    parser.add_argument('--live-root',type=Path,required=True)
    parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    try:
        result=run(args.source_root,args.live_root)
        document=json.dumps(result,sort_keys=True,indent=2)+'\n'
        if args.output:
            args.output.parent.mkdir(parents=True,exist_ok=True)
            args.output.write_text(document)
        print(document)
    except (OSError,ValueError,subprocess.CalledProcessError) as exc:
        print(json.dumps({'state':'BLOCKED_READBACK_UNVERIFIED','reason':str(exc),
                          'production_release_authorized':False}))
        raise SystemExit(2)
