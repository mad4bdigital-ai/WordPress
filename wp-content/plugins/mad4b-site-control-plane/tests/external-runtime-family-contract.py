#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
catalog = json.loads((ROOT / 'config/adapter-support-catalog.json').read_text('utf-8'))
artifacts = json.loads((ROOT / 'config/repository-plugin-artifacts.json').read_text('utf-8'))
adapter_src = (ROOT / 'includes/adapters/class-mad4b-scp-repository-family-adapter.php').read_text('utf-8')

families = {row.get('id'): row for row in catalog.get('families', []) if isinstance(row, dict)}
artifact_families = artifacts.get('families', {})

expected = {
    'hostinger-onboarding': ['hostinger-easy-onboarding/'],
    'hostinger-ai': ['hostinger-ai-assistant/'],
    'hostinger-reach': ['hostinger-reach/'],
    'duplicator': ['duplicator/'],
    'elementskit': ['elementskit-lite/'],
    'heic-support': ['heic-support/'],
    'wordpress-importer': ['wordpress-importer/'],
    'ai-engine': ['ai-engine/'],
    'external-mcp-server': ['miniorange-secure-mcp-server/'],
}

for family_id, exact_matches in expected.items():
    row = families.get(family_id)
    assert isinstance(row, dict), f'missing runtime family: {family_id}'
    assert row.get('adapter_id') == family_id, f'adapter id drift: {family_id}'
    assert row.get('strategy') == 'registered_adapter', f'strategy drift: {family_id}'
    assert row.get('mutation_scope') == 'none_read_only', f'write scope opened: {family_id}'
    assert row.get('match') == exact_matches, f'runtime identity drift: {family_id}'
    assert row.get('functional_safe_now') == ['plugin_status_read'], f'safe-now drift: {family_id}'
    assert row.get('functional_prohibited_until_certified'), f'prohibited scope missing: {family_id}'
    assert row.get('functional_evidence_requirements'), f'evidence requirements missing: {family_id}'
    assert row.get('functional_next_action'), f'next action missing: {family_id}'
    assert family_id not in artifact_families, f'runtime-only family must not fabricate repository artifacts: {family_id}'

for family_id in ('hostinger-onboarding', 'hostinger-ai', 'hostinger-reach', 'duplicator', 'elementskit', 'heic-support', 'wordpress-importer', 'ai-engine'):
    assert families[family_id].get('functional_mode') == 'contract_discovery', family_id

assert families['external-mcp-server'].get('functional_mode') == 'intentionally_restricted'
for marker in ('write_plane_exposure', 'remote_execution', 'credential_read'):
    assert marker in families['external-mcp-server'].get('functional_prohibited_until_certified', []), marker

# Generic runtime-family registration is deliberately narrow: exact self-named adapters,
# read-only mutation scope, and only contract-discovery or intentionally restricted modes.
for marker in (
    "'runtime_family_read'",
    "'runtime_match_only'",
    "'mad4b.runtime-family-read-adapter.v1'",
    "'Runtime Family Status'",
    "$family_id !== $adapter_id",
    "'none_read_only' !== $mutation_scope",
    "'contract_discovery', 'intentionally_restricted'",
    "'mutation_scope' => 'none'",
    "'artifacts' => array()",
):
    assert marker in adapter_src, marker

# Runtime matching must never create authority or mutation abilities.
assert "public function ability_names() { return array( 'read'=>array( $this->family_id . '/status' ), 'content'=>array(), 'admin'=>array() ); }" in adapter_src
assert "protected function mutation_requires_certification() { return false; }" in adapter_src
assert "'mutation_exposed'=>false" in adapter_src

print('mad4b.external-runtime-family.contract.v1: PASS')
