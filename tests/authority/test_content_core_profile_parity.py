#!/usr/bin/env python3
"""Source-neutral checks; never reads a live database or grants mutation rights."""
from __future__ import annotations
import json
from pathlib import Path

root = Path(__file__).resolve().parents[2]
location = root / 'ops/company-reconcile/content-core-profiles'
manifest = json.loads((location / 'blueprint.json').read_text())
php = (location / 'reconcile.php').read_text()
assert manifest['contract'] == 'mad4b.content-core-blueprint.v1'
assert manifest['policy']['all_new_posts'] == 'draft'
assert manifest['policy']['published_update_mode'] == 'draft_first'
assert manifest['policy']['write_grants_issued'] is False
assert manifest['policy']['content_migration_allowed'] is False
p = manifest['profiles']
assert len(p) == 5
expected = {'page', 'post', 'properties', 'tours-and-activities', 'transportations'}
assert {row['slug'] for row in p} == expected
assert {row['post_type'] for row in p} == expected
for row in p:
    assert row['creation_status'] == 'draft'
    assert row['live_update_mode'] == 'draft_first'
    assert row['meta_mode'] == 'allowlist'
    assert row['taxonomy_mode'] == 'allowlist'
    assert row['enabled'] is True
    assert len(row['taxonomies']) == len(set(row['taxonomies']))
    assert all(not key.startswith('_') for key in row['meta_keys'])
    # Target-specific taxonomy differences are narrowed, never widened.
    for scoped in [set(row['taxonomies']), set(row['taxonomies'][1::2])]:
        assert scoped.issubset(set(row['taxonomies']))
        assert len(scoped) <= len(row['taxonomies'])
    # The installed source revision can differ from the company's revision.
    assert 'revision' not in row and 'routes' not in row and 'authority_sha256' not in row
assert set(next(x for x in p if x['slug']=='tours-and-activities')['meta_keys']) == {'includes','excludes','tour_mode','tour_sub_title'}
serialized = json.dumps(manifest).lower()
for forbidden in ['wayfatours.com', 'tours.dev.mad4b.com', 'site_uuid', 'api_key', 'secret', 'oauth', 'password']:
    assert forbidden not in serialized, forbidden
for guard in [
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::site_urls_match_enrollment()",
    'get_current_user_id() !== 0',
    "MAD4B_CONTENT_CORE_PARITY_CONFIRM",
    "MAD4B_CONTENT_CORE_PARITY_SITE_UUID",
    "MAD4B_CONTENT_CORE_PARITY_ORIGIN",
    "MAD4B_SCP_Audit::storage_status()",
    "MAD4B_SCP_Content_Experience_Profiles::profile_plan",
    "MAD4B_SCP_Content_Experience_Profiles::profile_apply",
    "existing_profile_drift_",
    "preserve_existing",
    "get_object_taxonomies",
    "delete_option( $option )",
    "readback_failed_rolled_back",
]:
    assert guard in php, guard
assert "'production'" in php and "'staging'" in php
assert "update_option( $option, $before, false )" in php
assert 'grant_mutation_performed' in php and 'content_mutation_performed' in php
print('PASS: five neutral core profiles, site-scoped taxonomy, no grant/overwrite, planned and guarded apply')
