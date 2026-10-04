# Adaptive Search Intelligence — Coverage, Clarity, Quality and Objection Review

Review contract: `mad4b.adaptive-search-intelligence-review.v1`

Scope: Feature 007 Phase 38, `mad4b.adaptive-search-intelligence.v1`.

Status: **APPROVED_P0_CLOSED_P1_OPEN**. The original review score remains the baseline pre-closure assessment; Phase 38 is still OPEN and this does not approve Production activation, provider spend, or content mutation.

## P0 closure update

The 12 P0 review findings are now **CLOSED** at repository/runtime-contract level.

Evidence:
- exact implementation head: `178ec73281a19fd7c78a8e83ff110dd11027b684`;
- Feature 007 Spec Quality CI run: `37236946924`;
- adaptive-search P0 runtime-contract step: **PASS**;
- Spec Kit consistency on the same implementation lineage: PASS.

Implemented closure surfaces:
- ObservationContext / ComparabilityKey and provider-resolved geo/locale fidelity;
- versioned query and owned-URL identity;
- separated organic/group/absolute/provider-native rank semantics;
- complete/partial/not-found-within-depth capture semantics;
- fail-closed SearchEligibilityEnvelope;
- bounded facet/virtual-surface admission;
- ProviderAccountBudgetAuthority with truthful `hard_global` versus `local_best_effort` claims;
- provider usage reconciliation, billing-cycle reset and stale reservation expiry;
- provider evidence-rights/retention constraints;
- deterministic provenance-bound DecisionPolicy;
- measurable acceptance descriptors for all Phase 38 gates;
- composed drift/budget/lease/partial-capture/cache/uncertain-effect fault guard.

Remaining review backlog: **6 P1 findings**. Phase 38 remains a non-authorizing MATURITY_REQUIRED extension and is not globally complete.

## Executive assessment

The Phase 38 design is structurally strong and materially better than a vendor-specific SERP integration. It has clear separation between governance identity, search configuration, runtime discovery, target compilation, provider execution, immutable evidence, inference and governed content change.

The largest remaining risk is no longer basic architecture. It is **measurement semantics and distributed economics**: two SERP observations are not necessarily comparable merely because they share a query, and a WordPress-local budget governor cannot truthfully guarantee account-level quota safety when the same provider credential is shared across multiple sites/workers.

### Scorecard

| Dimension | Score | Assessment |
|---|---:|---|
| Architectural coverage | 92/100 | Broad coverage from discovery through closed-loop optimization. |
| Clarity / conceptual boundaries | 90/100 | Object vs Surface, evidence vs inference, Search Profile vs Site Profile are clear. |
| Provider neutrality | 93/100 | Strong adapter model; still needs explicit engine-specific semantic profiles. |
| Security / governance | 96/100 | Non-authorizing boundary, egress, secrets, circuit breaker and reconciliation are strong. |
| Evidence integrity / provenance | 92/100 | Immutable evidence and provenance are strong; comparability semantics remain underspecified. |
| Scheduling / economics | 84/100 | Fairness and hierarchical budget are good; shared-account distributed budget authority is missing. |
| Search measurement correctness | 78/100 | Location fidelity, rank semantics, partial capture and observation equivalence need explicit contracts. |
| Indexability / surface correctness | 85/100 | Good surface abstraction; crawlability/header/robots/facet-cardinality details need tightening. |
| Testability / objective gates | 82/100 | Many scenarios exist; PASS thresholds are not yet machine-measurable enough. |
| Operator UX / operability | 85/100 | Adaptive experience is strong but needs stability rules and bounded manual controls. |
| Long-term extensibility | 94/100 | New providers/sites/brands can fit without business-specific PHP branches. |
| Overall specification maturity | **88/100** | Strong architecture; implementation should not start broadly until P0 semantic gaps are closed. |

## What is already covered well

### 1. Authority separation — excellent
Search evidence cannot create WordPress mutation, Production or Breakglass authority. The closed loop correctly terminates in a separately governed Content Experience proposal.

### 2. Site Profile versus Search Profile — excellent
Keeping governance identity outside business/search configuration prevents a long-term monolithic Site Profile.

### 3. Object versus Search Surface — excellent
Separating a taxonomy term from its public archive URL is necessary for archives, CPT archives, facets and future non-post surfaces.

### 4. Field-level SEO provenance — strong
The design avoids the false assumption that one plugin owns every effective SEO field.

### 5. Evidence versus inference — excellent
Raw provider payload, normalized fact and derived signal are explicitly different artifacts. This is essential for reprocessing without repurchasing provider requests.

### 6. Provider-neutral execution — strong
Provider capabilities, health, economics and circuit-breaker state are part of routing rather than vendor-specific workflow branches.

### 7. Adaptive scheduling — strong direction
Expected Value of Observation is a better model than scanning every canonical keyword on a fixed cadence.

### 8. Fairness — strong
Language/market starvation is explicitly recognized instead of optimizing only high-volume English targets.

### 9. Degraded operation — strong
Quota exhaustion/provider failure does not incorrectly collapse historical and cached intelligence into a total outage.

### 10. Dynamic experience — strong direction
The experience is generated from capabilities and state instead of a SerpApi-specific admin page.

---

# Findings and objections

## ASIR-001 — P0 — Observation comparability is not explicit enough

### Objection
“Rank #6 yesterday and rank #8 today” is not necessarily a valid decline if location resolution, engine domain, device, provider, result depth or SERP experiment context changed.

### Gap
The current target identity is strong, but there is no normative **ObservationContext / ComparabilityKey** defining when two captures may be compared as one time series.

### Required resolution
Define:
- requested market;
- provider-resolved location identifier;
- country/gl;
- language/hl;
- engine/domain;
- device;
- location precision;
- result depth;
- feature set;
- safe-search/search-mode where material;
- provider normalization version;
- comparability class.

A provider change must not silently create a false ranking movement.

Task: T3861–T3862.

---

## ASIR-002 — P0 — Query canonicalization is underspecified

### Objection
Hashing a “normalized query” is unsafe without defining normalization. Unicode composition, case, punctuation, whitespace and locale-sensitive transformations can cause collisions or duplicate identities.

### Required resolution
Store:
- raw_query;
- normalized_query;
- normalization_version;
- normalization_reason/equivalence class.

Normalization must not erase meaningful accents or operators.

Task: T3863.

---

## ASIR-003 — P0 — Owned URL identity and matching need a canonical algorithm

### Objection
Cannibalization and owned-rank detection can be wrong across http/https, www/non-www, trailing slash, query parameters, redirects, translated URLs and canonical aliases.

### Required resolution
Introduce versioned URL identity with:
- observed_url;
- normalized_url;
- effective_canonical;
- redirect target;
- registrable domain/host ownership;
- locale relation;
- normalization version.

Task: T3864.

---

## ASIR-004 — P0 — Rank semantics are too generic

### Objection
Different providers distinguish organic rank, grouped rank and absolute SERP position differently. Paid, AI, PAA, local and video features can shift absolute positions without changing organic order.

### Required resolution
The normalized result must explicitly distinguish:
- result_type;
- organic_rank;
- group_rank;
- absolute_position;
- provider_native_position;
- feature/container membership.

Cross-provider comparison must use a declared comparable metric.

Task: T3865.

---

## ASIR-005 — P0 — “Not ranked” can be confused with incomplete capture

### Objection
A URL absent from a depth-10 response does not mean it lost ranking. The provider may return partial, truncated or failed results.

### Required resolution
Snapshot needs:
- requested_depth;
- returned_depth;
- completeness state;
- partial/truncated reason;
- target_found;
- not_found_within_depth;
- capture validation state.

A ranking-loss signal must not be generated from incomplete capture.

Task: T3866.

---

## ASIR-006 — P0 — Indexability is still too close to one scalar

### Objection
“Indexable” combines different questions: can a crawler reach it, may it be indexed, is it canonicalized elsewhere, is it discoverable, and is its language relationship valid?

### Required resolution
Use an eligibility envelope:
- crawlable;
- robots_txt_allowed;
- x_robots_header;
- meta_robots;
- indexable;
- canonical_state;
- redirect_state;
- discoverable/sitemap state;
- hreflang validity;
- effective eligibility + confidence.

Task: T3867.

---

## ASIR-007 — P0 — Dynamic/faceted surfaces can explode cardinality

### Objection
“Provider-approved virtual surfaces” can accidentally create millions of combinations from filters/query parameters.

### Required resolution
Add Surface Admission Policy:
- route/pattern allowlist;
- query-parameter normalization;
- finite cardinality estimate/cap;
- pagination limits;
- canonical policy;
- indexability requirement;
- duplicate-surface fingerprinting;
- deny unknown filter combinations.

This is especially important for JetSmartFilters-like sites.

Task: T3868.

---

## ASIR-008 — P0 — Shared provider-account budget is not globally safe yet

### Objection
Two WordPress sites using the same SerpApi/DataForSEO credential can both observe “100 requests remaining” and consume the same allowance concurrently. A local hierarchical governor cannot guarantee zero overspend.

### Required resolution
Introduce **Provider Account Budget Authority** keyed by credential/account identity:
- globally shared usage state;
- reservation/lease;
- fencing epoch;
- account billing cycle;
- hard allowance;
- local allocation;
- provider usage reconciliation;
- stale-reservation expiry.

If no shared authority exists, the runtime must downgrade its claim from “hard global budget enforcement” to “local best effort + provider reconciliation”.

Task: T3869–T3870.

---

## ASIR-009 — P1 — First-party search-performance data is not explicitly composed into the decision loop

### Objection
Polling SERPs for every ranking question can waste paid quota when Search Console or another first-party SearchPerformanceProvider already supplies owned query/page evidence.

### Required resolution
Bind the existing Growth/SearchPerformance concept into Phase 38 as a distinct evidence source. It must not masquerade as live SERP evidence, but it should influence:
- owned visibility;
- opportunity;
- decline detection;
- refresh scheduling;
- provider spend.

Task: T3871.

---

## ASIR-010 — P1 — Site language and search-query language are not the same thing

### Objection
An ES page existing does not mean its correct Spanish search query is a direct translation of the EN focus keyword.

### Required resolution
Add Query Language Provenance:
- source language/query;
- target language;
- translation/transcreation method;
- human/provider/AI source;
- search-market evidence;
- approval/confidence;
- semantic-cluster relation.

Task: T3872.

---

## ASIR-011 — P0 — Provider licensing / permitted retention is missing from evidence retention

### Objection
A provider may permit normalized use but restrict raw response retention, redistribution or duration. A generic retention policy could violate provider terms even if technically safe.

### Required resolution
Provider descriptor must carry evidence-usage constraints:
- raw retention permitted?
- max retention;
- redistribution class;
- derivative/normalized retention;
- storage-region restrictions where applicable;
- deletion obligations.

The stricter provider policy constrains storage.

Task: T3873.

---

## ASIR-012 — P0 — EVO scoring is conceptually good but not deterministic enough yet

### Objection
A formula with business value × information gain × urgency can become opaque or unstable if factor ranges, missing values and tie-breaking are undefined.

### Required resolution
Version:
- factor schema;
- bounded ranges;
- missing-value policy;
- monotonicity requirements;
- normalization;
- tie-break algorithm;
- calibration dataset;
- decision-policy version.

No ML model is required for v1; a deterministic heuristic baseline is preferable.

Task: T3874.

---

## ASIR-013 — P1 — Adaptive UI can become unpredictable

### Objection
Capability-driven navigation can move sections around between visits, harming operator confidence and deep links.

### Required resolution
Define UX stability:
- stable section IDs;
- deterministic order;
- persistent URLs;
- state-transition explanation;
- accessibility requirements;
- role-safe progressive disclosure;
- no hidden blocker due to section removal.

Add bounded operator controls:
- pause/resume profile;
- disable provider;
- freeze spend;
- pin/mute target;
- request refresh;
all audited and non-authorizing.

Task: T3875.

---

## ASIR-014 — P1 — WordPress must not become an accidental infrastructure lock-in

### Objection
The recommended tables and wp-admin experience are valid, but high-scale search execution or raw evidence storage may eventually need an external worker/store.

### Required resolution
Keep domain contracts storage/executor neutral and define supported profiles:
- WordPress-local;
- external worker with WordPress control plane;
- external blob/evidence store.

Changing profile must preserve canonical identities and evidence semantics.

Task: T3876.

---

## ASIR-015 — P0 — Gates are named but some PASS criteria are subjective

### Objection
`ADAPTIVE_SEARCH_GENERALIZATION_PASS` is not objectively auditable unless its fixtures, thresholds and required evidence are explicit.

### Required resolution
For every Phase 38 gate define:
- exact fixtures;
- assertions;
- quantitative threshold;
- denial cases;
- evidence artifact;
- exact-head binding;
- whether CI, disposable runtime or live Staging is required.

Task: T3877.

---

## ASIR-016 — P1 — SERP feature schema must be open to unknown future features

### Objection
Google SERP types evolve faster than plugin release cycles. A closed enum can discard new evidence or break normalization.

### Required resolution
Use:
- known normalized feature family;
- provider-native type;
- unknown/pass-through evidence class;
- normalizer version;
- safe rendering fallback.

Task: T3878.

---

## ASIR-017 — P1 — Decision factors need provenance

### Objection
“Commercial value = 90” or “search volume = 12,000” is not actionable evidence unless the source, age, market and normalization are known.

### Required resolution
Every scoring factor must bind to:
- value;
- source;
- market/language scope;
- observed_at;
- freshness;
- confidence;
- normalization version.

Task: T3879.

---

## ASIR-018 — P0 — A composed adversarial acceptance matrix is still needed

### Objection
Individual contracts can all pass while composition fails: provider changes during lease, quota reset during execution, language removed while job queued, canonical changes after target compile, stale budget reservation, partial SERP response, etc.

### Required resolution
Execute a cross-fault matrix combining:
- profile drift;
- provider drift;
- surface drift;
- language drift;
- budget races;
- lease loss;
- partial captures;
- provider ambiguity;
- cache equivalence;
- reconciliation;
- dynamic UX state.

Task: T3880.

---

# Common objections and disposition

| Objection | Disposition |
|---|---|
| “This is over-engineered for 250 free SerpApi searches.” | **Partly valid.** Architecture may remain rich, but runtime must support a minimal profile where most layers resolve to defaults and only a tiny queue executes. Complexity belongs in the platform, not the operator workflow. |
| “Just call SerpApi directly.” | **Rejected for long-term design.** It creates vendor, quota and schema coupling and fails multi-brand/provider evolution. |
| “WordPress is not a queue/data platform.” | **Valid at scale.** Domain semantics should remain portable; WordPress can be the control plane while workers/storage are profile-driven. |
| “Third-party SERP APIs are not Google truth.” | **Valid.** Evidence must be represented as provider-observed measurement with exact context and confidence, not authoritative Google state. |
| “Provider-neutral is fake because Google uses Google-specific semantics.” | **Valid warning.** Core can be engine-neutral while an explicit Google semantic profile owns Google-specific location/features. Do not claim cross-engine equivalence without conformance. |
| “Dynamic UI will confuse users.” | **Valid unless constrained.** Capability-driven content needs stable IDs/order, predictable states and explanation. |
| “EVO is a black box.” | **Valid unless deterministic.** v1 should be bounded, versioned and explainable with deterministic tie-breaking. |
| “A shared API key breaks local budget guarantees.” | **Fully valid P0 objection.** Requires account-level distributed reservation/reconciliation or a weaker claim. |
| “Archives/facets will create URL explosion.” | **Fully valid P0 objection.** Requires bounded Surface Admission Policy. |
| “Raw SERP storage may violate provider terms.” | **Fully valid P0 objection.** Provider usage/retention policy must constrain storage. |
| “Spanish URL means Spanish keyword is known.” | **Rejected assumption.** Query transcreation/provenance must be independent from page translation. |
| “Rank decline can be inferred from absence.” | **Rejected assumption.** Absence is meaningful only on a validated complete capture within declared depth. |

# Quality conclusion

The P0 semantic blockers ASIR-001 through ASIR-008, ASIR-011, ASIR-012 and ASIR-015/018 are now closed by exact-head executable evidence. Broad Phase 38 work may continue, while the six P1 findings and provider/live-execution maturity remain open.

The strongest parts are:
1. governance separation;
2. provider-neutrality;
3. immutable evidence;
4. dynamic context/surface modeling;
5. non-authorizing closed loop.

The highest-risk remaining areas are:
1. observation comparability;
2. global/shared-account economics;
3. indexability/surface cardinality correctness;
4. rank/capture semantics;
5. provider evidence licensing;
6. objective gate criteria.

## Recommended implementation order

```text
ObservationContext + canonical identities
        ↓
SearchEligibility + SurfaceAdmission
        ↓
Rank / Capture completeness semantics
        ↓
Provider Account Budget Authority
        ↓
Deterministic Decision Policy
        ↓
Provider / Evidence retention policy
        ↓
SearchPerformance evidence composition
        ↓
Adaptive UX stability / operator controls
        ↓
Cross-fault generalization proof
```

With the P0 set closed, the design has moved from **strong architecture specification** to an **implementation-ready Search Intelligence foundation**. This is not a claim that the full Phase 38 runtime, providers, live Staging acceptance, or Production activation is complete.
