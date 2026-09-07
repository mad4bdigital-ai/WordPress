#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
compat = (root / 'includes/class-mad4b-scp-mcp-client-compatibility.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')

required = [
    "mad4b.mcp-client-compatibility.v1",
    "WELL_KNOWN_PREFIX = '/.well-known/oauth-protected-resource'",
    "RESOURCE_PATH = '/wp-json/mcp/mad4b-read'",
    "'openai_chatgpt'",
    "'anthropic_claude'",
    "'google_gemini'",
    "'manus'",
    "'generic_mcp_client'",
    "'client_agnostic' => true",
    "'client_profiles_create_authority' => false",
    "'client_vendor_required_for_authorization' => false",
    "'transport' => 'streamable_http'",
    "'oauth_resource_metadata' => 'rfc9728'",
    "metadata_for_path",
    "serve_well_known_metadata",
]
for marker in required:
    assert marker in compat, f'missing compatibility marker: {marker}'

assert "class-mad4b-scp-mcp-client-compatibility.php" in main
assert "MAD4B_SCP_MCP_Client_Compatibility::boot();" in plugin

for forbidden in [
    'MAD4B_MCP_CHATGPT_ONLY',
    'MAD4B_MCP_CLAUDE_ONLY',
    'MAD4B_MCP_GEMINI_ONLY',
    'MAD4B_MCP_MANUS_ONLY',
    'create_credentials',
    'update_option(',
]:
    assert forbidden not in compat, f'forbidden client-specific authority marker: {forbidden}'

print('mad4b.site-control-plane.mcp-client-compatibility.v1: PASS')
