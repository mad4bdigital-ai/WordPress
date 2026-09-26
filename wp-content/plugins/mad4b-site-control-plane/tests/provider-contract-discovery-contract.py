#!/usr/bin/env python3
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
support=json.loads((ROOT/'config/adapter-support-catalog.json').read_text('utf-8'))
artifacts=json.loads((ROOT/'config/repository-plugin-artifacts.json').read_text('utf-8'))
discovery=(ROOT/'includes/class-mad4b-scp-plugin-discovery.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')

families={item['id']:item for item in support.get('families',[]) if isinstance(item,dict) and item.get('id')}
expected={
    'bulk-taxonomy-editor',
    'custom-mega-menu',
    'meta-catalog-feed-mapper',
    'google-tag-manager',
    'wpl-client',
}

assert 'content-utilities' not in families
assert 'analytics' not in families

for family_id in sorted(expected):
    assert family_id in families, family_id
    row=families[family_id]
    assert row.get('functional_mode') == 'contract_discovery', family_id
    assert row.get('mutation_scope') == 'none_read_only', family_id
    assert row.get('requested_contracts') == ['plugin_status_read'], family_id
    evidence=row.get('functional_evidence_requirements') or []
    safe=row.get('functional_safe_now') or []
    blocked=row.get('functional_prohibited_until_certified') or []
    assert evidence and all(isinstance(v,str) and v for v in evidence), family_id
    assert safe == ['plugin_status_read'], family_id
    assert blocked and all(isinstance(v,str) and v for v in blocked), family_id
    assert row.get('functional_next_action'), family_id
    assert row.get('functional_rationale'), family_id
    artifact=artifacts.get('families',{}).get(family_id) or {}
    assert artifact.get('support_mode') == 'family_read', family_id
    assert artifact.get('mutation_scope') == 'none', family_id
    assert artifact.get('artifacts'), family_id

assert 'credential_read' in families['wpl-client']['functional_prohibited_until_certified']
assert 'external_write' in families['wpl-client']['functional_prohibited_until_certified']
assert 'feed_publish' in families['meta-catalog-feed-mapper']['functional_prohibited_until_certified']
assert 'container_publish' in families['google-tag-manager']['functional_prohibited_until_certified']
assert 'bulk_taxonomy_write' in families['bulk-taxonomy-editor']['functional_prohibited_until_certified']
assert 'menu_structure_write' in families['custom-mega-menu']['functional_prohibited_until_certified']


for marker in [
    'public static function contract_discovery_report()',
    "'contract' => 'mad4b.provider-contract-discovery.v1'",
    "'read_only' => true",
    "'active_only' => true",
    "'network_request_sent' => false",
    "'credential_material_exposed' => false",
    "'authority_created' => false",
    "'mutation_default' => 'deny'",
    "'evidence_requirements'",
    "'safe_now'",
    "'prohibited_until_certified'",
    "'adapter_contract'",
    "'adapter_runtime_source'",
    "'repository_artifact_backed'",
    "'runtime_identities'",
    "'plugin_version'",
]:
    assert marker in discovery, marker

assert "mad4b/provider-contract-discovery" in registry
assert "provider_contract_discovery" in registry
assert "'mad4b/provider-contract-discovery'" in registry
assert "MAD4B_SCP_Plugin_Discovery::contract_discovery_report()" in registry
assert "'mad4b/provider-functional-coverage'" in registry
assert "'mad4b/provider-contract-discovery'" in registry
assert "ability_names( $surface )" in registry

print('mad4b.provider-contract-discovery.contract.v1: PASS')
