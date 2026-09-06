#!/usr/bin/env python3
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
PLUGIN = REPO / 'wp-content/plugins/mad4b-site-control-plane'
SPEC = REPO / 'specs/006-agent-governed-reversible-control-plane'
CONSTITUTION = REPO / '.specify/memory/constitution.md'
CONNECTION = SPEC / 'contracts/connection-readiness.md'

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
    'mad4b.connection-readiness.v2', 'Local transport ready', 'Remote endpoint preflight ready',
    'Connection certified', 'mad4b/connection-status', 'mad4b-write',
    'MAD4B_SCP_Transport_Context', 'server/ability/provider',
    'No self-probe / SSRF boundary', 'Foreign MCP transport governance',
    'mcp_foreign_transport_unreviewed', 'mcp_write_side_channel_detected',
    'Production write remains NO-GO',
): require(connection_contract, marker, 'connection-contract-invariant')
for stale in (
    'Contract: `mad4b.connection-readiness.v1`',
    'all four MAD4B custom servers',
    'The control plane owns four isolated MCP server IDs',
    'all four runtime-derived MAD4B endpoints',
): forbid(connection_contract, stale, 'connection-documentation-drift')

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
    'authz': PLUGIN / 'includes/class-mad4b-scp-authorization.php',
    'approval': PLUGIN / 'includes/class-mad4b-scp-approval-tickets.php',
    'budgets': PLUGIN / 'includes/class-mad4b-scp-budgets.php',
    'peer': PLUGIN / 'includes/class-mad4b-scp-mcp-peer-governance.php',
    'transport': PLUGIN / 'includes/class-mad4b-scp-transport-context.php',
    'connection': PLUGIN / 'includes/class-mad4b-scp-connection-status.php',
    'connection_ability': PLUGIN / 'includes/class-mad4b-scp-connection-ability.php',
    'connection_admin': PLUGIN / 'includes/class-mad4b-scp-connection-admin-ui.php',
    'servers': PLUGIN / 'includes/class-mad4b-scp-servers.php',
    'mutation': PLUGIN / 'includes/class-mad4b-scp-mutation-manager.php',
    'audit': PLUGIN / 'includes/class-mad4b-scp-audit.php',
    'audit_integrity': PLUGIN / 'includes/class-mad4b-scp-audit-integrity.php',
    'policy': PLUGIN / 'includes/class-mad4b-scp-policy.php',
    'provider': PLUGIN / 'includes/class-mad4b-scp-provider-contracts.php',
    'admin': PLUGIN / 'includes/class-mad4b-scp-admin-ui.php',
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
require(impl['transport'], 'mad4b.mcp-transport-context.v1', 'implementation-transport-context')
require(impl['transport'], "'/mcp/' . $server_id", 'implementation-transport-exact-route')
require(impl['transport'], 'mad4b_transport_route_mismatch', 'implementation-transport-route-mismatch')
require(impl['transport'], 'MAD4B_SCP_Servers::ability_is_mounted', 'implementation-transport-mount-check')
require(impl['connection'], 'mad4b.connection-readiness.v2', 'implementation-connection-readiness')
require(impl['connection'], "'connection_certified' => false", 'implementation-no-self-certification')
require(impl['connection'], "'write_surface'", 'implementation-write-readiness')
require(impl['connection_ability'], "const ABILITY = 'mad4b/connection-status'", 'implementation-connection-ability')
require(impl['servers'], "'mad4b/runtime-authority-status', 'mad4b/connection-status'", 'implementation-connection-read-server')
require(impl['servers'], "'mad4b-write'", 'implementation-write-server')
require(impl['servers'], 'public static function write_tools()', 'implementation-write-projection')
require(impl['servers'], "array_key_exists( 'readonly', $annotations )", 'implementation-explicit-write-annotation')
require(impl['servers'], "false !== $annotations['readonly']", 'implementation-readonly-exclusion')
require(impl['servers'], "array( __CLASS__, 'can_write_transport' )", 'implementation-write-transport-permission')
require(impl['connection_admin'], "'manage_options'", 'implementation-connection-admin-capability')
require(impl['connection_admin'], 'Governed write ingress', 'implementation-write-readiness-ui')
require(impl['connection_admin'], 'Exact transport grant required', 'implementation-write-grant-ui')
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

require(tasks, '- [x] T006 Dedicated `MAD4B Spec Consistency` CI', 'tasks-spec-gate-complete')
require(tasks, 'c2d7ba3d900097be35b6d2311f603a0c77f2d338', 'tasks-admin-runtime-checkpoint')
require(tasks, 'Runtime UI smoke PASS on WordPress 6.9/latest', 'tasks-admin-runtime-proof')
require(tasks, 'Production write remains NO-GO', 'tasks-production-no-go')
require(tasks, 'T103 — Real target staging', 'tasks-staging-gate')

print('mad4b.site-control-plane.spec-consistency.v4: PASS')
