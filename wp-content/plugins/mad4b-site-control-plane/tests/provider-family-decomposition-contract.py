#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
catalog = json.loads((ROOT/'config/adapter-support-catalog.json').read_text('utf-8'))
artifacts = json.loads((ROOT/'config/repository-plugin-artifacts.json').read_text('utf-8'))

families = {row.get('id'): row for row in catalog.get('families', []) if isinstance(row, dict)}
manifest = artifacts.get('families', {})
family_adapter_src = (ROOT/'includes/adapters/class-mad4b-scp-repository-family-adapter.php').read_text('utf-8')

support_rows = [row for row in catalog.get('families', []) if isinstance(row, dict)]
support_ids = {row.get('id') for row in support_rows}
support_adapter_ids = {row.get('adapter_id') for row in support_rows}
for family_id in manifest:
    assert family_id in support_ids or family_id in support_adapter_ids, (
        f'orphan repository artifact family has no support-family or adapter-id mapping: {family_id}'
    )

# These two intentionally use business-facing support-family names while keeping
# specialized adapter IDs stable. The alias must remain explicit and resolvable.
assert families.get('media-optimization', {}).get('adapter_id') == 'media'
assert families.get('rank-math', {}).get('adapter_id') == 'seo'

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
    assert row.get('functional_mode') == 'contract_discovery', f'functional mode drift: {family_id}'
    assert row.get('mutation_scope') == 'none_read_only', f'write scope opened: {family_id}'
    assert row.get('functional_rationale'), f'rationale missing: {family_id}'
    assert row.get('functional_next_action'), f'next action missing: {family_id}'
    assert row.get('functional_evidence_requirements'), f'evidence requirements missing: {family_id}'
    assert row.get('functional_safe_now') == ['plugin_status_read'], f'safe-now scope drift: {family_id}'
    assert row.get('functional_prohibited_until_certified'), f'blocked scope missing: {family_id}'
    if family_id == 'custom-mega-menu':
        assert 'custom-mega-menu-v' in row.get('match', []), 'versioned runtime family prefix missing'
        assert 'custom-mega-menu/' in row.get('match', []), 'normalized runtime slug alias missing'

    descriptor = manifest.get(family_id)
    assert isinstance(descriptor, dict), f'artifact family missing: {family_id}'
    assert descriptor.get('support_mode') == 'family_read', f'support mode drift: {family_id}'
    assert descriptor.get('mutation_scope') == 'none', f'artifact mutation scope opened: {family_id}'
    assert descriptor.get('artifacts') == [archive], f'artifact mapping is not one-provider-one-family: {family_id}'

assert "preg_quote( $base, '/' ) . '-v\\\\d+(?:\\\\.\\\\d+)*\\\\/'" in family_adapter_src, 'versioned runtime-directory normalization missing'
assert "! preg_match( '/-v\\\\d+(?:\\\\.\\\\d+)*$/'" in family_adapter_src, 'versioned archive must not double-normalize an already versioned base'

for removed in ('content-utilities', 'analytics'):
    assert removed not in families, f'ambiguous support family restored: {removed}'
    assert removed not in manifest, f'ambiguous artifact family restored: {removed}'

# WPL Client stays independently visible and explicitly contract-discovery-only until its
# runtime/vendor contract is captured; do not fold it into a generic utilities family.
wpl = families.get('wpl-client')
assert isinstance(wpl, dict)
assert wpl.get('functional_mode') == 'contract_discovery'
assert wpl.get('mutation_scope') == 'none_read_only'
assert wpl.get('functional_next_action') == 'capture_runtime_and_vendor_contract_before_specialized_adapter_design'
assert wpl.get('functional_evidence_requirements')
assert wpl.get('functional_safe_now') == ['plugin_status_read']
assert 'credential_read' in wpl.get('functional_prohibited_until_certified', [])
assert 'external_write' in wpl.get('functional_prohibited_until_certified', [])

print('mad4b.provider-family-decomposition.contract.v1: PASS')
