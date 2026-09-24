# Traceability Matrix — Feature 007

| Requirement family | Implementation surface | Primary evidence |
|---|---|---|
| REL | lineage reconciliation + CI | reconciliation artifact + exact-head CI |
| WFP | Workflow Providers facade/adapters | contract tests + runtime status |
| PC | provider certification engine | package hashes + semantic delta + capability evidence |
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
