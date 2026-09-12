#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
guard = (ROOT / 'includes/class-mad4b-scp-mcp-runtime-conflict-guard.php').read_text('utf-8')
refresh = (ROOT / 'includes/class-mad4b-scp-mcp-mu-bootstrap-refresh.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
diagnostics = (ROOT / 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php').read_text('utf-8')
mu_bootstrap = (ROOT / 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php').read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


for marker in (
    "const CONTRACT = 'mad4b.mcp-runtime-conflict-guard.v2'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "const OFFICIAL_PLUGIN = 'mcp-adapter/mcp-adapter.php'",
    "const HOSTINGER_PREFIX = 'hostinger-ai-assistant/'",
    "const MU_BOOTSTRAP_BASENAME = '000-mad4b-mcp-adapter-bootstrap.php'",
    "const MU_BOOTSTRAP_SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'",
    "'staging' === $environment && self::STAGING_HOST === $host",
    "get_option( 'active_plugins'",
    'runtime_provenance()',
    "'runtime_from_hostinger_bundle'",
    "'runtime_provenance_mismatch'",
    'ensure_mu_bootstrap()',
    "hash_file( 'sha256'",
    'hash_equals(',
    "copy( $source, $temp )",
    "@rename( $temp, $destination )",
    "MAD4B_SCP_Audit::record(",
    "'mad4b/mcp-runtime-bootstrap-repair'",
    "'next_request_required' => true",
):
    require(guard, marker, 'conflict-guard')

for forbidden in (
    'deactivate_plugins(', 'activate_plugin(', 'delete_plugins(', 'wp_remote_get(',
    'wp_remote_post(', 'curl_exec(', 'active_sitewide_plugins', 'switch_to_blog(',
    'wp_rand(',
):
    forbid(guard, forbidden, 'bounded-repair')

for marker in (
    "'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v3'",
    "'staging' === $mad4b_mcp_mu_status['environment']",
    "'staging.egypttourgates.com' === $mad4b_mcp_mu_status['host']",
    "in_array( 'mcp-adapter/mcp-adapter.php'",
    "in_array( 'mad4b-site-control-plane/mad4b-site-control-plane.php'",
    "'WP\\\\MCP\\\\Autoloader'",
    "'WP\\\\MCP\\\\Core\\\\McpAdapter'",
    "'WP\\\\MCP\\\\Plugin'",
    "class_exists( $mad4b_mcp_mu_symbol, false )",
    "'runtime_preclaimed_before_mu_bootstrap'",
    "'preclaimed_symbol'",
    "includes/Autoloader.php",
    "includes/Core/McpAdapter.php",
    "includes/Plugin.php",
    "vendor/autoload_packages.php",
    "foreach ( $mad4b_mcp_mu_pin_files as $mad4b_mcp_mu_pin_file ) require_once $mad4b_mcp_mu_pin_file;",
    "'canonical_symbols_pinned'",
    "\\WP\\MCP\\Core\\McpAdapter::instance()",
    "'adapter_instance_armed'",
    "'adapter_init_hook'",
    "'adapter_init_hook_bound'",
    "has_action( $mad4b_mcp_mu_status['adapter_init_hook'], array( $mad4b_mcp_mu_adapter, 'init' ) )",
    "'canonical_runtime_pinned_adapter_hook_armed'",
    "'runtime_from_official_plugin'",
):
    require(mu_bootstrap, marker, 'mu-bootstrap')

for forbidden in (
    "require_once $mad4b_mcp_mu_main",
    "$mad4b_mcp_mu_root . 'mcp-adapter.php'",
    'WP\\MCP\\Plugin::instance()',
    'update_option(', 'add_option(', 'delete_option(', 'deactivate_plugins(',
    'activate_plugin(', 'delete_plugins(', 'wp_remote_get(', 'wp_remote_post(',
    'curl_exec(', '$_GET', '$_POST', '$_REQUEST',
):
    forbid(mu_bootstrap, forbidden, 'mu-bootstrap-fail-closed')

# Compatibility is semantic; PHP quote style is not part of the contract.
for marker in (
    "const CONTRACT = 'mad4b.mcp-mu-bootstrap-refresh.v1'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    'mad4b.mcp-adapter-mu-bootstrap.v2',
    'mad4b.mcp-adapter-mu-bootstrap.v3',
    "'unmanaged_mu_bootstrap_path_conflict'",
    "'mad4b/mcp-mu-bootstrap-refreshed'",
    "'managed_mu_refreshed_for_next_request'",
    "'next_request_required' => true",
):
    require(refresh, marker, 'mu-refresh')

for forbidden in (
    'deactivate_plugins(', 'activate_plugin(', 'delete_plugins(', 'wp_remote_get(',
    'wp_remote_post(', 'curl_exec(', 'active_sitewide_plugins', 'switch_to_blog(',
):
    forbid(refresh, forbidden, 'mu-refresh-bounded')

require(bootstrap, "class-mad4b-scp-mcp-mu-bootstrap-refresh.php", 'bootstrap-load-refresh')
require(bootstrap, 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();', 'bootstrap-run-refresh')
require(bootstrap, "class-mad4b-scp-mcp-runtime-conflict-guard.php", 'bootstrap-load-guard')
require(bootstrap, 'MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();', 'bootstrap-run-guard')
if bootstrap.index('MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();') > bootstrap.index('MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();'):
    raise SystemExit('FAIL bootstrap-order: stale managed MU refresh must run before conflict guard')
if bootstrap.index('MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();') > bootstrap.index('MAD4B_SCP_MCP_Registration_Bridge::boot_early();'):
    raise SystemExit('FAIL bootstrap-order: conflict guard must run before registration bridge')

for marker in (
    'REST API init count',
    'MU bootstrap source refresh applied', 'MU bootstrap refresh next request required',
    'MU bootstrap refresh state', 'MU bootstrap refresh blocker',
    'Runtime conflict guard eligible', 'Official MCP Adapter active',
    'Hostinger bundled adapter active', 'Official loads before Hostinger in active_plugins',
    'Runtime provenance mismatch', 'Runtime from Hostinger bundle',
    'Collision risk detected', 'Runtime repair applied', 'MU bootstrap present',
    'MU bootstrap integrity', 'MU bootstrap executed this request',
    'MU bootstrap runtime state', 'Next request required',
):
    require(diagnostics, marker, 'diagnostics-evidence')

print('mad4b.site-control-plane.mcp-runtime-conflict-guard-contract.v5: PASS')
