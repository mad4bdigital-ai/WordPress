# Traceability Matrix — Feature 007

| Requirement family | Implementation surface | Primary evidence |
|---|---|---|
| REL | lineage reconciliation + CI | reconciliation artifact + exact-head CI |
| AUTH | Multi-Authority Registry/resource/subject mapping | full REST chain + live certification |
| KEY | Local OAuth key lifecycle | JWKS/keyring rotation evidence |
| MCP | protocol/package evolution | exact adapter package + protocol regressions |
| WFP | Workflow Providers facade/adapters | contract tests + runtime status |
| PC | provider certification engine | package hashes + semantic delta + capability evidence |
| DPC | dynamic capability certification/evidence graph | fingerprints + diff classifier + probes |
| DIAG | workflow-provider diagnostic | read-only artifact/capability/security inventory |
| PRV | Provider Resolver | resolution decision + candidate reason codes |
| RING | release rings/autopromotion | ring transition evidence |
| BRG | signed workflow bridge | request binding/replay/evidence tests |
| CJ | Content Job registry/state machine | schema/runtime tests + events |
| ART | Artifact registry/graph | fingerprint/lineage tests |
| CTX | Context Authority + dispatcher | ContextPack fixtures + lineage |
| WR | Writer Profile registry/distiller | profile version/hash tests |
| RES | research adapters | provider contracts + normalized artifacts |
| COMP | competitive intelligence | frozen-fixture reproducibility |
| PLAN/WRITE/QA | Skills + artifact services | quality-gate decisions |
| PUB | existing content/media/SEO abilities | PublishManifest + readback evidence |
| HOST | separate Host Connector | connector grants/provider evidence |
| CRON | cron semantic family | provider-specific runtime tests |
| GROW | search performance providers | snapshots + refresh lineage |
| QCORR | correctness/idempotency/concurrency | transaction + replay/race evidence |
| QRES | durable execution/resilience | lease/retry/fault-injection evidence |
| QSCHEMA | schema/contract evolution | migration + compatibility evidence |
| QSEC | threat model | negative security tests |
| QSUPPLY | supply chain/secrets | artifact/dependency/secret evidence |
| QAI | source trust/LLM evaluation | eval fixtures + grounding/injection tests |
| QTENANT | tenant/privacy | isolation + retention/erasure tests |
| QPERF | performance/capacity/cost | SLO/load/budget evidence |
| QDR | disaster recovery | restore/rollback rehearsal |
| QTEST | verification strategy | property/fuzz/fault/matrix evidence |
| QDRIFT | policy drift/kill switches | desired-vs-observed + fail-closed tests |
| QSPEC | cross-feature spec isolation | unrelated feature CI stays green |
| PORT | decommission-portability | export/revoke/final decommission report |
| USAGE | usage-ledger-chargeback | usage/budget/reconciliation ledger |
| EXP | experimentation-attribution | variant/exposure/guardrail evidence |
| EVALREG | eval-registry-alerting | eval provenance + error-budget alerts |
| LOC | localization-accessibility-linkgraph | locale/a11y/link graph evidence |
| FAIR | fair-scheduling-local-autonomy | quota/fairness/outage fixtures |
| CONF | provider-conformance-contract-lifecycle | cross-provider conformance + deprecation evidence |
| OPS | operator-control-doctor-deadletter | doctor/repair/DLQ evidence |
| AIDATA | ai-data-processing-residency | classification/provider processing decisions |
| RIGHTS | content-rights-licensing | rights/attribution/takedown evidence |
| PVERIFY | publication-verification | origin/edge/rendered SEO propagation evidence |
| RECOMP | incremental-recompute | minimal invalidation/recompute plan tests |
| STORE | artifact-storage-retrieval | blob integrity/quota/GC evidence |
| INTENT | content intent registry | canonical owner + collision decisions |
| BOOT | existing-site-bootstrap-content-inventory | bootstrap snapshot + inventory reconciliation |
| ATTEST | evidence-attestation-trust | signed/revoked evidence verification |
| POLICY | policy-resolution-separation-of-duties | effective decision + precedence/conflict tests |

## Dependency graph
release-lineage
→ canonical rc.59
→ provider-certification
→ workflow-provider
→ content-job
→ artifact/quality-gates

content-job
→ context-dispatch
→ writer-profile
→ research-provider
→ competitive-intelligence
→ blueprint/writing/QA
→ publishing

Host Connector is a separate authority track.
Growth depends on stable published-content identity.

## Gates
CAN_PLAN requires valid job + required ContextPack + minimum research + no hard source blocker.

CAN_WRITE requires CAN_PLAN + current approved ContentBlueprint + exact WriterProfile + non-stale context.

CAN_PUBLISH requires current ArticleDraft + FactLedger with no hard unsupported claim + EditorialQA pass + SEOQA pass + exact PublishManifest/target state.

CAN_SCHEDULE additionally requires schedule policy/authority.

CAN_ACTIVATE_PRODUCTION is always separate from content-quality gates.


## Expanded dependency graph
multi-authority-registry
→ subject-mapping
→ full REST filter-chain acceptance
→ multi-authority-live-certification
→ rc.59 release trust

provider-artifact
→ workflow-provider-diagnostic
→ artifact-diff-classifier
→ capability-fingerprint
→ behavioral/security/recovery evidence
→ global capability certification
→ site runtime compatibility
→ release ring
→ provider resolver eligibility

Provider Resolver remains non-authorizing; authority/approval/budget still execute after resolution.


## Quality dependency graph
canonical data contracts
→ atomic state/idempotency
→ durable execution
→ provider/workflow/content orchestration

threat model
→ supply-chain/secrets
→ source trust
→ tenant isolation
→ live security acceptance

schema evolution
→ compatibility matrix
→ migration/restore rehearsal

AI process versioning
→ eval suites
→ publish quality gates

SLO/capacity/cost
→ release-ring scale eligibility

No quality branch can widen authority; these gates can only block or constrain release.


## Long-lived platform dependency graph

site-bootstrap
→ content-inventory
→ intent-registry
→ content-job planning

artifact-store
→ artifact graph
→ incremental recompute
→ evaluation/publication evidence

policy-resolution
→ approval/separation-of-duties
→ signed evidence trust
→ capability execution

publish manifest
→ site mutation
→ publication verification
→ growth/experiment observations

provider conformance
→ certification
→ resolver
→ fair scheduler

localization cluster
→ content artifacts
→ link graph
→ publication verification

usage ledger + SLO/error budgets + operator control
→ operational governance

quiesce
→ export/revocation
→ decommission/portability

No downstream layer can widen upstream authority or fabricate evidence.
