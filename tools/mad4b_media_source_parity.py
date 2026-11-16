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


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--live-wp-content', type=Path, required=True)
    parser.add_argument('--git-wp-content', type=Path, required=True)
    parser.add_argument('--source-wp-content', type=Path)
    parser.add_argument('--require-parity', action='store_true', help='exit 4 on unresolved source ownership')
    parser.add_argument('--company-overlay', type=Path, help='verify exact company-owned source mirror')
    parser.add_argument('--require-overlay-match', action='store_true', help='exit 5 if saved company files drift from live')
    args = parser.parse_args(argv)
    if not args.live_wp_content.is_dir() or not args.git_wp_content.is_dir():
        parser.error('both live and Git wp-content directories must exist')
    report = audit(args.live_wp_content, args.git_wp_content, args.source_wp_content)
    if args.company_overlay:
        report['company_overlay'] = verify_company_overlay(args.live_wp_content, args.company_overlay)
    print(json.dumps(report, indent=2, ensure_ascii=False))
    if args.require_overlay_match and (not args.company_overlay or report['company_overlay']['status'] != 'exact_live_source_mirror'):
        return 5
    if args.require_parity and report['status'] != 'exact_git_runtime_parity':
        return 4
    return 0


if __name__ == '__main__':
    sys.exit(main())
