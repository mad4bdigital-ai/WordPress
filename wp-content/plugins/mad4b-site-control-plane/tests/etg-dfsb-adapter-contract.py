#!/usr/bin/env python3
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')


def require(condition, message):
    if not condition:
        raise SystemExit('FAIL ' + message)


adapter = text('includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php')
bootstrap = text('mad4b-site-control-plane.php')
catalog = json.loads(text('config/adapter-support-catalog.json'))

require("const CONTRACT = 'mad4b.etg-dfsb-read-adapter.v1'" in adapter, 'adapter contract marker missing')
require("const SUPPORTED_VERSION = '0.4.0-alpha.13'" in adapter, 'Alpha 13 compatibility pin missing')
require("public function id() { return 'etg-dfsb'; }" in adapter, 'adapter id missing')
require("'content' => array()" in adapter and "'admin' => array()" in adapter, 'ETG adapter must remain read-only')
require("protected function mutation_requires_certification() { return false; }" in adapter, 'read-only mutation declaration missing')

for ability in (
    'etg-dfsb/status',
    'etg-dfsb/build-identity',
    'etg-dfsb/configuration',
    'etg-dfsb/runtime-inventory',
    'etg-dfsb/profiles',
    'etg-dfsb/profile-blueprint',
    'etg-dfsb/profile-plan',
    'etg-dfsb/content-catalog',
):
    require(ability in adapter, 'missing MCP ability: ' + ability)

for service in (
    'ETG\\\\DynamicFilterSEOBridge\\\\Bootstrap',
    'ETG\\\\DynamicFilterSEOBridge\\\\Config\\\\Configuration',
    'ETG\\\\DynamicFilterSEOBridge\\\\Config\\\\ProfileRegistry',
    'ETG\\\\DynamicFilterSEOBridge\\\\Diagnostics\\\\RuntimeInventory',
    'ETG\\\\DynamicFilterSEOBridge\\\\Diagnostics\\\\BuildIdentity',
    'ETG\\\\DynamicFilterSEOBridge\\\\Diagnostics\\\\InventoryProfilePlanner',
    'ETG\\\\DynamicFilterSEOBridge\\\\Presentation\\\\InventoryContentCatalog',
):
    require(service in adapter, 'missing ETG service integration: ' + service)

for marker in (
    "'authority_mode'] = 'read_only_non_authorizing'",
    "'mutation_exposed'] = false",
    "'profile_mutation_exposed'] = false",
    "'seo_publication_mutation_exposed'] = false",
    "'ajax_proxy_exposed'] = false",
    "'mounted_surface'] = 'mad4b-read'",
):
    require(marker in adapter, 'authority boundary marker missing: ' + marker)

for forbidden in (
    'update_option(', 'add_option(', 'delete_option(',
    'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(',
    '$_POST', 'admin_post_', 'AjaxPresentationEndpoint(',
    'elementor/update-widget-settings', 'mad4b-write', 'mad4b-admin',
):
    require(forbidden not in adapter, 'forbidden write/side-channel primitive: ' + forbidden)

require("includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php" in bootstrap, 'adapter bootstrap require missing')
require("mad4b_scp_register_adapters" in adapter, 'adapter registry hook missing')

families = {item.get('id'): item for item in catalog.get('families', []) if isinstance(item, dict)}
etg = families.get('etg-dfsb', {})
require(etg.get('match') == ['etg-dynamic-filter-seo-bridge/'], 'ETG plugin family match drift')
require(etg.get('adapter_id') == 'etg-dfsb', 'ETG adapter id catalog drift')
require(etg.get('strategy') == 'registered_adapter', 'ETG coverage strategy must be registered_adapter')
require(etg.get('mutation_scope') == 'none_read_only_non_authorizing', 'ETG mutation scope must remain none')
required_contracts = set(etg.get('requested_contracts', []))
for contract in (
    'status_read', 'build_identity_read', 'configuration_read', 'runtime_inventory_read', 'profile_read',
    'profile_blueprint_plan_only', 'profile_inventory_plan_only', 'dynamic_content_catalog_read',
):
    require(contract in required_contracts, 'ETG requested contract missing: ' + contract)

print('mad4b.site-control-plane.etg-dfsb-read-adapter.v1: PASS')
