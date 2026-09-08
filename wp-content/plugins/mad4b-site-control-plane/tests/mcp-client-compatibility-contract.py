#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
compat = (root / 'includes/class-mad4b-scp-mcp-client-compatibility.php').read_text(encoding='utf-8')
registry = (root / 'includes/class-mad4b-scp-mcp-client-profile-registry.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
catalog = json.loads((root / 'config/mcp-client-profiles.json').read_text(encoding='utf-8'))

required_compat = [
    "mad4b.mcp-client-compatibility.v3",
    "WELL_KNOWN_PREFIX = '/.well-known/oauth-protected-resource'",
    "RESOURCE_PATH = '/wp-json/mcp/mad4b-read'",
    "MANIFEST_ROUTE = '/client-compatibility'",
    "'client_agnostic' => true",
    "'client_profiles_create_authority' => false",
    "'client_vendor_required_for_authorization' => false",
    "'unknown_clients_supported' => true",
    "'transport' => 'streamable_http'",
    "'oauth_resource_metadata' => 'rfc9728'",
    "'oauth_authority_mode'",
    "'authorization_server_external'",
    "'authorization_server_local'",
    "'authorization_server_hybrid'",
    "'authorization_server_count'",
    "'remote_oauth_read_policy'",
    "remote_oauth_read_policy_status",
    "resource_name",
    "mad4b_authority_mode",
    "authority_registry_valid",
    "subject_policy_ready",
    "authoritative_well_known_url",
    "compatibility_alias_url",
    "manifest_endpoint",
    "detected_profile",
    "authorization_depends_on_vendor",
    "status_header( 302 )",
    "header( 'Location: ' . esc_url_raw( self::authoritative_well_known_url() ) )",
]
for marker in required_compat:
    assert marker in compat, f'missing compatibility marker: {marker}'

assert "'authorization_server_external' => true" not in compat, 'external authority truth must not be hardcoded'
assert "MAD4B WordPress Staging Read MCP'" not in compat, 'resource name must not be hardcoded to Staging'

required_registry = [
    'mad4b.mcp-client-profile-registry.v1',
    'mad4b.mcp-client-profile-catalog.v1',
    'MAX_PROFILES = 50',
    'detect_request_profile',
    'apply_filters( \'mad4b_scp_mcp_client_profiles\'',
    "'profiles_create_authority' => false",
    "'detection_authoritative' => false",
    "'dynamic_extension_supported' => true",
    "'authority_effect' => 'none'",
]
for marker in required_registry:
    assert marker in registry, f'missing profile registry marker: {marker}'

assert "class-mad4b-scp-mcp-client-profile-registry.php" in main
assert "class-mad4b-scp-mcp-client-compatibility.php" in main
assert "MAD4B_SCP_MCP_Client_Compatibility::boot();" in plugin

assert catalog.get('contract') == 'mad4b.mcp-client-profile-catalog.v1'
assert catalog.get('default_profile') == 'generic-mcp'
profiles = catalog.get('profiles', [])
ids = {x.get('id') for x in profiles}
for expected in ['openai-chatgpt', 'anthropic-claude', 'google-gemini', 'manus', 'generic-mcp']:
    assert expected in ids, f'missing client catalog profile: {expected}'
for profile in profiles:
    assert profile.get('authority_effect') == 'none', f'profile creates authority: {profile.get("id")}'
    assert 'streamable_http' in profile.get('transports', []), f'profile lacks Streamable HTTP: {profile.get("id")}'

for forbidden in [
    'MAD4B_MCP_CHATGPT_ONLY',
    'MAD4B_MCP_CLAUDE_ONLY',
    'MAD4B_MCP_GEMINI_ONLY',
    'MAD4B_MCP_MANUS_ONLY',
    'create_credentials',
    'update_option(',
    'wp_set_current_user(',
]:
    assert forbidden not in compat, f'forbidden client-specific authority marker: {forbidden}'
    assert forbidden not in registry, f'forbidden registry authority marker: {forbidden}'

print('mad4b.site-control-plane.mcp-client-compatibility.v3: PASS')
