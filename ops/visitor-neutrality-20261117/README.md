# MAD4B Visitor Preference Engines — canonical source and release boundaries

## Canonical owners

- mad4b-country-switcher: visitor country selection. Country catalogue comes from WooCommerce; Market Profile authorization is checked separately.
- mad4b-traveller-nationality: explicit passport nationality and automatic country-following mode. No new ETG- branded engine name.
- visitor-preferences/v1: existing first-party REST and header UI remain authoritative, without duplicate storage, Woo checkout or pricing authority.
- etg-traveller-nationality-switcher: historical WordPress activation basename only. Its entry file delegates to the canonical owner. Historical ETG_ aliases, shortcode and option/meta names are isolated in includes-legacy-compat.php.

## Migration for each company

1. Inspect site installed plugin version, active_plugins, WPML languages, source SHA, read-only visitor-state bootstrap, asset ownership and saved preferences. Preserve newer company-specific assets; never overwrite unseen changes.
2. Install the canonical folder and verify its original PHP, checkout, REST and header behavior before replacing the active basename. Do not ship the legacy bridge ahead of the canonical plugin.
3. If the site has an early option_active_plugins lean bootstrap, add canonical plugin basename without removing login/security bypass or legacy compatibility before activation.
4. Atomically replace the legacy basename with mad4b-traveller-nationality/mad4b-traveller-nationality.php in active_plugins. Do not activate two independent engines concurrently.
5. Update early first-paint CSS asset registration to use canonical paths when activated, with legacy fallback. Keep original Elementor and WPML ownership.
6. Test fresh guest, returning cookie, manual passport, auto reset and existing cart across every active language; ensure signed site UUID and deployment binding are enforced.
7. Personal HTML remains private/no-store. Never allow country, nationality or Woo cookies into a shared HTML cache. Mobile CSS Async + Inline requires matching Critical CSS proof for the exact private/public bundle, language and page. Otherwise serve complete native CSS.
8. Preserve narrowly scoped rollback preimages. Avoid global LiteSpeed CSS/JS purges or removing verified CSS certificates.

## Verified Wayfatours runtime (17 Nov 2026)

Wayfatours Production uses the canonical MAD4B traveller-nationality plugin after an atomic activation update. Public EN/ES routes and read-only preference endpoints returned HTTP 200. Guest country, manual nationality and auto reset operations retained their values across EN/ES without creating a WooCommerce session cookie. Real personalized ES HTML has a different full CSS bundle from the anonymous-certified ES HTML: reusing its anonymous certificate would be invalid. This document does not claim rollout or certification on other companies.
