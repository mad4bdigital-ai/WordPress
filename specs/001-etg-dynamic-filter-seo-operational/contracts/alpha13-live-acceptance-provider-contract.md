# Alpha 13 Live Acceptance Provider Contract

## Purpose

ETG exposes a bounded, profile-governed semantic acceptance provider that a central MAD4B acceptance orchestrator can discover and invoke. ETG owns domain evaluation only. ETG does not own MCP transport, approval, mutation authority, browser automation, Ready-for-review, merge, or Production activation.

Canonical provider contract:

`etg.dfsb.live-acceptance-provider.v1`

Canonical central discovery hook:

`mad4b_live_acceptance_providers`

Native ETG discovery hook:

`etg_dfsb_live_acceptance_provider`

## Authority boundary

Every descriptor, plan, and run is:

- read-only;
- non-authorizing;
- non-destructive;
- non-persistent;
- profile-non-mutating;
- SEO-non-mutating;
- Production-activation-non-authorizing.

The provider must never accept arbitrary URLs, arbitrary provider query arguments, arbitrary taxonomies, generic HTTP requests, SQL, PHP callbacks, or browser JavaScript as public acceptance inputs.

The initial request surface is restricted to:

```json
{
  "profile_id": "tours",
  "suite": "semantic"
}
```

All route, provider, query, archive, taxonomy, and term identities are derived from the registered ETG Profile Registry and live WordPress term inventory.

## Semantic versus browser evidence

The provider may certify only server-side semantic evidence. It must report the distinction explicitly:

```text
semantic_parity_verified=true|false
browser_runtime_parity_verified=false
browser_runtime=INCOMPLETE_EVIDENCE
```

PHP must never claim that it observed JavaScript events, a browser AJAX round trip, DOM reconciliation, or browser event ordering.

Browser evidence is a future independent receipt authority and is not emulated through loopback HTTP or a generic POST executor.

## Planning

`plan()` is deterministic and bounded.

It resolves:

1. one registered `profile_id`;
2. registered profile routes only;
3. taxonomies present in both `taxonomy_rules` and `allowed_taxonomy_sets`;
4. real non-empty WordPress terms ordered deterministically;
5. bounded route/case counts.

Limits in v1:

- maximum routes: 4;
- maximum cases: 8;
- maximum taxonomies considered: 8;
- maximum candidate terms per taxonomy: 5;
- maximum returned dataset IDs: 100.

The plan emits a deterministic `plan_digest` over the governed profile/case selection.

## Canonical state

Direct and AJAX representations must normalize into the same canonical semantic state dimensions:

- `profile_id`;
- `provider`;
- `query_id`;
- `archive_path`;
- normalized taxonomy/term filter values.

Transport-only authority differences are preserved but excluded from semantic-state equality. In particular, direct evidence may retain URL authority while AJAX must remain `url_authority=false` and `authorizing=false`.

## Query evaluation

For JetEngine routes the semantic evaluator resolves the canonical Query Builder binding through `RuntimeQueryBindingResolver`, clones the resolved query object, applies bounded filtered properties, and reads:

- authoritative total count;
- bounded result item identities when the provider runtime exposes `get_items()`.

The evaluator never writes Query Builder configuration and never persists filtered state.

Dataset identity and ordering are separate dimensions:

- `ids_parity` compares result-set identity independent of order;
- `order_parity` compares provider order.

If the total result set exceeds the bounded ID ceiling, or the provider does not expose a complete item identity set, ID parity is `INCOMPLETE_EVIDENCE`; it must not be silently promoted to PASS.

## Verdict taxonomy

The provider uses these v1 verdict states:

- `PASS`: all required semantic dimensions are verified;
- `FAIL`: execution completed and semantic divergence was observed;
- `BLOCKED`: a prerequisite such as profile, route, scope, runtime, or provider query binding is unavailable;
- `INCOMPLETE_EVIDENCE`: semantic execution is otherwise healthy but one required evidence dimension cannot be completely observed.

Browser absence is always reported separately as `INCOMPLETE_EVIDENCE` and does not convert a semantic PASS into a semantic FAIL.

Classification examples:

- semantic state/count/ID/order divergence -> `PRODUCT_DEFECT`;
- missing profile/runtime/query binding -> `ENVIRONMENT_OR_PROVIDER_BLOCK`;
- incomplete bounded dataset identities -> `OBSERVATION_GAP`;
- verified semantic parity -> `NO_CONFIRMED_DEFECT`.

## Central MAD4B integration

The provider registers callbacks rather than a permanent ETG public MCP Ability:

- `descriptor_callback`;
- `capabilities_callback`;
- `plan_callback`;
- `run_callback`.

A later MAD4B Acceptance Core may project those callbacks through canonical abilities such as `mad4b/acceptance-plan` and `mad4b/acceptance-run`. ETG must not create a competing transport or approval surface.

`mad4b/live-acceptance-status` remains an aggregate/finalization surface and must not be redefined as the semantic runner.

## Long-term extension seam

Future suites and providers must extend capabilities without permitting caller-controlled arbitrary execution. Browser evidence, external client projection, and governed mutation acceptance remain independent evidence authorities reconciled by MAD4B rather than self-certified by ETG.
