# ACI01 — Pre-Implementation Clarity Review

**Review date:** 2026-10-08. **Reviewed branch snapshot before this report:** `13da3c56dc924615640f09f3fab0e4970f6aa65d`. Scope: specification, architecture and planning only. This is **not** an implementation, CI, Staging, provider, browser or production certification.

## Decision: DESIGN_REVIEW_REQUIRED — STOP runtime execution

### Scope evaluated
- Canonical `project-charter.md`: a multi-site/multi-brand/multilingual governed content-intelligence layer atop MAD4B, with six content recipes and explicitly separate sources of truth.
- Normative `dynamic-foundation.md` and `dynamic-policy.json`: dynamic-by-default resolution for site, market, language, author, evidence, provider, account cost, content type, WPML relations, workflow, QA, publication, learning, UI and recovery.
- Typed `task-registry.json`, `requirements.json`, `acceptance-gates.json`, `system-map.json`; doc views `tasks.md`, `traceability.md` and `plan.md`.
- `domain-fact-authority.json`, `content-recipes.json`, `use-cases.json`; pure non-authorizing `dynamic_core.py`, `validate.py` plus adversarial tests.

### Read-only structural inspection results
| Check | Result | Truth class |
|---|---|---|
| Requirement/Task/Gate inventory | 43/71/11 | static cross-file review |
| Content recipes | 6 | registry presence, not behavior |
| Declared scenarios | 30 | fixture definitions, not executed user journeys |
| Bidirectional task-requirement mapping | 0 mismatches in full registry inspection | static |
| Task Markdown ↔ task registry | 71/71 aligned | static |
| Traceability Markdown ↔ requirement registry | 43/43 aligned | static |
| Execution DAG cycles | 0 | static graph |
| Certification DAG cycles | 0 | static graph |
| Native write authority bypass in declared graph | 0 routes avoiding the two mandated barriers | static graph; not source-level PHP execution audit |
| Manifest Spec status | DESIGN_REVIEW_REQUIRED / SPEC_BACKLOG_ONLY | no completion/authorization |

These observations used the connected GitHub repo content and a separate JS-only graph/mapping inspection. **They do not mean the Python validators or test scripts passed.**

### Hard blockers before any runtime implementation or Hub integration
1. **Exact Python execution unverified**: `validate.py`, `test_validate.py`, `test_dynamic_core.py` need actual Python 3 execution on the exact child HEAD. Remote desktop is offline, connected GitHub Actions has not provided a current child-branch certificate and local runtime cannot fetch this repo. Until runner evidence exists, mark test state `NOT_RUN`.
2. **Dynamic source-level security not independently certified**: the graph is a declarative target, not proof that all WordPress, workflow and provider adapter methods are dominated by existing MAD4B authority.
3. **WPML Meta ID Mapper behavior, field registration and translation semantics are not certified** on disposable and live assets. No relation auto-repair.
4. **Exact Staging provider/candidate/Skills/host/browser acceptance not current**, and the hub is concurrently moving. No authority convergence or deploy.
5. **External rights/budget/provider SLOs and legal usage receipts are site/account-specific** and must be obtained for the selected site before enabling paid search/media operations.
6. **Functional acceptance for individual content recipes** needs executable native/browser/locale case matrices; declarative scenarios do not close product usability.
7. **G0 reviewer signoff pending**: ensure task-level outputs, profile-specific certification gates, project charter and parent Feature 007 reuse mapping are mutually acceptable and do not fork an existing operation authority.

### First implementation slice (proposed, NOT authorized)
- Choose one disposable WordPress validation site and one locale, one article/eligible tour-draft content recipe, one no-charge evidence source fixture and one native WPML relation read sample.
- Compile an OpportunityHypothesis → EvidencePack → Blueprint → Draft → independent QA → operator Review Ticket.
- Observe all outputs as versioned, non-authorizing artifacts; **zero WordPress writes, zero paid API calls and zero Production elevation**.
- Negative expected paths: missing brand voice, unknown native relation source, wrong term namespace, forged capability, stale site generation, source prompt injection, human-edit conflict and hypothetical external effect uncertainty.

### Required before progressing
- Run Python Spec Kit tests on exact source HEAD with command:
  `python specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/validate.py`
  `python specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/test_validate.py`
  `python specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/test_dynamic_core.py`
- Run parent Feature 007 validation and change-slice ownership against an exact, stable Hub base.
- Review profile-specific source-of-truth choices, the mandatory effects boundary and reusable parent interfaces; record named owners, decisions, evidence IDs, expiry and risks.
- Keep `#258` Draft, and do not merge this child work until exact-head review/CI and scoped ownership are verified. Do not merge `#258` to `master`.

## Decision ownership and evidence levels

`DESIGN`: human architecture/product review plus executable Spec Kit; `REPOSITORY`: exact CI; `DISPOSABLE`: native WordPress/WPML artifact; `STAGING`: live source/build/site/actor; `BROWSER`: actual rendered journey; `EXTERNAL`: real provider receipts and spend; `RELEASE`: owner promotion. None is interchangeable, and current review certifies only the explicit static inventory/graph observations.
