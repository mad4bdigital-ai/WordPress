#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
connection = (root / 'includes/class-mad4b-scp-connection-status.php').read_text(encoding='utf-8')
compat = (root / 'includes/class-mad4b-scp-mcp-client-compatibility.php').read_text(encoding='utf-8')
runtime_disabled = (root / 'tests/runtime-local-transport-oauth-isolation-smoke.php').read_text(encoding='utf-8')
runtime_misconfigured = (root / 'tests/runtime-local-transport-oauth-misconfigured-smoke.php').read_text(encoding='utf-8')

for marker in [
    'private static function oauth_transport_enabled()',
    "defined( 'MAD4B_MCP_OAUTH_ENABLED' )",
    "add_action( 'init', array( __CLASS__, 'boot_oauth_transport_if_effective' ), 3 )",
    'public static function boot_oauth_transport_if_effective()',
    "empty( $status['effective'] )",
    'MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready()',
    'MAD4B_SCP_OAuth_Request_Context_Guard::boot();',
    'MAD4B_SCP_OAuth_JWT_Header_Guard::boot();',
    'MAD4B_SCP_OAuth_Resource_Bridge::boot();',
    'MAD4B_SCP_OAuth_Outbound_Budget_Guard::boot();',
    'MAD4B_SCP_OAuth_Subject_Gate::boot();',
    'MAD4B_SCP_OAuth_Challenge_Alignment::boot();',
]:
    assert marker in plugin, f'missing local/OAuth isolation marker: {marker}'

# Readiness/client metadata is always available, but bearer interception is
# activated only after the authority graph and local signing-key policy are
# effective.
assert 'MAD4B_SCP_MCP_Client_Compatibility::boot();' in plugin
method = plugin.split('public static function boot_oauth_transport_if_effective()', 1)[1].split('private static function oauth_transport_enabled()', 1)[0]
assert 'MAD4B_SCP_MCP_Client_Compatibility::boot();' not in method
assert "empty( $status['effective'] )" in method
assert 'MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready()' in method

for marker in [
    "'local_transport_ready' => empty( $local_blockers )",
    '$remote_preflight_blockers = array_merge( $local_blockers, $oauth_blockers );',
    "'transport_model' => 'wordpress-authenticated-request-plus-server-bound-mad4b-transport-context'",
]:
    assert marker in connection, f'connection readiness no longer separates local and remote auth: {marker}'

for marker in [
    "&& ! empty( $status['effective'] )",
    'self::local_key_policy_ready( $status )',
    'MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready()',
    'if ( ! self::oauth_discovery_ready( $status ) ) return array();',
    'mad4b_oauth_resource_discovery_not_ready',
]:
    assert marker in compat, f'ineffective OAuth discovery is not fail-closed: {marker}'

for marker in [
    'runtime-local-transport-oauth-isolation.v1',
    'OAuth resource bridge must not intercept an OAuth-disabled local transport.',
    'WordPress-authenticated admin lost local read permission.',
]:
    assert marker in runtime_disabled, f'missing OAuth-disabled local transport proof: {marker}'

for marker in [
    'runtime-local-transport-oauth-misconfigured.v2',
    'Ineffective OAuth must not intercept local MCP transport.',
    'Configured-but-ineffective OAuth disabled local WordPress read permission.',
    'oauth_wp_subject_unconfigured',
    'Ineffective OAuth published protected-resource metadata as ready.',
    'Compatibility manifest advertised authorization servers for ineffective OAuth.',
]:
    assert marker in runtime_misconfigured, f'missing ineffective-OAuth local transport proof: {marker}'

print('mad4b.site-control-plane.local-transport-oauth-isolation.v4: PASS')
