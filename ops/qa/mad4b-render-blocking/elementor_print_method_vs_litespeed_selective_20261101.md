# Elementor Internal vs External CSS with LiteSpeed External+Inline Combine ON

Date: 2026-11-01. Site: tours.dev.mad4b.com; Staging only, enrolled UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production unchanged.

## Fixed LiteSpeed condition

For the A/B requests, LiteSpeed Cache 7.9.2 CSS Combine and Combine External+Inline were BOTH effective. Scoped Staging MU332 turns on External+Inline only for anonymous, unfiltered public six-language Tours archives and excludes per-request Elementor/JetEngine elementor-dcss-* CSS. The saved global LiteSpeed settings remain Combine=1, External+Inline=0. This is intentional: an unguarded all-inline combination produced two different URLs for nearly 3MB of CSS in two consecutive identical requests because Elementor generated new dcss selectors.

## Actual installed Elementor source behavior

Elementor 4.3.5 core/files/css/base.php use_external_file() reads elementor_css_print_method. Base::update() persists _elementor_css status inline (and CSS text) or file. Base::enqueue() prints the stored inline stylesheet or enqueues the generated file. Merely filtering the option during a request does not regenerate the cached CSS meta and is NOT a valid full A/B.

The Elementor settings action update_css_print_method() invokes files_manager->clear_cache(), removing all cached Elementor post CSS metadata and generated CSS files, plus other Elementor cache metadata. The site had approximately 303 cached post CSS records before the tests. Repeated global mode flips can cause significant recomputation and affect concurrent Staging jobs.

LiteSpeed src/optimize.cls.php parses HTML link/style tags after WordPress/Elementor/JetEngine has completed rendering. It cannot reduce PHP widget construction time. The source exclusion hook litespeed_optimize_css_excludes permits leaving dynamic Elementor dcss blocks inline while combining stable styles.

## Confirmed LiteSpeed A/B with Elementor Internal

| Metric | Prior external CSS combine | Safe selective external+inline combine |
| --- | ---: | ---: |
| Uncompressed English archive HTML | ~1.945MB | ~1.187MB |
| Gzip-compressed HTML | ~212,284 bytes | ~121,570 bytes |
| Gzip-compressed combined CSS file | ~269,291 bytes | ~339,172 bytes |
| Inline style blocks | ~77 | ~27 |
| Median origin TTFB in six alternating pairs | 2.7725s | 2.7812s |

Three of six pairs favored each variant: the source-backed conclusion is reduced transferred HTML and stable combined CSS URLs, NOT a proven Cold PHP TTFB or LCP improvement. The first CSS download is larger by about 70KB compressed, whereas repeat navigations may save about 91KB HTML once that CSS is cached.

Six WPML locale request pairs (EN/FR/DE/AR/ES/IT) all responded HTTP 200 with stable combined CSS file hashes across repeated requests in each locale. Desktop Chromium parity succeeded for Tour List, Featured, nav counts/IDs/prices/links/text/geometry and sampled computed styles. Mobile EN/AR parity succeeded. Cookies associated with WooCommerce, unknown context, currency, authorization, geography, or mismatched ORIGINAL incoming WPML language cause native fallback; benign attribution cookies preserve the eligible optimization. Verified repeated browser visits retain a stable CSS URL.

## External File generated natively, scoped experimental outcome

Used the actual Elementor Core\Files\CSS\Post::update() generator for the 42 archive-template post IDs, with a CLI-only External option override. All 42 file CSS sources were successfully generated (794,851 bytes total). Each original cached _elementor_css meta value was restored within a finally block; the persistent Elementor option remained Internal. For inspected templates the generated file SHA256 matched the original Internal CSS contents.

A separate request-scoped file-path experiment produced roughly 1.186MB HTML, close to the safe Internal output (~1.187MB). This scoped result is NOT a full, valid global External-vs-Internal performance proof: Elementor re-rendered certain CSS metadata while serving this test, and only selected archive-template records were switched to file status. The temporary MU415 was removed. Twelve file-status records observed while the configuration was Internal were returned to inline using the native Elementor updater, with exact Staging checks.

Twice, a guarded global-mode A/B script stopped BEFORE making any option changes because the Elementor option had unexpectedly become External after previous reads of Internal. It subsequently returned to Internal. This indicates concurrent configuration changes in the shared Staging environment; the exact actor was not established. A reliable real global comparison requires exclusive staging access and complete CSS regeneration/warm-up without other concurrent option changes. The report does not claim External improved TTFB, LCP or PHP CPU.

## Recommendation

Keep Elementor print method Internal for the moment, and retain the Staging MU332 selective LiteSpeed External+Inline ON on approved public archive routes. It reduces repeat HTML transfer without causing dynamic-selector CSS-cache churn and preserves fresh prices, availability, booking, nationality and filters. Neither print mode bypasses native Elementor/JetEngine Tour List and Featured widget creation, which remains the larger Cold PHP TTFB cost (~2.7-3s).

Current global Elementor mode was verified Internal after test cleanup. No global LiteSpeed option changed. No Production mutation, no PR merge. QA: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/litespeed-external-css-combine-20261101/
