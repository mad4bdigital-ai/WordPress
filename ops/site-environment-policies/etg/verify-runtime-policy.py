#!/usr/bin/env python3
"""Read-only proof that the ETG MU policy matches its source manifest."""
import argparse
import hashlib
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

p = argparse.ArgumentParser()
p.add_argument('--source', action='store_true', help='verify local source files')
p.add_argument('--runtime', metavar='MU_DIRECTORY', help='verify exact deployed MU bytes (read-only)')
a = p.parse_args()
if not a.source and not a.runtime:
    p.error('provide --source or --runtime')
manifest = json.loads((HERE / 'policy-manifest.json').read_text())
assert manifest['contract'] == 'mad4b.etg-environment-policy.manifest.v1'
for name, expected in manifest['files'].items():
    assert '/' not in name and name.endswith('.php'), 'unexpected path'
    if a.source:
        assert digest(HERE / name) == expected, f'source drift: {name}'
    if a.runtime:
        root = Path(a.runtime).resolve(strict=True)
        target = root / name
        assert target.is_file() and not target.is_symlink(), f'unsafe runtime path: {name}'
        assert digest(target) == expected, f'runtime drift: {name}'
print('ETG_ENV_POLICY_MANIFEST: PASS')
