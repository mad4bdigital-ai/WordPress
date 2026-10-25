=== MAD4B Country Switcher ===
Contributors: MAD4B
Requires at least: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce, mad4b-site-control-plane
Stable tag: 0.1.0-local
License: GPLv2 or later

Local Lab prototype — not certified for public release.
Requires MAD4B Site Control Plane with class-mad4b-scp-switcher-sources.php and approved Market Profiles, WPML for language context, and WooCommerce.

Shortcode: [mad4b_country_switcher]
Country selector is independent of billing/shipping, prices, taxes and WCML currency. It keeps the ISO2 country across WPML language changes, resolving the current approved country+language Market Profile. GeoIP is advisory only; the visitor must make an explicit choice for commercial eligibility. Guest preference is held in Woo session and HMAC-signed HttpOnly 90-day cookie.
No bundled flags, country names, currency tables, or exchange-rate logic. References existing WPML SVG assets and Woo country names via MAD4B data adapter.

Acceptance required before production: MAD4B adapter packaged/versioned, native WPML translation paths, WCML checkout/Blocks, CDN cache, real MaxMind source, formal CI and staged rollout.
