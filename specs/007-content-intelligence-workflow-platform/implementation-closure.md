# Unified Implementation Closure — Feature 007

## Purpose

This document is the normative closure program for the remaining Feature 007 work after PR #60, PR #61 and PR #62 are integrated into `master`.

It does not redefine the existing contracts. It orders them into one executable program, separates first-vertical-slice blockers from maturity work, and prevents the implementation from expanding governance infrastructure indefinitely while the Content Intelligence vertical slice remains unproven.

## Baseline

- target branch: `master`
- synchronized baseline: `ae40fa8821934318bec7c386d7acdf77653b8a87`
- Control Plane line: `0.4.0-rc.59`
- MCP Adapter line: `0.6.1`
- Production authorization: false
- Architecture Freeze: active

Any descendant synchronization MUST update `feature.json.current_baseline_head`, `baseline_sync_commit`, this file and the machine-readable closure ledger in the same governed change.


### Baseline release-root evidence

For this exact baseline, the trusted-master root has already been recaptured:

- Package workflow run: `36068553105`
- General Distribution artifact: `10836588669`
- outer digest: `sha256:e6c498d4330bb9c64e3d3d9c25fab4a890a2c78c870bd7fb8fc7e9714be18a78`
- receipt artifact: `10836443926`
- source SHA: `ae40fa8821934318bec7c386d7acdf77653b8a87`
- Control Plane: `0.4.0-rc.59`
- build fingerprint: `d543708e7191ec8b6e13e18f90d4b66cc3639eb411b864de92c446ce8c2391ac`
- package manifest digest: `17af19216dc029da672e22c93ac06757ccfe497f31e1373b7246dfd0599032e5`
- archive SHA-256: `0f6172e2b59ba933a8dbacba975334b69e5b4c83c613202589bbf2202a984d23`
- external trusted-master verification: PASS

This evidence closes the baseline-bound release-root workstream. It does **not** prove that ETG Staging has this artifact installed.


### Observed live ETG Staging state

Current read-only evidence is intentionally recorded as a blocker input, not as deployment proof:

- live Control Plane: `0.4.0-rc.59`
- live source SHA: `03a86d72dfb6d1d7b866dda6c6d9a9b006e6c81c`
- target trusted master SHA: `ae40fa8821934318bec7c386d7acdf77653b8a87`
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

1. Repository governance external enforcement.
2. Retire the one-time governance bootstrap exception after independent ruleset readback.
3. Exact latest-master release/root-trust recapture.
4. Protected backup root and Recovery Plane readiness.
5. Deploy the exact trusted master artifact to ETG Staging.
6. Read back runtime provenance and all seven Root Trust/provenance files from the deployed candidate.
7. Exact installed Bit Flows 1.29.0 recertification and privileged-side-channel decision.
8. Multi-Authority live subject path, Policy Resolution Engine and gate-liveness truthfulness.
6. Existing-site bootstrap and Intent Registry reconciliation.
7. ContentJob domain service plus immutable Artifact Registry/Store/lineage.
8. Knowledge Dispatcher, ContextPack and WriterProfile version binding.
9. Research providers, competitive intelligence and normalized evidence.
10. Blueprint → ArticleDraft → FactLedger → Editorial/SEO/Final QA.
11. Governed WordPress draft mutation with exact target/readback/rollback evidence.
12. Semantic origin/publication verification.
13. Operator/Doctor/reconciliation/recovery review and formal critical-state proof.
14. Exact ETG Staging end-to-end vertical slice.
15. Emit `CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED` only from linked evidence for the exact deployed candidate.

## Remaining workstream matrix

| Workstream | Class | Closure evidence |
|---|---|---|
| Repository ruleset applied/read back on `master` | KERNEL_BLOCKER | active ruleset, no bypass actors, pinned Release Verdict check |
| Governance bootstrap exception retirement | KERNEL_BLOCKER | bootstrap path removed/permanently disabled after ruleset readback; ordinary PR fails closed if ruleset disappears |
| Spec execution ledger reconciliation | KERNEL_BLOCKER | every legacy task classified DONE/PARTIAL/OPEN/DEFERRED with evidence refs |
| Latest-master release root | KERNEL_BLOCKER | trusted master attestation + package/Live parity on current baseline |
| Protected backup/recovery root | LIVE_PRECONDITION | exists/writable/ready + backup receipt + known-good restore proof |
| Exact trusted-master deployment to ETG | LIVE_PRECONDITION | deployed source/build/manifest/archive identity matches the exact master artifact |
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

`repository governance → bootstrap retirement → trusted master package → protected recovery → exact ETG deployment → runtime Root Trust readback → live authority → exact provider certification → bootstrap → intent → ContentJob → artifacts → context/writer/research → blueprint/draft/QA → governed WordPress draft → semantic verification → recovery/formal review`.

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
