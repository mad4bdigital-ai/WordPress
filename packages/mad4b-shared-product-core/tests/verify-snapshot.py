#!/usr/bin/env python3
"""Verify every and only canonical Local Lab source file is present and unchanged."""
import hashlib
from pathlib import Path

root = Path(__file__).resolve().parents[1]
source = root / 'mu-plugins'
rows = (root / 'source-files.sha256').read_text().splitlines()
expected = {}
for line in rows:
    digest, relative = line.split('  ', 1)
    if relative in expected:
        raise SystemExit('DUPLICATE SOURCE ' + relative)
    expected[relative] = digest
actual = {}
for path in source.rglob('*'):
    if path.is_file():
        rel = path.relative_to(source).as_posix()
        actual[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
if expected != actual:
    missing = sorted(set(expected)-set(actual))
    extra = sorted(set(actual)-set(expected))
    changed = sorted(k for k in set(expected)&set(actual) if expected[k] != actual[k])
    raise SystemExit(f'SNAPSHOT DRIFT missing={missing} extra={extra} changed={changed}')
if any('.before-' in path for path in actual):
    raise SystemExit('BACKUP SOURCE LEAK')
print('FROZEN_SOURCE_MANIFEST_PASS files=', len(actual))
