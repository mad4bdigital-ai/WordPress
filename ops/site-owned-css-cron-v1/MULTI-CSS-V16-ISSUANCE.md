# Multi-CSS V2 issuance and 422 consumption — v16 release candidate

The original MAD4B 421/422 WordPress runtime retains the request identity,
cookie/mobile policy, site authority and delivery hooks. A new first-party
companion MU file, 422-mad4b-public-critical-css-v2-consumer.php, is called
only from the original 422 transformer after the original trusted context
is established. No parallel public WordPress hook was introduced.

## Certificate format and runtime contract

- Contract: mad4b.public-critical-css.route-certificate.v2
- Private evidence and staged outputs come from the original route worker.
- Public certificate path after separately governed publishing:
  wp-content/mad4b-public-critical-css-v1/certs-v2/{sha256(host|lang|route|market|currency)}.json
- Critical CSS assets remain content-addressed in the original
  wp-content/mad4b-public-critical-css-v1/assets/{sha256}.css directory.
- V2 requires exact 422 runtime and V2 companion release SHAs, source
  manifesto canonical SHA, every ordered original file SHA/size/path,
  per-slot CSS SHA/size, trusted WPML/WCML dimensions, current CSS epoch,
  Elementor document graph, global/route/listing generation and strict
  five-viewport real-original-CSS-URL QA with an FCP gain threshold.
- 422 never trusts a new market from a visitor header, cookie or URL.
  Any stale or partially verified source falls back to the independently
  certified V1 route, or otherwise unmodified native HTML/CSS.
- The original head CSS link positions and inline styles remain ordered.
  Each full stylesheet retains its original media after loading. Noscript
  full-CSS fallbacks are preserved.
- The 422 post-transform readback labels READY only after verifying the
  expected V2 style and link tags. Late HTML corruption restores the
  original full synchronous CSS HTML.

## Controlled issuance sequence

1. Site-owned worker selects a WordPress catalog route under its resource
   and browser locks. Source-only manifest observation remains private.
2. Opt-in MAD4B_MULTI_CSS_SOURCE_QA=1 captures each ordered CSS source and
   runs CSS/DOM parity and five-viewport FCP/LCP/CLS/interaction QA against
   original public CSS URLs.
3. A separate source-owner admission validates the complete QA evidence.
4. Publisher dryrun is attempted only after fresh WP catalog/generation,
   immutable 422/companion release and WCML dimensions agree.
5. An explicit *two-part* release gate is required for actual certificate
   publishing: MAD4B_MULTI_CSS_V2_PUBLISH=1 and
   MAD4B_MULTI_CSS_V2_RELEASE_APPROVED=1, plus exact live PHP binaries.
   This is NOT an owner authorization substitute; normal production
   approval policies still apply. No public certificate is produced
   by source QA alone.
6. Publishing creates immutable CSS files first and a separate v2
   certificate last, with hash readback. It does not change FastCGI or
   automatically label a route READY in the queue.
7. WordPress administrator enables mad4b_perf_multi_css_v2=on only after
   the live site is on the exact approved runtime pair, the original v1
   routes are reconciled, and independent Chrome/HTML MISS/HIT readback
   succeeds. Default is OFF.

## Rollback and source freshness

- v14 (119 pinned files) and v15 (129 pinned files) are both rebuildable
  unchanged. V16 contains 131 pinned renderer files.
- v1 certs bind to the full 422 PHP file SHA. Changing 422 during a live
  rollout intentionally invalidates previously issued v1 certificates.
  Do NOT install v2 onto either production or staging without a coordinated
  v1 recertification and cache-vary review.
- On 20 November 2026, Wayfatours Dolphin House has a successful sampled
  five-viewport real-original-CSS-URL performance test, but the older
  captured browser report is not fully signed to the new v2 certificate
  contract. Fresh v2 evidence is required to issue a certificate.
- Tours.dev Arabic tour achieved source CSS compilation but at 390px
  LCP regressed by 4984ms. That route is on HOLD, regardless of FCP gain.
- The code was validated in an isolated Git worktree and PHP 8.5 CLI;
  no site WordPress files, live options, FastCGI entries, public
  certificates or asset directories were mutated in this change.
