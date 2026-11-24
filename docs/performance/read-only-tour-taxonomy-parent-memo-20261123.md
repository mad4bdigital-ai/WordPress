# MAD4B: tour read-only canonical taxonomy parent-row memo (23 November 2026)

## Context

Live Wayfatours tour 60623 was causing 1,730–1,874 WP database queries in the normal HTTP GET path with an unknown test cookie, including **1,145 repeated** `SELECT term_id,parent FROM wp_term_taxonomy WHERE taxonomy='destinations' AND term_id=? LIMIT 1` reads. Other query-param diagnostics yielded up to 2,225 queries. The owner is the existing `MAD4B_Company_Country_Operating_Scope_V1::canonical_country()` implementation in `020-mad4b-company-country-operating-scope-v1.php`.

Its existing request-local **raw taxonomy-parent-row** memo was intentionally restricted to no cookies, no query params and anonymous GET requests. Logged-in editor views and ordinary visitor cookies missed the memo and repeated the same raw ancestor reads 1,000+ times across Elementor/JetEngine widgets. This is a frontend *presentation* N+1, not a price change or a sales-policy failure.

## Scoped change

Preserve all existing country, WPML canonical ID, catalog activation, geographic sales authorization, publication and non-authorized decision logic. Extend **only the existing raw parent-row request-local map** (maximum 512 IDs) to an exact **read-only public Tour detail GET**, even if it has a visitor or logged-in cookie. This happens only when WordPress has resolved a single `tours-and-activities` page; it excludes admin, POST, AJAX, REST, cron, WP-CLI, preview, query params, authentication headers and WP-REST nonces. There is **no cross-request, Redis, persistent cache, sale-eligibility cache or price cache** and the WPML canonical mapping and term meta are still revalidated on every call.

The existing guard, for clean anonymous GET with no cookies, is unchanged. A new path only allows reusing the read-only term parent rows while rendering frontend Tour HTML; all write-capable, unknown and session-management endpoints retain direct database reads. Header and cookie *values* are never stored or logged.

## Live before/after

Source Tours.dev, Tour `nile-dinner-cruise-cairo`, unknown-cookie bypass request, two samples: 7.46s / 5.30s before; 4.61s / 4.30s after. This is influenced by host load; not a statistically controlled comparison.

Wayfatours Tour 60623, unknown-cookie bypass request with a temporary guarded PHP profiler removed immediately after two before and two after reads:

| Measure | Baseline #1 / #2 | Patched #1 / #2 |
| --- | --- | --- |
| HTTP | 200 / 200 | 200 / 200 |
| Origin TTFB | 3.827s / 3.906s | 4.021s / 3.764s |
| PHP query count | 1,874 / 1,730 | **656 / 656** |
| Canonical parent-row SELECTs | 1,145 / 1,145 | **71 / 71** |
| Peak PHP memory | 129.1MB / 131.1MB | 129.1MB / 129.1MB |

This **eliminated 1,074 redundant SQL reads per request** in this test context, with **no proven latency improvement** on Wayfatours at the observed host load. The remaining expensive Elementor Template 24784 / JetEngine listings, WPML translations, meta lookup and JS main-thread work must be optimized independently.

Both site source files had the same original SHA256 `be0413336c95fd8040c97734fc41cf28bd5ffd54c3bb80e5dcea07e6cd4b3375`; both now have SHA256 `78a78978ed12b75057d17ca016abed4f4060560b47cb3875a8aa1a4e42ff835a`. PHP 8.5 lint and narrow hermetic authority/route tests passed. Original backups are retained under `/opt/mad4b-backups/tour-parent-row-presentation-20261123/{source,wayfa}/original.php`. Both sites returned HTTP 200 and page sizes remained unchanged during source tests.

## Safety / deployment notes

This file was live on both sites but **not present on the reviewed Git master tree** at capture time, so the PR commits a reviewed first-party source copy to reconcile it with Git. Do not replace this new source using an older master ZIP without a live-file/source diff. WordPress commercial write operations and checkout must not consume this read-only row memo. Production release/owner approval remains independently gated.

Rollback per site: verify original backup SHA against the documented SHA, then replace ONLY `020-mad4b-company-country-operating-scope-v1.php` from the corresponding protected backup, validate PHP syntax and test a Tour. No change to Nginx or database schema.
