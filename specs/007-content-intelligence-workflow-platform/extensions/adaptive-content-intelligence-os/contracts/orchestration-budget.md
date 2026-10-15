# Durable Orchestration, Providers and Economic Safety
Contract: `mad4b.aci-os.workflow-budget.v1`.

## Runtime layering

MAD4B owns job admission, identity, grants, approvals, policy, budget and audit. `WorkflowProvider` (Bit Flows, n8n or native engine when certified) executes typed graph nodes under expiring operation tickets. No workflow callback may call WordPress directly with broader credentials or decide its own success.

## DAG nodes

`Inventory -> Context -> Discovery -> Evidence -> ExperienceGap -> Blueprint -> Draft -> FactQA+EditorialQA+SEOQA+RightsQA+RelationQA -> PublishReview -> GovernedWrite -> NativeReadback -> RenderReadback -> GrowthObservation`.

Each node declares schema, input hashes, provider contract, permissions, timeout, cost bound, egress/rights, retry policy, idempotency key, expected observable output and side-effect class. Gating edges are explicit. Jobs persist state separately from stage.

## Economic commit protocol

1. Resolve account-level and per-site/day/job budget with currency and quota; if unknown, stop.
2. Reserve estimated upper-bound amount atomically at shared provider-account authority (CAS).
3. Dispatch one idempotency-bound provider operation and log remote request correlation.
4. Reconcile confirmed actual charge/usage; release unused reservation, never negative-balance drift.
5. On timeout where external work *may* have succeeded, enter `EXTERNAL_EFFECT_UNKNOWN`; inspect remote operation via provider read API before any retry.
6. Budget denial never falls back silently to an unapproved provider/account.
7. Include source rights, rate policies, prompt/embedding token spend and retries in cost accounting.

`EXTERNAL_EFFECT_UNKNOWN`, `NEEDS_RECONCILIATION`, `CANCEL_REQUESTED` and `QUARANTINED` are first-class states; an unresponsive job is not implicitly failed-safe to restart.

## Durable execution

Use existing operation journal and lease/fence abstraction. Every transition guards `site_uuid`, generation, restore epoch, job/stage attempt, last event SHA, expected artifact parent hash and monotonic deadline. A race during publish or after approval invalidates the downstream plan. Retry only admitted idempotent pure operations or certified external reconciliations. Pauses/cancellation preserve external uncertainty and account charges.

## Adaptivity

The CE01 Adaptive Capability Fabric provides optional L0–L5 capability health; ACI01 uses its outputs for bounded plans. No self-installation, added OAuth consent, raw shell, auto-Pro upgrade or automatic Production repair. Maintain kill switches per provider/site/feature, SLO error budget, cooldown, quota fairness, and circuit breakers that preserve unrelated read journeys.

## Fault/denial fixtures

Double worker claim; crashed journal append; API timeout after charge; cost reservation conflict; revoked OAuth; provider output with instructions; missing tool response schema; workflow provider unavailable; delayed callback after Restore Epoch increment; human edit during publication; repeated webhook; currency mismatch; per-account quota exhaustion; duplicate posts after create response loss. Prove no double charge, double publish or scope widening.
