# Post-merge Capability Fabric completeness audit

Baseline:
- Repository: `mad4bdigital-ai/WordPress`
- PR #230 merged head: `6049d0f406639d1113fc7082df6f5ae960faca2d`
- Merge commit / reviewed post-merge master baseline: `a13252baf4719992027c0f0e324014ee0840f0dc`
- Scope: post-merge architecture backlog only; no Production activation, Breakglass widening, generic shell/raw SQL admission, or implicit authority.
- Source review classified the merged design as technically strong while explicitly retaining long-term migration work in durable network orchestration, provider-specific reconciliation, circuit breaking, resource constraints, impact-bound approvals, unified execution receipts, tracing, catalog storage, maintainability and protocol/lifecycle evolution.

## Dimension matrix

| Dimension | Current post-merge observation | Phase 37 ownership |
|---|---|---|
| Canonical capability semantics | Capability Descriptor is canonical for selected capability identity, while Operation Registry, Capability Traits, Servers and Authorization still own adjacent derivations. | T3701–T3706 |
| Semantic content fields | Generic field/string heuristics remain useful fallback evidence but are not a complete provider semantic model. | T3707–T3708 |
| Resource constraint compiler | No universal provider-neutral compiler currently binds post/taxonomy/path/table/provider-object limits across the full plan→approval→commit chain. | T3709–T3711 |
| Provider postcondition reconciliation | Durable uncertainty is fail-closed, but automated COMMITTED/VERIFIED_NO_EFFECT resolution still needs mutation-family-specific readers. | T3712–T3714 |
| Execution State View | Read-only normalization exists and owns no durable state; completeness requires a formal contradiction matrix and recomputability gate. | T3715–T3716 |
| Identifier policy | Approval UUIDv4 is stronger than some other historical identifier validators. | T3717–T3718 |
| Dedicated immutable catalog table | Current catalog object storage remains options-backed with immutable payload/CAS behavior. | T3719–T3724 |
| Cache coherence / horizontal workers | DB locking is strong, but persistent object-cache and multi-host behavior need explicit certification. | T3722–T3724 |
| Durable multisite/network orchestration | Site isolation and partial evidence exist; there is not yet a durable network transaction/journal coordinator. | T3725–T3729 |
| Projection isolation | Fixed dispatch is correctness; dynamic projection is an optimization, with site-global contention semantics still needing an explicit long-term policy. | T3730–T3731 |
| MCP refresh/protocol evolution | Current Adapter behavior is intentionally exact-package/profile bound; successor refresh/listChanged/protocol behavior needs certification. | T3732–T3735 |
| Durable provider circuit breaker | Connector Resilience is intentionally request-local and currently documents that no persistent breaker/cache is used. | T3736–T3738 |
| WordPress native lifecycle | Compatibility wrappers/Reflection preserve existing parity; native migration should occur only after certified parity. | T3739–T3740 |
| Distributed tracing | Metrics/audit/journal exist, but causal cross-stage/cross-provider trace propagation is not a complete tracing fabric. | T3741–T3742, T3746 |
| Measured operational SLOs | Hard budgets exist; stage-level P50/P95/P99 and error-budget operations need explicit measured contracts. | T3743–T3745 |
| Impact-bound approval | Approval/payload/context binding is strong; blast-radius/dependency-generation digest binding is not yet a unified approval identity. | T3747–T3748 |
| Authorization decision graph | Deterministic checks exist but not one complete PASS/FAIL/NOT_EVALUATED explainability graph. | T3749 |
| Unified execution receipt | Evidence is distributed across preparation, approval, journal, durable execution and readback rather than one independently verifiable receipt. | T3750–T3751 |
| Semantic intent routing | Capability execution is strong after selection; universal intent→traits→provider→capability selection remains a separate non-authorizing layer. | T3752–T3753 |
| Structural redaction | Secret-like keys are redacted; nested provider/plugin payloads require adversarial structural classification/fuzz coverage. | T3754 |
| Cryptographic agility | HMAC-backed receipts are strong but need explicit algorithm/key-id/rotation/revocation profiles beyond OAuth key lifecycle. | T3755 |
| Clock skew / monotonic deadlines | TTLs, leases and observation windows exist; one platform-wide clock model is still implicit. | T3756 |
| Replay semantics | Nonces/idempotency exist, but reusable versus single-use preparation evidence should be risk-class explicit. | T3757 |
| Unicode/canonicalization | Canonical JSON/order exists in key places; cross-surface Unicode/confusable normalization needs one policy. | T3758 |
| Rate limiting and complexity budgets | Many byte/count/time budgets exist; principal/site/client abuse and algorithmic complexity budgets need a unified policy. | T3759 |
| Egress TLS/DNS/proxy trust | SSRF/private-network controls are modeled; transport trust and redirect/DNS/certificate changes need explicit semantics. | T3760 |
| Backward compatibility | Tightening legacy dispatcher calls is intentional; migration telemetry/versioned errors/sunset should be explicit. | T3761 |
| Deterministic CI | Mutation/fault CI is strong; critical gates should also own fake clocks, deterministic fixtures and no flaky bypass. | T3762 |
| Maintainability and change architecture | Large responsibility concentrations and the historical mega-PR surface increase future change/review cost. | T3763–T3765 |
| Stable error/schema evolution | Reason codes exist across subsystems but need one client-facing registry/evolution policy. | T3766 |
| Configuration generation/drift | Material configuration affects behavior; explicit generation binding prevents flags from becoming hidden authority. | T3767 |
| Completeness closure | The backlog itself needs a machine-reviewable no-orphan/no-untriaged gate. | T3768–T3770 |

## Existing Spec Kit tasks intentionally reused rather than duplicated

Phase 37 refines rather than replaces existing coverage, especially:
- T2011 for timeouts/bulkheads/circuit breakers; T3736–T3738 make the missing durable provider/site/generation semantics explicit.
- T2207–T2209 and T2906–T2908 for SLO/alerts/cost; T3743–T3745 define missing stage measurement and outage semantics.
- T1526–T1528 for MCP Adapter evolution; T3732–T3735 add refresh/listChanged/projection correctness boundaries.
- T2301–T2315 for policy/approval/evidence trust; T3747–T3751 add impact digest, explainability and unified execution receipt.
- T3201–T3214 for authoritative state/fenced execution; T3715–T3716 explicitly bind the read-only normalized Execution State View.
- T3401–T3424 for semantic/privacy/data-flow hardening; Phase 37 adds missing content-field, canonicalization, crypto/time and abuse contracts.

## Exit semantics

`CAPABILITY_FABRIC_DIMENSION_OWNERSHIP=PASS` requires every row above to have explicit task ownership and fail-closed interim behavior.

`CAPABILITY_FABRIC_NO_UNTRIAGED_P0_P1=PASS` requires no P0/P1 dimension to exist only as prose or implicit design knowledge.

`CAPABILITY_FABRIC_NO_AUTHORITY_WIDENING=PASS` requires all Phase 37 work to preserve:
- descriptor/projection/telemetry/routing are non-authorizing;
- current live authorization, approval, certification, NHI, context and commit guards remain authoritative;
- Production and Breakglass stay separately authorized;
- generic shell, arbitrary PHP/`wp eval`, and generic raw SQL remain outside the ordinary catalog.
