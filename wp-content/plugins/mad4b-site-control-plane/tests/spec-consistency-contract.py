#!/usr/bin/env python3
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
PLUGIN = REPO / 'wp-content/plugins/mad4b-site-control-plane'
SPEC = REPO / 'specs/006-agent-governed-reversible-control-plane'
CONSTITUTION = REPO / '.specify/memory/constitution.md'
CONNECTION = SPEC / 'contracts/connection-readiness.md'
ADAPTER_COVERAGE = SPEC / 'contracts/adapter-coverage.md'

required_files = [
    CONSTITUTION,
    SPEC / 'spec.md',
    SPEC / 'plan.md',
    SPEC / 'research.md',
    SPEC / 'data-model.md',
    SPEC / 'quickstart.md',
    SPEC / 'tasks.md',
    SPEC / 'contracts/abilities.md',
    CONNECTION,
    ADAPTER_COVERAGE,
    SPEC / 'references/competitive-deep-research-attachments-only.md',
]
for path in required_files:
    if not path.is_file() or not path.read_text('utf-8').strip():
        raise SystemExit(f'FAIL required-spec-file: {path.relative_to(REPO)}')

def read(path): return path.read_text('utf-8')
def require(text, needle, label):
    if needle not in text: raise SystemExit(f'FAIL {label}: missing {needle!r}')
def forbid(text, needle, label):
    if needle in text: raise SystemExit(f'FAIL {label}: forbidden stale/unsafe text {needle!r}')

constitution = read(CONSTITUTION)
spec = read(SPEC / 'spec.md')
data_model = read(SPEC / 'data-model.md')
abilities_contract = read(SPEC / 'contracts/abilities.md')
connection_contract = read(CONNECTION)
adapter_contract = read(ADAPTER_COVERAGE)
tasks = read(SPEC / 'tasks.md')

for marker in (
    'C1 — One privileged mutation authority',
    'C3 — Fail closed by default',
    'C4 — Least privilege is an intersection',
    'C6 — Source code is deployment-plane only',
    'C8 — Every important mutation is planned, approved when required, verified and reversible when possible',
    'C11 — Credentials never belong in URLs or audit logs',
    'C12 — Audit is security evidence',
    'C13 — Rate limits and mutation budgets limit blast radius',
    'C15 — CI proves denials, not only success',
    'C16 — Documentation cannot outrun implementation',
): require(constitution, marker, 'constitution-invariant')

for marker in (
    'INV-001:', 'INV-007:', 'INV-009:', 'INV-013:', 'INV-016:', 'INV-018:',
    'INV-021:', 'INV-022:', 'INV-023:', 'INV-024:', 'US10 — `mad4b-write` exact transport isolation',
    'FR-024', 'FR-025', 'FR-026', 'FR-063', 'FR-064', 'FR-065',
    'SEC-001', 'SEC-002', 'SEC-008', 'SEC-013', 'SEC-014', 'Definition of done',
): require(spec, marker, 'feature-spec-invariant')

for marker in (
    'mad4b/runtime-authority-status', 'mad4b/agent-list', 'mad4b/agent-effective-access',
    'mad4b/approval-plan', 'mad4b/mutation-get', 'mad4b/mutation-undo',
    'No `*`, regex, glob or prefix scopes', "current_user_can('manage_options')", 'nonce',
): require(abilities_contract, marker, 'ability-contract-invariant')

for marker in (
    'mad4b.connection-readiness.v4', 'Local transport ready', 'Remote endpoint preflight ready',
    'Connection certified', 'mad4b/connection-status', 'mad4b-chatgpt', 'mad4b-write',
    'MAD4B_SCP_Transport_Context', 'different authority coordinates',
    'No self-probe / SSRF boundary', 'Foreign MCP transport governance',
    'Explicit provider MCP isolation', 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED',
    'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED', 'unknown_routes_fail_closed=true',
    'mcp_foreign_transport_unreviewed', 'mcp_write_side_channel_detected',
    'external handshake', 'Production write remains NO-GO',
): require(connection_contract, marker, 'connection-contract-invariant')
for stale in (
    'Contract: `mad4b.connection-readiness.v1`',
    'Contract: `mad4b.connection-readiness.v2`',
    'all four MAD4B custom servers',
    'The control plane owns four isolated MCP server IDs',
    'all four runtime-derived MAD4B endpoints',
    'The control plane owns five isolated MCP server IDs',
    'all five runtime-derived MAD4B endpoints',
    'all five MAD4B servers register',
    'exactly the WordPress `mad4b-read` endpoint',
): forbid(connection_contract, stale, 'connection-documentation-drift')

for marker in (
    'mad4b.adapter-coverage.v1',
    'Repository package inventory',
    'Runtime installed-plugin inventory',
    'mad4b/plugin-adapter-coverage',
    'mad4b/adapter-support-requests',
    'WooCommerce', 'Polylang',
    'mad4b.rollback.adapter.v1',
    'mad4b.rollback.media-metadata.v1',
    'mad4b.rollback.rank-math-meta.v1',
    'mad4b.rollback.woocommerce-product.v1',
    'mad4b.rollback.polylang-post-language.v1',
    'mad4b.rollback.jetengine-post-meta.v1',
    'adapter_present_side_channel_blocked',
    'parallel_mcp_write_plane_requires_isolation',
    'native_mcp_isolation',
    'jet-engine/v1',
    'Unknown plugin write default is always **DENY**',
    'T103 real Staging remains a separate mandatory boundary',
): require(adapter_contract, marker, 'adapter-coverage-contract-invariant')

require(data_model, 'Schema version: `4`', 'data-model-schema-v4')
for table in (
    'mad4b_scp_agents', 'mad4b_scp_agent_subjects', 'mad4b_scp_agent_grants',
    'mad4b_scp_approval_tickets', 'mad4b_scp_mutations', 'mad4b_scp_agent_budgets',
    'mad4b_scp_agent_budget_windows', 'mad4b_scp_audit_events', 'mad4b_scp_audit_heads',
): require(data_model, table, 'data-model-table')
for stale in (
    'Initial migration target: `2`',
    'Initial implementation may use atomic transients/options for counters',
    'audit chain: current option model retained in first implementation',
    'future append-only table',
    'schema is prepared for transactional append-only table migration',
): forbid(data_model + '\n' + spec, stale, 'documentation-drift')

implementation_files = {
    'schema': PLUGIN / 'includes/class-mad4b-scp-schema.php',
    'identity': PLUGIN / 'includes/class-mad4b-scp-identity-context.php',
    'registry': PLUGIN / 'includes/class-mad4b-scp-agent-registry.php',
    'adapter_registry': PLUGIN / 'includes/class-mad4b-scp-adapter-registry.php',
    'plugin_discovery': PLUGIN / 'includes/class-mad4b-scp-plugin-discovery.php',
    'reversible_adapter': PLUGIN / 'includes/class-mad4b-scp-reversible-adapter-mutations.php',
    'adapter_admin': PLUGIN / 'includes/class-mad4b-scp-adapter-coverage-admin-ui.php',
    'authz': PLUGIN / 'includes/class-mad4b-scp-authorization.php',
    'approval': PLUGIN / 'includes/class-mad4b-scp-approval-tickets.php',
    'budgets': PLUGIN / 'includes/class-mad4b-scp-budgets.php',
    'peer': PLUGIN / 'includes/class-mad4b-scp-mcp-peer-governance.php',
    'isolation': PLUGIN / 'includes/class-mad4b-scp-mcp-provider-isolation.php',
    'transport': PLUGIN / 'includes/class-mad4b-scp-transport-context.php',
    'connection': PLUGIN / 'includes/class-mad4b-scp-connection-status.php',
    'external_evidence': PLUGIN / 'includes/class-mad4b-scp-external-handshake-evidence.php',
    'connection_ability': PLUGIN / 'includes/class-mad4b-scp-connection-ability.php',
    'connection_admin': PLUGIN / 'includes/class-mad4b-scp-connection-admin-ui.php',
    'servers': PLUGIN / 'includes/class-mad4b-scp-servers.php',
    'mutation': PLUGIN / 'includes/class-mad4b-scp-mutation-manager.php',
    'audit': PLUGIN / 'includes/class-mad4b-scp-audit.php',
    'audit_integrity': PLUGIN / 'includes/class-mad4b-scp-audit-integrity.php',
    'policy': PLUGIN / 'includes/class-mad4b-scp-policy.php',
    'provider': PLUGIN / 'includes/class-mad4b-scp-provider-contracts.php',
    'admin': PLUGIN / 'includes/class-mad4b-scp-admin-ui.php',
    'media': PLUGIN / 'includes/adapters/class-mad4b-scp-media-adapter.php',
    'seo': PLUGIN / 'includes/adapters/class-mad4b-scp-seo-adapter.php',
    'woocommerce': PLUGIN / 'includes/adapters/class-mad4b-scp-woocommerce-adapter.php',
    'polylang': PLUGIN / 'includes/adapters/class-mad4b-scp-polylang-adapter.php',
    'jetengine': PLUGIN / 'includes/adapters/class-mad4b-scp-jetengine-adapter.php',
}
for label, path in implementation_files.items():
    if not path.is_file(): raise SystemExit(f'FAIL implementation-file-{label}: missing {path.relative_to(REPO)}')
impl = {name: read(path) for name, path in implementation_files.items()}

require(impl['schema'], 'const VERSION = 4;', 'implementation-schema-v4')
require(impl['schema'], "'budget_windows'", 'implementation-budget-windows')
require(impl['schema'], "'audit_events'", 'implementation-audit-events')
require(impl['schema'], "'audit_heads'", 'implementation-audit-heads')
require(impl['identity'], 'mad4b_scp_authenticated_subject_context', 'implementation-subject-bridge')
require(impl['registry'], 'mad4b_wildcard_grant_denied', 'implementation-wildcard-denial')
require(impl['authz'], 'exact_grant', 'implementation-exact-grant')
require(impl['authz'], 'MAD4B_SCP_Transport_Context::resolve_server_for_ability', 'implementation-effective-transport-binding')
require(impl['authz'], 'MAD4B_SCP_Budgets::reserve', 'implementation-budget-before-effect')
require(impl['authz'], 'MAD4B_SCP_Approval_Tickets::consume_exact', 'implementation-exact-approval')
if impl['authz'].index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > impl['authz'].index('MAD4B_SCP_Agent_Registry::exact_grant'):
    raise SystemExit('FAIL implementation-transport-before-grant')
if impl['authz'].index('MAD4B_SCP_Transport_Context::resolve_server_for_ability') > impl['authz'].index('MAD4B_SCP_Approval_Tickets::consume_exact'):
    raise SystemExit('FAIL implementation-transport-before-approval')
require(impl['peer'], 'mcp_write_side_channel_detected', 'implementation-side-channel-blocker')
require(impl['peer'], 'foreign_transport_inventory', 'implementation-foreign-mcp-inventory')
require(impl['peer'], 'mcp_foreign_transport_unreviewed', 'implementation-foreign-mcp-blocker')
require(impl['peer'], 'HOSTINGER_BANNER_CONTROL_ROUTE', 'implementation-reviewed-hostinger-banner-control')
require(impl['peer'], 'reviewed_non_transport_routes', 'implementation-reviewed-nontransport-inventory')
for marker in (
    'mad4b.mcp-provider-isolation.v2',
    "const ENABLE_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_ENABLED'",
    "const PRODUCTION_APPROVAL_FLAG = 'MAD4B_MCP_PROVIDER_ISOLATION_PRODUCTION_APPROVED'",
    "add_filter( 'wpmedia_mcp_oauth_server_enabled'",
    'filter_wpmedia_oauth_server_enabled',
    "add_filter( 'mcp_adapter_create_default_server'",
    "add_filter( 'rest_endpoints'",
    "'unknown_routes_fail_closed' => true",
    "'changes_provider_settings' => false",
    "'creates_authority' => false",
): require(impl['isolation'], marker, 'implementation-provider-isolation')
for forbidden in ('update_option(', 'add_option(', 'delete_option(', 'wp_remote_get(', 'wp_remote_post('):
    forbid(impl['isolation'], forbidden, 'implementation-provider-isolation-deny-only')
require(impl['transport'], 'mad4b.mcp-transport-context.v1', 'implementation-transport-context')
require(impl['transport'], "'/mcp/' . $server_id", 'implementation-transport-exact-route')
require(impl['transport'], 'mad4b_transport_route_mismatch', 'implementation-transport-route-mismatch')
require(impl['transport'], 'MAD4B_SCP_Servers::ability_is_mounted', 'implementation-transport-mount-check')
require(impl['connection'], 'mad4b.connection-readiness.v4', 'implementation-connection-readiness')
forbid(impl['connection'], "'connection_certified' => false", 'implementation-no-permanent-false')
require(impl['connection'], 'MAD4B_SCP_External_Handshake_Evidence::status()', 'implementation-external-evidence-readback')
require(impl['connection'], '$connection_certified = empty( $certification_blockers )', 'implementation-certified-from-bounded-blockers')
require(impl['connection'], "'external_handshake_unverified'", 'implementation-unverified-handshake-blocker')
require(impl['connection'], "'external_handshake_stale'", 'implementation-stale-handshake-blocker')
for marker in (
    'mad4b.external-handshake-evidence.v1',
    "const CHATGPT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'",
    "const SERVER_ID = 'mad4b-chatgpt'",
    "defined( 'REST_REQUEST' )", "defined( 'WP_CLI' ) && WP_CLI",
    'verified_bearer_active()', "'initialize'", "'tools/list'",
    "hash( 'sha256', $session_id )", "update_option( self::OPTION, $evidence, false )",
    "'credential_material_stored' => false", "'stale_build_evidence'", 'build_fingerprint()',
): require(impl['external_evidence'], marker, 'implementation-external-handshake-evidence')
for forbidden in ("'access_token' =>", "'refresh_token' =>", "'authorization_header' =>", "'raw_token' =>", 'wp_remote_get(', 'wp_remote_post('):
    forbid(impl['external_evidence'], forbidden, 'implementation-external-evidence-secret-free')
require(impl['connection'], "'write_surface'", 'implementation-write-readiness')
require(impl['connection'], "'provider_mcp_isolation'", 'implementation-isolation-readiness')
require(impl['connection_ability'], "const ABILITY = 'mad4b/connection-status'", 'implementation-connection-ability')
require(impl['servers'], "'mad4b/runtime-authority-status', 'mad4b/connection-status'", 'implementation-connection-read-server')
require(impl['servers'], "'mad4b-chatgpt'", 'implementation-chatgpt-server')
require(impl['servers'], "'mad4b-write'", 'implementation-write-server')
require(impl['servers'], 'public static function write_tools()', 'implementation-write-projection')
require(impl['servers'], "array_key_exists( 'readonly', $annotations )", 'implementation-explicit-write-annotation')
require(impl['servers'], "false !== $annotations['readonly']", 'implementation-readonly-exclusion')
require(impl['servers'], "array( __CLASS__, 'can_write_transport' )", 'implementation-write-transport-permission')
require(impl['connection_admin'], "'manage_options'", 'implementation-connection-admin-capability')
require(impl['connection_admin'], 'Governed write ingress', 'implementation-write-readiness-ui')
require(impl['connection_admin'], 'Exact transport grant required', 'implementation-write-grant-ui')
require(impl['connection_admin'], 'Provider MCP isolation', 'implementation-provider-isolation-ui')
require(impl['connection_admin'], 'Unknown routes fail closed', 'implementation-provider-isolation-fail-closed-ui')
for forbidden in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete(', 'wp_remote_get(', 'wp_remote_post(', 'wp_remote_request('):
    forbid(impl['connection_admin'] + '\n' + impl['connection'], forbidden, 'connection-surface-read-only')
require(impl['mutation'], 'mad4b_undo_state_drift', 'implementation-drift-safe-undo')
require(impl['mutation'], 'read-after-write', 'implementation-readback')
require(impl['audit'], 'mad4b_scp_audit_committed', 'implementation-post-commit-audit-hook')
require(impl['audit_integrity'], 'verify_chain', 'implementation-audit-verifier')
require(impl['policy'], 'MAD4B_MCP_MUTATION_ENABLED', 'implementation-mutation-kill-switch')
require(impl['provider'], 'mutation_guard', 'implementation-provider-guard')
require(impl['admin'], "'manage_options'", 'implementation-admin-capability')
for forbidden in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete('):
    forbid(impl['admin'], forbidden, 'admin-ui-read-only')

for marker in (
    'mad4b/plugin-adapter-coverage', 'mad4b/adapter-support-requests', 'reversible_adapter_count',
): require(impl['adapter_registry'], marker, 'implementation-adapter-registry')
for marker in (
    'mad4b.plugin-adapter-discovery.v1', 'get_plugins()', "'auto_install' => false", "'auto_generate_adapter' => false",
    'adapter_present_certification_required', 'adapter_present_side_channel_blocked',
    'parallel_mcp_write_plane_requires_isolation', 'mcp_foreign_transport_unreviewed',
): require(impl['plugin_discovery'], marker, 'implementation-plugin-discovery')
for marker in (
    'mad4b.rollback.adapter.v1', 'rollback_payload_sha256', 'mad4b_undo_state_drift',
    'provider_restore_guard', 'MAD4B_SCP_Provider_Contracts::mutation_guard', 'provider_readback_recorded',
): require(impl['reversible_adapter'], marker, 'implementation-reversible-adapter')
for marker in ('manage_options', 'Adapter Coverage', 'adapter_present_side_channel_blocked', 'Runtime blocker'):
    require(impl['adapter_admin'], marker, 'implementation-adapter-admin')
for forbidden in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete('):
    forbid(impl['adapter_admin'], forbidden, 'adapter-admin-read-only')

for label, marker in (
    ('media', 'mad4b.rollback.media-metadata.v1'),
    ('seo', 'mad4b.rollback.rank-math-meta.v1'),
    ('woocommerce', 'mad4b.rollback.woocommerce-product.v1'),
    ('polylang', 'mad4b.rollback.polylang-post-language.v1'),
    ('jetengine', 'mad4b.rollback.jetengine-post-meta.v1'),
): require(impl[label], marker, f'implementation-{label}-rollback-contract')
require(impl['woocommerce'], 'products_only_no_orders_payments_refunds', 'implementation-woocommerce-products-only')
require(impl['polylang'], 'mad4b_polylang_unassigned_not_reversible', 'implementation-polylang-unassigned-denial')
require(impl['jetengine'], 'mad4b_scp_jetengine_field_write_allowed', 'implementation-jetengine-exact-field-policy')

adapter_static = PLUGIN / 'tests/adapter-discovery-reversibility-contract.py'
adapter_runtime = PLUGIN / 'tests/runtime-plugin-adapter-discovery-smoke.php'
adapter_reversible_runtime = PLUGIN / 'tests/runtime-reversible-adapter-smoke.php'
jetengine_runtime = PLUGIN / 'tests/runtime-jetengine-reversible-adapter-smoke.php'
isolation_static = PLUGIN / 'tests/mcp-provider-isolation-contract.py'
isolation_runtime = PLUGIN / 'tests/runtime-mcp-provider-isolation-smoke.php'
adapter_workflow = REPO / '.github/workflows/mad4b-adapter-coverage.yml'
connection_workflow = REPO / '.github/workflows/mad4b-connection-governance.yml'
for path in (adapter_static, adapter_runtime, adapter_reversible_runtime, jetengine_runtime, isolation_static, isolation_runtime, adapter_workflow, connection_workflow):
    if not path.is_file(): raise SystemExit(f'FAIL evidence-file: missing {path.relative_to(REPO)}')
require(read(adapter_static), 'mad4b.site-control-plane.adapter-discovery-reversibility.v2', 'adapter-static-v2')
require(read(adapter_runtime), 'mad4b.site-control-plane.runtime-plugin-adapter-discovery.v1', 'adapter-discovery-runtime')
require(read(adapter_reversible_runtime), 'mad4b.site-control-plane.runtime-reversible-adapter.v1', 'adapter-reversible-runtime')
require(read(jetengine_runtime), 'mad4b.site-control-plane.runtime-jetengine-side-channel-boundary.v1', 'jetengine-side-channel-runtime')
require(read(isolation_static), 'mad4b.site-control-plane.mcp-provider-isolation-contract.v3', 'provider-isolation-static')
require(read(isolation_runtime), 'mad4b.site-control-plane.runtime-mcp-provider-isolation.v3', 'provider-isolation-runtime')
for marker in ('Repository plugin adapter coverage contract', 'Core adapter runtime', 'JetEngine adapter boundary'):
    require(read(adapter_workflow), marker, 'adapter-coverage-workflow')
for marker in ('Prove explicit provider MCP isolation remains deny-only', 'mcp-provider-isolation-contract.py', 'class-mad4b-scp-external-handshake-evidence.php'):
    require(read(connection_workflow), marker, 'connection-isolation-workflow')

require(tasks, '- [x] T006 Dedicated `MAD4B Spec Consistency` CI', 'tasks-spec-gate-complete')
require(tasks, 'c2d7ba3d900097be35b6d2311f603a0c77f2d338', 'tasks-admin-runtime-checkpoint')
require(tasks, 'Runtime UI smoke PASS on WordPress 6.9/latest', 'tasks-admin-runtime-proof')
require(tasks, 'Production write remains NO-GO', 'tasks-production-no-go')
require(tasks, 'T103 — Real target staging', 'tasks-staging-gate')

print('mad4b.site-control-plane.spec-consistency.v6: PASS')
