#!/usr/bin/env python3
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
isolation = (ROOT / 'includes/class-mad4b-scp-mcp-provider-isolation.php').read_text('utf-8')
bootstrap = (ROOT / 'mad4b-site-control-plane.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')
client = (ROOT / 'includes/adapters/class-mad4b-scp-jetengine-mcp-client.php').read_text('utf-8')
bridge = (ROOT / 'includes/adapters/class-mad4b-scp-native-provider-bridge-adapter.php').read_text('utf-8')
servers = (ROOT / 'includes/class-mad4b-scp-servers.php').read_text('utf-8')
write_runtime = (ROOT / 'includes/class-mad4b-scp-write-runtime-certification.php').read_text('utf-8')
transport_registry = (ROOT / 'includes/class-mad4b-scp-provider-transport-registry.php').read_text('utf-8')
transport_catalog = (ROOT / 'config/provider-transport-registry.json').read_text('utf-8')

PREVIOUS_MARKER = 'mad4b.site-control-plane.mcp-provider-isolation-contract.v4'
LEGACY_MARKER = 'mad4b.site-control-plane.mcp-provider-isolation-contract.v3'


def php_executable_text(text):
    """Return PHP lexical code with comments and quoted literals blanked."""
    out = []
    i = 0
    state = 'code'
    quote = ''
    while i < len(text):
        ch = text[i]
        nxt = text[i + 1] if i + 1 < len(text) else ''
        if state == 'code':
            if ch == '/' and nxt == '*':
                out.extend((' ', ' '))
                i += 2
                state = 'block_comment'
                continue
            if ch == '/' and nxt == '/':
                out.extend((' ', ' '))
                i += 2
                state = 'line_comment'
                continue
            if ch == '#':
                out.append(' ')
                i += 1
                state = 'line_comment'
                continue
            if ch in ("'", '"', chr(96)):
                quote = ch
                out.append(' ')
                i += 1
                state = 'string'
                continue
            out.append(ch)
            i += 1
            continue
        if state == 'block_comment':
            if ch == '*' and nxt == '/':
                out.extend((' ', ' '))
                i += 2
                state = 'code'
            else:
                out.append('\n' if ch == '\n' else ' ')
                i += 1
            continue
        if state == 'line_comment':
            if ch == '\n':
                out.append('\n')
                state = 'code'
            else:
                out.append(' ')
            i += 1
            continue
        if state == 'string':
            if ch == '\\' and i + 1 < len(text):
                out.extend((' ', ' '))
                i += 2
                continue
            if ch == quote:
                out.append(' ')
                i += 1
                state = 'code'
                quote = ''
                continue
            out.append('\n' if ch == '\n' else ' ')
            i += 1
    return ''.join(out)


def forbid_executable_php(text, needle, label):
    if needle in php_executable_text(text):
        raise SystemExit(f'FAIL {label}: forbidden executable {needle!r}')


_lexer_comment_fixture = "<?php /* rest_get_server( */ // rest_get_server(\n# rest_get_server(\n$x = 'rest_get_server(';"
_lexer_code_fixture = "<?php rest_get_server();"
if 'rest_get_server(' in php_executable_text(_lexer_comment_fixture):
    raise SystemExit('FAIL executable-php-lexer: comment/string false positive')
if 'rest_get_server(' not in php_executable_text(_lexer_code_fixture):
    raise SystemExit('FAIL executable-php-lexer: real call escaped detection')

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
require(isolation, 'MAD4B_SCP_Site_Profile::origin_enrolled()', 'explicit-site-profile-enrollment')
require(isolation, 'private static function bootstrap_governed_staging()', 'staging-zero-touch-bootstrap')
require(isolation, 'self::bootstrap_governed_staging();', 'early-staging-zero-touch-bootstrap')
require(isolation, 'MAD4B_SCP_Site_Profile::provider_isolation_enabled()', 'profile-provider-isolation-gate')
require(isolation, 'MAD4B_SCP_Site_Profile::current_environment()', 'profile-environment-binding')
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
require(isolation, "add_action( 'rest_api_init', array( __CLASS__, 'suppress_provider_rest_registrations' ), PHP_INT_MIN )", 'pre-rest-provider-registration-suppression')
require(isolation, 'public static function suppress_provider_rest_registrations()', 'reviewed-rest-callback-suppression')
require(isolation, 'private static function descriptor_for_rest_registration_callback', 'bounded-rest-callback-classifier')
require(isolation, "Jet_Engine\\\\MCP_Tools\\\\", 'jetengine-mcp-namespace-boundary')
require(isolation, "remove_action( 'rest_api_init'", 'pre-dispatch-rest-callback-removal')
require(isolation, 'private static function internal_materialization_cataloged', 'catalog-bounded-internal-materialization')
require(isolation, "MAD4B_SCP_Provider_Transport_Registry::route_descriptors()", 'internal-materialization-uses-transport-catalog')
require(isolation, 'private static function materialize_internal_provider_routes', 'lazy-internal-provider-materialization')
require(isolation, 'public static function ensure_internal_provider_transport', 'public-bounded-discovery-materialization-gate')
require(isolation, "'materialization_ready'", 'internal-materialization-readback')
require(isolation, "$status['outbound_network_performed'] = false;", 'internal-materialization-no-network')
require(isolation, "$status['provider_settings_changed'] = false;", 'internal-materialization-no-provider-settings')
require(isolation, "'materialized_internal_only'", 'lazy-internal-materialization-state')
require(isolation, "'rest_registration_suppression_attempted' => (bool) self::$rest_registration_suppression_attempted", 'rest-suppression-status-evidence')
require(isolation, "'suppressed_rest_callback_count' => count( $rest_suppressed )", 'rest-suppression-count-evidence')
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
require(isolation, "MAD4B_SCP_Portable_Readonly_Connection::effective()", 'portable-readonly-isolation-bootstrap')
require(isolation, "'portable_readonly_bootstrap'", 'portable-readonly-isolation-source')
require(isolation, "'site_profile_not_enrolled_and_portable_readonly_unavailable'", 'portable-readonly-fail-closed-without-bootstrap')
require(isolation, "'portable_readonly_isolation_nonproduction_only'", 'portable-readonly-no-production-auto-isolation')
require(isolation, "'explicit_isolation_intent_requires_runtime_suppression_approval'", 'portable-readonly-preserves-legacy-second-gate')
require(isolation, "'staging_zero_touch_autoconfig_source' => self::$staging_autoconfig_source", 'portable-readonly-status-source')

require(isolation, "retain_internal_provider_route", 'jetengine-internal-route-retention')
require(isolation, "internal_provider_transport_status", 'jetengine-internal-transport-status')
require(isolation, "dispatch_internal_provider_request", 'jetengine-internal-dispatch')
require(isolation, "bypassed_external_provider_permission", 'internal-handoff-does-not-replay-provider-external-auth')
require(isolation, "'internal_permission_mode' => 'mad4b-governed-provider-permission-bypass'", 'internal-handoff-permission-mode-evidence')
require(isolation, "'materialization_attempted' => isset( self::$internal_rest_materialization_attempted[ $provider ] )", 'isolated-materialization-attempt-evidence')
require(isolation, "'materialization_state' => isset( self::$internal_rest_materialization_state[ $provider ] )", 'isolated-materialization-state-evidence')
require(isolation, "'suppressed_callback_count' => count( array_filter( self::$suppressed_rest_callbacks", 'isolated-reviewed-callback-count-evidence')
require(client, "mad4b.jetengine-native-rest-execution-diagnostic.v1", 'native-rest-bounded-error-diagnostic')
require(client, "'request_envelope_digest'", 'native-rest-request-envelope-digest')
require(client, "'provider_response_error_code'", 'native-rest-provider-error-code')
require(client, "'callback_reached'", 'native-rest-callback-reachability')
require(isolation, "'raw_routes_exposed' => false", 'jetengine-raw-routes-remain-hidden')
require(isolation, "'mcp_execution_surface' === (string) $descriptor['class']", 'retain-execution-surfaces-only')
require(client, "'isolated-native-rest-tools'", 'native-bridge-isolated-transport')
require(client, "MAD4B_SCP_MCP_Provider_Isolation::ensure_internal_provider_transport( 'jetengine' )", 'native-bridge-discovery-materialization')
require(client, "MAD4B_SCP_MCP_Provider_Isolation::dispatch_internal_provider_request( 'jetengine', $request )", 'native-bridge-governed-isolation-handoff')
forbid_executable_php(client, 'rest_get_server(', 'native-client-never-consumes-rest-lifecycle')
require(client, "'raw_provider_routes_exposed' => false", 'native-client-no-raw-route-exposure')
require(client, "'blockers' => array_values( array_unique( $blockers ) )", 'native-client-actionable-transport-blockers')
require(client, "'next_action' => $next_action", 'native-client-actionable-next-step')
require(client, "'isolated_materialization_state' => $materialization_state", 'native-client-materialization-state')
require(bridge, "'resolution_contract' => 'mad4b.jetengine-native-operation-resolution.v1'", 'jetengine-operation-resolution-contract')
require(bridge, "'reviewed_native_name' => $reviewed_name", 'jetengine-reviewed-native-name-evidence')
require(bridge, "'candidate_matches'", 'jetengine-candidate-match-evidence')
require(bridge, "'available_count' => $available_count", 'jetengine-operation-availability-accounting')
require(bridge, "'unavailable_count' => count( $items ) - $available_count", 'jetengine-operation-unavailability-accounting')
require(bridge, "'get_configuration' => 'resource-get-configuration'", 'jetengine-get-configuration-exact-native-name')
require(bridge, "'create_query' => 'tool-add-query'", 'jetengine-create-query-exact-native-name')
require(bridge, 'exact_operation_native_name', 'jetengine-exact-operation-precedence')
require(servers, "did_action( 'rest_api_init' ) > 0", 'adapter-write-projection-cache-after-rest')
require(servers, "! doing_action( 'rest_api_init' )", 'adapter-write-projection-not-cached-during-rest-registration')
forbid(write_runtime, "add_action( 'rest_api_init', array( __CLASS__, 'observe' )", 'write-runtime-not-on-rest-critical-path')
forbid(write_runtime, "add_action( 'mcp_adapter_init', array( __CLASS__, 'observe' )", 'write-runtime-no-pre-rest-observation')
require(write_runtime, "'execute_callback' => array( __CLASS__, 'status' )", 'write-runtime-read-only-ability-callback')
require(write_runtime, "doing_action( 'rest_api_init' )", 'write-runtime-rest-registration-guard')
require(write_runtime, "add_action( 'admin_init', array( __CLASS__, 'observe' ), 110 )", 'write-runtime-admin-refresh')

for forbidden in (
    "register_rest_route(",
    "do_action( 'rest_api_init'",
    'rest_get_server(',
):
    retained_start = isolation.index('public static function dispatch_internal_provider_request')
    retained_end = isolation.index('public static function descriptors()', retained_start)
    forbid_executable_php(isolation[retained_start:retained_end], forbidden, 'internal-handoff-no-route-registration-or-rest-replay')

require(isolation, "MAD4B_SCP_Provider_Transport_Registry::route_descriptors()", 'declarative-route-registry-consumption')
require(isolation, "MAD4B_SCP_Provider_Transport_Registry::server_callback_descriptors()", 'declarative-server-registry-consumption')
require(transport_registry, "const CONTRACT = 'mad4b.provider-transport-registry.v1'", 'transport-registry-contract')
require(transport_registry, "'unknown_transport_auto_allowed' => false", 'unknown-transport-not-auto-allowed')
require(transport_registry, 'public static function route_descriptors()', 'transport-route-projection')
require(transport_registry, 'public static function server_callback_descriptors()', 'transport-server-projection')

for provider in ('hostinger_ai_assistant', 'fluent_forms', 'jetengine', 'uae_hfe', 'elementskit', 'elementor'):
    require(transport_catalog, f'"provider_id": "{provider}"', f'provider-{provider}')

for exact_surface in (
    '/hostinger-ai-assistant/v1/mcp/', '/hostinger-ai-assistant/v1/jwt/',
    '/fluentform/v1/mcp/', '/fluentform/mcp/', '/jet-engine/v1/mcp/',
    '/jet-engine/v1/mcp-tools/', '/hfe/v1/mcp-', '/uae/mcp/',
    '/elementskit/mcp/', '/elementskit/v1/mcp-proxy/',
    '/elementor-mcp-composer/v[0-9]+', '/elementor/v1/mcp-proxy/'
):
    require(transport_catalog, exact_surface, f'route-{exact_surface}')

catalog_data = json.loads(transport_catalog)
elementor_descriptor = next((row for row in catalog_data.get('descriptors', []) if row.get('provider_id') == 'elementor'), None)
if not elementor_descriptor:
    raise SystemExit('FAIL elementor-transport-descriptor: missing Elementor provider descriptor')

elementor_rules = [row.get('pattern', '') for row in elementor_descriptor.get('routes', [])]
elementor_versioned_routes = (
    '/elementor-mcp-composer/v1.0.17',
    '/elementor-mcp-composer/v1.0.17/mcp-settings',
    '/elementor-mcp-composer/v1.0.17/mcp-credentials',
    '/elementor-mcp-composer/v2.4.1',
    '/elementor-mcp-composer/v2.4.1/mcp-settings',
    '/elementor-mcp-composer/v2.4.1/mcp-credentials',
    '/elementor/v1/mcp-proxy',
)
for route in elementor_versioned_routes:
    if not any(re.fullmatch(pattern, route) for pattern in elementor_rules):
        raise SystemExit(f'FAIL elementor-versioned-route-coverage: no rule matched {route!r}')

for route in (
    '/elementor-mcp-composer-admin/v1.0.17/mcp-settings',
    '/elementor/v1/mcp-unreviewed',
):
    if any(re.fullmatch(pattern, route) for pattern in elementor_rules):
        raise SystemExit(f'FAIL elementor-unknown-route-fail-closed: catalog overmatched {route!r}')

# Hostinger Easy Onboarding banner state is a reviewed non-transport route and
# must remain visible; peer governance classifies it instead of isolation deleting it.
forbid(transport_catalog, 'update-mcp-connector-banner-status', 'do-not-hide-hostinger-banner-control')

for exact_server in (
    'hostinger-ai-assistant-mcp-server', 'Hostinger\\\\AiAssistant\\\\Mcp\\\\McpServer', 'create_server',
    'elementskit-mcp-server', 'ElementsKit_Lite\\\\Mcp\\\\Server', 'register_server',
):
    require(transport_catalog, exact_server, f'server-{exact_server}')

for forbidden in (
    'wp_remote_get(', 'wp_remote_post(', 'wp_safe_remote_get(', 'wp_safe_remote_post(',
    'update_option(', 'add_option(', 'delete_option(', 'wp_install_plugin(',
    'deactivate_plugins(', 'activate_plugin(', 'ReflectionClass', 'setAccessible(',
    'MAD4B_SCP_Agent_Registry::', 'MAD4B_SCP_Approval_Tickets::',
    "define( self::PRODUCTION_APPROVAL_FLAG, true )",
):
    forbid(isolation, forbidden, 'isolation-no-mutation-or-outbound')

require(bootstrap, "class-mad4b-scp-provider-transport-registry.php", 'transport-registry-bootstrap-load')
require(bootstrap, "MAD4B_SCP_Provider_Transport_Registry::boot();", 'transport-registry-bootstrap')
require(bootstrap, "class-mad4b-scp-mcp-provider-isolation.php", 'bootstrap-load')
require(bootstrap, 'MAD4B_SCP_MCP_Provider_Isolation::boot_early();', 'bootstrap-early-kill-switch')
require(plugin, 'MAD4B_SCP_MCP_Provider_Isolation::boot();', 'plugin-boot')

print('mad4b.site-control-plane.mcp-provider-isolation-contract.v8: PASS')
