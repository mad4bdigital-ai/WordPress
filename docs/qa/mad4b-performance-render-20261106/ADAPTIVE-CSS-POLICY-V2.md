# MAD4B adaptive CSS intelligence v2 — implementation and acceptance (2026-11-06)

Site used for real QA: `tours.dev.mad4b.com` **Staging**. `Wayfatours` and all Production sites were left unchanged. This work extends the existing MAD4B Performance Intelligence and Predictive CSS orchestration, not a new optimizer, certificate, broker or approval layer.

## Current native cycle

1. **Discover** the current company's site origin, `wp_get_environment_type()`, WPML active languages, page route, Nginx-injected device, Elementor document graph and CSS epoch.
2. **Generate** local CCSS / UCSS alternatives in the existing CSS plan; preserve native full CSS. A CSSOM unused-selector candidate is QA-only until dynamic states are verified.
3. **Measure** same-origin native/candidate A/B using browser Navigation Timing `responseStart` for per-sample TTFB, Paint Timing FCP, LCP, CLS, network, JS error and interaction observations. Never substitute the Google 28-day CrUX values or a single Lighthouse run for the browser A/B evidence.
4. **Evaluate** in `MAD4B_Company_Performance_Intelligence::css_evidence_policy_v2()`. The policy compares post-TTFB FCP and LCP differences at nearest-rank p75; protects against even a single >150ms LCP regression, CLS >0.02, missing visual/interactive parity, absent cold/warm cohorts, stale evidence or source drift.
5. **Reconcile** a bounded, computed `mad4b_perf_css_policy_receipt_v2`. Its refresh is triggered by native WordPress option updates for CSS evidence/runtime or `mad4b/performance/css_plan_updated`; it contains **no keys, signatures, approval ID, visitor PII or certificates**.
   The same refresh is wired to native `mad4b_css_ai_epoch_v1` changes and new `mad4b_adaptive_presentation_scan_needed_v1` discovery requests so active evidence is withdrawn on source drift without a page reload.
6. **Deliver** using existing `415-mad4b-critical-css-orchestrator.php`: requires site-local manifest release, WordPress runtime opt-in, route/locale/device gate, exact source stylesheet SHA, critical CSS SHA and Elementor graph, plus the short-lived data-only decision receipt. Otherwise return unmodified native CSS. The mobile QA probe remains staging-only.
7. **Invalidate/rollback**: when an approved variant disappears at a policy refresh, purge only its tenant-local URL through the native LiteSpeed hook. A request whose policy is expired, whose content changes, or whose source SHA differs falls back to native CSS. HTML caches still need targeted purge on policy revocation.

## Measured current decision (mobile home EN)

| Field | Value | Why it matters |
|---|---:|---|
| Matched paired browser samples | 2 | Below acceptance minimum 6 |
| Post-TTFB FCP delta, p75 | -2,528ms | Candidate starts painting earlier |
| Post-TTFB LCP delta, p75 | +412ms | Candidate is slower for actual largest element |
| LCP regressed pairs | 2/2 | Reject public rollout |
| CSS variants tied to exact manifest | 2 | No cross-bundle fallback |
| Current evidence source | `staging_chromium_ab` | Device-specific local lab; **not CrUX** |
| Runtime decision | `native_hold` | 0 approved public variants |
| Missing tested locale plans | FR, DE, ES, IT, AR | Default to native, no assumed English parity |

Observed conditional request tests: ordinary mobile QA enables local CCSS/Async, desktop and French QA do not; WooCommerce cart cookie or `X-MAD4B-Currency` bypasses it. Public homepage stays native. Network request output verified HTTP 200 and PHP lint passed.

## Acceptance gates — device/route/CSS version independent

- `mad4b.css-evidence-record.v2`, matching company host and environment, route, WPML locale, device, SHA256 source CSS, Elementor document fingerprint, and CSS epoch.
- Evidence captured within 72 hours of assessment; limited to 24 paired samples per record and 192 collection entries.
- At least **six pairs** with **three cold and three warm**, at least **two measured viewports**, native/candidate AB/BA controls, same document and CSS source, no JS errors, visual and interaction `pass` for every pair.
- p75 of the candidate's **post-TTFB LCP delta must not increase**; no single pair may worsen LCP by more than 150ms; CLS delta must not exceed 0.02; p75 post-TTFB FCP must improve.
- UCSS candidate additionally requires fully validated dynamic content states including modal/popup, mobile menu, WPML locale, Swiper, JetEngine/JetSmartFilters and booking/pricing contexts. A DOM-only candidate **cannot** pass without such tests.
- Current, nontruncated CSS content discovery; current published graph and CSS epoch. Evidence alone cannot change the runtime flag, local manifest, or CSS gate.
- The release receipt is valid for one hour and includes an exact route/locale/device/CSS/critical-style/Elementor/epoch fingerprint. It is just computed state; no certificate or owner approval introduced.

## Tests and integration

- Executed 17 source-site WP-CLI tests in `test-css-adaptive-policy-v2.php`: live LCP hold, correct two-pair p75, missing five locale plans, synthetic six-pair acceptance, cross-tenant, CSS source drift, stale evidence, single LCP regression, JS error, CLS, missing visual parity, small sample, dynamic UCSS guard, malformed timings, stale discovery and disabled runtime.
- Integrated read-only Adaptive CSS decision table into `MAD4B Control Plane → Performance`; added missing WPML route-plan finding to the existing Company Performance scan.
- Corrected the previous advisory `predictive_css_decisions()` to prefer normalized v2 pairs over older raw FCP/LCP values. The old UI must not contradict the new measured decision.
- Disabled Public Production QA probe; an untrusted currency/geo/auth or unsafe cookie context cannot receive the pilot.

## Known remaining work

- The first-visible destination card remains the mobile LCP element. Its dual image-preload conflict has been corrected in `310`; CSS Async still lags native on measured LCP, and a photo/slider paint sequence analysis is needed before enabling public Async.
- Current conservative UCSS gives small gains (~11KB Gzip) while aggressive CSSOM pruning can save ~135KB Gzip **but is not proven safe** under all dynamic states. These artifacts are never used as a public CSS replacement.
- Five WPML locales lack their own exact template, CSS, CLS, interaction and LCP evidence. Device and locale plans must be discovered automatically, but published only after version-pinned tests.
- Cold/warm, two-viewport, and interactive coverage requirements are not yet satisfied. Do not promote, claim a CWV Passed rating, or deploy to Wayfatours/Production.

## Rollback and source authority

- Native fallback is automatic when `mad4b_perf_predictive_css_v2` is `off`, when a URL is outside an approved plan, or when evidence expires. To disable all local Async public output explicitly, set `mad4b_perf_predictive_css_v2=off` on the affected company, not on all companies.
- Live source code was used as canonical evidence because parts of the current platform have not yet been reconciled with GitHub `master`. Review this branch's module-load dependencies before merging.
- UCSS candidates, private site/tenant manifests and sensitive telemetry are **not** part of this PR.
