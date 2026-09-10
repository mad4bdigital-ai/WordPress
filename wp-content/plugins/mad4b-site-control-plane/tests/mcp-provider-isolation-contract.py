#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
isolation = (ROOT / 'includes/class-mad4b-scp-mcp-provider-isolation.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')

PREVIOUS_MARKER = 'mad4b.site-control-plane.mcp-provider-isolation-contract.v4'


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


require(isolation, "const CONTRACT = 'mad4b.mcp-provider-isolation.v3'", 'v3-contract')
require(isolation, "const PREVIOUS_CONTRACT = 'mad4b.mcp-provider-isolation.v2'", 'previous-v2-contract')
require(isolation, "const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED'", 'explicit-enable-flag')
require(isolation, "const RUNTIME_SUPPRESSION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_RUNTIME_SUPPRESSION_APPROVED'", 'runtime-suppression-second-gate')
require(isolation, "const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED'", 'production-third-gate')
require(isolation, "const STAGING_HOST = 'staging.egypttourgates.com'", 'exact-staging-origin')
require(isolation, 'private static function bootstrap_governed_staging()', 'staging-zero-touch-bootstrap')
require(isolation, 'self::bootstrap_governed_staging();', 'early-staging-zero-touch-bootstrap')
require(isolation, "'staging' !== $environment || self::STAGING_HOST !== $host", 'exact-origin-auto-config-guard')
require(isolation, "defined( self::ENABLE_FLAG ) && true !== constant( self::ENABLE_FLAG )", 'respect-explicit-isolation-disable')
require(isolation, "defined( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG ) && true !== constant( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG )", 'respect-explicit-runtime-suppression-disable')
require(isolation, "define( self::ENABLE_FLAG, true )", 'staging-auto-enable-isolation-intent')
require(isolation, "define( self::RUNTIME_SUPPRESSION_APPROVAL_FLAG, true )", 'staging-auto-approve-runtime-suppression')
require(isolation, 'public static function runtime_suppression_approved()', 'runtime-suppression-gate-method')
require(isolation, 'if ( ! self::configured() || ! self::runtime_suppression_approved() ) return false;', 'two-gate-effective-contract')
require(isolation, "'production' === $environment && ! self::production_approved()", 'production-fail-closed')
require(isolation, "'legacy_enable_flag_alone_is_non_mutating' => true", 'legacy-flag-status-evidence')
require(isolation, "'staging_zero_touch_autoconfig_evaluated' => self::$staging_autoconfig_evaluated", 'staging-autoconfig-evaluated-evidence')
require(isolation, "'staging_zero_touch_autoconfig_applied' => self::$staging_autoconfig_applied", 'staging-autoconfig-applied-evidence')
require(isolation, "'staging_zero_touch_autoconfig_blocker' => self::$staging_autoconfig_blocker", 'staging-autoconfig-blocker-evidence')
require(isolation, "'production_auto_configured' => false", 'production-never-auto-configured')
require(isolation, "'runtime_suppression_requires_second_gate' => true", 'second-gate-status-evidence')
require(isolation, 'public static function boot_early()', 'provider-early-boot')
require(isolation, "add_filter( 'wpmedia_mcp_oauth_server_enabled'", 'wpmedia-official-kill-switch')
require(isolation, 'filter_wpmedia_oauth_server_enabled', 'wpmedia-kill-switch-callback')
require(isolation, "add_filter( 'mcp_adapter_create_default_server'", 'default-server-suppression-hook')
require(isolation, "add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_server_registrations' ), 14 )", 'pre-rest-adapter-suppression')
require(isolation, "add_action( 'init', array( __CLASS__, 'suppress_provider_server_registrations' ), 19 )", 'pre-cli-adapter-suppression')
require(isolation, "add_action( 'mcp_adapter_init', array( __CLASS__, 'suppress_provider_server_registrations' ), -1000000 )", 'late-registration-defense')
require(isolation, "add_filter( 'rest_endpoints'", 'rest-isolation-hook')
require(isolation, 'descriptor_for_route', 'exact-route-descriptors')
require(isolation, 'descriptor_for_server_callback', 'exact-server-callback-descriptors')
require(isolation, "remove_action( 'mcp_adapter_init'", 'server-callback-removal')
require(isolation, "'unknown_routes_fail_closed' => true", 'unknown-routes-fail-closed')
require(isolation, "'unknown_server_callbacks_fail_closed' => true", 'unknown-server-callbacks-fail-closed')
require(isolation, "'changes_provider_settings' => false", 'no-provider-settings-mutation')
require(isolation, "'disables_provider_plugins' => false", 'no-plugin-disable')
require(isolation, "'creates_authority' => false", 'no-authority-creation')
require(isolation, "'wpmedia_oauth_server_suppressed' => self::effective()", 'wpmedia-status-evidence')

for provider in ('hostinger_ai_assistant', 'fluent_forms', 'jetengine', 'uae_hfe', 'elementskit'):
    require(isolation, f"'provider' => '{provider}'", f'provider-{provider}')

for exact_surface in (
    '/hostinger-ai-assistant/v1/mcp/', '/hostinger-ai-assistant/v1/jwt/',
    '/fluentform/v1/mcp/', '/fluentform/mcp/', '/jet-engine/v1/mcp/',
    '/jet-engine/v1/mcp-tools/', '/hfe/v1/mcp-', '/uae/mcp/',
    '/elementskit/mcp/', '/elementskit/v1/mcp-proxy/'
):
    require(isolation, exact_surface, f'route-{exact_surface}')

# Hostinger Easy Onboarding banner state is a reviewed non-transport route and
# must remain visible; peer governance classifies it instead of isolation deleting it.
forbid(isolation, 'update-mcp-connector-banner-status', 'do-not-hide-hostinger-banner-control')

for exact_server in (
    'hostinger-ai-assistant-mcp-server', 'Hostinger\\\\AiAssistant\\\\Mcp\\\\McpServer', 'create_server',
    'elementskit-mcp-server', 'ElementsKit_Lite\\\\Mcp\\\\Server', 'register_server',
):
    require(isolation, exact_server, f'server-{exact_server}')

for forbidden in (
    'wp_remote_get(', 'wp_remote_post(', 'wp_safe_remote_get(', 'wp_safe_remote_post(',
    'update_option(', 'add_option(', 'delete_option(', 'wp_install_plugin(',
    'deactivate_plugins(', 'activate_plugin(', 'ReflectionClass', 'setAccessible(',
    'MAD4B_SCP_Agent_Registry::', 'MAD4B_SCP_Approval_Tickets::',
    "define( self::PRODUCTION_APPROVAL_FLAG, true )",
):
    forbid(isolation, forbidden, 'isolation-no-mutation-or-outbound')

require(bootstrap, "class-mad4b-scp-mcp-provider-isolation.php", 'bootstrap-load')
require(bootstrap, 'MAD4B_SCP_MCP_Provider_Isolation::boot_early();', 'bootstrap-early-kill-switch')
require(plugin, 'MAD4B_SCP_MCP_Provider_Isolation::boot();', 'plugin-boot')

print('mad4b.site-control-plane.mcp-provider-isolation-contract.v5: PASS')
