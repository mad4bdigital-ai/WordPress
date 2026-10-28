#!/usr/bin/env python3
"""Deterministic, unsigned *release-review* candidate from one exact Git HEAD.

The output is NOT certified, install-authorized, or a detached release receipt.
Independent approval, immutable publication and provider/source authority follow.
"""
import argparse
from pathlib import Path
import re
import sys
from build_candidate import build, git

def main():
    p = argparse.ArgumentParser()
    p.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--expected-source-sha', required=True)
    p.add_argument('--expected-version', required=True)
    args = p.parse_args()
    repo = args.repo.resolve()
    actual = git(repo, 'rev-parse', 'HEAD')
    if not re.fullmatch('[a-f0-9]{40}', args.expected_source_sha) or actual != args.expected_source_sha:
        raise RuntimeError('exact reviewed source SHA mismatch')
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+-alpha\.[0-9]+-rc\.[0-9]+', args.expected_version):
        raise RuntimeError('release-review version is invalid or locally held')
    header = (repo/'wp-content/plugins/dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php').read_text()
    if f' * Version: {args.expected_version}\n' not in header:
        raise RuntimeError('expected version does not match committed WordPress header')
    if 'DFSB_FORCE_FULL_BOOT' in header:
        raise RuntimeError('unsafe boot override in plugin header')
    build(repo, args.output, release_review=True)

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('DFSB_RELEASE_REVIEW_DENIED:', str(error), file=sys.stderr)
        sys.exit(1)
