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

## Follow-up 19 November: real Swiper interaction gate and source FastCGI

Original readback at 390/768 CSS-ready proved equal Swiper DOM/cards/links
for cached and bypassed layouts, but the former test used the first
anchor even if offscreen/cloned. Its negative click trial occurred
on BOTH cached and native render, and cannot prove a cache regression.
The v2 browser test waits for actual Swiper initialization, picks a
geometrically in-viewport/unoccluded original anchor, uses a real
Playwright pointer click while intercepting navigation solely in the
test browser, checks keyboard focus, and compares baseline JS errors.
It must pass independently on 390px and 768px before browser
interaction can be called certified. The original native test and
v2 remain pending during other jobs' Chromium lock/CPU occupancy.
No Chromium pass is inferred from a static check or cache hit.

The separate first-party source Nginx uses bounded, client-IP isolated
FastCGI HTML, **not** cross-IP shared market caching. New read-only
test-source-ip-isolated-fastcgi-policy.py validates 13 immutable
safety facts against the installed Nginx config: real client IP
inside the key, exact trusted proxy real-IP source set, UA/language/
device/Accept/path partitioning, cookie/auth/query/context/commerce
bypass and no-store behavior. Both Nginx syntax and all 13 static
admissions passed. Real cookie-backed WooCommerce and explicitly
supplied currency-header HTTP requests returned 200 with FastCGI
BYPASS and private,no-store. Normal anonymous requests have
sometimes HIT in 15–65ms and at other times MISS in ~4–6s;
**stable cache admission and reliable TTFB improvement have not been
established**. Never enable shared cross-IP HTML without separate
first-party market/consent/booking proof. No existing Nginx settings
were modified in this follow-up.

## 19 November — Source HTML cache origin conflict resolved without relaxing privacy

Repeated anonymous mobile requests with the EXACT same UA, Accept and
trusted client IP still returned MISS (4.66–5.17s). A temporary
anonymized boolean-only origin header audit (removed immediately)
observed X-MAD4B-Cache-Eligibility: public-static-v1 alongside an
origin-owned PRIVATE Cache-Control even with CSS-Ready 1 and without
Set-Cookie, private-policy or DONOTCACHEPAGE.

The exact header timeline proved first-party source
mad4b-general-public-page-origin-cache.php advertised public HTML
admission at template_redirect:9000, then source MU plugin
424-mad4b-private-html-css-bundler-v1.php intentionally marked
the same response private at template_redirect:9999. The final
mobile CSS certificate was successfully issued later and did NOT
cause the private response. The 424 CSS-only fallback is deliberately
private to protect current per-request JS/forms and WCML context.

Source-first 424 v1.2.1 now removes the THREE contradictory public
eligibility/browser HTML headers ONLY when its original CSS-only
private guard is active. It keeps the original private,no-store header,
the CSS-only delivery header, shared CSS optimizer behavior, WCML
guards and all original WooCommerce/form/booking invariants.
Read-only 10-case regression, PHP 8 runtime lint, live origin HTTP
200 / CSS-Ready 1 / private,no-store / no public-static admission
are verified. Original live 424 v1.2.0 source is backed up under
root-owned market-html-variance reports for instant rollback.

This is a correctness/safety repair, not a first-byte performance
optimization. Never disable 424's private guard merely to produce
a FastCGI HIT: reliable market-specific HTML price/nonce/consent
isolation and independent browser/booking proof are still required
before claiming safe public-shell caching. No current production
Wayfatours files, WordPress payment policies or Nginx configuration
changed.

## 19 November — consent banner explained the apparent Swiper click failure

Real Playwright source/native and source/cached sessions initialized the
Swiper component with identical card/link counts, link SHA and dimensions.
The first 390px and 768px click probes were non-actionable because
the first-visit consent banner, role=region and selector
mad4b-consent-banner, was visibly above the cards at z-index 2147483001.
A native Chromium layer survey identified the exact nearest obstructing
DOM parent as section.mad4b-consent-banner. Three temporary CSS z-index
and pointer-event variants in an isolated browser test **did not** remove
the obstruction, correctly showing this is a consent state, not a CSS
regression or destination-card cache bug.

The revised v3 QA interacts with the REAL site's Reject optional button,
then verifies the banner is hidden BEFORE evaluating Swiper, while
mocking ONLY the optional consent-receipt POST within the isolated test
browser to avoid test data writes. It uses genuine pointer click +
keyboard focus, and compares errors and DOM/link geometry between the
cached and native bypass variant at 390px and 768px. The original
site's consent UX is not changed or overridden.

The prior consent-visible baseline must not be interpreted as an
interaction failure of the underlying slider. A v3 browser run must
still complete independently before the post-consent pointer actions
can be declared passing. Consent audit remains mandatory on real
site visitors; the test's in-browser interception is never deployed.
