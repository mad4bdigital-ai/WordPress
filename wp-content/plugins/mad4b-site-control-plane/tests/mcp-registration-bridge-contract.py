#!/usr/bin/env python3
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
bridge = (ROOT / 'includes/class-mad4b-scp-mcp-registration-bridge.php').read_text('utf-8')
diagnostics = (ROOT / 'includes/class-mad4b-scp-mcp-registration-diagnostics-admin.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')
build_marker = ROOT / 'MAD4B-RUNTIME-BUILD.txt'


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


for marker in (
    "const CONTRACT = 'mad4b.mcp-registration-bridge.v2'",
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    'public static function boot_early()',
    "did_action( 'mcp_adapter_init' ) > 0",
    "did_action( 'rest_api_init' ) > 0",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_core_categories' ), 10 )",
    "add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_registry_categories' ), 20 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_core_abilities' ), 10 )",
    "add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_registry_abilities' ), 20 )",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'register_servers' ), 10, 1 )",
    "add_action( 'init', array( __CLASS__, 'recover_missed_rest_lifecycle' ), 9999 )",
    'public static function recover_missed_rest_lifecycle()',
    'wp_get_abilities();',
    "wp_has_ability( $sentinel )",
    "wp_get_ability( $sentinel )",
    "array( 'mad4b/site-info', 'mad4b/content-update-post' )",
    "\\WP\\MCP\\Core\\McpAdapter::instance()",
    "$adapter->init();",
    "'mcp_adapter_create_default_server'",
    "'\\\\WP\\\\MCP\\\\Transport\\\\HttpTransport'",
    "$server->create_transport_context()",
    "remove_action( 'rest_api_init', array( $transport, 'register_routes' ), 16 )",
    '$transport->register_routes();',
    "'missed_rest_lifecycle_recovered'",
    "'rest_init_seen_before_bridge_boot'",
    "'missed_rest_recovery_scheduled'",
    "'missed_rest_recovery_attempted'",
    "'missed_rest_recovery_succeeded'",
    "'missed_rest_recovery_route_count'",
    "'missed_rest_recovery_state'",
    "'missed_rest_recovery_blocker'",
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
    "do_action( 'rest_api_init'",
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->',
    'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'curl_exec(', 'fsockopen(',
):
    forbid(bridge, stale, 'bounded-missed-rest-recovery')

require(bootstrap, "class-mad4b-scp-mcp-registration-bridge.php", 'bootstrap-load-bridge')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Bridge::boot_early();', 'bootstrap-early-bridge')
require(bootstrap, "class-mad4b-scp-mcp-registration-diagnostics-admin.php", 'bootstrap-load-diagnostics')
require(bootstrap, 'MAD4B_SCP_MCP_Registration_Diagnostics_Admin::boot();', 'bootstrap-diagnostics')
require(bootstrap, "add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );", 'full-plugin-init-boundary')
forbid(bootstrap, "add_action( 'plugins_loaded', array( 'MAD4B_SCP_Plugin', 'boot' )", 'no-pre-init-full-plugin-boot')

# The pre-init phase is registration-only. It may arm callbacks/filters that
# populate the public catalog once WordPress fires wp_abilities_api_init, but it
# must not run reconciliation/certification observers or write-authority boot.
early_registration_wiring = (
    'MAD4B_SCP_Connection_Ability::boot();',
    'MAD4B_SCP_Governed_Ability_Overrides::boot();',
    "add_filter( 'wp_register_ability_args', array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' ), 70, 2 );",
    "add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'register_status_ability' ), 35 );",
    'MAD4B_SCP_Staging_Write_Planning_Guard::boot();',
    "add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_REST_Compatibility', 'register_ability' ), 36 );",
    "add_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Write_Runtime_Certification', 'register_ability' ), 37 );",
    'MAD4B_SCP_Governance_Abilities::boot();',
    'MAD4B_SCP_Skill_Abilities::boot();',
    'MAD4B_SCP_Skills_Adapter::boot();',
)
metadata_bootstrap_index = bootstrap.index('MAD4B_SCP_MCP_Adapter_Metadata_Bridge::bootstrap();')
for marker in early_registration_wiring:
    require(bootstrap, marker, 'early-ability-registration-wiring')
    if bootstrap.index(marker) > metadata_bootstrap_index:
        raise SystemExit(f'FAIL early-ability-registration-order: {marker} must be wired before metadata/bootstrap execution')

pre_metadata = bootstrap[:metadata_bootstrap_index]
for forbidden_early_boot in (
    'MAD4B_SCP_Staging_Write_Authority::boot();',
    'MAD4B_SCP_REST_Compatibility::boot();',
    'MAD4B_SCP_Write_Runtime_Certification::boot();',
    'MAD4B_SCP_Skill_Runtime_Certification::boot();',
):
    forbid(pre_metadata, forbidden_early_boot, 'registration-only-pre-init-boundary')

# Never probe the private registry directly. WordPress 6.9+ feature detection
# must go through wp_has_ability()/wp_get_abilities() before wp_get_ability().
for php_file in ROOT.rglob('*.php'):
    source = php_file.read_text('utf-8')
    if re.search(r'->\s*get_registered\s*\(', source):
        raise SystemExit(f'FAIL unsafe-ability-probe: direct get_registered() in {php_file.relative_to(ROOT)}')
    if re.search(r'\bseems_utf8\s*\(', source):
        raise SystemExit(f'FAIL deprecated-utf8-probe: seems_utf8() in {php_file.relative_to(ROOT)}')

# Release-candidate numbers change while this PR is under live Staging validation.
# Verify all release evidence agrees instead of pinning the test to a stale rc.N.
header_match = re.search(r'(?mi)^\s*\*\s*Version:\s*([^\r\n]+)', bootstrap)
runtime_match = re.search(r"define\(\s*'MAD4B_SCP_VERSION'\s*,\s*'([^']+)'\s*\);", bootstrap)
if not header_match:
    raise SystemExit('FAIL diagnostic-build-version: plugin Version header missing')
if not runtime_match:
    raise SystemExit('FAIL diagnostic-runtime-version: MAD4B_SCP_VERSION definition missing')
header_version = header_match.group(1).strip()
runtime_version = runtime_match.group(1).strip()
if header_version != runtime_version:
    raise SystemExit(f'FAIL diagnostic-version-consistency: header={header_version!r} runtime={runtime_version!r}')
if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?', header_version):
    raise SystemExit(f'FAIL diagnostic-version-format: invalid version {header_version!r}')

bridge_index = bootstrap.index('MAD4B_SCP_MCP_Registration_Bridge::boot_early();')
full_boot_index = bootstrap.index("add_action( 'init', array( 'MAD4B_SCP_Plugin', 'boot' ), -1000000 );")
if bridge_index > full_boot_index:
    raise SystemExit('FAIL bridge-order: registration bridge must bind before the full Plugin init callback is registered')

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
    'REST API init happened before bridge boot',
    'Missed REST recovery scheduled',
    'Missed REST recovery attempted',
    'Missed REST recovery succeeded',
    'Missed REST recovery route count',
    'Missed REST recovery state',
    'Missed REST recovery blocker',
    'Control Plane runtime version',
    'Control Plane main file disk version',
    'Control Plane runtime stale vs disk',
    'Control Plane main file SHA-256 prefix',
    'Control Plane build marker present',
    'Control Plane build marker release',
    'Control Plane build marker matches disk',
    'Runtime provenance mismatch',
    'Runtime from Hostinger bundle',
    'MU bootstrap present',
    'MU bootstrap integrity',
    'MU bootstrap executed this request',
    'Registration error:',
    'private static function disk_evidence()',
    "hash_file( 'sha256', $main )",
):
    require(diagnostics, marker, 'diagnostics-surface')

if not build_marker.is_file():
    raise SystemExit('FAIL build-marker: MAD4B-RUNTIME-BUILD.txt is missing')
marker_text = build_marker.read_text('utf-8')
marker_release = re.search(r'(?mi)^release=([^\r\n]+)$', marker_text)
if not marker_release:
    raise SystemExit('FAIL build-marker-release: release field missing')
build_version = marker_release.group(1).strip()
if build_version != header_version:
    raise SystemExit(
        f'FAIL build-marker-version-consistency: marker={build_version!r} header={header_version!r}'
    )
require(marker_text, 'contract=mad4b.runtime-build-evidence.v1', 'build-marker-contract')

for forbidden in (
    'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request(', 'curl_exec(', 'fsockopen(',
    'update_option(', 'add_option(', 'delete_option(', '$wpdb->', 'activate_plugin(',
    'deactivate_plugins(', 'file_put_contents(', 'unlink(', 'rename(',
):
    forbid(diagnostics, forbidden, 'diagnostics-read-only')

# Runtime source may be exposed only relative to WP_PLUGIN_DIR or as a bounded label.
forbid(diagnostics, 'getFileName(', 'diagnostics-do-not-resolve-path-directly')
require(bridge, "'outside-wp-plugin-dir'", 'bounded-outside-path-label')
require(bridge, "ltrim( substr( $normalized, strlen( $plugins ) ), '/' )", 'plugin-relative-runtime-source')

print('mad4b.site-control-plane.mcp-registration-bridge-contract.v8: PASS')
