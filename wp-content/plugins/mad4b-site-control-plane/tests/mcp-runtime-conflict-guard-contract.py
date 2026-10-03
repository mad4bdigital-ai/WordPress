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
    "const OFFICIAL_PLUGIN = 'mcp-adapter/mcp-adapter.php'",
    "const HOSTINGER_PREFIX = 'hostinger-ai-assistant/'",
    "const MU_BOOTSTRAP_BASENAME = '000-mad4b-mcp-adapter-bootstrap.php'",
    "const MU_BOOTSTRAP_SOURCE = 'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'",
    "MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' )",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::managed_runtime_enabled()",
    "get_option( 'active_plugins'",
    'runtime_provenance()',
    "'runtime_from_hostinger_bundle'",
    "'runtime_provenance_mismatch'",
    "'runtime_class_provenance_enforced'",
    "'runtime_class_provenance_ready'",
    "'runtime_class_provenance_state'",
    "'runtime_class_provenance_failure_count'",
    "! empty( $status['runtime_class_provenance_failure_count'] )",
    "'mixed_runtime_class_set'",
    "'mcp_adapter_class_provenance_mismatch'",
    "MAD4B_SCP_MCP_Class_Provenance::status( false, false )",
    'ensure_mu_bootstrap()',
    "hash_file( 'sha256'",
    'hash_equals(',
    "copy( $source, $temp )",
    "@rename( $temp, $destination )",
    "MAD4B_SCP_Audit::record(",
    "'mad4b/mcp-runtime-bootstrap-repair'",
    "'next_request_required' => true",
    "official_plugin_identity",
    "'official_plugin_identity_ambiguous'",
    "'official_mcp_adapter_identity_ambiguous'",
    "dirname( $official_plugin )",
):
    require(guard, marker, 'conflict-guard')
if "realpath( trailingslashit( WP_PLUGIN_DIR ) . 'mcp-adapter' )" in guard:
    raise SystemExit('FAIL conflict-guard: renamed MCP directories regressed to a hardcoded root')

for forbidden in (
    'deactivate_plugins(', 'activate_plugin(', 'delete_plugins(', 'wp_remote_get(',
    'wp_remote_post(', 'curl_exec(', 'active_sitewide_plugins', 'switch_to_blog(',
    'wp_rand(',
):
    forbid(guard, forbidden, 'bounded-repair')

repair_lifecycle = guard.split('private static function repair_lifecycle_allowed()', 1)[1].split('private static function mu_bootstrap_status()', 1)[0]
for marker in (
    "array( 'update.php', 'update-core.php', 'plugin-install.php', 'plugins.php' )",
    "current_user_can( 'update_plugins' )",
    "defined( 'WP_CLI' )",
    "wp_doing_cron()",
):
    require(repair_lifecycle, marker, 'repair-lifecycle')
for forbidden in (
    "explicit_rest_materialization_allowed()",
    "MAD4B_SCP_Endpoint_Diagnostic::is_authorized_request()",
):
    forbid(repair_lifecycle, forbidden, 'diagnostics-remain-read-only')

for marker in (
    "'contract' => 'mad4b.mcp-adapter-mu-bootstrap.v6'",
    "MAD4B_SCP_Site_Profile::early_managed_runtime_binding()",
    "'plugin_directory_discovery' => 'active_plugins_unique_main_file'",
    "$mad4b_mcp_mu_find_active",
    "'mcp-adapter.php'",
    "'mad4b-site-control-plane.php'",
    "'plugin_identity_ambiguous'",
    "$mad4b_mcp_mu_control_plane_root . 'config/certified-providers.json'",
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
    "'critical_class_baseline_ready'",
    "'critical_class_set_pinned'",
    "'critical_class_pin_count'",
    "'critical_class_pin_failed_symbol'",
    "$mad4b_mcp_mu_critical_classes = array(",
    "includes/Domain/Tools/RegisterAbilityAsMcpTool.php",
    "includes/Domain/Tools/McpToolValidator.php",
    "includes/Domain/Utils/SchemaTransformer.php",
    "includes/Domain/Utils/McpAnnotationMapper.php",
    "includes/Domain/Utils/McpValidator.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/Tool.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolInputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolOutputSchema.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolAnnotations.php",
    "vendor/wordpress/php-mcp-schema/src/Server/Tools/DTO/ToolExecution.php",
    "'critical_class_baseline_mismatch'",
    "'critical_class_source_not_official'",
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
    'curl_exec(', '$_GET', '$_REQUEST',
):
    forbid(mu_bootstrap, forbidden, 'mu-bootstrap-fail-closed')

# Compatibility is semantic; PHP quote style is not part of the contract.
for marker in (
    "const CONTRACT = 'mad4b.mcp-mu-bootstrap-refresh.v1'",
    "MAD4B_SCP_Site_Profile::nonproduction_governed( 'managed_runtime' )",
    "MAD4B_SCP_Site_Profile::origin_enrolled()",
    "MAD4B_SCP_Site_Profile::managed_runtime_enabled()",
    "'unmanaged_mu_bootstrap_path_conflict'",
    "'mad4b/mcp-mu-bootstrap-refreshed'",
    "'managed_mu_refreshed_for_next_request'",
    'historical_managed_sha256',
    'TRANSACTION_OPTION',
    'reconcile_transaction',
    'replaced_pending_audit',
    'add_option( self::TRANSACTION_OPTION',
    'mu_bootstrap_transaction_not_owner',
    'mu_bootstrap_transaction_in_progress',
    'TRANSACTION_STALE_AFTER',
    'private static function read_transaction_option()',
    "wp_cache_delete( self::TRANSACTION_OPTION, 'options' )",
    "'persistent_object_cache_authoritative' => false",
    "'transaction_store' => 'wp_options_unique_option'",
    "'filesystem_replace_strategy' => 'same_directory_atomic_rename'",
    "'filesystem_replace_atomicity_required' => true",
    "'non_atomic_replace_fallback' => false",
    "'shared_filesystem_certified' => false",
    "'next_request_required' => true",
):
    require(refresh, marker, 'mu-refresh')

for forbidden in (
    'deactivate_plugins(', 'activate_plugin(', 'delete_plugins(', 'wp_remote_get(',
    'wp_remote_post(', 'curl_exec(', 'active_sitewide_plugins', 'switch_to_blog(',
    '@unlink( $destination )',
):
    forbid(refresh, forbidden, 'mu-refresh-bounded')

transaction_reader = refresh.split('private static function read_transaction_option()', 1)[1].split('private static function transaction_record_for_owner', 1)[0]
if 'return self::read_transaction_option();' in transaction_reader:
    raise SystemExit('FAIL transaction-cache: authoritative option reader recurses instead of reading wp_options')
if "return get_option( self::TRANSACTION_OPTION, array() );" not in transaction_reader:
    raise SystemExit('FAIL transaction-cache: authoritative option reader no longer reaches WordPress Options API')

require(bootstrap, "class-mad4b-scp-mcp-mu-bootstrap-refresh.php", 'bootstrap-load-refresh')
require(bootstrap, "add_action( 'init', array( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', 'bootstrap' ), 20 );", 'bootstrap-schedule-refresh')
require(bootstrap, "class-mad4b-scp-mcp-runtime-conflict-guard.php", 'bootstrap-load-guard')
require(bootstrap, "add_action( 'init', array( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard', 'bootstrap' ), 21 );", 'bootstrap-schedule-guard')
for forbidden in (
    'MAD4B_SCP_MCP_MU_Bootstrap_Refresh::bootstrap();',
    'MAD4B_SCP_MCP_Runtime_Conflict_Guard::bootstrap();',
):
    forbid(bootstrap, forbidden, 'no-plugin-include-repair')
refresh_hook = bootstrap.index("add_action( 'init', array( 'MAD4B_SCP_MCP_MU_Bootstrap_Refresh', 'bootstrap' ), 20 );")
guard_hook = bootstrap.index("add_action( 'init', array( 'MAD4B_SCP_MCP_Runtime_Conflict_Guard', 'bootstrap' ), 21 );")
if refresh_hook > guard_hook:
    raise SystemExit('FAIL bootstrap-order: stale managed MU refresh must run before conflict guard')
if guard_hook > bootstrap.index('MAD4B_SCP_MCP_Registration_Bridge::boot_early();'):
    raise SystemExit('FAIL bootstrap-order: repair hooks must be scheduled before registration bridge')

for marker in (
    'REST API init count',
    'MU bootstrap source refresh applied', 'MU bootstrap refresh next request required',
    'MU bootstrap refresh state', 'MU bootstrap refresh blocker',
    'Runtime conflict guard eligible', 'Official MCP Adapter active',
    'Hostinger bundled adapter active', 'Official loads before Hostinger in active_plugins',
    'Runtime provenance mismatch', 'Runtime class provenance enforced',
    'Runtime class provenance ready', 'Runtime class provenance complete',
    'Runtime class provenance state', 'Runtime class provenance failure count',
    'Runtime class provenance unobserved count', 'Runtime from Hostinger bundle',
    'Collision risk detected', 'Runtime repair applied', 'MU bootstrap present',
    'MU bootstrap integrity', 'MU bootstrap executed this request',
    'MU bootstrap runtime state', 'Next request required',
):
    require(diagnostics, marker, 'diagnostics-evidence')

print('mad4b.site-control-plane.mcp-runtime-conflict-guard-contract.v5: PASS')

# The diagnostic POST is only an early routing hint after the Site Profile HMAC
# proof matches; nonce/capability authorization still belongs to the worker.
assert "'mad4b_connection_endpoint_diagnostic' === $_POST['action']" in mu_bootstrap
assert "diagnostic_mu_proof_valid" in mu_bootstrap
assert "MAD4B_SCP_Site_Profile::diagnostic_mu_proof()" in mu_bootstrap
assert "canonical_runtime_pinned_diagnostic_deferred" in mu_bootstrap
assert "managed_mu_transaction_pending" in mu_bootstrap
mu_tx_cache_delete = "wp_cache_delete( 'mad4b_scp_mcp_mu_refresh_transaction_v1', 'options' )"
mu_tx_read = "get_option( 'mad4b_scp_mcp_mu_refresh_transaction_v1', array() )"
assert mu_tx_cache_delete in mu_bootstrap
assert mu_tx_read in mu_bootstrap
assert mu_bootstrap.index(mu_tx_cache_delete) < mu_bootstrap.index(mu_tx_read), 'MU safety read must evict persistent cache before get_option'
assert "MAD4B_SCP_MCP_CLI_REQUEST" in mu_bootstrap
assert "cli_mcp_opt_in" in mu_bootstrap
assert "active_plugins_unique_main_file" in mu_bootstrap
assert "plugin_identity_ambiguous" in mu_bootstrap
mu_cleanup = mu_bootstrap.split("unset(", 1)[1]
for marker in (
    '$mad4b_mcp_mu_transaction',
    '$mad4b_mcp_mu_find_active',
    '$mad4b_mcp_mu_control_plane_matches',
    '$mad4b_mcp_mu_adapter_matches',
    '$mad4b_mcp_mu_control_plane_root',
    '$mad4b_mcp_mu_proof',
    '$mad4b_mcp_mu_expected_proof',
    '$mad4b_mcp_mu_is_cli',
    '$mad4b_mcp_mu_cli_opt_in',
):
    assert marker in mu_cleanup, f'MU global cleanup missing {marker}'
assert mu_bootstrap.index("Validate every executable pin") < mu_bootstrap.index("foreach ( $mad4b_mcp_mu_pin_files as $mad4b_mcp_mu_pin_file ) require_once")
