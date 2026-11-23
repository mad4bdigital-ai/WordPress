# Remove unused WooCommerce single-product gallery scripts from Tour detail pages

Date: 24 November 2026
Targets: Tours.dev (staging/source), Wayfatours Production, Tour #60623 and the existing Tour detail presentation family.

## Live evidence
- The Tour archive currently has 221 published Tours on Tours.dev and 84 on Wayfatours. An initial read-only scan of published Tour post content plus the known shared Elementor templates 24784/48366/48317 found no WooCommerce product gallery widget/product-page shortcode candidates. This is a bounded content scan, not an assertion that future templates cannot add such features.
- Tour #60623 generated five Woo **single-product gallery** scripts even though the browser found no `.woocommerce-product-gallery`. A generic PhotoSwipe shell (`.pswp`) was present but there was no actual Woo product gallery. The Tour's visitor gallery is an Elementor popup.
- The five source JS assets totaled **78,755 bytes** (uncompressed responses): `wc-zoom`, `wc-flexslider`, `wc-photoswipe`, `wc-photoswipe-ui-default`, `wc-single-product`.
- Core `woocommerce`, `wc-add-to-cart`, order attribution, Fluent Forms, Elementor and all pricing/booking code were left intact.

## Exact bounded guard
First-party MU plugin runs at `wp_enqueue_scripts` priority 9999 only for a published singular `tours-and-activities` GET, not admin, editor, preview, AJAX, REST, CLI or POST. It refuses exclusion if source Tour content or known Elementor templates contain product gallery widgets, product-page/embedded products shortcodes, or if a site override declares it needs gallery assets. It de-queues only the five listed JS handles, never de-registers code or modifies core WooCommerce.

## Rollout and browser QA
Source-first and Wayfatours plugin file SHA256 is `08ec666559be5b0c2137a98e9412cc7fce745030e289ecab37bba2a2a0e92f46`; PHP lint passed. A temporary header-scoped profiler was removed after proving on both origins that all five handles were **not queued** after the enqueue stage and before script printing, while `woocommerce` and `wc-add-to-cart` stayed enqueued.

Tours.dev uncached HTML script count decreased from 124 to 119 (5 corresponding handles). Chromium mobile 390×844 and desktop 1366×900 showed HTTP 200, Form 6, visible price, TOC, and a functional Elementor popup opened by actual click, with zero uncaught JS errors on both sites.

The four-sample source Chromium A/B (blocking above 50ms) was noisy: baseline 450/332ms vs five blocked scripts 445/414ms; **no stable TBT or INP improvement claimed**. Network JS byte saving is separate from main-thread performance.

### Important limitation
Wayfatours LiteSpeed returned its **previous 1,639,055-byte combined JS asset** on a post-deployment Origin MISS even though WordPress had removed the five handles from its active script queue. This is evidence of stale optimized asset reuse; the source code is live but a smaller production visitor bundle has **not** yet been proven. Do not claim the whole user issue has ended. Avoid global CSS/JS purges that may invalidate CSS v2 certificates until there is a signed, narrowly scoped optimizer rebuild with browser acceptance.

## Rollback
Remove only `wp-content/mu-plugins/440-mad4b-tour-woo-gallery-js-admission.php` from the affected site and recheck Woo gallery scripts/remote Tour browser. No Woo/Elementor plugin files, booking data, payment status, or schema were modified.

## Open performance work
Origin MISS ~3–6+ seconds across samples, Elementor template 24784 and Mega Menu are the largest traced server components; duplicated header native options remain in DOM; authenticated user-specific Chrome freeze is not reproduced. Full closure requires signed release, progressive widget optimization with exact SSR/AJAX proof, and authenticated/real-user interaction evidence.
