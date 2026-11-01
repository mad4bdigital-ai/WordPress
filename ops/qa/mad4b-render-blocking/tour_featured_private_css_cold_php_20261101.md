# Tour List and Featured: persistent CSS-only cold-origin acceleration

2026-11-01 — tours.dev.mad4b.com STAGING only; enrolled UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. No Production write or PR merge.

## Diagnosis

Tour List 25102 and Featured 14962 still require native Elementor/JetEngine per-card HTML generation, including live price, offer badge, inventory and filters. Independent first-origin render timings remain about 3–4s, even with existing CSS and term caches. A focused element profiler found that the 12 EN Tour price heading widgets took ~5.6ms, 11 Featured price fields ~6.7ms, and 23 global_offer_template shortcodes ~1ms. FR Featured price widgets cost ~65.8ms in that sample. Caching price HTML is inappropriate: most remaining costs arise in Elementor widget creation and its dynamic CSS processing.

Existing MU324 v1.1.0 accelerates CSS-only work for 180 seconds using source-fingerprinted WordPress object cache, but recomputes native CSS on object-cache misses/expiration. Newly installed v1.2.0 adds a bounded, SHA-verified disk tier to preserve that source-invariant CSS across cold origin PHP requests.

## Applied Staging change

Source: wp-content/mu-plugins/324-mad4b-priceless-dynamic-css-cache.php
Live/source SHA256: d588377a87abdb436549b3ef33ff538e174ca2e63d0314136ad82193b23895cb.

Only a normalized HTML style prefix is saved. The unique Elementor CSS selector ID is replaced with a static marker and regenerated for each request. Tour List/Featured per-tour HTML, price labels, currency, availability, customer information, filters and WooCommerce fragments are ALWAYS rendered anew and appended after the cached CSS, never saved.

Source-key includes exact six-locale template hashes, post ID and postmodified time, full tour postmeta hash, locale and mobile family, active Elementor Kit settings/data/modified time, custom WordPress CSS, theme and Elementor/JetEngine/WPML/WooCommerce versions. Only exact enrolled Staging origin, anonymous default-USD unfiltered GET archive requests without cookies, geo/currency override or authorization are allowed. Template edits fail closed.

Disk snapshots reside outside web root under /run/mad4b-oauth/mad4b-tour-dcss-v1/, max 256KiB per card, private 0700/0600 permissions, content SHA256, 72-hour lifetime, strict style-prefix/marker validation, rejection of script/form tags, safe filename and symlink checks, atomic temp write/rename. A daily Staging-only WP-Cron cleanup for expired old files executed successfully in 0.016s and did not delete valid data. Warm-up of 6 locale archives produced ~241 snapshots, ~1MiB private storage.

Three request modes allow independent comparisons: header X-MAD4B-PRICELESS-CSS-CONTROL: native-v1 forces original CSS computation; X-MAD4B-PRICELESS-CSS-DISK: disk-v1 forces disk-only (skips memory); X-MAD4B-PRICELESS-CSS-DISK: off-v1 disables disk but keeps the pre-existing 180s object cache.

## Live QA

Chromium desktop EN, FR, DE, AR, ES, IT native-vs-disk: all HTTP 200, no page errors, same Tour List and Featured post IDs/order (after a repeat FR check for variable Featured source data), live price texts, hrefs, normalized card text, stylesheet/style counts and script fingerprints. Elementor generates new numeric CSS selector IDs even between two native requests; the expected difference was normalized. EN and AR List -> Grid -> List interactions passed in native and private-disk mode. An intermittent browser network-change trial was retried and passed.

Standalone PHP contracts: prior CSS-only fresh-HTML contract PASS; new disk contract PASS for private permissions, SHA corruption, expiry, malicious markup, symlink/traversal, fresh price HTML appended after CSS and regenerated selectors. No persistent customer/price HTML.

## Paired origin TTFB (English Tour archive, page cache bypassed)

| Pair | Native CSS TTFB (s) | Private disk CSS TTFB (s) |
| --- | ---: | ---: |
| 1 | 3.536209 | 3.377435 |
| 2 | 3.415852 | 3.005665 |
| 3 | 3.507878 | 3.216347 |
| 4 | 3.410754 | 3.209094 |
| 5 | 3.452314 | 2.820469 |
| 6 | 3.297379 | 2.981897 |

Native median 3.434083s; private CSS median 3.1073795s, observed 0.3267035s (~9.5%) lower; 6/6 disk paths faster. Small six-pair sample, not statistically robust p75/p95 or guaranteed first-ever cache-empty improvement. An entirely new/changed template or tour still renders source-native CSS once; subsequent matching requests avoid it. PHP TTFB remains about 3s and is NOT fully closed.

## Rollback

QA: /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/dcss-private-disk-20261101/
Rollback: rollback-dcss-private-disk.sh plan|apply there. Plan validates exact URL, environment, site UUID, deployed and original MU324 checksums. Plan PASS, apply not invoked. Apply would restore the previous 180s memory-only MU324 and remove only the Staging cleanup event; private files become unused. Production untouched.

Open: native Elementor and JetEngine per-tour widget render, WordPress startup and JetSmartFilters still cost seconds, so full cold-origin performance target remains open.
