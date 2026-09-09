#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
bridge = (ROOT / 'includes/class-mad4b-scp-mcp-registration-bridge.php').read_text('utf-8')
diagnostics = (ROOT / 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


for marker in (
    "const CONTRACT = 'mad4b.mcp-registration-bridge.v1'",
    'public static function boot_early()',
    "did_action( 'mcp_adapter_init' ) > 0",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ), 10 )",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ), 20 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ), 10 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ), 20 )",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ), 10, 1 )",
    'public static function register_core_categories()',
    'public static function register_registry_categories()',
    'public static function register_core_abilities()',
    'public static function register_registry_abilities()',
    'self::$registry->register_defaults();',
    'self::$servers->register_servers( $adapter );',
    "'core_ability_hook_bound'",
    "'registry_ability_hook_bound'",
    "'ability_hook_bound' => $core_ability_hook_bound && $registry_ability_hook_bound",
    "'adapter_runtime_from_official_plugin'",
    "ReflectionClass( '\\\\WP\\\\MCP\\\\Core\\\\McpAdapter' )",
    "'registration_errors'",
    "'adapter_init_seen_before_bridge_boot'",
):
    require(bridge, marker, 'bridge-contract')

for stale in (
    "array( __CLASS__, 'register_categories' )",
    "array( __CLASS__, 'register_abilities' )",
):
    forbid(bridge, stale, 'no-collapsed-registration-priority')

require(bootstrap, "class-mad4b-scp-mcp-registration-bridge.php", 'bootstrap-load-bridge')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Bridge::boot_early();', 'bootstrap-early-bridge')
require(bootstrap, "class-mad4b-scp-mcp-registration-diagnostics-admin.php", 'bootstrap-load-diagnostics')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();', 'bootstrap-diagnostics')
if bootstrap.index('MAD4B_SCP_MCP_Registration_Bridge::boot_early();') > bootstrap.index("add_action( 'plugins_loaded'"):
    raise SystemExit('FAIL bridge-order: registration bridge must bind before plugins_loaded callback is registered')

require(plugin, 'MAD4B_SCP_MCP_Registration_Bridge::boot_early();', 'plugin-idempotent-bridge')
forbid(plugin, "add_action( 'mcp_adapter_init', array( $servers, 'register_servers' )", 'no-late-server-binding')
forbid(plugin, "add_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' )", 'no-late-ability-binding')
forbid(plugin, "add_action( 'wp_abilities_api_categories_init', array( $abilities, 'register_categories' )", 'no-late-category-binding')

for marker in (
    "'mad4b-control-plane-connection' !== $page",
    "'endpoints' !== $tab",
    'MAD4B_SCP_MCP_Registration_Bridge::status()',
    'Adapter runtime from official plugin',
    'Adapter init happened before bridge boot',
    'Registration error:',
):
    require(diagnostics, marker, 'diagnostics-surface')

for forbidden in (
    'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'curl_exec(', 'fsockopen(',
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->', 'activate_plugin(',
    'deactivate_plugins(', 'file_put_contents(', 'unlink(', 'rename(',
):
    forbid(bridge + '\n' + diagnostics, forbidden, 'bridge-diagnostics-read-only')

# Runtime source may be exposed only relative to WP_PLUGIN_DIR or as a bounded label.
forbid(diagnostics, 'getFileName(', 'diagnostics-do-not-resolve-path-directly')
require(bridge, "'outside-wp-plugin-dir'", 'bounded-outside-path-label')
require(bridge, "ltrim( substr( $normalized, strlen( $plugins ) ), '/' )", 'plugin-relative-runtime-source')

print('mad4b.site-control-plane.mcp-registration-bridge-contract.v2: PASS')
