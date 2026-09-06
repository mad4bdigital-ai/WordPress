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
mutation = read('includes/class-mad4b-scp-mutation-manager.php')
overrides = read('includes/class-mad4b-scp-governed-ability-overrides.php')
policy = read('includes/class-mad4b-scp-policy.php')
adapter = read('includes/adapters/class-mad4b-scp-adapter-base.php')
abilities = read('includes/class-mad4b-scp-abilities.php')
servers = read('includes/class-mad4b-scp-servers.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

# Schema authority must be normalized and migration must not seed authority.
require(schema, 'const VERSION = 2;', 'schema-version')
for table in ('mad4b_scp_agents', 'mad4b_scp_agent_subjects', 'mad4b_scp_agent_grants', 'mad4b_scp_approval_tickets', 'mad4b_scp_mutations', 'mad4b_scp_agent_budgets'):
    require(schema, table, 'schema-table')
for dangerous_seed in ("INSERT INTO", "status = 'enabled'", 'grant_ability('):
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

# Agent registry uses exact grants and deny precedence.
require(registry, 'mad4b_wildcard_grant_denied', 'wildcard-grant-denial')
require(registry, 'mad4b_nhi_subject_unbound', 'unbound-subject-denial')
require(registry, 'mad4b_nhi_agent_disabled', 'disabled-agent-denial')
require(registry, "if ( 'deny' === $row['effect'] )", 'deny-precedence')
require(registry, "if ( 'allow' === $row['effect'] )", 'exact-allow')

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

# Central authorization intersects exact grant/token scopes/resource constraints and high-impact approval.
require(authz, 'MAD4B_SCP_Agent_Registry::exact_grant', 'exact-grant-intersection')
require(authz, "'ability:' . $ability_name", 'exact-scope-intersection')
require(authz, 'mad4b_scp_require_token_scopes', 'scope-policy')
require(authz, 'mad4b_nhi_resource_constraints_unresolved', 'constraint-fail-closed')
require(authz, 'MAD4B_SCP_Impact_Policy::requires_approval', 'impact-approval-gate')
require(authz, 'MAD4B_SCP_Approval_Tickets::consume_exact', 'approval-consume-gate')
require(authz, 'mad4b_approval_required', 'missing-approval-denial')
require(authz, 'mutation_global_enabled', 'configured-mutation-status')
require(authz, 'mutation_effective_for_request', 'effective-mutation-status')
require(authz, 'mad4b/authorization:', 'authorization-audit')

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

# Bootstrap order makes governance/mutation services available before abilities/adapters.
order = [
    'class-mad4b-scp-schema.php',
    'class-mad4b-scp-identity-context.php',
    'class-mad4b-scp-agent-registry.php',
    'class-mad4b-scp-policy.php',
    'class-mad4b-scp-audit.php',
    'class-mad4b-scp-provider-contracts.php',
    'class-mad4b-scp-impact-policy.php',
    'class-mad4b-scp-approval-tickets.php',
    'class-mad4b-scp-authorization.php',
    'class-mad4b-scp-mutation-manager.php',
    'class-mad4b-scp-governed-ability-overrides.php',
    'class-mad4b-scp-abilities.php',
]
pos = [bootstrap.index(x) for x in order]
if pos != sorted(pos):
    raise SystemExit('FAIL bootstrap-order: governance dependencies are loaded out of order')

print('mad4b.site-control-plane.agent-governance-contract.v3: PASS')
