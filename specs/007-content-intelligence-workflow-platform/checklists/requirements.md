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
- [ ] First Host Connector/Tool Executor channel for implementation.
- [ ] Exact first Hostinger validation channels available on the target account.
- [ ] Durable queue/spool backend for Host Runner.
- [ ] Standalone CLI requirement after minimal WP-CLI/Recovery Runner proof.
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


## Hostile-review closure
- [x] Feature 007 has been merged with the current rc.59 integration head at review time.
- [x] Baseline drift is now an explicit hard CI concept.
- [x] Control Plane executable identity has an external root-provenance requirement.
- [x] Out-of-band Recovery Plane is explicitly required.
- [x] Aggregate state/events/artifacts/projections have explicit authority roles.
- [x] Worker leases require monotonic fencing tokens.
- [x] Execution Commit Guard closes plan/approval TOCTOU.
- [x] Approval invalidation dependencies are explicit.
- [x] Hard gates are represented as a DAG with liveness requirements.
- [x] Bootstrap exceptions are explicit BootstrapTransitions.
- [x] Gate output includes minimal unsatisfied blocker set.
- [x] Single-owner hardened mode is truthful and distinct from true multi-person SoD.
- [x] Provider capabilities have semantic traits, not booleans only.
- [x] Intent ownership is many-to-many and confidence/evidence-backed.
- [x] Content-addressed storage has privacy-safe dedup scope rules.
- [x] Publication verification uses semantic normalized fingerprints.
- [x] AI provenance does not falsely claim deterministic regeneration.
- [x] Eval registry separates holdout/adversarial sets.
- [x] Compatibility uses SupportedRuntimeProfiles instead of Cartesian-all testing.
- [x] Central outage uses explicit risk-classed offline authorization windows.
- [x] Audit evidence/domain events/telemetry are separate classes.
- [x] Data-flow/residency policy covers all processors, not AI only.
- [x] Three critical state families require executable/model-based invariant tests.
- [x] Architecture Freeze prevents unlimited pre-runtime abstraction growth.
- [ ] Current-base ancestry CI passes on final PR head.
- [ ] Recovery Plane runtime canary exists.
- [ ] Fencing/commit-guard runtime tests exist.
- [ ] Critical Kernel ETG Staging vertical slice passes.

## Governed tool execution / host operability
- [x] Semantic operations are separated from command/API/CLI syntax.
- [x] MCP, Admin UI, WP-CLI, standalone CLI and Host Runner are defined as frontends/executors over shared services.
- [x] Generic shell, arbitrary `wp eval`, arbitrary PHP and generic raw SQL are excluded from ordinary authority.
- [x] Host Read/Write/Execution/Breakglass/Recovery/Production authorities are separate from WordPress authority.
- [x] Full Staging Authority does not imply Host Execution Authority.
- [x] ToolOperationDefinition/ToolExecutorProfile/ToolExecutionPlan/ToolExecutionReceipt data models exist.
- [x] Named filesystem zones, canonical paths, symlink/zip-slip/TOCTOU controls are specified.
- [x] Fixed executable + structured argv process policy is specified.
- [x] Secret handles, environment allowlists and output redaction are specified.
- [x] Host Runner durable queue/lease/fencing/idempotency/DLQ semantics are specified.
- [x] Host Runner first-install/bootstrap and one-time enrollment are specified without assuming interactive hosting terminal.
- [x] WordPress Host Bridge is specified as plan/enqueue/status surface, not generic shell execution.
- [x] Host Bridge apply is exact-plan bound and revalidated by the runner.
- [x] Minimal WordPress-independent Recovery Runner is specified.
- [x] Provider API/CLI/plugin/WP-CLI/runner channels are discovered/certified independently.
- [x] Hostinger is a first validation profile, not a generic schema dependency.
- [x] Deterministic same-authority executor fallback is specified and non-authorizing.
- [x] Cross-adapter conformance is required.
- [x] Tool/runner Doctor, evidence, decommission and recovery integration are specified.
- [x] Runner/CLI binary supply-chain provenance and artifact-input verification are specified.
- [x] Host Execution/Runner/provider-channel kill switches are independent.
- [x] Queued jobs revalidate offline authorization/commit-guard dependencies before commit.
- [x] Host Runner fencing prevents zombie-worker commit.
- [x] Recovery Runner has an independent root-trust model.
- [x] Executor runtime-profile compatibility dimensions are explicit.
- [ ] Runtime `wp mad4b` namespace exists.
- [ ] Runtime Host Runner exists.
- [ ] At least one supported hosting profile can bootstrap/enroll Host Runner without interactive terminal.
- [ ] Runtime Recovery Runner canary exists.
- [ ] MCP/CLI normalized parity gate passes.
- [ ] Hostinger first-channel discovery/certification evidence exists.
- [ ] Cross-adapter semantic conformance gate passes.
- [ ] Reversible host write canary passes.
- [ ] Production Host Authority remains ungranted until separately approved.


## Unified implementation closure
- [x] All known remaining workstreams are classified in implementation-closure.md/json.
- [x] Remaining work is separated into KERNEL_BLOCKER / LIVE_PRECONDITION / MATURITY_REQUIRED / DEFERRED_MATURITY.
- [x] Phase 36 maps the closure program to explicit task IDs and terminal evidence.
- [ ] External master repository ruleset is active and independently read back.
- [ ] Legacy execution ledger is reconciled to DONE/PARTIAL/OPEN/DEFERRED with exact evidence refs.
- [ ] Latest master descendant has fresh trusted release/root evidence.
- [ ] Protected backup root is exists/writable/ready with current-runtime backup receipt.
- [ ] Exact installed Bit Flows 1.29.0 is certified and privileged-side-channel disposition is proven.
- [ ] Existing-site bootstrap and Intent Registry gates pass.
- [ ] ContentJob + Artifact Registry/Store + consistency/fencing gates pass.
- [ ] Context/Writer/Research/Competitive evidence gates pass.
- [ ] Blueprint/Draft/FactLedger/QA gates pass.
- [ ] Governed WordPress draft canary passes with readback and rollback.
- [ ] Semantic publication verification passes.
- [ ] Operator/Doctor/DLQ + live recovery review pass.
- [ ] Formal critical-state/liveness proof passes.
- [ ] Exact ETG Staging linked vertical slice emits CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED.
- [ ] Production remains separately unauthorized until a distinct production gate is approved.
