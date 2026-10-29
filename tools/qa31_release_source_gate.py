#!/usr/bin/env python3
"""Fail-closed QA31 release source parity gate. SHA-only offline evidence; never authorizes Production."""
from __future__ import annotations
import argparse,hashlib,json,re,subprocess,sys
from pathlib import Path
SHA=re.compile(r'^[a-f0-9]{64}$')
SHA40=re.compile(r'^[a-f0-9]{40}$')
def digest(p:Path)->str:return hashlib.sha256(p.read_bytes()).hexdigest()
def reviewed_bootstrap_successor(root:Path,path:str,contents:bytes)->bool:
    """Recognize one exact CI-reviewed Git successor; NEVER certify Staging parity."""
    if path!='mad4b-site-control-plane.php' or not isinstance(contents,bytes):
        return False
    repo=Path(__file__).resolve().parents[1]
    expected=repo/'wp-content/plugins/mad4b-site-control-plane'
    if root.resolve()!=expected.resolve():
        return False
    source=root/path
    if source.is_symlink() or not source.is_file() or source.read_bytes()!=contents:
        return False
    registry_path=repo/'governance/production-readiness/capability-coverage.json'
    try:
        registry=json.loads(registry_path.read_text(encoding='utf-8'))
        blob=hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()
        git_blob=subprocess.check_output(
            ['git','rev-parse','HEAD:wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
        test=repo/'wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.py'
    except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return False
    if (blob!=git_blob or not test.is_file() or test.is_symlink()
        or registry.get('contract')!='mad4b.readiness-capability-coverage.v1'
        or registry.get('authorizing') is not False
        or registry.get('production_promotion_from_ci') is not False):
        return False
    canonical='wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php'
    for row in registry.get('reviewed_changes',[]):
        if (isinstance(row,dict) and row.get('path')==canonical
            and row.get('source_blob_sha')==blob
            and row.get('status')=='reviewed_for_ci_only'
            and row.get('production_authorized') is False
            and row.get('live_evidence_required') is True
            and len(row.get('control_ids',[]))>=2
            and 'wp-content/plugins/mad4b-site-control-plane/tests/qa30-native-general-settings-rest-lifecycle.py'
                in row.get('regression_tests',[])):
            return True
    return False

def reviewed_server_write_catalog_successor(root:Path,path:str,contents:bytes)->bool:
    """Accept ONLY the exact SHA-reviewed W1 read-only provider map as pending, never as parity."""
    exact='includes/class-mad4b-scp-server-write-catalog.php'
    if path!=exact or not isinstance(contents,bytes):return False
    repo=Path(__file__).resolve().parents[1]
    expected=repo/'wp-content/plugins/mad4b-site-control-plane'
    if root.resolve()!=expected.resolve():return False
    f=root/path
    if f.is_symlink() or not f.is_file() or f.read_bytes()!=contents:return False
    registry_path=repo/'governance/production-readiness/capability-coverage.json'
    test=repo/'wp-content/plugins/mad4b-site-control-plane/tests/live-write-provider-snapshot-runtime.php'
    try:
        reg=json.loads(registry_path.read_text(encoding='utf-8'))
        blob=hashlib.sha1(b'blob '+str(len(contents)).encode('ascii')+b'\0'+contents).hexdigest()
        git_blob=subprocess.check_output(
            ['git','rev-parse','HEAD:wp-content/plugins/mad4b-site-control-plane/'+exact],
            cwd=repo,stderr=subprocess.DEVNULL,timeout=8).decode().strip()
    except (OSError,ValueError,subprocess.CalledProcessError,subprocess.TimeoutExpired):
        return False
    if (blob!=git_blob or not test.is_file() or test.is_symlink()
        or reg.get('contract')!='mad4b.readiness-capability-coverage.v1'
        or reg.get('authorizing') is not False
        or reg.get('production_promotion_from_ci') is not False):return False
    canonical='wp-content/plugins/mad4b-site-control-plane/'+exact
    for row in reg.get('reviewed_changes',[]):
        if (isinstance(row,dict) and row.get('path')==canonical
            and row.get('source_blob_sha')==blob
            and row.get('status')=='reviewed_for_ci_only'
            and row.get('production_authorized') is False
            and row.get('live_evidence_required') is True
            and {'GOVERNANCE-01','MCP-01','SECURITY-01'}.issubset(set(row.get('control_ids',[])))
            and 'wp-content/plugins/mad4b-site-control-plane/tests/live-write-provider-snapshot-runtime.php'
                in row.get('regression_tests',[])):
            return True
    return False

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
            else:unexpected.append(path)
        elif state=='GITHUB_ONLY':
            if g is None or s is not None:raise ValueError('Invalid Git-only record')
            if current==g:pending.append(path) # Staging compatibility still unverified
            else:unexpected.append(path)
        else:
            if g is None or s is None:raise ValueError('Invalid divergent record')
            if current==s:covered+=1
            elif current==g:pending.append(path)
            elif current is not None and (
                reviewed_bootstrap_successor(root,path,f.read_bytes())
                or reviewed_server_write_catalog_successor(root,path,f.read_bytes())
            ):
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
