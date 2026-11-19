#!/usr/bin/env python3
"""Verify every and only canonical Local Lab source file is present and unchanged."""
import hashlib
import json
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

# Current source-only manifest must match the exact bytes just verified.
# A prior reviewed snapshot is immutable evidence, not a deployment candidate.
manifest = json.loads((root / 'release-manifest.json').read_text())
source_bytes = (root / 'source-files.sha256').read_bytes()
if (manifest.get('status') != 'source-only-candidate'
        or manifest.get('deploy_automatically') is not False
        or manifest.get('source_file_count') != len(actual)
        or manifest.get('source_manifest_sha256') != hashlib.sha256(source_bytes).hexdigest()):
    raise SystemExit('CURRENT_SOURCE_ONLY_RELEASE_FENCE_OR_DIGEST_INVALID')
lineage = manifest.get('source_snapshot_lineage')
if not isinstance(lineage, dict) or lineage.get('state') != 'source_only_not_deployment':
    raise SystemExit('HISTORICAL_SOURCE_LINEAGE_REQUIRED')
prev_paths = []
for key in ('prior_manifest_path', 'prior_source_hash_path'):
    raw = lineage.get(key)
    if not isinstance(raw, str) or not raw.startswith('docs/snapshots/') or '..' in Path(raw).parts:
        raise SystemExit('UNTRUSTED_SOURCE_HISTORY_PATH')
    path = root / raw
    if path.is_symlink() or not path.is_file():
        raise SystemExit('MISSING_IMMUTABLE_SOURCE_HISTORY')
    prev_paths.append(path)
previous = json.loads(prev_paths[0].read_text())
previous_digest = hashlib.sha256(prev_paths[1].read_bytes()).hexdigest()
if (previous.get('version') != lineage.get('prior_version')
        or previous.get('status') != 'source-only-candidate'
        or previous.get('deploy_automatically') is not False
        or previous.get('source_manifest_sha256') != previous_digest
        or lineage.get('prior_source_manifest_sha256') != previous_digest
        or len(prev_paths[1].read_text().splitlines()) != previous.get('source_file_count')
        or previous.get('version') == manifest.get('version')):
    raise SystemExit('HISTORICAL_SOURCE_LINEAGE_DRIFT')
print('FROZEN_SOURCE_MANIFEST_PASS files=', len(actual),
      'version=', manifest['version'], 'prior_source_immutable=', True,
      'source_only=', True)
