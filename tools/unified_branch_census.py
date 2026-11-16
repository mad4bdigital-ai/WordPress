#!/usr/bin/env python3
"""Read-only branch divergence census; never approves, merges, or deletes.

A matching tip change is NOT proof the branch's earlier behavior is present.
Every non-ancestor ref stays a review candidate until independently certified.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import subprocess

def git(repo: Path, *args: str, permitted=(0,)):
    process = subprocess.run(
        ['git', '-C', str(repo), *args],
        capture_output=True, text=True, check=False, timeout=65,
    )
    if process.returncode not in permitted:
        raise RuntimeError('GIT_CENSUS_FAILED:' + args[0])
    return process.returncode, process.stdout.strip()

def blob(repo: Path, sha: str, path: str):
    if not path or path.startswith(('/', '-')):
        return None
    code, data = git(repo, 'rev-parse', '--verify', sha + ':' + path,
                     permitted=(0, 128))
    return data if code == 0 and re.fullmatch('[a-f0-9]{40}', data) else None

def build(repo: Path, candidate_ref: str = 'HEAD', tip_limit: int = 24):
    _, head = git(repo, 'rev-parse', '--verify', candidate_ref + '^{commit}')
    _, name = git(repo, 'branch', '--show-current')
    _, refs = git(repo, 'for-each-ref',
                  '--format=%(refname:short)%09%(objectname)%09%(committerdate:unix)',
                  'refs/heads')
    rows = []
    for record in refs.splitlines():
        branch, commit, changed = record.split('\t')
        if commit == head:
            continue
        rc, _ = git(repo, 'merge-base', '--is-ancestor', commit, head,
                    permitted=(0, 1))
        if rc == 0:
            rows.append({'branch': branch, 'tip': commit,
                         'classification': 'contained_ancestor',
                         'unique_commits': 0})
            continue
        _, divergence = git(repo, 'rev-list', '--left-right', '--count',
                            head + '...' + commit)
        behind, unique = [int(x) for x in divergence.split()]
        _, subject = git(repo, 'log', '-1', '--format=%s', commit)
        _, filenames = git(repo, 'diff-tree', '--root', '--first-parent',
                           '--no-commit-id', '--name-only', '-r',
                           '--no-renames', commit)
        names = sorted(set(filenames.splitlines()))
        different = [
            path for path in names[:tip_limit]
            if blob(repo, commit, path) != blob(repo, head, path)
        ]
        rows.append({
            'branch': branch, 'tip': commit, 'tip_subject': subject,
            'tip_utc': datetime.fromtimestamp(int(changed), timezone.utc).isoformat(),
            'classification': 'divergent_review_required',
            'unique_commits': unique, 'candidate_commits_not_on_branch': behind,
            'tip_paths_total': len(names),
            'tip_paths_sampled': min(tip_limit, len(names)),
            'tip_paths_differ': different,
            'tip_comparison_truncated': len(names) > tip_limit,
            'whole_branch_semantic_parity_unverified': True,
        })
    pending = sorted(
        (row for row in rows if row['classification'] == 'divergent_review_required'),
        key=lambda row: row['tip_utc'], reverse=True,
    )
    stable = sorted(
        (row for row in rows if row['classification'] == 'contained_ancestor'),
        key=lambda row: row['branch'],
    )
    cutoff = datetime.now(timezone.utc).timestamp() - 172800
    return {
        'contract': 'mad4b.unified-local-branch-census-readonly.v1',
        'candidate': head, 'current_worktree_branch': name,
        'observed_utc': datetime.now(timezone.utc).isoformat(),
        'counts': {
            'other_local_refs': len(rows),
            'contained_ancestors': len(stable),
            'divergent_review_required': len(pending),
            'recent_divergent_refs_48h': sum(
                datetime.fromisoformat(row['tip_utc']).timestamp() >= cutoff
                for row in pending
            ),
        },
        'branches': pending + stable,
        'auto_merge_authorized': False,
        'auto_delete_authorized': False,
        'signed_release_authorized': False,
        'source_coverage_proven_by_this_census': False,
        'warning': ('Tip byte comparison is not whole-branch semantic proof; '
                    'preserve divergent refs until independently reviewed.'),
    }

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', type=Path, required=True)
    parser.add_argument('--candidate', default='HEAD')
    parser.add_argument('--output', type=Path, required=True)
    opt = parser.parse_args()
    report = build(opt.repo, opt.candidate)
    opt.output.parent.mkdir(parents=True, exist_ok=True)
    opt.output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    print(json.dumps({
        'state': 'BRANCH_CENSUS_ONLY__SEMANTIC_REVIEW_REQUIRED',
        'candidate': report['candidate'],
        'counts': report['counts'],
        'auto_merge_authorized': False, 'auto_delete_authorized': False,
    }, sort_keys=True))

if __name__ == '__main__':
    main()
