# Adaptive Search Intelligence Fabric — Feature 007 Phase 38

Status: normative maturity extension, non-authorizing, implementation OPEN.

Contract family: `mad4b.adaptive-search-intelligence.v1`.

This phase extends Feature 007 with a provider-neutral, profile-driven Search Intelligence runtime. It does **not** add Production authority, Breakglass authority, generic outbound HTTP, arbitrary shell, arbitrary SQL, or automatic content mutation. It composes existing Capability Fabric primitives and keeps Search observation, inference, recommendation, content planning and mutation as separate governed states.

## 1. North-star model

The runtime MUST answer, from live evidence:

1. what site, brand, market and language context currently exists;
2. which public/searchable surfaces actually exist;
3. which SEO fields and indexability facts are effective, with field-level provenance;
4. which search targets are worth observing now;
5. what evidence is fresh enough to reuse;
6. which provider can execute the observation safely and economically;
7. what was learned from the observation;
8. which recommendation, if any, should be handed to a separately governed content-change path.

The runtime MUST NOT encode business-specific branches such as Egypt, travel, tours, US, Spanish, WPML, Rank Math or SerpApi into kernel behavior. Those are runtime facts, profile values or adapter capabilities.

## 2. Architectural layers

```text
Capability Fabric Kernel
  identity / authorization / exact-plan / durable execution / evidence
  provider certification / circuit breaker / abuse budget / audit / replay
            |
            v
Runtime Discovery Fabric
            |
            v
Effective Search Context Compiler
            |
            +--> Market-Language Matrix
            +--> Indexable Surface Graph
            +--> SEO Observation Graph
            +--> Provider Capability/Economic Graph
            |
            v
Search Target Compiler
            |
            v
Adaptive Decision Engine
            |
            v
Fair Scheduler + Hierarchical Budget Governor
            |
            v
Provider Router / Execution Mesh
            |
            v
Normalized Immutable SERP Evidence
            |
            v
Temporal Intelligence + Signals
            |
            v
Adaptive Experience Resolver
            |
            +--> recommendations only
            |
            v
Dynamic Content Experience Profiles
 plan -> approval -> apply -> verify -> post-change observation
```

## 3. Kernel versus runtime data

The kernel owns only invariants:
- canonical identity;
- policy/authority separation;
- plan/apply drift protection;
- durable execution;
- leases/fencing/idempotency;
- reconciliation before retry after uncertain external effects;
- evidence integrity;
- provider certification;
- circuit breaker state;
- egress policy;
- secrets/redaction;
- audit.

Runtime data owns:
- brands;
- markets;
- languages;
- search engines;
- devices;
- indexable surfaces;
- SEO providers;
- SERP providers;
- quotas/prices;
- target keywords;
- refresh policies;
- business value;
- experiments.

A runtime profile MAY specialize behavior but MUST NOT widen security or authority.

## 4. Runtime Discovery Fabric

Discovery MUST be evidence-producing, bounded and non-authorizing.

Canonical fact:

```yaml
contract: mad4b.search-runtime-fact.v1
fact_id:
fact_type:
value:
source:
source_generation:
confidence:
observed_at:
expires_at:
fingerprint:
authorizing: false
```

Discovery families:
- WordPress/runtime topology;
- public post types;
- taxonomies and archive behavior;
- front page/posts page;
- multilingual providers and live languages;
- SEO metadata providers;
- rendered frontend/indexability evidence;
- SERP providers;
- provider usage/quota/economic state;
- cache/evidence freshness;
- Search Intelligence profile state.

Installed does not imply active. Active does not imply certified. Certified does not imply eligible. Eligible does not imply selected. Selected does not imply authorized mutation.

## 5. Effective Search Context Compiler

Configuration MUST be composable overlays, not one monolithic object.

Precedence for business/search specialization:

```text
request-safe override
> experiment policy
> surface overlay
> language overlay
> market overlay
> brand profile
> search profile
> system defaults
```

Security/governance constraints remain outside this precedence and can only constrain the result.

Compiler input:
- site identity;
- search profile;
- brand overlay;
- market overlay;
- language state;
- surface state;
- objective;
- provider capabilities;
- provider economics;
- existing evidence;
- live policy constraints.

Compiler output:

```yaml
contract: mad4b.effective-search-context.v1
context_id:
profile_revision:
resolved_markets: []
resolved_languages: []
surface_policy:
provider_policy:
freshness_policy:
priority_policy:
budget_policy:
constraints: []
reason_chain: []
fingerprint:
```

Every material decision MUST include a reason chain and exact input-generation fingerprints.

## 6. Search Profile

Search Profile is separate from Site Profile. Site Profile remains governance/site identity authority.

```yaml
contract: mad4b.search-intelligence-profile.v1
profile_id:
site_uuid:
brand_id:
enabled: true
revision:
markets: []
language_policy:
surface_policy:
provider_policy:
budget_policy:
refresh_policy:
priority_policy:
experiment_policy:
profile_sha256:
```

The profile MUST use plan/apply/verify with expected revision and plan SHA. Search Profile mutation cannot grant WordPress mutation authority.

## 7. Market and language resolution

Target markets are Search Profile authority.

Languages are resolved from live Language Provider Registry plus profile policy. A desired language MUST NOT be treated as an owned-content language unless a live owned surface exists for that language.

Modes:
- discovery may exist without an owned URL;
- owned tracking requires a live indexable owned surface;
- partial translation state is explicit;
- disabled or removed languages suspend future owned tracking without deleting historical evidence.

Adapters may include WPML, Polylang and WordPress locale. Provider name is not part of core logic.

## 8. Object versus Search Surface

The runtime MUST distinguish content objects from search surfaces.

Examples:
- a taxonomy term is an object;
- the public term archive is a search surface;
- a CPT object is an object;
- a CPT archive is a separate search surface;
- filtered/virtual pages may be provider-approved search surfaces without being WordPress posts.

Canonical surface types:
- CONTENT_OBJECT;
- TERM;
- TERM_ARCHIVE;
- POST_TYPE_ARCHIVE;
- HOME;
- BLOG_INDEX;
- PAGINATED_ARCHIVE;
- VIRTUAL_LANDING_SURFACE;
- EXTERNAL_COMPETITOR.

## 9. Indexable Surface Graph

Canonical contract:

```yaml
contract: mad4b.indexable-search-surface.v1
surface_key:
object_ref:
surface_type:
site_uuid:
language:
locale:
public_url:
canonical_url:
http_state:
indexability:
indexability_confidence:
title:
content_fingerprint:
seo_fingerprint:
surface_fingerprint:
parent_context: []
taxonomy_context: []
provider_observations: []
captured_at:
```

Effective indexability MUST resolve:
- object status;
- public visibility;
- site indexing policy;
- SEO robots;
- canonical target;
- redirect state;
- language/translation state;
- rendered HTTP status;
- rendered robots/canonical evidence;
- provider conflicts.

Conflicts MUST produce `conflicting` or `requires_reconciliation`, never a silent winner.

## 10. Field-level SEO observation

SEO metadata MUST preserve field-level provenance.

```yaml
seo:
  title:
    value:
    source:
    observed_at:
  canonical:
    value:
    source:
  robots:
    value:
    source:
  hreflang:
    value: []
    source:
  focus_keywords:
    value: []
    source:
```

SEO providers may include Rank Math, Yoast, AIOSEO, SEOPress, WordPress core and rendered HTML. Provider observations can disagree; disagreement is evidence.

## 11. Search Knowledge Graph

The logical model relates:
- Site;
- Brand;
- Market;
- Language;
- Object;
- Search Surface;
- Query;
- Intent;
- Cluster;
- Competitor;
- Domain;
- URL;
- SERP Snapshot;
- SERP Feature;
- Signal;
- Recommendation;
- Content Change;
- Experiment.

The implementation MAY use relational tables/materialized projections; graph semantics are normative even if a graph database is not used.

## 12. Search Target

A Search Target is not merely a keyword.

```text
Target =
Query
x Market
x Language
x Engine
x Device
x Purpose
x optional Owned Surface
```

Purposes:
- OWNED_RANK_TRACKING;
- MARKET_DISCOVERY;
- COMPETITOR_DISCOVERY;
- CONTENT_GAP;
- CANNIBALIZATION_PROBE;
- SERP_FEATURE_PROBE;
- LANGUAGE_GAP;
- ARCHIVE_OPPORTUNITY;
- POST_CHANGE_VALIDATION;
- EXPERIMENT_MEASUREMENT.

Canonical identity MUST hash normalized query + market + language + engine + device + purpose identity. Owned URL is a relation, not the query identity.

## 13. Target Compiler

Pipeline:

```text
facts
-> candidate generation
-> eligibility
-> deduplication
-> semantic grouping
-> purpose classification
-> value estimation
-> target creation/update
```

New targets MAY be inferred from:
- SEO targets;
- keyword registry;
- newly published surfaces;
- market/language profiles;
- competitor recurrence;
- cluster gaps;
- ranking-loss investigation;
- post-change validation.

Target creation is not a provider request.

## 14. Temporal model

Search Intelligence MUST retain time semantics.

Every mutable observation class SHOULD support:
- observed_at;
- valid_from;
- valid_until;
- superseded_by;
- evidence age;
- source generation.

Rank is interpreted as a trajectory, not only a current scalar. Derived views MAY calculate velocity, volatility and stability without mutating historical evidence.

## 15. Immutable evidence and projections

Use:
- immutable evidence/events;
- recomputable materialized current views.

Example events:
- SEARCH_PROFILE_CHANGED;
- SURFACE_DISCOVERED;
- SURFACE_INDEXABILITY_CHANGED;
- SEO_OBSERVATION_CHANGED;
- SEARCH_TARGET_COMPILED;
- PROVIDER_BUDGET_CHANGED;
- SERP_CAPTURED;
- RANK_LOST;
- RANK_RECOVERED;
- CONTENT_PUBLISHED;
- LANGUAGE_STATE_CHANGED;
- SIGNAL_DERIVED.

Materialized views are disposable/recomputable. Evidence is authoritative for what was observed.

## 16. Adaptive Decision Engine

Scheduling SHOULD optimize Expected Value of Observation rather than static keyword priority.

Conceptual model:

```text
EVO =
business_value
* information_gain
* urgency
* actionability
* change_probability
* confidence_need
/ expected_cost
```

Modifiers:
- never_checked;
- staleness;
- ranking loss;
- commercial intent;
- new-market launch;
- content/SEO fingerprint change;
- volatility;
- aging/fairness;
- recent equivalent evidence;
- duplicate information penalty;
- provider scarcity.

Weights MUST be profile data, not hardcoded business logic.

Every decision MUST emit an explainable DecisionRecord.

## 17. Dynamic refresh

No universal refresh interval is allowed.

Next observation MAY depend on:
- current rank band;
- volatility;
- business value;
- time since content/SEO change;
- evidence confidence;
- historical stability;
- available budget;
- market/language launch state;
- provider economics.

A stable low-value target can age slowly. A recently changed or sharply declining target can receive an accelerated observation window.

## 18. Fair Scheduler

Fairness dimensions:
- site;
- brand;
- market;
- language;
- intent/purpose;
- cluster;
- surface class;
- provider.

A high-volume language MUST NOT permanently starve a smaller language. Aging and minimum coverage guarantees SHOULD be available.

The scheduler MUST remain non-authorizing.

## 19. Hierarchical Budget Governor

Budget model:

```text
Global
-> Site
-> Brand
-> Market
-> Language
-> Purpose
```

Supported constraints:
- monthly request units;
- daily request units;
- monetary budget;
- concurrency;
- provider rate limits;
- result-depth cost;
- reserve percentage;
- reset time.

Allocations SHOULD be soft reservations with safe reclaim, not permanently stranded hard buckets.

Budget overspend MUST fail closed.

## 20. Provider capability/economic contract

SERP adapters MUST expose a common descriptor:

```yaml
provider_id:
family: serp
capabilities:
  engines: []
  devices: []
  geo_precision: []
  max_depth:
  features: []
usage:
  mode:
  remaining:
  reset_at:
economics:
  estimated_cost_model:
health:
certification_generation:
```

Provider selection is a policy-constrained decision using capability fit, health, quota pressure, expected cost, latency and freshness need.

Primary/fallback ordering MAY be a policy hint but MUST NOT be the only routing model.

## 21. Provider execution mesh

Execution providers and evidence sources are distinct.

Execution providers:
- SerpApi;
- DataForSEO;
- future certified SERP providers.

Evidence sources:
- historical snapshots;
- imported evidence;
- HYPD or equivalent external intelligence;
- manual review evidence.

Manual evidence MUST NOT impersonate a live provider execution receipt.

## 22. Provider circuit breaker and uncertain effects

Search MUST reuse Capability Fabric circuit-breaker and reconciliation semantics.

- OPEN -> provider ineligible;
- HALF_OPEN -> bounded non-mutating probe;
- CLOSED -> normal consideration.

A request that may have consumed external quota but whose response is ambiguous MUST NOT be blindly retried. Reconciliation/usage evidence precedes retry where supported.

## 23. SERP request contract

```yaml
contract: mad4b.serp-request.v1
query_id:
query:
market:
language:
engine:
device:
depth:
purpose:
freshness:
requested_features: []
target_ref:
context_fingerprint:
```

Provider-specific query parameters remain inside adapters.

## 24. SERP snapshot

```yaml
contract: mad4b.serp-snapshot.v1
snapshot_id:
query_id:
provider:
provider_request_id:
market:
language:
device:
engine:
captured_at:
organic_results: []
features: {}
raw_response_sha256:
normalized_sha256:
cost:
provenance:
  provider_generation:
  runtime_build:
  request_fingerprint:
```

Snapshots are immutable. Normalization is versioned.

## 25. Raw versus normalized versus inferred

The runtime MUST preserve:
1. raw provider evidence;
2. normalized facts;
3. derived inference.

Example:
- raw: provider response;
- normalized: owned URL rank = 7;
- inference: ranking deterioration.

Inference can be recomputed without repurchasing the provider request.

## 26. External-content trust boundary

Provider text is untrusted data.

SERP titles/snippets/PAA/AI Overview content MUST NOT:
- become tool instructions;
- alter policy;
- expose credentials;
- grant authority;
- alter approval decisions;
- inject executable commands.

Schema validation, size bounds, structural sanitization and provenance are required before intelligence processing.

## 27. Confidence model

Signals SHOULD include:
- confidence;
- evidence count;
- evidence age;
- source diversity;
- contradictions;
- derivation version.

Low-confidence signals MUST remain distinguishable from confirmed facts.

## 28. Intelligence signals

Minimum signal families:
- ranking gain/loss;
- volatility;
- new competitor;
- competitor persistence;
- cannibalization;
- content gap;
- market gap;
- language gap;
- archive opportunity;
- SERP feature opportunity;
- post-change validation;
- stale evidence;
- provider disagreement;
- SEO metadata conflict.

Signals are recommendations/observations, not mutation authority.

## 29. Cannibalization

Cannibalization analysis MUST support content pages, CPTs, term archives and post-type archives in one model. It MUST preserve the exact observed query/market/language/device and candidate URLs.

## 30. Closed-loop content feedback

Allowed chain:

```text
SERP evidence
-> signal
-> recommendation
-> content change proposal
-> Content Experience plan
-> approval
-> apply
-> verify
-> post-change search observation
```

Search Intelligence MUST NOT directly edit/publish content from a ranking signal.

## 31. Experiment measurement

A content/SEO change MAY define pre/post observation windows.

Experiment output MUST distinguish correlation from causality and record confounders, including SERP volatility and competitor changes.

## 32. Adaptive Experience Resolver

The wp-admin Search Intelligence experience MUST be capability/state driven.

Canonical ExperienceModel:

```yaml
contract: mad4b.search-experience-model.v1
state:
headline:
metrics: []
blockers: []
opportunities: []
recommended_actions: []
sections: []
reason_chain: []
```

Allowed render primitives:
- STATUS_CARD;
- METRIC;
- TABLE;
- MATRIX;
- GRAPH;
- TIMELINE;
- DIAGNOSTIC;
- PLAN_REVIEW;
- ACTION.

Provider names MUST NOT require provider-specific UI branches.

## 33. Experience states

Minimum runtime states:
- UNCONFIGURED;
- DISCOVERING;
- PROFILE_DRAFTED;
- READY;
- BASELINING;
- ACTIVE;
- DEGRADED_PROVIDER;
- DEGRADED_BUDGET;
- RECONCILIATION_REQUIRED;
- PROFILE_DRIFT;
- EVIDENCE_STALE.

Degraded live capture MUST NOT be represented as total system failure when historical/cached intelligence remains valid.

## 34. Capability-driven navigation

Base: Overview + Diagnostics.

Conditional sections MAY include:
- Markets;
- Languages;
- Surfaces;
- Targets;
- SERP;
- Competitors;
- Archives;
- Experiments;
- Budgets;
- Providers.

Navigation is derived from registered capabilities and live state.

## 35. Semantic Work Operation Registry

The current remote-work queue MUST evolve from a fixed operation allowlist toward registered semantic operation definitions.

A search operation descriptor SHOULD include:

```yaml
operation_id: search.serp.capture
executor_class: provider_adapter
provider_family: serp
authority_surface: read_external
side_effect_class: external_cost
retry_semantics: reconciliation_first
idempotency: semantic_digest
budget_class: serp_search
production_policy: read_only_if_eligible
```

Registration never creates authority.

## 36. Search job state model

```text
CANDIDATE
-> ADMITTED
-> SCHEDULED
-> LEASED
-> PROVIDER_PREPARED
-> PROVIDER_ENTERED
-> PROVIDER_RETURNED
-> VALIDATED
-> NORMALIZED
-> EVIDENCE_COMMITTED
-> SIGNALS_DERIVED
-> COMPLETE
```

Uncertain provider effect:
```text
PROVIDER_ENTERED
-> UNKNOWN_EXTERNAL_EFFECT
-> RECONCILIATION_REQUIRED
```

No blind retry.

## 37. Batch as execution cohort

A batch is a reproducible selection decision, not an array of strings.

It MUST record:
- objective;
- selection policy;
- target candidates;
- selected targets;
- excluded targets;
- budget envelope;
- provider eligibility;
- decision reasons;
- exact profile/policy fingerprints.

## 38. Explainability

Every scheduling/provider decision MUST be explainable.

DecisionRecord SHOULD show:
- effective priority;
- positive/negative factors;
- budget impact;
- alternatives considered;
- provider selection reason;
- freshness reuse decision;
- fairness/aging contribution.

## 39. Storage model

Recommended additive tables:
- mad4b_search_profiles;
- mad4b_search_surfaces;
- mad4b_search_targets;
- mad4b_search_jobs;
- mad4b_search_observations;
- mad4b_search_events;
- mad4b_search_provider_usage;
- mad4b_search_signals.

Provider-specific tables are discouraged unless required for opaque raw payload storage.

Large raw evidence MAY use governed blob/object storage; DB rows retain locator + digest + normalized evidence reference.

## 40. Retention

Retention policies MUST distinguish:
- normalized ranking facts;
- raw provider payloads;
- diagnostic payloads;
- derived aggregates;
- audit/evidence.

Historical evidence required for reproducibility MUST not be silently rewritten by retention jobs.

## 41. Secrets and egress

Credentials are referenced by opaque secret handles only.

Secrets MUST NOT appear in:
- MCP responses;
- REST output;
- queue payloads;
- audit payloads;
- diagnostic errors;
- raw evidence visible to ordinary read clients.

Provider egress MUST be registered/certified, TLS-bound, host/path constrained, response-size bounded and timeout bounded. No generic URL-fetch ability is introduced.

## 42. Observability

Four views:
- runtime: queue depth, latency, reconciliation, failures;
- provider: success rate, 429s, latency, quota/cost, breaker;
- intelligence: coverage, freshness, volatility, gaps/signals;
- business/search: high-value coverage, commercial rank coverage, market/language opportunity.

Suggested SLO examples:
- no budget overspend;
- no secret exposure;
- all ambiguous external effects reconcile before retry;
- new indexable surfaces discovered within policy window;
- critical commercial targets meet freshness policy.

## 43. Drift classes

Minimum:
- PROFILE_DRIFT;
- LANGUAGE_DRIFT;
- SURFACE_DRIFT;
- SEO_PROVIDER_DRIFT;
- PROVIDER_CAPABILITY_DRIFT;
- BUDGET_DRIFT;
- SCHEMA_DRIFT.

Drift invalidates only dependent projections/jobs where possible. Historical evidence remains.

## 44. Generalization acceptance

The same runtime MUST support, without PHP business branching:
- a simple single-language site;
- a multilingual archive-heavy travel site;
- a large 4,000+ target site;
- partial translation coverage;
- provider quota scarcity;
- provider outage;
- newly added provider;
- newly added SEO adapter;
- newly added public CPT/taxonomy;
- market launch;
- post-change validation.

## 45. Non-authorizing boundary

Phase 38:
- MAY observe;
- MAY score;
- MAY queue read-only external observations;
- MAY derive signals;
- MAY recommend;
- MAY create a governed content-change proposal.

It MUST NOT:
- silently publish;
- automatically rewrite content;
- create Production authority;
- create generic outbound HTTP;
- bypass Content Experience plan/apply;
- bypass provider certification/budget/circuit-breaker policy.

## 46. Definition of Done

Phase 38 is complete only when:
1. the meta-model and contracts exist;
2. Search Profile plan/apply/verify exists;
3. live language and surface discovery are provider-neutral;
4. Terms + Term Archives + Post Type Archives are first-class;
5. field-level SEO provenance is implemented;
6. target compilation supports owned + discovery modes;
7. fair scheduling + hierarchical budget governor are durable;
8. at least SerpApi and one second adapter conform to the same provider contract, or the second adapter is proven by a conformance fixture;
9. immutable normalized SERP evidence is stored;
10. ambiguous external effects reconcile before retry;
11. dynamic wp-admin experience is capability/state driven;
12. search signals hand off to Content Experience only through governed proposal/plan;
13. cross-market/language/provider/budget/drift fault fixtures pass;
14. no business/vendor hardcode is required to add a new profile/provider/site;
15. exact-head CI and evidence link the implementation.

No documentation-only completion is allowed.
