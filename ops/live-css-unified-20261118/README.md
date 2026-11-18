# Live-first company CSS delivery: November 18, 2026
## Installed on original company environments
- Wayfatours Production: remove legacy 378 CSS-combine veto on tours. Keep the original LiteSpeed combine/minify enabled; the unchanged native style order remains part of the single generated stylesheet.
- Tours.dev Staging: 424 optimizes CSS in private public pages without granting shared HTML storage and without touching native JS behavior.
- Both: 424 accepts only native HMAC-verified signed country/passport preference cookies for eligible non-private-commerce GET routes. Unknown/invalid/Woo cookies cannot open the signed-preference optimization path; HTML remains private/no-store.
- Wayfatours anonymous public HTML retains original FastCGI and independently certified Critical CSS logic. Wayfatours signed mobile Home retains original 423 controller.
- 424 supports per-company opt-out via `mad4b_private_html_css_bundle_v1=off`.
- Original coupon/order/cart/payment behavior and WooCommerce original scripts are unchanged.
## Source-family continuity
The original critical-css engine's old negative browser proof is invalidated on a change to the original optimizer implementation: 424 on Source, 378 for Wayfatours tours. This only unlocks new browser QA; it never grants an inherited CSS certificate or bypasses exact market/currency and runtime validation.
## Proof, residual work
- Live anonymous EN/ES Wayfatours tour: CSS 158 -> 4 linked styles; native FastCGI MISS -> HIT (selective route update).
- Live Source tour: CSS 159 -> 2; native script count retained (126); private/no-store unchanged.
- Native Chromium mobile smoke: Wayfa tour, Source tour, Source archive all 200, zero CSS failures or JS exceptions, zero horizontal overflow.
- Wayfa five-viewport tour certificate published after real Chromium QA; other routes remain eligible for independent source certification.
- 18 Nov Source family report: all 59 groups retryable after Source optimizer repair; source-level readiness does *not* imply all currencies or routes are certified.
- Alexandria Corniche mobile hero background regression remains a real LCP hold; preloading the existing 574 KB image alone still regressed LCP ~22.7 sec at 390px. Never promote this failing candidate as certified.
- The two company-specific 424 deployments are reversible by deleting only 424. Wayfa 378 rollback uses its exact pre-change backup.

## Automatic repair of original CSS file identity drift
- Public route certificates are already bound to exact CSS SHA-256, Elementor document, WCML currency/market and source generations. A changed LiteSpeed artifact still correctly invalidates a stale CSS certificate.
- Original production evidence on 18 Nov: Wayfa Aswan Highlights tour had a certified `02493a53fbe004c8e3431a1b5283cb5f.css` SHA, but a later source bundle `0a5960bb46b7ece08d067615e75009a3.css` was served. The 970 bytes of difference included new Form6 stay/tier CSS, despite unchanged global/listing generations. Reusing the older certificate would be incorrect.
- The existing source-family runner now performs a bounded, no-cookie origin CSS proof (at most 4 currently certified public routes per admitted run). It verifies the exact market/currency and original filesystem CSS content digest, and then requests independent certification of that single route when it detects real drift.
- No additional timer, new grant, content edit, blanket purge or private-context HTML caching is required. The original immutable queue ledger is left untouched until the new browser QA finishes. The queue's selected job is built using a temporary exact-route repair ledger to retain the original CAS and regression checks.
- The live auditor detected the Aswan source discrepancy, unit tests passed, and its next first-party window is seeded to that route. A new certificate is **not** yet claimed; the existing CPU/WP-CLI admission must permit Chromium to run.

## Stable native JetEngine CSS bundles (18 Nov 2026)
A Source tour's large LiteSpeed bundle changed on each anonymous view even
with no CSS edit, because per-render JetEngine Elementor CSS contained volatile
`elementor-dcss-<hrtime>` selectors. Verified four Chromium original capture
files at widths 320, 390, 414 and 768 with identical byte lengths but four
different SHA-256 values; the first changing section was a per-card
`elementor-dcss-106953...` selector. Original 414 stable selector hooks were
already opted into `mad4b_perf_stable_tour_dcss_v1=on`, but did not remove
this source of variation from the final compiled CSS. Never normalize
selector IDs in the bundle alone: that would disconnect rules from live DOM.

The first-party original 424 private CSS optimizer now appends
`elementor-dcss-` to LiteSpeed's native
`litespeed_optimize_css_excludes` ONLY on eligible Source public tour
responses. Dynamic CSS stays as native inline styles with live JetEngine
selectors. Fixed Elementor/global/JetEngine CSS still uses one compiled,
immutable LiteSpeed CSS bundle. This is a CSS-only source change; native
Fluent Forms/WooCommerce JS is excluded from optimizer modification.
Signed-cookie, private/no-store and per-company scope remain unchanged.

**Observed live after permanent application, without special QA headers:**
Four Arabic tour views had the exact same CSS URL and SHA-256, dynamic CSS
outside the bundle, with private/no-store on every response. EN, ES and DE
tour views also had matching CSS URL+SHA across two views per language.
An isolated PHP hook test passed 8/8 cases: source-only eligibility,
idempotence, existing excludes preservation, separate Wayfa original
path, and unadmitted user-context integrity. The QA MU plugin 426 was
removed. Browser-wide layout and LCP certification are separate, not
implied by this CSS identity repair; the shared browser slot must be free.

## Arabic WPML percent-encoding and market-specific publication (18 Nov 2026)
The first Source Arabic Tour reached `passed_evidence` in Chromium for all
five viewports (320/390/414/767/768) with one stable CSS SHA. The original
publisher wrote the SHA- and market/currency-bound certificate but its live
readback correctly HOLDed; **the successful QA result is not a live
Critical CSS installation proof**.

A stage-gated diagnostic (temporary MU 425, removed after diagnosis) showed
two distinct facts: browsers/WordPress sometimes send Arabic percent escapes
as uppercase `%D8` while the original source catalogue and cert key
serialize the exact same bytes as `%d8`, and the tested original browser
cohort was `DE / USD` but current anonymous live requests were
`DE / EUR`. Source 422 v1.0.1 canonicalizes ONLY the casing of valid
percent escapes before certificate lookup, preserving all other URI
characters and original object, WPML, WCML, currency and market checks.
It was deployed on Tours.dev *only*, leaving Wayfatours Production 422
unchanged. Six hermetic tests passed and live source readback confirmed the
canonical Arabic path. This legitimate 422 source runtime hash change
invalidates old Source certificates until an independently measured new
runtime proof exists.

No certificate is reused across currencies. EUR/DE continues with
native source CSS until its own original Chromium proof and exact source
market/currency checks pass. The AR candidate's prior USD/DE receipt must
NEVER be relabelled EUR. The maintenance backlog is source certification
per trusted market/currency, not a reason to disable the exchange guard.
The old 425 diagnostic staging MU was deleted; no payment, Woo, WPML or
guest HTML cache controls were changed.

## Browser QA must use the actual public origin currency context
On 18 Nov, a Source AR tour passed five independent Chromium
FCP/LCP/CLS widths but live readback correctly withheld its DE/USD
certificate from a DE/EUR public request. The original Chromium worker
used Docker bridge egress. Same route, user agent, Accept and no cookies:
- Original VPS host request: DE/EUR.
- Original Source and Wayfa PHP container egress: DE/USD.
- Isolated Playwright image Node fetch with only Docker network changed:
  Docker bridge DE/USD, host DE/EUR. Both remain private/no-store.

The bounded runner now uses host networking with ALL capabilities
dropped only for Tours.dev Staging browser capture, verification and
public readback navigation commands. Wayfatours Production and offline
CSS assembly retain original networking. Native browser locks, CPU
admission, no-new-privileges, market/currency partitioning and five-view
QA remain mandatory. Source-family optimizer keys bind the versioned
public-host egress source so old bridge-negative proofs are not reused.
No existing DE/USD certificate is relabelled DE/EUR.

## Durable Source 422 runtime admission (no temporary Git worktree)
The original public CSS runner required installed WordPress PHP to match
original first-party source bytes exactly. Source 422 now has a legitimate
different WPML URI-canonicalization release from Wayfatours 422, but
selecting that version from a temporary PR worktree would make the runner
fail if the worktree were cleaned. The original Source 422 source in PR 849
has exact SHA-256 254c382bb96faadda2510d75501107ac73ae80673093dd3c0a47a5f63f7f2ff6.
For Source 422 the runner now checks the live bytes against that exact pinned
Git release digest. Both sites still match original 421 source bytes, and
Wayfatours original 422 remains matched to its original audited source.
All missing, symlink and mismatch cases still fail closed. A future
legitimate Source 422 release must update the pinned digest with its tested
source proof. No extra timer, grant, or operator gate was introduced.
Hermetic tests enforce Git-content hash identity, company scope and the
absence of any dependency on the temporary worktree.

## Cap-drop ALL in host-network CSS capture: group-only read permission
Original Source LiteSpeed CSS files are stored inside a www-data:www-data
0770 directory, with 0644 CSS files. The hardened staging Source browser
uses host network and drops all Linux capabilities, so its original 0:0
container process can no longer traverse the CSS directory and the first
real capture returned EACCES reading the immutable CSS file. This was
correctly recorded as HOLD; no certificate was created.

An isolated original Playwright image test demonstrated that adding only
the www-data supplemental GID 33 to the capture container permits
read-only access to that exact static 3.7 MB CSS artifact, without
reintroducing any Linux capabilities. The source CSS volume remains mounted
read-only. The original first-party runner now adds --group-add=33 only to
Tours.dev Staging capture.js and keeps --cap-drop=ALL. Source verification
and browser readback remain cap-dropped without supplemental group, while
Wayfatours Production and offline assembly are unchanged. The source-only
browser gate, original resource admission and private HTML policies
continue to apply.

## Original WordPress category archives: exact body identity
Original public-route browsers previously treated every taxonomy route as
term-N, but WordPress core category archives use the distinct body class
category-N. This caused a false BOUND_WP_OBJECT_DRIFT hold for valid
Wayfatours category/interests (category-2943) and category/uncategorized
(category-1). Unlike JetEngine custom taxonomies, which do use term-N,
core categories must validate category-N only. The existing first-party
capture and readback scripts now choose identity classes by the original
route taxonomy. No forged category from another object, object-id prefix
(e.g. category-29430), wrong page/post/archive, or custom term-ID can
authorize capture. 30 hermetic checks exercise the original source
functions for archive, taxonomy, post and tour cases. Both scripts were
reconciled to original live source, backed up and atomically deployed.
No WCML, WPML, payment or page template data was changed. New category
certification still requires real independent Chromium QA.

## Category-only negative-proof invalidation after original browser identity repair
Wayfatours history contained Category Interests and Uncategorized HOLD records
from the old false term-ID body-class assertion. Their historical retry
cooldowns blocked an immediate fresh QA pass even after the correct
category-ID source parser had been atomically installed. The original
public-route source_key and optimizer_source_key now include a versioned
core-category-body-class category-id-v2 fingerprint for type category only.
This opens one normal browser re-qualification opportunity per affected
category source, while preserving original visit and CSS byte checks, WPML,
WCML, dependency gates, ordinary cooldowns after the new attempt, and all
old receipt files until Chromium certifies a replacement.

Twelve focused tests prove only category historical IDs are invalidated,
that an old historical HOLD can retry without manually deleting a ledger,
that the original ledger stays unchanged until the browser returns, and
that every other public route type retains its exact original source key.
No global cache purge or category post data was edited.

## Original certificate publisher: core category taxonomy identity
The first Wayfatours native category acceptance cohort (Uncategorized)
passed *all five* original Chromium QA viewports after the original
capture and public readback identity fixes. Publication nevertheless
HOLDed because original public-route-certificate.py independently
verified its server-side WordPress body class with the historical
term-N check for all taxonomies. The original WordPress category body
uses category-N, so this was an exact independent gate mismatch,
not failed FCP/LCP/CLS or insecure market identity.

The publisher now requires category-N for core WordPress category routes
while preserving term-N on JetEngine taxonomies and postid/page-id for
regular content. The first-party original source-identity test was
expanded to 15 cases, including native Uncategorized and Interests,
negative wrong IDs, prefix spoofing and category vs term confusion.
The original certificate publisher dryrun passed using the existing
Uncategorized five-viewport evidence and existing market/currency
readback. A separate current WordPress WP-CLI check confirmed the
original authenticated route, source state, market, currency and both
dependency generations still match that captured browser evidence.
The original five-viewport QA is *not* rerun merely because the
publisher's identity parsing was repaired. Publication remains
conditional on exact source parity and a fresh public HTML readback;
a successful dryrun alone is not a deployed certificate.

## Wayfatours original WordPress core categories: stable compiled CSS source (18 Nov 2026)
Original category archives in Wayfatours Production failed the final live
Critical CSS readback despite passing five real Chromium viewports. The
negative proof was valid: the certified 2,883,305-byte CSS bundle
`d26b05e002dcea0b78d894c57d8c3cc7.css` was replaced after the
selective release by a different CSS artifact
`d8366a4964a24bda0dfe1b02d54e5100.css`. First CSS difference at
byte 2,551,420 was volatile JetEngine `elementor-dcss-<hrtime>` selectors
generated per request. CSS bytes differed even though they were the same
size; the current runtime correctly rejected stale certification.
Original 414 stabilizer was scoped to Home/Tour and did not handle
WordPress core category archives.

The new first-party 427 MU plugin applies LiteSpeed's native
`litespeed_optimize_css_excludes` for `elementor-dcss-` ONLY to
canonical published public WordPress `category` GET routes on
Wayfatours Production when unauthenticated, cookie-free, query-free and
without payment/user state. Dynamic selectors remain in the matching
live HTML inline styles; immutable global styles continue to combine.
No changes to WooCommerce, language/currency/market, WP core category
identity, Elementor layouts, HTML cache policy or native JS. The feature
has a per-company off switch
`mad4b_public_category_stable_css_source_v1=off`.
Live evidence from independently versioned origin cache generations for
`/category/interests/`: HTTP 200, one CSS bundle
`ab3c9e74709d568f8124bf0683ebf75a.css`, SHA-256
`ee20c27b8d5dc326e0550950aef3100eb1e48c9ee4c2c850b3b27e7c664170f0`
identical across two separate MISS generations; FastCGI MISS -> HIT;
0 volatile selector tokens in compiled CSS, 12 native dynamic CSS
inline style blocks, public original HTML cache policy preserved.
Original backout is deleting only the new 427 MU file, with selective
route revalidation if required.

The original source-family runner now reports the original 427 source
file SHA only for Wayfatours core categories, invalidating historical
category-only negative QA/hold keys when the optimizer changes. It
does not inherit successful source-family certification or bypass the
five-viewport Chromium and exact WPML/WCML or CSS source checks.
Both Interests and Uncategorized were proved eligible for fresh
original category source QA with their exact 427 fingerprint, with
no change to Tour, destinations, other types or Tours.dev.
Six hermetic PHP CSS-exclusion tests and eight source-key isolation
tests passed. Independent live Chromium recertification is still
necessary before these category pages count as Critical CSS Ready.

## Home-page ownership: do not send independent critical-css Home to the per-route pipeline
Observed after Wayfatours core-category source fixes: the original
`mad4b-public-critical-css-wayfa` worker spent a full cycle selecting
`page /`, then the original selective cache release correctly refused
it with `home-or-out-of-scope-route`. The standalone EN and translated
Home views already have a different, independently certified Critical
CSS/cache controller and MUST NOT be recaptured by the generic
source-family/route worker. This was not a CSS or eligibility failure;
it was a false source-family nomination that starved eligible pages.

The original general queue already excluded `/` and `/<language>/`
only for *legacy* selection, but an authenticated preferred source-family
canary bypassed that check. New original shared
`independently_certified_home(route)`:
- Filters independent Home and WPML translated Home out of the source
  family candidate collection; all other existing pages remain.
- Rejects a stale preferred Home proposal before a cache release.
- Rejects recording a fabricated or stale Home per-route certificate,
  retaining the separate home owner.
- Leaves all original WooCommerce routes excluded, WordPress core
  category candidates admitted and original cache-release safeguards
  unchanged.

11 focused route-selection/record tests passed including independent
home, WPML, ordinary pages and categories. This reduces wasted browser
cycles; it does not turn a source-family pass into an exact page
certificate or disable CPU and browser admissions.

## One audited native Runtime release gate for both CSS engines
The standalone, read-only `mad4b-public-source-families-refresh` service
still had a hard-coded 421/422 source byte-comparison from the original
PR #814 checkout. After the approved Source 422 v1.0.1 Arabic WPML URI
normalization was installed, the live per-route runner correctly admitted
the audited Source-specific SHA but the separate registry refresh would
have rejected it as `source_runtime_component_drift:source` whenever
its WP-CLI/CPU admission reopened.

Instead of weakening the check or copying staging code to production,
the original `run-public-route.py` now owns one
`approved_native_runtime_sha(site,wp)` function. Both the per-route
worker and the source-family registry call it unchanged:
- Original Source Staging 422 exact SHA-256
  `254c382bb96faadda2510d75501107ac73ae80673093dd3c0a47a5f63f7f2ff6`;
- Original 421 remains byte-equal to the audited central checkout on
  both companies, and Wayfatours 422 remains byte-equal to that checkout;
- Incorrect company/root, symlinks, absent or mutated PHP fail closed.
The registry consumes the resulting actual 422 SHA when constructing
source family evidence, so it cannot silently diverge from the
certificate issuer. No independent approval or new background timer
was added. The original 8 integration checks and updated 3 release-pin
regression checks pass. Separate WP-CLI resource admission remains;
a deferred registry refresh is not claimed to have completed.

Read-only source-family calculation using the newest original WordPress
catalogs with this release policy and the 427 category CSS source hash:
Wayfa 846 generic routes/21 groups, next core-category Uncategorized;
Source 1,022 generic routes/53 groups, next German Cairo tour.
Six Source and two Wayfa independently owned Home language routes were
excluded from the *generic route queue only*, not deleted from any
site, language menu, cache, or original dedicated Home certifier.
