#!/usr/bin/env python3
"""Historic 238-file contract: absent immutable Git history is HOLD, never PASS."""
from __future__ import annotations
import argparse
import ast
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
TEST = ROOT / 'tests/test_additive_mad4b_plugin_sources.py'

def snapshots() -> dict[str, str]:
    values = {}
    for node in ast.parse(TEST.read_text(encoding='utf-8')).body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            name = node.targets[0].id
            if name in ('SOURCE_SNAPSHOT', 'REVIEW_SNAPSHOT'):
                value = ast.literal_eval(node.value)
                if not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{40}', value):
                    raise ValueError('UNTRUSTED_HISTORICAL_GIT_IDENTITY')
                values[name] = value
    if set(values) != {'SOURCE_SNAPSHOT', 'REVIEW_SNAPSHOT'}:
        raise ValueError('HISTORICAL_SOURCE_SNAPSHOT_INCOMPLETE')
    return values

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    refs = snapshots()
    missing = []
    for label, sha in refs.items():
        check = subprocess.run(['git', 'cat-file', '-e', sha + '^{commit}'],
                               cwd=ROOT, capture_output=True, timeout=15)
        if check.returncode:
            missing.append(label)
    output = {
        'contract':'mad4b.historical-additive-source-ci.v1',
        'reviewed_source_identities': refs,
        'unavailable_source_identities': missing,
        'source_test_cases_passed': 0,
        'original_host_authority_verified': False,
        'production_authorized': False,
        'release_authorized': False,
        'state': 'HOLD__HISTORICAL_GIT_OBJECTS_UNAVAILABLE' if missing else 'HISTORICAL_SOURCE_TEST_PENDING',
    }
    args.report.parent.mkdir(parents=True, exist_ok=True)
    if missing:
        args.report.write_text(json.dumps(output, indent=2) + '\n')
        print('HISTORICAL_SOURCE_EVIDENCE_HOLD: GitHub Runner lacks immutable reviewed ancestors; 0 tests executed')
        return 2
    result = subprocess.run(
        [sys.executable, '-m', 'unittest', 'discover', '-s', 'tests',
         '-p', 'test_additive_mad4b_plugin_sources.py', '-v'],
        cwd=ROOT, capture_output=True, text=True, timeout=90,
    )
    passed = result.returncode == 0 and 'Ran 6 tests' in result.stderr and '\nOK' in result.stderr
    output['source_test_cases_passed'] = 6 if passed else 0
    output['state'] = 'SOURCE_ONLY_PASS_HOST_AUTHORITY_HOLD' if passed else 'FAIL__HISTORICAL_SOURCE_REGRESSION'
    args.report.write_text(json.dumps(output, indent=2) + '\n')
    if not passed:
        print('HISTORICAL_SOURCE_CONTRACT_FAILED: reviewed historical fixture failed')
        return 3
    print('HISTORICAL_SOURCE_6_TESTS_PASS; CI source-only, no Host/Owner release authority')
    return 0

if __name__ == '__main__':
    sys.exit(main())
