# Requirements Checklist — Feature 007

## Specification quality
- [x] Problem separates existing Control Plane from missing domain layer.
- [x] Goals are provider-neutral.
- [x] Non-goals prevent a second authority plane.
- [x] ETG is explicitly a validation profile, not architecture boundary.
- [x] Bit Flows is explicitly an adapter/provider, not governance owner.
- [x] Installed/certified/upstream versions are separated.
- [x] Content Job state and stage are separate.
- [x] Artifact lineage and immutable versions are defined.
- [x] Context dispatch reuses Context Authority.
- [x] Writer Profile is reusable/versioned.
- [x] Research providers are abstracted.
- [x] Competitive intelligence artifacts are explicit.
- [x] QA hard blockers cannot be averaged away.
- [x] Publishing reuses existing governed abilities.
- [x] Host authority is separate.
- [x] Cron is provider-neutral.
- [x] Growth loop creates new/revision jobs instead of silent rewrites.

## Governance
- [x] Production remains separately authorized.
- [x] Unknown providers fail closed.
- [x] Provider-native privileged MCP side channels are blocked/federated.
- [x] Provider certification does not create grants.
- [x] Workflow mechanics do not gain mutation authority.
- [x] Skills do not gain mutation authority.
- [x] Host Connector does not inherit WordPress grants.
- [x] Exact fingerprints/plans are required for high-impact execution.
- [x] Release-lineage reconciliation is a hard gate.

## Implementation readiness
- [x] Data model proposed.
- [x] Phase plan defined.
- [x] Task IDs defined.
- [x] Contracts defined.
- [x] Quickstart defined.
- [x] Operational runbook defined.
- [x] Traceability defined.
- [ ] Phase 0 reconciliation evidence generated.
- [ ] Final rc.59 exact head frozen.
- [ ] rc.59 canonical merge completed.
- [ ] Runtime implementation branch authorized.

## Open design decisions to resolve during implementation
- [ ] Exact storage strategy for large artifact bodies.
- [ ] First KeywordProvider.
- [ ] First SERPProvider.
- [ ] First ScrapeProvider.
- [ ] Exact Bit Flows lifecycle public API support on certified package.
- [ ] Host Connector transport/provider for first implementation.
- [ ] SearchPerformanceProvider for Growth phase.


## Supplied-input coverage audit
- [x] Repository-ready vs live-Staging vs Production states are separated.
- [x] Multi-Authority trust and advertisement are separate.
- [x] Exact authority resource policy is defined.
- [x] External subject mapping is issuer/site-bound and decoupled from WP primary keys.
- [x] Full REST filter-chain E2E is required.
- [x] External live readiness truthfulness is specified.
- [x] Local OAuth keyring/rotation is specified.
- [x] MCP protocol evolution is exact-package governed.
- [x] Multi-Authority Live Certification is a hard gate.
- [x] Workflow Provider Diagnostic is specified.
- [x] Capability fingerprints are specified.
- [x] Extended certification lifecycle including QUARANTINED is specified.
- [x] Artifact Diff Classifier is specified.
- [x] Evidence dependency graph/reuse is specified.
- [x] Global artifact certification vs site compatibility is separated.
- [x] Behavioral provider probes are specified.
- [x] Historical Bit Flows regression probes are retained permanently.
- [x] Run Code/arbitrary PHP is denied from ordinary workflow authority.
- [x] Native provider MCP is blocked/federated, not a parallel privileged transport.
- [x] Signed/replay-resistant workflow bridge is specified.
- [x] Provider Resolver is specified.
- [x] Release rings are specified.
- [x] Conditional evidence-based autopromotion is specified.
- [x] SemVer is metadata/risk signal, not authority.
- [x] coverage-audit.md maps all supplied architecture inputs.

## Runtime closure still pending
- [ ] Multi-Authority Live Certification PASS on exact ETG Staging candidate.
- [ ] PR #45/#47 semantic reconciliation REQUIRED=0.
- [ ] rc.59 exact-head live acceptance.
- [ ] rc.59 canonical merge.
- [ ] Dynamic certification runtime implementation.
- [ ] Exact Bit Flows installed artifact recertification.
- [ ] Provider Resolver runtime implementation.
- [ ] First Content Job vertical slice.

## Cross-feature compatibility
- [x] Cross-feature metadata isolation is explicit.
- [x] Feature 007 metadata is local to Feature 007.
- [x] Existing Feature 001 global metadata is preserved.
- [ ] CI proves spec-only additions cannot regress unrelated feature contracts.


## Quality and robustness hardening
- [x] Functional coverage is separated from quality readiness.
- [x] Atomicity/concurrency/idempotency contract exists.
- [x] At-least-once vs effect-once semantics are explicit.
- [x] Durable lease/retry/backpressure/circuit-breaker contract exists.
- [x] Schema/contract evolution and migration strategy exist.
- [x] Security threat model includes SSRF, injection, replay, confused deputy and tenant bleed.
- [x] Supply-chain provenance and secret lifecycle are explicit.
- [x] Prompt/source injection boundaries are explicit.
- [x] AI model/prompt/Skill versioning and evaluation are explicit.
- [x] Tenant/privacy isolation and retention are explicit.
- [x] SLO/capacity/cost governance is explicit.
- [x] DR/RPO/RTO/restore rehearsal is explicit.
- [x] Property/fuzz/fault/compatibility testing is explicit.
- [x] Policy drift and kill switches are explicit.
- [x] Cross-feature Spec Kit isolation is fixed in design.
- [ ] Runtime QCORRECTNESS evidence exists.
- [ ] Runtime QRESILIENCE evidence exists.
- [ ] Runtime QSECURITY/QSUPPLYCHAIN evidence exists.
- [ ] Runtime QEVAL evidence exists.
- [ ] Runtime QPERF evidence exists.
- [ ] Runtime QRECOVERY/QCOMPAT evidence exists.


## Long-lived platform closure
- [x] Policy precedence/conflict resolution is explicit.
- [x] Separation of duties, quorum, delegation and emergency approvals are explicit.
- [x] Reusable evidence authenticity/signing/revocation is explicit.
- [x] Existing-site bootstrap and normalized content inventory are explicit.
- [x] Content intent ownership/cannibalization prevention is explicit.
- [x] Artifact storage/retrieval/tiering/dedup/GC is abstracted.
- [x] Incremental invalidation/recompute planning is explicit.
- [x] Publication verification extends beyond WordPress database state.
- [x] Rights/licensing/attribution/takedown are explicit.
- [x] AI data-processing/residency/fallback policy is explicit.
- [x] Operator control, Doctor, RepairPlan and DLQ are explicit.
- [x] Cross-provider semantic conformance is explicit.
- [x] Contract/capability/Skill deprecation and sunset are explicit.
- [x] Fair scheduling/noisy-neighbor controls are explicit.
- [x] Central-vs-site outage/local autonomy is explicit.
- [x] Localization/transcreation/RTL/hreflang model is explicit.
- [x] Accessibility QA is explicit.
- [x] Internal Link Graph is explicit.
- [x] Eval Registry and gold-fixture governance are explicit.
- [x] SLO error budgets, alert ownership and escalation are explicit.
- [x] Experimentation/variants/SEO safety are explicit.
- [x] Usage ledger/budgets/chargeback/reconciliation are explicit.
- [x] Decommission/export/import/credential revocation are explicit.
- [ ] Runtime QGOVERNANCE evidence exists.
- [ ] Existing-site bootstrap has been run on ETG Staging.
- [ ] ArtifactStore backend is selected and certified.
- [ ] Publication Verification has a live ETG Staging canary.
- [ ] Rights/AI processing profiles are configured for initial providers.
- [ ] Operator Doctor/DLQ runtime exists.
- [ ] Multi-site fairness/local-autonomy fixtures exist.
- [ ] Eval/alert/usage/portability runtime evidence exists.
