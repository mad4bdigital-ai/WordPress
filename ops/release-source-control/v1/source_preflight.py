#!/usr/bin/env python3
"""Read-only MAD4B release-source selection and reconciliation qualification.

All source bytes are bounded, compared to two live company deployments and
pinned to an immutable identity. Selection is never package certification.
No WordPress, Git branch, release, or grant mutations are performed.
"""
from __future__ import annotations
import argparse
import datetime as dt
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tarfile
import tempfile

HERE = Path(__file__).resolve().parent
REPO = 'mad4bdigital-ai/WordPress'
REMOTE = 'https://github.com/' + REPO + '.git'
PREFIX = 'wp-content/plugins/mad4b-site-control-plane/'
MU_PREFIX = 'wp-content/mu-plugins/'
SHA = re.compile(r'^[a-f0-9]{40}$')
REV = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9._/-]{0,119}$')
SOURCE_KINDS = ('local_integration', 'live_toursdev', 'github_master', 'github_candidate')

class Blocked(Exception):
    pass

def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def canonical(data: object) -> bytes:
    return (json.dumps(data, sort_keys=True, ensure_ascii=False, separators=(',', ':')) + '\n').encode()

def command(argv: list[str], timeout: int = 35) -> bytes:
    p = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                       timeout=timeout, env={**os.environ, 'GIT_TERMINAL_PROMPT': '0'}, check=False)
    if p.returncode:
        raise Blocked('host_command_failed:' + argv[0] + ':' + str(p.returncode))
    return p.stdout.strip()

def branch_name(ref: str) -> str:
    if not isinstance(ref, str) or not REV.fullmatch(ref) or '..' in ref or '//' in ref or '@{' in ref or ref.endswith(('/', '.', '.lock')):
        raise Blocked('unsafe_branch_reference')
    if command(['git', 'check-ref-format', '--branch', ref]) != ref.encode():
        raise Blocked('git_branch_reference_invalid')
    return ref

def safe_path(raw: str) -> str | None:
    if '\\' in raw or '\x00' in raw or raw.startswith('/'):
        raise Blocked('unsafe_archive_path')
    pp = PurePosixPath(raw)
    if '..' in pp.parts:
        raise Blocked('unsafe_archive_path')
    if raw.startswith(PREFIX):
        rel = raw[len(PREFIX):]
        return 'plugin/' + rel if rel else None
    if raw.startswith(MU_PREFIX):
        rel = raw[len(MU_PREFIX):]
        if '/' not in rel and rel.endswith('.php') and 'mad4b' in rel.lower():
            return 'mu/' + rel
    return None

def inventory_live(site: Path, policy: dict) -> dict[str, str]:
    if not site.is_dir() or site.is_symlink():
        raise Blocked('live_site_root_missing')
    out: dict[str,str] = {}
    bytes_total = 0
    root = site / 'wp-content/plugins/mad4b-site-control-plane'
    if not root.is_dir() or root.is_symlink():
        raise Blocked('live_control_plane_missing')
    for path in sorted(root.rglob('*')):
        if path.is_symlink():
            raise Blocked('untrusted_symlink_in_live_plugin')
        if not path.is_file():
            continue
        rel = path.relative_to(root).as_posix()
        name = safe_path(PREFIX + rel)
        if name is None or name in out:
            raise Blocked('duplicate_or_unmapped_live_file')
        size = path.stat().st_size
        if size > policy['max_bytes'] or len(out) >= policy['max_files']:
            raise Blocked('live_inventory_limits_exceeded')
        data = path.read_bytes()
        if len(data) != size:
            raise Blocked('live_source_changed_during_read')
        bytes_total += size
        if bytes_total > policy['max_bytes']:
            raise Blocked('live_inventory_bytes_exceeded')
        out[name] = digest(data)
    mu = site / 'wp-content/mu-plugins'
    if mu.is_dir():
        for path in sorted(mu.iterdir()):
            name = safe_path(MU_PREFIX + path.name)
            if name is None:
                continue
            if path.is_symlink():
                raise Blocked('untrusted_symlink_in_selected_live_mu')
            if not path.is_file():
                continue
            data = path.read_bytes()
            bytes_total += len(data)
            if bytes_total > policy['max_bytes'] or len(out) >= policy['max_files']:
                raise Blocked('live_inventory_limits_exceeded')
            out[name] = digest(data)
    return out

def archive_git(repo: Path, sha: str, policy: dict) -> tuple[dict[str,str], bytes | None]:
    if not SHA.fullmatch(sha):
        raise Blocked('git_commit_sha_invalid')
    command(['git', '-C', str(repo), 'cat-file', '-e', sha + '^{commit}'])
    # Bound the archive *before* materializing it. Git tree entries are parsed as
    # bytes and no code from the candidate tree is ever executed.
    tree_listing=command(['git','-C',str(repo),'ls-tree','-r','-l','-z',sha,
                          'wp-content/plugins/mad4b-site-control-plane','wp-content/mu-plugins'],timeout=25)
    listed_files=0;listed_bytes=0
    for item in tree_listing.split(b'\0'):
        if not item: continue
        header,separator,encoded_path=item.partition(b'\t')
        if not separator: raise Blocked('git_tree_format_invalid')
        fields=header.split()
        if len(fields)!=4: raise Blocked('git_tree_entry_invalid')
        try:
            mapped=safe_path(encoded_path.decode('utf-8'))
        except UnicodeDecodeError as e:
            raise Blocked('git_tree_path_encoding_invalid') from e
        if not mapped: continue
        if fields[0] not in (b'100644',b'100755') or fields[1]!=b'blob' or not fields[3].isdigit():
            raise Blocked('git_tree_contains_nonregular_file:'+mapped)
        listed_files+=1;listed_bytes+=int(fields[3])
        if listed_files>policy['max_files'] or listed_bytes>policy['max_bytes']:
            raise Blocked('git_tree_inventory_limits_exceeded')
    result = subprocess.run(['git', '-C', str(repo), 'archive', '--format=tar', sha,
                             'wp-content/plugins/mad4b-site-control-plane', 'wp-content/mu-plugins'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=55, check=False)
    if result.returncode:
        raise Blocked('git_archive_unavailable')
    if len(result.stdout) > policy['max_bytes'] + 33554432:
        raise Blocked('git_archive_too_large')
    out: dict[str,str] = {}
    manifest_raw: bytes | None = None
    total = 0
    with tarfile.open(fileobj=io.BytesIO(result.stdout), mode='r:') as archive:
        for item in archive:
            name = safe_path(item.name)
            if not name or item.isdir():
                continue
            if not item.isfile():
                raise Blocked('git_source_contains_nonregular_file:' + name)
            if item.size > policy['max_bytes'] or len(out) >= policy['max_files']:
                raise Blocked('git_source_limits_exceeded')
            handle = archive.extractfile(item)
            if handle is None:
                raise Blocked('git_archive_file_missing')
            data = handle.read()
            if len(data) != item.size:
                raise Blocked('git_archive_file_truncated')
            if name in out:
                raise Blocked('git_archive_duplicate_path')
            if name == 'plugin/MAD4B-BUILD-PROVENANCE.json':
                manifest_raw = data
            total += len(data)
            if total > policy['max_bytes']:
                raise Blocked('git_archive_bytes_exceeded')
            out[name] = digest(data)
    if not any(x.startswith('plugin/') for x in out):
        raise Blocked('git_source_missing_control_plane')
    return out, manifest_raw

def source_identity(kind: str, ref: str, repo: Path, policy: dict, live_source: Path):
    if kind == 'live_toursdev':
        manifest_path=live_source/'wp-content/plugins/mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json'
        return ({'type':kind, 'repository':REPO, 'reference':'live_snapshot',
                'immutable_git_sha':None, 'origin_verified':'trusted_host_path'},
                inventory_live(live_source, policy),
                manifest_path.read_bytes() if manifest_path.is_file() else None)
    if kind == 'local_integration':
        branch = branch_name(ref)
        sha = command(['git','-C',str(repo),'rev-parse','--verify','refs/heads/'+branch+'^{commit}']).decode()
        if not SHA.fullmatch(sha): raise Blocked('local_branch_sha_invalid')
        files, manifest_raw = archive_git(repo, sha, policy)
        again = command(['git','-C',str(repo),'rev-parse','--verify','refs/heads/'+branch+'^{commit}']).decode()
        if again != sha: raise Blocked('local_branch_moved_during_preflight')
        return {'type':kind,'repository':REPO,'reference':branch,'immutable_git_sha':sha,'origin_verified':'host_git_ref'}, files, manifest_raw
    if kind in ('github_master', 'github_candidate'):
        branch = 'master' if kind == 'github_master' else branch_name(ref)
        # A fixed repository is used even when origin is reconfigured in local Git.
        expected_ref='refs/heads/'+branch
        out=command(['git','ls-remote','--heads',REMOTE,expected_ref],timeout=20).decode()
        lines=[x.split() for x in out.splitlines() if x.strip()]
        if len(lines)!=1 or len(lines[0])!=2 or lines[0][1]!=expected_ref or not SHA.fullmatch(lines[0][0]):
            raise Blocked('github_branch_not_verified')
        sha=lines[0][0]
        # Fetch into an isolated temporary bare repository; do not alter local integration.
        with tempfile.TemporaryDirectory(prefix='mad4b-release-preflight-') as td:
            isolated=Path(td)/'repository.git'
            command(['git','clone','--bare','--shared','--quiet',str(repo),str(isolated)],timeout=25)
            try:
                command(['git','-C',str(isolated),'cat-file','-e',sha+'^{commit}'],timeout=10)
            except Blocked:
                command(['git','-C',str(isolated),'fetch','--quiet','--no-tags','--depth=1',REMOTE,sha],timeout=85)
            files, manifest_raw=archive_git(isolated,sha,policy)
        renewed=command(['git','ls-remote','--heads',REMOTE,expected_ref],timeout=20).decode().split()
        if len(renewed)!=2 or renewed[0]!=sha or renewed[1]!=expected_ref:
            raise Blocked('github_branch_moved_during_preflight')
        return {'type':kind,'repository':REPO,'reference':branch,'immutable_git_sha':sha,'origin_verified':'github_branch_exact_head'},files,manifest_raw
    raise Blocked('source_type_not_allowlisted')

def source_manifest_status(raw: bytes | None, files: dict[str,str], pinned_sha: str | None) -> dict:
    report={'present':raw is not None,'self_digest_valid':False,
            'source_commit_matches_pin':None,'declared_files':0,'matching_files':0,
            'missing_or_mismatched':0,'mismatch_sample':[],'certified_release':False}
    if raw is None:
        return report
    try:
        manifest=json.loads(raw)
        entries=manifest['package_files']
        if not isinstance(entries,list) or not entries or len(entries)>6500:
            raise ValueError('invalid_entries')
        canonical_lines=[];seen=set();mismatches=[];matches=0
        for x in entries:
            path=x['path'];size=x['bytes'];sha=x['sha256']
            if not isinstance(path,str) or path in seen or not path or '..' in PurePosixPath(path).parts or not isinstance(size,int) or size<0 or not isinstance(sha,str) or not re.fullmatch('[a-f0-9]{64}',sha):
                raise ValueError('invalid_manifest_entry')
            seen.add(path)
            canonical_lines.append(f'{path}\0{size}\0{sha}\n')
            if files.get('plugin/'+path)==sha: matches+=1
            else: mismatches.append(path)
        report['self_digest_valid']=digest(''.join(sorted(canonical_lines)).encode())==manifest.get('package_manifest_digest')
        report['declared_files']=len(entries)
        report['matching_files']=matches
        report['missing_or_mismatched']=len(mismatches)
        report['mismatch_sample']=mismatches[:25]
        if pinned_sha:
            report['source_commit_matches_pin']=manifest.get('source_commit_sha')==pinned_sha
    except (ValueError,KeyError,TypeError,AttributeError,UnicodeDecodeError):
        report['manifest_parse_error']=True
    return report

def inventory_digest(files: dict[str,str]) -> str:
    return digest(''.join(f'{path}\0{value}\n' for path,value in sorted(files.items())).encode())

def is_protected(name: str, policy: dict) -> bool:
    if name in policy['protected_components']: return True
    if name.startswith('plugin/includes/') and name.endswith('.php'):
        return any(fragment in name for fragment in policy['protected_path_fragments'])
    if name.startswith('mu/'):
        return True
    return False

def release_path(path: str) -> bool:
    if path.startswith('mu/'):
        return True
    if not path.startswith('plugin/'):
        return False
    relative=path[len('plugin/'):]
    if relative.startswith(('tests/', 'docs/', 'tools/', '.github/', 'node_modules/')):
        return False
    if relative.endswith(('.md', '.rst', '.txt', '.pyc', '.log')):
        return False
    return True

def reconcile(selected: dict[str,str], target: dict[str,str], policy: dict) -> dict:
    excluded_from_release=sum(not release_path(p) for p in selected)
    selected={k:v for k,v in selected.items() if release_path(k)}
    target={k:v for k,v in target.items() if release_path(k)}
    common=selected.keys() & target.keys()
    changed=sorted(key for key in common if selected[key]!=target[key])
    source_only=sorted(selected.keys()-target.keys())
    live_only=sorted(target.keys()-selected.keys())
    risks=[path for path in changed+live_only if is_protected(path,policy)]
    site_specific=[path for path in changed if path in policy['site_specific_paths']]
    cap=policy['max_differences_returned']
    deltas=[]
    for path in sorted(set(changed+source_only+live_only)):
        issue = ('different_contents' if path in changed else
                 'not_installed_on_target' if path in source_only else 'live_only_preserve')
        kind = ('site_identity_or_media_contract' if path in policy['site_specific_paths'] else
                'site_role_overlay' if path.startswith('mu/') else
                'security_or_governance' if is_protected(path,policy) else 'shared_component')
        deltas.append({'path':path,'difference':issue,'category':kind,
                       'selected_sha256':selected.get(path),'target_sha256':target.get(path),
                       'default_action':'PRESERVE_TARGET_AND_REVIEW',
                       'automatically_reconciled':False})
    delta_digest=digest(canonical(deltas))
    return {
        'exact_matches':len(common)-len(changed),
        'changed':len(changed), 'source_only':len(source_only),'live_only':len(live_only),
        'source_development_files_excluded':excluded_from_release,
        'plugin_changed':sum(path.startswith('plugin/') for path in changed),
        'plugin_live_only':sum(path.startswith('plugin/') for path in live_only),
        'plugin_source_only':sum(path.startswith('plugin/') for path in source_only),
        'mu_changed':sum(path.startswith('mu/') for path in changed),
        'mu_live_only':sum(path.startswith('mu/') for path in live_only),
        'mu_source_only':sum(path.startswith('mu/') for path in source_only),
        'protected_conflicts':len(risks), 'site_specific_conflicts':len(site_specific),
        'changed_sample':changed[:cap], 'live_only_sample':live_only[:cap],
        'source_only_sample':source_only[:cap], 'protected_sample':risks[:cap],
        'site_specific_sample':site_specific[:cap],
        'reconciliation_plan_sha256':delta_digest,
        'reconciliation_delta_count':len(deltas),
        'reconciliation_actions':deltas,
        'preflight_conflict_free':not changed and not source_only and not live_only,
        'target_in_place_overwrite_allowed':False,
    }

def load_host_config(path: Path) -> dict:
    path=path.resolve(strict=True)
    # The preflight host inventory is a root-owned deployment configuration.
    if not str(path).startswith('/opt/mad4b-company-provisioning/config/'):
        raise Blocked('host_config_path_not_allowlisted')
    st=path.stat()
    if st.st_uid!=0 or st.st_mode & (stat.S_IWGRP|stat.S_IWOTH):
        raise Blocked('host_config_not_root_owned')
    d=json.loads(path.read_text())
    if d.get('contract')!='mad4b.release-source-host-config.v1':
        raise Blocked('host_config_contract_invalid')
    for k in ('repo','toursdev','wayfa'):
        p=Path(d.get(k,''))
        if not p.is_absolute() or not p.is_dir() or p.is_symlink():
            raise Blocked('host_config_path_invalid:'+k)
    if Path(d['repo']).resolve().as_posix().find('/opt/mad4b-company-provisioning/')!=0:
        raise Blocked('host_repo_outside_managed_root')
    return d

def write_receipt(report: dict, output: Path, select: bool, config: dict) -> None:
    output=output.resolve(strict=False)
    base=Path(config['reports_dir']).resolve(strict=True)
    if output.parent!=base or output.suffix!='.json':
        raise Blocked('report_path_must_be_in_managed_reports_dir')
    if output.is_symlink(): raise Blocked('unsafe_report_symlink')
    raw=canonical(report)
    with tempfile.NamedTemporaryFile(dir=base, prefix='.preflight-', delete=False) as tmp:
        tmp.write(raw)
        os.fchmod(tmp.fileno(),0o600)
        temp=Path(tmp.name)
    temp.replace(output)
    if select:
        # Only changes a nonauthoritative selection pointer. No release or grants.
        current=Path(config['selection_file']).resolve(strict=False)
        if current.parent!=Path(config['selection_file']).parent.resolve(strict=True) or current.is_symlink():
            raise Blocked('unsafe_selection_pointer')
        record={
            'contract':'mad4b.release-source-selection.v1',
            'selected_source':report['source'],
            'preflight_receipt_sha256':digest(raw),
            'preflight_receipt_path':str(output),
            'selected_state':'CANDIDATE_NEEDS_RELEASE_APPROVAL',
            'authorizing':False,'production_deployment_allowed':False,
            'report_status':report['preflight']['status'],
        }
        with tempfile.NamedTemporaryFile(dir=current.parent, prefix='.selected-source-', delete=False) as tmp:
            tmp.write(canonical(record)); os.fchmod(tmp.fileno(),0o600)
            temp=Path(tmp.name)
        temp.replace(current)

def show_selection(config: dict) -> dict:
    path=Path(config['selection_file'])
    if not path.is_file():
        return {'contract':'mad4b.release-source-selection-status.v1',
                'state':'UNSELECTED', 'production_deployment_allowed':False}
    if path.is_symlink(): raise Blocked('unsafe_selection_pointer')
    selected=json.loads(path.read_text())
    if selected.get('contract')!='mad4b.release-source-selection.v1' or selected.get('authorizing') is not False or selected.get('production_deployment_allowed') is not False:
        raise Blocked('selection_contract_invalid')
    report=Path(selected.get('preflight_receipt_path',''))
    if not report.is_file() or report.is_symlink() or report.parent.resolve()!=Path(config['reports_dir']).resolve():
        raise Blocked('selection_receipt_missing_or_untrusted')
    raw=report.read_bytes()
    if digest(raw)!=selected.get('preflight_receipt_sha256'):
        raise Blocked('selection_receipt_changed')
    evidence=json.loads(raw)
    if evidence.get('source')!=selected.get('selected_source') or evidence.get('preflight',{}).get('eligible_for_release_approval') is not False:
        raise Blocked('selection_source_receipt_mismatch')
    return {'contract':'mad4b.release-source-selection-status.v1',
            'state':selected.get('selected_state'),
            'selected_source':selected['selected_source'],
            'preflight_status':evidence['preflight']['status'],
            'receipt_integrity_verified':True,
            'production_deployment_allowed':False}

def main() -> int:
    parser=argparse.ArgumentParser(description='MAD4B reconciliation preflight before release-source approval')
    parser.add_argument('--source',choices=SOURCE_KINDS)
    parser.add_argument('--show-selection',action='store_true',help='Read back pinned nonauthorizing source choice and evidence')
    parser.add_argument('--ref',default='',help='Exact local integration or GitHub candidate branch')
    parser.add_argument('--target',default='both',choices=['toursdev','wayfa','both'])
    parser.add_argument('--host-config',default='/opt/mad4b-company-provisioning/config/release-source-host-config.json')
    parser.add_argument('--output',default='',help='Optional exact receipt filename within managed reports directory')
    parser.add_argument('--select',action='store_true',help='Persist candidate choice only, never approve or install')
    args=parser.parse_args()
    try:
        config=load_host_config(Path(args.host_config))
        if args.show_selection:
            if args.source or args.ref or args.select or args.output:
                raise Blocked('show_selection_is_read_only')
            print(json.dumps(show_selection(config),ensure_ascii=False))
            return 0
        if not args.source:
            raise Blocked('source_required_before_reconciliation_preflight')
        policy=json.loads((HERE/'policy.json').read_text())
        if policy.get('repository')!=REPO or args.source not in policy['allowed_sources']:
            raise Blocked('source_policy_invalid')
        if args.source in ('local_integration','github_candidate') and not args.ref:
            raise Blocked('branch_reference_required')
        if args.source in ('github_master','live_toursdev') and args.ref:
            raise Blocked('reference_not_allowed_for_this_source')
        source,files,manifest_raw=source_identity(args.source,args.ref,Path(config['repo']),policy,Path(config['toursdev']))
        source['package_manifest']=source_manifest_status(manifest_raw,files,source['immutable_git_sha'])
        if len(files)>policy['max_files']:
            raise Blocked('source_file_limit_exceeded')
        source['inventory_sha256']=inventory_digest(files)
        source['files_observed']=len(files)
        source['immutable_snapshot']=args.source!='live_toursdev'
        targets={}
        for site in (['toursdev','wayfa'] if args.target=='both' else [args.target]):
            target=inventory_live(Path(config[site]),policy)
            targets[site]={'site_inventory_sha256':inventory_digest(target),
                           'site_files_observed':len(target),
                           'reconciliation':reconcile(files,target,policy)}
        needs=any(not x['reconciliation']['preflight_conflict_free'] for x in targets.values())
        manifest_ok=(source['package_manifest']['present']
            and source['package_manifest']['self_digest_valid']
            and source['package_manifest']['missing_or_mismatched']==0)
        immutable_ok=bool(source['immutable_snapshot'])
        aligned=source['package_manifest']['source_commit_matches_pin'] is not False
        reconciliation_complete=not needs and manifest_ok and immutable_ok and aligned
        issues=[]
        if needs: issues.append('reconcile_live_code_and_site_specific_differences')
        if args.source=='live_toursdev':issues.append('capture_live_snapshot_into_reviewed_immutable_git_commit')
        if not source['package_manifest']['self_digest_valid'] or source['package_manifest']['missing_or_mismatched']:
            issues.append('reconcile_declared_package_files_and_build_identity')
        if source['package_manifest']['source_commit_matches_pin'] is False:
            issues.append('source_package_commit_differs_from_selected_commit')
        issues.extend(['certify_exact_source_package_and_attest_build',
                       'obtain_original_owner_release_approval',
                       'reverify_target_site_identity_grants_and_rollback_before_deploy'])
        report={
            'contract':'mad4b.release-source-reconciliation-preflight.v1',
            'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
            'source':source,
            'policy_sha256':digest((HERE/'policy.json').read_bytes()),
            'targets':targets,
            'preflight':{
                'status':('NEEDS_RECONCILIATION' if needs else
                          'NEEDS_PACKAGE_PROVENANCE' if not manifest_ok or not aligned or not immutable_ok else
                          'READY_FOR_CERTIFICATION_REVIEW'),
                'reconciliation_complete':reconciliation_complete,
                'source_identity_qualified_for_reconciliation':True,
                'immutable_release_candidate':source['immutable_snapshot'],
                'eligible_for_release_approval':False,
                'automatic_merge':False,'automatic_deployment':False,
                'mutations_performed':False,'grants_modified':False,
                'required_next_steps':issues,
            }
        }
        reports=Path(config['reports_dir'])
        reports.mkdir(mode=0o700,parents=True,exist_ok=True)
        ts=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
        outfile=Path(args.output) if args.output else reports / f'{ts}-{args.source}-{args.target}.json'
        write_receipt(report,outfile,args.select,config)
        print(json.dumps({'source':source,'target_counts':{k:{x:r['reconciliation'][x] for x in ('exact_matches','changed','live_only','source_only','protected_conflicts')} for k,r in targets.items()},
            'status':report['preflight']['status'],'source_qualified':True,'release_authorized':False,
            'selection_changed':args.select,'receipt':str(outfile)},ensure_ascii=False))
        return 0
    except (Blocked,ValueError,OSError,subprocess.TimeoutExpired,tarfile.TarError,UnicodeDecodeError) as e:
        print(json.dumps({'status':'BLOCKED','reason':str(e),'release_authorized':False,'mutation_performed':False}))
        return 2

if __name__ == '__main__':sys.exit(main())
