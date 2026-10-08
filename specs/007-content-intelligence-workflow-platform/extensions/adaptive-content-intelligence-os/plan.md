# Implementation Plan — ACI01 vertical slices and target operating model

Contract: `mad4b.aci-os.implementation-plan.v1`. All future work starts OPEN; phase names are local to ACI01 and do NOT change Feature 007's frozen release denominator. Implement as child branches integrating into #258 after reviewed exact-head checks; no direct merge to master from an unaccepted slice.

## G0 — Spec integrity and ownership
Source: this package. Review platform reuse, contract conflicts, immutable boundaries, parent/CE01 crosswalk, task traceability, exact-head ownership, doc/validator acceptance. Exit: fail-closed spec validator + repo code review, no release claim.

## G1 — Source and business context
Discover site native models, content, taxonomy, ownership, locale/market; classify Brand Strategy, Tone and Editorial Guideline gaps; bounded ContextPack and WriterProfile registry. Exit: least-privilege provider preflight and explicit missing-context tickets.

## G2 — Search, evidence and provider economics
Provider-neutral SERP/Scrape/keyword/GSC/GA4 contract, credential/account boundaries and budget reservation; collection, dedupe, comparison, freshness, rights. Exit: fixtures including injection, overlapping quota, partial paid effect, unavailable provider.

## G3 — Opportunity and competitive intelligence
Build grounded topic/intent/region cluster graph, competitor question/entity map, information-gain and commercial opportunity scoring without self-certified outcome. Exit: source-cited and comparable opportunity decisions plus denied conflicting/insufficient evidence.

## G4 — Native relation and multilingual identity
Bounded WordPress read, post/term namespaces, WPML group/field translation policy, Meta ID Mapper ownership probe, semantic identity resolver and uncertainty. Exit: exact disposable WPML plus one read-only Staging family; no auto-mutation; explicit coverage/readback.

## G5 — Blueprint and human writer system
Context/WriterProfile versioning, outline, section evidence, internal link intent, translation nuances, media brief. Exit: approved immutable Blueprint with rights and local semantic relations, human revisions preserved.

## G6 — Candidate writing and independent QA
Bounded AI provider draft, factual/editorial/SEO/rights/locale/relation evaluators, citation per claim and independent review queues. Exit: adversarial supported/unsupported claims, source injection and cross-locale hallucination cannot advance.

## G7 — Durable workflow, journal and operator UX
Wire stage DAG, operation CAS, provider effects, budget ledger, retries and uncertain-effects recovery; Arabic/RTL Action Center. Exit: paused/replay/restored/disconnected/revoked scenarios with no double spend or hidden writes.

## G8 — Governed WordPress preview/publication
Freeze PublishManifest; Staging-only exact grants, owner approval, draft preview, native Content/Media/SEO/WPML typed adapter apply, CAS, rollback+postcondition. Exit: real disposable and Staging native readback, browser/render/SEO multilingual parity. Production still requires separate promotion.

## G9 — Comparable postpublish optimization
Collect GSC/GA4/index/commercial conversions under comparability keys, rights/consent and backpressure, propose incremental improvement with causal-claim safeguards. Exit: matched/control fixtures and refusal of mismatched window/currency/attribution.

## G10 — Operational hardening and release eligibility
Threat model, model/adapter version skew, Staging QA, provider accounts, restore/reconcile, host isolation, OAuth exact write grants, full browser journey, performance baseline and canary rollback. Exit: hard gate matrix in acceptance.md; owner release/promotion approval independent.

## Delivery strategy

- Isolate source and responsibilities: core artifacts and decision engine first, native WPML read-only second, paid providers later, publishing last.
- Each child PR owns a disjoint list of paths, declares exact base/head, gate/output, fixtures and rollback/revert boundary. Do not bypass stalled CI by claiming CI success; an offline matrix records its limitations.
- Do not implement generic new engines already present in Feature 007; reuse registry/operation journal/WorkflowProvider/Context Authority/Translation Bridge first.
- Reconcile concurrent #258 and #303 changes with exact-head proof before applying their implementation claims.
- First vertical slice: one locale/topic, no-paid-calls fixture, one typed native relations family, a reviewed blueprint and draft. No publish or Production.
- Add provider and browser acceptance only after staging site identity, candidate binding and Skills are current. Developer host isolation is a separate external prerequisite; never downgrade to unsandboxed execution.
