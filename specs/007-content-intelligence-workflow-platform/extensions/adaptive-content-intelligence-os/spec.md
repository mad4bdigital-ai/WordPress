# Product and Functional Specification — Adaptive Content Intelligence OS

Contract: `mad4b.aci-os.requirements.v1` | provenance: DESIGN_DERIVED, based on parent Feature 007 and CE01 concepts. Target users: owner, CMO, SEO strategist, editor, copywriter, native WordPress operator, reviewer, authorized automation agent.

## Intended outcomes

A business defines its brand, operating constraints, market/locale intent and existing content. The system finds economically meaningful gaps, produces a grounded editorial and commercial plan, helps create or improve content, validates native relationships and rights, publishes only through separately governed WordPress abilities, then measures changes using comparable external signals. It can route low-risk evidence preparation autonomously, but cannot self-approve permission, paid spending, publication or Production.

## Personas and journeys

- **Portfolio owner** chooses sites, brands, local market, consent, budget and exact approval scope; observes cost, incidents and release posture.
- **SEO/growth lead** prioritizes topic clusters, locale coverage, SERP intent, technical debt, cannibalization and conversion impact with evidence freshness.
- **Editorial strategist** compares entities/questions/experience gaps and configures voice, article types, audiences and risk level; reviews plan and blueprint.
- **Writer/editor** sees permitted WriterProfile and ContextPack citations, edits generated sections, requests evidence and approves quality revisions.
- **Native content steward** inspects WordPress posts, terms, related tours/products/properties and WPML groups without assuming mapped IDs are correct.
- **Operator** inspects blocked/uncertain runs, replay-safe recovery, preserved manual changes and exact native readback.

## Required capabilities

### ACI-01 — Enrollment and intelligence context
- ACI-001 Register site/brand/locale/market with immutable exact identity and data-residency/currency settings; no automatic production authority.
- ACI-002 Inventory existing content and imported products from governed WordPress reads, distinguish human-owned fields and historical translations.
- ACI-003 Resolve missing Brand Strategy, Tone of Voice, Editorial Guidelines and author voice before style-dependent writing; pause with meaningful operator actions.
- ACI-004 Context Authority fetches bounded sources and produces fingerprinted ContextPack and WriterProfile versions; never pass raw unrestricted folders into every job.
- ACI-005 Maintain domain-independent semantic content profile mapping source/target types, taxonomy, language and required fields.

### ACI-02 — Opportunity discovery and evidence
- ACI-006 Combine scoped keyword, Search Console, analytics and SERP signals with dates, intent, geography, device and provider comparability key.
- ACI-007 Detect topic/entity/question gaps, freshness decay, ranking losses, cannibalization and audience opportunities without making publication decisions.
- ACI-008 Collect provider-neutral search/scrape evidence under legal, rate, privacy and cost policies; robots/rights/terms recorded.
- ACI-009 Deduplicate URLs by canonical provenance and snapshot content hashes while preserving contradictory sources and historical revisions.
- ACI-010 Separate primary facts, secondary interpretations, AI conjectures, external provider measurements and unverified claims.
- ACI-011 Publish transparent opportunity priority = expected value, coverage, confidence, implementation cost and risks, each independently explainable.
- ACI-012 Require evidence sufficiency and freshness threshold per content class; conflicting or absent material facts enter REVIEW.

### ACI-03 — Strategy, plans, writing and media
- ACI-013 Generate linked topic clusters, market/locale outlines, internal-link candidates and content intent ownership decisions.
- ACI-014 Create comparative Experience Gap and Information Gain matrices by entity, question, user decision and factual coverage, not copied prose.
- ACI-015 Build ContentBlueprint with search intent, outline, commercial CTAs, entity claims, canonical/structured-data plan and approved sources.
- ACI-016 Distill versioned language/author WriterProfiles, voice examples and forbidden claims; never infer permission to copy copyrighted material.
- ACI-017 Generate drafts section-by-section with references to immutable evidence IDs, AI output versions, failure classification and prompt template identity.
- ACI-018 Validate claims, URLs, accessibility, SEO, rights, geographic/locale correctness and hallucination risks in independent evaluators.
- ACI-019 Provide media brief/asset production only with usage rights, accessible alt text, caption and WordPress media metadata/readback.
- ACI-020 Preserve human edits and existing published pages; use proposed deltas and configurable article/landing page/tour product recipes.

### ACI-04 — Native relation / translation integrity
- ACI-021 Inventory provider/field ownership and read typed native post/meta/taxonomy relations; never infer ID namespace.
- ACI-022 Bind snapshots to site/origin/build/restore epoch, source version, WPML group, locale, field policy, and fresh source provenance.
- ACI-023 Distinguish structural existence, type, source/group/locale consistency, translated semantic equivalence and final mutation eligibility.
- ACI-024 Validate WPML Meta ID Mapper/field translation semantics and detect copied source IDs, missing local translation, wrong target and human overrides.
- ACI-025 Detect empty/partial scans, pagination drift, stale snapshots, cross-site namespace collision, duplicate edge and ambiguous fallback; fail closed.
- ACI-026 Plan a typed, reversible, exact-bound relation change only for proven owned fields, with intent ownership, diff, human preview and provider-specific hook behavior.
- ACI-027 Re-read native IDs, terms, localization and rendered page after mutations; rollback/reconcile partial failures. Never auto-correct from inferred post titles.

### ACI-05 — Orchestration, publication and growth
- ACI-028 Durable Content Job DAG separates job state, stage, approval, artifact lineage, lease and external-effect state.
- ACI-029 WorkflowProvider selection is per certified capability and account; Bit Flows/n8n/native are optional execution mechanics.
- ACI-030 Typed inputs/outputs, constrained per-stage retry, deadlines, budget reservation, circuit breakers and kill switch.
- ACI-031 Freeze PublishManifest with blueprint, approval, final content/media, relation references, SEO/canonical/translation targets and exact revision checks.
- ACI-032 Use governed WordPress content/media/SEO abilities; draft preview, explicit publication approval, commit-time CAS, native and rendered readback are separate steps.
- ACI-033 Never infer Production consent, tool scopes, OAuth re-consent or Breakglass from approved copy or job status.
- ACI-034 Collect GSC/GA4/organic/engagement/revenue outcomes through scoped adapters; no mixing unmatched currencies, markets or attribution windows.
- ACI-035 Compare outcome to baseline/control and uncertainty; generate optimization proposals with independent owner review.
- ACI-036 Prevent feedback-loop flapping, vanity metric optimization and unbounded AI spend; keep cost per accepted artifact and conversion learning traceable.

### ACI-06 — Governance and operator experience
- ACI-037 Central action workspace for blocked evidence, review decisions, provider setup, native previews, diff/recovery, schedules and related receipts.
- ACI-038 Show clear Arabic/RTL and localized messages without exposing internal IDs except on expanded diagnostics.
- ACI-039 Every action has explicit eligibility, actor scope, blast radius, cost, expiry, expected postcondition, uncertainty and Undo capability.
- ACI-040 Make fault handling observable: wrong OAuth subject, expired grant, external side effect unknown, stale build, WPML mismatch, missing provider, unsupported host and absent source.
- ACI-041 Support multi-site quotas/fairness, cancellation, pause/resume, partial failure isolation and restore epoch fencing.
- ACI-042 Certification layers remain separate: static/repository, disposable native, live Staging, real browser, external MCP, performance and release.
- ACI-043 Support user-selected human-only, assisted and preauthorized low-risk automation; all default to deny for unreviewed writes.

## Success measures (targets, not observed)
- Every material claim in an eligible published artifact has an attributable evidence citation or a documented uncertainty approved by the right reviewer.
- Zero unapproved paid provider requests and zero scope/Production elevations caused by content decisions.
- Every known relationship repair passes exact pre/post native readback and reversible/unknown-effects policy.
- Performance: define baseline/targets per site after workload profiling; report job completion p50/p95, cost, human rework, crash recovery and content/SEO outcomes with denominators.
- Never call draft generation, SERP collection or GSC movement a verified business outcome.

## Out of scope for initial releases
Autonomous Production publication, unrestricted crawling, silent WPML remapping, raw SQL writes, generic shell, generated PHP, unrestricted plugin installation, automatic OAuth scope grants, cross-site authority sharing, self-certified external acceptance and whole-site optimization without a signed policy.
