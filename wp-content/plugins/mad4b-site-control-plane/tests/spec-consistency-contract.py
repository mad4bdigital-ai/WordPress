#!/usr/bin/env python3
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
PLUGIN = REPO / 'wp-content/plugins/mad4b-site-control-plane'
SPEC = REPO / 'specs/006-agent-governed-reversible-control-plane'
CONSTITUTION = REPO / '.specify/memory/constitution.md'

required_files = [
    CONSTITUTION,
    SPEC / 'spec.md',
    SPEC / 'plan.md',
    SPEC / 'research.md',
    SPEC / 'data-model.md',
    SPEC / 'quickstart.md',
    SPEC / 'tasks.md',
    SPEC / 'contracts/abilities.md',
    SPEC / 'references/competitive-deep-research-attachments-only.md',
]

for path in required_files:
    if not path.is_file() or not path.read_text('utf-8').strip():
        raise SystemExit(f'FAIL required-spec-file: {path.relative_to(REPO)}')


def read(path):
    return path.read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden stale/unsafe text {needle!r}')

constitution = read(CONSTITUTION)
spec = read(SPEC / 'spec.md')
data_model = read(SPEC / 'data-model.md')
abilities_contract = read(SPEC / 'contracts/abilities.md')
tasks = read(SPEC / 'tasks.md')

# Normative security principles must remain explicit.
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
):
    require(constitution, marker, 'constitution-invariant')

for marker in (
    'INV-001:', 'INV-007:', 'INV-009:', 'INV-013:', 'INV-016:', 'INV-018:',
    'SEC-001', 'SEC-002', 'SEC-008', 'Definition of done',
):
    require(spec, marker, 'feature-spec-invariant')

for marker in (
    'mad4b/runtime-authority-status',
    'mad4b/agent-list',
    'mad4b/agent-effective-access',
    'mad4b/approval-plan',
    'mad4b/mutation-get',
    'mad4b/mutation-undo',
    'No `*`, regex, glob or prefix scopes',
    "current_user_can('manage_options')",
    'nonce',
):
    require(abilities_contract, marker, 'ability-contract-invariant')

# The schema design must describe what the implementation actually persists now.
require(data_model, 'Schema version: `4`', 'data-model-schema-v4')
for table in (
    'mad4b_scp_agents',
    'mad4b_scp_agent_subjects',
    'mad4b_scp_agent_grants',
    'mad4b_scp_approval_tickets',
    'mad4b_scp_mutations',
    'mad4b_scp_agent_budgets',
    'mad4b_scp_agent_budget_windows',
    'mad4b_scp_audit_events',
    'mad4b_scp_audit_heads',
):
    require(data_model, table, 'data-model-table')
for stale in (
    'Initial migration target: `2`',
    'Initial implementation may use atomic transients/options for counters',
    'audit chain: current option model retained in first implementation',
    'future append-only table',
    'schema is prepared for transactional append-only table migration',
):
    forbid(data_model + '\n' + spec, stale, 'documentation-drift')

# Implementation must continue to satisfy the same normative controls.
implementation_files = {
    'schema': PLUGIN / 'includes/class-mad4b-scp-schema.php',
    'identity': PLUGIN / 'includes/class-mad4b-scp-identity-context.php',
    'registry': PLUGIN / 'includes/class-mad4b-scp-agent-registry.php',
    'authz': PLUGIN / 'includes/class-mad4b-scp-authorization.php',
    'approval': PLUGIN / 'includes/class-mad4b-scp-approval-tickets.php',
    'budgets': PLUGIN / 'includes/class-mad4b-scp-budgets.php',
    'peer': PLUGIN / 'includes/class-mad4b-scp-mcp-peer-governance.php',
    'mutation': PLUGIN / 'includes/class-mad4b-scp-mutation-manager.php',
    'audit': PLUGIN / 'includes/class-mad4b-scp-audit.php',
    'audit_integrity': PLUGIN / 'includes/class-mad4b-scp-audit-integrity.php',
    'policy': PLUGIN / 'includes/class-mad4b-scp-policy.php',
    'provider': PLUGIN / 'includes/class-mad4b-scp-provider-contracts.php',
    'admin': PLUGIN / 'includes/class-mad4b-scp-admin-ui.php',
}
for label, path in implementation_files.items():
    if not path.is_file():
        raise SystemExit(f'FAIL implementation-file-{label}: missing {path.relative_to(REPO)}')

impl = {name: read(path) for name, path in implementation_files.items()}
require(impl['schema'], 'const VERSION = 4;', 'implementation-schema-v4')
require(impl['schema'], "'budget_windows'", 'implementation-budget-windows')
require(impl['schema'], "'audit_events'", 'implementation-audit-events')
require(impl['schema'], "'audit_heads'", 'implementation-audit-heads')
require(impl['identity'], 'mad4b_scp_authenticated_subject_context', 'implementation-subject-bridge')
require(impl['registry'], 'mad4b_wildcard_grant_denied', 'implementation-wildcard-denial')
require(impl['authz'], 'exact_grant', 'implementation-exact-grant')
require(impl['authz'], 'MAD4B_SCP_Budgets::reserve', 'implementation-budget-before-effect')
require(impl['authz'], 'MAD4B_SCP_Approval_Tickets::consume_exact', 'implementation-exact-approval')
require(impl['peer'], 'mcp_write_side_channel_detected', 'implementation-side-channel-blocker')
require(impl['mutation'], 'mad4b_undo_state_drift', 'implementation-drift-safe-undo')
require(impl['mutation'], 'read-after-write', 'implementation-readback')
require(impl['audit'], 'mad4b_scp_audit_committed', 'implementation-post-commit-audit-hook')
require(impl['audit_integrity'], 'verify_chain', 'implementation-audit-verifier')
require(impl['policy'], 'MAD4B_MCP_MUTATION_ENABLED', 'implementation-mutation-kill-switch')
require(impl['provider'], 'mutation_guard', 'implementation-provider-guard')
require(impl['admin'], "'manage_options'", 'implementation-admin-capability')
for forbidden in ('$_POST', 'admin_post_', '$wpdb->insert(', '$wpdb->update(', '$wpdb->delete('):
    forbid(impl['admin'], forbidden, 'admin-ui-read-only')

# Task truth must acknowledge this gate and must never claim Production GO before target staging.
require(tasks, 'T006', 'tasks-spec-gate')
require(tasks, 'Production write remains NO-GO', 'tasks-production-no-go')
require(tasks, 'T103 — Real target staging', 'tasks-staging-gate')

print('mad4b.site-control-plane.spec-consistency.v1: PASS')
