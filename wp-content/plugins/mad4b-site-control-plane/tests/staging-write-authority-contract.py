#!/usr/bin/env python3
from pathlib import Path
import json

repo = Path(__file__).resolve().parents[4]
wp = repo / 'wp-content' / 'plugins' / 'mad4b-site-control-plane'

write = (wp / 'includes' / 'class-mad4b-scp-staging-write-authority.php').read_text(encoding='utf-8')
planning = (wp / 'includes' / 'class-mad4b-scp-staging-write-planning-guard.php').read_text(encoding='utf-8')
cert = (wp / 'includes' / 'class-mad4b-scp-write-runtime-certification.php').read_text(encoding='utf-8')
rest = (wp / 'includes' / 'class-mad4b-scp-rest-compatibility.php').read_text(encoding='utf-8')
servers = (wp / 'includes' / 'class-mad4b-scp-servers.php').read_text(encoding='utf-8')
transport = (wp / 'includes' / 'class-mad4b-scp-transport-context.php').read_text(encoding='utf-8')
auth = (wp / 'includes' / 'class-mad4b-scp-authorization.php').read_text(encoding='utf-8')
plugin = (wp / 'includes' / 'class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
main = (wp / 'mad4b-site-control-plane.php').read_text(encoding='utf-8')
exporter = (wp / 'includes' / 'class-mad4b-scp-skill-exporter.php').read_text(encoding='utf-8')
portable = json.loads((repo / 'plugins' / 'mad4b-wordpress' / 'plugin.json').read_text(encoding='utf-8'))

for marker in [
    "const STAGING_HOST = 'staging.egypttourgates.com'",
    "const AGENT_SLUG = 'chatgpt-staging-write'",
    "const APPROVAL_INPUT_KEY = '_mad4b_approval_ticket_id'",
    "define( 'MAD4B_MCP_MUTATION_ENABLED', true )",
    "'production_auto_enable' => false",
    "'breakglass_auto_enable' => false",
    "'all_remote_writes_require_exact_approval' => true",
    "MAD4B_SCP_Agent_Registry::grant_ability",
    "'mad4b-write'",
    "'staging'",
    "remote_scope_delegation_allowed",
    "MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()",
    "mad4b:read",
    "approval_ticket_from_input",
]:
    if marker not in write:
        raise SystemExit(f'missing governed Staging write invariant: {marker}')

if "'mad4b/database-raw-query'" not in write or "array_diff( $tools, array( 'mad4b/database-raw-query' ) )" not in write:
    raise SystemExit('write authority must explicitly remove breakglass raw-query')

for ability in [
    'mad4b/content-update-post',
    'mad4b/plugin-activate',
    'mad4b/plugin-deactivate',
    'mad4b/filesystem-write',
    'mad4b/filesystem-patch',
    'mad4b/database-update',
    'mad4b/mutation-undo',
    'mad4b/approval-plan',
]:
    if ability not in servers:
        raise SystemExit(f'known core update/write/mutation action missing from server catalog: {ability}')

for marker in [
    "false !== $annotations['readonly']",
    "$registry->ability_names( 'content' )",
    "$registry->ability_names( 'admin' )",
    "array_merge( $tools, self::write_tools() )",
    "return self::provider_for_ability( 'mad4b-write', $ability_name )",
    "'mad4b/write-authority-status'",
    "'mad4b/write-runtime-certification'",
    "'mad4b/rest-compatibility-status'",
]:
    if marker not in servers:
        raise SystemExit(f'missing complete write inventory/same-Plugin projection invariant: {marker}')

for marker in [
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability( $ability_name )",
    "return 'mad4b-write'",
    "mad4b_write_authority_mount_missing",
]:
    if marker not in transport:
        raise SystemExit(f'missing transport-to-write-authority binding: {marker}')

for marker in [
    "MAD4B_SCP_Staging_Write_Authority::authorization_input( $input )",
    "MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $input )",
    "MAD4B_SCP_Staging_Write_Authority::remote_scope_delegation_allowed",
    "MAD4B_SCP_Approval_Tickets::consume_exact( $approval_ticket_id, $agent, $server_id, $ability_name, $provider, $target_fingerprint, $authorization_input, $ticket_class )",
    "MAD4B_SCP_Budgets::reserve( $agent, $ability_name, $provider, $authorization_input, $approval_required )",
    "'approval_ticket_source'",
]:
    if marker not in auth:
        raise SystemExit(f'missing central write authorization binding: {marker}')

# approval-plan is the bootstrap mutation: exact NHI/grant/budget, no prior ticket,
# same dedicated Staging agent, mad4b-write only, mutation ticket class only.
for marker in [
    "const CONTRACT = 'mad4b.staging-write-planning-guard.v2'",
    "const ABILITY = 'mad4b/approval-plan'",
    "MAD4B_SCP_Authorization::authorize_mutation( self::ABILITY, 'mad4b-admin', 'core', $input )",
    "'ability:' . self::ABILITY",
    "planner_approval_exception",
    "validate_remote_plan_input",
    "mad4b_remote_plan_agent_mismatch",
    "'mad4b-write' !== $server_id",
    "'mad4b/database-raw-query' === $target_ability",
    "MAD4B_SCP_Staging_Write_Authority::is_write_ability( $target_ability )",
    "MAD4B_SCP_Servers::provider_for_ability( 'mad4b-write', $target_ability )",
    "'mutation' !== MAD4B_SCP_Impact_Policy::ticket_class_for",
    "'remote_planner_requires_prior_ticket' => false",
    "'creates_pending_ticket_only' => true",
    "'auto_approves' => false",
]:
    if marker not in planning:
        raise SystemExit(f'missing approval planner bootstrap guard: {marker}')

for marker in [
    "const CONTRACT = 'mad4b.rest-compatibility.v2'",
    "const WPML_ROUTE = '/wpml/v1/rest/status'",
    "apply_filters( 'rest_enabled', true )",
    "rest_do_request( $request )",
    "'test_get_parameter' => '1'",
    "'query_parameters_preserved'",
    "hook_inventory( 'rest_enabled' )",
    "hook_inventory( 'rest_authentication_errors' )",
    "'control_plane_detected'",
    "ReflectionMethod",
    "ReflectionFunction",
    "wpml_route_missing",
    "scope_mcp_recovery_to_current_http_request",
    "current_http_request_targets_mad4b_mcp",
    "'/mcp/mad4b-read'",
    "'/mcp/mad4b-chatgpt'",
    "'/mcp/mad4b-content'",
    "'/mcp/mad4b-write'",
    "'/mcp/mad4b-admin'",
    "'/mcp/mad4b-breakglass'",
    "array( 'MAD4B_SCP_MCP_Registration_Bridge', 'verify_adapter_init_after_rest' )",
    "array( 'MAD4B_SCP_MCP_Registration_Rescue', 'after_rest_init' )",
    "array( 'MAD4B_SCP_MCP_Registration_Rescue', 'before_rest_dispatch' )",
    "array( 'MAD4B_SCP_MCP_Registration_Bridge', 'recover_missed_rest_lifecycle' )",
    "mcp_recovery_callbacks_removed_for_unrelated_request",
]:
    if marker not in rest:
        raise SystemExit(f'missing WPML/general REST compatibility evidence invariant: {marker}')

# The compatibility layer must never disable REST or rewrite global REST auth.
# Its only mutation of hooks is deny-only removal of MAD4B's own MCP recovery
# callbacks when the current HTTP request is unrelated to MAD4B MCP.
for forbidden in [
    "add_filter( 'rest_enabled'",
    "add_filter( 'rest_authentication_errors'",
    "remove_all_filters( 'rest_",
]:
    if forbidden in rest:
        raise SystemExit(f'REST compatibility layer may not globally modify WordPress REST behavior: {forbidden}')

for marker in [
    "const CONTRACT = 'mad4b.write-runtime-certification.v2'",
    "add_action( 'mcp_adapter_init', array( __CLASS__, 'observe' ), 110 )",
    "MAD4B_SCP_Staging_Write_Authority::eligible()",
    "return self::ineligible_status()",
    "'persistence' => 'not_applicable'",
    "all_write_tools_exposed_on_same_plugin_transport",
    "breakglass_absent_from_write_inventory",
    "approval_planner_governed",
    "approval_planner_no_prior_ticket",
    "approval_planner_self_agent_only",
    "approval_planner_breakglass_denied",
    "control_plane_not_on_rest_enabled_hook",
    "control_plane_not_on_rest_authentication_hook",
    "control_plane_does_not_block_wpml_rest",
    "mcp_recovery_scope_evaluated",
    "mcp_recovery_scoped_to_mad4b_routes",
    "external_wpml_acceptance_not_claimed_locally",
    "external_wpml_acceptance_required",
    "external_wpml_acceptance_verified",
    "external_wpml_test_url",
    "exact_approval_required_for_remote_write",
    "approval_planner_bootstrap_exception",
    "external_client_tools_verified",
    "MAD4B_SCP_Audit::record",
]:
    if marker not in cert:
        raise SystemExit(f'missing write certification invariant: {marker}')

# A missing WPML route inside an already-running MAD4B MCP request is diagnostic
# evidence only. Local write certification must not claim or require external WPML
# success; the real endpoint remains a separate mandatory live-acceptance gate.
for forbidden in [
    "$checks['wpml_query_parameters_preserved']",
    "$checks['wpml_internal_probe_ready_or_not_active']",
]:
    if forbidden in cert:
        raise SystemExit(f'write certification re-coupled to in-process WPML route state: {forbidden}')

if "add_action( 'wp_abilities_api_init', array( __CLASS__, 'observe' )" in cert:
    raise SystemExit('write certification must not inspect REST before MCP transports are constructed')
if cert.index("MAD4B_SCP_Staging_Write_Authority::eligible()") > cert.index("MAD4B_SCP_Audit::record"):
    raise SystemExit('write certification eligibility must be checked before any audit persistence')
if cert.index("MAD4B_SCP_Staging_Write_Authority::eligible()") > cert.index("update_option( self::OPTION"):
    raise SystemExit('write certification eligibility must be checked before option persistence')

for marker in [
    'class-mad4b-scp-staging-write-authority.php',
    'class-mad4b-scp-staging-write-planning-guard.php',
    'class-mad4b-scp-rest-compatibility.php',
    'class-mad4b-scp-write-runtime-certification.php',
    'MAD4B_SCP_Staging_Write_Authority::bootstrap()',
]:
    if marker not in main:
        raise SystemExit(f'main plugin missing write/REST component: {marker}')

for marker in [
    'MAD4B_SCP_Staging_Write_Authority::boot()',
    'MAD4B_SCP_Staging_Write_Planning_Guard::boot()',
    'MAD4B_SCP_REST_Compatibility::boot()',
    'MAD4B_SCP_Write_Runtime_Certification::boot()',
]:
    if marker not in plugin:
        raise SystemExit(f'plugin lifecycle missing write/REST boot: {marker}')

for marker in [
    "array( 'Read', 'Write' )",
    "'governed_write_ready'",
    "'write_certification_ready'",
]:
    if marker not in exporter:
        raise SystemExit(f'runtime portable export missing governed Write state: {marker}')

caps = portable['extensions']['com.openai']['interface'].get('capabilities', [])
if caps != ['Read', 'Write']:
    raise SystemExit(f'portable Plugin capability contract must be [Read, Write], got {caps!r}')

print('mad4b.staging-write-authority.v5: PASS')
