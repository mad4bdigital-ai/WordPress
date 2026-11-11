# Unified Date v0.3.1 — Native JSF AJAX, WPML and WCML: source-only acceptance

**Source:** Tours.dev Staging as independently read from local live filesystem on 2026-11-11.
**Candidate:** isolated local full-WordPress Git worktree.
**Status:** SOURCE_ONLY / NO DEPLOYMENT / HOLD.

## Latest immutable source

The source WordPress adapter enqueues immutable unified-date-v0.3.1.js and v0.3.0.css. Their exact original live SHA-256 and blob hashes are retained in the Commerce source manifest. Earlier immutable 0.2.6/0.2.7/0.3.0 assets also remain source-identical to the current live files.

The JSF implementation creates a single Date/Package/Season/Apply toolbar on eligible Tour Rate pages. Native JetSmartFilters remains the sole AJAX provider; this is not a custom second price query or a Woo order executor. Its scope is the original jet-engine/tour_rates_accordion and jet-engine/tours_query_archive providers. It preserves Package and Season, rewrites the canonical URL, passes selected date parameters in the native AJAX request, and falls back to reload if the provider is not available.

A guarded WCML Ajax display context passes only USD/EUR/EGP/GBP. The PHP adapter restores the configured client display currency only for these two providers, not the underlying Rate currency, FX table, cart or payment. New direct callbacks were not added to arbitrary AJAX actions. The original age/children pricing readiness gates and provider authorities remain unchanged.

## Exactly two deliberate, fingerprinted semantic exceptions

- WordPress PHP Unified Date adapter retains its previously reviewed single AND intersection function. It never widens a previously denied post__in=[0], retains original verified Tour/Rate Query 310 scope, accepts an explicit native date Clear, gives current POST facet precedence over stale GET/referrer dates, and excludes any temporary file-writing diagnostic code. The latest live source does not include the AND safeguard.
- Active immutable 0.3.1 JS retains two narrowly reviewed corrections that are not in the original live JS bytes: WPML Tour links are rewritten after Clear so they cannot resurrect an old selected date, and an empty Fluent Form #6 date formats to an empty string instead of malformed text. The earlier immutable 0.3.0 JS is preserved byte-exact to live.

The Commerce source verifier denies any third overlay or any changed path, digest, policy, case count or deployment claim. It verifies the current 276 Commerce PHP/JS/CSS paths: 274 match the live source byte-for-byte, two have exact reviewed candidate blob fingerprints. Verification is source-only, never an Original318/313 authority certificate.

## Test evidence, limitations

- 27 isolated fake-WordPress PHP assertions: Rate Query 310, valid/invalid date, original native post filtering, POST-over-stale-GET, native Clear, PHP WCML currency input scope, allowed currency, invalid currency, unrelated provider, and no price mutation.
- 23 VM-run tests against the actual 0.3.1 JS source: native AJAX prefilter, Package/Season preservation, no interference with WooCommerce, cleared date, display currency, invalid currency rejection, same-site WPML Tour links, scoped currency handoff and empty Fluent Form #6 dates.
- 131 Python source/provenance tests passed at the acceptance checkpoint.
- No browser click-path or installed JetSmartFilters/currency changer was exercised; none of these results asserts real on-site AJAX performance or booking eligibility.

Source-only modifications do not modify Tours.dev, Wayfatours, company options, WPML content, WCML exchange rates, WooCommerce orders, Stripe or site permissions. The global full-feature code release remains HOLD pending other branch conflicts, approved Original318 publishing, real Host Issue/Revoke and independent Original313 signed readback.
