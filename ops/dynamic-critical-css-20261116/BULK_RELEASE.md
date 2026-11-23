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


### Per-object native WordPress content transaction coalescing

The original first-party 421 WordPress dirty/event source now coalesces
duplicate post/meta events for one Tour, Property or Blog Post **within
a single PHP request**. Its first event immediately marks the original
global listing generation and the exact object's post-meta generation.
The final shutdown mark executes only when that same object received
multiple events, invalidating any 422 CSS certificate issued during the
remaining in-request content edits. Another HTTP/WP-CLI request always
gets a new independent first and final mark. One-event updates retain
their original single generation, preserving 422's existing strict
unrelated-property-only proof for nonintersecting tours.

This changes neither the PHP 422 consumer SHA (a change would itself
invalidate many certificates) nor the source/Elementor/browser/CSS
manifest admission and original six-hour provisional CAS. Page,
global CSS, navigation, WPML and taxonomy events still follow the
unchanged original paths; no post updates are globally waived. The
original 421 SHA and previous first-party nav-coalescing SHA remain
explicit exact rollback versions.

A hermetic 421 PHP behavioral fixture proves 68/68 invariants including
25 metadata writes on a single hotel generating exactly two original
post/listing events, one-event property saves retaining original semantics,
13 separate tour visual updates, independent two-tour revisions, home
and WordPress page changes, plus prior native navigation conditions.


### Native WPML/421 source snapshot consistency and stale V2 recertification

The original 5-minute WordPress family refresh already reads and attests
the live 421 WPML catalog and 422 runtime/consumer/CSS policy source.
Its family report now atomically references an immutable **paired** root-
private catalog/site snapshot, with both exact SHA256 hashes, matching
CSS epoch, 421 global and listing generations. The original Bulk planner
reads exactly that report-bound pair rather than guessing which historic
jobdir happens to have the latest filesystem mtime. Any missing pairing,
mismatched CSS epoch, 421 generations, company scope or tampered snapshot
fails closed; old reports retain the original strict snapshot behavior.
Only the three latest root-private family source snapshots are retained.
This fixes Source CSS epoch 154 family report paired to stale epoch 153
historic jobdir (which had previously blocked original Bulk planning).

The original Bulk issuer scans only the root-owned 422 **certified** route
certificates whose exact host|language|path|market|currency SHA matches
their filename, and identifies candidates with a source-bound mismatch in
the report's global, route or listing generation, CSS epoch, immutable
422 runtime SHA or consumer SHA. Such files are never reused, re-certified
from a snapshot or publicly marked Ready. They are scheduling hints only.
At most **one** of the unattempted source-mismatched certificates gets
priority per native publish ticket; it must have a 2-hour no-repeat
cooldown and no already-open original QA lease. The native route publisher
re-reads current original 421/422 WordPress source, fresh HTML, original
full ordered stylesheet SHA, actual WCML country/currency, Elementor graph,
Chrome extraction and 422 CAS before issuing a new six-hour provisional
certificate. Five-width performance QA still runs after public release
and can revert each exact route to Native CSS. Prioritizing a repair never
advances the existing normal family+language publication cursor; all
remaining ticket capacity still goes to routes in original native order.

Old V2 certificates that are still acceptable through 422's narrow
original unrelated-property proof are not globally invalidated here:
the issuer has the final authority. Any market/route/context mismatch
fails the same strict existing source checks and cannot grant public CSS.
No 421 or 422 PHP changes, no manual certificate status or generation
changes, no new ticket issuer, no CPU/Chromium bypass and no forced
HTML cache purge. Fixtures cover the 21 priority safety/fairness cases
and 12 paired-original-source/Tamper cases; old 19 bounded-Bulk and
13 release-first tests remain required.


### Postrelease 3-minute first QA and per-route proof reporting

WordPress 429 remains the sole source of immutable CSS task tickets, with
two independent company-bound OS Cron wakes every 5 minutes. On an actual
root-owned, company/host/route bound **released_pending_qa** record
which has reached three minutes of age, the original native scheduler
prioritizes its **already-issued WP bulk_qa ticket** over further new
public/CSS source issues. If an overdue integrity audit has priority -1,
it remains first. The original strict host CPU/CLI guard, global Chromium
lock, per-route 422 compare-and-swap, six-hour public lease, and full
independent 320/390/414/767/768 real-browser QA are unchanged.
No WP job is fabricated, ACKed early, or tried before the site admission.

The original V2 bulk issuer reduces its maximum simultaneous untested
leases from 12 to **three**, and its per-ticket newly published candidate
cap from four to **two**. When two or more original pending lease markers
exist and the oldest waited >=10 minutes, new bulk publishing defers
without consuming the WordPress ticket. The hard three-pending cap is
always enforced even for fresher leases. All historic strict runtime,
market, CSS manifest, WPML and route source checks remain unchanged.
This favors testing an issued certificate while its first-party 421
source remains current rather than creating many 6h provisional issues
that will later be source-invalidated.

Original health-audit now exposes
`route_target_delivery_coverage` independently of its legacy home-
inclusive route catalog count: it excludes exactly the six Source/
two Wayfa first-fold home routes, retains only first-party 421/422
source-current certificate counts, then reports the actual per-market
anonymous HTTP **sample size and independently verified V2 HTML delivery
count** separately. Samples never extrapolate to all 1,267 historic
or newly created eligible routes, and an HTTP HIT alone never proves
a CSS optimization. The report does not issue/publish CSS or modify any
cache/certificate. A full site coverage claim remains unverified unless
source-current certificate and actual delivery proofs support it.

The browser is still permitted to reject a candidate even when the
source generation has not changed, and the original 422 consumer's
broad listing/epoch invalidation is **not overridden** by this QoS
change. A future scoped consumer contract requires separate verified
Elementor/JetEngine source dependency proof and canary QA; no unsafe
generation-number rewrites are made.

### First-party 421/422 ordered listing-event scope proof (Nov 23, 2026)

The original 421 source generation is a global monotonically increasing
dirty counter. Its 100-key `ids` map holds **only the last generation per
post**, so multiple actual Property updates between CSS publication and
delivery could erase intervening source-change records. A certificate
could not prove that an unrelated Property save was harmless.

Source 421 now additionally records a bounded **512-event, ordered, versioned,
root-WordPress-owned event journal**, retaining every original `mark()`
generation, scope and numeric ID in the exact original order. The existing
global, listing and per-route generation bumps remain unchanged. On an
unknown/jumped prior generation the journal resets to a one-entry
starting point; it can never synthesize missed history. Writes are
server-side WordPress options, non-autoloaded; no visitor entrypoint.

The 422 V2 companion retains the original strict listing-generation check
for all archives, packages, Nile cruises, Properties and page/templates.
Only the **existing** independently bounded day-tour case may use the new
journal when the older per-key history map has overwritten intermediate
generations. It verifies all interval events are consecutively ordered
and exclusively Property updates; that the edited tour is published with
`tour_mode=tour`, contains no known cruise, program, related property,
JetEngine relation, Property ID or title, unsafe nested accommodation
metadata, or dynamic Jet listing grid; unknown data or missing interval
generations aborts to native CSS. The original 422 global generation,
singular route revision, CSS epoch, market/currency, Elementor DOM document
fingerprint, exact stylesheet order and byte hashes, CSS critical assets
and original independent 5-width Chromium evidence remain unchanged.

The new Git-approved 421 + V2-422 companion must be installed as an
**exact pinned pair**. Mixed old/new components are rejected by the
first-party renderer and original Worker. To avoid an arbitrary churn of
already valid five-viewport certificates on installation only, the
new companion explicitly recognizes one immutable **legacy exact V2
consumer SHA** in already published 422 certificate receipts, and its
own exact new Git SHA. Rendering/CSS transformation code is not changed.
Neither arbitrary SHA nor undocumented consumer release is allowed.
The original certificate and all CSS source/current dependency checks
still apply, and the 6h provisional lease cannot be extended.

This is a **scoped rescue of falsely invalidated simple day tours**,
NOT global epoch bypass, automatic family certification or coverage
completion. A genuine CSS epoch or header/template generation change,
new package/property relation, stale CSS file/market, or any otherwise
unproven client still forces original CSS/QA. The original postrelease QA
also remains strict on any source drift; this migration does not
claim to have changed its independent browser admission contract.

Original CPU-only PHP 8.5.12 tests: 21 ordered real event / strict tour
source cases and 14 original 422 legacy cases. Source and Wayfa
first-party pair-gate fixtures: 14 fail-closed cases with old/new SHA,
unapproved/mixed releases, plus the original 24 health tests. Test
fixture calls mocked WordPress functions; no production post, SSL/cache
or payment content is modified.
