#!/usr/bin/env python3
"""Run mapped open-issue source fixtures; never certify external acceptance."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

HERE = Path(__file__).resolve().parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    manifest = json.loads((HERE / 'open-issue-regressions.json').read_text())
    assert manifest['authorizing'] is False
    issues = manifest['issues']
    assert len({row['issue'] for row in issues}) == len(issues)
    names = sorted({name for row in issues for name in row['tests']})
    php = shutil.which('php')
    if not php:
        raise RuntimeError('PHP executor missing; no implicit skip/PASS')
    subprocess.run([php, '-r', 'exit(extension_loaded("pdo_sqlite") && extension_loaded("mbstring") ? 0 : 1);'], check=True)
    results = {}
    for name in names:
        source = HERE / name
        if source.parent != HERE or not source.is_file() or source.suffix not in ('.php', '.py'):
            raise ValueError('Invalid issue regression path: ' + name)
        command = [php if source.suffix == '.php' else sys.executable, str(source)]
        try:
            run = subprocess.run(command, cwd=HERE.parents[3], capture_output=True,
                                 text=True, timeout=90)
            results[name] = {'exit': run.returncode}
            print(('PASS ' if run.returncode == 0 else 'FAIL ') + name, flush=True)
            if run.returncode:
                print((run.stdout + run.stderr)[-8000:], flush=True)
        except subprocess.TimeoutExpired:
            results[name] = {'exit': 124, 'reason': 'fixture_timeout'}
            print('FAIL ' + name + ': fixture_timeout', flush=True)
    rows = []
    for issue in issues:
        assert issue['tests'] and issue['independent_acceptance_required']
        failed = [name for name in issue['tests'] if results[name]['exit']]
        rows.append(dict(issue, source_regressions='FAIL' if failed else 'PASS',
                         failed_tests=failed, closure='OPEN_INDEPENDENT_ACCEPTANCE_REQUIRED'))
    report = {'contract': manifest['contract'], 'tests': results, 'issues': rows,
              'ledger_issues': manifest['ledger_issues'], 'authorizing': False,
              'live_acceptance': False, 'production_authorized': False}
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(json.dumps(report, indent=2) + '\n')
    # The repository-root workflow is protected. Run dynamic, read-only GitHub
    # issue admission from this existing plugin-owned pre-Staging runner instead.
    if os.environ.get('GITHUB_ACTIONS', '').lower() == 'true' and os.environ.get('GITHUB_REPOSITORY'):
        subprocess.run([sys.executable, str(HERE / 'open-issue-live-coverage-contract.py')],
                       cwd=HERE.parents[3], check=True, timeout=20)
        if not args.output:
            raise ValueError('GitHub CI requires source-regression evidence output')
        coverage_file = args.output.parent / 'mad4b-open-issue-live-coverage.json'
        command = [sys.executable, str(HERE / 'open-issue-live-coverage.py'),
                   '--live', '--repo', os.environ['GITHUB_REPOSITORY'],
                   '--regressions', str(args.output), '--output', str(coverage_file)]
        outcome = subprocess.run(command, cwd=HERE.parents[3], check=False, timeout=90)
        if outcome.returncode:
            raise RuntimeError('GitHub issue coverage unreadable, unmapped or failing')
        coverage = json.loads(coverage_file.read_text(encoding='utf-8'))
        if coverage.get('authorizing') is not False or coverage.get('live_acceptance') is not False:
            raise RuntimeError('Unsafe live issue coverage status')
        # Reuse the existing audit artifact path, not a protected workflow edit.
        report['github_open_issue_coverage'] = coverage
        args.output.write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({'tests': len(results), 'issues': len(rows),
                      'failed': sum(bool(x['exit']) for x in results.values()),
                      'authorizing': False, 'live_acceptance': False}), flush=True)
    return int(any(x['exit'] for x in results.values()))


if __name__ == '__main__':
    sys.exit(main())
