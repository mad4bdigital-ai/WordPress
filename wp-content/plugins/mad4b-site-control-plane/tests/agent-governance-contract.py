#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel):
    return (ROOT / rel).read_text('utf-8')


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


schema = read('includes/class-mad4b-scp-schema.php')
identity = read('includes/class-mad4b-scp-identity-context.php')
registry = read('includes/class-mad4b-scp-agent-registry.php')
authz = read('includes/class-mad4b-scp-authorization.php')
impact = read('includes/class-mad4b-scp-impact-policy.php')
approvals = read('includes/class-mad4b-scp-approval-tickets.php')
budgets = read('includes/class-mad4b-scp-budgets.php')
audit = read('includes/class-mad4b-scp-audit.php')
audit_verify = read('includes/class-mad4b-scp-audit-verifier.php')
mutation = read('includes/class-mad4b-scp-mutation-manager.php')
overrides = read('includes/class-mad4b-scp-governed-ability-overrides.php')
governance = read('includes/class-mad4b-scp-governance-abilities.php')
policy = read('includes/class-mad4b-scp-policy.php')
adapter = read('includes/adapters/class-mad4b-scp-adapter-base.php')
adapter_registry = read('includes/class-mad4b-scp-adapter-registry.php')
abilities = read('includes/class-mad4b-scp-abilities.php')
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

# Schema authority must be normalized and migration must not seed authority.
require(schema, 'const VERSION = 4;', 'schema-version')
for table in (
    'mad4b_scp_agents', 'mad4b_scp_agent_subjects', 'mad4b_scp_agent_grants',
    'mad4b_scp_approval_tickets', 'mad4b_scp_mutations', 'mad4b_scp_agent_budgets',
    'mad4b_scp_agent_budget_windows', 'mad4b_scp_audit_events', 'mad4b_scp_audit_heads',
):
    require(schema, table, 'schema-table')
for dangerous_seed in ("status = 'enabled'", 'grant_ability('):
    forbid(schema, dangerous_seed, 'no-default-authority-seed')
require(plugin, 'MAD4B_SCP_Schema::install_or_upgrade()', 'schema-migration')

# Identity context must reject secret material and wildcard scopes and may carry only an opaque approval ID.
require(identity, 'mad4b_scp_authenticated_subject_context', 'subject-context-hook')
require(identity, 'mad4b_identity_secret_field_denied', 'raw-secret-denial')
require(identity, 'mad4b_identity_wildcard_scope_denied', 'wildcard-scope-denial')
require(identity, "hash( 'sha256', $type . \"\\0\" . $identifier )", 'subject-fingerprint')
require(identity, "'approval_ticket_id'", 'approval-context-id')
for forbidden_url_pattern in ('/mcp/{token}', '/mcp/{api_key}', 'HTTP_AUTHORIZATION] ='):
    forbid(bootstrap + identity + authz, forbidden_url_pattern, 'no-token-url')

# Agent registry uses exact grants, mounted-server/provider binding and deny precedence.
require(registry, 'mad4b_wildcard_grant_denied', 'wildcard-grant-denial')
require(registry, 'mad4b_nhi_subject_unbound', 'unbound-subject-denial')
require(registry, 'mad4b_nhi_agent_disabled', 'disabled-agent-denial')
require(registry, "if ( 'deny' === $row['effect'] )", 'deny-precedence')
require(registry, "if ( 'allow' === $row['effect'] )", 'exact-allow')
require(registry, 'MAD4B_SCP_Servers::provider_for_ability', 'grant-server-provider-binding')
require(registry, 'mad4b_grant_server_ability_mismatch', 'grant-server-ability-denial')
require(registry, 'mad4b_grant_provider_mismatch', 'grant-provider-denial')
require(registry, 'mad4b_scp_allow_breakglass_grant_creation', 'breakglass-exception-hook')
require(registry, 'mad4b_breakglass_grant_creation_denied', 'breakglass-default-denial')

# Server membership has a single provider-aware source of truth.
require(servers, 'public static function provider_for_ability', 'server-provider-resolver')
require(servers, "return 'core';", 'core-provider-binding')
require(servers, '$adapter->provider_key()', 'adapter-provider-binding-from-server')
require(adapter, 'public function provider_key()', 'adapter-public-provider-key')

# Global mutation requires schema + authenticated bound NHI in addition to the constant.
require(policy, "defined( 'MAD4B_MCP_MUTATION_ENABLED' )", 'global-mutation-gate')
require(policy, 'MAD4B_SCP_Schema::is_ready()', 'schema-required-for-mutation')
require(policy, 'MAD4B_SCP_Identity_Context::current()', 'identity-required-for-mutation')
require(policy, 'MAD4B_SCP_Agent_Registry::resolve_agent', 'bound-agent-required-for-mutation')

# All core and adapter writers call the central exact authorization engine.
require(adapter, 'MAD4B_SCP_Authorization::authorize_mutation', 'adapter-central-authorization')
require(adapter, "'mad4b-' . sanitize_key( $surface )", 'adapter-server-binding')
require(adapter, '$this->certified_provider_key()', 'adapter-provider-binding')
require(abilities, "MAD4B_SCP_Authorization::authorize_mutation( $ability_name, $server_id, 'core', $input )", 'core-central-authorization')
require(abilities, 'mad4b/runtime-authority-status', 'authority-status-ability')
require(servers, 'mad4b/runtime-authority-status', 'authority-status-server-mount')

# Central authorization intersects exact grant/token scopes/resource constraints, budget and approval.
require(authz, 'MAD4B_SCP_Agent_Registry::exact_grant', 'exact-grant-intersection')
require(authz, "'ability:' . $ability_name", 'exact-scope-intersection')
require(authz, 'mad4b_scp_require_token_scopes', 'scope-policy')
require(authz, 'mad4b_nhi_resource_constraints_unresolved', 'constraint-fail-closed')
require(authz, 'MAD4B_SCP_Impact_Policy::requires_approval', 'impact-approval-gate')
require(authz, 'MAD4B_SCP_Budgets::reserve', 'budget-reservation-before-approval')
require(authz, 'MAD4B_SCP_Budgets::rollback', 'budget-rollback-on-approval-denial')
require(authz, 'MAD4B_SCP_Budgets::commit', 'budget-commit-after-approval')
require(authz, 'MAD4B_SCP_Approval_Tickets::consume_exact', 'approval-consume-gate')
if authz.index('MAD4B_SCP_Budgets::reserve') > authz.index('MAD4B_SCP_Approval_Tickets::consume_exact'):
    raise SystemExit('FAIL budget-before-approval: budget reservation must occur before approval consumption')
require(authz, 'mad4b_approval_required', 'missing-approval-denial')
require(authz, 'public static function target_fingerprint', 'target-fingerprint-single-source')
require(authz, 'self::target_fingerprint', 'authorization-target-resolver-use')
require(authz, "'budget' => array(", 'budget-decision-evidence')
require(authz, 'budget_service_ready', 'budget-authority-status')
require(authz, 'mutation_global_enabled', 'configured-mutation-status')
require(authz, 'mutation_effective_for_request', 'effective-mutation-status')
require(authz, 'mad4b/authorization:', 'authorization-audit')

# Transactional budgets use bounded types/windows/costs and no option-based counters.
for budget_type in ("'requests'", "'mutations'", "'affected_objects'", "'external_actions'"):
    require(budgets, budget_type, 'budget-type')
require(budgets, 'MIN_WINDOW_SECONDS = 60', 'budget-min-window')
require(budgets, 'MAX_WINDOW_SECONDS = 604800', 'budget-max-window')
require(budgets, 'MAX_COUNT = 1000000', 'budget-max-count')
require(budgets, 'MAX_COST = 100000', 'budget-max-cost')
require(budgets, "START TRANSACTION", 'budget-transaction-start')
require(budgets, 'FOR UPDATE', 'budget-row-lock')
require(budgets, 'INSERT IGNORE', 'budget-window-race-safe-create')
require(budgets, 'mad4b_budget_exhausted', 'budget-exhaustion-denial')
require(budgets, 'mad4b_budget_concurrency_conflict', 'budget-concurrency-denial')
require(budgets, "array( 'innodb', 'xtradb' )", 'budget-transactional-engine')
require(budgets, "DELETE FROM {$t['budget_windows']}", 'budget-bounded-cleanup')
require(budgets, 'CLEANUP_LIMIT = 200', 'budget-cleanup-bound')
require(budgets, 'mad4b_scp_budget_costs', 'budget-cost-policy-hook')
require(budgets, 'public static function set_budget', 'budget-config-service')
for forbidden_counter in ('update_option(', 'add_option(', 'set_transient(', 'wp_cache_incr('):
    forbid(budgets, forbidden_counter, 'budget-no-option-cache-counter')
require(bootstrap, 'class-mad4b-scp-budgets.php', 'budget-bootstrap-load')

# Append-only audit storage is durable, concurrent-safe and tamper evident.
require(audit, 'mad4b_scp_audit_events', 'audit-events-table-use')
require(audit, 'mad4b_scp_audit_heads', 'audit-heads-table-use')
require(audit, 'FOR UPDATE', 'audit-head-lock')
require(audit, 'previous_hash', 'audit-previous-hash')
require(audit, 'entry_hash', 'audit-entry-hash')
require(audit, 'legacy', 'audit-legacy-anchor')
require(audit, 'mad4b_scp_audit_committed', 'audit-post-commit-hook')
require(audit, 'SUMMARY_MAX_DEPTH', 'audit-summary-depth-bound')
require(audit, 'SUMMARY_MAX_ITEMS', 'audit-summary-item-bound')
require(audit_verify, 'verify_chain', 'audit-chain-verifier')
require(audit_verify, 'legacy', 'audit-legacy-verification')
for forbidden_event_mutation in ("UPDATE {$t['audit_events']}", "DELETE FROM {$t['audit_events']}"):
    forbid(audit + audit_verify, forbidden_event_mutation, 'audit-events-immutable')
for forbidden_option_write in ('update_option( self::OPTION', 'add_option( self::OPTION'):
    forbid(audit, forbidden_option_write, 'audit-no-option-event-write')

# Impact policy is conservative: admin writers and undo high, raw DB exceptional, non-core adapters high.
require(impact, "'mad4b/database-raw-query'", 'breakglass-exceptional')
require(impact, "'mad4b/plugin-activate'", 'plugin-high-impact')
require(impact, "'mad4b/database-update'", 'db-high-impact')
require(impact, "'mad4b/mutation-undo'", 'undo-high-impact')
require(impact, "'core' !== $provider && 'media' !== $provider", 'adapter-high-default')
require(impact, "array( 'high', 'exceptional' )", 'approval-required-high')

# Approval tickets bind one exact canonical operation, expire, and are single-use/replay resistant.
require(approvals, "'contract' => 'mad4b.approval.v1'", 'approval-contract-version')
for field in ("'site'", "'agent_public_id'", "'server_id'", "'ability'", "'provider'", "'target'", "'ticket_class'", "'input'"):
    require(approvals, field, 'approval-envelope-field')
require(approvals, 'MAX_CANONICAL_BYTES = 65536', 'approval-size-bound')
require(approvals, 'MAX_DEPTH = 8', 'approval-depth-bound')
require(approvals, 'MAX_TTL = 3600', 'approval-ttl-bound')
require(approvals, "status = 'approved'", 'approval-atomic-use-precondition')
require(approvals, "SET status = 'used'", 'approval-single-use-transition')
require(approvals, 'hash_equals', 'approval-hash-compare')
require(approvals, 'mad4b_approval_replay_denied', 'approval-replay-denial')
require(approvals, 'mad4b_approval_payload_mismatch', 'approval-payload-denial')
require(approvals, "array( 'mutation', 'breakglass', 'recovery' )", 'approval-class-separation')
for forbidden in ('serialize(', 'unserialize('):
    forbid(approvals, forbidden, 'approval-no-php-serialization')

# Governance visibility is admin-only, non-secret, deny-aware and never auto-approves.
for ability_name in ("'mad4b/agent-list'", "'mad4b/agent-effective-access'", "'mad4b/approval-plan'"):
    require(governance, ability_name, 'governance-ability-registration')
    require(servers, ability_name, 'governance-admin-server-mount')
    require(adapter_registry, ability_name, 'governance-runtime-self-test-inventory')
require(governance, "current_user_can( 'manage_options' )", 'governance-admin-capability')
require(governance, "if ( 'deny' === $row['effect'] )", 'effective-access-deny-precedence')
require(governance, "'conditional'", 'effective-access-conditional-constraints')
require(governance, 'MAD4B_SCP_Servers::provider_for_ability', 'approval-mounted-provider-resolver')
require(governance, 'mad4b_approval_provider_mismatch', 'approval-provider-mismatch-denial')
require(governance, 'mad4b_approval_annotation_missing', 'approval-mutation-annotation-fail-closed')
require(governance, 'mad4b_approval_not_required', 'approval-only-when-policy-requires')
require(governance, 'MAD4B_SCP_Authorization::target_fingerprint', 'approval-central-target-resolver')
require(governance, 'mad4b_approval_target_mismatch', 'approval-target-assertion-denial')
require(governance, "'auto_approved' => false", 'approval-never-auto-approved')
require(plugin, 'MAD4B_SCP_Governance_Abilities::boot()', 'governance-boot')
require(bootstrap, 'class-mad4b-scp-governance-abilities.php', 'governance-bootstrap-load')

# Reversible mutation pilot uses the supported WordPress ability-registration filter rather than duplicate registration/forking.
require(overrides, 'wp_register_ability_args', 'supported-ability-override-hook')
require(overrides, "'mad4b/content-update-post'", 'post-update-override')
require(overrides, 'MAD4B_SCP_Mutation_Manager::execute_post_update', 'post-update-manager-route')
require(overrides, "'mad4b.rollback.post.v1'", 'post-rollback-contract-meta')
require(overrides, "'mad4b/mutation-get'", 'mutation-get-registration')
require(overrides, "'mad4b/mutation-undo'", 'mutation-undo-registration')
require(overrides, "authorize_mutation( 'mad4b/mutation-undo', 'mad4b-admin', 'core', $input )", 'undo-central-authorization')
require(overrides, "unset( $record['rollback_payload'], $record['rollback_payload_sha256'] )", 'rollback-payload-not-exposed')
require(plugin, 'MAD4B_SCP_Governed_Ability_Overrides::boot()', 'override-boot')
require(servers, "'mad4b/mutation-get'", 'mutation-get-server-mount')
require(servers, "'mad4b/mutation-undo'", 'mutation-undo-server-mount')

# Mutation envelope is persisted before side effects, bounded, readback-verified and drift-safe on undo.
require(mutation, 'MAX_ROLLBACK_BYTES = 262144', 'rollback-size-bound')
require(mutation, "'contract' => 'mad4b.rollback.post.v1'", 'rollback-contract')
require(mutation, "'status' => 'planned'", 'mutation-planned-before-write')
require(mutation, "self::update_record( $mutation_id, array( 'status' => 'executing' ) )", 'mutation-executing-transition')
require(mutation, 'wp_update_post', 'provider-aware-wordpress-write')
require(mutation, 'verify_post_update', 'readback-verification')
require(mutation, "'status' => 'verified'", 'verified-transition')
require(mutation, 'before_sha256', 'before-state-hash')
require(mutation, 'after_sha256', 'after-state-hash')
require(mutation, 'rollback_payload_sha256', 'rollback-integrity-hash')
require(mutation, 'mad4b_undo_state_drift', 'undo-drift-denial')
require(mutation, 'mad4b_undo_payload_integrity_failed', 'undo-payload-integrity-denial')
require(mutation, "'parent_mutation_id' => $mutation_id", 'child-recovery-evidence')
require(mutation, "'verification_code' => 'restore_readback_match'", 'undo-readback-verification')
for forbidden in ('unserialize(', 'eval(', 'shell_exec('):
    forbid(mutation, forbidden, 'mutation-dangerous-primitive')

# Bootstrap order makes governance/mutation/budget/audit services available before plugin boot.
order = [
    'class-mad4b-scp-schema.php',
    'class-mad4b-scp-identity-context.php',
    'class-mad4b-scp-agent-registry.php',
    'class-mad4b-scp-policy.php',
    'class-mad4b-scp-audit.php',
    'class-mad4b-scp-audit-verifier.php',
    'class-mad4b-scp-provider-contracts.php',
    'class-mad4b-scp-impact-policy.php',
    'class-mad4b-scp-approval-tickets.php',
    'class-mad4b-scp-budgets.php',
    'class-mad4b-scp-authorization.php',
    'class-mad4b-scp-mutation-manager.php',
    'class-mad4b-scp-governed-ability-overrides.php',
    'class-mad4b-scp-abilities.php',
    'class-mad4b-scp-servers.php',
    'class-mad4b-scp-governance-abilities.php',
    'class-mad4b-scp-plugin.php',
]
pos = [bootstrap.index(x) for x in order]
if pos != sorted(pos):
    raise SystemExit('FAIL bootstrap-order: governance dependencies are loaded out of order')

print('mad4b.site-control-plane.agent-governance-contract.v6: PASS')
