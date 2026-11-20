#!/usr/bin/env python3
"""Verify a historic PR942 collision matrix against immutable Git trees (no writes)."""
import argparse
import json
import pathlib
import subprocess
from collections import Counter

PINS = {
    'pr882': '65bb2791f7b46c5887f3e814feb8b48604a3e0ae',
    'pr920': 'e1b8c8aa83fd72bea87cfe6511f8200b57420b12',
    'pr942': 'ed7ae0508cd927aa89294e56840d468f9ea8233e',
    'pr961': 'bfd207a5e9583eaee129a4447aeb124d1a05ed2e',
    'pr964': '9f610f0abc56f4d0cbe4249036f338acd6e46167',
    'master_20261120': 'c34b93fb485c33608a0d1cd3681ac97dd25e18cf',
}
MATRIX = 'docs/source-reconciliation/merge-conflict-review-matrix-20261119.json'
PLUGIN_PREFIX = 'wp-content/plugins/mad4b-site-control-plane/'


def git_blobs(repo, commit):
    process = subprocess.run(['git', '-C', str(repo), 'ls-tree', '-r', '--full-tree', commit],
                             check=True, capture_output=True, text=True)
    return {line.split('\t', 1)[1]: line.split()[2]
            for line in process.stdout.splitlines() if '\t' in line}


def classify_rows(matrix, refs):
    rows = matrix['rows']
    if len(rows) != matrix['conflict_count'] or len({r['path'] for r in rows}) != len(rows):
        raise ValueError('Duplicate or omitted collision paths')
    if matrix['semantic_conflict_review_completed'] or matrix['release_authorized']:
        raise ValueError('Historical review ledger unexpectedly authorizing')
    counts = Counter()
    output_rows = []
    for row in rows:
        path = row['path']
        blobs = {name: tree.get(path) for name, tree in refs.items()}
        if any(blobs[name] is None for name in ['pr882', 'pr920', 'pr942', 'pr964']):
            raise ValueError('Missing committed collision source: ' + path)
        if row['source_882_blob'] != blobs['pr882']:
            raise ValueError('Historic PR882 source hash mismatch: ' + path)
        if row['manual_functional_signoff'] is not False:
            raise ValueError('Unexpected manual signoff: ' + path)
        if blobs['pr942'] == blobs['pr920']:
            resolution = 'pr920_exact'
        elif blobs['pr942'] == blobs['pr882']:
            resolution = 'pr882_exact'
        else:
            resolution = 'combined_or_revised'
        counts[resolution] += 1
        old_920_missing = row['source_920_blob'] is None
        old_candidate_stale = row['current_candidate_blob'] != blobs['pr942']
        counts['historic_pr920_null'] += old_920_missing
        counts['historic_candidate_stale'] += old_candidate_stale
        counts['changed_pr942_to_pr964'] += blobs['pr942'] != blobs['pr964']
        output_rows.append({
            'path': path, 'source_882': blobs['pr882'], 'source_920': blobs['pr920'],
            'reviewed_942': blobs['pr942'], 'followup_964': blobs['pr964'],
            'master_20261120': blobs.get('master_20261120'),
            'resolution_by_blob': resolution,
            'historic_920_blob_missing': old_920_missing,
            'historic_candidate_blob_stale': old_candidate_stale,
            'changed_since_942': blobs['pr942'] != blobs['pr964'],
            'semantic_review_completed': False,
        })
    return dict(counts), output_rows


def make_report(repo, matrix, trees, sitecopy=None):
    counts, rows = classify_rows(matrix, trees)
    report = {
        'contract': 'mad4b.pr942-conflict-provenance-successor.non-authorizing.v1',
        'pinned_refs': PINS,
        'historic_matrix': MATRIX,
        'total_collision_paths': len(rows),
        'counts': counts,
        'rows': rows,
        'sitecopy_is_authenticated_live_runtime': False,
        'semantic_conflict_review_completed': False,
        'release_authorized': False,
        'production_deploy_authorized': False,
    }
    if sitecopy is not None:
        status = Counter()
        for entry in sitecopy['files']:
            digest = trees['pr964'].get(PLUGIN_PREFIX + entry['path'])
            status['missing' if digest is None else 'byte_identical' if digest == entry['git_blob_sha1'] else 'different'] += 1
        report['historic_sitecopy_comparison'] = {'sitecopy_source': 'saved on-disk site-copy hash inventory, NOT independently authenticated live WordPress runtime', 'counts': dict(status)}
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[2])
    parser.add_argument('--sitecopy-inventory', type=pathlib.Path)
    parser.add_argument('--output', type=pathlib.Path)
    args = parser.parse_args()
    repo = args.repo.resolve()
    matrix = json.loads((repo / MATRIX).read_text())
    trees = {key: git_blobs(repo, sha) for key, sha in PINS.items()}
    sitecopy = json.loads(args.sitecopy_inventory.read_text()) if args.sitecopy_inventory else None
    report = make_report(repo, matrix, trees, sitecopy)
    result = json.dumps(report, sort_keys=True, indent=2) + '\n'
    if args.output:
        args.output.write_text(result)
    else:
        print(result, end='')


if __name__ == '__main__':
    main()
