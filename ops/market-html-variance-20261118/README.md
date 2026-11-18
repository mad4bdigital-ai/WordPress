# Route-level rendered market variance and safe destination-card reuse

Live: Wayfatours Production, 18 November 2026.

## Enrolled behavior
- Signed country preference is verified by first-party WordPress HMAC before
  a private destination-card fragment cache lookup.
- The shared public term renderer tests the **actual rendered HTML** on both
  cache read and write for price, currency, market, form, checkout, JS, session
  and booking indicators.
- Every new country/currency/language renders its own card before eligibility.
  Each market stores its own SHA pointer. Exact identical bytes use the same
  SHA-addressed content object; different bytes remain separate.
- Entries expire after 300 seconds. The key also includes locale, source
  Elementor/JetEngine document IDs, modification generation and term changes.
- Shared-render adapter source is installed on Tours.dev and Wayfatours;
  the signed market card consumer is active only on Wayfatours.

## Real live evidence
- English mobile home with signed US, DE, EG and GB: nine prices and public
  listings matched, while all four complete HTML hashes differed.
- Thus this is a candidate price/listing cohort, NOT full-HTML cache authority.
- Spanish home uses JetEngine Dynamic Field price presentation (Desde ...
  plus an explicit sibling ISO currency), unlike the English Woo price
  elements. These are now read independently from actual HTML. Distinct
  public listing graphs are classified as separate content variants, not
  as absent prices and not as shared full HTML.
- Historical pre-rollout baseline: signed visitor home requests were BYPASS.
  The 18 November company-scoped update below changed this to a short-lived
  private signed-origin FastCGI cache with exact context isolation. Woo,
  booking/payment, pricing policy and native Critical CSS were unchanged.

## Future cross-country public shell admission (not active)
1. Resolve the visitor's signed market and authorized currency.
2. Compare real output on the same route, locale and source generation.
3. Share a public neutral shell only after visitor state is moved out of HTML
   and its price owner can provide server-validated private hydration.
4. If price or content differs, maintain isolated versioned path + market +
   currency + locale variants. Do not partition just because an engine exists.
5. If output proof is missing, preserve private no-store instead of guessing.

The Python route variance classifier is deliberately read-only; it explicitly
returns edge_cache_activation=not_authorized_by_this_observation.
Its live observer uses synthetic first-party HMAC cookies only in volatile
process memory. It stores only response hashes/summary, never tokens, raw
HTML, user identifiers, WordPress salts or real visitors' preference data.
Critical CSS and WP-Cron remain separately governed.

## 18 November: Wayfatours stable market + passport cache

**Running:** market country and manual passport choices now issue stable,
site-bound first-party HMAC-signed cookies. Different sessions selecting the
same ISO2 country and passport no longer create different FastCGI objects
merely because their selections were made at different times.

Historical valid time-bearing cookies are upgraded once during the existing
private visitor preference AJAX state read, not during a public HTML GET.
Only anonymous sessions without Woo/cart/auth are upgraded. Unknown tokens
are never re-signed. A choice remains valid for the prior 90-day browser
lifetime, with the same country and nationality.

Nginx still permits only exact EN and ES home paths for this cache; its key
contains the signed country and signed passport when present. WordPress must
verify both signatures, the original public-page and mobile CSS proof, and
the absence of session, auth and Set-Cookie. The browser is private/no-store,
and the origin requests a 150-second cache TTL. Booking, pricing APIs,
cart/checkout, query-driven filters and authenticated HTML remain excluded.

Verified real-time behavior: two older US-country/EG-passport cookie pairs
converted to the same signed values. The first complete HTML GET was a
FastCGI MISS in 3871ms, the second session HIT in 13ms with matching HTML
SHA and verified Critical CSS. Country-only US and DE repeat visits returned
15ms and 20ms HIT respectively. These are observed origin response times,
not whole-browser LCP/JavaScript measurements.

**Cross-country raw full-HTML sharing remains disabled.** EN has equal
prices and public listings for four examined markets, but country-specific
flags and the consent gateway receipt are real private context. ES has
distinct prices/listings for those markets. Merging full raw HTML across them
would be wrong; only verified price-free destination cards use SHA-dedup
across countries. Do not infer variation solely from the existence of
pricing engines. The shared-HTML shell is a separate future admission after
private-state/consent neutralization and authoritative price hydration.

### Deployment authority

Source is the live Wayfatours Production file, NOT a stale global plugin.
Wayfatours has newer header/preference runtime behavior than Tours.dev
Staging for these modules, while the original shared-source country plugin
has different market entitlement logic. This is stored as a complete
Wayfa-scoped live overlay under wayfa-live-overlay/wp-content, not as a
wholesale replacement of the shared-source WordPress plugin. Reconcile
selectively by company before a common release. The read-only
test-live-overlay-parity.py compares every overlay asset against live
Wayfatours. It refuses silent drift rather than overwriting newer code.

No WordPress data, Woo prices, orders, booking/payment settings, CSS policy
or native certificates were modified. The Nginx binding and final config
were tested; temporary diagnostic response header was removed.
