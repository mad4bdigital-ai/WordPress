# MAD4B — one operation policy for multiple authenticated transports

## Binding contract

OAuth/MCP is one way to **authenticate** a principal, not a mandatory authority source for every managed operation. The same trusted identity/grant policy applies when a principal is independently authenticated through an approved native VPS SSH/WP-CLI connection or the existing signed Broker.

Transport | Server-side verifier and identity | Authorization
--- | --- | ---
OAuth / MCP | Existing authenticated `MAD4B_SCP_Identity_Context::current()` | Existing `MAD4B_SCP_Agent_Registry::resolve_agent()`, `exact_grant()`, `MAD4B_SCP_Policy` and any operation-specific authority
VPS SSH / WP-CLI | **Authenticated host/SSH assertion** bound to the actual target host, key and actor; a WP-CLI `--user` switch alone is not proof | Same bound agent, exact grant, company delegation, target, command digest and runtime gates
Signed Broker | Existing Central Authority Verifier `312` and its independently checked grant/claim/delegation/command digest | Same operation policy; actual dispatch and remote postcondition require the already-existing Broker and Readback `313`

No transport is considered intrinsically more privileged. A Production operation can be authorized under a valid current grant without requiring per-company Staging. Its capability surface may match Staging, but the **site, environment, operation and command digest** must match the target-specific policy.

## Implementation in PR #669

`MAD4B_SCP_Unified_Operation_Authority` is an **internal policy-only** module. `assess_trusted_boundary()` evaluates the exact same rules for:
- `oauth_mcp` verified by `identity_context_agent_registry`;
- `vps_ssh_wpcli` verified by `host_verified_ssh_wpcli_identity`;
- `signed_broker` verified by `central_authority_verifier_312`.

Its input is a **server-side verified normalized identity, independently refreshed deny-first grant and immutable operation**. Never bind this method to a REST/MCP endpoint that accepts those objects from a user. Returning `eligible=true` from the pure policy means the *verified tuple would satisfy policy*, not that a dispatcher has received a signed execution token: `dispatch_eligible=false`, `execution_authorized=false` and `mutation_performed=false` in all cases.

The decision enforces:
- verified transport identity and bound agent identity;
- exact company/site UUID and target environment;
- exact ability, provider, server and command SHA-256;
- enabled allow grant with deny-first and revocation checked;
- company delegation;
- expiring grants **or** a centrally verified standing grant with per-execution revocation checks (no arbitrary reapproval on every operation);
- Production runtime gate as an explicit separate check.

`current_registered_identity_plan()` accesses the existing normalized `Identity_Context`, `Agent_Registry::resolve_agent` and `exact_grant`. This is a **read-only adapter example**, not a replacement for the existing `MAD4B_SCP_Policy::can_mutate()` or an executor. It does not automatically classify WordPress `--user` as verified SSH.

`MAD4B_SCP_Central_Operator_Handoff` loads the shared policy class and advertises all three accepted authenticated identity channels in its **read-only**, non-authorizing handoff contract.

## Pending live transport adapters and rollout

The actual SSH host attestation and signed Broker verifier must construct trusted contexts in their **already-approved runtime**, using their existing 312 verification contracts. Their exact private PHP method names and proof format are not inferred here. Do **not** map a caller-supplied `authenticated=true`, role, WP user ID or `transport_verified=true` to execution authority.

The Native SSH Remote Executor remains undeployed after a security refusal. The shared policy does not bypass that refusal or write to Production; it also does not replace the existing signed production batch or Remote Readback 313 checks.

Only after an approved source-backed executor is deployed can an actual operation use:

1. Authenticate by OAuth/MCP, SSH/WP-CLI host verifier, or signed Broker;
2. Resolve the same principal/agent and fresh exact grant, including delegation and company/site/environment binding;
3. Verify the exact immutable Central Staging release and local company compatibility;
4. Approve the operation with the same MAD4B operation policy, then bind the command digest and target SSH identity;
5. Execute through the authorized native operator and require real remote Readback 313, rollback and journal;
6. Refuse stale grants, mismatch, missing postcondition, or any attempt to treat transport authentication as deployment authority.

## Verification

The hermetic `tests/unified-operation-authority-runtime.php` exercises all three channels under the **same grant**, an explicit deny, revoked state, expired or standing grant, wrong company, changed command digest, missing Production gate, forged verifier and WP-CLI-only subject. No customer site, grant or Production mutation is made in this test.

This change is a source-level unified policy **contract and implementation**, not a claim that all three live authentication adapters or the remote SSH executor are already integrated or certified.
