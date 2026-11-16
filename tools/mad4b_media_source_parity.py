#!/usr/bin/env python3
"""Read-only first-party media source preflight. Never copies or deploys files.

A missing Git file is an unclassified ownership question, NOT a request to
reinstall a legacy MU file that may have moved into a native plugin. Only a
reviewed provider release plan can decide ownership and resolve these holds.
"""
from __future__ import annotations
import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import sys

_MEDIA_NAMES = (
    'media', 'photo', 'gallery', 'image', 'focus', 'portrait', 'coverage',
    'tour-content-layout-control', 'page-dependency-cache',
)


def digest(path: Path) -> str | None:
    if not path.is_file() or path.is_symlink():
        return None
    h = hashlib.sha256()
    with path.open('rb') as file:
        for block in iter(lambda: file.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def audit(live: Path, tracked: Path, source: Path | None) -> dict:
    paths: set[Path] = set()
    for root in (live / 'mu-plugins', live / 'plugins/mad4b-commerce/modules'):
        if not root.is_dir():
            continue
        for file in root.rglob('*.php'):
            rel = file.relative_to(live)
            if ('/vendor/' in '/' + rel.as_posix() + '/') or not any(
                word in rel.as_posix() for word in _MEDIA_NAMES
            ) or not file.is_file() or file.stat().st_size < 512:
                continue
            paths.add(rel)
    results = []
    for rel in sorted(paths, key=str):
        live_sha = digest(live / rel)
        git_sha = digest(tracked / rel)
        source_sha = digest(source / rel) if source is not None else None
        status = ('unmapped_git_owner' if git_sha is None else
                  'unreconciled_git_runtime' if git_sha != live_sha else 'tracked_same')
        source_status = ('not_inspected' if source is None else
                         'not_in_source' if source_sha is None else
                         'source_differs_from_live' if source_sha != live_sha else 'source_matches_live')
        results.append({
            'path': rel.as_posix(), 'status': status,
            'source_status': source_status,
            'live_sha256': live_sha, 'git_sha256': git_sha,
            'source_sha256': source_sha,
        })
    counts = Counter(row['status'] for row in results)
    source_counts = Counter(row['source_status'] for row in results)
    safe = bool(results) and counts['unmapped_git_owner'] == 0 and counts['unreconciled_git_runtime'] == 0
    return {
        'contract': 'mad4b.media-runtime-source-parity-preflight.v1',
        'read_only': True, 'media_modules_examined': len(results),
        'status': 'exact_git_runtime_parity' if safe else 'hold_for_owner_aware_reconciliation',
        'git_parity': dict(sorted(counts.items())),
        'source_parity': dict(sorted(source_counts.items())),
        'missing_git_does_not_imply_file_should_be_copied': True,
        'source_copy_and_grants_issued': False,
        'files': results,
    }



def verify_company_overlay(live: Path, overlay: Path) -> dict:
    """Verify every mirrored file against both its manifest and live bytes.

    Passing this check proves a saved source snapshot, not ownership approval
    for promoting a company MU file to the common plugin runtime.
    """
    manifest_file = overlay / 'manifest.json'
    try:
        data = json.loads(manifest_file.read_text())
        rows = data['source_files']
    except (OSError, ValueError, KeyError, TypeError):
        return {'status': 'invalid_overlay_manifest', 'verified': 0, 'drift': []}
    if data.get('contract') != 'mad4b.wayfatours.production-media-runtime-source-mirror.v1' or not isinstance(rows, list):
        return {'status': 'invalid_overlay_manifest', 'verified': 0, 'drift': []}
    issues, checked = [], 0
    if len(rows) != data.get('total_files') or not rows:
        issues.append('manifest_file_count_mismatch')
    present = {x.relative_to(overlay / 'wp-content').as_posix()
               for x in (overlay / 'wp-content').rglob('*') if x.is_file()}
    manifest_paths = {row.get('relative_path') for row in rows if isinstance(row, dict)}
    if present != manifest_paths:
        issues.append('unlisted_or_missing_overlay_file')
    for row in rows:
        if not isinstance(row, dict):
            issues.append('invalid_manifest_row')
            continue
        rel = row.get('relative_path')
        if not isinstance(rel, str) or rel.startswith('/') or not rel or '..' in Path(rel).parts:
            issues.append('invalid_relative_path')
            continue
        expected = row.get('sha256')
        if not isinstance(expected, str) or len(expected) != 64:
            issues.append(rel + ':invalid_sha256')
            continue
        if digest(overlay / 'wp-content' / rel) != expected:
            issues.append(rel + ':git_overlay_hash_drift')
            continue
        if digest(live / rel) != expected:
            issues.append(rel + ':production_hash_drift')
            continue
        checked += 1
    return {'contract': 'mad4b.media-company-overlay-identity.v1',
            'status': 'exact_live_source_mirror' if not issues else 'mirror_drift',
            'declared': len(rows), 'verified': checked, 'drift': issues,
            'never_auto_deploy_or_grant_from_mirror': True}



def verify_company_ownership(report: dict, contract_file: Path) -> dict:
    """Admit a scoped *source identity* only; never a deployment grant.

    Unlisted divergence is a hard HOLD, even when the company overlay is
    valid. A newer common module remains a version-pinned candidate until
    its semantics are explicitly accepted for the site.
    """
    problems=[]
    if report.get('company_overlay', {}).get('status') != 'exact_live_source_mirror':
        problems.append('exact_company_runtime_mirror_required')
    try:
        contract = json.loads(contract_file.read_text())
    except (OSError, ValueError):
        contract = None
    if not isinstance(contract, dict) or contract.get('contract') != 'mad4b.media.company-scoped-runtime-ownership.v1':
        problems.append('company_ownership_contract_invalid')
        contract = {}
    if contract.get('site') != 'https://wayfatours.com':
        problems.append('company_site_not_exactly_bound')
    declared=contract.get('exceptions',[])
    if not isinstance(declared,list):
        declared=[];problems.append('exceptions_not_array')
    keyed={}
    for row in declared:
        key=row.get('path') if isinstance(row,dict) else None
        if not isinstance(key,str) or key in keyed:
            problems.append('duplicate_or_invalid_exception_path')
        else:keyed[key]=row
    discrepancies={x['path']:x for x in report['files'] if x['status']!='tracked_same'}
    if set(discrepancies)!=set(keyed):
        problems.append('unlisted_or_stale_runtime_differences')
    covered=0
    for path,record in keyed.items():
        current=discrepancies.get(path)
        if current is None:continue
        if record.get('owner_scope')!='wayfatours_only' or record.get('site_overlay_immutable_source') is not True:
            problems.append(path+':scope_not_bound');continue
        if record.get('live_sha256') != current['live_sha256'] or record.get('common_candidate_sha256') != current['git_sha256'] or record.get('source_site_expected_sha256') != current['source_sha256']:
            problems.append(path+':source_digest_changed');continue
        decision=record.get('decision')
        if decision=='company_only':
            if current['status']!='unmapped_git_owner' or current['source_status']!='not_in_source':
                problems.append(path+':not_company_unique');continue
        elif decision=='company_runtime_version_pin':
            if current['status']!='unreconciled_git_runtime' or current['source_status']!='source_matches_live':
                problems.append(path+':unreviewed_common_release_candidate');continue
        elif decision=='company_runtime_version_pin_source_diverged':
            # A company-native module may be newer than the central source.
            # Record all three exact SHAs, never silently copy either runtime.
            if current['status']!='unreconciled_git_runtime' or current['source_status']!='source_differs_from_live':
                problems.append(path+':unreviewed_cross_site_release_candidate');continue
        else:
            problems.append(path+':unrecognized_ownership_decision');continue
        covered+=1
    return {'contract':'mad4b.media.company-source-ownership-preflight.v1',
            'status':'exact_company_source_ownership_reconciled' if not problems else 'hold_for_ownership_reassessment',
            'declared_exceptions':len(keyed),'verified_exceptions':covered,
            'unlisted_runtime_differences':len(set(discrepancies)-set(keyed)),
            'reasons':problems,
            'original313_release_grant_issued':False,
            'copyright_clearance_issued':False,
            'unreviewed_common_code_auto_promoted':False}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--live-wp-content', type=Path, required=True)
    parser.add_argument('--git-wp-content', type=Path, required=True)
    parser.add_argument('--source-wp-content', type=Path)
    parser.add_argument('--require-parity', action='store_true', help='exit 4 on unresolved source ownership')
    parser.add_argument('--company-overlay', type=Path, help='verify exact company-owned source mirror')
    parser.add_argument('--company-ownership', type=Path, help='verify approved company-scoped source-version pins')
    parser.add_argument('--require-company-source', action='store_true', help='exit 6 if company-scope source mapping is not exact')
    parser.add_argument('--require-overlay-match', action='store_true', help='exit 5 if saved company files drift from live')
    args = parser.parse_args(argv)
    if not args.live_wp_content.is_dir() or not args.git_wp_content.is_dir():
        parser.error('both live and Git wp-content directories must exist')
    report = audit(args.live_wp_content, args.git_wp_content, args.source_wp_content)
    if args.company_overlay:
        report['company_overlay'] = verify_company_overlay(args.live_wp_content, args.company_overlay)
    if args.company_ownership:
        report['company_source_ownership'] = verify_company_ownership(report, args.company_ownership)
    print(json.dumps(report, indent=2, ensure_ascii=False))
    if args.require_company_source and (not args.company_ownership or report['company_source_ownership']['status']!='exact_company_source_ownership_reconciled'):
        return 6
    if args.require_overlay_match and (not args.company_overlay or report['company_overlay']['status'] != 'exact_live_source_mirror'):
        return 5
    if args.require_parity and report['status'] != 'exact_git_runtime_parity':
        return 4
    return 0


if __name__ == '__main__':
    sys.exit(main())
