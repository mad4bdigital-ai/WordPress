#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
isolation = (ROOT / 'includes/class-mad4b-scp-mcp-provider-isolation.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


require(isolation, "const CONTRACT = 'mad4b.mcp-provider-isolation.v2'", 'v2-contract')
require(isolation, "const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED'", 'explicit-enable-flag')
require(isolation, "const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED'", 'production-second-gate')
require(isolation, "'production' === $environment && ! self::production_approved()", 'production-fail-closed')
require(isolation, "add_filter( 'mcp_adapter_create_default_server'", 'default-server-suppression-hook')
require(isolation, "add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 )", 'pre-rest-adapter-suppression')
require(isolation, "add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 )", 'pre-cli-adapter-suppression')
require(isolation, "add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 )", 'late-registration-defense')
require(isolation, "add_filter( 'rest_endpoints'", 'rest-isolation-hook')
require(isolation, 'descriptor_for_route', 'exact-route-descriptors')
require(isolation, 'descriptor_for_server_callback', 'exact-server-callback-descriptors')
require(isolation, 'remove_action( \'mcp_adapter_init\'', 'server-callback-removal')
require(isolation, "'unknown_routes_fail_closed' => true", 'unknown-routes-fail-closed')
require(isolation, "'unknown_server_callbacks_fail_closed' => true", 'unknown-server-callbacks-fail-closed')
require(isolation, "'changes_provider_settings' => false", 'no-provider-settings-mutation')
require(isolation, "'disables_provider_plugins' => false", 'no-plugin-disable')
require(isolation, "'creates_authority' => false", 'no-authority-creation')

for provider in ('hostinger_ai_assistant', 'fluent_forms', 'jetengine', 'uae_hfe', 'elementskit'):
    require(isolation, f"'provider' => '{provider}'", f'provider-{provider}')

for exact_surface in (
    '/hostinger-ai-assistant/v1/mcp/', '/hostinger-ai-assistant/v1/jwt/',
    '/fluentform/v1/mcp/', '/fluentform/mcp/', '/jet-engine/v1/mcp/',
    '/jet-engine/v1/mcp-tools/', '/hfe/v1/mcp-', '/uae/mcp/',
    '/elementskit/mcp/', '/elementskit/v1/mcp-proxy/'
):
    require(isolation, exact_surface, f'route-{exact_surface}')

for exact_server in (
    'hostinger-ai-assistant-mcp-server', 'Hostinger\\\\AiAssistant\\\\Mcp\\\\McpServer', 'create_server',
    'elementskit-mcp-server', 'ElementsKit_Lite\\\\Mcp\\\\Server', 'register_server',
):
    require(isolation, exact_server, f'server-{exact_server}')

# The isolation layer is deny-only and must not make network calls, modify
# options/provider configuration, deactivate plugins, reach into the private
# MCP Adapter server registry, or create governance authority.
for forbidden in (
    'wp_remote_get(', 'wp_remote_post(', 'wp_safe_remote_get(', 'wp_safe_remote_post(',
    'update_option(', 'add_option(', 'delete_option(', 'wp_install_plugin(',
    'deactivate_plugins(', 'activate_plugin(', 'ReflectionClass', 'setAccessible(',
    'MAD4B_SCP_Agent_Registry::', 'MAD4B_SCP_Approval_Tickets::',
):
    forbid(isolation, forbidden, 'isolation-no-mutation-or-outbound')

require(bootstrap, "class-mad4b-scp-mcp-provider-isolation.php", 'bootstrap-load')
require(plugin, 'MAD4B_SCP_MCP_Provider_Isolation::boot();', 'plugin-boot')

print('mad4b.site-control-plane.mcp-provider-isolation-contract.v2: PASS')
