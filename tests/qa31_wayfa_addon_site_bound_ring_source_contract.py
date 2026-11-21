#!/usr/bin/env python3
"""Adversarial review: exact Git Addon Registry source, not release authority."""
import json
import hashlib
import subprocess
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa

repo = Path(__file__).resolve().parents[1]
name = 'includes/class-mad4b-scp-addon-registry.php'
manifest = repo / 'governance/release-parity/qa31-pr997-wayfa-addon-site-bound-source-20261121.json'
m = json.loads(manifest.read_text())
source = (repo / 'wp-content/plugins/mad4b-site-control-plane' / name).read_bytes()
review = qa.reviewed_pr997_wayfa_addon_site_bound_ring_source
# The original P0 contract is an immutable Git object; do not silently
# replace it with either of the newer reviewed source candidates.
original_p0_blob = '69e220a8c11a494da3216a1f98ce2cff770142ce'
original_p0 = subprocess.check_output(
    ['git', 'cat-file', '-p', original_p0_blob], cwd=repo, timeout=15)
assert hashlib.sha1(
    b'blob ' + str(len(original_p0)).encode() + b'\0' + original_p0
).hexdigest() == original_p0_blob
assert original_p0_blob not in (m['reviewed_blob_sha'], m['original_parent_blob_sha'])
assert review(repo, name, source) == m['regression']
assert review(repo, name, source + b'\n// unreviewed privilege') is None
assert review(repo, 'includes/class-mad4b-scp-skill-abilities.php', source) is None

original_read = Path.read_text
for key, value in (
    ('production_authorized', True), ('release_approved', True),
    ('host_anchor_issued', True), ('addon_activated', True),
    ('addon_deployed', True), ('execution_grant_created', True),
    ('wordpress_mutated', True), ('source_promoted', True),
    ('legacy_rings_modified', True), ('source_only', False),
    ('reviewed_for_ci_only', False), ('live_host_evidence_required', False),
    ('base_head_sha', '0' * 40), ('prior_source_review_sha256', '0' * 64),
    ('frozen_wayfatours_live_sha256', '0' * 64),
    ('original_parent_sha256', '0' * 64),
    ('original_parent_blob_sha', '0' * 40),
    ('reviewed_sha256', '0' * 64),
    ('reviewed_blob_sha', '0' * 40),
    ('release_gate', 'PRODUCTION_APPROVED'),
    ('control_ids', []), ('negative_contract', 'tests/not-real.py'),
    ('regression', 'tests/unreviewed.php')
):
    tampered = dict(m)
    tampered[key] = value
    def forged(self, *args, **kwargs):
        return json.dumps(tampered) if self.resolve() == manifest.resolve() else original_read(self, *args, **kwargs)
    with mock.patch.object(Path, 'read_text', forged):
        assert review(repo, name, source) is None, (key, value)

print('QA31_WAYFA_DYNAMIC_ADDON_SITE_BOUND: PASS (exact hashes, claims denied, no release)')
