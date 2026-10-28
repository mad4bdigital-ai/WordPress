# QA40 — Commerce engine, product context, presentation and admin diagnostics

**Date:** 2026-10-28
**Scope:** ETG Woo Lab ONLY, PHP container `etg-woo-lab-php`, WordPress `local`, DB `etg_woocommerce_lab`, `SHARED_PRODUCT_LAB_ONLY=true`.
**Production changes:** NONE. No customer checkout, supplier confirmation, payments, Woo Order writes or financial approval.

## Installed modifications

### Commerce Composition — native owned code
- `includes/CommerceEngineInspector.php`: read-only source-owned engine catalog, incomplete pricing rule diagnostics, verified source/Rate relationship checks, native CPT WooCommerce product identity, Booking Experience form-routing receipt and indicative UI integration facts.
- `includes/CommerceEngineDashboard.php`: WordPress **Tools → Commerce Engines**, bounded admin-only table of engine/owner/state, incomplete rule list, single product/Rate/date inspection form. No background crawler and no customer details.
- `includes/CompanyManagementRest.php`: audited admin-only accessor for the enrolled company context, and route names in management definitions.
- `composition.php`: register the two classes using existing WordPress plugin bootstrap.

### MU presentation
- `wp-content/mu-plugins/transport-component-price-tags.php`: Elementor pricing-role control renamed from **Current final** to **Current indicative**. The original price provider and output are unchanged.

## HTTP / REST

```
GET /wp-json/commerce-composition/v1/management/engines
GET /wp-json/commerce-composition/v1/management/product-context/{source_id}?rate_id={id}&travel_date=YYYY-MM-DD
```

Both require WordPress `manage_options`, return read-only v1 diagnostics with private/no-store responses, and have no mutation counterpart.

The company-bound native CPT source and Rate type allowlists come from `commerce_management_company_definitions_v1` via MAD4B Site Profile v2; diagnostics do not create new company-provider credentials or write capabilities. If form policy is absent, response explicitly includes `booking_available=false` and `checkout_authority=false`. A readable Woo Product identity or enabled Fluent Form NEVER certifies a sellable booking.

## Real WP-CLI results

| Suite | Passed |
|---|---:|
| New QA40 Engine Inspector / REST / Admin | 20/20 |
| Native Elementor Dynamic Tag safety | 12/12 |
| Dynamic CPT/Transport UI wording extension | 7/7 |
| QA39 company REST regression | 33/33 |
| QA39 Catalog regression | 11/11 |
| QA39 Role governance regression | 9/9 |
| QA38 Lifecycle regression | 17/17 |
| **Total** | **109/109** |

PHP lint and installed/source parity for four Composer files passed. Both the Commerce Composition and the MU presentation patches replayed from their precise pre-change snapshots and produced identical files. HTTP local homepage 200, unauthenticated REST 401. These are code-level/WordPress REST tests; actual responsive-browser visual certification in Elementor editor and published pages is **not claimed**.

## Read-only source audit and open gaps

- Tour pricing owned by `shared_product_pricing_quote_contract_v2`, with rule catalog containing partial supplier cost/margin, child/age, accommodation, transport, availability; tax/fees, coupon, financial approval unintegrated. Pricing preview is **not** a signed final Quote.
- Accommodation pricing by Accommodation Booking Core. Provider is mounted, but unit inventory/hold, supplier confirmation and order binding not proven ready.
- Transport pricing by Booking Experience / transport-rates CPT. Elementor display is indicative, never "final" merely because it renders a number.
- Product identity by CPT Woo Integration, *not* a duplicated `product` post. Rate/season/provenance by their original domain owners.
- Booking form ownership by FormRouter and existing Booking Experience site policy. A published booking form and "booking available" flag do not grant checkout.
- Elementor and JetEngine presentation providers are mounted; no interactive cross-browser, mobile, WPML or all-template certification in this QA.
- Company Site Profile **origin binding is mismatched and authority is quarantined** in Woo Lab: all management POST remains denied by QA39. No automatic secret/identity rewrite is appropriate.
- No provider-complete contract for manager-approved financial quote, traveler acceptance, WC order creation, supplier booking, or accounting. Existing unassigned tasks / failed Workflow require source-specific reconciliation.
- No WP Role/Capability change.

## Rollback / packaging

- Source: `/opt/commerce-components/composition`.
- Installed Lab: `/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/plugins/commerce-composition`.
- Evidence and backups: this directory, including `composition.before-qa40.php`, `CompanyManagementRest.before-qa40.php`, and `transport-component-price-tags.before.php`.
- Apply **Commerce Patch** on top of an exact QA39 source tree, then separately **MU Patch** on the matching presentation tag source. Verify SHA256/parity/lint after any migration.
- The WordPress GitHub repository PR is a *source patch and evidence handoff*, NOT an installable Commerce Composition full plugin release. Full source onboarding and staging release attestation remain required.

## Source review

Correctness: 109 fail-closed behavioral tests. Security: admin-only, no PII, no writes, exact company allowlists, WP native capabilities. Architecture: reuses canonical price/form/provider contracts; no duplicate price engine. Readability: two focused classes and explicit state labels. Performance: one selected product diagnostic and bounded catalog; no template crawl per admin page. Deferred: visual/browser QA, verified positive order/supplier flows, and complete financial quote policies.
