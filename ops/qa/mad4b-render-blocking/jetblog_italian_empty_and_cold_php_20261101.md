# Staging Italian JetBlog empty requests and cold PHP evidence — 2026-11-01

Scope: tours.dev.mad4b.com Staging, site UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. No Production changes.

## Content fact and missing translations

English WordPress post #16248, 7 Best Spots for Diving in Egypt, has WPML trid 103774. Published AR #42168, FR #23583, DE #23595 and ES #23607 translations exist. Italian WP_Query for published post type post returned zero. No Italian translation exists in this English post's WPML group. Italian homepage JetBlog widgets b44fd5b and 1330604 have post_type [post], noncustom query, 1-column by 3-row layout without mobile overrides, and empty server HTML. Their default mobile code nevertheless POSTs twice to jet_blog_smart_listing_get_posts.

No invented, fake-published, or silently English-fallback Italian article was created. Editorial publication is a separate content task.

## Staging live fastpath in MU 325

The existing module 325-mad4b-jetblog-mobile-server-first-paint.php now checks a real localized WP_Query of at most one published Italian post, with WPML filters enabled, no found-rows and IDs only. It skips the two known empty widgets only if there are no published Italian posts, the Elementor template hash and JetBlog version still match, request is anonymous/cookie-free/default-currency/unfiltered, widget post type is precisely post without a custom/archive query, and its HTML is empty. The first published Italian post makes the guard automatically fail open to original JetBlog fetching. EN/FR/DE/AR/ES keep their earlier server-first-paint optimization. Explicit native-control header remains available.

Real 390px Playwright tests:
- IT native-control: 2 initial AJAX; optimized: 0. Both widgets remained identically empty, HTTP 200.
- EN native-control: 2; optimized: 0. Article text SHA, links and image geometry match.
- Italian synthetic orientationchange after optimized load: 2 normal JetBlog AJAX requests; both widgets returned to nonprocessing state.
- Intermittent generic browser network errors appeared in both native and optimized cases, not a demonstrated new regression.

Staging source and live MU 325 SHA-256:
610bc17456eb9703385e6a41e97086d7c6f1dbb78d0ee89743fbd6f8922a9e49.
QA snapshots: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/jetblog-it-empty-20261101/.

## QA release-gate repair

The Country and Nationality Switchers QA release gate failed on PR 677 because the new native nationality source introduced three uses of str_contains, but the original portability contract prohibits it. The source now uses semantically equivalent strpos checks for signed cookie separators, UTF-8-normalized matching of country names, and ISO2 matching. The original ten gate tests all pass WITHOUT modifying or weakening that release test. French and German accented country search remained functional.

## Independent Cold origin PHP measurements

Earlier captured Staging EN archive request:
- 3913ms total, 1005 SQL queries, peak PHP memory 126MiB.
- Elementor widget 7739f090: Tour List Grid, Listing 25102, Query 5, 533.8ms self-time, 750ms inclusive, 49 own SQL.
- Elementor widget 29f3807b: Featured Tours, Listing 14962, Query 22, 348.1ms self-time, 389.2ms inclusive, 47 own SQL.
- Nested 30e3360: 146.7ms self-time across 12 calls.
- An independent 3641ms request had SQL time only 235.43ms. Optimizing database statements alone cannot eliminate the multi-second PHP rendering overhead.

OPcache is already enabled (512MiB and 20k slots). PHP-FPM has four workers, four vCPU allocated, and no FPM pool configuration was changed. Recent no-page-cache sample TTFB remains about 3.5 to 4.2 seconds. No prices, inventory, sessions, FX, or filtered tour HTML were cached; the cold PHP render bottleneck remains open and requires further source-level safe optimization. No PR merge and no Production mutation.

## Six-locale fresh profiler verification (2026-11-01)

A temporary Staging-only read-only Elementor render profiler, activated by a private request header, measured fresh anonymous Tours archives with page cache bypass. All pages returned HTTP 200. It was removed immediately after collecting the six results; no production profiler or FPM configuration change remains.

| WPML language | Request duration | Query count | Tour List 7739f090 self time | Featured 29f3807b self time | Nested List 30e3360 self time |
| --- | ---: | ---: | ---: | ---: | ---: |
| EN | 3832ms | 980 | 572ms | 409ms | 132ms |
| FR | 3222ms | 867 | 547ms | 208ms | 100ms |
| DE | 3390ms | 900 | 572ms | 352ms | 155ms |
| AR | 3033ms | 856 | 549ms | 324ms | 93ms |
| ES | 3319ms | 902 | 542ms | 367ms | 125ms |
| IT | 3260ms | 908 | 500ms | 428ms | 101ms |

The six samples are isolated first-party staging observations, not statistically robust real-user p75/p95 metrics. Tour List #25102 (JetEngine Query #5) and Featured #14962 (Query #22) remain the dominant individually measured widgets across all six languages. The fresh evidence lives at:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/oauth-signing/perf-fresh-widget-20261101.jsonl

Future render optimization must preserve native per-tour dynamic price, inventory, WPML query context, JetSmartFilters and distinct List/Grid layout; current test does not authorize caching those user-sensitive results. The PHP cold-render objective is explicitly open.
