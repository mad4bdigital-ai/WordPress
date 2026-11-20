# MAD4B — Elementor Sticky lifecycle guard
Source-backed, 18 November 2026.

Active selective fix on Tours.dev and Wayfatours Production. It does not authorize full plugin replacement or merge.

The original Elementor Pro jquery.sticky.js can call jQuery.css with an undefined CSS backup after resize, AJAX or stale instance reactivation, producing the TypeError involving replace. Repeated initialization of the same Sticky element can also leave multiple scroll listeners and spacers.

The source-owned MU-plugin selects a SHA-pinned JS compatibility adapter on tour pages using the actual Elementor library behavior fingerprint, independent of Elementor version numbers. The adapter recovers a missing inline CSS backup and destroys an existing instance before reinitializing. It does not change Elementor vendor files, bookings, prices, payments or desktop layout.

After the runtime change, public tour route cache generations were updated: 1,024 staging routes and 440 Wayfatours routes, excluding private URLs. Live automated Chrome checks verified the top Tour Contents, bottom inquiry bar, and constant title row height of approximately 103px through repeated scrolling. A deliberate in-browser loss of the saved CSS state recovered successfully with no exception.

One screenshot message concerning a closed asynchronous response channel may originate from a Chrome extension and is not claimed resolved. Test without extensions if it persists.

Run python3 verify.py for source and SHA verification. Optional browser test: tests/sticky-recovery-in-browser.js runs against Tours.dev; it changes only the isolated browser DOM to force the fallback, not site data. Any upstream Elementor Sticky source behavior change results in the original upstream library being used until this adapter is revalidated. Release/merge and runtime compatibility checks are separate.


## Second independent layout-shift cause — Rate Lazy Load
Reproduced a 241px jump in the form's document position while JetEngine
replaced the rate grid's 36px loader with two real rows totaling 277px. The
underlying source is the rate listing widget itself, not a fixed CSS margin.
A source-owned MU presentation component, 410-mad4b-tour-rate-lazy-footprint.php,
reads the native Query Builder count associated with the listing's own
data-lazy-load query_id and reserves a measured mobile row footprint before
the signed AJAX result replaces it. It supports Arabic/Persian digits, caps
implausible counts, leaves zero/unknown counts unchanged, never queries or
changes booking/rate data, and scopes its observer to the containing Elementor
rate template (not the entire DOM). Existing JetEngine loading remains lazy.

Verified canonical Wayfatours mobile 427px: 277px reserved and 277px rendered,
form document-top delta 0px; mobile 390px on Wayfatours and Tours.dev:
277px reserved then 276.6875px actual, delta -0.3125px. Async rate
cards completed; no JS exception. The tests/rate-lazy-footprint.test.js script
repeats the contract on read-only public tour pages.

The existing source + Wayfatours Form #6 asset reconciler now fingerprints
this rate footprint, the sticky guard and its immutable JS as well as the
original three Form #6 sources, and existing per-site systemd.path watches all
seven sources. Any source change produces selective public-tour HTML generations;
no extra service, sitewide purge, checkout mutation or database writes.
This bundle is a later, source-owned operational snapshot: the separate
historical Form #6 PR remains immutable and should not overwrite the newer
seven-source reconciler during a future release.

### Multilingual observation
The Spanish translation's live Fluent Forms #6 instances were hidden from
layout before and after rates loaded (visibility:hidden; zero client height).
That is a separate form/readiness decision, not a rate lazy footprint jump.
The regression measures the actual immediate next sibling of the rate listing,
so the footprint check works on EN and ES without fabricating a visible form.
Spanish 390px: native rates 277px to 276.6875px; next sibling delta -0.3125px.
Do not force-show hidden language-specific booking forms based on this test.

## Mobile reopening, browser history and idle interactions

Another report described intermittent loss of touch after reopening a page,
Back/Forward, or idle. It was NOT reproduced in disposable extension-free
Chromium on the two live sites. The new
tests/mobile-interaction-resume.test.js preserves read-only regression coverage:

- Tour to Home to browser Back, Forward, Back and reload
- Foreground after visiting another tab and a short idle period
- Four Chromium frozen to active lifecycle transitions
- Mobile hit-test for the native menu, first-touch state change,
  stuck aria-busy detection, Sticky guard and Javascript exceptions

The tested pages returned pageshow.persisted=false: BFCache was not
observed, so do not claim that a true browser memory restore was tested.
The extension-related closed message-channel warning is also not covered.
No blanket touch interceptor, unsafe overlay clearing or preference deletion
was installed in response to an unreplicated mobile symptom.


## Native n-menu direct-link correction — live source, 18 November 2026

A real first-click JavaScript failure was reproduced on Tours.dev after
saving the visitor market (EG), opening Elementor's native mobile Mega Menu,
and tapping a Home link with no dropdown. Elementor Pro 4.3.2
elements-handlers.min.js onTabClick called getTabIndex(null), throwing:
Cannot read properties of null (reading 'getAttribute').

The source-owned 411-mad4b-direct-menu-link-contract.php emits an early
head script. After DOM readiness it identifies native Elementor n-menu titles
with a direct anchor and no submenu and marks them as link-only, an existing
Elementor handler class. No click interception, vendor modification, fake
dropdown or Elementor version certificate is needed. A header-scoped child-list
observer reconciles dynamic menu changes; pageshow handles return navigation.

Live browser results on Tours.dev and Wayfatours:
- Four direct menu links marked correctly, two dropdown items untouched
- Re-render simulation: direct to dropdown to direct automatically corrected
- Native Home tap, Back, and first menu tap: pass with zero page JS exceptions
- EG market saved through the actual first-party visitor preference API,
  then direct tap, Back, reload and short idle: four out of four on each host

The existing source and Wayfa source-owned reconcilers now fingerprint
seven runtime artifacts instead of six. Same per-site systemd.path and
five-minute timers, no new execution service. Final receipt:
Tours.dev 1,024 public tour paths, Wayfatours 440, no private or checkout
paths and no Wordpress order/payment writes. Public home and archive HTML
both include the guard in the canonical page response on both hosts.

Regression files: direct-menu-link-contract.test.js and
mobile-saved-market-return.test.js (set trusted TEST_TOUR_URL).
Neither file alters WordPress configuration or booking data; the saved-market
test saves a temporary browser preference using the public native UI.

Safety boundary: the reproduced Elementor menu exception is corrected,
but the user's sporadic all-click freeze on physical Android Chrome is not
claimed fully reproduced or cured. Chromium with normal no-store page
responses did not demonstrate a true BFCache hit; the separate browser
message-channel promise warning did not reproduce with extensions disabled.
No global touch capture, cookie deletion or forced booking form visibility.

The seven-file snapshot and host watcher copies are evidence for selective,
source-first reconciliation. Do NOT install the old source tree wholesale or
overwrite newer live modules. An authorized owner exact-head attestation
and all CI gates remain separate requirements before merge.


## Uniform mobile bottom actions — both live sites, 18 November 2026

Inspected three existing Elementor mobile presentation models on
Wayfatours Production and Tours.dev Staging:
- Archive: one native elementor-element-650c69 shortcode, moved into
  body > .mad4b-mobile-dock-host by the current portal script.
- Tour: elementor-element-d8505dd has one visible bottom-fixed action
  and one hidden elementor-sticky__spacer clone preserving document flow.
- Destination: elementor-element-9f6293a similarly has a fixed CTA
  and hidden spacer. Unlike other templates, it remained visible while
  the actual Elementor mobile menu was open.

First-party 428-mad4b-mobile-bottom-action-nav-visibility.php is a
presentation-only CSS rule to suspend existing bottom docks, active
bottom-sticky actions and other mobile float controls whenever the real
Elementor menu has aria-expanded=true. On closing the menu they return.
No click capture or JavaScript hook, template ID rewrites, reparenting
of elements, forced form visibility or edits to Elementor plugin code.

The archive text Start from USD 28.00 / per person is the minimum scoped
archive price, NOT the Best of Egypt tour price (From USD 2,310.00).
It belongs to a single .mad4b-archive-price-value inside the original
archive dock. In a degraded browser with the dock portal script
intentionally aborted, the dock remains usable but this label is
kept hidden until data-mad4b-dock-mounted=viewport exists. This
prevents an isolated unanchored price overlay during delayed loading.
No prices or actual JetEngine cards are changed.

Verified with real Chromium on the source and Wayfatours:
- Three kinds of pages, 390px and 427px: 12/12 open/close cycles, all
  visible bottom docks hidden while menu open and restored when closed,
  with zero page JavaScript errors.
- Archive portaling normal/blocked on both sites: 4/4 correct visibility
  states; browser-only synthetic price text proves that the CSS gate,
  not a pending REST price response, causes the degraded visibility.
- One visible sticky CTA per page; the hidden Elementor spacer clones
  deliberately remain to avoid introducing layout shifts.
- Eight source files tracked by existing per-site systemd path watchers
  and five-minute timers. Recent selective public HTML reconciliation
  receipts: Tours.dev 1,024 and Wayfatours 440 routes, both PASS,
  private checkout and payment routes unchanged.

Run tests/mobile-bottom-action-menu.test.js and
tests/archive-preportal-price.test.js with TEST_ORIGIN set to the trusted
site origin. This addresses inconsistent dock navigation policy, not the
unreproduced physical-Android all-click freeze or browser extension
message-channel closure.


## Tablet breakpoint reconciliation — 19 Nov 2026

Further live browser tests found the native Elementor collapsed menu toggle
remains available at 768, 820, 991 and 1024px, but not at 1199px.
The original bottom-action suspension used max-width:767px and thus
left fixed booking/inquiry docks visible while the actual collapsed
menu was open on tablets. The source guard now uses max-width:1024px
and still ONLY suspends actions when the native Elementor toggle has
aria-expanded=true. This is a behavior/viewport constraint, not
an Elementor plugin version condition. No vendor files touched.

Tours.dev source browser: the destination bottom CTA was hidden after
clicking the native collapsed menu at 768/820/991/1024; no JS errors.
Wayfatours production: same four widths passed and at 1199 the mobile
toggle was absent. The strengthened regression suite includes mobile and
tablet viewports, skips the mobile-only archive dock at tablet widths,
and uses a desktop pointer click for non-touch viewport tests.


Verified 19 November native tablet final regression:
- Source and Wayfatours: tour/destination at 768px and 1024px all pass
  (**8/8**), active bottom CTA visible before menu, hidden on open and
  restored on close, with no page JavaScript exceptions.
- The test now distinguishes touch tap from mouse click, and explicitly
  waits (with a bounded timeout) for native Elementor Sticky activation
  before comparing geometry. An early test incorrectly treated an
  uninitialized Sticky state as a failed CSS policy; the settled behavior
  is correct.
- The original phone regression below 768px was already proven 12/12.
- Eight-file live-source composite SHA matched across source and Wayfa:
  59852c7b7f77bebd2c8462e7469e7208af7451299520e61c614b1538da29528d.
  Bounded public-route reconciliation PASS: 1,024 source and 440 Wayfa,
  both file watchers and timers active. Canonical archive and destination
  HTML on both hosts serves the new <=1024px CSS contract exactly once.
