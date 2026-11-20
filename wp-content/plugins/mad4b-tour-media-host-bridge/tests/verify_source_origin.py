#!/usr/bin/env python3
"""Verify staged original media provider blobs (not a production deployment grant)."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parents[2]
PROVENANCE = REPO / 'docs/source-reconciliation/tour-media-standalone-source-provenance-20261120.json'
records = json.loads(PROVENANCE.read_text())
assert records['release_authorized'] is False
assert records['runtime_deployment_authorized'] is False
assert records['source_install_requires_explicit_activation'] is True
for name, digest in records['source_file_sha256'].items():
    target = ROOT / name
    assert target.is_file(), name
    assert hashlib.sha256(target.read_bytes()).hexdigest() == digest, name
assert records['min_php_version'] == '8.1'
bootstrap = (ROOT / 'mad4b-tour-media-host-bridge.php').read_text()
assert 'Requires PHP: 8.1' in bootstrap
assert "version_compare(PHP_VERSION, '8.1', '<')" in bootstrap
assert "post_type_exists('tours-and-activities')" in bootstrap
assert "class_exists('MAD4B_SCP_Host_Agent_Bridge', false)" in bootstrap
for disallowed in ('update_option(', 'add_option(', 'wp_insert_post(', 'wp_mail(', 'shell_exec('):
    assert disallowed not in bootstrap, disallowed
core = REPO / 'wp-content/plugins/mad4b-site-control-plane/includes'
for filename in (ROOT / 'includes').glob('*.php'):
    assert not (core / filename.name).exists(), filename.name
print('PASS: 4 immutable original Media provider blobs, guarded PHP 8 plugin bootstrap, no core duplicates')
