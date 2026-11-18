#!/usr/bin/env python3
"""Fail-closed QA31 release source parity gate. SHA-only offline evidence; never authorizes Production."""
from __future__ import annotations
import argparse,hashlib,json,re,subprocess,sys
from pathlib import Path
SHA=re.compile(r'^[a-f0-9]{64}$')
SHA40=re.compile(r'^[a-f0-9]{40}$')
def digest(p:Path)->str:return hashlib.sha256(p.read_bytes()).hexdigest()
_REVIEWED_CI_ONLY_SOURCE_TESTS = {
    'mad4b-site-control-plane.php': 'wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.py',
    'config/cross-plugin-sources.json': 'wp-content/plugins/mad4b-site-control-plane/tests/dfsb-rc15-signed-review-source-pin.py',
    'includes/class-mad4b-scp-server-write-catalog.php': 'wp-content/plugins/mad4b-site-control-plane/tests/live-write-provider-snapshot-runtime.php',
}

def reviewed_rc129_pinned_successor(repo:Path,path:str,contents:bytes):
    """Find an explicit, frozen source successor. Never infer allowlists from
    arbitrary coverage rows, titles, runtime endpoints or production state.
    """
    file=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
    if not file.is_file() or file.is_symlink():return None
    try:
        manifest=json.loads(file.read_text(encoding='utf-8'))
        legacy_path=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
        historical=json.loads(legacy_path.read_text(encoding='utf-8'))
        entries=manifest.get('reviewed_entries')
        base='ee6c57e870db6b4aada4de64b0471a953c8bdf0a'
        if (manifest.get('contract')!='mad4b.qa31-rc129-reviewed-source-successors.v1'
            or manifest.get('authorizing') is not False
            or manifest.get('production_authorized') is not False
            or manifest.get('live_evidence_required') is not True
            or manifest.get('is_build_provenance') is not False
            or manifest.get('reviewed_for_ci_only') is not True
            or manifest.get('source_commit')!=base
            or manifest.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or manifest.get('frozen_legacy_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or manifest.get('frozen_legacy_ledger_sha256')!=digest(legacy_path)
            or not isinstance(entries,list) or len(entries)!=65
            or manifest.get('total_reviewed_successors')!=len(entries)):return None
        names=[e.get('path') for e in entries if isinstance(e,dict)]
        if len(names)!=len(entries) or names!=sorted(set(names)) or path not in names:return None
        entry=entries[names.index(path)]
        legacy={x['path']:x for x in historical.get('source_decisions',[])}
        record=legacy.get(path)
        if not isinstance(record,dict) or record.get('state') not in ('STAGING_ONLY','DIVERGENT'):
            return None
        if (entry.get('state')!=record['state']
            or entry.get('historical_staging_sha256')!=record.get('staging_sha256')
            or entry.get('historical_github_sha256')!=record.get('github_sha256')
            or not SHA.fullmatch(str(entry.get('current_sha256','')))
            or not SHA40.fullmatch(str(entry.get('current_git_blob_sha','')))
            or hashlib.sha256(contents).hexdigest()!=entry['current_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=entry['current_git_blob_sha']):return None
        test=entry.get('required_regression')
        if path.startswith('bootstrap/'):
            if path not in (
                'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php',
                'bootstrap/mad4b-runtime-cache-convergence-mu.php'):
                return None
            review=entry.get('independent_bootstrap_review')
            controls=review.get('control_ids') if isinstance(review,dict) else None
            known=json.loads((repo/'governance/production-readiness/controls.json').read_text())
            known_ids={x['id'] for x in known.get('controls',[]) if isinstance(x,dict)}
            if (not isinstance(review,dict)
                or review.get('status')!='reviewed_for_ci_only'
                or review.get('production_authorized') is not False
                or review.get('live_evidence_required') is not True
                or not review.get('owner_role')
                or len(str(review.get('review_rationale','')))<90
                or not isinstance(controls,list) or len(controls)<3
                or len(controls)!=len(set(controls)) or not set(controls)<=known_ids
                or test!='tests/qa31_rc129_bootstrap_source_contract.py'):
                return None
        elif entry.get('independent_bootstrap_review') is not None:
            return None
        if (not isinstance(test,str) or not test.startswith(('tests/','wp-content/plugins/mad4b-site-control-plane/tests/'))
            or '..' in test.split('/') or not (repo/test).is_file()
            or (repo/test).is_symlink()):return None
        # CI checks out only the PR HEAD by default. If a pinned predecessor
        # is present, verify its exact blob. Otherwise permit only a *genuine*
        # shallow Git checkout; the current HEAD blob, reviewed registry SHA,
        # explicit manifest SHA256 and all source gates remain mandatory.
        parent=subprocess.run(['git','cat-file','-e',base+'^{commit}'],
                              cwd=repo,stdout=subprocess.DEVNULL,
                              stderr=subprocess.DEVNULL,timeout=8)
        if parent.returncode==0:
            exact=subprocess.check_output(
                ['git','rev-parse',base+':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if exact!=entry['current_git_blob_sha']:return None
        else:
            shallow=subprocess.check_output(
                ['git','rev-parse','--is-shallow-repository'],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if shallow!='true':return None
        return test
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,subprocess.TimeoutExpired,json.JSONDecodeError):
        return None


def reviewed_rc129_host_integration_successor(repo:Path,path:str,contents:bytes):
    """Return only the source-pinned Host regression for the six exact current
    files. The original QA31 ledger and prior rc129 successor reviews are
    immutable; this review neither authorizes release nor grants Host writes.
    """
    src=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
    if not src.is_file() or src.is_symlink():return None
    base='27883e019d7b9ddc744f6be33608b78c54ec411b'
    allowed=(
        'includes/class-mad4b-scp-artifacts.php',
        'includes/class-mad4b-scp-brand-review-routing.php',
        'includes/class-mad4b-scp-content-jobs.php',
        'includes/class-mad4b-scp-context-authority.php',
        'includes/class-mad4b-scp-host-agent-bridge.php',
        'mad4b-site-control-plane.php',
    )
    if path not in allowed or not isinstance(contents,bytes):return None
    try:
        v=json.loads(src.read_text(encoding='utf-8'))
        previous=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
        historical=repo/'governance/release-parity/qa31-commerce-staging-source-parity.json'
        ledger=json.loads(historical.read_text(encoding='utf-8'))
        rows=v.get('source_entries')
        if (v.get('contract')!='mad4b.qa31-host-integration-exact-successors.v1'
            or v.get('base_source_commit')!=base
            or v.get('original_qa31_ledger')!='governance/release-parity/qa31-commerce-staging-source-parity.json'
            or v.get('previous_rc129_reviews')!='governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
            or v.get('original_qa31_sha256')!=digest(historical)
            or v.get('previous_rc129_reviews_sha256')!=digest(previous)
            or v.get('authorizing') is not False
            or v.get('production_authorized') is not False
            or v.get('source_promotion_approved') is not False
            or v.get('is_build_provenance') is not False
            or v.get('live_evidence_required') is not True
            or v.get('release_gate')!='BLOCKED_SOURCE_RECONCILIATION'
            or v.get('exact_source_files')!=6
            or v.get('historical_qa31_overrides')!=3
            or not isinstance(rows,list) or len(rows)!=6):return None
        names=[r.get('path') for r in rows if isinstance(r,dict)]
        if len(names)!=6 or tuple(names)!=allowed:return None
        entry=rows[allowed.index(path)]
        existing={r['path']:r for r in ledger['source_decisions']}
        listed=existing.get(path)
        if listed:
            if (entry.get('source_record_scope')!='qa31_historical'
                or entry.get('original_qa31_state')!=listed.get('state')
                or entry.get('original_qa31_staging_sha256')!=listed.get('staging_sha256')
                or entry.get('original_qa31_github_sha256')!=listed.get('github_sha256')):return None
        elif entry.get('source_record_scope')!='new_or_separate_review':return None
        if (entry.get('required_regression')!='wp-content/plugins/mad4b-site-control-plane/tests/host-agent-source-parity-contract.py'
            or not SHA.fullmatch(str(entry.get('current_sha256','')))
            or not SHA40.fullmatch(str(entry.get('current_blob_sha','')))
            or hashlib.sha256(contents).hexdigest()!=entry['current_sha256']
            or hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()!=entry['current_blob_sha']):return None
        previous_sha=entry.get('parent_sha256')
        previous_blob=entry.get('parent_blob_sha')
        newfile=path=='includes/class-mad4b-scp-host-agent-bridge.php'
        if newfile:
            if previous_sha is not None or previous_blob is not None:return None
        elif not SHA.fullmatch(str(previous_sha)) or not SHA40.fullmatch(str(previous_blob)):
            return None
        parent=subprocess.run(['git','cat-file','-e',base+'^{commit}'],
            cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=8)
        if parent.returncode==0:
            old=subprocess.run(['git','rev-parse','--verify',
                base+':wp-content/plugins/mad4b-site-control-plane/'+path],
                cwd=repo,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=8)
            if newfile:
                if old.returncode==0:return None
            elif old.returncode!=0 or old.stdout.decode().strip()!=previous_blob:
                return None
        else:
            is_shallow=subprocess.check_output(['git','rev-parse','--is-shallow-repository'],
                cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
            if is_shallow!='true':return None
        return entry['required_regression']
    except (OSError,ValueError,KeyError,TypeError,subprocess.CalledProcessError,
            subprocess.TimeoutExpired,json.JSONDecodeError):
        return None


def reviewed_ci_only_source_successor(root:Path,path:str,contents:bytes)->bool:
    """Accept exact, reviewed Git blobs for CI inventory only; never parity.
    Static legacy entries remain valid; rc129 successors are independently
    pinned in a frozen SHA-backed ledger with named regression evidence.
    """
    if not isinstance(contents,bytes):return False
    repo=Path(__file__).resolve().parents[1]
    expected=repo/'wp-content/plugins/mad4b-site-control-plane'
    if root.resolve()!=expected.resolve():return False
    source=root/path
    if source.is_symlink() or not source.is_file() or source.read_bytes()!=contents:
        return False
    requested=reviewed_rc129_host_integration_successor(repo,path,contents)
    if requested is None:
        requested=reviewed_rc129_pinned_successor(repo,path,contents)
    if requested is None:
        requested=_REVIEWED_CI_ONLY_SOURCE_TESTS.get(path)
    if requested is None:return False
    try:
        registry=json.loads((repo/'governance/production-readiness/capability-coverage.json').read_text(encoding='utf-8'))
        blob=hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()
        git_blob=subprocess.check_output(
            ['git','rev-parse','HEAD:wp-content/plugins/mad4b-site-control-plane/'+path],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
        test=repo/requested
    except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return False
    if (blob!=git_blob or not test.is_file() or test.is_symlink()
        or registry.get('contract')!='mad4b.readiness-capability-coverage.v1'
        or registry.get('authorizing') is not False
        or registry.get('production_promotion_from_ci') is not False):
        return False
    canonical='wp-content/plugins/mad4b-site-control-plane/'+path
    # These two exact MU bootstrap sources are not general Control Plane
    # runtime paths; their separate, non-authorizing, SHA-pinned QA31 review
    # is validated in the frozen successor manifest above.
    if (path in ('bootstrap/mad4b-mcp-adapter-mu-bootstrap.php',
                 'bootstrap/mad4b-runtime-cache-convergence-mu.php')
        and requested=='tests/qa31_rc129_bootstrap_source_contract.py'):
        return True
    for row in registry.get('reviewed_changes',[]):
        if (isinstance(row,dict) and row.get('path')==canonical
            and row.get('source_blob_sha')==blob
            and row.get('status')=='reviewed_for_ci_only'
            and row.get('production_authorized') is False
            and row.get('live_evidence_required') is True
            and len(row.get('control_ids',[]))>=2
            and isinstance(row.get('regression_tests'),list)
            and requested in row['regression_tests']):
            return True
    return False

def reviewed_bootstrap_successor(root:Path,path:str,contents:bytes)->bool:
    """Bootstrap compatibility wrapper: no cross-path authorization."""
    return path=='mad4b-site-control-plane.php' and reviewed_ci_only_source_successor(root,path,contents)

def evaluate(evidence:dict,root:Path)->dict:
    if evidence.get('contract')!='mad4b.qa31.source-parity-evidence.v1' or        evidence.get('authorizing') is not False or        evidence.get('production_authorized') is not False or        evidence.get('live_production_evidence') is not False:
        raise ValueError('Untrusted or authorizing evidence contract')
    if not SHA40.fullmatch(str(evidence.get('github_base_source_sha',''))) or        not SHA40.fullmatch(str(evidence.get('staging_build_source_sha',''))):
        raise ValueError('Missing exact source commit provenance')
    records=evidence.get('source_decisions')
    if not isinstance(records,list) or not records:raise ValueError('Missing source coverage evidence')
    if len({x.get('path') for x in records})!=len(records):raise ValueError('Duplicate source identity')
    root=root.resolve(strict=True)
    installed_version=''
    main=root/'mad4b-site-control-plane.php'
    if main.is_file():
        m=re.search(r'(?m)^\s*\* Version:\s*(\S+)',main.read_text(errors='replace'))
        installed_version=m.group(1) if m else ''
    pending=[];unexpected=[];covered=0;reviewed_successors=[]
    for row in records:
        path=row.get('path')
        if not isinstance(path,str) or not path or '\\' in path or any(x in ('.','..','') for x in path.split('/')):
            raise ValueError('Unsafe source path')
        if row.get('category') not in ('active_source','needs_classification'):
            raise ValueError('Non-source record added to protected ledger')
        if row.get('state') not in ('STAGING_ONLY','GITHUB_ONLY','DIVERGENT'):
            raise ValueError('Unknown divergence state')
        s=row.get('staging_sha256');g=row.get('github_sha256')
        for hashval in (s,g):
            if hashval is not None and not SHA.fullmatch(str(hashval)):raise ValueError('Invalid source hash')
        f=root/path
        if f.is_symlink():raise ValueError('Symlinked source path')
        current=digest(f) if f.is_file() else None
        state=row['state']
        if state=='STAGING_ONLY':
            if g is not None or s is None:raise ValueError('Invalid missing-source record')
            if current==s:covered+=1
            elif current is None:pending.append(path)
            elif reviewed_ci_only_source_successor(root,path,f.read_bytes()):
                pending.append(path)
                reviewed_successors.append(path)
            else:unexpected.append(path)
        elif state=='GITHUB_ONLY':
            if g is None or s is not None:raise ValueError('Invalid Git-only record')
            if current==g:pending.append(path) # Staging compatibility still unverified
            else:unexpected.append(path)
        else:
            if g is None or s is None:raise ValueError('Invalid divergent record')
            if current==s:covered+=1
            elif current==g:pending.append(path)
            elif current is not None and reviewed_ci_only_source_successor(root,path,f.read_bytes()):
                pending.append(path)
                reviewed_successors.append(path)
            else:unexpected.append(path)
    version_match=installed_version==evidence.get('staging_package_version')
    decision=('SOURCE_PARITY_CANDIDATE_UNCERTIFIED'
       if not pending and not unexpected and version_match else 'BLOCKED_SOURCE_RECONCILIATION')
    return {'contract':'mad4b.qa31.source-parity-gate-result.v1',
      'authorizing':False,'production_authorized':False,'source_parity_only':True,
      'decision':decision,'evidence_source_sha':evidence['github_base_source_sha'],
      'github_version':installed_version,'staging_version':evidence['staging_package_version'],
      'version_match':version_match,'tracked_source_records':len(records),
      'merged_matching_staging_hash':covered,'pending_count':len(pending),'unexpected_count':len(unexpected),
      'reviewed_github_successor_count':len(reviewed_successors),'reviewed_github_successor_paths':reviewed_successors,
      'pending_paths':pending,'unexpected_paths':unexpected}
def main():
    p=argparse.ArgumentParser()
    p.add_argument('--evidence',required=True);p.add_argument('--github-plugin-root',required=True)
    p.add_argument('--strict',action='store_true')
    p.add_argument('--out')
    a=p.parse_args();e=json.loads(Path(a.evidence).read_text())
    r=evaluate(e,Path(a.github_plugin_root))
    if a.out:Path(a.out).write_text(json.dumps(r,indent=2)+'\n')
    print(json.dumps({k:v for k,v in r.items() if k not in ('pending_paths','unexpected_paths')},indent=2))
    if r['unexpected_count']>0:return 3
    if a.strict and r['decision']!='SOURCE_PARITY_CANDIDATE_UNCERTIFIED':return 4
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,KeyError,TypeError,OSError,json.JSONDecodeError) as exc:
        print('QA31_PARITY_FAIL_CLOSED: '+str(exc),file=sys.stderr);sys.exit(3)
