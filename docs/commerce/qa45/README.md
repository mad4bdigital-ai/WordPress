# QA45 — Verified provider-backed commercial Final Quote lifecycle

Executed on **ETG Woo Lab VPS only**, October 28, 2026. Stacked on **Draft PR #550 (QA44)**, then #546 QA43, #545 QA42B, #544 QA42A. No Production mutation. **No real Final Quote has been issued**.

## Installed native implementation

- `includes/CommercialFinalQuoteLifecycle.php` registered in Commerce Composition boot.
- `CommercialQuoteEvidenceProbe::inspectForIssuer()` is an internal PHP-only read+verify return path, exposing verified source claims to trusted code; the existing REST route keeps claims and raw receipts private.
- Admin-only REST `/wp-json/commerce-composition/v1`:
  - `POST /management/commercial-quote/final/readiness` — read-only readiness and precise blocking evidence.
  - `POST /management/commercial-quote/final/issue` — immutable source-backed Final Quote issuance **only** when all source, financial policy and site-binding checks are met.
  - `POST /management/commercial-quote/final/approval/verify` — persist a single independently verified manager or traveler signature/receipt, bound to the exact issued Quote digest. No client-uploaded acceptance or price.
  - `GET /management/commercial-quote/final/{64-char-digest}/status` — returns source-verified approval states without payment or order grants.
- REST is admin-only (`manage_options && manage_woocommerce`) and `Cache-Control: private,no-store`. Not a guest booking API.
- Idempotent quote and approval records use atomically unique WordPress option names keyed by signed receipt/Quote digest; immutable readback, conflict denial, no PII. Policy revision and source expiry invalidate stale quotes. **This is not a payment or supplier booking engine.**

### Required live provider proof

All source owners must register verified server-side `read(context)` and `verify(receipt,context)` callbacks under `commerce_composition_final_quote_evidence_providers`. QA44 requires authenticated exact tenant, issuer, product and policy/plan identity, provider signature verification and finite expiry. QA45 requires the following verified **independent** receipts before issuing:

1. `supplier_cost` — currency + integer `amount_minor` (cost, possibly 0 only with a genuinely authorized zero-cost receipt).
2. `tax` — currency + integer `amount_minor` (zero only when separately certified by an authentic tax authority/source, not because Woo taxes are disabled).
3. `availability` — genuine provider `hold_reference` and unexpired supplier-owned inventory hold.
4. `final_quote` — currency, `amount_minor` total, `supplier_cost_minor`, `tax_minor`, `margin_minor`, `fee_minor`, `discount_minor`, plus exact `reference_digests` for each of the three other receipts.

All money is in integer minor units. Exact reconciliation: **total = supplier cost + tax + margin + fees − discount**, cross-matching cost and tax receipts and signed source references. No Woo preview prices, inferred tax zeros, or simulated provider holds.

Issuance also requires the **active** company policy bound to the current Site Profile, `site_write_ready`, and native financial-mode readiness without blockers. Quotes expire at the earliest source receipt expiry. A Final Quote digest binds site UUID, company policy revision/digest, plan digest, selected native Woo product/Rate, currency, amount breakdown, supplier hold and all source receipt digests.

After issuance, manager and traveler approvals are **independent receipt kinds and signing subjects**, signed against the **same immutable Quote digest**. A manager identity must be a source-verified WordPress user, a traveler identity must be a separate opaque verified-channel subject. Approval records never authorize Cart/Order/Payment/Booking; these need separate future certification.

## Real source audit

Lab target: `https://tours.dev.mad4b.com`, `etg_woocommerce_lab` DB. The current company policy remains **Draft**, native `shared_product_financial_readiness_v2(60629,60820)` reports `inferred_mode_policy_pending_approval` and `checkout_ready=false`. Woo tax calculation is disabled; no Woo tax tables are configured. The installed inventory ledger is a local-only shadow prototype and cannot prove an authorized supplier hold for Tour Rate 60820. No registered live supplier cost, tax, availability, final Quote, manager approval or traveler acceptance verification adapter. **These are factual blockers, not implementation defaults.**

**QA45 40/40 PASS** native Lab PHP/REST, source-missing refusal, pure reconciliation of synthetic test receipts, financial/tenant policy denial, tampering and permissions. **QA44 27/27 PASS** and **QA43 37/37 PASS** regression, total 104/104 tested in the installed Lab. All synthetic positive tests were pure in-memory calculation only; the actual issuance route returns HTTP 409. No Woo Orders, commerce Requests, FluentBoards Tasks, inventory holds, bookings, issued Quote options or acceptance options were created.

## Operational next gates

Authorize source tour-mode financial policy and company policy via their governed independent approval processes. Integrate **genuine** provider contracts/readers/verifiers and verify their signature lineage plus source economics, tax policy, live supplier stock hold, quote expiry, manager and traveler acceptance. Only then run an actual issuance test and separate release certification for Woo cart/order/payment/supplier booking with idempotent reconciliation.

Evidence and backup are held on VPS at `/opt/commerce-components/evidence/etg-final-quote-lifecycle-qa45-20261028/`; branch remains Draft. Do not auto-merge or deploy to Production.
