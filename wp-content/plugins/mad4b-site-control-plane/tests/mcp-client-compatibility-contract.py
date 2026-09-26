#!/usr/bin/env python3
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
compat = (root / 'includes/class-mad4b-scp-mcp-client-compatibility.php').read_text(encoding='utf-8')
challenge = (root / 'includes/class-mad4b-scp-oauth-challenge-alignment.php').read_text(encoding='utf-8')
registry = (root / 'includes/class-mad4b-scp-mcp-client-profile-registry.php').read_text(encoding='utf-8')
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text(encoding='utf-8')
transport_context = (root / 'includes/class-mad4b-scp-transport-context.php').read_text(encoding='utf-8')
main = (root / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
catalog = json.loads((root / 'config/mcp-client-profiles.json').read_text(encoding='utf-8'))

required_compat = [
    "mad4b.mcp-client-compatibility.v4",
    "WELL_KNOWN_PREFIX = '/.well-known/oauth-protected-resource'",
    "RESOURCE_PATH = '/wp-json/mcp/mad4b-chatgpt'",
    "ENROLLMENT_RESOURCE_PATH = '/wp-json/mcp/mad4b-enrollment'",
    "rest_url( 'mcp/mad4b-chatgpt' )",
    "rest_url( 'mcp/mad4b-enrollment' )",
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
    "'oauth_effective'",
    "'oauth_discovery_ready'",
    "'local_key_path_policy_ready'",
    "&& ! empty( $status['effective'] )",
    "&& self::local_key_policy_ready( $status )",
    "&& ! empty( $status['resource_transport_allowed'] )",
    "MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready()",
    "if ( ! self::oauth_discovery_ready( $status ) ) return array();",
    "'remote_oauth_read_policy'",
    "remote_oauth_read_policy_status",
    "resource_name",
    "mad4b_authority_mode",
    "authority_registry_valid",
    "subject_policy_ready",
    "authoritative_well_known_url",
    "authoritative_well_known_url( 'mad4b-enrollment' )",
    "enrollment_authoritative_well_known_url",
    "enrollment_protected_resource_metadata",
    "compatibility_alias_url",
    "manifest_endpoint",
    "detected_profile",
    "authorization_depends_on_vendor",
    "protected_resource_metadata( $resource )",
    "self::authoritative_path( 'mad4b-enrollment' )",
    "status_header( 302 )",
    "header( 'Location: ' . esc_url_raw( self::authoritative_well_known_url() ) )",
]
for marker in required_compat:
    assert marker in compat, f'missing compatibility marker: {marker}'

discovery_body = compat.split("private static function oauth_discovery_ready( $status )", 1)[1].split("private static function local_key_policy_ready", 1)[0]
assert "$status['resource_transport_allowed']" in discovery_body, 'OAuth discovery must follow the bridge transport policy'
assert "$status['https']" not in discovery_body, 'Compatibility must not re-forbid the bridge bounded Local loopback exception'

for marker in [
    "'/mcp/mad4b-chatgpt' => 'mad4b-chatgpt'",
    "'/mcp/mad4b-enrollment' => 'mad4b-enrollment'",
    "'/mcp/mad4b-developer' => 'mad4b-developer'",
    "'/mcp/mad4b-developer-breakglass' => 'mad4b-developer-breakglass'",
    "$server_id = $server_map[ $route ]",
    "MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url( $server_id )",
    "MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier( $server_id )",
    "MAD4B_SCP_OAuth_Resource_Bridge::scopes_for_resource( $resource )",
]:
    assert marker in challenge, f'missing resource-specific challenge marker: {marker}'
assert "MAD4B_SCP_OAuth_Resource_Bridge::metadata_url( $resource )" not in challenge, 'resource challenge must use canonical RFC9728 path-derived metadata'

assert "'authorization_server_external' => true" not in compat, 'external authority truth must not be hardcoded'
assert "MAD4B WordPress Staging Read MCP'" not in compat, 'resource name must not be hardcoded to Staging'

required_registry = [
    'mad4b.mcp-client-profile-registry.v1',
    'mad4b.mcp-client-profile-catalog.v1',
    'MAX_PROFILES = 50',
    'detect_request_profile',
    "apply_filters( 'mad4b_scp_mcp_client_profiles'",
    "'profiles_create_authority' => false",
    "'detection_authoritative' => false",
    "'dynamic_extension_supported' => true",
    "'authority_effect' => 'none'",
]
for marker in required_registry:
    assert marker in registry, f'missing profile registry marker: {marker}'

# Exact enrolled Staging uses one ChatGPT resource with a minimal direct
# transport. The full logical read/write universe remains discoverable, while
# large target schemas stay outside tools/list and execute only through their
# original governed WP_Ability contracts.
for marker in [
    'chatgpt_unified_catalog_enabled',
    "'staging' === MAD4B_SCP_Site_Profile::current_environment()",
    'MAD4B_SCP_Site_Profile::origin_enrolled()',
    'MAD4B_SCP_Site_Profile::site_urls_match_enrollment()',
    'public static function chatgpt_full_catalog_candidates()',
    "foreach ( array( 'read', 'content', 'admin', 'write' ) as $surface )",
    "self::core_tools( 'mad4b-enrollment' )",
    "private static function chatgpt_internal_enrollment_mutations()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_read_tools()",
    "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()",
    "MAD4B_SCP_Full_Staging_Authority::chatgpt_step_up_tools()",
    "$direct_mutation_transport = array_merge( array( 'mad4b/write-execute' ), $narrow_step_up, $full_step_up )",
    'self::external_write_tools()',
    "'mad4b/database-raw-query' === $ability_name",
    "self::core_tools( 'mad4b-breakglass' )",
]:
    assert marker in servers, f'missing minimal ChatGPT transport/logical catalog marker: {marker}'

# Low-level enrollment mutations remain present only as internal primitives and
# must be removed from both direct ChatGPT tools/list and logical user discovery.
chatgpt_tools_body = servers.split('public static function chatgpt_tools()', 1)[1].split('private static function chatgpt_internal_enrollment_mutations()', 1)[0]
assert "MAD4B_SCP_Staging_Write_Grant_Reconciliation::chatgpt_step_up_tools()" in chatgpt_tools_body, 'bounded convergence step-up projection is missing from ChatGPT transport'
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-candidate-bind'",
]:
    assert low_level not in chatgpt_tools_body, f'low-level enrollment mutation leaked into direct ChatGPT catalog: {low_level}'

internal_enrollment = servers.split('private static function chatgpt_internal_enrollment_mutations()', 1)[1].split('private static function chatgpt_enrollment_candidates()', 1)[0]
for low_level in [
    "'mad4b/site-profile-feature-reenroll'",
    "'mad4b/site-profile-write-enable'",
    "'mad4b/staging-write-grant-reconcile'",
    "'mad4b/staging-write-candidate-bind'",
]:
    assert low_level in internal_enrollment, f'internal enrollment primitive was lost: {low_level}'

logical_enrollment = servers.split('private static function chatgpt_enrollment_candidates()', 1)[1].split('public static function chatgpt_full_catalog_candidates()', 1)[0]
assert 'self::chatgpt_internal_enrollment_mutations()' in logical_enrollment

# The non-unified fallback is also a minimal transport and must never
# restore heavy filesystem/database schemas or Breakglass to tools/list.
assert 'if ( ! self::chatgpt_unified_catalog_enabled() )' in servers
fallback = servers.split('if ( ! self::chatgpt_unified_catalog_enabled() )', 1)[1].split('$bootstrap = array(', 1)[0]
for marker in [
    "$tools = array_values( array_diff( $core, $breakglass, array( 'mad4b/database-raw-query' ) ) )",
    "array_unique( array_map( 'strval', $tools ) )",
    "sort( $tools, SORT_STRING )",
    "return $tools",
]:
    assert marker in fallback, f'missing non-Staging minimal fallback marker: {marker}'
assert 'self::write_tools()' not in fallback, 'non-Staging fallback must not merge the governed write catalog directly'

# Discovery never grants execution. Every normal mutation arriving through ChatGPT
# is intercepted regardless of current write readiness and can only cross to
# mad4b-write after authority, eligibility, and mount checks succeed.
for marker in [
    "'mad4b-chatgpt' === $current && MAD4B_SCP_Servers::is_external_write_candidate( $ability_name )",
    "'mad4b_write_authority_not_ready'",
    'MAD4B_SCP_Staging_Write_Authority::effective()',
    'MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability_name )',
    "MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability_name )",
    "return 'mad4b-write';",
    "'chatgpt_write_discovery_model' => 'stable_unified_catalog_fail_closed_execution'",
]:
    assert marker in transport_context, f'missing fail-closed ChatGPT write delegation marker: {marker}'

assert "'mad4b/database-raw-query'" in servers, 'Raw SQL isolation must remain explicit'
assert "array( 'mad4b/database-raw-query' )" in servers, 'Breakglass raw SQL surface must remain isolated'

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

print('mad4b.site-control-plane.mcp-client-compatibility.v12: PASS')
