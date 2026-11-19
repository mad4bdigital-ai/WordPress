# MAD4B mobile public-shell / private CSS split (19 Nov 2026)

The live Tours.dev source MU plugin `424-mad4b-private-html-css-bundler-v1.php`
was previously admitted for **anonymous, cookie-free public HTML**. Its
`template_redirect` protection then set `Cache-Control: private, no-store`
and removed the origin's public eligibility claims. This interfered with
FastCGI and the site-owned public Critical CSS flow.

The source-captured v1.2.3 code now refuses that **private CSS-only fallback
for every cookie-free page**, including home, archives, tours and destination
listings. It retains the original site/environment checks, shortcode/query,
authentication, WooCommerce, signed preference validation and original JS
and CSS behavior for private customers. The fix is source-matched, not a
license to store personally segmented HTML in a shared cache.

**Important:** This is an exact, reviewed Tours.dev source snapshot. Wayfatours
production has a different original `424` (v1.1.0) and independently certified
public + signed-private CSS paths. Never copy this staging file over that
company without a preflight diff and separate site-aware deployment verdict.
New companies may adopt the shared cache-primer by creating independently
registered policies, per-language CSS certs and verified first-party Nginx
site keys. A missing certified route stays `AWAITING_CCSS` and never
masquerades as ready. Protected checkout, authenticated requests, Woo
sessions and user-private preference HTML remain uncached in public buckets.
