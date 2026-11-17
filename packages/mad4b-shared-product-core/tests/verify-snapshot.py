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
# Exact successor review: a new snapshot may only inherit a proven, immutable
# prior file list. Never turn source parity into any booking/payment authority.
review_ref = manifest.get('source_successor_review')
if review_ref is None:
    raise SystemExit('SOURCE_SUCCESSOR_REVIEW_REQUIRED')
if review_ref is not None:
    if (not isinstance(review_ref, dict)
            or review_ref.get('state') != 'source_only_review_no_host_admission'
            or not isinstance(review_ref.get('sha256'), str)
            or not isinstance(review_ref.get('path'), str)
            or not review_ref['path'].startswith('docs/snapshots/')
            or '..' in Path(review_ref['path']).parts):
        raise SystemExit('UNTRUSTED_SOURCE_SUCCESSOR_REVIEW')
    review_file = root / review_ref['path']
    if not review_file.is_file() or review_file.is_symlink():
        raise SystemExit('SOURCE_SUCCESSOR_REVIEW_MISSING')
    review_bytes = review_file.read_bytes()
    if hashlib.sha256(review_bytes).hexdigest() != review_ref['sha256']:
        raise SystemExit('SOURCE_SUCCESSOR_REVIEW_DIGEST_DRIFT')
    review = json.loads(review_bytes)
    if hashlib.sha256(prev_paths[0].read_bytes()).hexdigest() != review.get('old_manifest_sha256'):
        raise SystemExit('SOURCE_SUCCESSOR_PRIOR_MANIFEST_DRIFT')
    if (review.get('contract') != 'mad4b.shared-product-core.source-successor-review.v1'
            or review.get('scope') != 'source-only ETG Lab; no tenant/runtime/Production admission'
            or review.get('current_version') != manifest.get('version')
            or review.get('prior_version') != previous.get('version')
            or review.get('old_source_hash_list_sha256') != previous_digest
            or review.get('previous_file_count') != previous.get('source_file_count')
            or review.get('current_file_count') != len(actual)
            or review.get('removed_files') != []
            or review.get('reviewed_historical_file_hashes_preserved') is not True
            or review.get('original_historical_files_changed') is not False
            or review.get('reconciliation_for_existing_live_tenant_done') is not False
            or review.get('booking_payment_or_grants_authorized') is not False
            or review.get('production_release_authorized') is not False):
        raise SystemExit('SOURCE_SUCCESSOR_REVIEW_NOT_ADMITTED')
    prior = {}
    for row in prev_paths[1].read_text().splitlines():
        sha, relative = row.split('  ', 1)
        if relative in prior:
            raise SystemExit('PRIOR_SOURCE_SUCCESSOR_DUPLICATE')
        prior[relative] = sha
    additions = sorted(set(actual) - set(prior))
    removed = sorted(set(prior) - set(actual))
    changes = sorted(k for k in set(actual) & set(prior) if actual[k] != prior[k])
    claimed_added = review.get('added_files')
    claimed_changed = review.get('changed_files')
    if not isinstance(claimed_added, list) or not isinstance(claimed_changed, list):
        raise SystemExit('SOURCE_SUCCESSOR_REVIEW_ROWS_INVALID')
    if removed or len(claimed_added) != len(additions) or len(claimed_changed) != len(changes):
        raise SystemExit('SOURCE_SUCCESSOR_UNREVIEWED_CHANGE_COUNT')
    if ([(r['path'], r['sha256']) for r in claimed_added]
            != [(k, actual[k]) for k in additions]
            or [(r['path'], r['prior_sha256'], r['current_sha256']) for r in claimed_changed]
            != [(k, prior[k], actual[k]) for k in changes]):
        raise SystemExit('SOURCE_SUCCESSOR_UNREVIEWED_CONTENT_DRIFT')
    if any(not isinstance(r.get('reason'), str) or not r['reason'].strip()
           for r in claimed_added + claimed_changed):
        raise SystemExit('SOURCE_SUCCESSOR_REASON_MISSING')

print('FROZEN_SOURCE_MANIFEST_PASS files=', len(actual),
      'version=', manifest['version'], 'prior_source_immutable=', True,
      'source_only=', True)
