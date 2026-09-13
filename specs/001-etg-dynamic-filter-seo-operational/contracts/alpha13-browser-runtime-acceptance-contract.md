# Alpha13 Browser Runtime Acceptance Contract

Status: Draft candidate contract for `0.4.0-alpha.13`.

## Purpose

This contract closes the observation gap that remains after server-side semantic Live Acceptance has passed. It does **not** move browser execution into WordPress and does not create a second authority plane.

The architecture is deliberately split:

1. WordPress/ETG derives a profile-governed browser plan from an already-PASS semantic plan/run.
2. A real external browser engine executes transient UI actions against that exact plan.
3. The passive ETG browser observer captures browser evidence only.
4. The external authenticated Control Plane returns the evidence to the ETG browser acceptance provider.
5. WordPress reduces the evidence against the canonical semantic IDs/count/order and emits a non-authorizing signed receipt.

```text
WordPress semantic PASS
  -> mad4b.browser-acceptance-plan.v1
  -> external real browser engine
  -> etg.dfsb.browser-acceptance-observer.v1
  -> etg.dfsb.browser-acceptance-evidence.v1
  -> mad4b.browser-acceptance-result.v1
```

## Provider contract

ETG registers:

- central registry filter: `mad4b_browser_acceptance_providers`
- native provider filter: `etg_dfsb_browser_acceptance_provider`
- provider contract: `etg.dfsb.browser-acceptance-provider.v1`

The provider exposes descriptor, capabilities, plan and result callbacks. It does not expose a browser engine, generic HTTP proxy, arbitrary URL input, arbitrary JavaScript input, mutation callback or public evidence-ingest REST route.

The projected central contracts are:

- `mad4b.browser-acceptance-capabilities.v1`
- `mad4b.browser-acceptance-plan.v1`
- `mad4b.browser-acceptance-result.v1`

## Authority boundary

Every browser acceptance surface is:

- `read_only=true`
- `authorizing=false`
- `business_state_mutation=false`
- `profile_mutation=false`
- `seo_mutation=false`
- `production_activation=false`
- `transport_owned_by_provider=false`
- `browser_engine_owned_by_provider=false`

Transient browser filter/reset interaction performed by the external execution agent is observation-only acceptance activity and must not be treated as business-state authority.

## Plan derivation

A browser plan may become `ready` only when the canonical semantic provider returns `PASS` and `semantic_parity_verified=true` for the same governed profile.

The browser plan MUST be derived from the semantic plan/run rather than rebuilding Query Builder semantics in a browser-specific implementation.

Each case therefore carries the canonical semantic expectation:

- case ID
- archive path
- provider
- provider Query ID
- taxonomy
- term ID and slug
- expected authoritative result total
- ordered complete result IDs
- deterministic ID digest

The plan is also bound to:

- exact WordPress origin
- exact embedded ETG `git_sha`
- exact embedded ETG `tree_sha`
- semantic `plan_digest`

Arbitrary caller-supplied route, URL, taxonomy, term, query or JavaScript is forbidden.

## Plan signature

The deterministic browser `plan_digest` is HMAC-bound with the existing WordPress auth salt. No new secret is introduced.

This is a WordPress plan-origin/integrity signature. External-agent authentication remains the responsibility of the authenticated MAD4B Control Plane transport; the ETG provider does not create a parallel authentication system.

## Freshness challenge

A ready browser plan also carries a separate, stateless, server-signed freshness challenge:

`etg.dfsb.browser-acceptance-challenge.v1`

The challenge contains:

- 128-bit random nonce encoded as 32 hexadecimal characters
- `issued_at`
- `expires_at`
- HMAC signature

The canonical maximum lifetime is `900` seconds. The HMAC binds the challenge to:

- provider `etg-dfsb`
- governed profile ID
- browser `plan_digest`
- nonce
- issue time
- expiry time

The freshness challenge is:

- non-authorizing
- stateless
- non-persistent
- not a mutation ticket
- not a browser authentication credential

Observed browser evidence MUST echo the exact challenge and every governed case snapshot MUST echo the same `challenge_nonce`. Missing, malformed, expired, not-yet-valid, signature-invalid or nonce-mismatched challenges fail closed as `TEST_INFRASTRUCTURE_FAILURE` before browser parity can be certified.

This contract provides **bounded freshness / replay-window resistance**, not one-time replay denial. Because the challenge is deliberately stateless, an identical valid evidence envelope can theoretically be replayed during the unexpired challenge window. That is acceptable for this non-authorizing observational receipt. Any future requirement for strict one-time replay denial would require a separate consumed-challenge store or equivalent stateful authority and is outside this contract.

## Browser observer

The exact plugin package includes:

`assets/js/browser-acceptance-observer.js`

Contract:

`etg.dfsb.browser-acceptance-observer.v1`

The observer is passive. It MUST NOT:

- click filters
- choose terms
- navigate the browser
- call `history.pushState()` or `history.replaceState()` to create URL state
- publish SEO metadata
- activate profiles
- mutate WordPress data

The external browser engine arms the observer with one already-governed plan case **and the signed freshness challenge**, then performs the real UI interaction. The observer validates only the bounded nonce shape; cryptographic signature and expiry validation remain server-side.

The observer can record:

- `ajaxFilters/updated`
- `etg-dfsb/ajax-presentation-updated`
- `etg-dfsb/ajax-presentation-reset`
- bounded `/wp-json/etg-dfsb/v1/ajax-presentation` fetch response evidence
- JetSmartFilters filter-group identity
- rendered JetEngine `data-post-id` values
- rendered result-count surface when available
- browser URL before/after/reset
- whether an ETG runtime frame attempted browser history mutation
- canonical
- robots
- hreflang
- document title + description as the rendered Rank Math head projection
- reset-to-neutral observation
- the armed `challenge_nonce`

The observer exposes bounded `arm(planCase, challenge)`, `snapshot()` and `disarm()` methods only. The external browser engine remains responsible for actual UI actions and multi-page DOM walking when a semantic case spans multiple browser pages.

## Required live-browser dimensions

A complete result evaluates independently:

- `browser_ajax_round_trip`
- `browser_event_stream`
- `browser_dom_result_count`
- `browser_dataset_id_parity`
- `browser_order_parity`
- `browser_url_state`
- `browser_seo_non_authority`
- `browser_reset_behavior`

The browser agent must collect the complete ordered result set, including walking browser pagination when necessary. Deduplicating a repeated browser page after the fact is not valid evidence.

## AJAX authority invariants

For every observed presentation response:

```text
contract = etg.dfsb.ajax-presentation.v1
status = ready
authorizing = false
url_authority = false
seo_mutation = false
provider = governed provider
query_id = governed query ID
```

A missing/invalid round trip is a browser execution/infrastructure failure, not automatically a Tours product defect.

## Exact-build evidence envelope

External evidence contract:

`etg.dfsb.browser-acceptance-evidence.v1`

The envelope MUST echo:

- browser `plan_digest`
- WordPress `plan_signature`
- exact origin
- exact build `git_sha`
- exact build `tree_sha`
- observer identity and real browser-engine identity
- exact signed freshness `challenge`
- one evidence entry for every governed case and no unplanned extra case
- the same signed challenge nonce as `challenge_nonce` on every case snapshot

Stale plan, wrong origin, wrong build, missing case, invalid observer identity, invalid plan signature, missing/invalid/expired challenge or challenge nonce mismatch is classified as `TEST_INFRASTRUCTURE_FAILURE` and must fail closed.

## Defect versus infrastructure classification

After a trusted/complete browser execution envelope is established:

- rendered result-count divergence -> `PRODUCT_DEFECT`
- rendered dataset identity divergence -> `PRODUCT_DEFECT`
- rendered ordering divergence -> `PRODUCT_DEFECT`
- ETG browser history authority violation -> `PRODUCT_DEFECT`
- rendered SEO non-authority violation -> `PRODUCT_DEFECT`
- reset divergence -> `PRODUCT_DEFECT`

Before a trusted envelope exists:

- missing browser evidence -> `INCOMPLETE_EVIDENCE / browser_runtime_not_observed`
- stale plan/build/origin -> `BLOCKED / TEST_INFRASTRUCTURE_FAILURE`
- missing/invalid/expired freshness challenge -> `BLOCKED / TEST_INFRASTRUCTURE_FAILURE`
- challenge nonce mismatch -> `BLOCKED / TEST_INFRASTRUCTURE_FAILURE`
- missing event stream -> `BLOCKED / TEST_INFRASTRUCTURE_FAILURE`
- missing/invalid presentation round trip -> `BLOCKED / TEST_INFRASTRUCTURE_FAILURE`

This prevents browser infrastructure failure from being mislabeled as a Tours defect.

## Signed evidence receipt

After reduction, WordPress computes a canonical evidence digest and HMAC-signs a receipt over:

- browser plan digest
- evidence digest
- final verdict

The evidence digest includes the accepted evidence envelope, including the freshness challenge and per-case challenge nonces. The receipt is explicitly `receipt_authorizing=false`. It is evidence integrity/provenance, not an approval, publication or Production activation capability, and it does not claim one-time replay consumption.

## Closure condition

Browser Runtime Acceptance is closed only when the canonical browser result reports:

```text
verdict = PASS
classification = NO_CONFIRMED_DEFECT
semantic_parity_verified = true
browser_runtime_parity_verified = true

browser_ajax_round_trip = PASS
browser_event_stream = PASS
browser_dom_result_count = PASS
browser_dataset_id_parity = PASS
browser_order_parity = PASS
browser_url_state = PASS
browser_seo_non_authority = PASS
browser_reset_behavior = PASS
```

A provider/observer implementation alone does not satisfy this closure condition. A real external browser engine must still execute the exact signed plan on the exact live build and return complete, unexpired freshness-bound evidence.
