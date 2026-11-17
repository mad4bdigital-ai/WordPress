# MAD4B Cart Island — permanent independent component contract

## Current flow

Wayfatours uses payment_only. Fluent Form #6 saves a request; the Commerce
owner validates any payable advance and creates or finds the Woo order. Its
confirmation offers the Woo order's signed order-pay URL. Woo Cart, public
shop and ordinary cart/checkout pages remain disabled. This code never
enables them, alters the Form #6 submission, payment links, Stripe, taxes,
Woo order metadata or pricing.

## Future storefront: isolated cart, not a page session

A first-party reusable shortcode, [mad4b_cart_island], and a Classic Woo Cart
Widget compatibility adapter render a cache-neutral shell only in standard
storefront mode, never on order-pay or order-received. Native Woo Mini-Cart
Blocks already implement this pattern with Store API; do not duplicate their
storage.

Cart data is fetched directly and privately from the canonical WooCommerce
Store API GET /wp-json/wc/store/v1/cart when the island is opened, when Woo
dispatches an add/remove event, or when an existing cart signal is present.
The island updates without page navigation. The fetch must return no-store,
private JSON. If not, private state is cleared, never leaked into shared HTML.

Static shell includes no private data, user cart contents, order IDs,
payment URLs, Cart Tokens or Woo session credentials. Only textContent
is used for untrusted product names. Data and tokens are never written
to localStorage or sessionStorage. Static assets are independently cacheable.

## Caching and privacy matrix

PUBLIC SHELL:
  Existing FastCGI/WordPress public page gates only, cache-independent widget.
PRIVATE CART:
  Native Woo Store API, private/no-store per session, never HTML/edge cached.
BOOKING:
  Fluent Form #6, Commerce and order-pay signed URLs, private/no-store.
STATIC:
  Island JS/CSS do not vary by visitor or cart and can be cached as assets.

An important limitation remains: current Wayfatours Nginx excludes any
wp_woocommerce_session_*, cart hash, country or other sensitive cookies from
the shared public HTML cache. This code does not weaken that boundary.
Consequently, a returning cart holder may still get a full-page cache BYPASS,
even though the Cart Island is rendered independently. A public HTML HIT with
an active Woo session requires independent proof that the particular page's
server-rendered content (including price, market, tax, currency, forms,
Elementor and other widgets) is identical to a clean public shell.
Never allowlist Woo cookies or ignore session identity without that proof.
This prevents cross-visitor data leakage and cache poisoning.

## Default and future activation

- payment_only: shortcodes return empty, legacy cart fragments disabled
  only on public nonpayment routes; signed order-pay remains native.
- standard: explicit shortcode or a classic Cart Widget mounts static
  Cart Island. JS and CSS load only if the island is mounted.
- mad4b_cart_island_enabled_v1=off disables the island without deleting
  Woo cart/order data.
- Future add/update/removal actions remain owned by Woo and its Store API
  nonce and Cart Token authorization.
- For each new company, verify native Woo price, localization, active theme,
  forms, market context, logout, customer cart and shared-cache proof. This
  feature is company-neutral: no ETG-named new runtime owner.

## Verification evidence, 17 November 2026

On Wayfatours, the code is installed and dormant under payment_only.
A read-only WP-CLI simulation of future standard mode confirmed identical
public shell markup, no per-user state or payment URLs, unique instance IDs
and no persistence of the simulated option.
PHP tests covered disabled/standard/payment/native-script variants.
Chromium used two independent, mocked Woo Store API sessions with different
item names/counts: no reload, no cross-session leak, no HTML injection,
no browser storage, invalid public-cache API response rejected.
Live Woo Store API GET returned private/no-store. No real cart purchases or
changes were exercised, because the Woo public cart remains disabled.
