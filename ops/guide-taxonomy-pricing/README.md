# Guide Cost · Taxonomy and Multi-Currency, v1 (2026-11-20)

## Delivery

Implemented in BOTH Tours.dev Staging and Wayfatours Production. No migration of Tour Rates, WooCommerce selling amounts, existing Tour records, or source-language taxonomy terms.

- Native JetEngine tax meta box: MAD4B · Guide Cost per Language — Draft Fields (ID mad4b-guide-language-cost-v1), on guide-languages.
- Pricing UI: /wp-admin/admin.php?page=dynamic-pricing-controls. Section Guide Service · Supplier Cost per Language.
- MU: wp-content/mu-plugins/mad4b-guide-taxonomy-pricing-v1.php
- UI JS: wp-content/mu-plugins/assets/mad4b-guide-taxonomy-pricing-v1.js
- Native box installer: ops/guide-taxonomy-pricing/install-jetengine-metabox.php
- Existing evaluator integration: mad4b-guide-daily-base-cost-v1.php and mad4b-guide-service-composition-v1.php

## Storage and source of truth

Company fallback in wp_options, key mad4b_guide_daily_base_cost_v1, remains:
- Arabic 2,000 EGP
- English 2,500 EGP
- Other languages 3,000 EGP
- Supplier=self, unit=per_guide_day, internal cost only.

Native JetEngine Term Meta for canonical guide-languages:
1. mad4b_guide_cost_mode: inherit or override
2. mad4b_guide_base_cost: original supplier currency amount
3. mad4b_guide_cost_currency: EGP, USD, EUR, GBP
4. mad4b_guide_supplier: self; external suppliers are blocked until separately certified
5. mad4b_guide_cost_unit: per_guide_day
6. mad4b_guide_cost_status: draft or approved
7. mad4b_guide_cost_valid_from: ISO date
8. mad4b_guide_cost_valid_to: ISO date
9. mad4b_guide_cost_note: source proof, at least 12 characters for override approval

Five eligible terms: Arabic, English, French, German, Spanish. QA fixture excluded.

The Pricing Controls panel reads and edits those same Term Meta fields. No second conflicting price storage.

Only a valid company Pricing Controls approval creates _mad4b_guide_cost_cert_v1 and appends _mad4b_guide_cost_audit_v1. The receipt binds canonical term identity, operator, timestamp, source-field hash and monotonic revision. Editing a Term inside JetEngine after approval invalidates its source hash and blocks its previous cost, requiring a new approval. Draft-only Term values never override the company fallback.

Authorized Global Default changes are separately audited under wp_options key mad4b_guide_global_cost_audit_v1. No Global Default amounts were changed by deployment.

## Multi-currency

Approved native supplier amount is kept in the original EGP/USD/EUR/GBP currency. The existing freshness-bounded FX provider pricing_public_fx_rate(source_currency, EGP) converts it into EGP for Guide and Package component cost. The provider rejects missing/stale/non-positive rates; FX snapshot as-of timestamp is included in the internal receipt.

Receipt fields include original currency/amount/supplier, normalized EGP unit cost, FX rate/source/as-of, term revision and cost policy fingerprint. No rate or checkout/selling authority is granted.

Example, mock only: 100 USD/day at the tested verified rate of 53.814593 EGP per USD yields 5,381.46 EGP/day. The Suite also confirms that one actual reviewed one-day Tour and the generic Guide preview consume this normalized amount only within an in-memory metadata mock. No real Term override was saved.

## Governance and safety

Requires BOTH commerce_manage_pricing and manage_woocommerce, per-term edit_term rights and AJAX nonce; source hash/revision optimistic concurrency control; per-source brief lock; review readback and audit. A regular taxonomy edit can draft but cannot approve a cost certificate. QA terms and unknown locale IDs fail closed. Invalid original currency, unsupported supplier, malformed dates and incomplete evidence are rejected.

The live deployment made no retail price, Tour Rate, booking, WCML base currency, FX source, or payment change. All five real languages continue inheriting existing EGP defaults.

## Deployment

Native schema installer is DRY-RUN unless MAD4B_GUIDE_TAX_SCHEMA_INSTALL=1. The exact existing JetEngine registry was backed up before Staging and Production writes:
  /opt/mad4b-company-provisioning/backups/guide-taxonomy-pricing-20261120/

The installer merges the precise new box without replacing unrelated JetEngine definitions, refuses conflicts, and is idempotent. An early staging-only schema lacked the native box ID; this was repaired before Production deployment, with no warnings on the final acceptance.

## Tests

- tests/verify-schema-and-fallback.php: exact native JetEngine schema, legacy EGP values, 60451 cost, 55260 no-guide cost=0, 60443 unreviewed, stale Global Defaults rejected.
- tests/verify-multicurrency-mock.php: source currency USD, verified FX to EGP, Package cost and preview use the Term rate, changed source blocked, stale approval denied, no live Term Meta writes.
- tests/verify-admin-and-runtime.php: runtime native box registration, Pricing Controls panel 5 terms plus Global Defaults, scoped AJAX, unauthorized user denial and page isolation.
- Existing Guide composition / Form #6 / Rate inclusion tests remain required regressions.

## Future

External Guide suppliers need a certified provider binding and price contract before permitting supplier IDs other than self. Additional rates by season/market or hourly Guide schedules should use a separate, versioned provider-cost ledger, not a second copy in the selling-rate model.


## 2026-11-20 — UI visibility correction

The initial Guide Cost admin script contained an invalid extra closing brace. The browser rejected the whole script, so the cost panel generated by admin_footer did not mount into the native Pricing Controls interface; it was not accessible from the six standard functional sections.

The corrected module:
- Passes Node.js syntax verification before deployment.
- Mounts the supplier cost editor inside the actual Pricing Controls main content column.
- Adds a seventh Guide costs sidebar navigation item with the number of eligible terms.
- Supports direct deep links using hash #mad4b-guide-taxonomy-cost-panel.
- Preserves JetEngine native form fields, the six original sections, the existing single Save action, and separate audited Guide Cost approval actions.
- Restores native section selection when another sidebar section is clicked.
- Falls back to placing the cost editor near the page title if vendor workspace markup changes.
- Updates the functional group metric to seven.

Standalone DOM wiring test: node ops/guide-taxonomy-pricing/tests/verify-navigation.js.
The test covers mounting, tab activation, native-tab restoration, deep links and vendor-markup fallback. This is a deterministic simulated DOM, not a logged-in browser screenshot.

All changes are presentation-only. No Guide Cost term values, global defaults, Tour Rates or Checkout settings were modified.
