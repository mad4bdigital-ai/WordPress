#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
catalog = json.loads((ROOT/'config/adapter-support-catalog.json').read_text('utf-8'))
artifacts = json.loads((ROOT/'config/repository-plugin-artifacts.json').read_text('utf-8'))

families = {row.get('id'): row for row in catalog.get('families', []) if isinstance(row, dict)}
manifest = artifacts.get('families', {})

expected = {
    'bulk-taxonomy-editor': 'bulk-taxonomy-editor.zip',
    'custom-mega-menu': 'custom-mega-menu-v43.zip',
    'meta-catalog-feed-mapper': 'meta-catalog-feed-mapper-pro.zip',
    'google-tag-manager': 'duracelltomi-google-tag-manager.zip',
}
for family_id, archive in expected.items():
    row = families.get(family_id)
    assert isinstance(row, dict), f'missing provider-specific family: {family_id}'
    assert row.get('adapter_id') == family_id, f'adapter id drift: {family_id}'
    assert row.get('strategy') == 'registered_adapter', f'strategy drift: {family_id}'
    assert row.get('functional_mode') == 'review_required', f'functional mode drift: {family_id}'
    assert row.get('mutation_scope') == 'none_read_only', f'write scope opened: {family_id}'
    assert row.get('functional_rationale'), f'rationale missing: {family_id}'
    assert row.get('functional_next_action'), f'next action missing: {family_id}'

    descriptor = manifest.get(family_id)
    assert isinstance(descriptor, dict), f'artifact family missing: {family_id}'
    assert descriptor.get('support_mode') == 'family_read', f'support mode drift: {family_id}'
    assert descriptor.get('mutation_scope') == 'none', f'artifact mutation scope opened: {family_id}'
    assert descriptor.get('artifacts') == [archive], f'artifact mapping is not one-provider-one-family: {family_id}'

for removed in ('content-utilities', 'analytics'):
    assert removed not in families, f'ambiguous support family restored: {removed}'
    assert removed not in manifest, f'ambiguous artifact family restored: {removed}'

# WPL Client stays independently visible and explicitly review-required until its
# runtime/vendor contract is captured; do not fold it into a generic utilities family.
wpl = families.get('wpl-client')
assert isinstance(wpl, dict)
assert wpl.get('functional_mode') == 'review_required'
assert wpl.get('mutation_scope') == 'none_read_only'
assert wpl.get('functional_next_action') == 'capture_runtime_and_vendor_contract_before_specialized_adapter_design'

print('mad4b.provider-family-decomposition.contract.v1: PASS')
