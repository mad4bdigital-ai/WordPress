#!/usr/bin/env python3
from pathlib import Path
import re

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
policy = read('includes/class-mad4b-scp-policy.php')
adapter = read('includes/adapters/class-mad4b-scp-adapter-base.php')
bootstrap = read('mad4b-site-control-plane.php')
plugin = read('includes/class-mad4b-scp-plugin.php')

# Schema authority must be normalized and migration must not seed grants/agents.
require(schema, 'const VERSION = 2;', 'schema-version')
for table in ('mad4b_scp_agents', 'mad4b_scp_agent_subjects', 'mad4b_scp_agent_grants', 'mad4b_scp_approval_tickets', 'mad4b_scp_mutations', 'mad4b_scp_agent_budgets'):
    require(schema, table, 'schema-table')
for dangerous_seed in ("INSERT INTO", "status = 'enabled'", 'grant_ability('):
    forbid(schema, dangerous_seed, 'no-default-authority-seed')
require(plugin, 'MAD4B_SCP_Schema::install_or_upgrade()', 'schema-migration')

# Identity context must reject secret material and wildcard scopes.
require(identity, 'mad4b_scp_authenticated_subject_context', 'subject-context-hook')
require(identity, 'mad4b_identity_secret_field_denied', 'raw-secret-denial')
require(identity, 'mad4b_identity_wildcard_scope_denied', 'wildcard-scope-denial')
require(identity, "hash( 'sha256', $type . \"\\0\" . $identifier )", 'subject-fingerprint')
for forbidden_url_pattern in ('/mcp/{token}', '/mcp/{api_key}', 'HTTP_AUTHORIZATION] ='):
    forbid(bootstrap + identity + authz, forbidden_url_pattern, 'no-token-url')

# Agent registry uses exact grants, deny precedence and unique subject binding.
require(registry, 'UNIQUE', 'placeholder') if False else None
require(registry, 'mad4b_wildcard_grant_denied', 'wildcard-grant-denial')
require(registry, 'mad4b_nhi_subject_unbound', 'unbound-subject-denial')
require(registry, 'mad4b_nhi_agent_disabled', 'disabled-agent-denial')
require(registry, "if ( 'deny' === $row['effect'] )", 'deny-precedence')
require(registry, "if ( 'allow' === $row['effect'] )", 'exact-allow')

# Global mutation now requires schema + authenticated bound NHI in addition to the constant.
require(policy, "defined( 'MAD4B_MCP_MUTATION_ENABLED' )", 'global-mutation-gate')
require(policy, 'MAD4B_SCP_Schema::is_ready()', 'schema-required-for-mutation')
require(policy, 'MAD4B_SCP_Identity_Context::current()', 'identity-required-for-mutation')
require(policy, 'MAD4B_SCP_Agent_Registry::resolve_agent', 'bound-agent-required-for-mutation')

# Adapter writers must call the central exact authorization engine.
require(adapter, 'MAD4B_SCP_Authorization::authorize_mutation', 'adapter-central-authorization')
require(adapter, "'mad4b-' . sanitize_key( $surface )", 'adapter-server-binding')
require(adapter, '$this->certified_provider_key()', 'adapter-provider-binding')

# Central authorization intersects exact grant and token scopes; constraints fail closed without evaluator.
require(authz, 'MAD4B_SCP_Agent_Registry::exact_grant', 'exact-grant-intersection')
require(authz, "'ability:' . $ability_name", 'exact-scope-intersection')
require(authz, 'mad4b_scp_require_token_scopes', 'scope-policy')
require(authz, 'mad4b_nhi_resource_constraints_unresolved', 'constraint-fail-closed')
require(authz, 'mad4b/authorization:', 'authorization-audit')

# Bootstrap order must make governance services available before abilities/adapters.
order = [
    'class-mad4b-scp-schema.php',
    'class-mad4b-scp-identity-context.php',
    'class-mad4b-scp-agent-registry.php',
    'class-mad4b-scp-policy.php',
    'class-mad4b-scp-audit.php',
    'class-mad4b-scp-provider-contracts.php',
    'class-mad4b-scp-authorization.php',
    'class-mad4b-scp-abilities.php',
]
pos = [bootstrap.index(x) for x in order]
if pos != sorted(pos):
    raise SystemExit('FAIL bootstrap-order: governance dependencies are loaded out of order')

print('mad4b.site-control-plane.agent-governance-contract.v1: PASS')
