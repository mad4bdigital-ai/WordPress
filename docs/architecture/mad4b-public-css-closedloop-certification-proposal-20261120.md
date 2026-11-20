# MAD4B public CSS certification: stable identity, scoped validity, delivery proof

**Status:** Proposed for technical review (not approved for Production)
**Reviewed:** 2026-11-20
**Affects:** existing 421 discovery, 422 delivery, 429 WP-Cron, FastCGI, Elementor, JetEngine, WPML/WCML.
**Related work:** #957 (FastCGI/mobile), #971 (ordered Multi-CSS v2), #981 (read-only evidence), #984 (site-owned Cron v17), merged #976 (Form 6 observer fix).

## Context and confirmed observations

The existing system already discovers template families, schedules workers, captures
browser evidence and independently certifies homepages. Do not create another
certificate engine, schema, broker, route registry, or WordPress UI plugin.

- Tours.dev: **1,022** discovered registry routes, **53** discovered template
  family variants, **0** fully qualified; CSS Epoch **152**. Wayfatours:
  **239** discovered routes, most recent **23** family variants, **0**
  fully qualified; CSS Epoch **252**. Family counts changed 21→24→23
  during live source sampling. Dynamic variant discovery may change
  grouping without an Elementor template change.
- Stored V1 route certs: **42** Tours.dev (41 at Epoch 129; one at 130)
  and **44** Wayfatours (34 at Epoch 39, nine at 231, one at 178).
  All are older than current Epoch, thus invalid as current route proof.
  Tours.dev has **21** original LiteSpeed CSS assets absent from both
  active and original asset-retention paths, and **10** certs with
  mismatched original 422 runtime SHA. Causes overlap.
- Tours.dev currently advertises currency scope EGP/EUR/GBP/USD and
  market `dynamic_not_enumerated`. The family registry's
  `full_market_coverage = currency_coverage_complete and not geo_dynamic`
  intentionally cannot report *globally qualified* while country
  scope is dynamic. This must not hide context-specific progress
  (but **no currently valid context canaries were observed**).
- The real `public-source-families.family_key` includes
  `css_epoch`, `global_generation`, `listing_generation` and
  runtime SHA. We verified with the current WP-attested catalog that
  changing any of the first three alone changes the family key,
  even when template source/profile and Elementor graph are unchanged.
  The current key is an **eligibility generation key**, not a durable
  family identity.
- Wayfatours Adaptive Asset Intelligence still calls the older
  `save_post` handler and bumps global CSS Epoch for saved public
  content, including `units` and `properties`. The source staging
  code instead uses `wp_after_insert_post`, an atomic queue and
  `MAD4B_Cache_Lifecycle_V1::home_impact_preview`. This is source/
  production version drift, **not** justification to copy the whole
  source plugin to production or disable invalidations.
- A read-only WordPress option shadow test on Wayfatours shows the
  existing graph can skip *content-only* changes for selected Units/
  Properties while preserving purge for a directly displayed tour and
  for all membership/taxonomy changes. The production option is
  **unset/unchanged**, and Epoch remained 252. A negative graph must
  remain fail-closed unless a bounded company-specific policy exists.
- Both sample homepages deliver native Critical CSS, but sampled
  tours/archives can return FastCGI **HIT** with no route Critical CSS.
  A homepage certificate is not a route certificate. FastCGI HIT
  is not proof of markup quality or market/private-content isolation.
- The ordered v2 QA path exists but its gate
  `MAD4B_MULTI_CSS_SOURCE_QA` defaults to off; separate explicit
  publisher flags and native 422 consumer opt-in are also off.
  Thus simply merging source cannot auto-certify routes.
- Wayfatours Form 6 fix is merged in Git (#976) but production still
  has the older JS asset SHA. The governed company code-release
  allowlist blocks this file until it receives a scoped release.
  Unmodified Chrome must be measured *after* authorized deployment.
- Site-owned Cron #984 scopes families/visual/audit to one enrolled
  company and rejects other-site success receipts; Wayfatours's
  original WordPress and Host profiles still only enroll public and
  first_fold. Do not enable an old two-site renderer on Wayfatours.

## Decision: a single six-stage evidence flow, with three independent keys

Retain the original data stores, certificate signer, 421/422/429 and
existing signed company release. Version and extend their *contracts*:

1. **FamilyIdentity**: a durable display/discovery identity over enrolled
   site UUID, source profile, route type, source Elementor template
   graph/topology and effective responsive layout family. Never include
   CSS Epoch, route/content generation, worker run time or global
   listing-generation counters. A *genuinely changed* template graph
   deliberately creates a new family. A missing/ambiguous graph is
   provisional and cannot authorize shared-canary reuse.
2. **ContextKey**: locale, device/breakpoint, market and currency;
   country/nationality and cookie-derived display states are treated
   separately wherever they can change markup, pricing widget layout,
   assets or rendered critical viewport. Avoid enumerating all global
   countries when real requested contexts can be proven separately.
   A context proof never silently becomes global proof.
3. **DependencyRevision**: ordered CSS URL/content + inline-source
   digests, Elementor document graph, runtime and QA verifier ABI,
   route revision, global theme/plugin CSS generation, relevant
   listing/query generation, and *market-dependent DOM shape*.
   A change invalidates only the certificates whose dependency graph
   uses it. Unknown dependency topology fails closed. Keep the old
   global Epoch as a compatibility and full-source-ABI gate until a
   separately versioned 422 consumer and migration are approved.
   Do **not** drop the current Epoch check before the replacement
   authority has equivalent source-change enforcement.

Do **not** copy a route's Critical CSS asset to a sibling because its
FamilyIdentity matches. Reuse *source behavior/QA sampling evidence*
only where verified context and shape match. Every published route
still needs current exact source hashes, route binding and delivery
readback; dynamic pricing and private user data are never cached as a
shared public HTML fragment.

### Six strictly ordered gates

| Gate | Evidence | What it does NOT prove |
| --- | --- | --- |
| DISCOVERED | WP-attested route + source/template identity | CSS valid |
| SOURCE_VALID | ordered CSS+inline manifest, revisions, ABIs, context | performance |
| QA_VALID | paired browser Chrome baseline vs candidate, five widths, interactions | deployed |
| CERT_VALID | atomic signed company-specific certificate, QA freshness | served from cache |
| DELIVERED | final anonymous HTML contains verified critical/rest tags and current cert | cache safe for other visitors |
| CACHE_SAFE | HIT/MISS/WARM + cookie/market/currency and private/public isolation | price correctness of unrelated workflows |

Only a route with **all required gates**, for that exact context, may
display `Optimized & Delivered`. Partial gates are not failures if the
route safely serves its native CSS, but must not be counted as optimized.
Separate the always-on safe native fallback from a true certified success.

## QA policy and operational scope

- Run one *vertical slice* end to end on a representative Wayfatours
  tour after Form 6 is correctly deployed, then extend to one
  Archive/Destination and source locale/currency variants. Use
  320/390/414/767/768px browser pairs and explicit DOM interactive
  checks. Source Arabic 390px LCP regression remains its own HOLD.
- Existing v2 thresholds (mobile FCP gain ≥150ms and LCP regression
  ≤max(400ms, 10% native LCP)) are **minimum technical admission**
  thresholds, not a business-approved field-performance SLA. Require
  repeat paired runs/variance bounds and real-user monitoring when
  practical; never infer CrUX INP from one local QA run.
- Preserve existing CPU/Chromium locks and no-store/private treatment;
  v17 enables one-site family, visual and audit after its own signed
  host-profile and WordPress 429 enrollment. Do not enable the
  public publisher or 422 consumer as an incidental side effect of
  installing a QA-only renderer.
- After atomic certificate issuance and 422 admission, evict/rebuild
  only the exact safe public HTML cache key(s), check real HIT
  readback and ensure a prior unoptimized HIT isn't labeled READY.
  Never purge user session or merge geo-/pricing-specific HTML keys.
- Prioritize QA by *member count × observed traffic × commercial value
  × performance opportunity × feasibility*, not FIFO across all
  1,261 routes. Start with at most one QA worker per company, a
  shared global browser budget and independent lightweight source
  fingerprint inspection for other routes.
- The old global release/governance rules stay active. Neither a
  Draft PR, a one-time owner attestation for an ancestor SHA, nor
  successful source-only tests authorize production publication.

## WordPress administration: use the existing Performance workspace

Enhance the existing MAD4B Control Plane/Performance experience rather
than create a new plugin screen. Proposed UI order:

1. **Coverage overview:** certified contexts / contexts observed,
   fully qualified families, actual optimized HTML readbacks,
   native-fallback routes, stale certificates, safe HIT/MISS split.
   Never display “0 globally qualified” as the sole adoption metric.
2. **Family table:** stable FamilyIdentity label, template and source,
   WPML language, country/market, WCML currency, member routes,
   last source change, status, failure category, pending canary,
   next allowed retry. Filters are server-side/AJAX; no raw SHA
   copy/paste required in normal administration.
3. **Readiness drawer:** six gate states with the concrete failing
   condition, graph and CSS source digests (read-only advanced panel),
   event/owner receipt, browser metric pairs and exact HTML cache proof.
4. **Operations:** safe “Recheck source”, “Queue one QA canary”,
   “Inspect cache HTML” and “Rollback to native” only through existing
   site-owned task/authority and capability checks. A status badge
   never doubles as a mutating button, and no UI can bypass release
   signatures. Expensive work remains scheduled, not in an AJAX
   request.
5. **Company policy:** separate `QA-only`, `Cert publish` and
   `Public delivery` approval states, with inheritance and override
   per company/template/context. Changes require audit logs, clear
   dry-run impact and rollback. Owner gates remain hard.

## Alternative approaches rejected

- **Finish the global Epoch cleanup before first QA:** blocks valuable
  early evidence on one route, with no assurance the CSS can be
  delivered. Run source-policy remediation in parallel but keep
  compatibility checks on.
- **Turn on all Wayfatours Cron tasks now:** old worker operates on
  two sites and would multiply browser work; use immutable v17 and
  signed company-specific enrollment first.
- **Use source-family canary as shared CSS asset:** unsafe when pages
  have different inline CSS, responsive DOM, WCML pricing widgets or
  Elementor document variants.
- **Normalize all FastCGI keys to reduce MISS:** dangerous without
  independent cookie/currency/country/private-shell parity.
- **Treat cached HIT, green GitHub CI or old certificate count as
  delivered optimization:** all are different pieces of evidence,
  none substitutes for final HTML and exact context checks.
- **Ship an entirely new scheduler or certification engine:** creates
  split ownership, extra data migrations and difficult rollback.

## Checkpoints / Definition of Done

- **CP0 — baseline:** immutable backup, route+market+cookie inventory,
  source SHA and live JS/422/HTML readback; external pricing/checkout
  untouched.
- **CP1 — scoped releases:** Form6 single-asset deployment through the
  authorized company code release; exact source and rollback
  preimage, clean Chrome and normal/incognito interactions. Separately
  test v17 site-only renderer with two-site isolation and original
  WordPress+Host profiles.
- **CP2 — one canary:** dry-run Multi-CSS source+ordered inline
  manifest, QA-only flags, five-viewports performance, zero interaction
  regressions; no production publishing yet.
- **CP3 — certified delivery:** exact native 422+companion ABI,
  current QA, signed certificate, one scoped cache refresh, final
  HTML HIT+MISS+cookie/market readback; restore native CSS on any
  mismatch.
- **CP4 — family growth:** stable identity, explicit per-context
  canaries, independent route source proof and progressive coverage.
  Require no reclassification of an unchanged source family
  from a numeric Epoch-only increment.
- **CP5 — readiness parity:** visual/interaction audit, isolated
  multi-company Cron, daily health monitoring and an operator
  dashboard with distinct evidence counts and a rollback action.

**Rollback:** Disable public v2 consumer flag, serve native CSS without
removing safe FastCGI, revert exactly the company-scoped JS/renderer
assets under approved preimage and owner policy, and retain read-only
evidence. Never restore a stale V1 cert to simulate coverage.
