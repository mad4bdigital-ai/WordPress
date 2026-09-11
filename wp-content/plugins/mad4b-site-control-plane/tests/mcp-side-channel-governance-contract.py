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

peer = read('includes/class-mad4b-scp-mcp-peer-governance.php')
authz = read('includes/class-mad4b-scp-authorization.php')
adapter_registry = read('includes/class-mad4b-scp-adapter-registry.php')
bootstrap = read('mad4b-site-control-plane.php')
runtime_baseline = read('tests/runtime-mcp-side-channel-baseline.php')
runtime_blocker = read('tests/runtime-mcp-side-channel-blocker.php')
fixture = read('tests/fixtures/mad4b-ci-rogue-public-write.php')

# Runtime inventory must use the exact official MCP Adapter registry/server surfaces.
require(peer, r'\WP\MCP\Core\McpAdapter::instance()', 'adapter-singleton-inventory')
require(peer, "method_exists( $adapter, 'get_servers' )", 'adapter-server-registry')
require(peer, "method_exists( $server, 'get_tools' )", 'server-tool-inventory')
require(peer, "method_exists( $server, 'get_mcp_tool' )", 'server-tool-introspection')
require(peer, "method_exists( $mcp_tool, 'get_adapter_meta' )", 'ability-backed-tool-provenance')
require(peer, r'\WP\MCP\Abilities\McpAbilityExposure::is_public', 'effective-public-exposure-resolver')
require(peer, "GENERIC_EXECUTE_ABILITY = 'mcp-adapter/execute-ability'", 'generic-execute-detection')
require(peer, "'generic_execute_reaches_public_write'", 'generic-reachable-write-risk')
require(peer, "'direct_callable_tool_unreviewed'", 'direct-callable-fail-closed')
require(peer, "'readonly_annotation_missing'", 'unknown-readonly-fail-closed')
require(peer, "'mcp_tool_inventory_overflow'", 'bounded-peer-inventory')
require(peer, "MAX_SERVERS = 100", 'bounded-server-inventory')
require(peer, "MAX_TOOLS_PER_SERVER = 500", 'bounded-tool-inventory')

# No filter may weaken or replace the peer detector result.
for bypass_hook in (
    "apply_filters( 'mad4b_scp_mcp_peer",
    "apply_filters( 'mad4b_scp_side_channel",
    "apply_filters( 'mad4b_scp_ignore_mcp",
):
    forbid(peer, bypass_hook, 'no-runtime-side-channel-bypass-filter')

# Mutation authorization must fail before identity/grant/budget/approval when peer truth is unsafe.
require(authz, 'MAD4B_SCP_MCP_Peer_Governance::mutation_guard()', 'central-side-channel-guard')
require(authz, "'mcp_write_side_channel_detected'", 'authority-side-channel-blocker')
require(authz, "'mcp_peer_governance'", 'authority-peer-status')
if authz.index('MAD4B_SCP_MCP_Peer_Governance::mutation_guard()') > authz.index('MAD4B_SCP_Budgets::reserve'):
    raise SystemExit('FAIL side-channel-before-budget: peer blocker must run before budget reservation')
if authz.index('MAD4B_SCP_MCP_Peer_Governance::mutation_guard()') > authz.index('MAD4B_SCP_Approval_Tickets::consume_exact'):
    raise SystemExit('FAIL side-channel-before-approval: peer blocker must run before approval consumption')

# Runtime self-test must incorporate the same source of truth.
require(adapter_registry, 'MAD4B_SCP_MCP_Peer_Governance::status()', 'self-test-peer-status')
require(adapter_registry, "'mcp_peer_governance_ok'", 'self-test-peer-verdict')
require(adapter_registry, "'mcp_peer_governance'", 'self-test-peer-evidence')

# Bootstrap must load detector before authorization can call it.
require(bootstrap, 'class-mad4b-scp-mcp-peer-governance.php', 'peer-governance-bootstrap')
if bootstrap.index('class-mad4b-scp-mcp-peer-governance.php') > bootstrap.index('class-mad4b-scp-authorization.php'):
    raise SystemExit('FAIL peer-bootstrap-order: detector must load before central authorization')

# Runtime acceptance must prove clean baseline and a real default-server reachable public write blocker.
require(runtime_baseline, 'runtime-mcp-side-channel-baseline.v1: PASS', 'baseline-runtime-proof')
require(fixture, "'mad4b-ci/rogue-public-write'", 'rogue-public-write-fixture')
require(fixture, "'public' => true", 'rogue-public-exposure')
require(fixture, "'readonly' => false", 'rogue-write-annotation')
require(runtime_blocker, "'generic_execute_reaches_public_write'", 'default-execute-reachability-proof')
require(runtime_blocker, "'mcp_write_side_channel_detected'", 'runtime-exact-blocker-proof')
require(runtime_blocker, '$budget_before === $budget_after', 'runtime-pre-budget-proof')
require(runtime_blocker, '$used_tickets_before === $used_tickets_after', 'runtime-pre-approval-proof')

print('mad4b.site-control-plane.mcp-side-channel-governance-contract.v1: PASS')
