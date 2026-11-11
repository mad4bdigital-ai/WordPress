# Company-branded transactional request email — verification

**Change:** 2026-11-11. **Scope:** company-owned WooCommerce request lifecycle email, not all WordPress emails. **Central composite release:** HOLD. No payment capture or customer mail test sent with this change.

## Provenance and presentation

- Identity resolves by the current enrolled site using MAD4B_SCP_Company_Communication::brandIdentity, never a form field or foreign company override. Sender and reply-to remain under Company Communication.
- Existing Look & Feel keys supply the brand logo, tagline and legal line. Active Elementor Kit supplies primary, secondary, accent, text and surface colors. Missing logo falls back to the Woo text header; foreign or unsupported media is rejected.
- Original WooCommerce header/footer remain intact; temporary option and logo-link filters apply only during the MAD4B lifecycle message render and are removed in finally. No WooCommerce or Elementor global settings were overwritten.
- Slogan uses original Look & Feel, optionally WPML string translation. If missing for a non-default language, English slogan is omitted rather than incorrectly presented as translated.
- Email uses email-safe table layout, localized status labels, verified translated tour name, locale-scoped legal links, and a strictly guarded payment CTA.

## Traveler language and links

- Request locale comes from saved Fluent Forms submission source_url, checked against the same site and WPML language mode. Invalid or missing URL falls back to the verified tour language.
- Tour and page URLs are resolved via wpml_object_id and wpml_permalink, verified published and on the same site. Missing translations or unsupported alternate domains yield no link, not the wrong language or company.
- Homepage and WooCommerce logo URLs are language-localized; the Spanish message never inherits the default English home link.
- Generic storewide Woo additional_content is not applied to this flow because it may be in another language or off-brand.
- Stale raw pay URLs were removed from the request message composer. Only the existing authenticated WC_Order advance CTA can render when eligibility and payment state permit it.

## Execution and tested limits

- Source tested and applied to tours.dev.mad4b.com first, then wayfatours.com, with pre-update backups in /opt/mad4b-company-provisioning/reports/company-branded-email-20261111/.
- Isolated PHP cases: brand identity, translation and scoped links 18; traveler request language 11; Woo mail contract 25; opt-in loyalty 51. No real payments, emails or database writes.
- Live WordPress checked Spanish tour, header/logo, privacy, terms and homepage URLs on both sites. All five rendered Spanish hrefs passed same-host and exact-language tests. No order-pay URL was emitted.
- Wayfatours EN/ES active locales passed. Tours.dev all six active locales EN/AR/FR/DE/ES/IT passed a later readback: every available published home/privacy/terms link was correctly scoped to the site and requested language.
- Prior Wayfatours 3 failed lifecycle mail jobs were not replayed. Earlier 3 Gmail SMTP acceptance tests are separate from this brand design update.
- V1 historical provenance intentionally rejects these newer source bytes; V2 manifest/verifier covers all five new source files, mail dispatch authority, locale contracts, tamper resistance, no monetary discount and no release permission.
- Gmail mobile / Outlook / Apple Mail visual screenshots and full Original 318/313 composite release certification remain outstanding.
