# MAD4B CSS V2 — first-party Bulk Release

The source-owned route catalog remains authoritative. One route's Critical CSS,
source manifest, market country or currency certificate is never reused for
another route.

## Site-owned WordPress tasks

The existing first-party 429-mad4b-css-wpcron-dispatch-v1.php registry
registers bulk_publish and bulk_qa. Each company enrolls both with 300-second
intervals via the existing mad4b_css_wpcron_site_profile_v1 option and its
matching signed host site-profile.json. The host worker executes one original
WordPress ticket at a time under site and Chromium locks. No separate
systemd timer or parallel Chromium worker is introduced.

The bulk-release-plan.py module plans waves of 24 unique original 421 WPML
routes, rotates across language and Elementor/source-profile families, and
excludes home pages owned by first_fold. Plans are suggestions only: every
issue re-reads the source catalog, CSS epoch, runtime and 422 consumer.
At the 22 November 2026 source snapshot, 1,027 Source routes and 239 Wayfa
routes are eligible for original preflight. None is marked published by a plan.

The bulk-publish.py worker admits up to two independently source-captured routes
per WordPress ticket and at most eight unverified provisional certificates
per company. Five-width browser CSS coverage extraction builds the actual
source-bound Critical CSS; it is not performance QA. A genuine original V2
released_pending_qa certificate is published with a six-hour lease and
private,no-store HTML, then only that exact route's FastCGI generation rotates.
Each release stores an intent and pending QA record inside its private job
folder. Crashed publishing can be recovered only from an actual exact-market
422 pending certificate with the same source manifest SHA.

The bulk-postrelease-qa.py worker selects the earliest valid pending release
under the same Chromium lock and CPU guard, then tests the exact issued
five-width CSS/interaction/performance source. It either atomically verifies
the certificate with a current WordPress and 422 source readback, or reverts
that exact provisional certificate to native CSS and rotates only its route
generation. A six-hour pending lease expires to native CSS if host load
continues to defer QA. A performance test is never required BEFORE the
provisional public issue.

## Company and market constraints

- Tours.dev: WPML EN/ES/DE/FR/AR/IT; baseline USD/Cairo market must not
  authorize shared CSS for unverified EUR, GBP, EGP, nationality or market.
- Wayfatours: EN/ES, independently registered service visibility and pricing.
- Original public, first_fold, families, visual, audit and retention tickets
  retain all their original policies and schedules.
- One global Chromium worker, original host CPU guard, 421/422 source SHA
  attestations, route-specific market context, and native fallback remain.
- Catalog routes, pending QA, certified receipts and public HTML readback
  are separate status counters.

## Protected deployment and rollback receipts

Per-site backups: renderer.rollback-before-bulk-v2-20261122,
worker.py.rollback-before-bulk-v2-20261122, and
site-profile.json.rollback-before-bulk-v2-20261122.

The original WordPress 429 MU plugin has a separate
429-mad4b-css-wpcron-dispatch-v1.php.rollback-before-bulk-v2-20261122 copy.

Original WordPress option values are stored at:
reports/wpcron-css-v1/bulk-wp-profile-before-{source,wayfa}-20261122.json.

Root-owned deployment receipts are stored at:
reports/wpcron-css-v1/bulk-v2-live-deployment-{source,wayfa}-20261122.json.

To roll back, stop new bulk admissions with existing site locks; reconcile or
expire any actual 422 provisional certificates using exact CAS and route-only
cache refresh; restore 429 WordPress task profiles and pinned worker/renderer
as a matched release; and recheck original WordPress plus public readback.
Never delete source chunks shared by certified routes or purge other markets.
