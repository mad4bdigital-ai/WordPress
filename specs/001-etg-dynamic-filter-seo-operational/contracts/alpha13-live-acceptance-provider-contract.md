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
- maximum complete dataset IDs: 100;
- maximum Query Builder page fetches per semantic dataset: 20.

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

For JetEngine routes the semantic evaluator resolves the canonical Query Builder binding through `RuntimeQueryBindingResolver`, derives each semantic page from a clone of the resolved query definition, clears clone-local evaluated runtime state, reapplies the governed filtered properties, and reads the authoritative total count/result IDs without mutating the registered Query Builder configuration.

The internal evaluator contract is `etg.dfsb.semantic-query-evaluation.v2`.

When the total is at or below the 100-ID ceiling and Query Builder exposes its canonical pagination contract, the evaluator walks the result pages using `get_items_per_page()` plus `set_filtered_prop('_page', n)`. Every page fetch is reinitialized independently so a manager-owned Query Builder object cannot leak a previously evaluated `final_query`/`current_query` page into semantic acceptance.

The page walk is resource bounded:

- no more than 100 result IDs may be accepted as a complete dataset;
- no more than 20 Query Builder pages may be fetched for one semantic dataset;
- results above either ceiling stay `INCOMPLETE_EVIDENCE` with an explicit reason such as `total_exceeds_id_ceiling` or `page_fetch_ceiling_exceeded`.

The evaluator must not hide pagination defects with post-hoc deduplication. It records raw page evidence and applies these invariants before certifying IDs:

```text
requested page n > 1 must report/behave as page n when the provider exposes page state
page_signature[n] must not equal page_signature[n-1] while unique_count < total
raw_collected_id_count must not exceed provider_total
new_unique_ids must be > 0 while unique_count < total
collection stops successfully when unique_count == total
IDs exposed for parity are ordered unique IDs, while raw_id_count remains separate evidence
```

A violation is a bounded acceptance-infrastructure failure, not a Tours/JetSmartFilters product mismatch. Canonical reasons include:

- `paged_query_items_do_not_advance`;
- `duplicate_page_signature`;
- `raw_collected_id_count_exceeds_total`;
- `pagination_no_progress`.

A complete bounded page walk reports:

```text
ids_complete=true
ids_scope=full_result_set
ids_reason=complete
collection_mode=paged_query_items|single_query_items
page_fetches=<bounded integer>
raw_id_count=<provider total>
unique_id_count=<provider total>
infrastructure_failure=false
```

Dataset identity and ordering are separate dimensions:

- `ids_parity` compares result-set identity independent of order;
- `order_parity` compares provider order.

If the provider runtime cannot expose the complete bounded item identity set, ID/order parity is `INCOMPLETE_EVIDENCE`; it must not be silently promoted to PASS. If the acceptance collector itself cannot advance pagination, ID/order parity is blocked by `TEST_INFRASTRUCTURE_FAILURE` while independently verified count parity remains valid.

## Verdict taxonomy

The provider uses these v1 verdict states:

- `PASS`: all required semantic dimensions are verified;
- `FAIL`: execution completed and semantic divergence was observed;
- `BLOCKED`: a prerequisite or acceptance-infrastructure condition prevents certification;
- `INCOMPLETE_EVIDENCE`: semantic execution is otherwise healthy but one required evidence dimension cannot be completely observed.

Browser absence is always reported separately as `INCOMPLETE_EVIDENCE` and does not convert a semantic PASS into a semantic FAIL.

Classification examples:

- semantic state/count/ID/order divergence -> `PRODUCT_DEFECT`;
- missing profile/runtime/query binding -> `ENVIRONMENT_OR_PROVIDER_BLOCK`;
- semantic dataset pagination collector cannot advance safely -> `TEST_INFRASTRUCTURE_FAILURE`;
- incomplete bounded dataset identities without collector failure -> `OBSERVATION_GAP`;
- verified semantic parity -> `NO_CONFIRMED_DEFECT`.

## Central MAD4B integration

The provider registers callbacks rather than a permanent ETG public MCP Ability:

- `descriptor_callback`;
- `capabilities_callback`;
- `plan_callback`;
- `run_callback`.

MAD4B Acceptance Core may project those callbacks through canonical abilities such as `mad4b/acceptance-plan` and `mad4b/acceptance-run`. ETG must not create a competing transport or approval surface.

`mad4b/live-acceptance-status` remains an aggregate/finalization surface and must not be redefined as the semantic runner.

## Long-term extension seam

Future suites and providers must extend capabilities without permitting caller-controlled arbitrary execution. Browser evidence, external client projection, and governed mutation acceptance remain independent evidence authorities reconciled by MAD4B rather than self-certified by ETG.
