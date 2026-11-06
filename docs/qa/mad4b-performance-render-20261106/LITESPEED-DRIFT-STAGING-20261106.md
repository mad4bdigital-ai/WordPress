# LiteSpeed × MAD4B CSS Drift — live Staging acceptance, 2026-11-06

## Verified deployment scope
**Origin:** `https://tours.dev.mad4b.com` (WordPress environment `staging`); **channel:** `registry.infra.mad4b`, authorized key alias `shell_access`; **WP-CLI:** container `php-wkgtkzta35wkkgouwt6ruljc`. **No change to Wayfatours Production or repository master.**

## Code in this PR
- `336-mad4b-litespeed-local-provider.php` v0.2.0: reads configured options and native resolved LiteSpeed Conf values; distinguishes dormant UCSS Inline and Async Library from effective CSS; tracks settings changes and keeps the original Performance Intelligence receipt in sync; records bounded anonymous HTML proof from LiteSpeed's buffer; detects unrecognized CSS bundle variants.
- `415-mad4b-critical-css-orchestrator.php` v0.3.13: checks the local provider's actual LiteSpeed eligibility before attaching MAD4B CSS Async/CCSS; never runs two CSS owners on one request.
- `company-performance-intelligence.php`: invalidates prior browser evidence when LiteSpeed CSS settings change; blocks release when the local CSS provider becomes ineligible; uses the preexisting tenant-local release receipt and selective LiteSpeed purge on revocation.
- `class-mad4b-scp-admin-query-performance-ui.php`: surfaces configured/resolved, QUIC prerequisites, observed CSS links, unrecognized source bundles and policy diagnostics in the existing Performance page.

## Readback
Native LiteSpeed CSS `optm-ucss=off`, `optm-css_async=off`; dormant `optm-ucss_inline=on`, `optm-css_async_inline=on`. LiteSpeed `Conf::conf()` agrees with saved values, and QUIC activation is false.
New diagnostics include `UCSS_INLINE_PARENT_OFF`, `ASYNC_LIBRARY_PARENT_OFF`, and `CSS_BUNDLE_NOT_IN_CERTIFIED_MANIFEST`. The current CSS file `753c892e7e8c5151228b4458057f42cf.css` is absent from the plan's two pinned variants; therefore the mobile CSS canary correctly falls back to original CSS. **Do not auto-certify the new bundle.**

Native settings event was tested on Staging by disabling and restoring only the dormant `optm-ucss_inline` option in one WP-CLI request. Its stored value was restored to `1`, `mad4b_lsc_css_drift_event_v1` recorded the update, and the release remained `native_hold` with zero approved variants. Older browser evidence is deliberately rejected after this change and remains stored for comparison.

Actual anonymous HTML readback showed combined CSS but no injected LiteSpeed UCSS/CCSS or MAD4B public Async on the examined pages. The HTML collector is staging-only, limited to safe GETs, throttled by route/device/locale and CSS bundle, with no cookies or raw page HTML stored. It cannot observe Nginx cache hits that bypass PHP.

**Tests:** 17/17 adaptive policy regression checks PASS, 17/17 LiteSpeed drift integration checks PASS, PHP lint PASS on all four live changed files, and Tours.dev / Wayfatours returned HTTP 200. The LiteSpeed native `litespeed_purge_url` action is registered; because no prior CSS variant was approved, automatic release-withdrawal purging was not triggered in this run.

## Remaining
The source's current CSS bundle changed without a corresponding certified mobile plan; regenerate candidates and rerun exact-bundle mobile LCP/CLS and interactive WPML acceptance before public Async. The last measured mobile LCP regression remains a separate release blocker. Reconcile newer source loader/dependencies before merging this Draft PR.
