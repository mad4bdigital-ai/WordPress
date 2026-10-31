# MAD4B Unified Operation Admission — Operational Contract

Status: **policy specification and read-only regression coverage**. This document is **not an authority grant**, a production approval, a registered broker credential, or a completed end-to-end executor.

## Source of truth

- **Canonical principal:** `MAD4B_SCP_Execution_Authority` derives owner and exact enrolled site from verified runtime facts. Transport identity is evidence, not a permission.
- **User / agent identity:** `MAD4B_SCP_Identity_Context` and `MAD4B_SCP_Agent_Registry`; preserve global subject uniqueness and existing delegated agents.
- **Capability admission:** exact `server_id + ability_name + provider + environment`; `Agent_Registry::exact_grant` is deny-first. Wildcard or unrelated provider does not qualify.
- **Mutation policy:** `MAD4B_SCP_Policy::mutation_gate_status`, Site Profile, exact environment, and optional native resource constraints.
- **Execution:** existing native operation authorizer checks exact ticket or scoped delegated approval, limits, budget, audit, idempotency, readback and rollback. Authentication and an allow grant alone do **not** authorize execution.

## Transport evidence and admission

| Transport | Required proof | Reject |
| --- | --- | --- |
| OAuth/MCP | Verified bearer, enrolled WP principal, canonical subject + client attribution, operation-required scopes | Unverified bearer, mismatching subject, unrelated Agent |
| VPS SSH/WP-CLI | Existing authenticated host runner, exact site UUID/origin/database/environment, registered `host_operator` subject and its own operation authorization | `--user` impersonation, unbound operator, changed site, CLI flag alone |
| Broker | Signed and independently verified job *plus* bound execution identity and scoped job grant; or handoff to the registered VPS executor, whose authenticated host context and original authorization are validated | A broker header/name, job signature in isolation, raw shell availability, reused OAuth credentials |
| Production | One canonical model, with stricter production-native approval, risk limits and independent result verification | Blanket Production denial or blanket Production allow |

A verified transport and exact principal must be mapped to **the same logical owner** without copying a transport subject fingerprint to a different Agent. Do not automatically steal, disable or rebind a subject owned by another principal.

## Required ordering

1. **Resolve** authenticated transport, canonical owner, exact site and environment.
2. **Preflight** source/build provenance, provider mount, policy eligibility and request-specific scope.
3. **Reconcile safely**: resolve identity conflicts and source-approved grant deltas before modifying the registry. Unknown grants or ownership conflicts remain reviewed events; no approval may be fabricated.
4. **Authorize** the exact native operation using required scopes, delegated approval or exact approval ticket; enforce resource constraints and risk policy.
5. **Execute** only via the selected native backend, recording an append-only audit and immutable operation identity.
6. **Verify** independent readback, with rollback on verified failure.
7. **Resume** only the dependent parked job after verified success.

No create/revoke grant call may happen **before** identity and source-policy preflight. If one transaction combines grant changes, plan against a complete stable snapshot and implement compensating rollback for all partial modifications.

## Central Staging without per-company Staging

`https://tours.dev.mad4b.com` is the central build, qualification and release origin. A company's own Staging installation is **optional**, not a prerequisite for deployment. This does **not** waive exact target identity, compatibility checks, scoped production authorization, backups/rollback or release-readback evidence for that company.

## Executable evidence present

- `tests/grant-reconcile-identity-before-grant-contract.py`: identity and reviewed source policy precede grant mutations.
- `tests/authority-transport-regression.php`: verified host and OAuth map to the same canonical principal; forged broker header and unbound host are denied.
- `tests/authority-grant-policy-readonly-regression.php`: use actual Agent Registry and Policy source to check deny-first exact grants and production risk gate.
- `.github/workflows/mad4b-grant-identity-preflight.yml`: PHP lint, source-order and isolated read-only regressions.

**Unproven:** independently authenticated Broker ingress, unified runtime Operation Admission enforcement, native job authorization/readback and end-to-end Production execution. Do not mark Full Authority ready based on these tests alone.

## Non-goals

No new grant issuance, no Agent reassignment, no OAuth impersonation, no bypass of step-up where it applies, no raw SQL/shell escalation, no production mutation, and no rewrite of existing registry or authorization implementation.

## Automatic authorization convergence after transport authentication

The distinction between `SSH_AUTHENTICATED` and `OPERATION_AUTHORIZED` is **not** an extra manual approval stage. It is one transactionally linked workflow that wakes on authenticated transport, a newly admitted job, changed grants or delegation, target enrollment, and fresh signed readback.

| Event | Automated next step | No action allowed without |
| --- | --- | --- |
| Registered SSH host becomes authenticated | Re-evaluate matching parked exact-scope jobs using current canonical registry snapshot | Canonical host principal, target site UUID/origin/environment and valid per-job grant |
| New Broker Job / Claim | Verify source Job, signed Claim, approved operator, exact company/target/command and resource constraints | Active source grant, nonce/freshness, delegated role, provider mount |
| Target Binding / Grant / Delegation changes | Invalidate cached eligibility, resnapshot and atomically re-evaluate dependent parked jobs | A fresh evidence chain; never reuse old eligibility |
| All admission evidence passes | Transition that exact job to `OPERATION_AUTHORIZED`, then dispatch approved native executor and verify readback | Native approval, agent grant, non-replayable job and execution budget |
| Missing evidence / grant expired | Mark `WAITING_EXACT_AUTHORITY` with machine-readable blockers; automatically retry on its **own** authoritative dependency change | Never forge or renew grants, promote one-time to persistent, or mutate job state merely to suppress diagnostics |
| Execution failed or readback mismatched | Park job, emit audit and invoke original operation's bounded rollback or compensating action | A reversible mutation with verified prior snapshot; non-mutating diagnostics need no rollback mutation |

**Essential liveness invariant:** a successful SSH identity check produces an authorization evaluation automatically, **not** automatic authorization. When valid exact evidence is already present, the handoff must be zero-touch. Where evidence is absent, return a precise reviewable dependency and retry only after the dependency changes; no unbounded polling and no generic manual approval ceremony.

**Readback truth invariant:** `CENTRAL_GRANT_VERIFIER_NOT_BOUND` and `CENTRAL_REMOTE_TARGET_SCOPE_BINDING_PENDING` are honest blockers until the signed Registry observation demonstrates actual binding. Do not remove these strings via default boolean fields, an unsigned fallback, or an execution receipt derived from a Claim. The 312 verifier must report why a claim is ineligible, and 313 may only attest a genuinely executed, independently observed exact operation. Existing expired Job IDs must not be resurrected.

**Release integrity:** executable Broker modules 301/312/313 and their dependency graph need exact source parity and replay/expiry/deny tests in the canonical central release before automatic remote execution can be considered deployment-ready. An existing VPS file alone is not release authorization.
