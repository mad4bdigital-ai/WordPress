---
name: booking-to-cash-reconciliation
description: "Trace a traveler inquiry through Fluent Forms, WooCommerce, Stripe and deposit/balance fulfillment without generating payments or altering orders during diagnostics."
---

# MAD4B — Booking To Cash Reconciliation

This Skill is company-scoped, advisory and read-first. It does not add WordPress permissions, MCP abilities, payment powers, API secrets or arbitrary shell authority.

## Procedure

1. Resolve company, tour ID, market, language, quote mode (inquiry-only or deposit), event and order reference; use masked identifiers in reports.
2. Trace original Fluent Forms submission and field snapshot to the original WooCommerce order, typed line items, taxes, currency and any authorized Stripe payment intent.
3. Verify gateway environment (test/live) and webhook signature/endpoint evidence independently; never treat an HTTP 200 or email appearance as confirmed payment.
4. Compare rate/direct source, occupancy, child/infant policy, optional extras, accommodation and pickup metadata with the captured invoice and order version.
5. Check deposit percentage, balance due date, refund/cancellation policy and reconciliation events. Explain mismatches without silently recalculating historical invoices.
6. Use read-only source evidence first; a repair requires the original Woo/Stripe owner, exact order fingerprint, idempotency key and approved mutation operation.
7. Return a stage-by-stage ledger: Form → Quote → Order → Payment → Notification → Fulfillment, with evidence status, blockers and next safe action.

## Result contract

Return the exact company/environment, selected native data sources, operation owner, evidence freshness, proposed action, authorization status, verification method and reversible rollback path. Unknowns remain explicit.
