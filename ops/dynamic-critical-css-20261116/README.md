# MAD4B dual-site dynamic Critical CSS certification

Both companies have the native option mad4b_perf_critical_css_v1=on.
An option does not represent public admission: a changed LiteSpeed CSS SHA,
Elementor source or source CSS epoch may invalidate earlier evidence.

Permanent default workflow:
- Read trusted company WordPress host/environment, WPML active languages,
  Elementor native mobile breakpoint, CSS generation and last content change.
- Enter a 10-minute source quiet period rather than testing while CSS is
  changing. Keep the native complete stylesheet on any drift or failure.
- Fetch the actual anonymous mobile HTML for each active WPML homepage and
  compare its linked CSS file content SHA with that company's manifest. A
  current native-certified CSS bundle skips repeat expensive browser work.
- Process up to two uncovered languages per cycle. Extract used CSS from
  browser coverage and generate content-addressed Critical CSS with explicit
  bounds, then compare normal versus candidate on throttled Chromium.
- Require complete interaction, FCP/LCP/CLS, visual geometry, CSS load and
  JavaScript-error checks. Never publish if the source changed during QA.
- Publish using the existing exact-hash/CAS guarded publisher, preserving a
  backup of the prior manifest and preventing cross-company publication.
- Original native transform is always enabled as default. On rejected/stale
  evidence it automatically falls back to the original full CSS.

Tours.dev: v2 adaptive runtime at 767px, requires both 767 and 768 tests,
CSS source and current Elementor document graph. Site is Staging.
Wayfatours: preserve original v1 low-priority/no-preload strategy,
and its exact PHP SHA. Site is Production. Do NOT replace its original
Critical CSS consumer with Tours.dev's plugin.

Two separate systemd timers retry every fifteen minutes and share a global browser lock
to avoid overloading the VPS. Each run saves the evidence, CSS source hashes,
QA cases, old manifest and publication receipt under a company-specific path
in /opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116.

Scope: anonymous mobile company homepages and their active WPML locales.
Other pages and desktop still have native complete CSS, not a falsely
promoted critical certificate. Origin TTFB and edge cache state have separate
performance gates and are not implicitly declared complete.

## Mobile/tablet native performance parity v0.3.28

The adaptive source renderer's former global high-priority complete-CSS
preload was competing with the native 768px tablet paint. The revised
native source restricts full CSS preload to the Elementor mobile media
query, defaults the full native CSS priority at tablet widths, and
continues to serve the blocking full stylesheet at widths >=768px.
The browser verifier now mirrors the exact scoped preload, media
queries and inline CSS loader/fallback. The separate Wayfatours
original consumer/strategy is unchanged.

A saturated VPS can bias Chromium LCP/FCP and produce unsafe or noisy
release evidence. A first-party load admission gate skips the heavy
browser stage and relies on the hourly timer to retry after pressure
decreases, while never changing visitor CSS. No QA checks are waived.

## Resource-gated retry cadence

Site-specific, staggered 15-minute systemd timers. Before crawling WPML
homepages or launching Chromium, check the genuine first-party WordPress
CSS epoch and the VPS one-minute run-queue load. Under >1.75 queued tasks
per CPU core, return a successful HOLD with original CSS intact. A
completed browser QA pass also requires a second independent load check
at final publication. An expected negative QA decision is HOLD, not a
failed systemd unit or a forged positive certificate.

If the source changes during Chromium measurements or last-moment CSS
readback, keep the previous manifest untouched. All holds are retried
automatically on the next timer; company browser stages cannot run at the
same time because they share the native browser admission lock.

## Permanent Wayfatours first-paint asset and home FastCGI fix

The original Wayfa v1 publisher emitted correct CSS SHA files but in mode
0600 under the worker's umask 077. That prevented PHP and Nginx from
reading the files and caused Ready:0 on a cache MISS. We explicitly set
and verify public static 0644 mode before publishing each CSS asset.

Wayfatours EN/ES homepage HTML also uses a static FastCGI epoch. Following
fresh, validated v1 publication, a Wayfa-only updater checks the published
manifest, both live CSS byte hashes and public read permissions, then bumps
just the / and /es/ home-key map, tests Nginx configuration, and reloads
only Wayfa's Nginx. It backs up the old config and restores on failure.
No tour, archive, WooCommerce or private-state cache keys are changed.

When an already-published EN/ES manifest is valid but FastCGI still serves
Ready:0, the recurring worker detects both current verified manifest SHA
entries, rebuilds a read-only inventory from them, rotates the homepage
namespace if needed and rechecks the public pages. No repeat browser QA or
new certificate is fabricated for an already-valid asset.

## Browser CSS-variant parity — final certification gate

On Tours.dev, LiteSpeed emits different CSS bundle files for real Chromium
and for curl, even with the same User-Agent and Accept text. A previously
successful 10/10 browser QA was repeatedly refused by the last-minute curl
readback because its CSS SHA and Elementor document set were a different
server-negotiated variant. That was **not** evidence that source changed;
it was a mismatch between two independent request-family variants.

Use tools/probe-public.js to fetch exact public anonymous Chrome mobile
HTML at a fixed 390px/deviceScaleFactor=2 and the same Chrome UA/Accept as
capture.js and verify.js. The runner uses this first-party Chrome proof:
1. To select only missing/current locale certificates (no repeated QA when
   the same CSS bundle is already certified).
2. To recertify current source hashes and Elementor graph immediately after
   the measured browser session, with independent, new Chrome contexts.
3. To recheck Wayfatours after a homepage-only cache-key renewal.
The source identity guard still fails closed if CSS SHA, Elementor graph,
route/page ID or actual source CSS bytes differ between Chrome observations.

The paired QA still enforces original/variant FCP, LCP, CLS, rendered item
geometry, JS console errors, full CSS completion, and interaction parity.
A fixed 7400ms delay sometimes ran before the native async CSS onload/6500ms
fallback had completed in a CPU-throttled browser. QA now observes and waits
up to 5 additional seconds for the actual native loader to deliver the
full stylesheet; it does not force-set CSS media, remove fallback, modify
timing metrics artificially or waive any performance threshold. A candidate
which does not finish loading still fails.

## Auto-cover every enrolled WPML language, without starving other locales

The two company's native WordPress WPML registry is the ONLY authority for
enabled language selection. The source controller reads active languages and
the actual WPML default language each cycle (up to 20 safe two-letter
language codes); it does not rely on a manually edited EN/ES/FR/DE/AR/IT list.
It verifies the resolved / or /lang/ homepage path against that registry and
the anonymous Chromium DOM fingerprint. New languages automatically join
the certification queue; disabled languages leave it.

Locale certification is independent:
- The first browser cycle captures and QA-tests up to two still-uncovered
  languages; each has five independently measured responsive widths on the
  adaptive staging source (320, 390, 414, 767, 768), or four native Wayfa
  widths (320, 390, 414, 768).
- The selected language's source SHA, CSS epoch, WPML route, candidate
  critical CSS SHA, Elementor document fingerprint, current real Chromium
  HTML and actual present PHP consumer must match before publication.
- A failed locale cannot invalidate an independently passing v2 locale.
  The published manifest includes only complete passing locale evidence.
  Each stalled locale gets a bounded 15/30/60/120/240-minute backoff
  keyed to its actual CSS source SHA and source epoch, with automatic retry.
  A changed CSS bundle or Elementor CSS epoch immediately invalidates the
  previous cooldown, so coverage grows with new content.
- The persistent company-specific private coverage ledger is under
  reports/dynamic-critical-css-20261116/{site}/coverage-progress.json.
  Never use another company's or earlier CSS generation's progress.
- A locale without trustworthy public HTML or valid performance proof
  **retains original complete native CSS**; that is coverage with safe
  fallback, NOT a false claim that Critical CSS is public-ready.

The business has six enrolled WPML languages on Tours.dev currently: EN, ES,
DE, FR, AR, IT. Wayfatours has EN and ES. A locale-specific LCP or CLS
regression must be resolved through a new measured browser strategy rather
than by weakening the thresholds or marking the language ready prematurely.

## Inherit hero and responsive first-paint eligibility from active WPML

An independent image preload problem was discovered in the non-EN/ES source
homepages: the installed Delayed Hero plugin had two literal WordPress
allowlists with only EN and ES, while WPML had six active languages. This
made mobile image priority and the below-fold first-city preload behave
differently for FR/DE/AR/IT, even when CSS generation itself was correct.

The governed first-party MU bridge
wp-content/mu-plugins/420-mad4b-wpml-first-paint-auto-coverage.php
does not edit or replace the installed Delayed Hero plugin, its mode, media,
poster rules or WPML translation content. Only the two exact installed
WordPress option filters inherit the current validated WPML active language
registry when the company's WordPress option
mad4b_perf_wpml_first_paint_all_languages_v1=on.
The company host is pinned to the enrolled Tours.dev or Wayfatours,
up to 20 native two-letter WPML language codes with a valid default.
Missing/invalid WPML data, disabled company mode, or a foreign host all
retain the original stored allowlists. Both native mobile_hero_priority
and responsive_lcp_enabled still enforce their existing per-route,
public-device and company mode conditions.

The bridge does not mint any Critical CSS certificates. Existing browser
performance, CSS SHA, Elementor graph, true UA/cache isolation, and
per-language LCP/CLS protection stay in force. The source-only staging
activation should be measured before enabling a different company policy.

## Independent per-language admission and exact measured viewport contracts

Live Chromium capture uses [320,390,414,768] to construct CSS.
Measured paired browser QA on the adaptive v2 source additionally tests
[320,390,414,767,768]. The previous admission validator accidentally
required the extra measured 767px viewport to be part of capture, which
held legitimate ten-pass browser sessions as incomplete content-addressed
QA input. Admission now requires exactly the four source capture widths,
and independently requires all five QA widths to pass (four for native
Wayfatours v1); no performance threshold was relaxed.

The native v2 publisher uses admitted-candidates.json only when the
runner explicitly passes the exact admission filename, with validation
against the complete original capture inventory and the per-language QA
evidence. It refuses foreign languages, changed candidate source/critical
CSS SHAs, altered admission reports, path injection, and any v1 legacy
subset publication. It preserves raw candidates.json and verification.json
unchanged, so failed sibling locales do not obstruct safe v2 publication.

Stale CSS/Elementor variants observed later are not promoted on previously
passing evidence. Last-moment anonymous Chromium source checks compare real
HTML/CSS bytes and Elementor documents; a different variant requires a
new measured certificate. The source v2 manifest can retain multiple
content-addressed certified CSS variants for the same locale and CSS epoch,
with safe native fallback on unmatched variants.

## Partial WPML crawl isolation and truthful readiness

Anonymous Chromium discovery now collects public HTML/CSS evidence independently
for each active WPML locale. A single broken, unpublished, or temporarily
unreachable language produces a locale-scoped HOLD and does not prevent
healthy locales from entering their browser certification queue.
A crawl with zero successful CSS sources still fails closed; partial
failures never fabricate critical CSS certificates.

When no certifiable batch remains, the runner prints READY only if every
active WPML homepage has a current native certificate. Any held,
unreachable, or deferred locale produces a truthful coverage HOLD instead
of the former misleading all-languages-ready result. The on-site native
CSS fallback is still available to each held language.

The deterministic fake-Chromium regression suite checks that one or
multiple failing locales cannot block a healthy neighbor, and that an
all-failed source crawl remains blocked.

## Per-language source drift is not a global coverage failure

An independently changed CSS SHA, homepage route, or incomplete capture
invalidates exactly that language's admission. Other languages with the
original measured CSS and fully passing QA retain their eligible
candidate records, and the v2 publisher's original-subset SHA fences
still validate every independently accepted item.

The entire run continues to reject wrong site/host, duplicate candidate
languages, foreign admission records, or untrusted QA evidence.

## Automatic source staging public HTML cache release after certification

Successful Critical CSS publication alone does not change previously cached
FastCGI Ready:0 HTML. The source staging Nginx cache uses an authoritative
path-generation socket. The first-party source-home-cache-release.py is
restricted to the installed exact Tours.dev WordPress root and the active
WPML homepage path registry; it accepts no arbitrary URLs, user-supplied
countries, WooCommerce endpoints, or wildcard cache purges.

After an actual v2 certificate publication, it checks the installed
manifest, locale, source CSS bytes/SHA, public 0644 Critical CSS asset SHA,
adaptive 767px QA evidence, and exact current CSS epoch. It versions ONLY
the proven homepage paths through the existing original local socket.
The runner then independently opens those exact pages in a fresh Chromium
session and checks native Ready:1 and per-locale Elementor/CSS SHA matching.
A failed cache/version acknowledgement or public readback remains HOLD.

On the next run, if a still-current manifest entry matches the live
source/Elementor graph but a stale cached page reports Ready:0, the
same helper may recover only that already-certified locale's homepage
without recreating or bypassing its performance certificate.
The production Wayfatours native v1 FastCGI home-version path and its
published EN/ES Critical CSS behavior remain unchanged.

## Automatic submobile LCP recovery — 599/600px + native 767/768px proof

When the default native 767px critical-CSS boundary fails LCP
specifically at 767px but passes 320, 390, 414 and 768, the source
worker automatically attempts a narrower 599px first-paint cap. This
is a second, separately measured strategy. Elementor's native 767px
responsive breakpoint is never changed. The complete LiteSpeed CSS
is render-blocking above 599px, avoiding the delayed JetEngine
first-city background LCP on the intermediate 600–767px widths.

Narrower strategy MUST pass all seven independent Chromium viewports:
320, 390, 414, 599, 600, 767 and 768. Missing even one or failing
the LCP/FCP/CLS/layout/interaction gate keeps that locale on native
CSS. A passing original locale is not delayed or replaced by another
locale's failure. The primary failure report is permanently retained
as verification-primary-767.json and the independently passing
fallback is saved under safe-cap/verification.json; safe-cap-fallback.py
merges only passing locale gates.

Runtime v0.3.29 requires original Elementor mobile breakpoint=767,
the exact source CSS SHA and page/document/locale/epoch, and a special
seven-width proof for adaptive cap 599. The final HTML cache-readiness
header accepts 599 only when THIS request already completed a verified
Critical CSS rewrite, to prevent a LiteSpeed internal cache hit from
claiming an unverified public certificate.

The publisher independently checks the full seven-viewport measured
QA, exact newly deployed first-party PHP consumer SHA and WPML site
registry. Company-specific FastCGI generation is rolled only for
the newly certified home paths, after verifying exact live CSS and
critical asset hashes. Wayfatours' original v1 CSS certificate
and manifest contract are not changed.

## Dynamic coverage assessor distinguishes native Elementor width from safe CSS cap

The source v2 homepage assessment must recognize BOTH independently measured
strategies: existing 767px Critical CSS and separately seven-viewport
qualified 599px first-paint cap (Elementor native breakpoint remains 767px).
Without this proof-aware lookup the scheduler reports already active
DE/IT pages as uncertified, needlessly remeasuring them forever.

The inspector now binds source host, locale, CSS SHA, epoch, Elementor body
document fingerprint, exact seven QA widths (including 599/600 and
767/768), current native breakpoint, actual injected HTML media boundary,
and critical asset filename/bytes/SHA before returning certified_active.
Incorrect or stale source, script/media markup, certificate, or CSS
asset receives the normal safe fallback classification. The unchanged
Wayfatours v1 inspector still relies on its separately admitted existing
CSS manifest contract.

## Automatic public route CSS coverage, event-driven qualification (Nov 17, 2026)

The previous native home CSS certificates remain owned by the 415 runtime:
six home locales on Tours.dev, two home locales on Wayfatours. The new route
runtime never overrides these home CSS contracts.

The existing home timer now compares source-dependent SHA fingerprints of
the actual LiteSpeed CSS bundles, native 415 consumer, manifest, Elementor,
WPML, active kit and the affected CSS epoch before launching Chromium. A
healthy unchanged generation does not run browser QA on every timer tick.
Its normal daily integrity check is a bounded filesystem/source comparison;
weekly browser regression is reserved for silent drift.

The 421 MU plugin discovers real WPML URLs for all published, public,
unprotected pages, tours, property profiles and taxonomy archives.
WCML cart, checkout and account pages are excluded in every translation.
It maintains dependency generations for Elementor, media, itinerary, rate,
taxonomies, WPML and dynamically rendered listing children. Changes to one
post do not automatically invalidate unrelated static pages.

The 422 MU plugin keeps anonymous public Critical CSS enabled at the
feature-policy level, but transforms only when a page-specific five-width
Chromium certificate matches its exact route, locale, WordPress object,
current currency, trusted market, CSS SHA, Elementor graph, CSS epoch and
content/listing revisions. Any missing proof uses original full LiteSpeed CSS
without throwing, suppressing the complete stylesheet or caching private HTML.

The automatic worker run-public-route.py chooses at most one route per
cycle, checks baseline vs altered FCP, LCP, CLS and interactions at 320,
390, 414, 767 and 768px, and requires a final live public browser readback
before marking it certified. Credentials, user-defined URLs, WooCommerce
checkout and query-bearing routes are never accepted. Routes are selected
from authenticated WP-CLI inventory only. Queue source dependencies include
the native plugin SHA so that future runtime releases requalify earlier
blocked categories automatically.

Tours, Properties, destinations, taxonomy listings and related archives
now enter the same exact-route certification queue, subject to a real,
independently verified WCML country/currency and five-viewport performance
proof. Missing native CSS, a mismatched WordPress object ID, missing market
identity or unsafe interactive behavior retains the original native CSS.
Neither a homepage nor another route's success is inheritable.
Original first-party HTML cache market admission is unchanged and may
remain HOLD.

Two new company systemd services and staggered timers are installed from
the systemd folder, reusing the global browser lock and CPU admission. No
bulk concurrent Chromium sweeps or unconditional 15-minute full retests.

### Verified route types (first-party)
Exact body identifiers are required: page-id for normal pages, postid for
tour and ship singles, term-ID for localized taxonomies, and the precise
post-type-archive-* class for CPT archives. A term ID is never coerced to a
post ID, and an archive cannot invent an object ID.

JetEngine grid and visible-price HTML on any page requires server-owned
WCML country and currency, not just locale or WordPress CLI base currency.
Changing country with the currency unchanged cannot reuse a different
certificate. Relevant image, itinerary, rate and taxonomy edits invalidate
dependent route generations only. Original native CSS and all market-aware
HTML cache admission gates remain independent safety mechanisms.

WooCommerce product/archive scope is ready for future separate enrollment
once real published products and their live interactions exist. Checkout,
cart, account, payment, query-bearing and other private endpoints never
inherit public CSS certificates.

## Public-page cache generation after runtime and certificate changes

The first-party route worker intentionally does NOT purge LiteSpeed or the
company CDN globally. A public page can retain a pre-runtime HTML
generation that lacks country/currency headers even after the 422
native runtime or its certificate is installed. That stale HTML cannot be
used as new browser certificate evidence.

Before each selected, eligible public route capture, the worker reads the
authenticated WPML catalog and versions only that exact non-home path via
the already-installed local company FastCGI generation socket. It then
measures the fresh anonymous rendered page.

After the per-route FCP/LCP/CLS and interaction gates have passed, the
publisher issues one content-addressed certificate bound to host, language,
route, WordPress queried object and exact revision, CSS SHA, Elementor
graph, currency and independently trusted market. Before exposing the
new certificate, the worker checks the installed certificate, current
source stylesheet bytes, critical asset bytes, trusted market/currency
and route revision. Only then it versions exactly the certified path
again and performs a fresh real public Chromium readback.

An unavailable socket, wrong company root, checkout/cart/account route,
stale country/currency/route generation, or changed CSS asset produces
a safe HOLD; no wildcard purge or invented public-ready claim.

## Negative performance evidence and immutable runtime certificate identity

The site-wide public CSS policy is on by default, but each exact route,
language, currency and trusted market is independently optimized only
after a positive 5-width Chromium measurement. Two separate, complete and valid
performance measurements proving no meaningful FCP gain never issue
a Critical CSS certificate: instead a first-party native_preferred
result is preserved in the same content/locale/runtime/epoch dependency
ledger only after both measurements agree. The page keeps original full LiteSpeed CSS. This is NOT an
approval to weaken performance or interaction gates.

The native_preferred ledger result skips repeat browser tests on
15-minute timer ticks. A CSS epoch change, Elementor/global generation,
post revision, WPML or dependent first-screen listing change invalidates
it and opens a new measurement. Partial QA, failed interactions, source
drift or missing market proof always remain HOLD, not negative evidence.

Critical CSS certificates additionally bind the original published
422 PHP consumer SHA256 to the specific Chromium evidence; updating the
consumer automatically invalidates older certificates and falls back to
the original CSS until compatible new certificates pass QA. The same
SHA binding must survive the exact-path cache generation release, so
publishing an old certificate is never silently accepted.

## Reuse already-active page certificates without measuring the same page twice

When a public route was previously held because its post-publication
Chromium readback failed (and the live PHP runtime still has the exact
SHA256 that issued the certificate), subsequent jobs must first test whether its
earlier passing certificate is actually active. Re-running FCP/LCP against
a baseline that already contains the same Critical CSS yields meaningless
near-zero gains and an artificial QA failure.

For routes with existing certified/HOLD queue history, the first-party
worker performs one real anonymous Chromium readback. Its resume helper
does NOT mint or modify a certificate. It requires an already installed
five-viewport passing certificate with matching company root, WPML route
and post ID, exact WordPress revision, active language, CSS epoch, trusted
server market/currency, listing/global dependency generations, original
CSS SHA, Critical CSS SHA, identical native runtime SHA256,
Elementor document graph SHA, inline CSS byte
identity and the full native CSS media breakpoint/loader. Fresh WP-CLI
source/route generations must still match after browser readback.
Only the single observed market/currency can be considered certified.

If any dependency differs, retain the previous source-side QA decision and
perform the normal full browser certification of the newly changed page.
A preview, user/cart context or missing runtime certificate can never
inherit a different visitor's proof. The original unoptimized full CSS
remains the standard fallback for any unqualified request.

## Public certificates must be readable by the live WordPress PHP UID

The route worker's systemd service uses restrictive UMask=0077. With
only mkdir(mode=0755), freshly created per-route certificate and CSS
asset directories were root-only (0700), so production PHP (www-data)
could not traverse them. The publisher now uses no-follow directory
descriptors and explicit fchmod(0755) for the exact public CSS root,
assets and certificates directories only, after successfully verifying
the independent route evidence. JSON certificates and content-addressed
critical CSS files remain 0644. It never touches private reports,
credentials, uploads, checkout or general WordPress directories.

The published file must still match the current LiteSpeed CSS asset
filename and SHA on the live request; after regeneration into another
bundle, the full native CSS is retained until a new proof is admitted.
Directory accessibility alone does not imply a valid page certificate.

## Fair company-wide route coverage across languages, services and CSS epochs

New public routes retain the site's default-on coverage policy and
are discovered dynamically from the authenticated WPML registry.
One Chromium operation runs at a time under the shared host lock.
A source/Elementor/CSS epoch change can invalidate existing page
certificates, but must not monopolize every browser cycle indefinitely.

The durable company-specific route ledger now keeps a four-slot
scheduler_sequence, updated only when a route result is recorded.
When both queues have eligible work, three slots are reserved for
never-before-tested page/language/type combinations and one for
previously tested routes whose source changed or whose retry
cooldown expired. New routes are selected fairly by count of prior
attempts within their exact route-type/language group, with
deterministic native-priority ordering as a tie breaker. Previously
attempted routes are prioritized oldest-first in the maintenance slot.

A healthy certificate or proven native_preferred page with unchanged
dependencies is never retested merely to advance a timer. A changed
WordPress route, WCML-safe render context, Elementor/global dependency,
CSS epoch, runtime file or first-screen listing causes automatic
requalification under the source-key contract. A failed or incomplete
browser test retains full native CSS. No change to WooCommerce, payment,
shipment or per-market isolation rules.

## Permanent read-only stability monitoring

An independent daily systemd integrity auditor reads the live, authenticated
WPML WordPress catalog and actual Critical CSS runtime for both registered
companies. It does not run Playwright/Chromium, alter WordPress options,
publish certificates, reset caches, or invalidate private/commerce routes.

The audit checks original site consumer SHA, public 421/422 runtime parity
with the local integration Git source, secure public asset directory mode,
enabled home and sitewide timers, private ledger state against fresh
WordPress CSS epoch/Elementor generation/WPML languages, and anonymous
browser-like GET response headers. The public readback is separately
identified by trusted market/currency; a certificate from USD is never
reported as active for EUR. It reports active homepage Critical CSS,
safe native fallbacks, progress/backlog, route attempt staleness and
published CSS certificate counts.

Reports are written atomically mode 0600 under
/opt/mad4b-company-provisioning/reports/critical-css-health-v1/latest.json
plus one timestamped audit. The systemd unit has UMask=0077, a bounded
15-minute timeout, no-new-privileges, private temp, read-only base system
and no broad filesystem write permissions. It exits nonzero for infrastructure
integrity errors; safe CSS fallback/pending work is reported as DEGRADED
without attempting an unsafe automatic CSS promotion.

This does not replace per-route Chromium FCP/LCP/CLS certification or
claim blanket coverage of every WPML market and currency.

## Italian 767px and adjacent 768px performance remediation

An immutable source Chromium measurement at CSS epoch 129 established that
Italian /it/ gained >2s in FCP for smaller mobile widths but suffered a
significant 767px LCP regression and a small 768px FCP/LCP regression.
The earlier automatic 599px test selector required 768px itself to PASS,
so it never attempted the independently measured seven-viewport fallback.

The selector now admits this BOUNDED edge-case for an independently measured
599px candidate only when 767px failed specifically due to a real LCP
regression and 320/390/414 all pass, while 768's regression is limited to
at most 500ms FCP and 400ms LCP without browser errors. This does NOT
certify Italian automatically: the replacement must still pass complete
320/390/414/599/600/767/768 FCP, LCP, CLS and interaction gates, exact
live source hash, responsive breakpoint identity, source route/Elementor
proof, and fresh Chromium public readback under the current CSS epoch.
The Elementor 767px breakpoint is unchanged; 600px+ retains normal CSS.

## Permanent correction: first-party QA code revisions and Wayfa home menu drift

The first-party Critical CSS event gate now fingerprints source QA logic
(run-site, browser verify and probe, publisher, final-source verifier,
coverage-queue) and the company-specific admission engine: safe-cap
fallback for Tours.dev, exact home cache preflight for Wayfatours.
Changing QA behavior causes requalification in the next eligible cycle,
rather than retaining an old QA HOLD for an hour or a ready result for
seven days without examining the new behavior. Code is always read
locally and never fetched or executed from a browser result.

Wayfatours had unchanged combined CSS but an 220KB difference in the
Spanish Elementor navigation HTML between first and final Chromium
snapshots, including different activated destination menu items and
Elementor document IDs. An old FastCGI HTML generation was one
possible source. Before collecting proof after a trusted event, the
Wayfatours home worker now versions only the two registered anonymous
EN and ES home routes, once per exact source epoch/home dependency/
runtime-version identity. This uses the existing native origin Unix
socket and is not a general cache purge or Nginx rewrite. The preflight
is idempotent and fail-closed: unavailable socket, modified site
identity, unexpected languages, tampered state or bad publisher
sources retain full native CSS. The *final* browser HTML and CSS SHA
proof must still pass unchanged; the preflight cannot certify a menu
that genuinely changes during QA.

## Self-maintaining, non-destructive browser evidence retention

The weekly Critical CSS evidence retention timer runs only when the global
Chromium lock is idle. It never touches production WordPress content,
certificate manifests, live CSS assets, invoices, private user data or
payment routes. For each of the two named company report families, it
preserves the three newest run directories and all detail newer than five
days. Older timestamped runs are compressed to a 0600 tar.gz under that
company's private report/archive folder with a sidecar 0600 receipt
containing every original file SHA256. Every archive member, byte length
and SHA must verify before the original run is removed. The entire
operation refuses symlinks, unsupported file types, insufficient disk
space, archive collisions, or files changing mid-archive.

Archives and receipts remain available as provenance for historic
source-bound Chromium tests; no automated deletion of those proofs is
performed. The weekly timer has a 35% CPU cap, 256MB memory cap and
uses the same cross-company Chromium lock. A dry-run never alters the
report filesystem.

The daily audit also reports size of both browser report families,
raises a warning above 15GiB and logs degraded/error health to the
system daemon journal at warning/error priority. External email delivery
is NOT attempted unless the SMTP recipient and delivery admission
are separately verified; the local alert is present without bypassing
the existing WordPress production notification gate.

## Service/destination activation and dynamic Elementor menu invalidation

The first-party 421 WordPress dependency controller now listens to the
actual JetEngine option stores service-activation, destination-activation,
product_navigation_sources and look-and-feel. Changing, adding or deleting
these settings increments public global CSS + first-screen/home dependency
generations and dynamic listing generations. This is essential because
the Wayfatours Elementor mega-menu topology and country/destination
choices can change with NO combined CSS byte change.

Native WordPress nav_menu item title/order/URL meta changes and nav_menu
term updates are included as first-paint dependencies. Destination/
service taxonomy labels and visual taxonomy media changes now mark
the shared header dependency, while private payment settings, WP editor
locks and unchanged option values do not trigger recertification.

The route catalog still excludes internal WordPress navigation taxonomies
and commerce/private endpoints; only public WPML page/tour/property/
destination/archive routes enter the browser QA queue. Updating this
dependency tracker itself does not manufacture a CSS certificate.
The source home worker's event gate observes a new home dirty generation;
the public route worker observes global/listing generations and rejects
stale certificates. The original CSS remains active until the actual
independent Chromium proof succeeds.

## Real Chromium mobile browser versus a safe generic HTTP cache variant

A generic mobile curl request can negotiate a different Nginx/LiteSpeed
cache/client-hints variant from a real mobile Chromium page. Therefore
X-MAD4B-Mobile-CSS-Ready:0 alone is NOT evidence that the latest passing
certificate is unavailable to Chromium or that the site has a production
failure. It can be a safe original CSS fallback for that request profile.

The daily integrity audit now keeps BOTH observations. When a generic
HTTP profile receives native fallback, it attempts read-only validation
of the latest <=7-day stored first-party Chrome HTML/headers from the
same site. It calls the original home CSS classifier against the CURRENT
content-addressed source CSS file, certificate manifest, page Elementor
document fingerprint and exact CSS epoch. The site WP-CLI first-paint
dependency generation and active WPML language set must also equal those
recorded during the Chromium measurement. If and only if the real mobile
Chrome proof is still actively certified, the audit reports status
healthy_with_safe_fallback, records the exact Chrome CSS SHA and tested
mobile breakpoint, and separately lists the safe fallback locale.

If no valid proof exists, its epoch changed, a dependency changed, the
browser snapshot expired or another company attempted to inherit it,
the normal degraded alert remains. Neither profile becomes certified
from this monitor; the real certificate publisher is unchanged.

## Recover automatically from concurrent WordPress CLI admission

The daily read-only audit intentionally shares managed WordPress
runtime with live source, editor, content and WPML operations.
Staging WordPress can return exit code 75 with the exact
STAGING_WPCLI_BUSY admission message when two authorized WP-CLI
sessions are already active. This is temporary resource contention,
NOT CSS corruption or a failing original certificate.

Only that exact combination (exit status 75 and explicit busy text)
is classified as deferred, preserving a fail-closed report and
returning exit code 5. The systemd Type=oneshot service has
Restart=on-failure, RestartSec=300s and StartLimitBurst=12 per hour to
retry without spinning. Real runtime/source errors remain exit code 4
with RestartPreventExitStatus=4, preserving the local error alert.
No user grants, WP options or production secrets are changed.

This avoids labeling an incomplete audit healthy or mislabeling
temporary WordPress CLI contention as CSS production corruption.

## Public-route browser admission: exclude the Posts index and reject volatile CSS

The WordPress assigned Posts Page is displayed through is_home(), even if
the navigation system stores it as a normal page ID. The original public
CSS runtime explicitly excludes is_home(), so this page and its WPML
translations cannot issue the server-owned country/currency headers
required for a public route CSS certificate. The first-party 421 route
catalog now excludes the assigned page_for_posts and its WPML translated
page IDs before enqueuing browser work; ordinary published pages, posts,
tour CPTs, taxonomies and archives remain eligible.

Before running CPU-intensive per-route FCP/LCP/CLS QA, the runner now
checks the four independently captured 320/390/414/768 source views.
Their exact LiteSpeed CSS filename, source bytes SHA256, trusted
server-owned WCML currency/country, route/object ID and Elementor graph
must match the single assembled candidate. If they differ or market
authority is missing, the page stays on its original CSS and the
first-party bounded HOLD code is retained in the private queue ledger.
This preflight cannot approve or issue any certificate.

A confirmed Wayfatours article had four distinct source CSS hashes:
JetEngine rendered different dynamic item selectors and background
URLs, not merely four names for identical CSS. Normalizing those CSS
hashes or inheriting a certificate from another tour would corrupt
the rendered image/selector semantics. Leave this real volatile CSS
source unoptimized until its WordPress/JetEngine presentation graph
becomes stable; do not override the source integrity gate.

The daily audit exposes HOLD causes independently of actual certified
routes and of client-hints safe profile fallbacks. All reason codes
are enumerated; raw browser exceptions and request values cannot be
injected into the ledger. A failed page is retried under the existing
bounded policy, and a genuinely revised source identity opens a new
admission immediately.

## Fail-closed QA evidence for zero-viewport public page holds (Nov 17)

A public page can change its trusted country/currency or combined CSS
source between the four-viewport capture and the subsequent browser QA.
Previously the browser emitted `QA_COMPLETE tested:0` without creating
`verification.json`, which hid the real HOLD as a missing-file exception.
The verifier now always saves the bounded gate result and closes its
prefetch browser context on both early-exit paths. The public worker
classifies only the explicit `source_html_drift` and
`country_or_currency_context_drift` statuses in the private queue.
No zero-viewport result can certify CSS. A missing proof remains a hard
HOLD, never a successful release. This is covered by a real Node verifier
regression using mock browser navigation (no live site changes).

## Existing infrastructure alert dispatch for fatal audit errors

The daily Critical CSS audit service reuses the installed MAD4B systemd
`OnFailure=mad4b-alert-handler@%n.service` channel. This is restricted to
terminal service failures, after bounded WP-CLI contention retries; it
uses the existing server-side rate-limited SMTP infrastructure and never
embeds credentials or recipients in the CSS source. A degraded CSS profile
still retains native CSS and is logged locally, not falsely reported as
a fatal outage. The notifier's `check` command validates configuration
without sending email. No test email is sent during installation.

## CPU incident: never perform unbounded raw-byte SequenceMatcher on CSS

On 2026-11-17 four detached `python3 -` shell diagnostics compared the same two
~2.47MB LiteSpeed CSS files using `difflib.SequenceMatcher(..., autojunk=False)`.
Each had run for over two hours, consuming nearly a CPU core, outside the
managed Chromium/systemd admission and causing benign Critical CSS queue HOLDs.
After exact process identity, parentage and orphaned-session verification, only
those four diagnostics were terminated; WordPress/Chromium services were not
stopped. The host's original load/capacity admission must not be relaxed.

For future large stylesheet comparisons use **only the bounded, linear-time,
read-only** tool instead of byte-level difflib:

```
/usr/bin/timeout 8s /usr/bin/python3 ops/dynamic-critical-css-20261116/safe-css-diff.py wayfa FIRST_32HEX.css SECOND_32HEX.css
```

It reads only enrolled staging/Wayfa LiteSpeed CSS assets, refuses symlinks,
oversized files and invalid content-addressed filenames, and has hard per-run
CPU and address-space limits. It outputs hashes, sizes, first-difference byte,
unchanged prefix/suffix and overlap mismatch counts (no full CSS content).
It performs no cache invalidation, remote fetch, WooCommerce changes, or CSS
certificate issuance. This prevents the exact repeated-byte quadratic
comparison trap; it does not claim to sandbox arbitrary SSH commands. Existing
low-priority systemd workers and the shared Chromium lock continue normally.

## Article-safe JetEngine per-item CSS source partition (Nov 17)

The shared First-Paint 406 MU-plugin (v1.0.9) now carries its *existing*
`litespeed_optimize_css_excludes` filter to the currently published, exact
canonical permalink for `is_singular('post')`, in addition to homepages.
The filter leaves `elementor-dcss-...` style blocks in original page HTML,
where their selectors and image/background values still belong to the
actually selected JetEngine posts, instead of folding their transient
selectors and images into a globally reusable LiteSpeed combined CSS file.
LiteSpeed's own `_parse_css()` checks the complete `<style>` block for its
CSS exclude strings; the filter does **not** remove content from the HTML.

Admission remains closed for unpublished/passworded posts, WordPress posts
index, preview, draft, AJAX, REST, users with sessions, non-GET requests,
query arguments, noncanonical paths or hosts, foreign WPML/blog context,
unknown cookies and commerce/market override headers. There is no sitewide
CSS exclusion, no breakpoint change, and no CSS certificate issued from a
source change alone. Source-backed PHP mock tests cover the admitted public
post and blocked variants; each changed live site requires a narrow cache
renewal and independent browser capture before any critical certificate.

## Operational health and fleet coverage are separate facts

The daily audit now emits `coverage_completion.status=rolling|complete`,
`fully_qualified`, `qualified_percent`, independent native-preferred counts,
and `coverage_by_language` alongside the existing engine `status`. Unseen,
HOLD, incomplete and stale source keys can never be shown as certified;
ledger certification does not assert the live market-specific consumer is
active. A healthy engine with 1% route coverage is still rolling. This
changes private read-only reporting only, not certificate admission, site
performance thresholds, notification gates or the publication runtime.

## Contact hero's native eager-loading guarantee

Plugin 406 v1.0.10 enrolls exactly `mad4b-contact-hero-photo` in the native
LiteSpeed `litespeed_media_lazy_img_cls_excludes` list on published canonical
public pages, preserving its already configured `loading=eager` and
`fetchpriority=high` from the original Contact shortcode. In the prior Spanish
Wayfatours 320px QA the full CSS candidate deferred this LCP image behind a
LiteSpeed 1x1 GIF/data-src transformation, yielding +452ms LCP. Other images
and gallery cards remain subject to native Lazy Load. The rule never applies
to admin, session, draft, query, noncanonical or non-GET contexts. Post-change
browser QA is independent of the unit tests and is required before promotion.

## Quiet-window coverage scheduling

Both companies now get up to eight staggered route-certification opportunities
per hour (previously four), but each attempt is admitted only when the
root-owned 45-second CPU-budget sensor is fresh (105s), the eight-vCPU
identity is unchanged, host CPU is under 50%, live/recorded load under 7,
steal under 20%, CPU PSI under 25 and fewer than two original Central WPCLI
sessions are active. Missing/stale/invalid telemetry fails closed. Each
company keeps its separate ledger, one Chromium global lock, per-job Docker
CPU/memory caps, original 313 protections, direct source parity checks and
original negative-QA/market/currency certificate gates. Deferred slots issue
no certificate and cause no WPCLI work. The faster schedule improves
throughput only when genuine spare CPU exists; it does not claim every
public route already passes Chrome QA.

## Source-owned CSS certification, v1: autonomous template canaries

The fleet no longer treats every WPML URL as an independent mandatory
performance-certification unit. WordPress's original source catalog now
classifies each URL by a `source_profile` rooted in published post type,
assigned page-template slug, taxonomy/archive or a separately authored
Elementor document. These are *candidate source origins*, not blanket proofs
of identical CSS, layout or LCP. WPML languages are discovered from the
active SitePress registry, never hardcoded to EN/ES.

`public-source-families.py` builds independent company + language + source
family generations from the live WPML catalog, CSS epoch, global/listing
revision, native breakpoint and exact original PHP consumer SHA. Every
source exemplar must have an ORIGINAL five-viewport QA certificate, current
route/Elementor/CSS hashes, matching server-resolved WCML market+currency,
valid immutable assets and the exact source-specific route ledger. A truly
shared template with multiple routes needs **two distinct** current Chromium
canaries with the same Elementor document graph and market before its source
behavior is qualified; individually authored Elementor documents and
singletons need one. Divergent Elementor graphs form a source split/hold,
not an inherited certificate. Failed, stale, untrusted or busy examples
never count. The source registry is written as a private root-only report and
shown in the source-level integrity audit, independently of URL metrics.

The permanent browser worker now prioritizes exactly one eligible **source
canary** from this registry, with the existing strict CPU/Chromium locks,
independent five-viewport FCP/LCP/CLS and marketplace proof. Completed source
families are skipped on recurring ticks. A content/Elementor/market/epoch
change automatically invalidates the affected lineage and reopens its
source QA. Autopilot is therefore bounded by source groups rather than
1,876 route-level QA runs.

**Important separation:** Source canary qualification certifies the shared
*behavioral source*. The existing consumer 422 still deploys a particular
Critical CSS asset only after the exact public route, live CSS bytes,
Elementor document graph, responsive breakpoint, locale, market/currency,
pricing/listing generations and its own original certification match.
No 1:many route CSS asset inheritance, no fake Ready:1 headers, no weakened
negative QA or arbitrary cross-company grant is allowed. Unproven route
variants automatically retain the original complete CSS. Source-level
certification is not a claim that all routes have been performance-optimized.

### Independent source-register autopilot (no browser lock)

`refresh-source-families.py` independently reconciles the authoritative
WordPress source graph, original 421+422 PHP byte parity, WPML locales,
CSS epoch, original signed QA canaries and their immutable assets for **both**
companies. It writes root-private `source-families-latest.json` atomically
inside each company's evidence directory; it never issues a certificate or
modifies a visitor's page. The persistent systemd timer retries every 30
minutes only when the existing eight-vCPU budget grants quiet WPCLI slots.
The browser certification workers remain separate and use this same group
algorithm to choose an eligible source canary rather than 1,876 route runs.
A fresh report lists `source_canary_pending` and `source_canary_qualified`
separately from actual per-route CSS-ready proof; no pending group is styled
using another route's CSS. No operator action is needed after source changes;
failing QA retains native CSS and next scheduled source slot is considered.

### Bounded negative source canaries

A shared family stops allocating fresh route canaries after four distinct
attempts at the same dependency generation. Existing failed canaries may be
rechecked only after their original exponential cooldown; any changed
source generation resets the bound. If Elementor document fingerprints
diverge, the family is marked `source_variants_need_split` and kept on native
CSS rather than assigning another unrelated route's certified stylesheet.
This prevents a 597-property shared template from silently reverting to
hundreds of independent Chromium QA jobs when one source misbehaves.

### Native-only source cohorts with no eligible combined stylesheet

If all four exact Chromium capture viewports return HTTP 200 but expose zero
combined CSS files, `optimizer_bundle_missing()` records the fixed
`source_css_bundle_unavailable` HOLD reason. It never fabricates a critical
CSS candidate, rewrites HTML or treats the result as a successful Chrome QA.
The source coordinator can sample up to four different routes per unchanged
dependency generation, then reuses cooled-down failed canaries rather than
probing an entire group of tours. Source changes reopen certification.

### Observed HTTP variant is not the measured Chrome CSS profile

The daily audit must not treat a generic curl `Ready:0` as proof of a broken
Chrome certificate: LiteSpeed legitimately negotiates different combined CSS
hashes for the same anonymous market. When a published market-specific cert
exists but the generic HTTP variant serves original blocking CSS, audit reads
bounded, root-local ORIGINAL Chromium readbacks and invokes the exact 422
`public-route-resume.ok_cert()` validator against current WP source state,
Elementor document set, source CSS bytes, immutable certificate, current
listing generation, language and WCML market/currency. Only current valid
proof is reported as `native_http_variant_with_current_chromium_proof` and
`healthy_with_safe_fallback`; neither the generic variant nor any other route
gets a false Ready:1. If proof is expired/missing/mismatched, the original
failure warning remains. Event-driven invalidation which correctly makes
historic certificates stale is separately reported as an expected native
CSS fallback, not a broken optimizer. Individual route CSS-readiness is still
reported distinctly from source-family qualification.
