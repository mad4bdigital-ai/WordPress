# QA43 — Final Commercial Quote readiness, company-owned policy and source evidence

Executed on **ETG Woo Lab only** on 2026-10-28. No Production mutation, no cart, no paid Order, no supplier booking.

## Deployed source

The Commerce Composition plugin now includes `CommercialQuoteReadiness.php`, registered from `composition.php`. The existing **Tools → Commerce Engines** admin screen has a new **Final Quote — Provider Readiness** table showing native pricing rule ownership and explicit incomplete states. Nothing is copied into another tenant's configuration.

Admin-only REST under `/wp-json/commerce-composition/v1`:

- `GET /management/commercial-quote/providers`: site-bound policy revision and status, WooCommerce tax setting, native provider coverage and missing certified receipt issuers.
- `POST /management/commercial-quote/readiness`: `{ "source_id": 60820, "configuration": { ...native booking configuration... }, "sale_role": "independent_rate" }`. Uses the existing native `CommercialExecutionPreflight::inspect`, `CommercialQuotePlan::fromPreflight`, `CompanyCommercePolicy::resolve`, `shared_product_financial_readiness_v2` and CPT Woo identity checks. Returns a deterministic readiness digest, 11 provider-owned gates and exact corrective actions. Source and Rate ancestry are verified; no alternate pricing engine.

Both routes use native WordPress `manage_options && manage_woocommerce` and private/no-store responses. No PII is returned. Installed domain adapters may register evidence-provider readers through the server-side WordPress filter `commerce_composition_final_quote_evidence_providers` with contract `commerce.source-evidence-provider.v1`, exact site UUID, registered provider ID and a callable. Discovery is tenant-bound and never makes evidence certified. The final Quote, cost, tax, availability, approval, payment and booking flags remain false even when a provider is registered. No REST route to issue a final Quote or accept a payment was introduced without certified source receipts.

## Real source audit for Tour 60629 / Rate 60820

- Company Policy revision 1 is **Draft**, tied to exact domain `https://tours.dev.mad4b.com` and enrolled Site Profile; manager and traveler approval required.
- Source financial readiness returns blocker **`inferred_mode_policy_pending_approval`**. It explicitly reports `checkout_ready=false`.
- Pricing Rule v2 reports supplier cost/margin `not_integrated`, tax/fees `not_integrated_v2`, availability `partial`, manual approval `not_integrated_v2`.
- WooCommerce `woocommerce_calc_taxes=no`, no tax-rate rows, prices exclude tax. Company policy `tax_mode=manual_review`; no certified tax receipt. **Do not synthesize zero tax as approved.**
- Rate meta has a `has_allotment_inevntory` key with numeric-looking value, but its `remaining_allotment` and `sold_allotment` fields are empty. Presence of a field is not a reserved inventory hold.
- No native verified supplier cost/margin receipt was observed in the tested Tour/Rate metadata. No assumption of vendor cost from Woo sale price.
- Both existing Woo Product identities are readable but `is_purchasable=false`; neither is approved to sell.
- A final Quote must be issued by a registered source/provider with a verifiable receipt bound to exact site, product/Rate, company policy revision and an immutable quote digest; approvals must match that digest, and availability holds need an expiring provider reference. The included evidence contract describes the required future issuer receipts, **not** an automatic issuer.

## Actual verification

- QA43 REST, admin, source financial checks, parent Tour/independent Rate, permissions and no business side effects: **37/37 PASS**.
- QA42 exact-domain new company definition + archive regression: **33/33 PASS**.
- QA42B real Woo Lab Quote Plan regression: **25/25 PASS**.
- **95/95 live WP-CLI checks**. PHP lint, installed/source parity, exact patch replay PASS.
- Local HTTP 200; anonymous provider REST HTTP 401.
- Additional QA43 checks prove registered source providers are dynamically discovered, cannot self-certify financial readiness and cannot cross tenant boundaries. No change to Woo Orders, Commerce Requests, FluentBoards tasks, provider inventory holds or bookings during tests.

## Rollback and release boundaries

VPS evidence and rollback snapshots: `/opt/commerce-components/evidence/etg-commercial-quote-readiness-qa43-20261028/`.

The GitHub PR will contain the actual three modified/new plugin source files, the versioned SHA256 runtime manifest, acceptance criteria and test results. It is NOT permission to set the company policy to `active`, fabricate final tax/cost/stock values or call Woo checkout APIs.

Before certifying the first final Quote, establish independent provider-authorized cost, tax/fees and availability receipts, review the inferred tour-mode pricing rule, approve the company policy with appropriate governance and implement separate manager/traveler receipt verification. Only thereafter can Woo Order/supplier execution be submitted for its own release certification.
