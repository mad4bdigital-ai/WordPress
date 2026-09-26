# Contract — Architecture Freeze and Critical Kernel

Contract: mad4b.architecture-freeze.v1

## Purpose

Prevent unlimited architecture expansion before runtime evidence validates existing abstractions.

## Critical Kernel

Implementation priority is limited to:
- current baseline synchronization;
- root release/evidence trust;
- Multi-Authority live path;
- deterministic policy + commit guard;
- revisions/idempotency/fencing;
- inbox/outbox + durable recovery;
- exact Bit Flows capability certification;
- existing-site bootstrap/content inventory;
- ContentJob/artifact/context/research/blueprint/draft/QA;
- governed WordPress Draft mutation;
- semantic origin/public verification;
- minimal out-of-band Recovery Plane.

## New core contract admission

After architecture freeze, a new CORE contract requires at least one:
1. observed runtime failure;
2. concrete security threat/boundary;
3. irreversible data-model/storage decision;
4. second real provider proving an abstraction requirement;
5. Production recovery requirement;
6. applicable compliance/legal requirement.

Otherwise it is:
- ADR/design note;
- backlog;
- optional extension;
not a Critical Kernel blocker.

## Deferred implementation

Specification may retain broader maturity contracts, but implementation does not proceed merely because a contract exists.

Examples generally deferred until evidence demands them:
- full experimentation platform;
- financial chargeback;
- general dynamic multi-provider resolver before provider #2;
- advanced semantic retrieval;
- broad portability automation.

## Vertical-slice proof

Before widening Critical Kernel, execute one exact live Staging path:
baseline/root trust
→ authority
→ provider certification
→ bootstrap
→ one ContentJob
→ Context/Research
→ Blueprint/Draft/QA
→ governed WordPress Draft
→ semantic public/origin verification as applicable
→ complete evidence/recovery observations.

## Review

Any major redesign triggered by this vertical slice updates the affected contracts before scale-out.

## Admission decision — governed tooling

Observed evidence now satisfies admission criteria 1, 2 and 5:
- operational diagnosis required a manual hosting-terminal fallback because no canonical CLI/MCP diagnostic existed;
- exposing generic shell inside WordPress would create a concrete privilege/security boundary;
- recovery must remain possible when the WordPress/plugin path is unhealthy.

Therefore the following minimal subset is admitted to Critical Kernel / Recovery Plane support:
- semantic ToolOperation registry sufficient for recovery diagnostics;
- canonical read-only CLI diagnostics;
- bounded executor contract;
- minimal out-of-band Recovery Runner capable of package/runtime health and known-good package restore;
- host/WordPress authority separation.

The broader Host Execution Plane remains a maturity/Phase 11 platform capability and MUST NOT block the first ContentJob vertical slice merely because every future host write adapter is not implemented.

This admission does not authorize generic shell, Production host mutation, provider-specific automation or broad Host Write.
