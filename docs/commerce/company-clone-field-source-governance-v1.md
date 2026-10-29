# Company clone field-source governance (v1, proposed)

**Scope:** Source-backed discovery and company-provisioning policy for the WordPress tourism platform. Tracking issue: [#626](https://github.com/mad4bdigital-ai/WordPress/issues/626). Machine-readable candidate: [field-source-policy-v1.json](../../governance/company-clone/field-source-policy-v1.json).

**Desired demo:** Wo Vacation at `tours.dev.mad4b.com`. **Updated 29 Oct 2026:** the live WordPress `blogname`, Elementor active Kit `60050` `site_name`, WPML Blog Title string and Rank Math Organization/Website names are now **Wo Vacation**, changed successfully through the supported WP-CLI/Elementor integration on Staging. Previous permission error affected an ordinary PHP CLI without `WP_CLI` context. The owner explicitly declined creation of a new backup for this change. **This is not a full brand-neutral migration:** legacy company contacts, social links, dynamic review/gallery media, editorial references and provider account bindings still need source-backed isolation; no new company has been provisioned.

## 1. What was actually discovered

Read-only WordPress source census on `etg_commerce_staging` on 29 Oct 2026:

| Source | Distinct observed field-level occurrences | Physical evidence |
| --- | ---: | --- |
| `wp_options` | 2,461 keys | Option names and value lengths only, no values |
| `wp_postmeta` | 801 (post type, meta key) combinations | 16 relevant CPT/template families |
| `wp_termmeta` | 148 (taxonomy, key) combinations | Taxonomy terms including destination metadata |
| `wp_usermeta` | 253 keys | Names and counts, never user values |
| Integration schemas | 1,174 columns across 111 tables | Names/types only |
| **Total** | **4,837** | Root-only detailed census with SHA-256 |

Complete source index with field names and preliminary classification is stored with `0700` directory/`0600` files on the VPS in:

`/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/company-clone-field-governance-20261029/`

`field-source-census.json` SHA-256: `7d9401c5748dab414fb883b2b46c65d9c9c5c1c8857e651dd7fd73fb7c604291`.
`field-policy-discovered.json` SHA-256: `b6c79f53443d599c6d9b57c94fbb4a7d1e656d445f3e91877150376450938a0e`.
`field-policy-report.md` SHA-256: `6fbc5bee3b084273f72cdaef646786f5603b15d3d3daabcd81e9c38000dceb9f`.

The provisional classification has **2,008 unknown occurrences**, and they are blocked by default. The GitHub manifest intentionally contains only reviewed source groups and explicit field rules; it does **not** copy raw option values, credentials, user/commerce records or dynamic option nonce identifiers.

## 2. Source ownership and clone behavior

| Source / owner | Shared across companies | Must originate from new company |
| --- | --- | --- |
| Astra/Elementor/JetEngine | Versioned layout, widget, listing, filter and CPT definitions | Brand content, links, images, source queries, SEO, company-only colors |
| `contact-and-help-info` | Option-page schema and generic control labels | Contact email/mailto, phone, WhatsApp, social profiles, location, help links, favicon/logo |
| `look-and-feel` | Option-page schema, neutral headings/labels and card layout settings | Header/banner images, review-media references, accent/colors, style presets and business-specific images |
| Our Traveler Reviews template | Template structure and reusable design | Dynamic gallery/media, actual review text, photos, reviewer/source accounts and provider bindings |
| Rank Math/Schema | SEO settings **schema** and neutral variables | Organization/site name, URL, logo, OG image and analytics/social integrations |
| WPML | Languages and neutral translation conventions | Per-language term/post IDs, company text, permalink and source-map selection |
| Destinations | Place IDs/geo coordinates and validated taxonomy structure | Company featured images, galleries, branded descriptions and pricing |
| Tour, hotel, transport & rate CPTs | CPT schemas and rate calculation rules | Tour/supplier listings, availability, dates, prices, stock, media and transactions |
| Fluent Forms, Woo & integrations | Form layout and commerce feature schemas | Recipients, payment gateways, submissions, customer data, bookings, inventory |
| Users and authorization | Role definitions and capability policy | Fresh users, site UUID, delegated grants, OAuth/app passwords and audit trail |
| QA fixtures | Test definitions only | Test records stay in isolated sandbox, never copied to company site |

A previously observed wrong-brand configuration demonstrates the need for field-level checks: the source had Rank Math identity matching Egypt Tour Gates but an earlier set of fields still referencing Dona Tours; contact options also pointed at legacy staging handles. A global search-and-replace is **not** permitted because gallery URLs, licensed media, serialized Elementor JSON, remote account references and real content ownership require distinct treatment.

## 2A. JetEngine Options Pages: company-level ownership and demo seed separation

Read directly from registered `Jet_Engine_Options_Pages::get_items()` on Wo Vacation Staging on **29 Oct 2026**. Source-controlled full, no-values field manifest: [jetengine-options-source-policy-v1.json](../../governance/company-clone/jetengine-options-source-policy-v1.json). The 11 registered pages contain **180 top-level fields**, including **64 repeater-child definitions** across communication and dynamic-pricing controls. The JetEngine registered source differs from the historical serialized values: `look-and-feel` has **54 registered definitions but only 39 persisted keys**; `contact-and-help-info` has **16 registered definitions and 17 persisted keys**. Three old persisted-only keys are **blocked for cloning**: `look-and-feel.busness_reviews_section_tab`, `look-and-feel.destination_activity_card_alternate`, and `contact-and-help-info.social_links_tab`. The remaining missing stored values often represent layout/tab definitions or optional unset fields; absence must not be interpreted as a target company's approved default.

| JetEngine Options Page | Fields | Source ownership | Clone behavior |
| --- | ---: | --- | --- |
| `look-and-feel` | 54 | Visual identity, destination copy, banners, reviews/gallery | Share field/schema layout only. Media and patterns per tenant; the recent Wo Vacation marketing placeholders belong **only** to the demo |
| `contact-and-help-info` | 16 | Contact email, WhatsApp, support, social, internal CTA, logo | Per-company verified channels; leave blank or disabled rather than invent an address/account; no source-company tokens or photos |
| `company-communication-operations` | 24 | Company display name, sender/reply routing, staff and supplier repeaters, mail policy | Company-specific identity and personnel, default outbound OFF; never copy another company's people or SMTP |
| `company-operations-policies` | 5 | Deletion/publication/pricing authority and staff limits | Company-scoped governance; no inherited owner grants |
| `global-offers` | 18 | Offer flags, CTA, audience and brand colors | Company marketing, ID remapping and safe offers OFF until approved |
| `service-activation` | 19 | Catalog channels, recommendations and market gates | Company catalog and source proof required; safe default before enrollment |
| `destination-activation` | 3 | Enabled countries, regions and continents | Tenant-specific destinations with WPML term mapping |
| `etg-dynamic-pricing-controls` | 33 | Booking, formulas, tax, fees, gateways, promotions and 7 repeaters | Financially sensitive, certified and company-scoped; **never copy active pricing/booking state** |
| `abk-date-formats` | 5 | Display/input date format and calendar locale | Per-company/per-language preferences |
| `abk-selling-options` | 1 | Permitted selling price bases | Per-company commercial mode |
| `calculate-booking-price` | 2 | Price formula and display gate | Company pricing, require certified authority |

**Required admin/UI company boundary:** Each Options Page must identify the **active company display name, verified company/site identity and source profile**, and show whether the current value is an approved company value, a `DEMO_ONLY` placeholder, a shared definition, or an unconfigured external connection. This is an architectural requirement, **not an assertion that the JetEngine admin UI already implements such identity banners**.

**Storage constraint:** JetEngine native `get_option(slug)` is a site-global options array. A separate per-company WP database/site can safely own those keys once provisioned, but **a multi-company installation sharing one WordPress DB cannot safely reuse global option arrays as company-specific state**. It requires a server-validated `company_id` / `site_uuid` resolver, company-scoped persistence, capability checks on reads and writes, and **no cross-company fallback**. Existing `MAD4B_Tenant_Field_Registry` can inform content inheritance but does not by itself enforce JetEngine Options Pages tenant isolation.

### Wo Vacation demo placeholders and language status

- The demo contains **11 updated `look-and-feel.destination_*` headings, notices and CTAs**, plus `contact-and-help-info.support_group_url_aria_label`. All are classified **`TENANT_MARKETING`**, and must **not** be inherited as business facts by the next real company.
- The Woo Lab demo title, Elementor Site Kit, WPML title, Rank Math Organization and six-language homepage/SEO copy were updated. Demo feature templates, localized inspiration in place of unverified reviews and localized buttons in place of newsletter signup were also updated. **No fabricated client reviews, contact addresses or working external accounts.**
- **French is now visible**: `hidden_languages=[]`, `/fr/` and `/fr/tours-and-activities/` both return HTTP 200 with `fr-FR` and their own canonical URLs. Earlier statements that French is hidden are obsolete. The French tour archive still contains legacy-site source references, so **full neutrality remains open** under [#625](https://github.com/mad4bdigital-ai/WordPress/issues/625).
- Scope the marketing seed to the demo; for each real tenant require reviewed field values, permitted media provenance and verification of locales/routes/SEO. Never change live booking or provider delivery policies merely to make the demo more attractive.

**Contract status:** source-reviewed and tested, **not runtime-enforced yet**; owner attestation and a separate bounded implementation are required before provisioning or applying company-scoped JetEngine edit guards.

## 3. External provider connections — never inherited

At minimum, inspect and reset/reconnect: **Trustindex/TripAdvisor**, **LiteSpeed Cache / QUIC.cloud**, **Google Tag Manager/GA**, **Meta/Instagram**, **Rank Math Google/Search Console**, **Fluent SMTP/CRM/Forms delivery**, **Woo/Stripe/PayPal**, **WhatsApp**, **Google Drive OAuth**, **JetEngine Maps API**, **Elementor license**, and the site's DNS/email/CDN. A provider's setting existing in `wp_options` proves only that a stored value/key is present, **not** that the provider is currently connected or licensed.

Credentials, app secrets, provider IDs and remote account bindings must be issued for the new company and remain outside any shared template. External deliveries/sync jobs should remain disabled until verified.

## 4. Compatibility with existing MAD4B implementation

- `MAD4B_Tenant_Field_Registry` already provides `inherit`, `local_wins`, `review`, `local_only` for a **subset of content fields** and explicitly disallows commercial/private/security fields in that sync contract.
- `MAD4B_Tenant_Override_Policy` has site UUID and language scoped content overrides; local WordPress attachment IDs are **not** global reusable IDs.
- `MAD4B_SCP_Company_Tenant_Migration` explicitly implements only company-role migration, **not** a WordPress site/database or provider-account clone.
- Existing `mad4b_tenant_field_overrides` and `mad4b_company_migration_decisions` tables have **zero rows** in the audited Staging database. The presence of tables alone does not mean clone policy is enrolled.

This proposed policy extends inventory/decision coverage without bypassing those existing guards. It is **not yet an executable cloning engine**.

## 5. New-company workflow and release criteria

1. Create a new tenant/site identity with distinct UUID, database, domain, ownership and authorized users. Never rename/reuse an existing company's identity as a substitute for provisioning.
2. Derive the target's neutral CPT, taxonomy, form, widget and template structure from versioned GitHub source. Identify each embedded dynamic URL/ID.
3. Require an explicit, per-field provenance decision: `SHARED_DEFINITION`, `TENANT_SOURCE`, `TENANT_MEDIA`, `RECONNECT_PROVIDER`, `REMAP_GEO`, `NO_CROSS_TENANT_COPY`, `SANDBOX_ONLY`, `REBUILD`, or `REVIEW_REQUIRED`.
4. Create new company-owned branding sources: site title, colors, logo, favicon, contact channels, review gallery, company-specific media, terms and privacy pages, SEO knowledge graph.
5. Remap source-neutral geo places and all WPML translation relationships to target-local IDs. Never copy WordPress IDs across company boundaries without a verified mapping.
6. Rebuild from *company-authoritative* tours, hotels, transfers, commercial rates, inventory and supplier catalogs. Do not copy customers, subscriptions, order/booking history, staff authority, lead submissions or CRM contact lists.
7. Reconnect third-party services only with target-company approval. Avoid inheriting Trustindex, GTM, LiteSpeed, payment, SMTP, license, OAuth, AI or analytics credentials/account IDs.
8. Quarantine or exclude all QA data; zero public test fixtures in the new company site. Build caches/indexes locally.
9. Stage a dry-run plan, input manifest SHA, owner approval, before/after snapshots and rollback; require checks for EN/AR/IT UI, company emails, cards, Schema/OG, image licenses, WPML and payment/provider boundaries.
10. If any selected field has no reviewed source policy, stop the clone as **NO-GO**. Never convert a missing company field into another brand's field value.

## 6. Open implementation work

- Convert this reviewed manifest to a **site provisioning field-source adapter** (read, validate, plan, deny unknown, approve, apply, verify, rollback). Prefer using the existing tenant field registry and Control Plane authority.
- Build runtime source resolvers for nested Elementor/JetEngine settings and Options Pages; distinguish shared template structure from company dynamic bindings, especially review-gallery media.
- Add tests that reject account data reuse, leakage of another company's name, external image URLs and QA fixtures; ensure senders/cron integrations start disabled.
- Verify remaining contact/WhatsApp/social links, review-gallery sources, brand-specific logos/captions and external provider bindings for Wo Vacation; core WordPress/Elementor/WPML/Rank Math name sync is complete on Staging, but the wider tenant-neutral content migration is not. Recheck all active WPML languages and public rendered templates before declaring fully neutral.
- Preserve independent DFSB Safe Boot release certification; no GO decision on Production or template cloning is implied.

**Current status: draft for review; no Production mutation or new-company clone applied.**
