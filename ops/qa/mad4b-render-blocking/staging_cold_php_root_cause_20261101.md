# Cold PHP: root-cause and bounded diagnostic remediation

2026-11-01. Staging: https://tours.dev.mad4b.com; enrolled site UUID e5e64bca-ed62-48d2-b2b3-dff693cd702a. Production unchanged.

## Source-level breakdown

Read-only one-request header-gated PHP timing probes established:
- EN: wp hook 560ms, wp_head 788ms, get_footer 2619ms, wp_footer 2804ms, shutdown 3141ms. Tour List 520ms; Featured 309ms; JetSmartFilters range 153ms. 876 SQL queries and 124MiB peak PHP memory.
- FR: wp hook 641ms, wp_head 877ms, get_footer 3006ms, wp_footer 3378ms, shutdown 3689ms. Tour List 883ms; Featured 430ms. 836 SQL queries and 126MiB peak memory.
- SQL time is not dominant: aggregate SAVEQUERIES timings ~284.5ms for 882 EN queries, ~190ms for 814 FR queries.
- About 290–390ms of request-end output-flushing time. The Elementor image optimization callback itself took only 1.24ms EN and 1.41ms FR over 68/30 image tags. Disabling image optimization would not remedy flushing and could degrade LCP.
- EN public HTML ~1,945,691 bytes, including 836,167 bytes from 77 style blocks, ~137,704 bytes of script blocks and ~178KiB from 382 SVG tags. Elementor's largest inline CSS block alone ~257.5KiB. Exact duplicate style blocks total only 3,519 bytes.
- Elementor CSS print method is currently internal. A trial request-only external override produced identical HTML and CSS counts. No persisted setting was changed; trial rejected.
- Nested destination term template 48562: 13 calls with just 4 unique terms and 9 duplicates per EN/FR request; existing MU328 already deduplicates within request. The native source hash of each term was stable across independent requests.
- JetSmartFilters range callbacks already receive source-data from their original provider; EN had no identical duplicate query contexts suitable for another general-purpose cache.

All temporary probes 406–413 were removed after use. Permanent Tour/Featured price and HTML rendering behavior remained unchanged.

## Root VPS CPU starvation identified and remedied

The VPS has 8 CPU cores, 31GiB RAM, and at the time had 8GiB swap occupied with load near 7–10. Four orphaned source-search greps were confirmed running for ~97–98 minutes, each using ~95% of a CPU core. All four were exact duplicate diagnostics searching a source token across the entire Docker volumes tree and broad host paths. They were not a PHP-FPM worker, WordPress process, database, production service or a container.

After checking exact command, parent process, and source scope, sent SIGTERM only to those four child grep PIDs; all four exited. No Production container, application source, DB or FPM setting was modified. Load declined from about 6.36/7.85/8.53 (1m/5m/15m) to about 2.46/6.11/7.84 as averages decayed.

Three immediate EN uncached archive TTFBs: 2.873773s, 2.672536s, 2.665948s.

Ten repeat HTTP 200 page-cache-bypassed requests with Cache-Control: no-cache:
- EN 6 samples: 2.889288, 2.609621, 2.792940, 2.779736, 2.694988, 2.676623s. Median 2.737362s; min 2.609621; max 2.889288.
- FR 2 samples: 2.864159, 2.667100s. Median 2.7656295s.
- AR 2 samples: 2.707079, 2.621995s. Median 2.664537s.

This is an observed nonrandom before/after result under changing server load, not a controlled causal attribution or statistically reliable p75/p95. Cold origin PHP remains around 2.7s, so full closure is NOT achieved.

## Durable bounded source-search workflow

Added read-only script:
ops/qa/mad4b-render-blocking/staging_bounded_source_scan.sh

Only allowlisted scopes repo and staging-mu are accepted. The second checks exact Staging site URL/environment/UUID. Searches are literal (not regex), PHP files limited to under 512KiB, skips .git, vendor, node_modules and uploads, and are terminated after 15 seconds with a further 3-second kill-after limit for only the search subprocess. At most 80 result paths are printed; output is temporary and removed after completion. No site mutation occurs.

Examples:
- bash ops/qa/mad4b-render-blocking/staging_bounded_source_scan.sh repo 'wordpress_queue_execution_allowed'
- bash ops/qa/mad4b-render-blocking/staging_bounded_source_scan.sh staging-mu 'wordpress_queue_execution_allowed'

Regression test:
ops/qa/mad4b-render-blocking/tests/test_staging_bounded_source_scan.sh

It passed on the VPS, including a known source result, unknown scope refusal, empty-pattern refusal, maximum file size and timeout checks. This helper prevents recurrence only where adopted; it cannot automatically intercept commands dispatched independently by other shell clients.

## Remaining engineering target

Native Elementor and JetEngine Tour List/Featured per-card creation, WooCommerce/dynamic pricing integration, and approximately 1.95MiB of HTML still cost substantial PHP CPU. These require source-level redesign and six-language price/booking/filter/SEO parity, not a generic cached priced-card response. Staging FPM max_children remains 4; increasing it while CPUs/swap are stressed is not supported by current capacity evidence. No Production mutation or PR merge.
