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

The original families WP-Cron job also prepares the next 24 route-specific
HTML/CSS manifest candidates in original source-family order, without running
Chromium or altering WordPress/public HTML. Preparation runs with at most
three source-only compiler threads, retains no more than 96 root-owned
records per site and expires entries after 24 hours. A fresh uncached source
GET must match original market, currency, Elementor graph, runtime, CSS epoch,
stylesheet manifest and compiler SHA before using a prepared compiled chunk.
A miss or mismatch reverts to original source compiler, never a sibling
certificate. The original 422 issuer independently recompiles and verifies
every CSS asset before publication, while browser QA remains post-release.
A planned/prepared 24-route wave is NOT 24 published certificates.

The bulk-publish.py worker admits up to four independently source-captured routes
per WordPress ticket (bounded by a 1,100-second host wake budget) and at most twelve unverified provisional certificates
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

### Revision-safe prepared-source retention

The immutable first-party compiled source cache may survive for 24 hours,
rather than one hour, because the independent global Chromium QA and original
WordPress ticket queues can delay publication beyond 60 minutes. Extending
this PRIVATE source-cache lifetime does **not** extend the six-hour public
provisional certificate or qualify an old CSS payload. Reuse still requires
current fresh uncached original HTML, exact company, WordPress route revision,
Elementor source graph, WPML language, WCML country/currency, 422 runtime and
consumer SHA, CSS epoch, full ordered CSS dependency manifest and compiler
source hash. The original 422 publisher independently recompiles source and
executes CAS on the live certificate. No cross-route/market certificate reuse.
The per-site 96-record retention bound and browser/CPU gates remain unchanged.


### WordPress Menu Header Global Event Coalescing

The first-party 421 route/market/locale invalidation still treats actual
navigation and Theme Builder mutations as global and invalidates affected
shared-header CSS. One WordPress PHP menu-save request can update many menu
item fields; the original plugin incremented global/listing CSS generations
for each such metadata write (18 items x 9 writes = 162 generations).

The revised 421 invalidates on the FIRST menu mutation immediately, then
performs a FINAL global invalidation on WordPress shutdown in that same PHP
request. Any new CSS certificate issued during menu editing is invalidated
by the final mark. A subsequent PHP request still gets an independent first
and final mark. No time-based debounce, cross-request suppression, unverified
event exclusions, or changes to post/taxonomy/commerce dependencies.

The existing 422 V2 runtime and consumer SHA remain byte-for-byte unchanged
and retain the existing certificates. Both the legacy first-party 421 SHA
and the source-backed batched 421 SHA are explicitly admitted by the
original renderer's immutable source allowlist for rollback continuity.
An unknown 421 implementation still fails closed.

Hermetic test coverage: 58 original and new event invariants, including
162 repeated native menu metadata writes, first and final CSS invalidation,
request-boundary behavior and existing WPML/Elementor routing rules.


### Aged original post-release QA priority and bounded publish backpressure

The first-party WordPress 429 site-owned scheduler remains the sole ticket
issuer. The original host worker now grants an already-issued native
bulk_qa ticket priority over younger first_fold/public/families/bulk_publish
tasks if an original **root-owned, company- and host-bound** provisional
source has waited at least **60 minutes**. The worker only performs a
strict read-only 422 source-record check for this priority. It may never
invent or acknowledge a ticket, skip CPU admission, bypass an overdue
site-integrity audit, run a second Chromium worker or approve a CSS
certificate. Original 422 bulk_qa performs independent source/catalog
readback plus real five-viewport Chromium testing, or atomically reverts
an expired or failing provisional route with its original CAS and
route-scoped Native CSS cache recovery.

Publishing now applies an additional source-backed backpressure rule:
when **four or more** original public pending QA leases exist and their
oldest has waited **90 minutes**, new releases are deferred until the
original QA queue clears. The original 12-pending hard cap remains as
a second guard. Deferred publisher tickets remain pending in WordPress
with normal resource-style retry, never misreported as complete. The
existing public six-hour lease (Native CSS fallback after expiry), 24-hour
PRIVATE source chunk cache, exact market/currency SHA checks and global
Chromium serialization are unchanged.

This fixes an observed Wayfa 12-lease queue in which three provisional
leases expired while source and public tickets continued to run. It is
a scheduler/feedback correction, not a claims-based certification.


### Bounded expired-CAS cleanup in the original postrelease queue

When the first-party native bulk_qa ticket acquires the original site and
global Chromium locks and passes the unchanged strict host CPU guard,
its oldest **already-expired** V2 public source leases can now be cleaned
in batches of at most THREE expired records and 150 seconds per ticket,
using the same independently source/market/route-verified original
qa_one and 422 compare-and-swap retract, route-only cache recovery and
native CSS fallback. The private bulk-pending timestamp alone cannot
revoke a newly reissued public certificate: the currently installed
422 certificate's own six-hour deadline is independently verified. A
strict 60-second extra grace excludes apparently expired queue timestamps
whose original 422 release timestamp may differ slightly. A genuinely
newer, market/source-identical 422 lease only supersedes the old *private*
queue record without revoking or certifying the newer public lease.

Newer or still-active CSS candidates continue to receive exactly ONE
original full real-viewport (320/390/414/767/768) postrelease browser
QA per ticket. No Chromium jobs are batched, no public CSS certificate is
issued from cleanup and no WordPress tickets are fabricated or ACKed
until the original worker verifies completion. Resource deferral and
rollback errors remain retriable under original WP-Cron. This speeds
cleanup of inherited expired provisional leases only and never relaxes
the V2 CSS readiness or CSS source signature requirements.

Original expiry batching and same-route supersession are covered by
13 isolated fixture checks plus the 19 prior original Bulk invariants.
