"""Ensure the 19 source modules promoted from both active runtimes stay byte-exact.

This is a source custody test, not authorization to install MU modules on a new site.
"""
import hashlib
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
manifest=json.loads((root/'ops/media-source-ownership/fleet-active-media-sources-20261116.json').read_text())
assert manifest['contract']=='mad4b.media.fleet-shared-source-hashes.v1'
items=manifest['files']
assert len(items)==19
for item in items:
    rel=Path(item['path'])
    assert not rel.is_absolute() and '..' not in rel.parts
    source=root/'wp-content'/rel
    assert source.is_file() and not source.is_symlink()
    assert hashlib.sha256(source.read_bytes()).hexdigest()==item['sha256'],str(rel)
    assert item['source_ownership']=='active_fleet_shared_media_module'
assert manifest['not_automatic_new_site_mu_activation'] is True
print('PASS 19 shared currently-active media source modules have stable SHA-256')
