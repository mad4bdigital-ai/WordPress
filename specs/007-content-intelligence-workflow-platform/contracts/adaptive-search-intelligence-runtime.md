# Adaptive Search Intelligence Runtime Contract

Contract: `mad4b.adaptive-search-intelligence.v1`

## Purpose

Define the normative provider-neutral runtime boundary for Search Intelligence introduced by Feature 007 Phase 38.

## Mandatory invariants

1. Search Intelligence is non-authorizing.
2. Site Profile remains governance identity; Search Profile owns search/business configuration.
3. Business/provider/site names are data, not kernel branches.
4. Owned tracking requires a real eligible owned search surface.
5. Discovery may exist without an owned URL.
6. Content Object and Search Surface are separate identities.
7. SEO provenance is field-level and conflict preserving.
8. Query identity includes market/language/engine/device/purpose dimensions.
9. Provider routing is capability/economic/policy based and does not grant authority.
10. Provider text is untrusted evidence.
11. Raw evidence, normalized facts and inference are distinct.
12. SERP evidence is immutable.
13. Derived projections are recomputable.
14. Ambiguous external effects require reconciliation before retry.
15. Budget admission is fail-closed and cannot overspend configured hard limits.
16. Fair scheduling prevents permanent starvation across configured dimensions.
17. Experience composition is descriptor/state driven.
18. Search signals cannot directly mutate content.
19. Content changes reuse Dynamic Content Experience plan/apply/verify.
20. Production authorization remains separate.

## Required canonical contracts

- `mad4b.search-runtime-fact.v1`
- `mad4b.search-intelligence-profile.v1`
- `mad4b.effective-search-context.v1`
- `mad4b.indexable-search-surface.v1`
- `mad4b.search-target.v1`
- `mad4b.search-decision.v1`
- `mad4b.search-batch.v1`
- `mad4b.serp-provider-descriptor.v1`
- `mad4b.serp-request.v1`
- `mad4b.serp-snapshot.v1`
- `mad4b.search-signal.v1`
- `mad4b.search-experience-model.v1`

## Provider adapter contract

Each execution adapter MUST expose:
- stable provider ID/family;
- exact certification generation;
- capability descriptor;
- health;
- usage/quota state when available;
- economic estimate;
- request translation;
- bounded execution;
- response validation;
- normalization;
- failure classification;
- reconciliation capability where supported.

Adapters MUST NOT expose secrets or generic arbitrary URL execution.

## Decision record

Every scheduling/provider decision MUST be reconstructable from:
- target identity;
- profile/policy revisions;
- relevant runtime fact generations;
- evidence freshness;
- budget state;
- fairness/aging state;
- provider candidates;
- selected provider or no-op/cache reuse;
- factor/reason chain.

## Evidence contract

SERP evidence MUST bind:
- request fingerprint;
- provider ID;
- provider certification generation;
- runtime build identity;
- capture time;
- raw digest;
- normalization version/digest;
- cost/usage receipt where available.

## Acceptance matrix

Required scenarios:
- single language/simple site;
- multilingual partial-owned coverage;
- public CPT + taxonomy term/archive;
- post-type archive;
- SEO provider conflict;
- never-checked target;
- stale but reusable evidence;
- primary quota low;
- primary provider OPEN;
- HALF_OPEN health probe;
- second provider selection;
- ambiguous quota-consuming request;
- language disabled after historical evidence;
- new translation published;
- content fingerprint change;
- SEO fingerprint change;
- ranking loss;
- cannibalization across page/archive;
- new competitor candidate;
- AI/PAA/feature observation;
- budget reserve preservation;
- fairness starvation prevention;
- profile revision drift;
- provider capability drift;
- dynamic UI section addition/removal;
- signal-to-content proposal without direct mutation.

## Completion

The contract is complete only with executable conformance/fault tests and exact-head evidence. Static documentation is insufficient.
