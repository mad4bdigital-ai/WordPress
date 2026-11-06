# MAD4B source reconciliation — selective render, WPML links & CWV

Date: 2026-11-06. Evidence site: `tours.dev.mad4b.com` (staging only).

## Changes and evidence
- `mad4b-render-query-governor.php` v1.2.2 uses existing Redis and JetEngine Query Builder, default-off selective term-only HTML fragments. Safe cache keys contain locale, device, template SHA, template CSS fingerprint, taxonomy term state and data-generation. Private requests, filters, prices and bookings remain native.
- `414-mad4b-stable-listing-css-identity.php` v0.2.1 stabilizes ephemeral JetEngine selectors; render fragments require this to be active.
- `native-listing-background-lazy.php` fixes a strict JavaScript scope error where a `schedule` callback was bound outside its block.
- `class-mad4b-scp-core-web-vitals.php` uses the existing WP-Cron queue and Google Pagespeed source, with a verified-release trigger and job receipts. Measurements may fail on provider timeout/rate limits and must not be reported as improved Core Web Vitals without CrUX data.
- Performance admin UI exposes local accepted release evidence independently of Core Web Vitals field data.
- Stale `icl_tax_permalink_filter` Redis entries were repaired on staging by exact cache-key deletion: 30 mismatches from 311 cached `destinations` links, all re-rendered correctly across 6 WPML languages. The Query Governor now invalidates this WPML group on taxonomy mutations and explicit migration actions.

## Verification on staging
- EN warm cache 57/57 eligible term fragments; FR 37/37; ES 48/48.
- HTML byte-equivalent native/cached sections: attractions, locations, destinations. Swiper on desktop/mobile, 0 JS errors in the acceptance run.
- Sample paired source TTFB (uncached page shell): native 5.147s, cached 4.067s. Not an end-user p75 or guaranteed LCP gain.
- Individual request timings vary substantially with PHP-FPM CPU; Nginx warm HTML remains about 20ms.
- Route-level cache isolation: visitor cart/currency/filter inputs bypass shared HTML fragments.

## Release constraints
- **Do not deploy this branch automatically to Production**: main does not yet contain the full platform module catalog/loader from the live source. Reconcile loader, provider dependencies, manifest bindings and full 6-language page parity first.
- `mad4b-selective-render-policy.json` is **not committed**. Each company must generate its own exact host/environment/template SHA allowlist in provisioning. Default flag OFF.
- `Wayfatours` remained unchanged. Preserve existing live source version if it is newer than this branch.
- Rollback on source: `wp option update mad4b_render_public_fragments_enabled 0`; leave the pre-existing stable CSS engine as configured.

## Core Web Vitals
The original admin control is `admin.php?page=mad4b-control-plane-performance`. Queue via the existing button or a verified-release event. Google CrUX is rolling 28-day real-user data. The release receipt alone is not a passed assessment.
