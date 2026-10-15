# ACI01 — Project Charter, Product Boundaries and Architecture Freeze Candidate

Contract: `mad4b.aci-os.project-charter.v1`. Status: **DESIGN_REVIEW_REQUIRED** / `SPEC_BACKLOG_ONLY`; no implementation, Staging, publication or Production authority is granted by this document.

## One-sentence product promise

**ACI01 is a multi-tenant, multi-brand and multilingual content decision-and-delivery layer that continuously discovers evidence-backed growth opportunities, proposes and reviews useful content, protects native WordPress/WPML identities, and learns from outcomes, using the existing MAD4B governed execution plane for all real effects.** The system is dynamic by default and never derives authority from model output or evidence.

## Explicit users and owned jobs

| Persona | Owns | Must not own |
|---|---|---|
| Portfolio Owner | business objective, brand/site priority, budgets and final high-risk approvals | hidden global WordPress privileges |
| CMO / SEO Strategist | intent, opportunity policy, topic and channel measurement | publication grants |
| Editor / Author | human-authored text, voice, rights and content review | site connection/Production authority |
| Site Content Steward | CPT/term/media/SEO/WPML field ownership and mappings | unsupported raw SQL or invented translation IDs |
| Operator / SRE | provider health, jobs, incident reconciliation, host prerequisites and restore | self-authorizing changes |
| Governed AI Agent | bounded candidates, evidence collections and low-risk delegated decisions | new grants, arbitrary executor/code/Production |

## System of record and reuse

- **Existing MAD4B Site Control Plane:** tenant/site identities, OAuth scopes, effective permissions, policy, step-up approvals, existing operation journal, budget authority, restore/fencing, Host Connector and release controls. ACI01 does not fork it.
- **Existing WordPress and verified adapters:** posts, CPTs, postmeta, terms/taxonomy, WPML translations, Media Library, SEO settings, front-end renderer. Existing native objects own their data; ACI01 owns only versioned intent/evidence/proposals.
- **ACI01 candidate domain:** Site/Brand profiles, semantic ContentRecipe, DomainFactAuthority, EvidencePack, OpportunityHypothesis, ContentBlueprint, Draft, QualityVerdict, PublishManifest, GrowthObservation and OptimizationProposal. These are logical types that reuse Feature 007 registries where available; no new DB schema is authorized by the Spec Kit.
- **Execution provider:** Bit Flows, n8n or native runner only if exact capability certified. No workflow tool may bypass current MAD4B policy or effect journal.
- **External integrations:** Google Drive/Docs/Sheets, search/SERP/scrape, AI, Search Console, GA4, media and sales conversions remain optional typed adapters; missing provider is a scoped blocker, not a reason to install or enable plugins automatically.

## Product boundaries, no hidden scope

**In:** existing-content inventory and ownership, competitive/search evidence, consent-aware context, writer profile, opportunity scoring, multilingual blueprint, editorial drafting, native relation inspection, independent QA, governed publication proposals/readback, and comparable growth proposals.

**Out until separately certified:** unrestricted crawling, arbitrary external spend, generated PHP, raw database writes, auto-installed plugins, novel OAuth scopes, unsandboxed shell, universal auto-publication, cross-site grant sharing, unverified ID remapping and automatic Production deployment.

**Tourism is a first domain-validation profile, not a core assumption.** Tours, properties, destinations and package terms use dynamically scoped recipes/fact owners. Each field—price, inventory, cancellation, duration, title, local reference—has a separate source of truth and expiry policy. Articles and category listings can omit booking-specific checks if inapplicable.

## Dynamic execution contract

All stages receive `Scope(site, tenant, brand, market, locale, environment, runtime_generation, restore_epoch, provider/account)`, current `CapabilitySnapshot`, `EvidencePack`, and `PolicyRevision`. Pure `CandidatePlan` computation may run spec-only; **only the existing authority layer** can dispatch a real operation after current-scope approval, budget reservation, CAS and downstream readback.

Dynamic decisions determine:
- eligible provider / fallback based on certified capabilities and account-cost/rights;
- whether missing Brand Context blocks drafting only or a more limited read-only path;
- whether a content recipe needs WPML relation verification, commercial inventory, media rights, conversion tracking or only editorial QA;
- which task execution prerequisites apply and which independent certificates apply **to this specific operation**;
- when to stop, retry a pure read, reconcile an uncertain external effect or request human review.

Unknown evidence, ambiguous ownership, unknown semantics and stale runtime are never green. A planner is not an executor and a classifier is not an authority.

## One coherent top-level architecture

```text
Owner / Strategist / Editor / Site Steward / Operator
  -> Action Center (review, cost, effect, evidence, exact approval)
  -> Dynamic Decision Layer (non-authorizing)
      -> Site + Brand + Author Context
      -> Typed Content Recipes + Domain Fact Authority
      -> Provider Capability / Cost / Rights / Evidence
      -> WordPress Native Relations / WPML Translation Inspector
      -> Opportunity -> Blueprint -> Draft -> Independent QA
  -> Durable Job + Artifact DAG (checkpoints / leases / incidents)
  -> Existing MAD4B grant, approval, budget, journal, commit-time CAS
  -> Native WordPress / Media / SEO / WPML adapter (only when approved)
  -> Native + Browser Readback (independent verification)
  -> Comparable Growth Evidence -> New Proposal (not blind rewrite)
```

Five loops: `Discovery`, `Evidence`, `Content`, `Validation`, `Optimization`. The loops are dynamically scheduled, not one inflexible pipeline; no loop bypasses the native commit gate.

## Work graph and acceptance semantics

Execution prerequisites are **task artifact dependencies**. Certification prerequisites are **capability and effect-specific proofs**. A reader-only WordPress inventory does not wait for an AI writer; text-only drafting does not require unrelated WPML write certification; publication cannot avoid a relevant native-relation certificate.

ACI-G0 through ACI-G10 are acceptance families, not ten serial workflows. All 71 tasks start OPEN in a registry with exact requirement IDs, output evidence and negative cases. ACI01 files are optional to the parent Feature 007 frozen release denominator. Passing `validate.py` tests document/data model consistency only.

## First end-to-end vertical slice (acceptance before live effects)

- **Site:** one *disposable* WordPress test site (or a read-only governed Staging source); one real selected language and locale.
- **Content:** one existing article or one eligible tour draft; one ContentRecipe and a reviewed BrandContext/WriterProfile.
- **Evidence:** non-paid, licensed fixture SERP and bounded native Content/Meta/Term/WPML reads; no live external account charges.
- **Flow:** inventory → scoped evidence and opportunity → Blueprint → Draft → independent Fact/SEO/Rights/Relation QA → operator Review Ticket.
- **Result:** reproducible immutable artifact DAG and reasons for each blocked stage, with zero WordPress mutation and no Production credentials.
- **Negative must-pass:** missing writer context, unknown mapper/local translation, human edit conflict, source prompt injection, revoked/stale grant, changed restore epoch and forged dynamic capability never generate write permission.

Native write Staging certification is a distinct later milestone requiring an exact current authority handshake, provider-specific tests, real native/browser readback and fresh owner consent.

## Acceptance definition of clarity (a hard STOP gate)

1. Product purpose, personas, first vertical slice and exclusions are unambiguous.
2. Every requirement maps to individual implementable tasks and vice versa; all acceptance-family dependencies are conditional and acyclic.
3. Site, locale, content type, field, provider and cost variation is covered with explicit *policy-based* defaults and fail-closed unknowns.
4. Existing vs new vs external responsibilities are named; no duplicate authority plane.
5. Every write path is dominated by existing governed commit controls.
6. Content recipes and DomainFactAuthority Profiles cover articles, destinations, tour products, taxonomy listings, landing pages and comparisons.
7. Acceptance and adversarial use cases have exact expected states; fake capability certificates are visibly simulation-only.
8. All current machine registries, Markdown, manifest, validator, tests and CI bindings agree on one version.
9. Actual `validate.py`, `test_validate.py`, `test_dynamic_core.py` runner results are observed from an exact source copy. Tests merely added but not run do **not** pass.
10. Hub #258 change-slice map and source are frozen by exact-head compare before integrating changes; external provider/Staging/browser/release remain independently pending.

If any is missing, mark `DESIGN_REVIEW_REQUIRED`. Do not change ACI task states or move to runtime G1–G10.

## Design questions that remain review items, not implementation guesses

- The live field owner/WPML Meta ID Mapper behavior and which translated targets have proven business equivalence.
- The actual Feature 007 job/artifact/journal interfaces reused for ACI01, including schema compatibility and migrations.
- Per-site production-safe latency, spend, evidence freshness and provider account quota SLOs.
- Eligible browser provider and current Staging/Host/Skills/authority certificates.
- Legal source/media rights and external Search Console/analytics access and consent per site.
- Human-visible disposition of `EXTERNAL_EFFECT_UNKNOWN` and release/rollback ownership.

Nothing here represents a current deployable or self-authorizing runtime.
