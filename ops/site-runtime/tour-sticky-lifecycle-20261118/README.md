# MAD4B — Elementor Sticky lifecycle guard
Source-backed, 18 November 2026.

Active selective fix on Tours.dev and Wayfatours Production. It does not authorize full plugin replacement or merge.

The original Elementor Pro jquery.sticky.js can call jQuery.css with an undefined CSS backup after resize, AJAX or stale instance reactivation, producing the TypeError involving replace. Repeated initialization of the same Sticky element can also leave multiple scroll listeners and spacers.

The source-owned MU-plugin selects a SHA-pinned JS compatibility adapter on tour pages using the actual Elementor library behavior fingerprint, independent of Elementor version numbers. The adapter recovers a missing inline CSS backup and destroys an existing instance before reinitializing. It does not change Elementor vendor files, bookings, prices, payments or desktop layout.

After the runtime change, public tour route cache generations were updated: 1,024 staging routes and 440 Wayfatours routes, excluding private URLs. Live automated Chrome checks verified the top Tour Contents, bottom inquiry bar, and constant title row height of approximately 103px through repeated scrolling. A deliberate in-browser loss of the saved CSS state recovered successfully with no exception.

One screenshot message concerning a closed asynchronous response channel may originate from a Chrome extension and is not claimed resolved. Test without extensions if it persists.

Run python3 verify.py for source and SHA verification. Optional browser test: tests/sticky-recovery-in-browser.js runs against Tours.dev; it changes only the isolated browser DOM to force the fallback, not site data. Any upstream Elementor Sticky source behavior change results in the original upstream library being used until this adapter is revalidated. Release/merge and runtime compatibility checks are separate.
