# All Royal Egypt: company-specific sales, languages and home-page variant

## Classification (verified October 29, 2026)

The core platform, common widgets, Elementor section structure, JetEngine field schemas and reusable templates are versioned once. **All Royal Egypt is not obliged to use every section or market behavior in the ETG/Wo Vacation demo.**

The company's **existing Production database** is the authoritative source of brand, content, published pages, WPML translation relations, sale regions/currencies/prices and the active homepage composition. No other tenant's option values, pricing policy, image URLs, or numeric post/template/query IDs may overwrite it.

### Current source observations (read-only)
- Main domain `allroyalegypt.com`. Published WP homepage ID `35138` and `page_on_front=35138`.
- **WPML**: configured active languages `en, es, de, fr, ar, it` (default `en`). The `ar` language is **also marked hidden** in the company's current WPML settings; these flags are not interchangeable. Preserve them; do not accidentally expose/hide during migration. The `wp_icl_translations` source has records in each of the six languages. Translation groups, local translated IDs, language permalink mode and taxonomy translation settings remain tenant-owned.
- **About Us**: published page with slug `about-us`, source ID `45790`, nonempty post content and Elementor page settings. An additional published page with slug `about-us-2` is present. Do not automatically select it as the canonical page or discard it; verify translation/language and menu routing.
- **Homepage**: current source page references ten Elementor templates plus three `elementor-template` shortcodes. Relevant sections are `Home Tours Section`, `Home Why Us Section`, `Home Global Faqs Template`, `TrustIndex.io Trip Advisor Business Reviews`, `Home Attractions Section`, `Home Locations Section`, `Home Recommended for You`, `Home Blog Secction`, `News Letter Sign Up - section`, and **`Combined Tours`**. These identities and bindings must be reconciled by stable functional key in the company's own DB, not by source numeric ID.
- **Combined / Multi Country Tours**: current Homepage embeds Elementor library document `28340` (`Combined Tours`), containing a `jet-listing-grid` referencing existing JetEngine listing `Tours & activities Listing 2`. The same widget has a custom-query ID; that ID is **not a WordPress `wp_posts` record**, which is not by itself a failure. Its true JetEngine/provider resolver and dataset semantics must be verified separately. **For All Royal Egypt the Multi Country Tours/Combined Tours feature substitutes for the demo's Daytour Your Way section.** It must not be replaced by the demo section during copy or template promotion.
- **Selling / rate policy**: 181 published `tour-rates` records were observed; their metadata includes `base_currency`, `double_price`, `single_price`, `triple_price`, date validity and policies. `has_egyptians_price` exists on 141 `tours-and-activities` records. Local fields include `egyptians_currency` and per-occupancy price variants on a small subset. The precise multiple-country selling rules and checkout/payment geolocation are **not yet certified**: WooCommerce basic currency options were not present in this source check, so DO NOT infer that WooCommerce Multi-Currency is the implementation. Preserve the existing per-country/rate/nationality policy and classify custom origin/destination taxonomies before porting.
- Geography: known location taxonomy country terms include Egypt, Jordan, Greece and Saudi Arabia; this is evidence of configured destinations, **not proof of country-specific tax/legal selling rules**.

## Explicit company variant mapping

| Functional slot | Common system behavior | All Royal Egypt variant |
|---|---|---|
| Homepage journey/itinerary merchandising | Shared slot/section interface | `multi_country_tours` via reviewed Combined Tours listing/query |
| Demo alternative | `daytour_your_way` if chosen by a different tenant | **not automatically enabled**; no silent fallback/replacement |
| About / company story | Shared page layout and supported elements | Local `about-us` content, media, page meta, menus, translations |
| Homepage data | Reusable Jet listing widgets, search and template components | All 10 template slots and 3 shortcodes sourced from this company's database |
| Country/market selling | Reusable validation, rate schemas and checkout abstractions | Source country/nationality/currency/occupancy/rate policies only |
| WPML | Shared translation integration code | `en,es,de,fr,ar,it`; default EN; Arabic hidden flag preserved |
| Brand and business sources | Shared Options Page/Elementor schema | Company's own Brand Kit, Look & Feel field values, dynamic media, Trustindex and QUIC.cloud |

## Acceptance on isolated tenant clone (not performed yet)

1. Clone **All Royal Egypt's own** database; do not seed with ETG Commerce Staging content. Keep preview emails/webhooks/payment writes disabled and no Production mutations.
2. Prove `page_on_front` resolves to the **right company home**; all live source template/shortcode bindings resolve, and the homepage displays the company-chosen Multi Country Tours slot.
3. Verify Combined Tours JetEngine listing and custom query through the actual provider, with target-owned query/content IDs; fail closed if unresolved. Do not inject Daytour Your Way as a fallback.
4. Preserve `about-us` and `about-us-2`, resolve their WPML translation and menu links by company content keys. Compare page content, page template and media sources before/after.
5. Check all six WPML translation languages, default EN, URL negotiation mode and **AR hidden status**; no silent addition/removal/visibility flip.
6. Independently inspect local pricing and country rules: tour-rate associations, date ranges, occupancy, Egyptian-specific price flags, base_currency, WPML price/currency display and checkout billing/eligibility. Require functional acceptance by market, not just schema equality.
7. Confirm only this company's Elementor Brand Kit, JetEngine Options Page values, email, GTM, SMTP, Rank Math, Trustindex, QUIC.cloud, and VPS media origin bindings are present.
8. Approve the exact release source and tenant-specific release variant before touching `public_html` or DNS.

**Verdict:** source inventory and company-variant policy recorded; active deployment and actual query/pricing parity still **NO-GO**. No shared-source defaults may replace the tenant-specific section or content.
