# MAD4B Tours.dev — Render Pipeline / Cache Lifecycle Optimization

Checkpoint: 2026-11-07, Tours.dev Staging only
Environment: host srv2030908, WordPress /var/www/html in php-wkgtkzta35wkkgouwt6ruljc
Primary symptom: screenshots show uncached mobile HTML 15–54s and JetBlog AJAX 8–13s. This change addresses server-side Query generation, validated public destination fragment longevity, repeated WPML canonical-domain checks, term visibility checks, and optional warm-up contention. It does NOT promise a specific Core Web Vitals improvement.

## Deployed

1. Shared Render Query Governor 1.2.5
   - Cache tenant partition now hashes the canonical WordPress home option and blog ID; WPML language is still separately included in cache-group keys. This avoids repeatedly invoking home_url() and WPML permalink/backtrace filters during JetEngine public query setup.
   - Auto-regenerated _elementor_css metadata no longer bumps the global Query Data generation. Actual edits to _elementor_data, _elementor_page_settings, content and taxonomy still invalidate data.
   - Audited destination term Fragment Cache TTL upper bound increased to 600s; the specific Staging allowlisted policy now uses 300s.
   - Public term Fragment Cache's CSS fingerprint excludes only the transient Elementor CSS metadata time field. Actual dynamic-elements inventory, status, fonts, icons, Elementor template SHA and Elementor kit/version remain in the key.
   - The existing Fragment HTML public/private safety checks and company/WPML/term selectors remain in place. Do not use for rates, quotes, reservations, WooCommerce or visitor-specific responses.

2. MAD4B Tourism Source View 0.1.1
   - Only on tours.dev Staging with the source class loaded: cached canonical host check per WordPress blog ID per request, avoiding repeated WPML home_url filter work during pre_get_posts.
   - No source content, booking, conversion or redirects altered.

3. Destinations Compatibility Activation
   - Read-only memo of destination term/parent is_public checks for anonymous GET frontend, scoped by blog ID. Access/write/admin/REST/AJAX/WP-CLI continue using native fresh checks.
   - Hierarchical inactive ancestors and cycle protection preserved.

4. Optional JetEngine Static Dynamic CSS Bypass 0.1.2
   - Strictly verifies static Elementor CSS metadata and nested templates before skipping any vendor dynamic-css processing. In live QA, 0 of 114 calls qualified: default OFF to avoid overhead. Only opt-in maintenance probe may test. The original JetEngine CSS renderer is authoritative in real visitor flow.

5. Host visitor-load admission (noncritical cache warming)
   - Two systemd ExecCondition drop-ins, for mad4b-hostinger-public-cache-warm.service and mad4b-public-object-cache-warm@tours-dev.service. They defer only optional warm-up cycles when two or more WP-CLI processes are active or host one-minute load is at least max(4.0, 0.65 * CPU count). No active worker was killed or restarted.
   - CPUWeight and IOWeight set to 10, Nice to 15. Original Broker, 305/312/313 workers, company authority and Production unchanged.
   - Verified the Redis warm service ExecCondition actually skipped one cycle with status 1 under load. Subsequent timer runs are left intact.

## Verification

- PHP lint: all four relevant installed PHP sources passed.
- Isolated Query Governor CSS/locale/company tests PASS in two independent runs: Elementor CSS time-only regeneration does not trigger data invalidation; real document/page-settings changes do, with one generation increment per request. English/French and site/blog cache partitions differ, without calling WPML home_url.
- CSS fingerprint tests PASS: changing time alone leaves digest unchanged; changes to status or dynamic IDs change digest.
- Tourism source memo PASS: repeated call one lookup, switching blogs isolated, no Production activation.
- Destination activation memo PASS: published/disabled ancestor hierarchy, repeated calls, cycles, admin and write bypass.
- Jet static CSS optional proof tests PASS; 0/114 live static templates eligible; therefore default OFF.
- Warm admission 5/5 synthetic tests PASS; systemd unit verification PASS.
- Actual fragment lifecycle before TTL change: one public Home sample found 45938 native builds 20 (~3.26s), 45929 native builds 5 (~0.78s). A subsequent sample served 42 calls of 45938, 6 of 45929, and 6 of 45927 from Redis; this proves fragment reuse, not a total TTFB improvement.
- Homepage and Tours archive HTTP 200 after deployment, no new sampled PHP Fatal; original JetEngine/JETBLOG plugin files, prices and Production unchanged.
- Cache-hit HTML ~0.08-0.09s TTFB in a few observations. Uncached requests still about 9-14s and one 19s under changing host load. These readings are not matched controlled pre/post trials; no claimed regression or gain.
- WP-CLI runtime readback initially encountered STAGING_WPCLI_BUSY. A later legitimate WP-CLI slot returned ttl=300, status=active, template IDs [45938,45929,45927,48562] and external_object_cache=true. Independent runtime TTL verification is now PASS.

## Safety and rollback

- Generator 1.2.3 and 1.2.4 backups plus v1.2.5 exact source are in the local VPS QA folder.
- The original destination activation and tourism source view files were copied to *.before.php in the QA folder.
- Previous public fragment policy was 120s; backup in QA. Restore it plus the matching previous governor implementation if rollback is necessary.
- Public fragment keys still vary by site, WPML language, route, device, Elementor template content, term metadata, CSS shape, kit version and data generation.
- Post Type Legacy and Hybrid Dependency query caching remain available as before. The operator must validate PHP/Redis/FastCGI/browser separately before Production rollout.

## Further work

Main cold WordPress Elementor/JetEngine/WPML renderer is not yet fast; profile repeated dynamic CSS generation (all 114 CSS calls were genuinely dynamic in the sampled Home request), front-end visible-element query work, noncached article/tour layouts and WP-CLI demand in a controlled quiet window. Keep old and new rendering parity tests, signed AJAX behavior and Rate/Quote boundaries.

