# MAD4B Private Mobile CSS Family

Source owner: wp-content/mu-plugins/423-mad4b-private-mobile-css-family.php.
This extends the existing first-party verified Critical CSS and Inline Loader.
It is not a second public-cache system and never adds marketing/passport
cookies to the shared FastCGI cache allowlist.

## Original defect

A valid mobile visitor who selects a country or manual passport nationality
gets private HTML. JetEngine dynamic listing CSS also changes the LiteSpeed
combined CSS filename and SHA on repeated navigations. The ordinary public
certificate cannot be used by full-file SHA, but measured verified copies
have a common SHA-256 first-paint stylesheet backbone.

## Narrow safe delivery

- Only mobile and homepage with signed valid MAD4B visitor country or passport
  preferences and a qualified same-site WPML locale are eligible.
- Verify the original publicly certified source CSS SHA, Critical CSS asset SHA,
  CSS epoch and page dependency generation, plus exactly 2.5MB of shared CSS
  SHA prefix. Variation after that prefix may include dynamic listing CSS,
  which is loaded once via print/onload and the original inline loader.
- Preserve private, no-store headers. Reject Woo cart/session, unknown cookies,
  logged-in, unsupported language, preview/query, missing proof, and desktop.
- Reject stale certificates; use full native CSS until new browser proof passes.
- Existing original 415/417 CSS and public-cookie logic remain unchanged.

## Original live verification (Wayfatours, 17 November 2026)

24 isolated synthetic Chromium cases: country AE with manual GB passport,
EG auto, US auto across EN/ES at 320, 390, 414, 767px. All visible geometry
samples matched the native CSS baseline. Only a hidden zero-size Elementor
title had a computed-style difference; no page errors.
Live Chromium after selective deployment verified EN and ES private mobile:
one Critical CSS style, one async CSS link promoted to media=all, one inline
loader, one real CSS request, manual nationality persistence and private/no-store.
Desktop stayed on native full CSS. No Woo session was created.

## Autonomous operational lifecycle

- The enrolled Wayfatours-only monitor.py checks sources every 30 minutes;
  unchanged CSS proof is reused without repeated Chromium tests.
- Source/epoch/home graph drift triggers a fresh isolated 24-scenario visual QA
  before atomically changing the local per-site JSON certificate.
- Failure leaves native CSS as the safe fallback for the changed version.
- Lightweight live Chromium verification runs daily.
- Company-local JSON certificate is generated from measured evidence and must
  not be installed generically on other sites.
- Production rollback uses the saved local site preimage; do not mass purge CSS.

The engine itself uses MAD4B-only identifiers. The operator script is pinned
to the Wayfatours enrolment and needs a separate provider configuration and
proof cycle before deployment to a different company. Scope is homepage only;
other page types must be certified independently.
