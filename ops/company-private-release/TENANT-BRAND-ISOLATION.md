# Tenant brand isolation for reusable WordPress and Elementor templates

**Decision:** Shared MAD4B sites may reuse layout **structure and behavior**, but may **not** reuse another company's visual identity or account-bound settings.

This policy is effective for the private company candidates in PR #619 and is a deployment gate, not a live-site mutation.

## Portable across companies (after compatibility review)

- Layout hierarchy, containers, grids, responsive breakpoints, card/list/search-widget composition, reusable widget definitions, dynamic field contracts, archive/single template logic, interaction patterns, and generic accessibility settings.
- Neutral Elementor templates and patterns only after the template's brand-bound fields are replaced by per-tenant bindings.
- Forms' **field schema / validation / general UX** may be shared when the business workflow matches; form IDs, recipients, payment integrations, automation/webhooks, security tokens, and submission data must remain tenant-specific.
- Rank Math **technical SEO rules** may be shared as source code where applicable, but organization schema, social profiles, titles/defaults, robots overrides, sitemap configuration and local SEO identities are tenant-owned.

## Company-owned, MUST remain separate

- Elementor Site Settings / active Kit: `elementor_active_kit`, its Kit post and `_elementor_page_settings` (global colors, typography, default buttons, background, responsive brand scales).
- Logos, favicons, brand marks, brand names, taglines, font families, custom font files, color palettes, gradients, design tokens and brand imagery.
- Header/footer **identity content** and company-specific menus, contacts, branches, company social links and account-bound dynamic tags. Their layout may be shared, not their content.
- Astra `theme_mods_astra`, widgets, customizer values, menus and custom CSS if containing identity or brand styles.
- Other tenant-local `wp_options` values including URLs, `home`/`siteurl`, WPML language mappings, WooCommerce shop identity/tax/checkout, emails/SMTP, form settings, Rank Math entity identity, keys/licensing and integration credentials.
- Elementor Template/Kit `post_id`, media/attachment IDs, terms, WPML translations and each company's DB relationships. Re-map references by stable keys and tenant-owned identifiers; **never copy numeric IDs blindly**.

## Correct migration order

1. Start from **each company's own verified Production DB backup**, cloned to its own **isolated new DB**. That carries forward its existing `wp_options`, Elementor Kit, WordPress identity, Rank Math settings, Fluent Forms and all serialised metadata without a destructive merge.
2. From the canonical approved MAD4B release, install **shared source code and portable template structure only**. No whole-table `wp_options` replacement from ETG Staging or another tenant. No bulk `REPLACE()` across serialised values.
3. Inspect the template exports before transfer. If there are literal colors/hex values, fonts, logos, image URLs, external domains, email addresses, IDs, shortcodes, embedded CSS or unknown `__globals__` references, classify as **brand/site-bound for review**.
4. Bind styles to the target company's **active Kit** and local token map (`primary`, `secondary`, `text`, `accent` and custom named tokens). Ensure tokens resolve to the intended target Kit; a source Kit's numeric IDs must not be assumed valid.
5. Match targeted Fluent Forms configuration, notifications, conditional logic and Rank Math identity independently from the company's **own backup**. Never copy submissions, passwords, OAuth credentials or webhook secrets between companies.
6. Compare old/new in a company-only, non-public preview across desktop/mobile, templates, headers/footers, widgets, WooCommerce pricing, WPML, forms, brand assets and SEO. Compare color palette + typography tokens before/after and reject brand drift.
7. Permit live cutover only after an exact-head source review, independent database restore, functional QA, owner acceptance, media origin TLS/tenant authorization, and rollback.

## Invariants

- `tenant_B.brand_kit` **must never become** `tenant_A.brand_kit`.
- `tenant_B.wp_options` is sourced from tenant B's existing database, never another tenant's.
- A shared Elementor template is portable **only** after it has been audited and its brand/site bindings stripped or remapped.
- Existing sites and their assets remain untouched during candidate preparation. Preview mode must not send mail, payments or webhooks.
- **No defaults to brand A** when a target brand token is missing; fail closed for review.
- A reusable theme does not imply a reusable theme customization record.

## Audit interface

The standalone `audit_elementor_template_portability.py` performs conservative **read-only** checks of Elementor JSON exports and returns a summary with field paths, not branding values. It does not import or modify templates, settings or live sites. Any flagged export requires manual review and target-tenant token remapping before declaring it reusable. Passing its heuristic checks alone is **not** a production release authorization.


## Clarification: shared JetEngine Brand / Look & Feel Options Pages

The **Options Page UI and field definitions** may be reused across tenants. This does not change the rule that Brand values (colors, fonts, logos, contact content) and Look & Feel values (header images, review gallery URLs, media IDs, source links) are **company-owned**. Elementor active Kits also remain company-owned. Never copy the entire donor `wp_options['look-and-feel']` array or invent values for missing target fields. A new shared field should start unset in the tenant until a tenant-specific source supplies its content. Details: `JETENGINE-OPTIONS-SCHEMA-OWNERSHIP.md`.
