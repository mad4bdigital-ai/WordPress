#!/usr/bin/env python3
"""CI-only runner for the real fail-closed orphan-source PHP fixture.

No WordPress startup, token, network request or mutating entrypoint is involved.
"""
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tests/self-update-orphan-sha-readonly-runtime.php'
RUNTIME = ROOT / 'includes/class-mad4b-scp-self-update.php'


def main() -> int:
    php = shutil.which('php')
    if not php:
        print('ORPHAN_SHA_READONLY=BLOCKED: PHP CLI missing', file=sys.stderr)
        return 2
    if not (RUNTIME.is_file() and SCRIPT.is_file()):
        print('ORPHAN_SHA_READONLY=BLOCKED: source or fixture missing', file=sys.stderr)
        return 2
    source = RUNTIME.read_text()
    for token in (
        "'installed_source_unresolvable'",
        "'native_release_installed_source_unresolvable'",
        "'automatic_apply_allowed' => false",
        "'mutation_performed' => false",
    ):
        if token not in source:
            print(f'ORPHAN_SHA_READONLY=FAIL: missing source guard {token}', file=sys.stderr)
            return 1
    for f in (RUNTIME, SCRIPT):
        p = subprocess.run([php, '-l', str(f)], capture_output=True, text=True, timeout=20, check=False)
        if p.returncode != 0:
            print('ORPHAN_SHA_READONLY=FAIL: PHP lint ' + str(f) + ': ' + p.stderr, file=sys.stderr)
            return 1
    p = subprocess.run([php, str(SCRIPT)], cwd=ROOT, capture_output=True, text=True, timeout=40, check=False)
    if p.returncode or 'ETG_ORPHAN_SOURCE_READONLY_GUARD=PASS' not in p.stdout:
        print('ORPHAN_SHA_READONLY=FAIL: '+p.stdout[-900:]+p.stderr[-900:], file=sys.stderr)
        return 1
    print('ORPHAN_SHA_READONLY_CONTRACT=PASS')
    return 0


if __name__ == '__main__':
    sys.exit(main())
