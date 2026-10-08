# Acceptance Gates, Threat Models and Quality Scorecard

Contract: `mad4b.aci-os.acceptance.v1`.

## Evidence levels (independently required)

| Level | Proof | Not equivalent to |
|---|---|---|
| REPOSITORY | pinned exact-head tests, spec traceability and code review | installed WPML or provider runtime |
| DISPOSABLE_NATIVE | real WP/MySQL/MariaDB/WPML isolated asset, before/after readbacks and rollback | current site readiness |
| LIVE_STAGING | exact deployed artifact/site/subject/provider version and permitted operation receipt | Production approval |
| REAL_BROWSER | rendered/RTL/accessibility/navigation/SEO/hreflang/AJAX/permission journeys | static HTML assertions |
| EXTERNAL_PROVIDER | active account/consent, exact scopes, real cost and provider-certified behavior | model simulation |
| PERFORMANCE | comparable baseline, representative concurrent workload, configured thresholds | generic absolute budget |
| RELEASE | owner-reviewed plan and independent Production gates | GitHub mergeability |

## Blocking acceptance matrix

| Gate | Exit tests | Adversarial denial |
|---|---|---|
| ACI-G0 | spec files, IDs, task/requirement/ownership, no false completion | missing docs, ghost task, status DONE, unsafe authority flag |
| ACI-G1 | context/brand revision, site/locale inventory, identity | missing tone, wrong brand, stale source, clone |
| ACI-G2 | research normalization, source integrity, account budget CAS | poisoned scrape, unpriced request, mixed currency, double debit |
| ACI-G3 | evidence+gap matrix, comparable opportunity baseline | copied competitor claims, hallucinated gain, contradictory facts |
| ACI-G4 | native typed refs, WPML group/field settings, semantic equality | original-ID fallback, cross-site, wrong term namespace, missing locale |
| ACI-G5 | context+voice+blueprint exact revisions and editor diff | source corpus leakage, human overwrite, disputed claim |
| ACI-G6 | fact/SEO/editorial/rights independent gate receipts | AI self-approval, uncited fact, illegal media, link rot |
| ACI-G7 | durable stage replay, lease/CAS, bounded retries, kill switch | crash-after-charge, late callback, retry-after-UNKNOWN, restore |
| ACI-G8 | exact PublishManifest/approval, native + rendered readback and Undo | revoked approval, modified post, wrong WPML locale, duplicate publish |
| ACI-G9 | GSC/GA4 window comparability, uncertainty, proposal-only | mixed device, seasonality, attribution drift, vanity metric |
| ACI-G10 | host/grants/skills, external/browser/perf, release certificate | stale runtime, 403/404 route, sandbox missing, Production scope leak |

## Performance and reliability

Performance SLOs must be chosen per actual environment and workload. Collect at least three comparable frontend samples for Staging acceptance where applicable, queue wait/run p50/p95, DB queries, peak memory, request overhead, provider account spend, crawl credits, job recovery time and editorial rework. Fixed global thresholds without a baseline are forbidden. Define budget ceilings at plan time, not after receipt.

## Provenance and acceptance record

Every closed task references `task_id`, exact `commit_sha`, `build_fingerprint`, site profile, provider version/account, test environment, testcase IDs, executed-at, evidence hash, interpretation scope and any nonreversible external effect. Tests not run are `NOT_RUN`; unavailable external acceptance is `EXTERNAL_PENDING`, not PASS.

## Release red lines

No Production authority, Breakglass, host shell or paid provider execution is granted by this Spec Kit. Missing WPML mapper semantics prevents relation auto-remap and related publication changes. All optional autonomy work honors CE01 levels and cannot bypass original MAD4B policy/capability/admission/commit guards.

## Dynamic-by-default review and product clarity gates (ACI01 v2)

**Design acceptance is separate from repository/CI and live release acceptance.** The current design remains `DESIGN_REVIEW_REQUIRED` until these validations run on one exact reviewed HEAD:

- Registry-to-Markdown and reverse requirement/task/gate coverage, with individual task meaning, output evidence and denial cases; no blanket group-wide mapping.
- DAG conformance: execution dependencies acyclic, certification dependencies separately acyclic, optional G4 only when native relations are actually required.
- Actual graph-path analysis: every route into WordPress native write effect must cross commit guard and governed dispatch. Added edges with unexpected write reachability are rejected.
- Pure candidate simulation: current site UUID/origin/generation/restore epoch required; simulated provider certificate cannot become write authority; all paid/WordPress/host effect requests denied by Spec Kit planner.
- Six ContentRecipes and domain-specific FactAuthority profiles; unknown price/availability/rights/translation source blocks only the dependent operation.
- 30 use-case fixtures with expected state, named failure, content recipe and relevant gate. These are **declared scenarios**, not live tests.
- Negative tests against stale runtime, mixed locale/account/currency, poisoned evidence, unsafe media, human edit, duplicate paid effect, uncertain publish, restore replay, Original-ID WPML fallback and missing native/browser readback.
- Test `validate.py`, `test_validate.py`, `test_dynamic_core.py` on exact source; repository PASS is only structural. Independently record disposable/native, external-provider, browser, Staging, performance and eventual release receipts.

A failed or unexecuted validation makes design certification `BLOCKED` or `NOT_VERIFIED`; it must not silently promote task, gate, release or authority status. See `project-charter.md` for the product's complete preexecution STOP gate.
