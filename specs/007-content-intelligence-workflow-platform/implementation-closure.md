# Unified Implementation Closure — Feature 007

## Purpose

This document is the normative closure program for the remaining Feature 007 work after PR #60, PR #61 and PR #62 are integrated into `master`.

It does not redefine the existing contracts. It orders them into one executable program, separates first-vertical-slice blockers from maturity work, and prevents the implementation from expanding governance infrastructure indefinitely while the Content Intelligence vertical slice remains unproven.

## Baseline

- target branch: `master`
- reviewed repository parent: `deb28052ab6e89b87f6154557e2d0f78f2b122c6`
- Control Plane line: `0.4.0-rc.59`
- MCP Adapter line: `0.6.1`
- Production authorization: false
- Architecture Freeze: active
- repository ruleset: `23968498` active on `refs/heads/master`, no bypass actors
- governance bootstrap exception: retired in PR #64

Any descendant synchronization MUST update the exact reviewed-parent identity (`feature.json.last_reviewed_master_parent_sha`), this document and the machine-readable closure ledger together. Deployable runtime-release identity remains separately evidenced and MUST NOT be rewritten merely because repository-only changes landed.


### Baseline release-root evidence

For this exact baseline, the trusted-master root has already been recaptured after PR #64 merged and repository governance was enforced:

- Package workflow run: `36072550999`
- General Distribution artifact: `10838403565`
- outer digest: `sha256:23887c052950d62f42d68ddba378460ec80a68f9b14365bc3d91220be451b63e`
- receipt artifact: `10838323685`
- source SHA: `540d5db4be521297de673c8a4d14974c23b67a6a`
- Control Plane: `0.4.0-rc.59`
- build fingerprint: `f16cb7ecccff30bd1d54aa3088de404f5315823222f4e26b73fb340b4195a8c6`
- package manifest digest: `3d2870fd75ad5b6822a76fa00e1b7b90489ad6b121d6d0dd8e31ea6f07541950`
- archive SHA-256: `c8885bdfc42e6aa1a6ce44896f6a9b6b54742a1d7d56269605402ffe11c33b06`
- external trusted-master verification: PASS

This evidence closes the baseline-bound release-root workstream. It does **not** prove that ETG Staging has this artifact installed.


### Observed live ETG Staging state

Current read-only evidence is intentionally recorded as a blocker input, not as deployment proof:

- live Control Plane: `0.4.0-rc.59`
- live source SHA: `03a86d72dfb6d1d7b866dda6c6d9a9b006e6c81c`
- target selected runtime-release SHA: `540d5db4be521297de673c8a4d14974c23b67a6a` (superseded for deployment once this runtime-changing bulk PR merges; post-merge recapture required)
- trusted master deployed: false
- live manifest: present/valid/runtime-match; stale=false; provenance_mismatch=[]
- MCP Adapter: `0.6.1`
- protected backup root: exists=false, writable=false, ready=false, requires_preparation=true
- filesystem trust read abilities exist, but the current subject is not authorized; the seven trust files are therefore UNKNOWN from this subject, not missing
- Bit Flows: installed `1.29.0`, inactive; prior certified baseline `1.24.0`; available=false; runtime_contract_ok=false; execution_enabled=false
- breakglass=false; mutation_performed=false

The next live mutation is forbidden until the protected backup/recovery gate passes.

## Closure classes

| Class | Meaning |
|---|---|
| KERNEL_BLOCKER | MUST close before `CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED`. |
| LIVE_PRECONDITION | MUST close before the related real ETG Staging mutation/canary. |
| MATURITY_REQUIRED | Required platform work, but not a blocker for the first bounded content vertical slice unless a runtime dependency admits it into the kernel. |
| DEFERRED_MATURITY | Designed and tracked; intentionally after the first vertical slice. |

No class grants authority. Production, Breakglass, host execution and public publish remain separately authorized.

## Critical closure sequence

1. Repository governance external enforcement — DONE.
2. Governance bootstrap exception retirement — DONE.
3. Exact latest-master release/root-trust recapture — DONE.
4. Protected backup root and Recovery Plane readiness.
5. Recapture a trusted post-merge runtime release, then deploy that exact selected runtime artifact to ETG Staging.
6. Read back runtime provenance and all seven Root Trust/provenance files from the deployed candidate.
7. Exact installed Bit Flows 1.29.0 recertification and privileged-side-channel decision.
8. Multi-Authority live subject path, Policy Resolution Engine and gate-liveness truthfulness.
9. Existing-site bootstrap and Intent Registry reconciliation.
10. ContentJob domain service plus immutable Artifact Registry/Store/lineage.
11. Knowledge Dispatcher, ContextPack and WriterProfile version binding.
12. Research providers, competitive intelligence and normalized evidence.
13. Blueprint → ArticleDraft → FactLedger → Editorial/SEO/Final QA.
14. Governed WordPress draft mutation with exact target/readback/rollback evidence.
15. Semantic origin/publication verification.
16. Operator/Doctor/reconciliation/recovery review and formal critical-state proof.
17. Exact ETG Staging end-to-end vertical slice.
18. Emit `CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED` only from linked evidence for the exact deployed candidate.

## Remaining workstream matrix

| Workstream | Class | Closure evidence |
|---|---|---|
| Repository ruleset applied/read back on `master` | KERNEL_BLOCKER | active ruleset, no bypass actors, pinned Release Verdict check |
| Governance bootstrap exception retirement | KERNEL_BLOCKER | bootstrap path removed/permanently disabled after ruleset readback; ordinary PR fails closed if ruleset disappears |
| Spec execution ledger reconciliation | KERNEL_BLOCKER | every legacy task classified DONE/PARTIAL/OPEN/DEFERRED with evidence refs |
| Latest-master release root | KERNEL_BLOCKER | trusted master attestation + package/Live parity on current baseline |
| Protected backup/recovery root | LIVE_PRECONDITION | exists/writable/ready + backup receipt + known-good restore proof |
| Exact selected-runtime-release deployment to ETG | LIVE_PRECONDITION | deployed source/build/manifest/archive identity matches the externally attested selected runtime release; repository HEAD equality is not required for non-runtime deltas |
| Runtime Root Trust readback | LIVE_PRECONDITION | seven trust/provenance files read from deployed runtime and matched to package evidence; UNKNOWN does not satisfy the gate |
| Recovery Plane live drill | KERNEL_BLOCKER | deliberate normal-plane failure and attested recovery |
| Bit Flows 1.29 exact package | KERNEL_BLOCKER | artifact/tree/hash/semantic/runtime certification |
| Bit Flows privileged side-channel | KERNEL_BLOCKER | proven absent, suppressed, read-only federated, or blocking |
| Capability semantic traits | KERNEL_BLOCKER | `workflow.execute` CapabilityProfile fingerprint bound into plans |
| Dynamic provider certification | MATURITY_REQUIRED | lifecycle, diff classifier, reusable dependency evidence |
| Provider Resolver + signed bridge | MATURITY_REQUIRED | deterministic non-authorizing selection + replay-safe envelope |
| Provider release rings | MATURITY_REQUIRED | R0–R4 promotion/demotion/quarantine evidence |
| Existing-site bootstrap | KERNEL_BLOCKER | normalized content/SEO/canonical/media/link inventory |
| Intent Registry | KERNEL_BLOCKER | many-to-many ownership/collision/cannibalization decision |
| ContentJob domain | KERNEL_BLOCKER | create/transition/cancel/read + state/stage/revision/event invariants |
| Artifact Registry + lineage | KERNEL_BLOCKER | immutable versions/edges/fingerprints/invalidation |
| ArtifactStore | KERNEL_BLOCKER | content integrity + tenant-safe addressing + bounded GC/retention semantics |
| Incremental recompute | MATURITY_REQUIRED | minimal invalidation and unaffected-artifact preservation |
| Knowledge Dispatcher / ContextPack | KERNEL_BLOCKER | bounded, versioned, dependency-linked context |
| Writer Profiles | KERNEL_BLOCKER | immutable approved profile version/hash pinned to job |
| Research provider platform | KERNEL_BLOCKER | normalized keyword/SERP/search/scrape evidence and error contracts |
| Competitive intelligence | KERNEL_BLOCKER | SERP/competitor/topic/question/entity/evidence matrices |
| Blueprint/Writing/QA | KERNEL_BLOCKER | blueprint, draft, FactLedger and hard QA gates |
| Governed WordPress draft | KERNEL_BLOCKER | exact PublishManifest, mutation envelope, readback and rollback |
| Scheduling/public publish | MATURITY_REQUIRED | environment-aware schedule/publish with stale-target denial |
| Publication verification | KERNEL_BLOCKER | origin/public semantic fingerprints and canonical/robots/schema/hreflang/media checks |
| Rights + AI data processing | MATURITY_REQUIRED | RightsRecord, takedown invalidation, residency/redaction/fallback policy |
| Policy Resolution Engine | KERNEL_BLOCKER | explainable precedence across deny/kill-switch/environment/certification/approval/grant |
| Approval/SoD/quorum modes | MATURITY_REQUIRED | distinct-principal/quorum/delegation/emergency rules where applicable |
| Evidence trust lifecycle | KERNEL_BLOCKER | trust roles, revocation, TTL/expiry/tamper negatives |
| Gate DAG/liveness/modes | KERNEL_BLOCKER | parser, cycle/unknown rejection, reachability, blocker sets and truthful modes |
| Authoritative consistency | KERNEL_BLOCKER | aggregate/event/artifact checker + BLOCKED_FOR_RECONCILIATION |
| Fencing failure model | KERNEL_BLOCKER | crash-after-success, stale writer, duplicate/reordered callback effect-once tests |
| Security hardening | KERNEL_BLOCKER | SSRF/rebinding/injection/XSS/SQL/path/command/wrong-site/provider compromise/tenant isolation negatives |
| AI evaluation | KERNEL_BLOCKER | model/prompt/Skill/input fingerprints, grounding and regression/fallback gates |
| Performance/fault/recovery evidence | KERNEL_BLOCKER | SLO/cost + property/fuzz/fault/restore/compromise drills for the admitted kernel |
| Operator / Doctor / DLQ | KERNEL_BLOCKER | blocker read model, RepairPlan, stuck/orphan/DLQ replay/quarantine |
| Governed Tool Execution / CLI / Recovery Runner | LIVE_PRECONDITION | semantic operations, exact executor, no generic shell, terminal-independent Runner bootstrap/enrollment, recovery independence |
| Cron semantic provider | MATURITY_REQUIRED | read/health/run then governed schedule/unschedule |
| Provider backlog | MATURITY_REQUIRED | WP Import/Export, JetSmartFilters, SEO providers via same certification contracts |
| Adaptive Search Intelligence Fabric | MATURITY_REQUIRED | Phase 38 meta-model/context/surface/target/decision/budget/provider/evidence/experience conformance; explicitly non-authorizing |
| Growth loop | DEFERRED_MATURITY | performance/index/decay/cannibalization/refresh observations |
| Fair scheduling/local autonomy/localization/a11y/link graph | DEFERRED_MATURITY | quality and isolation evidence |
| Eval ops/alerts/experiments/usage ledger | DEFERRED_MATURITY | governed operational/economic evidence |
| Decommission/portability | MATURITY_REQUIRED | quiesce/export/import/remap/revoke/final authority proof |
| Formal critical-state proof | KERNEL_BLOCKER | authority/approval/commit/fencing/provider invariants + liveness |
| Exact ETG Staging vertical slice | KERNEL_BLOCKER | one linked evidence chain from trusted package through verified WordPress draft/public semantics and recovery |

## Execution-ledger rule

A task is not DONE because code with a similar name exists. DONE requires an exact evidence reference tied to a commit/artifact/runtime receipt. PARTIAL requires an explicit remainder. OPEN and DEFERRED are distinct.

The legacy unchecked task list therefore MUST be reconciled; it MUST NOT be mass-marked complete.

## Critical Kernel Definition of Done

One evidence chain on real ETG Staging MUST prove:

`repository governance → bootstrap retirement → selected runtime release/root trust → protected recovery → exact ETG runtime-release deployment → runtime Root Trust readback → live authority → exact provider certification → bootstrap → intent → ContentJob → artifacts → context/writer/research → blueprint/draft/QA → governed WordPress draft → semantic verification → recovery/formal review`.

The terminal gate is invalid if any dependency is inferred from disposable CI, a different SHA, a different provider artifact, or a previous Staging candidate.

## Parallel maturity lanes

Provider rings/resolver breadth, cron breadth, growth, fairness/localization/accessibility, experimentation, usage/chargeback and broad portability may proceed in parallel after their upstream contracts exist. They do not silently become blockers for the first slice.

Architecture Freeze still applies: runtime evidence may admit a deferred concern into the kernel, but a speculative abstraction may not.

## Hard boundaries

- No arbitrary shell, arbitrary PHP, unrestricted `wp eval`, or unrestricted raw SQL in ordinary catalogs.
- Full Staging Authority does not imply Host Execution Authority.
- Transport availability does not create authority.
- Repository readiness does not imply ETG live readiness.
- Staging certification does not imply Production authorization.
- Production remains false until separately authorized.


## Bulk runtime closure hardening

The normative machine-readable matrix is `bulk-closure-hardening.json` under contract `mad4b.feature007-bulk-closure-hardening.v1`.

The bulk lane closes the remaining review findings without widening the architecture:
- durable pre-mutation journal and atomic evidence persistence;
- explicit `MUTATED_BUT_EVIDENCE_UNCERTAIN` reconciliation state;
- lease/zombie/replay/crash-after-side-effect/provider-uncertainty fault injection;
- centralized filesystem/process confinement;
- minimal runner bootstrap/enrollment;
- protected backup plus corrupt/interrupted restore rehearsal;
- WordPress-independent Recovery Runner proof;
- cross-executor parity and execution-location truthfulness;
- blocker-to-terminal gate liveness;
- Doctor/DLQ/reconciliation incident behavior;
- exact Bit Flows 1.29 behavioral/security recertification;
- one complete live request-to-rollback vertical slice.

Repository CI proves contracts and denial behavior only. Live ETG gates still require fresh exact-runtime evidence; documentation cannot close them. Production authorization remains false.


## Host deployment closure update

Repository implementation now includes the bounded `wordpress_plugin_deploy` Host Bridge → Host Runner semantic operation. It accepts exact package identity only, consumes a pre-staged General Distribution bundle from the fixed runner zone, verifies manifest/receipt/provenance/archive inventory, backs up the current Control Plane, performs an atomic plugin-directory swap, verifies the installed exact package in the same cycle, and rolls back on failed readback.

This closes the repository-side alternative to manual hosting-terminal deployment. Live ETG remains **PARTIAL** until the one-time first bootstrap installs a runtime containing this Host Bridge/Runner capability through an already-authorized external deployment connector; after that bootstrap, subsequent Control Plane deployments can use the governed semantic path directly.

## Phase 38 maturity extension — Adaptive Search Intelligence

Phase 38 is now an explicit MATURITY_REQUIRED extension documented by `adaptive-search-intelligence.md`, `contracts/adaptive-search-intelligence-runtime.md` and `adaptive-search-intelligence.json`. It generalizes the Phase 6 SERPProvider and Phase 14 Growth concepts into an adaptive, provider-neutral Search Intelligence runtime with live surface/language/SEO discovery, target compilation, fair economic scheduling, immutable SERP evidence, confidence-aware signals and a capability/state-driven operator experience.

This extension is deliberately outside the current Critical Kernel terminal proof unless a concrete runtime dependency is later admitted through the normal Architecture Freeze process. It creates no Production authority, no Breakglass widening, no generic HTTP surface and no direct search-signal-to-content mutation path.


## Adaptive Search review status: APPROVED_P0_CLOSED_P1_OPEN

The independent Phase 38 review is recorded in `adaptive-search-intelligence-review.md` and `adaptive-search-intelligence-review.json`.

Current review score: **88/100 overall specification maturity**.

The review identified 12 P0 and 6 P1 gaps. The **12 P0 gaps are now CLOSED** at repository/runtime-contract level by exact implementation head `042e49926d909dd11d0925b5b19b19143561b556`, Feature 007 Spec Quality CI run `37239748455`, Runtime Integration run `37239748528` (WordPress 6.9 + latest PASS), and Spec Kit consistency run `37239748494`, and Pre-Staging Hybrid Audit run `37239748433`. Closure includes certified cross-provider comparability, canonical query/URL/rank/capture semantics, fail-closed SearchEligibilityEnvelope, bounded surface admission with cardinality and URL-pagination protection, truthful shared-account budget authority with stable provider account identity, evidence-rights retention, deterministic decision policy, measurable gates and composed cross-fault behavior.

The **6 P1 findings remain OPEN**. Phase 38 remains MATURITY_REQUIRED and OPEN; P0 closure does not imply provider-live completeness, Staging certification, Production authorization or direct content mutation authority.

## Phase 38 repository runtime implementation

The complete discovery → context → target → budgeted capture → immutable snapshot → signal/graph → governed Content Experience proposal path is implemented under `includes/search/`, loaded by `class-mad4b-scp-adaptive-search-intelligence.php`. The Phase 38A compiler remains a composed validation/dependency boundary, and the existing account budget authority shares the same fenced coordinator used by capture. Registered abilities use the existing execution wrapper and private catalogs. Search configuration grants no spend, content mutation or Production authority.

`adaptive-search-runtime-acceptance.json` binds all 15 repository conformance gates to executable positive/denial fixtures and thresholds. The dedicated workflow runs PHP 7.4/8.3 plus disposable WordPress/MySQL proof of real CAS races, public CPT/taxonomy discovery, governed ability mounting and accessible operator rendering. PASS gate records require a clean exact CI head and source digest. Local fixtures currently pass 39 scenarios and 893 assertions; local evidence emits no PASS gates.

Implementation tasks remain PARTIAL while this exact-head workflow is pending. Provider activation separately requires account-bound descriptor certification, observed economics/usage/reset evidence, opaque credentials and licensed retention/region evidence. Owned tracking requires live rendered eligibility and locale facts. Repository acceptance does not grant or claim provider-live or Production readiness.
