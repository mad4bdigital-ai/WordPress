# MAD4B Public CSS closed-loop readback (source-only)

The existing Template Discovery, CSS source-family registry and CSS
certification engine remain authoritative. This is a **read-only evidence
aggregator**, not a new certificate engine, not a scheduler and not a new
FastCGI cache controller. It is deliberately separate from the live
WordPress 421/422/429 runtime.

## Observations captured 20 November 2026

| Metric | Tours.dev staging | Wayfatours production |
| --- | ---: | ---: |
| Registry routes | 1,022 | 239 |
| Source template families | 53 | 21 |
| Fully qualified template families | 0 | 0 |
| Current-context route certificates within registry | 0 | 0 |
| Stored public V1 route certificates | 42 | 44 |
| Stored public V2 route certificates | 0 | 0 |
| CSS Epoch observed | 152 | 252 |
| Old-epoch V1 certs | 42 | 44 |
| Runtime SHA mismatch V1 certs | 10 | 0 |
| Missing original LiteSpeed CSS files referenced by V1 | 21 | 0 |

All V1 certificate critical CSS asset files were found. The 42/44 old
CSS Epoch results prohibit their reuse. Source runtime/asset mismatches
are overlapping reasons, not additional invalid route counts. This audit
does NOT count the independently certified homepage variants as public
*route* certificates.

Source-family states: Tours.dev 51 `source_canary_pending` and 2
`source_native_optimizer_market_partial`; Wayfa 20 pending and one
`source_variant_mapping_pending`. The existing source-family policy
requires independent five-viewport canaries and correct WPML/WCML market
coverage. Native/no-bundle source evidence does not grant a route asset.

### Anonymous public mobile HTML readback

Four URLs per site were probed with a standard mobile Chrome UA,
without cookies, authentication or WordPress mutations. Results are
individual cache snapshots, NOT PageSpeed or LCP samples:

| Site + page | Origin cache | CSS markup | Source links |
| --- | --- | --- | ---: |
| Tours.dev homepage | HIT | Critical CSS homepage v1 | 4 |
| Tours.dev Dendera/Abydos tour | HIT | None verified | 158 |
| Tours.dev Alexandria destination | MISS | None verified | 2 |
| Tours.dev tour archive | HIT | None verified | 3 |
| Wayfatours homepage | HIT | Critical CSS homepage v1 | 3 |
| Wayfatours GEM tour | HIT | None verified | 5 |
| Wayfatours Alexandria destination | MISS | None verified | 2 |
| Wayfatours tour archive | HIT | None verified | 2 |

Tours.dev returned `private, no-store` browser headers for its
sampled cached public HTML. Wayfatours returned public cache headers
for the sampled anonymous routes. Do not equate FastCGI origin HIT with
public browser cache eligibility; do not equate any HIT with CSS
certification. Other headers/cookies/markets can yield different keys
or a BYPASS. CSS and JS deployment are independent gates.

## What the tool verifies

- Exact per-site live 422 SHA and CSS Epoch against the source-family
  registry. The older `current-site.json` snapshot from 17 November
  must NOT replace the refreshed family source state.
- Every V1 certificate's stored CSS Epoch and runtime, the existence
  of its original combined stylesheet and its immutable critical
  asset, and the SHA-256 of both payloads.
- The states of all template families, qualified canary count, and
  presence of job receipts. A missing receipt is **not proof** that a
  scheduled task does not exist.
- The final HTTP-hop cache status, private/no-store, the actual HTML
  CSS tags, and the corresponding readiness header for homepage V1,
  public-route V1 and public-route V2.
- It never asserts that HTML markup alone passes site identity,
  Elementor document graph, language, currency, country, cache
  freshness, Chrome visual tests, or route-specific certificate
  admission.

Its public report marks
`browser_html_ready_from_cache_state_alone: false`,
`template_family_reuse_authorized_by_this_report: false`, and
`fastcgi_promotion_authorized_by_this_report: false` by contract.

## Controlled release dependencies

1. **CSS Epoch:** trace real option writes, classify content/template
   changes against the CSS dependency graph and prevent unrelated
   mutations from invalidating all routes. Do not simply freeze the
   Epoch number, suppress an authentic invalidation, or reuse V1
   certificates from Epoch 129/130 or 39/178/231 at 152/252.
2. **Multi-CSS:** staged v2 issuance/422 consumption in PR #971,
   including five viewport real-origin CSS QA, original cascade and
   private market/currency isolation. Tours.dev Arabic sample has an
   independently observed 390px LCP regression and must stay HOLD.
   The old single-bundle original 422 cannot certify its 158-source
   tour. No fabricated LiteSpeed combined file is allowed.
3. **Canary reuse:** qualify actual independent exemplars for each
   template variant, language, country and WCML currency. Evidence
   reuse is not CSS asset reuse across distinct routes.
4. **Site-owned schedule:** independently read the live WordPress
   `mad4b_css_wpcron_site_profile_v1` option on 20 November. Tours.dev
   enrolls `public`, `first_fold`, `families`, `visual`, `audit`,
   `retention`, while **Wayfatours explicitly enrolls only `public`
   and `first_fold`**. This confirms a real schedule coverage gap,
   not merely missing job receipts. To expand it, enroll the new
   company-owned tasks with bounded intervals and resource admission;
   do not infer task authorization from the PHP allowlist alone.
   Both public workers recently deferred on source/browser admission.
   Keep CPU/Chromium locks, resource limits, independent company
   job receipts and no silent success statuses.
5. **FastCGI:** HTML may be cacheable without being CSS-optimized.
   Label CCSS readiness only from source-, delivery- and
   context-verified proof; ensure a cached native fallback is never
   relabeled optimized. Do not remove country/IP/UA cache variation
   until a privacy, market, price and public-shell parity review.
6. **Transport prerequisite:** Fluent Form 6 JS self-mutation loop
   fix was merged in PR #976, but deployment to Wayfatours was
   still blocked by the company's code-release allowlist as of the
   review. Re-run normal JS/Chrome and LCP proof only after an
   authorized single-asset live release, not a patched browser proxy.
7. **PR #957:** previous permanent mobile cache source remains Draft
   with a Release Verdict exact-head owner attestation hold. Reconcile
   its 10 source files against the CURRENT master and approved
   PR dependencies before seeking fresh owner attestation.

## Repeatable read-only audit

```bash
python3 ops/css-closedloop-readback-v1/closedloop_audit.py \
  --site wayfa \
  --wp-content /path/to/wayfa/site/wp-content \
  --family-registry /path/to/private/wayfa/source-families-latest.json \
  --cron-receipts /path/to/private/wpcron-css-v1 \
  --cron-profile /path/to/company-owned/site-profile.json \
  --samples-manifest /path/to/private/wayfa-samples.json
```

The optional sample manifest contains private local HTML and response
header file paths `{"name","headers","html"}`. The tool never
calls WordPress, modifies a site, downloads a public page, signs a
certificate, or changes an option/cache key. The HTML capture step is
separate and optional.

Live audit evidence with restricted directory permissions:
`/opt/mad4b-company-provisioning/reports/ccss-closedloop-readback-20261120/`.

Unit tests:

```bash
python3 ops/css-closedloop-readback-v1/tests/test_closedloop_audit.py
```
