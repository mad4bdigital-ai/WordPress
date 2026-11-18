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
six sources. Any source change produces selective public-tour HTML generations;
no extra service, sitewide purge, checkout mutation or database writes.
This bundle is a later, source-owned operational snapshot: the separate
historical Form #6 PR remains immutable and should not overwrite the newer
six-source reconciler during a future release.

### Multilingual observation
The Spanish translation's live Fluent Forms #6 instances were hidden from
layout before and after rates loaded (visibility:hidden; zero client height).
That is a separate form/readiness decision, not a rate lazy footprint jump.
The regression measures the actual immediate next sibling of the rate listing,
so the footprint check works on EN and ES without fabricating a visible form.
Spanish 390px: native rates 277px to 276.6875px; next sibling delta -0.3125px.
Do not force-show hidden language-specific booking forms based on this test.
