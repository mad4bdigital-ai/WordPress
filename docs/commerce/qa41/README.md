# QA41 — Company Commerce Policy, Woo CPT inheritance and Rate sale modes

**2026-10-28 | ETG Woo Lab only | Production unchanged**

## Executed

Reused the installed Commerce Composition plugin, WooCommerce's existing CPT product identities and the existing `RateProvenance` registry. Added `CompanyCommercePolicy` and `CompanyCommercePolicyRest` plus a small Policy section to the existing **Tools → Commerce Engines** admin screen.

A single company-owned, site-bound option `commerce_company_policy_registry_v1` stores revisioned rules and explicit status `draft|active|retired`. Identity derives from canonical `MAD4B_SCP_Site_Profile` and `commerce_management_company_definitions_v1`: site UUID, revision, digest, environment, tenant CPT source lists and per-Rate context field mappings. Reusing these verified sources prevents copied policies between companies from granting access.

### Policy resolution

Order: **company → service type → parent product → Rate context term → Rate → market → channel → explicit override**. Same-stage ambiguous selector matches are rejected, not arbitrarily selected. Every setting comes with its source Rule ID and the resolved result with a SHA256 intent digest.

Company can define price source, supplier cost source, margin method, taxes, availability model, manager review requirement, traveler acceptance method, order timing, supplier booking timing, deposit, currency and `rate_sale_mode`.

### Rate as independent WooCommerce product

Supported `rate_sale_mode`: `parent_with_rate`, `independent_rate`, `both`, `disabled`.

**Actual Woo Lab evidence:** published Tour `60629` and Rate `60820` both resolve to real WooCommerce `WC_Product` objects. Rate `60820` is independently addressable by its *existing* Product ID `60820`, linked to Tour `60629` via the canonical `related_tour_id` declared in `RateProvenance`. There is no duplicate Product record. The Rate also has a validated term reference `related_program_term_id=318`, but it belongs to the `duration-options` taxonomy. This reference is therefore **not assumed to be a certified itinerary/program identity**; the term source is mapped in per-company data, not hardcoded in the shared resolver.

A company policy may select the **parent Product ID**, the **existing Rate Product ID**, or both as *intended* sale-line identities. This must not be confused with WooCommerce purchasability: on this Lab both `is_purchasable()` values are currently **false**, and policy resolution never forces them true, bypasses native eligibility, makes an Order or captures payment.

### Woo Lab policy fixture

Created a single create-only local **Draft**:
- Company defaults: rate engine source, manual supplier cost/tax/margin review, manual inventory confirmation, required manager and traveler approval, no automatically authorized supplier booking, `rate_sale_mode=both`.
- Rate-specific rule for `60820`: `rate_sale_mode=independent_rate`. This is a **Lab demonstration** of inheritance/override, not a Production business policy.
- Per-tenant rate context field map: `tour-rates → related_program_term_id`, from live CPT data; company definition revision incremented to `4`.

No finance, inventory, request, order, customer or task changes were made.

### New REST surfaces

Existing base: `/wp-json/commerce-composition/v1`.

| Method | Route | Behavior |
|---|---|---|
| GET | `/management/commerce-policy` | Site profile, policy version/status, rule definitions and business intent |
| GET | `/management/commerce-policy/resolve/{source_id}?rate_id=...&market=...&channel=...` | Read-only resolved company policy, inherited service/Rate/term context, intended Woo line item, exact matched rule provenance |
| POST | `/management/commerce-policy/validate` | Schema/binding check only, no storage |
| POST | `/management/commerce-policy/commands` | Governed revision- and idempotency-bound replacement **only when Site Profile authority is healthy** |

REST permissions: WordPress `manage_options` **and** `manage_woocommerce`. Policy-only replacement is guarded by site profile origin, environment, deployment binding, signed authority and quarantine status. Uses a compare-and-swap SQL update for revision consistency. Does not itself authorize checkout, sale, payment, booking, ledger or user-role changes.

**Site Profile on this Lab remains quarantined with Origin mismatch**; mutation POST is correctly rejected. The positive replacement path is **not** runtime-certified on this Lab.

### Verification

- QA41 company policy / Rate independent sale-line / REST / ACL / no-mutation: **32/32 PASS**
- QA40 inspector/UI/price tags: **39/39 PASS**
- QA39 REST/catalog/role: **53/53 PASS**
- QA38 Lifecycle: **17/17 PASS**
- **141/141** total WP-CLI checks. PHP lint and source-installed parity passed.
- Source patch `QA41-Company-Commerce-Policy.patch` applied to exact QA40 predecessor snapshots and reproduces deployed files, SHA256 `b7e293e6fbde74c09e1c5cc4d08deb28d4e3d7e564756b174d8b24951f6efa15`.

Rollback backups, policy configuration and results:
`/opt/commerce-components/evidence/etg-company-policy-qa41-20261028/`.

### Release boundaries

This PR includes **scoped source patch + contract + evidence only**, not the complete installable Commerce Composition plugin. Full source onboarding, multi-tenant Staging certification, current Site Profile authority, manager/traveler evidence, fully priced provider Quote, inventory and supplier contract, native Woo checkout/Order Item and payment provider execution, browser/WPML visual QA and explicit Production release are still required.

**Never equate a Rate's independent Woo Product identity with a payable/confirmed booking.** Supported sale-line identity and actual commercial purchasability are independent controls.
