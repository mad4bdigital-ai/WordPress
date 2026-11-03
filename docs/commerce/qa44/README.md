# QA44 — Source-bound evidence probe (Lab-only)

This change is **stacked on Draft PR #546 (QA43), which depends on Draft #545 and #544**. Source added to the real Commerce Composition plugin and installed only on **ETG Woo Lab**. No Production modification, company policy activation, cart, order, payment, inventory hold, FluentBoards task or supplier booking.

## Runtime route

Admin only: `POST /wp-json/commerce-composition/v1/management/commercial-quote/evidence-probe` with:

```json
{"source_id":60820,"sale_role":"independent_rate","configuration":{"schema_version":2,"tour_id":60629,"source":"rate","rate_id":60820,"travel_date":"2026-11-10","audience":"general","occupancy":"double","quantities":{"persons":2},"booking_intent":"quote","extras":[],"selections":[]}}
```

The handler **recomputes** QA43 readiness using native Preflight, Company Policy and CommercialQuotePlan. It does not accept provider receipts, price, approvals or signatures from the caller. All commercial authorizations in response remain false. Response has `Cache-Control: private,no-store` and excludes raw proof/signatures and traveler personal information.

## Adapter read + independent verification contract

A trusted installed domain-native adapter registers under the existing server-side filter `commerce_composition_final_quote_evidence_providers`, keyed by `supplier_cost`, `tax`, `availability`, `final_quote`, `manager_approval`, `traveler_acceptance`. Required provider fields: `contract=commerce.source-evidence-provider.v1`, exact `site_uuid`, stable `provider_id`, stable `source_owner`, callable `read(context)`, callable `verify(receipt, context)`.

The read context carries **site UUID, company policy revision + digest, native preflight digest, plan digest, Woo product identity, parent service ID, Rate ID, sale role** and optional whitelisted travel date/occupancy. No name, email, phone, traveler contact or unapproved price is supplied to callbacks.

The source receipt must carry `contract=commerce.source-evidence-receipt.v1`, `kind`, `provider_id`, `site_uuid`, `policy_revision`, `policy_digest`, `preflight_digest`, `plan_digest`, `woo_product_id`, `receipt_id`, `receipt_digest` (64 hex), `issued_at_utc`, `expires_at_utc` (ISO UTC seconds). All context and time fields are checked before `verify` is called. A source adapter's `verify` must independently verify signer identity, signature/digest, source-owned record, audience, amount/currency where relevant, and revocation/replay against its original authority. Merely returning `true` without such verification is **not** sufficient for commercial certification.

This probe returns `source_verified_evidence_only` only when the installed adapter passes both checks. It **never issues Final Quote** or grants checkout, order, payment, acceptance, supplier booking or hold. Provider registration is not authority; fake/test adapters must never be deployed to a client tenant.

## Current actual ETG Lab outcome

- Company Commerce Policy revision 1 remains Draft.
- Tour 60629 and independent Rate 60820 retain distinct native Woo product identities and plan digests, with correct ancestry.
- No authentic supplier-cost, tax, availability, final-quote, manager-approval or traveler-acceptance source adapters exist on this Lab at verification time: **0/6 verified receipts**.
- Native tour mode remains `inferred_mode_policy_pending_approval`; Woo tax calculation is disabled and tax-rate rows are empty. No synthetic zero-tax, supplier price or inventory hold was entered.
- QA44 PHP + REST + malicious/expired/cross-tenant source probe scenarios: **27/27 PASS**. QA43 regression on the same installed Lab: **37/37 PASS**. Woo order/request/task/hold/booking counts unchanged. Source & installed plugin byte parity checked; PHP lint passed.

## Next release gates

Approve source financial mode and company business policy through the authorized process, onboard source-owned signed cost/margin and tax/fee verifiers, obtain an expiring inventory hold from an authorized provider, then issue an immutable Final Quote with currency, total, deadline and verified source provenance. Only after that, implement distinct manager and traveler acceptance for the **same** signed Quote digest. Cart/Order, payment and supplier execution require separate certification and idempotent reconciliation. Remain Draft; no auto-merge or Production deployment.

VPS evidence and rollback: `/opt/commerce-components/evidence/etg-commercial-quote-evidence-qa44-20261028/`.
