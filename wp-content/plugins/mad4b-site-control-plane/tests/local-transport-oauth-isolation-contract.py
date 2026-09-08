#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
connection = (root / 'includes/class-mad4b-scp-connection-status.php').read_text(encoding='utf-8')
runtime = (root / 'tests/runtime-local-transport-oauth-isolation-smoke.php').read_text(encoding='utf-8')

for marker in [
    'private static function oauth_transport_enabled()',
    "defined( 'MAD4B_MCP_OAUTH_ENABLED' )",
    'if ( self::oauth_transport_enabled() ) {',
    'MAD4B_SCP_OAuth_Request_Context_Guard::boot();',
    'MAD4B_SCP_OAuth_JWT_Header_Guard::boot();',
    'MAD4B_SCP_OAuth_Resource_Bridge::boot();',
    'MAD4B_SCP_OAuth_Outbound_Budget_Guard::boot();',
    'MAD4B_SCP_OAuth_Subject_Gate::boot();',
    'MAD4B_SCP_OAuth_Challenge_Alignment::boot();',
]:
    assert marker in plugin, f'missing local/OAuth isolation marker: {marker}'

# Client-compatibility/readiness metadata remains available outside the optional
# bearer interception block.
gated = plugin.split('if ( self::oauth_transport_enabled() ) {', 1)[1].split('\n\t\t}', 1)[0]
assert 'MAD4B_SCP_MCP_Client_Compatibility::boot();' not in gated
assert 'MAD4B_SCP_MCP_Client_Compatibility::boot();' in plugin

for marker in [
    "'local_transport_ready' => empty( $local_blockers )",
    '$remote_preflight_blockers = array_merge( $local_blockers, $oauth_blockers );',
    "'transport_model' => 'wordpress-authenticated-request-plus-server-bound-mad4b-transport-context'",
]:
    assert marker in connection, f'connection readiness no longer separates local and remote auth: {marker}'

for marker in [
    'runtime-local-transport-oauth-isolation.v1',
    'OAuth resource bridge must not intercept an OAuth-disabled local transport.',
    'WordPress-authenticated admin lost local read permission.',
]:
    assert marker in runtime, f'missing runtime local transport proof: {marker}'

print('mad4b.site-control-plane.local-transport-oauth-isolation.v1: PASS')
