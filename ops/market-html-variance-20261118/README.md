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

## Remediation review — 18 November 2026, 13:00 UTC

The following corrections are **running on Wayfatours Production**:

1. Price freshness: original Nginx FastCGI use_stale and
   background_update are disabled. A source timeout or an expired key
   cannot silently reuse expired market-price HTML. An unexpired HIT
   remains fast; an expired object requires fresh rendering. This applies
   to the site-wide PHP location.
2. Browser caching: Nginx owns the single browser-facing Cache-Control
   response header; upstream Cache-Control still feeds the privacy
   veto. Signed public home and archive HTML is private/no-store
   in the browser; anonymous public HTML retains its public policy.
3. Signed archive canary: only canonical unfiltered English
   /tours-and-activities/ was added beyond EN/ES home. First-party HMAC,
   guest status, exact original path and locale, zero query parameters,
   no Woo session/client override, is_post_type_archive, and original
   X-Wayfa-Public-Page:1 admission remain necessary. TTL 90s, no stale.
   US: 3729ms MISS -> 21ms HIT. DE: 3189ms MISS -> 17ms HIT.
   Within-country HTML SHA stayed identical across repeat visits;
   between-country SHA differed. No cross-market raw HTML sharing.
   All JSF filter URLs, tours, bookings, rate editing, quote/payment,
   and unrelated page archives remain outside this signed edge canary.
4. Server-side signed preference expiry: country/passport canonical
   HMAC bytes are stable per 90-day UTC signing slot. A signed nonzero
   slot must not be older than 180 days nor more than 60 seconds in
   the future. Legacy zero-time signatures are accepted only through
   2027-02-28 and transition on the pre-existing private state AJAX.
   Other valid old timestamp-bearing cookies are accepted within the
   age bounds. Browser expiry is still 90 days, and these preferences
   are not financial or authentication credentials. Old valid
   preferences, session, and Woo settings are preserved.
5. Tests: PHP signed archive admission 9; Nginx route/source 9;
   native WordPress signed preferences 8; signer fixture 12; plus
   prior market/price, card, and live fail-closed regressions and
   byte-level overlay parity.

**Not complete:** Tour-detail and JSF dynamic filter HTML lack
independent safe public certification and must not be force-cached,
because rates, date, accommodation and booking state can be
commercial. Cross-country public shell requires first-party
SWATCHER/consent hydration and trusted price updates before sharing.
The original Tours.dev CSS source observer still reports EN/ES/IT
drift while CPU/Chromium headroom gating leaves its certificate
queue pending; a verified Wayfa homepage CSS-ready HIT does not
claim source-wide CSS readiness.

### Error response header hardening

The signed origin policy is also emitted on non-2xx PHP responses: Nginx
now uses add_header Cache-Control with always in the FastCGI handler,
so hiding a potentially conflicting WordPress Cache-Control never
accidentally removes private/no-store from 403, 404 or 5xx responses.
A real 404 and public 200 were checked after reloading the live
bind-mounted configuration. The Nginx source contract guards this
behavior against regression.
