# Tours.dev — Verified Top Cities public term fragments

Source first: Tours.dev Staging MU plugin 435-mad4b-source-verified-home-term-cards-v1.php.
The change never enables public full-page HTML caching, disables taxes, modifies
WooCommerce / WCML pricing, changes customer bookings, or shares complete HTML.

## Admission

- Exact canonical anonymous GET / home path, no query, no browser cookie.
- Staging company-bound domain tours.dev.mad4b.com, mobile EN only.
- Exact Elementor template 25909 SHA, widget b25dbff, destination listing 45927.
- Original first-party WCML ISO2 country and ISO3 currency, exact currency
  agreement. Different countries have different SHA-addressed Redis pointers.
- A synthesized WPML locale may appear internally only when the HTTP Cookie
  header is empty, and its value must equal the configured locale.
- Every other cookie, auth, preview, admin, desktop, non-home, arbitrary
  client GEO/COUNTRY/CURRENCY/MARKET/PASSPORT header or noncanonical request
  uses the original native JetEngine/Elementor renderer.
- The shared immutable public-term helper rejects prices, rates, scripts,
  forms, nonce, cart, checkout, booking, country/market selectors and
  volatile Elementor DCSS classes. SHA readback validates content identity.
- Redis TTL 300s; only public term-card HTML is cached. The entire webpage
  remains private,no-store in browser, with unchanged FastCGI policy.

## Verified source live results

- 33 PHP safety and partition regression cases pass.
- Canary first request: six destination card writes; next two: 6/6 HIT.
- Native and cache widget HTML SHA equal b18680cde24b09055d, 23,270 bytes,
  4 requests without volatile selectors, CSS-ready=1.
- After narrow normal activation: 6/6 HIT repeated on EN mobile, without
  changing DOM hash; Desktop, on-wire cookie, query and arbitrary spoofed
  country/currency headers do not enter the cache.
- HTML TTFB remains roughly 5–6s. No site-wide speed claim.
- Initial Chromium 390/768 comparison matched card/link counts, link digest,
  widget dimensions and CSS. First-link click trial failed in BOTH native
  and cached conditions, so interaction QA is not yet certified. The revised
  test selects an actually visible/unoccluded anchor and reports JS errors
  separately. No full interactive pass is implied.
- Source code matched deployed live MU-plugin SHA after application.
- WordPress option mad4b_source_home_top_cities_cache_v1 is active for EN
  mobile only. Revert mode to canary or remove the option to fail closed.
  Original source plugin/config snapshots are under root-owned reports.
- Do not extrapolate to other languages, devices, tour pages or pricing.
