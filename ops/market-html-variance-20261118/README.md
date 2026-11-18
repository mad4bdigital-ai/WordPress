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
- Spanish home uses JetEngine Dynamic Field price presentation (Desde ...),
  rather than the English Woo price elements. It remains unclassified.
- Nginx FastCGI private bypass for the signed visitor cookie remains active;
  Woo/booking/payment and Critical CSS policies were not changed.

## Future full-page admission (not active)
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
